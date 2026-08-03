<?php
/* POST /api/contact-log.php — "I spoke to them."
 *
 *   { person_id: 12 }                         -> the 1-tap button
 *   { person_id: 12, note: "about the move" } -> the same, with a note
 *   -> { id: 88, entry: { id, person_id, logged_at, note },
 *        last_contact_date: "2026-08-03" }
 *
 * ===================== THE APP'S ONE DAILY ACTION ===========================
 *
 * The dashboard's row button posts { person_id } here and nothing else
 * (assets/dashboard.js, docs/CONTRACTS.md §5); the profile's composer adds the
 * optional note. THE PATH AND THE FIELD NAME ARE FIXED — dashboard.js was
 * written against them before this file existed.
 *
 * It answers with the new contact_log id so that a caller can offer an Undo
 * through showSnackbar() and api/contact-delete.php. The dashboard does not use
 * one today (its button disables itself instead, because a second tap would
 * honestly mean "log another conversation"), but returning it costs nothing and
 * leaves the door open.
 * ============================================================================
 *
 * THE DATE IS ALWAYS TODAY, from the single crm_today() below. contact_log_add()
 * takes the date as a parameter — it must, because the reminder arithmetic runs
 * off it and lib/dates.php's one-clock rule forbids anything downstream from
 * asking — and it handles a backdated one correctly, but nothing in the UI
 * offers to backdate and this endpoint deliberately does not accept a date.
 * "Log a conversation on a date of the caller's choosing" is a screen nobody
 * has asked for, and an unused parameter on the app's most-hit endpoint is a
 * way to move somebody's cadence clock without going near a cadence.
 *
 * TAPPING IT TWICE IN ONE DAY IS TWO ROWS AND ONE DATE, on purpose, and this
 * endpoint does not de-duplicate: two conversations in one day are two
 * conversations, and the cadence clock runs off the date (CLAUDE.md). */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/contact.php';

require_login_api();
require_same_origin();
require_method('POST');

$in    = json_body();
$today = crm_today();

$personId = (int) ($in['person_id'] ?? 0);
if ($personId <= 0 || people_get($personId) === null) {
    json_error('person_not_found', 404, 'That person is not here any more.');
}

/* An unreadable or over-long note is trimmed and stored rather than refused.
 * The note is the optional half of a one-tap action, and failing the whole log
 * because of it would lose the fact that the conversation happened. */
$note = contact_clean_note(isset($in['note']) ? (string) $in['note'] : null);

try {
    /* Writes the log row, moves last_contact_date forward, and hands the date to
     * reminders_contact_logged() — which is the only thing in the app that moves
     * a reach-out reminder (PLAN.md §7.2). All three, or none. */
    $entry = contact_log_add($personId, $note, $today, $today);
} catch (Throwable $e) {
    error_log('contact-log: ' . $e->getMessage());
    json_error('contact_log_failed', 500, 'That could not be logged.');
}

if ($entry === null) {
    /* Only reachable if the person went away between the check above and the
     * insert. The dashboard's row reverts and says nothing was logged. */
    json_error('person_not_found', 404, 'That person is not here any more.');
}

$person = people_get($personId);

json_out(array(
    'id'                => $entry['id'],
    'entry'             => $entry,
    'lines'             => contact_log_lines($entry, $today),
    'last_contact_date' => $person === null ? null : $person['last_contact_date'],
));
