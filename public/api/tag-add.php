<?php
/* POST /api/tag-add.php — create a custom relationship tag, and assign it.
 *
 *   { name: "Neighbour", person_id: 12 }
 *   -> { tag: { id, name, sort_order, is_preset }, assigned: true }
 *
 * ONE CALL, BOTH HALVES, because typing a tag name into a box on somebody's
 * profile means "put this person in that group" — creating the tag and then
 * leaving them out of it would be a control that did half of what it said. The
 * person_id is optional so the endpoint stays usable from anywhere else later.
 *
 * A name that already exists comes back as that tag rather than as an error.
 * relationship_tags.name is UNIQUE, so a second row is impossible anyway, and
 * "Family" already exists is not news to somebody who just typed "family" — see
 * tags_add(). The response is therefore always the tag you asked for, whether
 * or not this call is what created it. */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/people.php';

require_login_api();
require_same_origin();
require_method('POST');

$in = json_body();

$name = people_clean_tag_name((string) ($in['name'] ?? ''));
if ($name === null) {
    json_error('name_required', 422, 'A tag needs a name.');
}

$personId = (int) ($in['person_id'] ?? 0);

try {
    $tag = tags_add($name);
    $assigned = $tag !== null && $personId > 0 && people_assign_tag($personId, $tag['id']);
} catch (Throwable $e) {
    error_log('tag-add: ' . $e->getMessage());
    json_error('add_failed', 500, 'That tag could not be added.');
}

if ($tag === null) {
    json_error('name_required', 422, 'A tag needs a name.');
}

json_out(array('tag' => $tag, 'assigned' => $assigned));
