<?php
/* POST /api/reminder-delete.php — clear a person's reach-out reminder.
 *
 *   { person_id: 12 }  ->  { reminder: null }
 *
 * Its own endpoint rather than a save with empty fields, so that a form field
 * that failed to arrive can never read as "delete this". The two paths that
 * change a reminder are therefore both explicit about which one they are.
 *
 * NO UNDO, AND NONE IS OFFERED. Unlike a gift idea, a reminder is one number
 * and a date: re-setting it is two taps on the screen you are already looking
 * at, and swipe.js's undo snackbar is for rows you lose by accident during a
 * gesture. This is a deliberate control that says what it does.
 *
 * Takes no type, for the same reason api/reminder-save.php doesn't: the
 * birthday reminder belongs to the birthday, and deleting it here would be
 * undone by the cron's reconciliation pass within a day. */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/reminders.php';

require_login_api();
require_same_origin();
require_method('POST');

$in = json_body();

$personId = (int) ($in['person_id'] ?? 0);
if ($personId <= 0) {
    json_error('person_not_found', 404, 'That person is not here any more.');
}

try {
    /* Deliberately not conditional on there being one. The caller asked for
     * "no reach-out reminder" and that is the state afterwards either way — a
     * double tap on a phone is one gesture, and erroring on the second would
     * report a failure for something that worked. */
    reminders_clear($personId, REMINDER_REACH_OUT);
} catch (Throwable $e) {
    error_log('reminder-delete: ' . $e->getMessage());
    json_error('reminder_delete_failed', 500, 'That reminder could not be removed.');
}

json_out(array('reminder' => null));
