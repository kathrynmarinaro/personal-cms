<?php
/* Gift ideas and the contact log — the two things you do TO a person.
 *
 * Nothing outside this file writes SQL against gift_ideas or contact_log, and
 * nothing outside it writes people.last_contact_date either. That column is
 * named as this track's in lib/people.php's header and in docs/CONTRACTS.md §2:
 * people_save() deliberately leaves it alone, because an identity edit is not a
 * conversation. Every statement here is prepared, via q().
 *
 * ---------------------------------------------------------------------------
 * LOGGING A CONTACT IS THREE WRITES AND THEY ONLY MEAN ANYTHING TOGETHER.
 * ---------------------------------------------------------------------------
 *
 *   1. the contact_log row      — the history, "when did we last talk about
 *                                 the wedding"
 *   2. people.last_contact_date — the cadence clock, a DATE, cheap to compare
 *   3. reminders_contact_logged() — what that does to the reach-out reminder
 *
 * The third is Phase 2B's and is CALLED, never re-implemented: it resets a
 * cadence to the logged date plus the interval, deletes a one-off that had
 * already come due, leaves a future one-off and the birthday reminder alone,
 * and is a no-op for somebody with no reach-out reminder. Every one of those
 * rules has a test in tools/tests-reminders.php. A second copy of any of them
 * here would be a bug the day one of the two was fixed.
 *
 * ---------------------------------------------------------------------------
 * WHY LOGGING TWICE IN ONE DAY IS TWO ROWS AND ONE DATE.
 * ---------------------------------------------------------------------------
 *
 * schema.sql and CLAUDE.md both say it: contact_log is a DATETIME and gets a
 * row per conversation, last_contact_date is a DATE and does not move. Two
 * calls in one day ARE two conversations — the history should show both — and
 * the cadence clock only ever looks at the date, so there is nothing for it to
 * move to. This file therefore does not de-duplicate anything, and the guard on
 * the UPDATE below is "only ever forward", not "only once".
 *
 * ---------------------------------------------------------------------------
 * NOT ONE FUNCTION HERE ASKS WHAT DAY IT IS.
 * ---------------------------------------------------------------------------
 *
 * $today and $contactDate are parameters, from a single crm_today() at the top
 * of a request. lib/dates.php's header explains why at length. The one clock
 * this file does read is the DATABASE's, for contact_log.logged_at, and only
 * for a contact logged today — see contact_log_add(), which says why that is
 * the schema's own design rather than an exception to the rule.
 *
 * PORTABILITY NOTE, the same one the other repos carry. MySQL is the production
 * target; tools/test-harness.php runs these functions against SQLite because
 * the build environment has no MySQL. Every statement below is in the
 * intersection of the two: no INTERVAL, no DATE_ADD, no NOW(), no DATE(). */

declare(strict_types=1);

/* people_get(), for the existence check both writers make before inserting a
 * child row. Cheaper to ask than to catch a foreign-key violation and try to
 * work out which constraint it was. */
require_once __DIR__ . '/people.php';

/* Phase 2B's, for the one call at the end of contact_log_add(). Required
 * explicitly rather than leant on: lib/people.php happens to pull it in today
 * for its birthday hook, and a require that works by accident is one refactor
 * away from a reach-out reminder that silently stops resetting. */
require_once __DIR__ . '/reminders.php';

/* Column widths, mirrored from schema.sql so the app truncates rather than
 * letting MySQL's strict mode reject a whole INSERT over one long paste. Both
 * are 500 for the same reason: these are sentences ("the walnut cutting board
 * from the shop on Grand"), not product names or subject lines. */
const GIFT_TEXT_MAX    = 500;   // gift_ideas.idea_text
const CONTACT_NOTE_MAX = 500;   // contact_log.note

/* The columns every read selects, in schema order. One constant each so a
 * column added later is added in one place rather than in four SELECTs. */
const GIFT_COLUMNS        = 'id, person_id, idea_text, created_at';
const CONTACT_LOG_COLUMNS = 'id, person_id, logged_at, note';

