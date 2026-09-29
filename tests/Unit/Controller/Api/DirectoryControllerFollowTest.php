<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Api;

use App\Controller\Api\DirectoryController;
use App\Entity\Follow;
use App\Entity\LibraryProfile;
use App\Repository\FollowRepository;
use App\Repository\LibraryProfileRepository;
use App\Service\DirectoryService;
use App\Service\HubEventLogger;
use App\Service\SidecarNotifier;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The follow route answers 404 only for a profile that does not exist.
 *
 * It used to answer 404 for an unlisted profile too, which broke ADR-053: a
 * paired library that is not listed could never be followed, so its offline
 * catalog fallback and its contact card never reached the peer. Whether the
 * follow starts active or pending is the service's call, not the route's.
 */
#[AllowMockObjectsWithoutExpectations]
final class DirectoryControllerFollowTest extends TestCase
{
    private const FOLLOWER = '11111111-1111-4111-8111-111111111111';
    private const FOLLOWED = '22222222-2222-4222-8222-222222222222';
    private const WRITE_TOKEN = 'write-token';

    private function controller(?LibraryProfile $followed, ?Follow $follow = null): DirectoryController
    {
        $follower = new LibraryProfile(self::FOLLOWER, self::WRITE_TOKEN, 'Follower');

        $directoryService = $this->createStub(DirectoryService::class);
        $directoryService->method('authenticate')->willReturn($follower);
        $directoryService->method('follow')->willReturn($follow);

        $profiles = $this->createStub(LibraryProfileRepository::class);
        $profiles->method('findByNodeId')->willReturn($followed);

        $controller = new DirectoryController(
            $directoryService,
            $profiles,
            $this->createStub(FollowRepository::class),
            $this->createStub(Connection::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(HubEventLogger::class),
            $this->createStub(SidecarNotifier::class),
            sys_get_temp_dir(),
        );
        $controller->setContainer(new Container());

        return $controller;
    }

    private static function request(): Request
    {
        return Request::create(
            '/api/directory/follow/' . self::FOLLOWED,
            'POST',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::WRITE_TOKEN],
        );
    }

    public function testUnknownProfileIsNotFound(): void
    {
        $response = $this->controller(null)->follow(self::FOLLOWED, self::request());

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testUnlistedProfileCanBeFollowed(): void
    {
        $followed = new LibraryProfile(self::FOLLOWED, 'other-token', 'Unlisted');
        $followed->setIsListed(false);
        $follow = new Follow(self::FOLLOWER, self::FOLLOWED);

        $response = $this->controller($followed, $follow)->follow(self::FOLLOWED, self::request());

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertSame(Follow::STATUS_PENDING, json_decode((string) $response->getContent(), true)['status']);
    }

    public function testListedProfileIsUnchanged(): void
    {
        $followed = new LibraryProfile(self::FOLLOWED, 'other-token', 'Listed');
        $followed->setIsListed(true);
        $follow = new Follow(self::FOLLOWER, self::FOLLOWED);
        $follow->approve();

        $response = $this->controller($followed, $follow)->follow(self::FOLLOWED, self::request());

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertSame(Follow::STATUS_ACTIVE, json_decode((string) $response->getContent(), true)['status']);
    }
}
