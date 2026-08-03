<?php
/* POST /api/import-restore.php — undo a skip.
 *
 *   { id: 12 }
 *   -> { id: 12, pending: 217 }
 *
 * The Undo on the skip snackbar. It is a RESTORE and not a cancellation:
 * swipe.js has already called api/import-skip.php by the time the snackbar
 * appears, because the server has to be the source of truth if the tab closes
 * during those five seconds (docs/CONTRACTS.md §5).
 *
 * The id does not change — a skip is a status, not a delete — so the row that
 * comes back is the same row. */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/import.php';

require_login_api();
require_same_origin();
require_method('POST');

$in = json_body();
$id = (int) ($in['id'] ?? 0);
if ($id <= 0) {
    json_error('draft_required', 422, 'No contact was named.');
}

$draft = import_draft($id);
if ($draft === null) {
    json_error('draft_gone', 409, 'That contact is not in the queue any more.');
}

try {
    $restored = import_restore($id);
} catch (Throwable $e) {
    error_log('import-restore: ' . $e->getMessage());
    json_error('restore_failed', 500, 'That contact could not be brought back.');
}

if (!$restored) {
    json_error('draft_already_added', 409, 'That person has already been added.');
}

json_out(array(
    'id'      => $id,
    'pending' => import_counts($draft['batch_id'])['pending'],
));