/* The time of day a BACKDATED entry is stamped with. See contact_log_add().
 *
 * Midday, not midnight: a backdated entry has no time and one has to be
 * invented for a DATETIME column, so the honest choice is the one furthest from
 * both edges of the day — noon survives a twelve-hour shift in either direction
 * without changing the date it is filed under, and it reads as "sometime that
 * day" rather than as a suspiciously precise 00:00:00. */
const CONTACT_BACKDATE_TIME = ' 12:00:00';

/* ================================================================ cleaning ==*/

/**
 * Trim and cap a gift idea, or null if there is nothing left.
 *
 * TRIMMED AND NOTHING ELSE, the same rule people_clean_name() follows: this is
 * a phrase somebody typed on a phone and the app has no business improving it.
 */
function gift_clean_text(?string $raw): ?string
{
    $text = trim((string) $raw);
    if ($text === '') {
        return null;
    }
    return mb_substr($text, 0, GIFT_TEXT_MAX, 'UTF-8');
}

/**
 * Trim and cap a contact note, or null.
 *
 * NULL IS THE ORDINARY CASE AND NOT A FAILURE. The 1-tap "Logged today" button
 * writes a row with no note at all (schema.sql), and it has to stay one tap —
 * requiring a note is how a logging button stops being used, and an app nobody
 * logs into is an app that knows nothing about when you last called anyone.
 */
function contact_clean_note(?string $raw): ?string
{
    $note = trim((string) $raw);
    if ($note === '') {
        return null;
    }
    return mb_substr($note, 0, CONTACT_NOTE_MAX, 'UTF-8');
}

/* ============================================================ gift reading ==*/

/** Cast one gift_ideas row out of the database into the shape the app uses. */
function gift_row(array $row): array
{
    return array(
        'id'         => (int) $row['id'],
        'person_id'  => (int) $row['person_id'],
        'idea_text'  => (string) $row['idea_text'],
        'created_at' => (string) ($row['created_at'] ?? ''),
    );
}

/** One gift idea by id, or null. */
function gift_get(int $id): ?array
{
    $row = q('SELECT ' . GIFT_COLUMNS . ' FROM gift_ideas WHERE id = ?', array($id))->fetch();
    return $row === false ? null : gift_row($row);
}

/**
 * One person's gift ideas, newest first.
 *
 * ORDER BY id DESC, AND THERE IS NO sort_order COLUMN TO ORDER BY INSTEAD.
 * That is a decision, not an omission: manual ordering would mean a column and
 * a -order.php endpoint for a list that is typically three items long
 * (PLAN.md §4.6, CLAUDE.md). public/assets/reorder.js is ported anyway and
 * wired to nothing, so if it turns out to matter the module is already sitting
 * there — but until then, do not sort this by anything else.
 *
 * By id rather than by created_at because id is what idx_person (person_id, id)
 * is built on, and because two ideas typed in the same second still have an
 * order that way.
 */
function gifts_for_person(int $personId): array
{
    $rows = q(
        'SELECT ' . GIFT_COLUMNS . ' FROM gift_ideas WHERE person_id = ? ORDER BY id DESC',
        array($personId)
    )->fetchAll();

    return array_map('gift_row', $rows);
}

/* ============================================================ gift writing ==*/

/**
 * Add a gift idea. Null when there is no such person or nothing to store.
 *
 * The person is checked rather than left to the foreign key: this is reached
 * from an endpoint that has to answer with a code the screen can show, and
 * "which constraint did MySQL just object to" is not something a caller should
 * have to work out from an exception message.
 */
function gift_add(int $personId, string $text): ?array
{
    $clean = gift_clean_text($text);
    if ($clean === null || people_get($personId) === null) {
        return null;
    }

    q(
        'INSERT INTO gift_ideas (person_id, idea_text) VALUES (?, ?)',
        array($personId, $clean)
    );

    return gift_get((int) db()->lastInsertId());
}

