<?php
/* POST /api/person-update.php — overwrite one person's identity fields.
 *
 *   { id: 12, name: "Alex Chen", birth_month: 4, birth_day: 15, notes: "…" }
 *   -> { person: { id, name, … } }
 *
 * ALL OF THE FIELDS, EVERY TIME, INCLUDING THE EMPTY ONES. This is what the
 * profile's edit form saves, and that form renders every field — so a missing
 * key here means "clear it", which is exactly what clearing an address that
 * changed has to mean. There is deliberately no partial-update endpoint: the
 * sibling app keeps a one-field rename separate from a whole-row edit precisely
 * so a JSON body missing a key cannot wipe something nobody was editing, and
 * the way to have that guarantee here is to have only the whole-row one.
 *
 * The stored person is echoed back because the server normalizes — a name is
 * trimmed, an address's newlines fold into ", ", an implausible birth year is
 * dropped while the month and day survive — and the screen should show what was
 * really saved rather than what was typed. */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/people.php';

require_login_api();
require_same_origin();
require_method('POST');

$in    = json_body();
$today = crm_today();

$id = (int) ($in['id'] ?? 0);
if ($id <= 0) {
    json_error('bad_id', 422);
}

$name = people_clean_name((string) ($in['name'] ?? ''));
if ($name === null) {
    /* An emptied name is a delete in disguise, and deleting a person is a
     * confirmed action of its own on the profile. Refuse rather than guess. */
    json_error('name_required', 422, 'A person needs a name.');
}

if (people_get($id) === null) {
    json_error('not_found', 404);
}

$birthday = people_clean_birthday(
    $in['birth_year'] ?? null,
    $in['birth_month'] ?? null,
    $in['birth_day'] ?? null,
    $today
);

try {
    people_save($id, array(
        'name'        => $name,
        'birth_year'  => $birthday['birth_year'],
        'birth_month' => $birthday['birth_month'],
        'birth_day'   => $birthday['birth_day'],
        'address'     => people_clean_address((string) ($in['address'] ?? '')),
        'phone'       => people_clean_phone((string) ($in['phone'] ?? '')),
        'email'       => people_clean_email((string) ($in['email'] ?? '')),
        'notes'       => people_clean_notes((string) ($in['notes'] ?? '')),
    ), $today);
} catch (Throwable $e) {
    error_log('person-update: ' . $e->getMessage());
    json_error('update_failed', 500, 'That change could not be saved.');
}

json_out(array('person' => people_get($id)));
