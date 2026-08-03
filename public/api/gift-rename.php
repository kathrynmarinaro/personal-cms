<?php
/* POST /api/gift-rename.php — change one gift idea's text.
 *
 *   { id: 57, idea_text: "the walnut board, not the bamboo one" }
 *   -> { gift: { id: 57, … }, idea_text: "the walnut board, not the bamboo one" }
 *
 * The tap-to-edit half of the row (inline-edit.js). idea_text comes back at the
 * top level as well as inside the gift because that module renders whatever
 * string the promise resolves to — which is how a server that trims or
 * truncates gets the last word instead of leaving the row showing what was
 * typed (docs/CONTRACTS.md §5).
 *
 * AN EMPTIED EDITOR IS REFUSED, NOT TREATED AS A DELETE. inline-edit.js cancels
 * on an empty field before it ever gets here; this is the same rule stated
 * server-side, because delete has its own gesture with its own undo and an
 * accidental select-all-backspace must not be one. */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/contact.php';

require_login_api();
require_same_origin();
require_method('POST');

$in = json_body();

$id = (int) ($in['id'] ?? 0);
if ($id <= 0 || gift_get($id) === null) {
    json_error('gift_not_found', 404, 'That gift idea is not here any more.');
}

$text = gift_clean_text(isset($in['idea_text']) ? (string) $in['idea_text'] : null);
if ($text === null) {
    json_error('idea_required', 422, 'A gift idea cannot be empty.');
}

try {
    $gift = gift_rename($id, $text);
} catch (Throwable $e) {
    error_log('gift-rename: ' . $e->getMessage());
    json_error('gift_rename_failed', 500, 'That change could not be saved.');
}

if ($gift === null) {
    json_error('gift_not_found', 404, 'That gift idea is not here any more.');
}

json_out(array('gift' => $gift, 'idea_text' => $gift['idea_text']));