/**
 * Change a gift idea's text. Null when there is no such row or nothing to
 * store.
 *
 * An emptied editor is a null here and the caller refuses it, which matches
 * inline-edit.js: an emptied field CANCELS rather than deleting, because delete
 * has its own gesture with its own undo (docs/CONTRACTS.md §5).
 */
function gift_rename(int $id, string $text): ?array
{
    $clean = gift_clean_text($text);
    if ($clean === null || gift_get($id) === null) {
        return null;
    }

    q('UPDATE gift_ideas SET idea_text = ? WHERE id = ?', array($clean, $id));
    return gift_get($id);
}

/**
 * Delete a gift idea and hand back what it was. Null if it was already gone.
 *
 * A HARD DELETE, unlike an import draft, which is marked instead. A draft has a
 * status column because the review queue needs to remember a decision; a gift
 * idea has nowhere to put one, and inventing a column so that a five-second
 * undo can be an UPDATE would be a schema change for an interaction that
 * gift_restore() already covers honestly.
 *
 * The returned row is what makes that undo possible: swipe.js fires the delete
 * the moment the gesture completes and treats Undo as a RESTORE rather than a
 * cancellation, so the client holds these fields for the five seconds the
 * snackbar is up and posts them back (docs/CONTRACTS.md §5).
 */
function gift_delete(int $id): ?array
{
    $gift = gift_get($id);
    if ($gift === null) {
        return null;
    }

    q('DELETE FROM gift_ideas WHERE id = ?', array($id));
    return $gift;
}

/**
 * Put a deleted gift idea back. Null when there is no such person.
 *
 * IT TRIES TO KEEP THE ORIGINAL id, which is the whole reason this is not just
 * gift_add() under another name. Gift ideas sort newest-first BY ID, so a
 * restore that took a fresh id would drop the row back into the list at the
 * top — in a different place from the one it was swiped out of, and different
 * again from where the undo animation just put it back. The row is gone from
 * the database, so the id is free (neither MySQL's AUTO_INCREMENT nor SQLite's
 * AUTOINCREMENT hands a deleted id out again), and re-using it makes the list
 * after a reload agree with the list on screen.
 *
 * If that id has somehow been taken — a restore posted twice, a database
 * restored from a dump — it falls back to an ordinary insert and returns the
 * new row. swipe.js adopts whatever id comes back into the element's data-id,
 * so the caller does not have to re-render the list to fix one attribute.
 *
 * created_at is deliberately NOT taken from the caller. Nothing reads it (the
 * ordering is by id) and a client-supplied timestamp is a write path for a
 * value the client has no business setting.
 */
function gift_restore(int $id, int $personId, string $text): ?array
{
    $clean = gift_clean_text($text);
    if ($clean === null || people_get($personId) === null) {
        return null;
    }

    if ($id > 0 && gift_get($id) === null) {
        try {
            q(
                'INSERT INTO gift_ideas (id, person_id, idea_text) VALUES (?, ?, ?)',
                array($id, $personId, $clean)
            );
            return gift_get($id);
        } catch (Throwable $e) {
            /* Lost the race, or the id is not insertable for some reason this
             * code cannot see. Fall through: coming back with a new id is worth
             * far more than coming back in the right position. */
            error_log('contact: restoring gift ' . $id . ' by id failed: ' . $e->getMessage());
        }
    }

    return gift_add($personId, $clean);
}

/* ===================================================== contact log reading ==*/

/** Cast one contact_log row out of the database into the shape the app uses. */
function contact_row(array $row): array
{
    return array(
        'id'        => (int) $row['id'],
        'person_id' => (int) $row['person_id'],
        'logged_at' => (string) $row['logged_at'],
        'note'      => $row['note'] === null || $row['note'] === '' ? null : (string) $row['note'],
    );
}

/** One log entry by id, or null. */
function contact_log_get(int $id): ?array
{
    $row = q('SELECT ' . CONTACT_LOG_COLUMNS . ' FROM contact_log WHERE id = ?', array($id))->fetch();
    return $row === false ? null : contact_row($row);
}

