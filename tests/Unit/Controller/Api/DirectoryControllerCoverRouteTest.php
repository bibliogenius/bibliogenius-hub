<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Api;

use App\Controller\Api\DirectoryController;
use App\Entity\LibraryProfile;
use App\Repository\FollowRepository;
use App\Repository\LibraryProfileRepository;
use App\Service\DirectoryService;
use App\Service\HubEventLogger;
use App\Service\SidecarNotifier;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Freezes the identifier contract of the three cover routes.
 *
 * They gated the book id on `ctype_digit` alone, which was the whole contract
 * while clients keyed books by an integer row id. Clients moved to uuid primary
 * keys and kept naming cover files after the book id, so every custom cover was
 * answered 400 on upload, fetch and delete: owners saw a permanent "cover not
 * synced" badge, and the cover URLs their catalogs advertise pointed at a route
 * that refused to serve them. The cover GC had already been taught both shapes.
 *
 * Both generations therefore have to pass, and everything else has to keep
 * failing: both segments name a path component under the covers directory.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class DirectoryControllerCoverRouteTest extends TestCase
{
    private const NODE_ID = '94df3033-4a30-4307-8b82-1c5941474b99';
    private const UUID_BOOK_ID = '019f09b8-4a66-703e-ac96-7054b37b83d0';
    private const WRITE_TOKEN = 'write-token';

    /** A minimal valid JPEG payload: the magic bytes the route checks, plus a body. */
    private const JPEG = "\xFF\xD8\xFF\xE0" . 'payload';

    private string $coversDirectory;

    protected function setUp(): void
    {
        $this->coversDirectory = sys_get_temp_dir() . '/bg-covers-' . bin2hex(random_bytes(6));
        mkdir($this->coversDirectory, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->coversDirectory . '/*/*.jpg') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->coversDirectory . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            rmdir($dir);
        }
        if (is_dir($this->coversDirectory)) {
            rmdir($this->coversDirectory);
        }
    }

    private function controller(): DirectoryController
    {
        $profile = new LibraryProfile(self::NODE_ID, self::WRITE_TOKEN, 'Test library');

        $directoryService = $this->createStub(DirectoryService::class);
        $directoryService->method('authenticate')->willReturn($profile);

        $controller = new DirectoryController(
            $directoryService,
            $this->createStub(LibraryProfileRepository::class),
            $this->createStub(FollowRepository::class),
            $this->createStub(Connection::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(HubEventLogger::class),
            $this->createStub(SidecarNotifier::class),
            $this->coversDirectory,
        );
        // AbstractController::json() falls back to new JsonResponse() when the
        // container has no 'serializer' service, so an empty container
        // exercises the same code path as production.
        $controller->setContainer(new Container());

        return $controller;
    }

    private function authenticatedUpload(string $body = self::JPEG): Request
    {
        return Request::create(
            '/api/directory/' . self::NODE_ID . '/covers/' . self::UUID_BOOK_ID,
            'POST',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . self::WRITE_TOKEN],
            $body,
        );
    }

    public function testUploadAcceptsAUuidBookId(): void
    {
        $response = $this->controller()->uploadCover(
            self::NODE_ID,
            self::UUID_BOOK_ID,
            $this->authenticatedUpload(),
        );

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertFileExists(
            $this->coversDirectory . '/' . self::NODE_ID . '/' . self::UUID_BOOK_ID . '.jpg',
        );
    }

    public function testUploadStillAcceptsAnIntegerBookId(): void
    {
        $request = Request::create(
            '/api/directory/' . self::NODE_ID . '/covers/42',
            'POST',
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . self::WRITE_TOKEN],
            self::JPEG,
        );

        $response = $this->controller()->uploadCover(self::NODE_ID, '42', $request);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertFileExists($this->coversDirectory . '/' . self::NODE_ID . '/42.jpg');
    }

    public function testFetchAcceptsAUuidBookId(): void
    {
        $controller = $this->controller();
        $controller->uploadCover(self::NODE_ID, self::UUID_BOOK_ID, $this->authenticatedUpload());

        $response = $controller->getCover(self::NODE_ID, self::UUID_BOOK_ID);

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testDeleteAcceptsAUuidBookId(): void
    {
        $controller = $this->controller();
        $controller->uploadCover(self::NODE_ID, self::UUID_BOOK_ID, $this->authenticatedUpload());

        $response = $controller->deleteCover(
            self::NODE_ID,
            self::UUID_BOOK_ID,
            $this->authenticatedUpload(),
        );

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertFileDoesNotExist(
            $this->coversDirectory . '/' . self::NODE_ID . '/' . self::UUID_BOOK_ID . '.jpg',
        );
    }

    /**
     * A uuid that reaches the route must be the canonical shape, and anything
     * that could climb out of the covers directory stays refused. The guard is
     * what lets both segments be joined onto a path.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function rejectedTargets(): iterable
    {
        yield 'traversal book id' => [self::NODE_ID, '..'];
        yield 'separator in book id' => [self::NODE_ID, 'a/b'];
        yield 'truncated uuid' => [self::NODE_ID, '019f09b8-4a66-703e-ac96'];
        yield 'uuid with a non-hex char' => [self::NODE_ID, '019f09b8-4a66-703e-ac96-7054b37b83dz'];
        yield 'empty book id' => [self::NODE_ID, ''];
        yield 'traversal node id' => ['..', self::UUID_BOOK_ID];
        yield 'empty node id' => ['', self::UUID_BOOK_ID];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedTargets')]
    public function testRejectsUnusableTargets(string $nodeId, string $bookId): void
    {
        $upload = $this->controller()->uploadCover($nodeId, $bookId, $this->authenticatedUpload());
        self::assertSame(Response::HTTP_BAD_REQUEST, $upload->getStatusCode());

        $fetch = $this->controller()->getCover($nodeId, $bookId);
        self::assertSame(Response::HTTP_BAD_REQUEST, $fetch->getStatusCode());

        $delete = $this->controller()->deleteCover($nodeId, $bookId, $this->authenticatedUpload());
        self::assertSame(Response::HTTP_BAD_REQUEST, $delete->getStatusCode());
    }
}
