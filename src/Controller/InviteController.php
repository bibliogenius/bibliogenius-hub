<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class InviteController extends AbstractController
{
    #[Route('/invite', name: 'invite', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $data = $request->query->get('d', '');

        // Decode the base64url payload to extract library name for display
        $libraryName = null;
        if ($data !== '') {
            try {
                $b64 = strtr($data, '-_', '+/');
                $json = base64_decode($b64, true);
                if ($json !== false) {
                    $payload = json_decode($json, true);
                    if (is_array($payload)) {
                        // v4 short keys: "n", v3 long keys: "name"
                        $libraryName = $payload['n'] ?? $payload['name'] ?? null;
                    }
                }
            } catch (\Throwable $e) {
                // Invalid payload - template will show error state
            }
        }

        return $this->render('invite/index.html.twig', [
            'data' => $data,
            'library_name' => $libraryName,
        ]);
    }
}
