<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Fire-and-forget notifier for the WebSocket sidecar.
 *
 * When a message is deposited in a relay mailbox, this service sends a
 * lightweight POST to the sidecar so it can push a "nudge" to connected
 * WebSocket clients. If the sidecar is unreachable, the call silently fails
 * and the polling fallback handles delivery.
 *
 * @see ADR-017 for architecture details.
 */
class SidecarNotifier
{
    private readonly string $sidecarUrl;

    public function __construct(?string $sidecarUrl = null)
    {
        $this->sidecarUrl = $sidecarUrl ?? 'http://127.0.0.1:9090';
    }

    /**
     * Notify the sidecar that a new message was deposited in a mailbox.
     *
     * Best-effort: if the sidecar is down or slow, this method returns
     * without throwing. The 500ms timeout prevents blocking the HTTP response.
     */
    public function nudge(string $mailboxUuid): void
    {
        $url = sprintf('%s/internal/notify/%s', $this->sidecarUrl, $mailboxUuid);

        $ch = curl_init($url);
        if ($ch === false) {
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 500,
            CURLOPT_CONNECTTIMEOUT_MS => 200,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => '',
        ]);

        curl_exec($ch);
        curl_close($ch);
    }
}
