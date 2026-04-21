<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Raised by DirectoryService when a profile upsert attempts to set a
 * relay_mailbox_id whose owner_node_id is another node (hijack attempt).
 *
 * Thrown only when MAILBOX_OWNERSHIP_ENFORCED is on. In shadow mode the
 * service logs and allows the update. See ADR-031.
 *
 * Extends \LogicException so the existing DirectoryController catch
 * branch returns 403 without additional wiring. The detailed hijack
 * event is logged by the service before throwing, so the controller's
 * generic 'upsert forbidden' log is acceptable redundancy.
 */
final class MailboxOwnershipConflictException extends \LogicException
{
}
