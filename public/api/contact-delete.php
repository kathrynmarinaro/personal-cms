<?php
/* POST /api/contact-delete.php — remove one entry from a person's history.
 *
 *   { id: 88 }
 *   -> { id: 88, person_id: 12, last_contact_date: "2026-06-04", count: 42 }
 *
 * The mis-tap escape hatch. assets/dashboard.js promises this exists — its
 * "Logged today" button disables itself rather than becoming an un-log, on the
 * grounds that removing a logged contact is a deliberate action in the
 * profile's history. This is that action.
 *
 * NO UNDO AND NO api/contact-restore.php, which is why the profile asks first
 * rather than offering a swipe. CLAUDE.md keeps swipe-to-delete on gift ideas
 * and import drafts, where the worst case is retyping a phrase.
 *
 * last_contact_date COMES BACK because deleting the newest entry moves it —
 * contact_log_delete() puts it back to whatever the remaining history says,
 * including NULL when the last entry has just gone. The screen would otherwise
 * go on showing a last-contact line that is no longer true.
 *
 * IT DOES NOT REWIND THE REACH-OUT REMINDER. See contact_log_delete(): the
 * reminder was moved to a date computed from the entry, there is no record of
 * what it said before, and inventing one would be worse than leaving a schedule
 * you can see and change on the profile. */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/contact.php';

require_login_api();
require_same_origin();
require_method('POST');

$in = json_body();

$id = (int) ($in['id'] ?? 0);
if ($id <= 0) {
    json_error('entry_required', 422, 'No log entry was named.');
}

try {
    $entry = contact_log_delete($id);
} catch (Throwable $e) {
    error_log('contact-delete: ' . $e->getMessage());
    json_error('contact_delete_failed', 500, 'That entry could not be removed.');
}

if ($entry === null) {
    json_error('entry_not_found', 409, 'That entry is not here any more.');
}

$person = people_get($entry['person_id']);

json_out(array(
    'id'                => $entry['id'],
    'person_id'         => $entry['person_id'],
    'last_contact_date' => $person === null ? null : $person['last_contact_date'],
    'count'             => contact_log_count($entry['person_id']),
));
