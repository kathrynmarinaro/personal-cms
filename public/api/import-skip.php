<?php
/* POST /api/import-skip.php — take one draft out of the review queue.
 *
 *   { id: 12 }
 *   -> { id: 12, pending: 216 }
 *
 * The delete half of the swipe on the import queue. The draft row is MARKED,
 * not deleted, because swipe.js fires this the moment the gesture completes and
 * treats Undo as a restore rather than a cancellation (docs/CONTRACTS.md §5) —
 * so the row has to be able to come back with the same id, and api/
 * import-restore.php is what brings it. Everything left is pruned when the
 * batch is finished, so nothing accumulates. */

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
    $skipped = import_skip($id);
} catch (Throwable $e) {
    error_log('import-skip: ' . $e->getMessage());
    json_error('skip_failed', 500, 'That contact could not be skipped.');
}

if (!$skipped) {
    /* Only reachable for a draft that has already been added. That person
     * exists now, and deleting a person is a deliberate action on their profile
     * with a confirmation (CLAUDE.md) — never a side effect of a swipe here. */
    json_error('draft_already_added', 409, 'That person has already been added.');
}

json_out(array(
    'id'      => $id,
    'pending' => import_counts($draft['batch_id'])['pending'],
));
