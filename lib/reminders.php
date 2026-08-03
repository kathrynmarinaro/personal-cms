<?php
/* Reminders: the due query, the recurrence rules, and birthday reconciliation.
 *
 * Nothing outside this file writes SQL against reminders or reminder_sends.
 * Every statement is prepared, via q().
 *
 * ---------------------------------------------------------------------------
 * NOT ONE FUNCTION HERE ASKS WHAT DAY IT IS.
 * ---------------------------------------------------------------------------
 *
 * Every one of them takes $today as a parameter, from a single crm_today() at
 * the top of a request or a cron run. lib/dates.php's header explains why at
 * length; the short version is that "due today", "seven days before a
 * birthday" and "last contact plus sixty days" ARE this app, so a one-day skew
 * is not a subtle bug — it is birthday cards arriving late, every time, with
 * nothing on screen to explain it. Taking $today as a parameter is also the
 * only reason tools/tests-reminders.php can assert anything at all.
 *
 * ALL THE ARITHMETIC IS lib/dates.php's. next_birthday(), the Feb-29 clamp,
 * the January-birthday year boundary and "last contact plus N days" are
 * written and tested there. A second copy of any of them here would be a bug
 * the day one of the two was fixed.
 *
 * Every due query is `WHERE next_due_date <= ?` against a DATE column with the
 * date computed in PHP. There is no DATE_ADD, no INTERVAL and no NOW() in this
 * file on purpose (PLAN.md §5): it keeps the statement index-friendly, keeps it
 * identical on MySQL and on the SQLite the tests run against, and keeps the one
 * clock in one place.
 *
 * ---------------------------------------------------------------------------
 * THE ASYMMETRY THAT IS THE POINT OF THE WHOLE TRACK (PLAN.md §7.2).
 * ---------------------------------------------------------------------------
 *
 *   A BIRTHDAY reminder rolls its next_due_date forward a year when it fires.
 *   It has done its job — the birthday is coming, nothing more is expected of
 *   you this year.
 *
 *   A REACH-OUT reminder DOES NOT MOVE when it fires. It stays overdue, the
 *   dashboard keeps showing it, and it keeps accumulating. What moves it is
 *   logging an actual contact, and nothing else. reminder_sends is what stops
 *   the cron emailing about it again tomorrow (schema.sql).
 *
 * Advancing a reach-out on send would mean the app quietly forgives you for
 * not calling your sister, which is the one thing it exists to not do.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS FILE READS THE people TABLE DIRECTLY.
 * ---------------------------------------------------------------------------
 *
 * lib/people.php's header claims the people table, and this file reads three
 * of its columns anyway. Two reasons, both structural rather than lazy:
 *
 *   1. lib/people.php requires THIS file (for the birthday hook in
 *      people_add() and people_save()), so requiring it back is a cycle, and
 *      the cron reaches reminders_reconcile_birthdays() without loading
 *      lib/people.php at all.
 *   2. schema.sql already names both reads as this module's, in the comments
 *      on idx_birthday ("serves the birthday reconciliation pass the cron
 *      runs") and on reminders.next_due_date.
 *
 * They are READS of three columns and nothing else. Nothing here writes to
 * people — in particular not last_contact_date, which belongs to Phase 2A's
 * logging path.
 *
 * PORTABILITY NOTE, the same one lib/people.php carries. MySQL is the
 * production target; tools/test-harness.php runs these functions against
 * SQLite because the build environment has no MySQL. Every statement below is
 * in the intersection of the two, with one deliberate exception: the
 * ON DUPLICATE KEY UPDATE in reminders_upsert() and reminders_claim_send() is
 * written in MySQL and translated on its way through the connection, exactly
 * as docs/CONTRACTS.md §2 requires. */

declare(strict_types=1);

/* The two reminder types, spelled once. They are an ENUM in the schema, so a
 * typo is a database error rather than a silent no-op — but a database error
 * on the one screen that cannot show you one is still a screen that does
 * nothing, so the strings live here and callers use the constants. */
const REMINDER_REACH_OUT = 'reach_out';
const REMINDER_BIRTHDAY  = 'birthday';

/* The columns every read selects, in schema order. One constant so a column
 * added later is added in one place. */
const REMINDER_COLUMNS = 'id, person_id, type, recurrence_interval_days, next_due_date, created_at';

