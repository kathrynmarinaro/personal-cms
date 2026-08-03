<?php
/* POST /api/gift-delete.php — remove one gift idea.
 *
 *   { id: 57 }
 *   -> { id: 57, gift: { id: 57, person_id: 12, idea_text: "…", created_at: "…" } }
 *
 * The delete half of the swipe. It happens IMMEDIATELY — swipe.js fires this
 * the moment the gesture completes rather than when the undo snackbar expires,
 * because the server is the source of truth and an item must be gone if the tab
 * closes during those five seconds (docs/CONTRACTS.md §5).
 *
 * WHICH IS WHY THE DELETED ROW COMES BACK IN THE RESPONSE. Undo is a RESTORE,
 * not a cancellation: the row is already gone from the database, so the client
 * holds these fields for the length of the snackbar and posts them to
 * api/gift-restore.php if the button is tapped. Nothing is kept server-side —
 * five seconds of state on a server is five seconds of state to get wrong.
 *
 * A gift idea IS swipe-deletable, unlike a person. CLAUDE.md draws that line:
 * five seconds is not a window in which you notice losing somebody's notes and
 * years of contact history, but it is plenty for a phrase you can retype. */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/contact.php';

require_login_api();
require_same_origin();
require_method('POST');

$in = json_body();

$id = (int) ($in['id'] ?? 0);
if ($id <= 0) {
    json_error('gift_required', 422, 'No gift idea was named.');
}

try {
    $gift = gift_delete($id);
} catch (Throwable $e) {
    error_log('gift-delete: ' . $e->getMessage());
    json_error('gift_delete_failed', 500, 'That gift idea could not be deleted.');
}

if ($gift === null) {
    /* Already gone — a second swipe on a row that had not been removed from the
     * DOM yet, or two tabs. The end state is the one that was asked for, but it
     * is answered as a conflict rather than as a success because there is
     * nothing to hand back for the undo, and a snackbar offering an Undo that
     * cannot work is worse than no snackbar. */
    json_error('gift_not_found', 409, 'That gift idea is not here any more.');
}

json_out(array('id' => $gift['id'], 'gift' => $gift));
