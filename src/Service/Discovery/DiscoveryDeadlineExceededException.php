<?php

declare(strict_types=1);

namespace App\Service\Discovery;

/**
 * One resolution spent its whole wall-clock allowance (ADR-060 section
 * 3.4, deadline added 2026-08-25).
 *
 * The per-call timeouts are INACTIVITY timeouts, so they bound one call
 * and say nothing about a resolution that chains about twenty of them.
 * Without a total ceiling, a slow episode at a source keeps a FrankenPHP
 * worker and outbound tokens busy long after the client has given up
 * waiting, producing an answer nobody will read.
 *
 * Extends DiscoverySourceException so no existing catch site can let it
 * escape as a 500: it is a source-side slowness like the others, and it
 * maps to the same 'unavailable' envelope. Callers that want to tell it
 * apart in the journal catch it first.
 */
class DiscoveryDeadlineExceededException extends DiscoverySourceException
{
    public const REASON = 'resolution_deadline_exceeded';
}