/* How far past today the dashboard's "this week" bucket reaches.
 *
 * SIX, NOT SEVEN, AND IT IS NOT AN OFF-BY-ONE. fmt_relative_due() phrases
 * anything within six days as a weekday name ("Friday") and anything beyond
 * that as a date, because seven days out is the same weekday as today and
 * "Monday" would be ambiguous. Ending the bucket where that phrasing ends
 * means every row under "This week" reads as a weekday, which is the whole
 * reason to have the bucket. */
const REMINDERS_WEEK_AHEAD = 6;

/* Bounds on a typed cadence. One day is a legitimate (if intense) choice; ten
 * years is where "remind me about this person" stops meaning anything and a
 * fat-fingered "60000" starts. Outside the range the value is refused rather
 * than clamped — a cadence silently rewritten to something else is a reminder
 * that fires on a day nobody chose. */
const REMINDER_CADENCE_MIN = 1;
const REMINDER_CADENCE_MAX = 3650;

/* ================================================================== config ==*/

/**
 * Days of warning before a birthday.
 *
 * Read here and nowhere else, so the dashboard, the profile and the cron
 * cannot disagree about it. The lead is applied when a reminder row is
 * WRITTEN, never when it is read (schema.sql) — which is why changing this
 * value only takes effect as rows are reconciled, and why the cron's nightly
 * full pass exists to make that "within 24 hours" rather than "eventually".
 */
function reminders_lead_days(): int
{
    $lead = (int) cfg('reminders.birthday_lead_days', 7);
    return $lead < 0 ? 0 : $lead;
}

/** The cadence the picker opens on. Only ever a default; the stored value wins. */
function reminders_default_cadence(): int
{
    $days = (int) cfg('reminders.default_cadence_days', 60);
    return $days < REMINDER_CADENCE_MIN || $days > REMINDER_CADENCE_MAX ? 60 : $days;
}

/**
 * A typed cadence, or null if it is not one.
 *
 * Returns null rather than a clamped number, deliberately — see
 * REMINDER_CADENCE_MAX. The caller answers with an error the person can see.
 */
function reminders_clean_cadence($raw): ?int
{
    if (!is_numeric($raw)) {
        return null;
    }
    $days = (int) $raw;
    return $days < REMINDER_CADENCE_MIN || $days > REMINDER_CADENCE_MAX ? null : $days;
}

/**
 * A typed one-off date as Y-m-d, or null if it is not a date.
 *
 * A date in the PAST is accepted and is not a mistake: docs/CONTRACTS.md §2 and
 * lib/dates.php both say a next_due_date earlier than today is correct, because
 * `<= today` fires it immediately instead of skipping it for a year. Somebody
 * typing yesterday means "I should already have done this", and the dashboard
 * is right to say so.
 */
function reminders_clean_date($raw): ?string
{
    if (!is_string($raw)) {
        return null;
    }
    $date = trim($raw);
    return crm_parse_date($date) === null ? null : substr($date, 0, 10);
}

/* ================================================================= reading ==*/

/** Cast one reminders row out of the database into the shape the app uses. */
function reminder_row(array $row): array
{
    return array(
        'id'                       => (int) $row['id'],
        'person_id'                => (int) $row['person_id'],
        'type'                     => (string) $row['type'],
        'recurrence_interval_days' => $row['recurrence_interval_days'] === null
            ? null
            : (int) $row['recurrence_interval_days'],
        'next_due_date'            => (string) $row['next_due_date'],
        'created_at'               => (string) ($row['created_at'] ?? ''),
    );
}

/**
 * The three people columns this file is allowed to read. See the file header.
 *
 * @return array{name: string, birth_month: int|null, birth_day: int|null, last_contact_date: string|null}|null
 */
function reminders_person(int $personId): ?array
{
    $row = q(
        'SELECT name, birth_month, birth_day, last_contact_date FROM people WHERE id = ?',
        array($personId)
    )->fetch();

    if ($row === false) {
        return null;
    }

    return array(
        'name'              => (string) $row['name'],
        'birth_month'       => $row['birth_month'] === null ? null : (int) $row['birth_month'],
        'birth_day'         => $row['birth_day'] === null ? null : (int) $row['birth_day'],
        'last_contact_date' => $row['last_contact_date'] === null || $row['last_contact_date'] === ''
            ? null
            : (string) $row['last_contact_date'],
    );
}

