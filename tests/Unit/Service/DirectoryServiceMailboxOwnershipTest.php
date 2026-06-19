<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\LibraryProfile;
use App\Exception\MailboxOwnershipConflictException;
use App\Repository\BorrowRequestRepository;
use App\Repository\FollowRepository;
use App\Repository\LibraryProfileRepository;
use App\Repository\RelayMailboxRepository;
use App\Service\DirectoryService;
use App\Service\HubEventLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for mailbox ownership enforcement in DirectoryService.
 *
 * See ADR-031. Coverage:
 *   - Unknown mailbox (not in relay_mailboxes): accept without claim.
 *   - Unowned mailbox: claim on first reference, store owner_node_id.
 *   - Caller owns the mailbox: no-op, legitimate credential refresh.
 *   - Mismatched owner + enforcement on: throw, profile changes rolled back.
 *   - Mismatched owner + shadow mode: log + bump counter, profile changes allowed.
 *   - Counter is incremented on the caller's profile, not the victim's.
 */
// Shared EM/Connection/repository/logger doubles: some tests set expectations,
// others use them as pure stubs. Opt out of PHPUnit 12.5's no-expectations notice.
#[AllowMockObjectsWithoutExpectations]
final class DirectoryServiceMailboxOwnershipTest extends TestCase
{
    private const MAILBOX_ID = '11111111-2222-3333-4444-555555555555';
    private const CALLER_NODE = 'node-caller-abcdef';
    private const OTHER_NODE = 'node-victim-zzzzzz';

    private EntityManagerInterface&MockObject $em;
    private LibraryProfileRepository&MockObject $profileRepository;
    private Connection&MockObject $connection;
    private HubEventLogger&MockObject $eventLogger;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->profileRepository = $this->createMock(LibraryProfileRepository::class);
        $this->connection = $this->createMock(Connection::class);
        $this->eventLogger = $this->createMock(HubEventLogger::class);

