<?php
/* POST /api/reminder-save.php — set or change a person's reach-out reminder.
 *
 *   { person_id: 12, cadence_days: 60 }        -> every 60 days
 *   { person_id: 12, due_date: "2026-08-10" }  -> once, on that day
 *   -> { reminder: { id, type, recurrence_interval_days, next_due_date, … },
 *        label: "Every 60 days · Friday" }
 *
 * EXACTLY ONE OF THE TWO, because a reach-out is a cadence OR a one-off and
 * never both (schema.sql, UNIQUE (person_id, type) plus a nullable
 * recurrence_interval_days). Clearing it is api/reminder-delete.php, not an
 * empty save — "save nothing" and "delete" arriving at the same endpoint is how
 * a dropped field silently removes somebody's reminder.
 *
 * IT REFUSES TO TOUCH A BIRTHDAY REMINDER, and takes no type parameter at all
 * so there is nothing to refuse. Birthday rows are materialized from the
 * person's birthday and reconciled from people_save() and from the cron
 * (PLAN.md §4.5); a row edited here would be silently corrected within 24
 * hours, which is worse than not offering the control. Change the birthday
 * instead. */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/reminders.php';

require_login_api();
require_same_origin();
require_method('POST');

$in    = json_body();
$today = crm_today();

$personId = (int) ($in['person_id'] ?? 0);
if ($personId <= 0 || reminders_person($personId) === null) {
    json_error('person_not_found', 404, 'That person is not here any more.');
}

$hasCadence = array_key_exists('cadence_days', $in) && $in['cadence_days'] !== null && $in['cadence_days'] !== '';
$hasDate    = array_key_exists('due_date', $in) && $in['due_date'] !== null && $in['due_date'] !== '';

if ($hasCadence === $hasDate) {
    json_error(
        'reminder_choice_required',
        422,
        'Choose a repeating cadence or a single date, not both and not neither.'
    );
}

$cadence = null;
$dueDate = null;

if ($hasCadence) {
    $cadence = reminders_clean_cadence($in['cadence_days']);
    if ($cadence === null) {
        json_error(
            'cadence_out_of_range',
            422,
            'A cadence is between ' . REMINDER_CADENCE_MIN . ' and ' . REMINDER_CADENCE_MAX . ' days.'
        );
    }
} else {
    $dueDate = reminders_clean_date($in['due_date']);
    if ($dueDate === null) {
        json_error('due_date_invalid', 422, 'That date could not be read.');
    }
    /* A date in the past is deliberately allowed through — see
     * reminders_clean_date(). It means "I should already have done this", and
     * `<= today` puts it straight onto the dashboard. */
}

try {
    $reminder = reminders_set_reach_out($personId, $cadence, $dueDate, $today);
} catch (Throwable $e) {
    error_log('reminder-save: ' . $e->getMessage());
    json_error('reminder_save_failed', 500, 'That reminder could not be saved.');
}

if ($reminder === null) {
    /* Only reachable if the person was deleted between the check above and the
     * write. Answer with the truth rather than inventing a reminder. */
    json_error('person_not_found', 404, 'That person is not here any more.');
}

json_out(array(
    'reminder' => $reminder,
    'label'    => reminders_label($reminder, $today),
));
