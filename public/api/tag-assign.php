<?php
/* POST /api/tag-assign.php — put a tag on a person, or take it off.
 *
 *   { person_id: 12, tag_id: 3, assigned: true }
 *   -> { person_id: 12, tag_id: 3, assigned: true }
 *
 * ONE ENDPOINT FOR BOTH DIRECTIONS, because the control is a toggle: the tag
 * picker is a sheet whose rows show what is already on and turn it off when you
 * tap them again. A pair of assign/unassign endpoints would mean the caller
 * deciding which one to hit from state it is about to change, and getting that
 * backwards on a double tap is how a tag ends up flickering.
 *
 * IDEMPOTENT IN BOTH DIRECTIONS. Assigning a tag somebody already has is a
 * no-op, not a primary-key error; removing one they never had is a success,
 * because the end state is the one that was asked for. On a phone a double tap
 * is one gesture. */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/people.php';

require_login_api();
require_same_origin();
require_method('POST');

$in = json_body();

$personId = (int) ($in['person_id'] ?? 0);
$tagId    = (int) ($in['tag_id'] ?? 0);
if ($personId <= 0 || $tagId <= 0) {
    json_error('bad_id', 422);
}

/* Defaults to assigning: a body that forgot the flag is far more likely to mean
 * "add this" than "remove whatever is there". */
$assigned = !array_key_exists('assigned', $in) || (bool) $in['assigned'];

if (people_get($personId) === null) {
    json_error('not_found', 404, 'That person is gone.');
}
if (tag_get($tagId) === null) {
    json_error('unknown_tag', 404, 'That tag is gone.');
}

try {
    if ($assigned) {
        people_assign_tag($personId, $tagId);
    } else {
        people_unassign_tag($personId, $tagId);
    }
} catch (Throwable $e) {
    error_log('tag-assign: ' . $e->getMessage());
    json_error('assign_failed', 500, 'That tag could not be changed.');
}

json_out(array('person_id' => $personId, 'tag_id' => $tagId, 'assigned' => $assigned));