/** One person's reminder of one type, or null. UNIQUE (person_id, type) makes this at most one row. */
function reminders_get(int $personId, string $type): ?array
{
    $row = q(
        'SELECT ' . REMINDER_COLUMNS . ' FROM reminders WHERE person_id = ? AND type = ?',
        array($personId, $type)
    )->fetch();

    return $row === false ? null : reminder_row($row);
}

/** One reminder by its own id, or null. What the cron holds after the due query. */
function reminders_by_id(int $id): ?array
{
    $row = q('SELECT ' . REMINDER_COLUMNS . ' FROM reminders WHERE id = ?', array($id))->fetch();
    return $row === false ? null : reminder_row($row);
}

/**
 * Everything scheduled for one person, keyed by type.
 *
 * Keyed rather than a list because the caller always wants one specific kind —
 * the profile asks for the reach-out, the email asks for the birthday — and
 * there can only ever be one of each (UNIQUE (person_id, type)).
 */
function reminders_for_person(int $personId): array
{
    $rows = q(
        'SELECT ' . REMINDER_COLUMNS . ' FROM reminders WHERE person_id = ? ORDER BY type',
        array($personId)
    )->fetchAll();

    $byType = array();
    foreach ($rows as $row) {
        $reminder = reminder_row($row);
        $byType[$reminder['type']] = $reminder;
    }
    return $byType;
}

/**
 * Everything due on or before $through, newest deadline last, with the person.
 *
 * THE ONE QUERY THE DASHBOARD AND THE CRON BOTH RUN, and the reason
 * next_due_date is a DATE and idx_due exists. `<= ?` rather than `= ?`: an
 * overdue reminder must surface immediately rather than being missed because
 * nobody opened the app on the exact day (docs/CONTRACTS.md §2).
 *
 * Joined to people rather than looked up per row: the dashboard is the screen
 * opened daily and N+1 there is N+1 every morning. The three birthday columns
 * come along because the row's subtitle wants "April 15" spelled out, and
 * fetching them costs nothing once the join is there.
 *
 * @param string $through Y-m-d. crm_today() for the cron; today plus the week
 *                        window for the dashboard.
 */
function reminders_due(string $through): array
{
    $rows = q(
        'SELECT r.id, r.person_id, r.type, r.recurrence_interval_days, r.next_due_date, r.created_at,
                p.name AS person_name, p.birth_year, p.birth_month, p.birth_day, p.last_contact_date
           FROM reminders r
           JOIN people p ON p.id = r.person_id
          WHERE r.next_due_date <= ?
          ORDER BY r.next_due_date, p.name, r.id',
        array($through)
    )->fetchAll();

    $due = array();
    foreach ($rows as $row) {
        $reminder = reminder_row($row);
        $reminder['person_name']       = (string) $row['person_name'];
        $reminder['last_contact_date'] = $row['last_contact_date'] === null || $row['last_contact_date'] === ''
            ? null
            : (string) $row['last_contact_date'];
        $reminder['birth_year']  = $row['birth_year'] === null ? null : (int) $row['birth_year'];
        $reminder['birth_month'] = $row['birth_month'] === null ? null : (int) $row['birth_month'];
        $reminder['birth_day']   = $row['birth_day'] === null ? null : (int) $row['birth_day'];
        $due[] = $reminder;
    }
    return $due;
}

/**
 * The Today dashboard, in its three buckets.
 *
 * Overdue / today / this week, reach-outs and birthdays together — the screen
 * answers "who am I forgetting", and which mechanism produced the row is a
 * detail of the answer rather than a way to organise it.
 *
 * ONE QUERY, BUCKETED IN PHP. The horizon is computed with next_cadence_date(),
 * which is exactly "today plus N days" when there is no last contact to count
 * from — the same tested arithmetic the cadences use rather than a second copy
 * of it written here, and no DATE_ADD in the query.
 *
 * A row whose next_due_date will not parse (a hand-edited row, a bad restore)
 * lands in "overdue" rather than throwing: the query already decided it was
 * due, and one unreadable date must degrade one row, never the screen.
 *
 * @return array{overdue: array, today: array, week: array}
 */
