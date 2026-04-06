<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\BookPriceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public book price API.
 * Data source: nudger.fr - Open Database License (ODbL).
 *
 * GET /api/prices/{isbn}           - Single ISBN price lookup
 * GET /api/prices/batch?isbns=...  - Batch lookup (max 50)
 */
#[Route('/api/prices', name: 'api_prices_')]
class PriceController extends AbstractController
{
    private const BATCH_MAX = 50;

    public function __construct(
        private readonly BookPriceRepository $bookPriceRepository,
    ) {}

    /**
     * GET /api/prices/batch?isbns=isbn1,isbn2,...
     * Returns prices for up to 50 ISBNs in a single request.
     */
    #[Route('/batch', name: 'batch', methods: ['GET'], priority: 2)]
    public function batch(Request $request): JsonResponse
    {
        $raw = $request->query->getString('isbns');
        if ($raw === '') {
            return $this->json(
                ['error' => 'isbns query parameter is required.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $isbns = array_filter(array_map('trim', explode(',', $raw)));
        if (count($isbns) > self::BATCH_MAX) {
            return $this->json(
                ['error' => sprintf('Maximum %d ISBNs per request.', self::BATCH_MAX)],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // Validate each ISBN format
        foreach ($isbns as $isbn) {
            if (!preg_match('/^\d{10}(\d{3})?$/', $isbn)) {
                return $this->json(
                    ['error' => sprintf('Invalid ISBN format: %s', $isbn)],
                    Response::HTTP_BAD_REQUEST,
                );
            }
        }

        $prices = $this->bookPriceRepository->findByIsbns($isbns);

        return $this->json([
            'data' => array_map(fn($p) => $p->toArray(), $prices),
            'attribution' => 'Price data: nudger.fr (ODbL)',
        ]);
    }

    /**
     * GET /api/prices/{isbn}
     * Returns the price for a single ISBN, or 404 if not found.
     */
    #[Route('/{isbn}', name: 'show', methods: ['GET'], priority: 1)]
    public function show(string $isbn): JsonResponse
    {
        if (!preg_match('/^\d{10}(\d{3})?$/', $isbn)) {
            return $this->json(
                ['error' => 'Invalid ISBN format.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $price = $this->bookPriceRepository->findByIsbn($isbn);

        if ($price === null) {
            return $this->json(['error' => 'Price not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            ...$price->toArray(),
            'attribution' => 'Price data: nudger.fr (ODbL)',
        ]);
    }
}
