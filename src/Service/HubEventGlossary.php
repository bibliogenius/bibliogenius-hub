<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Plain-language explanations shown on hover in the dashboard's Recent
 * Hub Events table, keyed by the exact message string the code logs.
 *
 * Kept in PHP rather than in the template so that a test can verify every
 * key still matches a message logged somewhere in src/: a renamed message
 * used to lose its explanation silently.
 */
final class HubEventGlossary
{
    /** @var array<string, string> message => explanation */
    public const TIPS = [
        HubEventLogger::MARKER_HUB_EVENTS_CAPPED =>
            'This table hit its row cap and its oldest entries were deleted. Context: cutoff is the oldest entry still standing, deleted how many went. Expected on a busy hub, but any figure computed over a window reaching past that cutoff is missing events, which is why the Resolution Quality card stops answering.',
        'discovery_drift_degraded' =>
            'The discovery resolver found entities at Wikidata or Inventaire and they came back empty, past the alert threshold. Fix: run make test-discovery-prod, the sources most likely changed their schema. See the Resolution Quality card above.',
        'catalog_coverage_degraded' =>
            'More alive libraries than the threshold have no valid cached catalog. See the Catalog Coverage card and its drill-down: an old client build is the usual cause.',
        'duplicate_library_detected' =>
            'The same catalog is published under several live node ids (ADR-055, observation only). See the Duplicate Libraries drill-down.',
        'invalid write token' =>
            'Peer is using an expired or revoked invitation link. Fix: regenerate an invitation.',
        'collect from non-existent mailbox' =>
            'Collection from a non-existent mailbox. The peer needs to re-register with the hub.',
        'mailbox creation failed' =>
            'Mailbox creation failed: database issue. Check the Context column for the error detail.',
        'mailbox delete failed' =>
            'Mailbox deletion failed: database issue. Check the Context column.',
        'mailbox purged from dashboard (inactive)' =>
            'An admin deleted an inactive mailbox from this dashboard. Informational, no action needed.',
        'deposit failed' =>
            'Message deposit failed: database issue. Check the Context column.',
        'registration rejected (missing write_token)' =>
            'Missing write token. Outdated client (pre-v0.8) or unauthorized attempt. Suspicious if recurring.',
        'upsert forbidden' =>
            'Profile update rejected: peer tried to modify a profile it does not own.',
        'upsert failed' =>
            'Profile update failed: database issue. Check Context for detail.',
        'hijack_attempt' =>
            'A profile upsert tried to point relay_mailbox_id at a mailbox owned by another node (ADR-031). See the Hijack Attempts card and its drill-down.',
        'recovery failed (invalid code)' =>
            'Invalid recovery code entered. Suspicious if repeated from different sources.',
        'profile recovered via recovery code' =>
            'Profile successfully recovered via recovery code. Informational, no action needed.',
        'profile lookup: not found' =>
            'Profile lookup returned no result. Normal if the node_id is unknown to this hub.',
        'push rejected: storage quota exceeded' =>
            'An account tried to sync past its storage quota. The client shows the user a quota message; no server action unless it recurs for many accounts.',
        'account approaching storage quota' =>
            'An account is close to its storage quota. Informational: expect a quota rejection if it keeps growing.',
        'push failed' =>
            'Account sync push failed: database issue. Check the Context column.',
        'library_export' =>
            'An admin exported a library identity from the back office. Audit trail, no action needed.',
    ];
}