function reminders_dashboard(string $today): array
{
    $horizon = next_cadence_date(null, REMINDERS_WEEK_AHEAD, $today);

    $buckets = array('overdue' => array(), 'today' => array(), 'week' => array());

    foreach (reminders_due($horizon) as $reminder) {
        $due = $reminder['next_due_date'];
        if ($due === $today) {
            $buckets['today'][] = $reminder;
        } elseif ($due > $today) {
            $buckets['week'][] = $reminder;
        } else {
            $buckets['overdue'][] = $reminder;
        }
    }

    return $buckets;
}

/* =============================================================== rendering ==*/

/**
 * A reminder said the way a person would say it.
 *
 *   "Every 60 days · next Friday"   "Once · August 10"   "Birthday · Yesterday"
 *
 * The date half is fmt_relative_due()'s, which keeps overdue reminders in days
 * ("34 days ago") however far back they go — how overdue something is, is the
 * information, and a reach-out deliberately does not advance until you log a
 * contact, so the number is allowed to get large.
 */
function reminders_label(array $reminder, string $today): string
{
    $when = fmt_relative_due($reminder['next_due_date'], $today);

    if ($reminder['type'] === REMINDER_BIRTHDAY) {
        return 'Birthday · ' . $when;
    }
    if ($reminder['recurrence_interval_days'] === null) {
        return 'Once · ' . $when;
    }
    return 'Every ' . $reminder['recurrence_interval_days'] . ' days · ' . $when;
}

/* ================================================================= writing ==*/

/**
 * Create or update one person's reminder of one type.
 *
 * AN UPSERT, NOT A DELETE-THEN-INSERT. UNIQUE (person_id, type) is what makes
 * "one reach-out schedule and one birthday schedule per person" a database
 * fact rather than an application habit (schema.sql), and a delete followed by
 * an insert can die in the middle and leave the person with neither.
 *
 * Written in MySQL. tools/test-harness.php translates ON DUPLICATE KEY UPDATE
 * into SQLite's ON CONFLICT DO UPDATE on the way through the connection, so
 * this exact statement is the one the tests exercise — see
 * docs/CONTRACTS.md §2.
 *
 * created_at is deliberately left alone on the update path: changing a cadence
 * is editing a schedule, not starting a new one.
 */
function reminders_upsert(int $personId, string $type, ?int $intervalDays, string $dueDate): void
{
    q(
        'INSERT INTO reminders (person_id, type, recurrence_interval_days, next_due_date)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE recurrence_interval_days = VALUES(recurrence_interval_days),
                                 next_due_date = VALUES(next_due_date)',
        array($personId, $type, $intervalDays, $dueDate)
    );
}

/**
 * Remove one person's reminder of one type. True whether or not there was one —
 * the end state is what was asked for.
 *
 * The send ledger for it goes too, by cascade (schema.sql). That is correct:
 * the ledger's whole content is "this reminder was already emailed about on
 * this date", which means nothing once the reminder is gone.
 */
function reminders_clear(int $personId, string $type): bool
{
    q('DELETE FROM reminders WHERE person_id = ? AND type = ?', array($personId, $type));
    return true;
}

/**
 * Set, change or clear a person's reach-out reminder. Returns it, or null when
 * there now isn't one.
 *
 * The brief describes a reach-out as a repeating cadence OR a one-off date OR
 * neither — never both, never several — so this one function is the whole
 * control, and passing null for both is how you clear it. Passing both is a
 * caller bug and the cadence wins, because a cadence is the thing you meant to
 * keep.
 *
 * FOR A CADENCE, THE CLOCK STARTS FROM THE LAST CONTACT, NOT FROM TODAY.
 * Setting "every 60 days" on somebody you spoke to 50 days ago should be due in
 * ten days, not in sixty — otherwise setting a reminder quietly forgives the
 * gap you set it because of. next_cadence_date() counts from today only when
 * there is no last contact at all, which is the honest answer for somebody you
 * have never spoken to (see its comment: four hundred freshly imported
 * contacts must not all be overdue tomorrow morning).
 *
 * @param int|null    $cadenceDays a repeating cadence in days
 * @param string|null $oneOffDate  Y-m-d, for a single "remind me on the 3rd"
 */
