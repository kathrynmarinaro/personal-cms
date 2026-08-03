<?php
/* POST /api/import-promote.php — turn one import draft into a real person.
 *
 *   { id: 12 }
 *   -> { person: { id, name, … }, pending: 217 }
 *
 * What assets/import.js calls when the row's circle is tapped. public/import.php
 * does the same thing through a plain POST for a browser with no script, and
 * both go through import_promote() — the only duplicated thing is the shape of
 * the answer.
 *
 * A FLAGGED DUPLICATE IS PROMOTED LIKE ANY OTHER AND CREATES A SECOND PERSON.
 * dup_person_id is a warning to a human (schema.sql, docs/CONTRACTS.md §2); the
 * human answered it by tapping Add. Nothing here merges anything.
 *
 * `pending` comes back so the screen can update its count without a reload. */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/import.php';

require_login_api();
require_same_origin();
require_method('POST');

$in = json_body();
$id = (int) ($in['id'] ?? 0);
if ($id <= 0) {
    json_error('draft_required', 422, 'No contact was named.');
}

$draft = import_draft($id);
if ($draft === null) {
    json_error('draft_gone', 409, 'That contact is not in the queue any more.');
}

$today = crm_today();

try {
    $person = import_promote($id, $today);
} catch (Throwable $e) {
    error_log('import-promote: ' . $e->getMessage());
    json_error('promote_failed', 500, 'That person could not be added.');
}

if ($person === null) {
    /* Already added or already skipped — a stale tab, or the same row tapped
     * twice on a slow connection. Refused rather than creating a second copy of
     * somebody. */
    json_error('draft_not_pending', 409, 'That contact has already been dealt with.');
}

json_out(array(
    'person'  => $person,
    'pending' => import_counts($draft['batch_id'])['pending'],
));