        $this->em->method('getConnection')->willReturn($this->connection);
    }

    private function makeService(bool $enforced): DirectoryService
    {
        return new DirectoryService(
            $this->em,
            $this->profileRepository,
            $this->createStub(FollowRepository::class),
            $this->createStub(BorrowRequestRepository::class),
            $this->createStub(RelayMailboxRepository::class),
            $this->eventLogger,
            coversDirectory: sys_get_temp_dir(),
            mailboxOwnershipEnforced: $enforced,
        );
    }

    /**
     * Unknown mailbox: the relay_mailboxes row does not exist yet.
     * This is a "trust on first use" case documented as out-of-scope in
     * ADR-031: the mailbox will be created by the relay service later.
     * The profile upsert must accept the value without claiming anything.
     */
    public function testUnknownMailboxAcceptsWithoutClaim(): void
    {
        $service = $this->makeService(enforced: true);

        $this->profileRepository->method('findByNodeId')->willReturn(null);

        $this->connection->expects(self::once())->method('beginTransaction');
        $this->connection->expects(self::once())
            ->method('fetchAssociative')
            ->with(
                self::stringContains('FOR UPDATE'),
                ['uuid' => self::MAILBOX_ID],
            )
            ->willReturn(false);
        $this->connection->expects(self::never())->method('update');
        $this->connection->expects(self::once())->method('commit');

        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $service->upsertProfile([
            'node_id'            => self::CALLER_NODE,
            'display_name'       => 'Test',
            'relay_mailbox_id'   => self::MAILBOX_ID,
            'relay_write_token'  => 'aabbccdd',
        ], null);
    }

    public function testUnownedMailboxIsClaimedOnFirstReference(): void
    {
        $service = $this->makeService(enforced: true);

        $this->profileRepository->method('findByNodeId')->willReturn(null);

        $this->connection->expects(self::once())->method('beginTransaction');
        $this->connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['owner_node_id' => null]);
        $this->connection->expects(self::once())
            ->method('update')
            ->with(
                'relay_mailboxes',
                ['owner_node_id' => self::CALLER_NODE],
                ['uuid' => self::MAILBOX_ID],
            );
        $this->connection->expects(self::once())->method('commit');

        $this->eventLogger->expects(self::once())
            ->method('info')
            ->with('directory', self::stringContains('mailbox claimed'), self::anything());

        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $service->upsertProfile([
            'node_id'            => self::CALLER_NODE,
            'display_name'       => 'Test',
            'relay_mailbox_id'   => self::MAILBOX_ID,
            'relay_write_token'  => 'aabbccdd',
        ], null);
    }

    public function testOwnerRefreshingCredentialsIsNoOp(): void
    {
        $service = $this->makeService(enforced: true);

        $existing = new LibraryProfile(self::CALLER_NODE, 'write-tok', 'Self');
        $this->profileRepository->method('findByNodeId')->willReturn($existing);

        $this->connection->expects(self::once())->method('beginTransaction');
        $this->connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['owner_node_id' => self::CALLER_NODE]);
        $this->connection->expects(self::never())->method('update');
        $this->connection->expects(self::once())->method('commit');

        $this->eventLogger->expects(self::never())->method('warning');

        $this->em->expects(self::once())->method('flush');

        $service->upsertProfile([
            'node_id'            => self::CALLER_NODE,
            'display_name'       => 'Self',
            'relay_mailbox_id'   => self::MAILBOX_ID,
            'relay_write_token'  => 'aabbccdd',
        ], $existing);

        self::assertSame(self::MAILBOX_ID, $existing->getRelayMailboxId());
    }

    public function testMismatchedOwnerInEnforcedModeThrows(): void
    {
        $service = $this->makeService(enforced: true);

        $existing = new LibraryProfile(self::CALLER_NODE, 'write-tok', 'Attacker');
        $existing->setRelayMailboxId(null);
        $this->profileRepository->method('findByNodeId')->willReturn($existing);

        $this->connection->expects(self::once())->method('beginTransaction');
        $this->connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['owner_node_id' => self::OTHER_NODE]);
        $this->connection->expects(self::never())->method('update');
        $this->connection->expects(self::once())->method('commit');

        $this->eventLogger->expects(self::once())
            ->method('warning')
            ->with('directory', 'hijack_attempt', self::callback(function (array $ctx): bool {
                // Must include mailbox and node_id context only (no tokens).
                return isset($ctx['mailbox']) && isset($ctx['node_id']);
            }));

        // Counter bump is attempted (best-effort executeStatement).
        $this->connection->expects(self::atLeastOnce())
            ->method('executeStatement')
            ->with(
                self::stringContains('hijack_attempts_total = hijack_attempts_total + 1'),
                self::arrayHasKey('node_id'),
            );

        // In enforced mode, flush MUST NOT happen because the service
        // throws before reaching it. Profile stays unchanged on disk.
        $this->em->expects(self::never())->method('flush');

        $this->expectException(MailboxOwnershipConflictException::class);

        $service->upsertProfile([
            'node_id'            => self::CALLER_NODE,
            'display_name'       => 'Attacker',
            'relay_mailbox_id'   => self::MAILBOX_ID,
            'relay_write_token'  => 'aabbccdd',
        ], $existing);
    }

    public function testMismatchedOwnerInShadowModeLogsButAllows(): void
    {
        $service = $this->makeService(enforced: false);

        $existing = new LibraryProfile(self::CALLER_NODE, 'write-tok', 'Attacker');
        $this->profileRepository->method('findByNodeId')->willReturn($existing);

        $this->connection->expects(self::once())->method('beginTransaction');
        $this->connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['owner_node_id' => self::OTHER_NODE]);
        $this->connection->expects(self::once())->method('commit');

        $this->eventLogger->expects(self::once())
            ->method('warning')
            ->with('directory', 'hijack_attempt', self::callback(function (array $ctx): bool {
                return ($ctx['reason'] ?? null) === 'shadow';
            }));

        $this->connection->expects(self::atLeastOnce())
            ->method('executeStatement')
            ->with(self::stringContains('hijack_attempts_total'), self::anything());

        // Shadow mode: the upsert proceeds, flush is called, value is stored.
        $this->em->expects(self::once())->method('flush');

        $service->upsertProfile([
            'node_id'            => self::CALLER_NODE,
            'display_name'       => 'Attacker',
            'relay_mailbox_id'   => self::MAILBOX_ID,
            'relay_write_token'  => 'aabbccdd',
        ], $existing);

        // Shadow mode stores the (attacker-controlled) mailbox id. That is
        // the deliberate tradeoff of the shadow window (ADR-031).
        self::assertSame(self::MAILBOX_ID, $existing->getRelayMailboxId());
    }

    public function testHijackCounterIsIncrementedOnCallerNotVictim(): void
    {
        $service = $this->makeService(enforced: true);

        $existing = new LibraryProfile(self::CALLER_NODE, 'write-tok', 'Attacker');
        $this->profileRepository->method('findByNodeId')->willReturn($existing);

        $this->connection->method('beginTransaction');
        $this->connection->method('fetchAssociative')
            ->willReturn(['owner_node_id' => self::OTHER_NODE]);
        $this->connection->method('commit');

        $capturedParams = null;
        $this->connection->expects(self::atLeastOnce())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedParams): int {
                if (str_contains($sql, 'hijack_attempts_total')) {
                    $capturedParams = $params;
                }
                return 1;
            });

        try {
            $service->upsertProfile([
                'node_id'            => self::CALLER_NODE,
                'display_name'       => 'Attacker',
                'relay_mailbox_id'   => self::MAILBOX_ID,
                'relay_write_token'  => 'aabbccdd',
            ], $existing);
        } catch (MailboxOwnershipConflictException) {
            // expected
        }

        self::assertNotNull($capturedParams);
        self::assertSame(self::CALLER_NODE, $capturedParams['node_id']);
    }

    public function testUpsertWithoutMailboxFieldSkipsOwnershipCheckEntirely(): void
    {
        $service = $this->makeService(enforced: true);

        $existing = new LibraryProfile(self::CALLER_NODE, 'write-tok', 'Self');
        $this->profileRepository->method('findByNodeId')->willReturn($existing);

        $this->connection->expects(self::never())->method('beginTransaction');
        $this->connection->expects(self::never())->method('fetchAssociative');

        $this->em->expects(self::once())->method('flush');

        $service->upsertProfile([
            'node_id'      => self::CALLER_NODE,
            'display_name' => 'Self Renamed',
            // No relay_mailbox_id: ownership path must not run.
        ], $existing);
    }

    public function testNullRelayMailboxIdSkipsOwnershipCheck(): void
    {
        // Explicit null means "clear the relay mailbox". That is an action
        // on the caller's own stored value and never touches relay_mailboxes.
        $service = $this->makeService(enforced: true);

        $existing = new LibraryProfile(self::CALLER_NODE, 'write-tok', 'Self');
        $existing->setRelayMailboxId(self::MAILBOX_ID);
        $this->profileRepository->method('findByNodeId')->willReturn($existing);

        $this->connection->expects(self::never())->method('beginTransaction');
        $this->em->expects(self::once())->method('flush');

        $service->upsertProfile([
            'node_id'          => self::CALLER_NODE,
            'relay_mailbox_id' => null,
        ], $existing);

        self::assertNull($existing->getRelayMailboxId());
    }

    public function testMalformedMailboxIdSkipsOwnershipCheck(): void
    {
        // Format validation already rejects non-UUID values silently
        // (preserves prior behavior). The ownership path must not run
        // on a rejected input.
        $service = $this->makeService(enforced: true);

        $existing = new LibraryProfile(self::CALLER_NODE, 'write-tok', 'Self');
        $existing->setRelayMailboxId(self::MAILBOX_ID);
        $this->profileRepository->method('findByNodeId')->willReturn($existing);

        $this->connection->expects(self::never())->method('beginTransaction');
        $this->em->expects(self::once())->method('flush');

        $service->upsertProfile([
            'node_id'          => self::CALLER_NODE,
            'relay_mailbox_id' => 'not-a-uuid',
        ], $existing);

        // Prior value preserved.
        self::assertSame(self::MAILBOX_ID, $existing->getRelayMailboxId());
    }

    public function testHijackEventContextContainsNoSecrets(): void
    {
        $service = $this->makeService(enforced: true);

        $existing = new LibraryProfile(self::CALLER_NODE, 'write-tok', 'Attacker');
        $this->profileRepository->method('findByNodeId')->willReturn($existing);

        $this->connection->method('beginTransaction');
        $this->connection->method('fetchAssociative')
            ->willReturn(['owner_node_id' => self::OTHER_NODE]);
        $this->connection->method('commit');

        $this->eventLogger->expects(self::once())
            ->method('warning')
            ->with('directory', 'hijack_attempt', self::callback(function (array $ctx): bool {
                // Context MUST NOT contain relay_write_token, write_token, or
                // any token-shaped value. See S2 and the ADR-031 secrets
                // hygiene rule.
                foreach ($ctx as $value) {
                    if (is_string($value) && str_contains(strtolower($value), 'aabbccdd')) {
                        return false;
                    }
                }
                return true;
            }));

        try {
            $service->upsertProfile([
                'node_id'            => self::CALLER_NODE,
                'display_name'       => 'Attacker',
                'relay_mailbox_id'   => self::MAILBOX_ID,
                'relay_write_token'  => 'aabbccdd',
            ], $existing);
        } catch (MailboxOwnershipConflictException) {
            // expected
        }
    }
}