function reminders_set_reach_out(int $personId, ?int $cadenceDays, ?string $oneOffDate, string $today): ?array
{
    if ($cadenceDays === null && $oneOffDate === null) {
        reminders_clear($personId, REMINDER_REACH_OUT);
        return null;
    }

    if ($cadenceDays !== null) {
        $person = reminders_person($personId);
        if ($person === null) {
            return null;
        }
        $due = next_cadence_date($person['last_contact_date'], $cadenceDays, $today);
        reminders_upsert($personId, REMINDER_REACH_OUT, $cadenceDays, $due);
    } else {
        /* recurrence_interval_days NULL is what MAKES it a one-off (schema.sql).
         * There is no separate flag, and the upsert has to write the NULL
         * explicitly or a cadence changed into a one-off would keep its old
         * interval and quietly repeat. */
        reminders_upsert($personId, REMINDER_REACH_OUT, null, $oneOffDate);
    }

    return reminders_get($personId, REMINDER_REACH_OUT);
}

/**
 * Bring one person's birthday reminder into line with their birthday.
 *
 * THE FIRST OF THE TWO PLACES THIS HAPPENS. The other is
 * reminders_reconcile_birthdays(), the full pass at the top of every cron run.
 * Both, on purpose (PLAN.md §4.5, schema.sql): a reminder that silently never
 * fires is undetectable by the person relying on it, so the app is willing to
 * do the work twice to make "somebody edited a birthday by a path that forgot
 * to reconcile" self-heal within 24 hours instead of never.
 *
 * Three cases, and it must handle all three:
 *   - a birthday appeared   -> create the row
 *   - a birthday changed    -> recompute next_due_date
 *   - a birthday was cleared-> delete the row
 *
 * The date is birthday_reminder_date()'s and not computed here. That function
 * finds the next occurrence of the birthday FIRST and subtracts the lead
 * SECOND, which is the difference between reminding on December 27th about a
 * January 3rd birthday and reminding 358 days late — see its comment. It may
 * return a date in the past, and that is correct: `<= today` fires it now.
 *
 * IT DOES NOT PRESERVE AN ALREADY-SENT ROLL-FORWARD, and that is the one thing
 * to know about running it nightly. A birthday reminder that fired and advanced
 * to next year is recomputed back to this year's lead date if that date is
 * still ahead of the birthday — but by then the birthday itself has moved on,
 * so next_birthday() returns next year's occurrence and the answer is the same
 * date the send already advanced it to. reminder_sends is keyed on the due date
 * either way, so even a recompute that disagreed could not produce a second
 * email for a date already sent.
 */
function reminders_reconcile_birthday(int $personId, string $today): void
{
    $person = reminders_person($personId);
    if ($person === null) {
        /* Deleted between the write and this call. The cascade already took any
         * reminder with them; there is nothing to reconcile and nothing wrong. */
        return;
    }

    $month = $person['birth_month'];
    $day   = $person['birth_day'];

    /* birth_month NULL means NO BIRTHDAY RECORDED, which is a different state
     * from an unknown year and is the one that means "delete the reminder".
     * schema.sql and lib/people.php both refuse to store a day without a
     * month, so testing both is belt and braces against a hand-edited row. */
    if ($month === null || $day === null) {
        reminders_clear($personId, REMINDER_BIRTHDAY);
        return;
    }

    $due = birthday_reminder_date($month, $day, $today, reminders_lead_days());

    /* recurrence_interval_days stays NULL for a birthday: its recurrence is
     * "annually" and is implicit in the type, not a number that could drift to
     * 364 (schema.sql). */
    reminders_upsert($personId, REMINDER_BIRTHDAY, null, $due);
}

/**
 * Reconcile EVERY person's birthday reminder. Returns how many were touched.
 *
 * The second of the two places, and the cron's first act on every run
 * (PLAN.md §7.2, step 1) — before it looks for anything due, so a birthday
 * added today by any path at all is already correct by the time the same run
 * decides what to email about.
 *
 * A full pass over a few hundred rows costs nothing, which is what makes belt
 * and braces affordable here.
 *
 * TWO SCANS, NOT ONE. People WITH a birthday are reconciled; reminders whose
 * person no longer has one are deleted. The second is not covered by the first,
 * because a person whose birthday was cleared no longer appears in the
 * birthday index at all — and the row left behind would fire an email about a
 * birthday nobody recorded.
 *
 * One unreadable row degrades to one unreconciled reminder, logged, rather
 * than to a cron run that dies before sending anything.
 */