/**
 * One person's history, newest first.
 *
 * Exactly the query schema.sql's idx_person_time comment says it serves, plus
 * `id DESC` as the tie-break — two conversations logged in the same second are
 * two rows with the same logged_at, and without the tie-break the order they
 * come back in is whatever the storage engine feels like.
 */
function contact_log_for_person(int $personId): array
{
    $rows = q(
        'SELECT ' . CONTACT_LOG_COLUMNS . ' FROM contact_log
          WHERE person_id = ? ORDER BY logged_at DESC, id DESC',
        array($personId)
    )->fetchAll();

    return array_map('contact_row', $rows);
}

/** How many conversations are on record for one person. The accordion's count. */
function contact_log_count(int $personId): int
{
    $row = q('SELECT COUNT(*) AS n FROM contact_log WHERE person_id = ?', array($personId))->fetch();
    return $row === false ? 0 : (int) $row['n'];
}

/**
 * One history row's two lines.
 *
 *   with a note:    "Called about the move" / "August 1, 2026 · 2 days ago"
 *   without one:    "August 1, 2026"        / "2 days ago"
 *
 * THE NOTE TAKES THE TOP LINE WHEN THERE IS ONE, because .row-text is the line
 * that wraps and .row-sub is the line that is grey and small — and what you are
 * scanning this list for is what you talked about, with the date as the answer
 * to "when was that". With no note there is nothing else to promote, and
 * putting the date up there is also what stops .row-text rendering its
 * "(untitled)" placeholder for a perfectly ordinary one-tap log.
 *
 * A logged_at that will not parse degrades to the raw string on the top line
 * and an empty second line, rather than throwing: one malformed row must cost
 * one row, never the screen it is on.
 *
 * @return array{text: string, sub: string}
 */
function contact_log_lines(array $entry, string $today): array
{
    $stamp = (string) ($entry['logged_at'] ?? '');
    $date  = fmt_date($stamp);
    $ago   = crm_parse_date($stamp) === null ? '' : fmt_relative_due(substr($stamp, 0, 10), $today);

    if ($date === '') {
        return array('text' => $stamp, 'sub' => '');
    }

    $note = $entry['note'] ?? null;
    if ($note === null || $note === '') {
        return array('text' => $date, 'sub' => $ago);
    }

    return array('text' => (string) $note, 'sub' => $ago === '' ? $date : $date . ' · ' . $ago);
}

/* ===================================================== contact log writing ==*/

/**
 * The date of the most recent logged conversation, or null for none.
 *
 * MAX() over a DATETIME and then a substr, rather than MAX(DATE(logged_at)):
 * DATE() is one more function to teach the test harness for an answer PHP can
 * take off the front of the string, and the two are identical here because a
 * DATETIME sorts lexically the same way it sorts chronologically.
 */
function contact_last_logged_date(int $personId): ?string
{
    $row = q('SELECT MAX(logged_at) AS latest FROM contact_log WHERE person_id = ?', array($personId))->fetch();
    if ($row === false || $row['latest'] === null || $row['latest'] === '') {
        return null;
    }

    return substr((string) $row['latest'], 0, 10);
}

