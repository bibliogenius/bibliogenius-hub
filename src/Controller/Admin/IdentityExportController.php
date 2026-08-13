<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\HubEventLogger;
use App\Service\LibraryIdentityExporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Downloads the analysis bundle for a single library.
 *
 * This is the narrow counterpart of /admin/backup: same admin-only surface,
 * one node instead of the whole database, and credentials reduced to a
 * present/absent marker by the exporter.
 */
#[IsGranted('ROLE_ADMIN')]
class IdentityExportController extends AbstractController
{
    public function __construct(
        private readonly LibraryIdentityExporter $exporter,
        private readonly HubEventLogger $eventLogger,
    ) {}

    #[Route(
        '/admin/export/library/{nodeId}',
        name: 'admin_export_library',
        requirements: ['nodeId' => '[^/]{1,128}'],
        methods: ['GET'],
    )]
    public function exportLibrary(string $nodeId): Response
    {
        $bundle = $this->exporter->export($nodeId);

        if ($bundle === null) {
            throw $this->createNotFoundException('No library profile for this node id.');
        }

        $json = json_encode(
            $bundle,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );

        // The bundle aggregates device fingerprint, client IPs and the lookup
        // traces of one library into a file that leaves the server and is no
        // longer under any access control. Record that it was produced.
        $this->eventLogger->audit('admin', 'library_export', [
            'node_id' => $nodeId,
            'size' => strlen($json),
        ]);

        return new Response($json, Response::HTTP_OK, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $this->filenameFor($nodeId)),
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * The node id reaches us from the URL, so it never goes into a response
     * header unfiltered: anything outside the safe set is dropped rather than
     * escaped, and the id is truncated to its readable prefix.
     */
    private function filenameFor(string $nodeId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $nodeId) ?? '';
        $safe = substr($safe, 0, 16);

        return sprintf('library-analysis-%s-%s.json', $safe === '' ? 'node' : $safe, date('Y-m-d'));
    }
}
