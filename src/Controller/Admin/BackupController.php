<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class BackupController extends AbstractController
{
    #[Route('/admin/backup', name: 'admin_backup', methods: ['GET'])]
    public function backup(): Response
    {
        $databaseUrl = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? null;
        if ($databaseUrl === null) {
            $this->addFlash('danger', 'DATABASE_URL not configured.');
            return $this->redirectToRoute('admin');
        }

        $parts = parse_url($databaseUrl);
        if ($parts === false || !isset($parts['host'], $parts['port'], $parts['user'], $parts['path'])) {
            $this->addFlash('danger', 'Invalid DATABASE_URL format.');
            return $this->redirectToRoute('admin');
        }

        $host = $parts['host'];
        $port = (string) $parts['port'];
        $user = $parts['user'];
        $password = urldecode($parts['pass'] ?? '');
        $dbName = ltrim($parts['path'], '/');

        $filename = sprintf('hub-backup-%s.sql', date('Y-m-d-His'));

        return new StreamedResponse(function () use ($host, $port, $user, $password, $dbName): void {
            $process = new Process(
                ['pg_dump', '--no-owner', '--no-acl', '-h', $host, '-p', $port, '-U', $user, $dbName],
                null,
                ['PGPASSWORD' => $password, 'PGSSLMODE' => 'require'],
                null,
                120, // 2 min timeout
            );

            $process->run(function (string $type, string $data): void {
                if ($type === Process::OUT) {
                    echo $data;
                    flush();
                }
            });
        }, Response::HTTP_OK, [
            'Content-Type' => 'application/sql',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
