<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\Discovery\Isbn;
use App\Service\DiscoveryResolverService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Anonymous external discovery endpoints (ADR-060).
 *
 * POST /api/discovery/series - resolve the series owned volumes belong to.
 * POST /api/discovery/author - resolve the bibliography of a liked author.
 *
 * Privacy contract: requests carry no Authorization header, no node id, no
 * account id; nothing here may persist a requester-to-query association.
 * The rate limiter keys on IP transiently (token bucket state), which is
 * accepted as inherent to abuse protection.
 *
 * Validation is strict and cheap, before any cache or source access (OWASP
 * A03, and the first line of defense against cache-miss flooding): ISBNs
 * must pass checksum validation, arrays are capped, the optional name is
 * an opaque length-capped string never interpolated anywhere.
 */
#[Route('/api/discovery', name: 'api_discovery_')]
class DiscoveryController extends AbstractController
{
    use AccountApiTrait;

    private const MAX_ANCHOR_ISBNS = 3;
    private const MAX_LANGS = 8;
    private const MAX_NAME_LENGTH = 256;
    private const MAX_BODY_BYTES = 4096;

    public function __construct(
        private readonly DiscoveryResolverService $resolver,
        private readonly RateLimiterFactoryInterface $discoveryAnonLimiter,
    ) {
    }

    #[Route('/series', name: 'series', methods: ['POST'])]
    public function series(Request $request): JsonResponse
    {
        if (($limited = $this->enforce($this->discoveryAnonLimiter, $request->getClientIp() ?? 'unknown')) !== null) {
            return $limited;
        }
        if (($tooLarge = $this->rejectIfBodyTooLarge($request, self::MAX_BODY_BYTES)) !== null) {
            return $tooLarge;
        }

        $data = $this->decodeJson($request);
        if ($data === null) {
            return $this->json(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $isbn13s = $this->validatedIsbns($data['isbns'] ?? null);
        if ($isbn13s === null) {
            return $this->json(
                ['error' => sprintf('isbns must be 1..%d checksum-valid ISBN-10/13 strings', self::MAX_ANCHOR_ISBNS)],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $name = $data['name'] ?? null;
        if ($name !== null && (!is_string($name) || mb_strlen($name) > self::MAX_NAME_LENGTH)) {
            return $this->json(
                ['error' => sprintf('name must be a string of at most %d characters', self::MAX_NAME_LENGTH)],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $langs = $this->validatedLangs($data['langs'] ?? []);
        if ($langs === null) {
            return $this->json(
                ['error' => sprintf('langs must be at most %d short language codes', self::MAX_LANGS)],
                Response::HTTP_BAD_REQUEST,
            );
        }

        return $this->json($this->resolver->resolveSeries($isbn13s, $name, $langs));
    }

    /**
     * Author bibliography lookup: the name is REQUIRED here, unlike the
     * series tiebreaker, because it is what the resolver verifies the
     * anchor's author entity against (ADR-060 section 3.2). It stays an
     * opaque length-capped string: source queries are built from resolved
     * identifiers, never from it.
     */
    #[Route('/author', name: 'author', methods: ['POST'])]
    public function author(Request $request): JsonResponse
    {
        if (($limited = $this->enforce($this->discoveryAnonLimiter, $request->getClientIp() ?? 'unknown')) !== null) {
            return $limited;
        }
        if (($tooLarge = $this->rejectIfBodyTooLarge($request, self::MAX_BODY_BYTES)) !== null) {
            return $tooLarge;
        }

        $data = $this->decodeJson($request);
        if ($data === null) {
            return $this->json(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $name = $data['name'] ?? null;
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            return $this->json(
                ['error' => sprintf('name must be a non-empty string of at most %d characters', self::MAX_NAME_LENGTH)],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $isbn13s = $this->validatedIsbns($data['anchor_isbns'] ?? null);
        if ($isbn13s === null) {
            return $this->json(
                ['error' => sprintf('anchor_isbns must be 1..%d checksum-valid ISBN-10/13 strings', self::MAX_ANCHOR_ISBNS)],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $langs = $this->validatedLangs($data['langs'] ?? []);
        if ($langs === null) {
            return $this->json(
                ['error' => sprintf('langs must be at most %d short language codes', self::MAX_LANGS)],
                Response::HTTP_BAD_REQUEST,
            );
        }

        return $this->json($this->resolver->resolveAuthor(trim($name), $isbn13s, $langs));
    }

    /**
     * 1..MAX_ANCHOR_ISBNS checksum-valid ISBNs, canonicalized to ISBN-13
     * and deduplicated. Null on any invalid entry: a single bad checksum
     * rejects the request rather than resolving a partial anchor set.
     *
     * @return list<string>|null
     */
    private function validatedIsbns(mixed $raw): ?array
    {
        if (!is_array($raw) || $raw === [] || count($raw) > self::MAX_ANCHOR_ISBNS || !array_is_list($raw)) {
            return null;
        }
        $isbn13s = [];
        foreach ($raw as $entry) {
            if (!is_string($entry)) {
                return null;
            }
            $isbn13 = Isbn::toIsbn13($entry);
            if ($isbn13 === null) {
                return null;
            }
            $isbn13s[] = $isbn13;
        }

        return array_values(array_unique($isbn13s));
    }

    /**
     * Reader language codes: 2-8 chars, letters and dash (e.g. "fr",
     * "pt-BR"). Null when the array shape or any entry is invalid.
     *
     * @return list<string>|null
     */
    private function validatedLangs(mixed $raw): ?array
    {
        if (!is_array($raw) || count($raw) > self::MAX_LANGS || !array_is_list($raw)) {
            return null;
        }
        $langs = [];
        foreach ($raw as $entry) {
            if (!is_string($entry) || preg_match('/^[A-Za-z][A-Za-z-]{1,7}$/', $entry) !== 1) {
                return null;
            }
            $langs[] = $entry;
        }

        return $langs;
    }
}