function reminders_reconcile_birthdays(string $today): int
{
    $touched = 0;

    /* Exactly the query schema.sql's idx_birthday comment says it serves. */
    $withBirthday = q('SELECT id FROM people WHERE birth_month IS NOT NULL AND birth_day IS NOT NULL')
        ->fetchAll();

    foreach ($withBirthday as $row) {
        try {
            reminders_reconcile_birthday((int) $row['id'], $today);
            $touched++;
        } catch (Throwable $e) {
            error_log('reminders: reconcile failed for person ' . (int) $row['id'] . ': ' . $e->getMessage());
        }
    }

    $orphans = q(
        'SELECT r.id
           FROM reminders r
           JOIN people p ON p.id = r.person_id
          WHERE r.type = ? AND (p.birth_month IS NULL OR p.birth_day IS NULL)',
        array(REMINDER_BIRTHDAY)
    )->fetchAll();

    foreach ($orphans as $row) {
        q('DELETE FROM reminders WHERE id = ?', array((int) $row['id']));
        $touched++;
    }

    return $touched;
}

/**
 * What logging a contact does to that person's reach-out reminder.
 *
 * ================== THE FUNCTION Phase 2A's contact-log path CALLS ===========
 *
 *   reminders_contact_logged(int $personId, string $contactDate, string $today): void
 *
 * Call it AFTER writing people.last_contact_date and the contact_log row.
 * $contactDate is the date being logged (crm_today() for the dashboard's 1-tap
 * button, a chosen date for a backdated entry) and is passed rather than read
 * back out of people, so this cannot depend on which order the writes happened
 * in — and so it is testable without one.
 * ============================================================================
 *
 * A CADENCE IS RESET: next_due_date = the logged date + the interval. This is
 * the only thing in the app that moves a reach-out reminder, which is the point
 * (PLAN.md §7.2): the cron sending an email does not move it, so it stays
 * overdue and keeps showing on the dashboard until you have actually spoken to
 * somebody.
 *
 * A ONE-OFF IS DELETED — but only if it had come due. "Remind me about Sam on
 * the 3rd" is satisfied by talking to Sam on or after the 3rd, and the row has
 * no next occurrence to move to. A one-off still in the FUTURE has not asked
 * you for anything yet, so an unrelated conversation today must not silently
 * consume it: that would be deliberate, dated, irrecoverable data loss caused
 * by a button whose entire promise is "I talked to them". docs/CONTRACTS.md §2
 * says "a one-off that has now been satisfied", and this is what makes the
 * qualifier mean something.
 *
 * Doing this twice in one day is harmless: the second call computes the same
 * date from the same inputs. That matches last_contact_date being a DATE and
 * "Logged today" tapped twice writing two log rows and moving nothing
 * (CLAUDE.md).
 *
 * The BIRTHDAY reminder is not touched. Calling somebody is not their birthday
 * happening.
 */
function reminders_contact_logged(int $personId, string $contactDate, string $today): void
{
    $reminder = reminders_get($personId, REMINDER_REACH_OUT);
    if ($reminder === null) {
        return;
    }

    if ($reminder['recurrence_interval_days'] === null) {
        if ($reminder['next_due_date'] <= $today) {
            reminders_clear($personId, REMINDER_REACH_OUT);
        }
        return;
    }

    $due = next_cadence_date($contactDate, $reminder['recurrence_interval_days'], $today);
    q('UPDATE reminders SET next_due_date = ? WHERE id = ?', array($due, $reminder['id']));
}

/* ============================================================ the send path ==*/

/*
 * Phase 3's cron owns tools/cron-reminders.php; the three functions below are
 * what it calls, because they are reminder data access and belong beside the
 * rest of it. They are here rather than there for the same reason the rest of
 * this file is: they are testable without SMTP, and the double-send rule is the
 * one behaviour in the app that absolutely has to be right.
 */

