<?php
/* POST /api/gift-add.php — add one gift idea to a person.
 *
 *   { person_id: 12, idea_text: "the walnut board from the shop on Grand" }
 *   -> { gift: { id: 57, person_id: 12, idea_text: "…", created_at: "…" } }
 *
 * ONE IDEA PER SUBMISSION. The sibling app's grocery composer splits on commas
 * because "milk, eggs, bread" is three things; a gift idea is a described want
 * and "the blue one, not the grey" is one. */

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
    json_error('idea_required', 422, 'Type a gift idea first.');
}

try {
    $gift = gift_add($personId, $text);
} catch (Throwable $e) {
    error_log('gift-add: ' . $e->getMessage());
    json_error('gift_add_failed', 500, 'That gift idea could not be saved.');
}

if ($gift === null) {
    /* Only reachable if the person went away between the check above and the
     * insert. Say so rather than answering with a gift idea nobody can see. */
    json_error('person_not_found', 404, 'That person is not here any more.');
}

json_out(array('gift' => $gift));