/**
 * Log a conversation. Returns the new entry, or null if there is no such
 * person or the date will not parse.
 *
 * ============================ THE APP'S DAILY ACTION ========================
 *
 * Called by api/contact-log.php, which the dashboard's 1-tap button and the
 * profile's "Logged today" both post to.
 *
 * @param string $contactDate the date being logged, Y-m-d. crm_today() for the
 *                            1-tap button; a chosen date for a backdated entry.
 * @param string $today       from crm_today(), for the reminder arithmetic.
 * ============================================================================
 *
 * THE ORDER OF THE THREE WRITES IS THE ONE docs/CONTRACTS.md SPECIFIES: the log
 * row, then last_contact_date, then the reminder. The reminder call is handed
 * $contactDate rather than reading people back, so it cannot depend on the
 * write order — but doing them in the stated order anyway means a failure
 * halfway through leaves the history and the clock agreeing with each other.
 *
 * logged_at IS LEFT TO THE DATABASE FOR A CONTACT LOGGED TODAY, and that is not
 * a hole in the one-clock rule. The rule (CLAUDE.md, PLAN.md §5) is about
 * DECIDING A DUE DATE: no NOW(), no DATE_ADD, no INTERVAL anywhere a reminder
 * can be computed. Nothing here decides one — the cadence is reset from
 * $contactDate, in PHP. What is wanted for logged_at is a wall-clock time of
 * day, which is the one thing crm_today() does not carry, and schema.sql gives
 * the column DEFAULT CURRENT_TIMESTAMP precisely so it comes from the server's
 * clock. lib/db.php pins the connection's time zone to the configured one, so
 * that clock and PHP's are the same clock.
 *
 * A BACKDATED entry is stamped explicitly instead, at noon — see
 * CONTACT_BACKDATE_TIME. Letting the default fill it would file "we spoke on
 * the 3rd" under today.
 *
 * LAST CONTACT ONLY EVER MOVES FORWARD. The guard is in the WHERE clause rather
 * than in PHP so that it is one statement and cannot be raced: logging a
 * conversation you forgot about last month must not rewind the cadence clock
 * past a call you made yesterday, and logging today twice must leave the column
 * exactly where it was (CLAUDE.md).
 */
function contact_log_add(int $personId, ?string $note, string $contactDate, string $today): ?array
{
    if (crm_parse_date($contactDate) === null || people_get($personId) === null) {
        return null;
    }

    $date  = substr($contactDate, 0, 10);
    $clean = contact_clean_note($note);

    if ($date === $today) {
        q('INSERT INTO contact_log (person_id, note) VALUES (?, ?)', array($personId, $clean));
    } else {
        q(
            'INSERT INTO contact_log (person_id, logged_at, note) VALUES (?, ?, ?)',
            array($personId, $date . CONTACT_BACKDATE_TIME, $clean)
        );
    }

    $id = (int) db()->lastInsertId();

    q(
        'UPDATE people SET last_contact_date = ?
          WHERE id = ? AND (last_contact_date IS NULL OR last_contact_date < ?)',
        array($date, $personId, $date)
    );

    /* Phase 2B's, and the only thing in the app that moves a reach-out reminder.
     * CALLED, never re-implemented — see the file header. */
    reminders_contact_logged($personId, $date, $today);

    return contact_log_get($id);
}

/**
 * Remove one logged conversation and hand back what it was. Null if it was
 * already gone.
 *
 * THERE IS NO UNDO AND NO RESTORE ENDPOINT, deliberately, which is why this is
 * reached from a confirm rather than from a swipe. CLAUDE.md draws the line:
 * swipe-to-delete with a five-second window belongs on gift ideas and import
 * drafts, where the worst case is retyping a phrase. A log entry is a dated
 * record of something that happened, and the thing you would be undoing is not
 * the typing.
 *
 * IT PUTS last_contact_date BACK where the remaining history says it should be.
 * Nothing else in the app writes that column, so it is exactly the newest
 * logged_at — including NULL, when the last entry has just gone, which is a
 * real answer and not a reason to reach for created_at (schema.sql).
 *
 * IT DOES NOT REWIND THE REMINDER, and that is deliberate rather than
 * forgotten. reminders_contact_logged() moved the cadence to a date computed
 * from the log entry, and there is no record of what the reminder said before
 * that, so "put it back" would mean inventing a date nobody chose. The reminder
 * is a schedule you set, not a value derived from this table; the way to change
 * it is the control on the profile that sets it.
 */
function contact_log_delete(int $id): ?array
{
    $entry = contact_log_get($id);
    if ($entry === null) {
        return null;
    }

    q('DELETE FROM contact_log WHERE id = ?', array($id));

    q(
        'UPDATE people SET last_contact_date = ? WHERE id = ?',
        array(contact_last_logged_date($entry['person_id']), $entry['person_id'])
    );

    return $entry;
}
