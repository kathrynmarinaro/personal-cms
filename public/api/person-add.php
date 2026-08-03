<?php
/* POST /api/person-add.php — create one person.
 *
 *   { name: "Alex Chen", birth_month: 4, birth_day: 15, phone: "…" }
 *   -> { person: { id, name, … } }
 *
 * What assets/add.js calls. public/add.php does the same work through a plain
 * POST for a browser with no script, and the two must stay in step — both clean
 * with the same people_clean_* functions and both go through people_add(), so
 * the only thing duplicated between them is the shape of the answer.
 *
 * THE DUPLICATE ANSWER IS A 409 THAT ONLY EVER HAPPENS ONCE. schema.sql leaves
 * name_key non-unique on purpose: a duplicate is flagged, never refused. So the
 * first attempt at a name somebody already has comes back with
 * 'duplicate_name' and a sentence naming them; the caller sets
 * confirm_duplicate and the second attempt goes through and creates a SECOND
 * person, which is correct. Nothing here merges anything. */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/people.php';

require_login_api();
require_same_origin();
require_method('POST');

$in    = json_body();
$today = crm_today();

$name = people_clean_name((string) ($in['name'] ?? ''));
if ($name === null) {
    json_error('name_required', 422, 'A person needs a name.');
}

if (empty($in['confirm_duplicate'])) {
    $duplicates = people_same_name(people_name_key($name));
    if ($duplicates !== array()) {
        json_error(
            'duplicate_name',
            409,
            'You already have somebody called ' . $name . '. Add them again?'
        );
    }
}

$birthday = people_clean_birthday(
    $in['birth_year'] ?? null,
    $in['birth_month'] ?? null,
    $in['birth_day'] ?? null,
    $today
);

try {
    $id = people_add(array(
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
    error_log('person-add: ' . $e->getMessage());
    json_error('add_failed', 500, 'That person could not be added.');
}

json_out(array('person' => people_get($id)));
