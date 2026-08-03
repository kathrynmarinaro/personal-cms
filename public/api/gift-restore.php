<?php
/* POST /api/gift-restore.php — the Undo behind a swipe delete.
 *
 *   { id: 57, person_id: 12, idea_text: "the walnut board" }
 *   -> { id: 57, gift: { … } }
 *
 * A re-INSERT, not an un-delete: api/gift-delete.php already removed the row.
 * The fields come from that endpoint's response, held by the client for the
 * five seconds the snackbar is up — and they are all re-validated here anyway,
 * because anything can post to this endpoint and "the client is only echoing
 * what we just told it" is exactly the assumption that ages badly.
 *
 * IT TRIES TO KEEP THE ORIGINAL id and answers with whatever it ended up with,
 * which swipe.js adopts into the row's data-id. gift_restore() explains why the
 * id is worth keeping: these sort newest-first by id, so a fresh id would put
 * the row back in a different place from the one it was swiped out of. */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/contact.php';

require_login_api();
require_same_origin();
require_method('POST');

$in = json_body();

$personId = (int) ($in['person_id'] ?? 0);
if ($personId <= 0 || people_get($personId) === null) {
    json_error('person_not_found', 404, 'That person is not here any more.');
}

$text = gift_clean_text(isset($in['idea_text']) ? (string) $in['idea_text'] : null);
if ($text === null) {
    json_error('idea_required', 422, 'There was nothing to put back.');
}

try {
    $gift = gift_restore((int) ($in['id'] ?? 0), $personId, $text);
} catch (Throwable $e) {
    error_log('gift-restore: ' . $e->getMessage());
    json_error('gift_restore_failed', 500, 'That gift idea could not be put back.');
}

if ($gift === null) {
    json_error('gift_restore_failed', 500, 'That gift idea could not be put back.');
}

json_out(array('id' => $gift['id'], 'gift' => $gift));
