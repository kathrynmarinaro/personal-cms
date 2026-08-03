<?php
/* POST /api/tag-rename.php — rename a relationship tag.
 *
 *   { id: 3, name: "Closest Friends" }  ->  { id: 3, name: "Closest Friends" }
 *
 * What assets/people.js calls when a group heading on the People tab is tapped
 * and edited — docs/CONTRACTS.md §4 gives `.cat-head button` as exactly that:
 * a heading that is itself a control.
 *
 * A RENAME MOVES EVERYONE UNDER IT AND TOUCHES NO OTHER ROW. person_tag_map
 * points at the tag's id, never at its name (schema.sql, where both sides
 * cascade), so this is one UPDATE and the grouping follows. That is also why
 * there is no merge: renaming "Colleague" to "Friend" is refused rather than
 * quietly folding two groups together, because the two sets of people are not
 * the same people and nothing could undo it.
 *
 * The stored name is echoed back because assets/inline-edit.js renders whatever
 * it is handed, so a name the server trimmed or collapsed shows as what was
 * really saved. */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/people.php';

require_login_api();
require_same_origin();
require_method('POST');

$in = json_body();

$id = (int) ($in['id'] ?? 0);
if ($id <= 0) {
    json_error('bad_id', 422);
}

$name = people_clean_tag_name((string) ($in['name'] ?? ''));
if ($name === null) {
    /* An emptied heading is not a delete. inline-edit.js already treats a
     * cleared field as a cancel; this is the same rule for anything that gets
     * past it. */
    json_error('name_required', 422, 'A tag needs a name.');
}

if (tag_get($id) === null) {
    json_error('not_found', 404);
}

try {
    $tag = tags_rename($id, $name);
} catch (Throwable $e) {
    error_log('tag-rename: ' . $e->getMessage());
    json_error('rename_failed', 500);
}

if ($tag === null) {
    json_error('name_taken', 409, 'There is already a tag called ' . $name . '.');
}

json_out(array('id' => $tag['id'], 'name' => $tag['name']));