/**
 * Stake a claim on sending this reminder for this due date. True = go and send.
 *
 * THE ONE STATEMENT THAT MAKES A DOUBLE-SEND IMPOSSIBLE, and it is safe to run
 * twice because the database enforces it: (reminder_id, due_date) is
 * reminder_sends' PRIMARY KEY, so the insert either creates the row or bumps
 * its attempt count, and nothing can create a second one (schema.sql). This is
 * the exact statement docs/CONTRACTS.md §2 specifies, in MySQL, translated for
 * SQLite on the way through the connection.
 *
 * It answers false only for a row that has ALREADY BEEN DELIVERED. A previous
 * attempt that failed left sent_at NULL, and retrying it tomorrow is the
 * intended behaviour — a hung SMTP connection must not cost you the birthday.
 *
 * Note what makes this work for a reach-out: its next_due_date does not move
 * when it sends, so tomorrow's run claims the SAME (reminder_id, due_date)
 * pair, finds it delivered, and stays quiet — while the dashboard goes on
 * showing the reminder. That is the whole mechanism behind "overdue but not
 * nagging by email".
 */
function reminders_claim_send(int $reminderId, string $dueDate): bool
{
    q(
        'INSERT INTO reminder_sends (reminder_id, due_date, attempts) VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE attempts = attempts + 1',
        array($reminderId, $dueDate)
    );

    $row = q(
        'SELECT sent_at FROM reminder_sends WHERE reminder_id = ? AND due_date = ?',
        array($reminderId, $dueDate)
    )->fetch();

    return $row !== false && ($row['sent_at'] === null || $row['sent_at'] === '');
}

/**
 * Record a delivered send.
 *
 * The timestamp comes from the caller rather than from NOW(), keeping the one
 * clock rule intact — and it is the answer to "when did that email actually
 * go", which is a question asked exactly once, in a panic (schema.sql).
 */
function reminders_mark_sent(int $reminderId, string $dueDate, string $sentAt): void
{
    q(
        'UPDATE reminder_sends SET sent_at = ?, last_error = NULL WHERE reminder_id = ? AND due_date = ?',
        array($sentAt, $reminderId, $dueDate)
    );
}

/**
 * Record a failed send. sent_at stays NULL, so tomorrow's run tries again.
 *
 * Truncated to the column width because this is a diagnostic beside the row,
 * not a log — the full text belongs in error_log (schema.sql).
 */
function reminders_mark_failed(int $reminderId, string $dueDate, string $error): void
{
    q(
        'UPDATE reminder_sends SET last_error = ? WHERE reminder_id = ? AND due_date = ?',
        array(mb_substr($error, 0, 255, 'UTF-8'), $reminderId, $dueDate)
    );
}

/**
 * What happens to a reminder once it has been emailed about. True if it moved.
 *
 * THE ASYMMETRY, IN ONE FUNCTION, SO THERE IS ONE PLACE TO GET IT RIGHT
 * (PLAN.md §7.2, step 3d).
 *
 * A BIRTHDAY rolls forward to next year's lead date. It has fired, the birthday
 * is coming, and nothing more is expected of you this year.
 *
 * A REACH-OUT DOES NOT MOVE, and returning false here is not a failure — it is
 * the feature. It stays overdue, the dashboard keeps showing it, and it gets
 * louder as the number of days climbs. reminder_sends is what stops tomorrow's
 * run emailing about it again. Advancing it here would mean the app quietly
 * forgives you for not calling your sister.
 *
 * Next year's date is found by asking birthday_reminder_date() from the day
 * AFTER the birthday itself, not from today: today is the lead date, so asking
 * from here would find the same birthday again and the reminder would never
 * move. The +1 is on the birthday, so a Feb 29 birthday clamped to Feb 28 still
 * lands in the following year rather than on itself.
 */
function reminders_advance_after_send(int $reminderId, string $today): bool
{
    $reminder = reminders_by_id($reminderId);
    if ($reminder === null || $reminder['type'] !== REMINDER_BIRTHDAY) {
        return false;
    }

    $person = reminders_person($reminder['person_id']);
    if ($person === null || $person['birth_month'] === null || $person['birth_day'] === null) {
        return false;
    }

    $month = $person['birth_month'];
    $day   = $person['birth_day'];

    $birthday = crm_parse_date(next_birthday($month, $day, $today));
    if ($birthday === null) {
        return false;
    }

    $after = $birthday->modify('+1 day')->format('Y-m-d');
    $next  = birthday_reminder_date($month, $day, $after, reminders_lead_days());

    q('UPDATE reminders SET next_due_date = ? WHERE id = ?', array($next, $reminderId));
    return true;
}
