<?php
/* POST /api/person-delete.php — delete a person and everything hanging off them.
 *
 *   { id: 12 }  ->  { deleted: 12, name: "Alex Chen" }
 *
 * THERE IS NO RESTORE ENDPOINT AND THERE MUST NOT BE ONE. Deleting a person
 * cascades five ways in schema.sql — tag links, gift ideas, the contact log, the
 * reminders, and the send ledger for those reminders two levels down — so
 * re-inserting the row would bring back a shell with none of the history that
 * made them worth keeping. That is also why this is not swipeable (CLAUDE.md):
 * an action that cannot be undone does not get a five-second undo window, it
 * gets a confirmation.
 *
 * The confirmation lives on the profile, not here. assets/person.js asks before
 * calling this, and person.php?id=…&delete=1 renders the same question as a page
 * for a browser with no script. An endpoint cannot enforce that, which is
 * precisely why the no-script path is a page and not a bare button. */

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

try {
    $person = people_delete($id);
} catch (Throwable $e) {
    error_log('person-delete: ' . $e->getMessage());
    json_error('delete_failed', 500, 'That person could not be deleted.');
}

if ($person === null) {
    /* Already gone — a double tap, or a second tab that got there first. The
     * caller wanted them not to exist and they don't, so this is not an error
     * worth putting on screen. */
    json_out(array('deleted' => $id, 'name' => ''));
}

json_out(array('deleted' => $id, 'name' => $person['name']));
