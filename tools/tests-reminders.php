<?php
/* Phase 2B — Reminders. Auto-discovered and required at global scope by
 * tools/run-tests.php, so ok(), is_same(), throws(), section() and $T behave
 * exactly as they do inline.
 *
 * WHAT THESE ARE FOR. The date arithmetic itself is lib/dates.php's and is
 * already tested there without a database. What is tested HERE is everything
 * that decides WHICH of those functions to call and WHEN — which is where this
 * track can be wrong in a way nobody would notice:
 *
 *   * the asymmetry: a birthday rolls forward on send, a reach-out does not
 *   * logging a contact resets a cadence, and satisfies a one-off
 *   * a birthday reminder is reconciled from BOTH places, and both agree
 *   * reminder_sends refuses a second send for the same due date
 *
 * Every one of those is silent when it breaks. A reminder that never fires and
 * an email that fires twice are both invisible from inside the app.
 *
 * This file owns its setup: it clears reminders and reminder_sends (its own two
 * tables) and creates its own people, rather than assuming anything about what
 * the sections above left behind. It does NOT clear people — the other Phase 2
 * track files run beside this one. */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/people.php';
require_once dirname(__DIR__) . '/lib/reminders.php';

section('R · Reminders — reconciliation, recurrence and the due query');

/* Its own two tables, emptied. The foundation and People sections above create
 * people, and people_add() now reconciles a birthday reminder for each of them
 * — which is the hook working, and is also rows this section did not ask for. */
q('DELETE FROM reminder_sends');
q('DELETE FROM reminders');

/* A fixed "today". Not crm_today(): a test that passes only in April is not a
 * test. This is the whole reason every function in lib/reminders.php takes
 * $today as a parameter. */
$rToday = '2026-04-01';

/* ------------------------------------------- the hook in lib/people.php */

$rAlex = people_add(array('name' => 'Reminders Alex', 'birth_month' => 4, 'birth_day' => 15), $rToday);
$rNoBd = people_add(array('name' => 'Reminders Nobirthday'), $rToday);

$alexBirthday = reminders_get($rAlex, REMINDER_BIRTHDAY);
ok($alexBirthday !== null, 'people_add() materializes a birthday reminder — the Phase 2B hook is wired up');
is_same(
    $alexBirthday === null ? '' : $alexBirthday['next_due_date'],
    '2026-04-08',
    'and it lands on the birthday minus the configured lead, not on the birthday'
);
is_same(
    $alexBirthday === null ? 0 : $alexBirthday['recurrence_interval_days'],
    null,
    'recurrence_interval_days stays NULL — annual is implicit in the type, not a number that can drift to 364'
);
is_same(reminders_get($rNoBd, REMINDER_BIRTHDAY), null, 'somebody with no birthday gets no birthday reminder');

/* The three cases people_save() has to handle. */
people_save($rNoBd, array('name' => 'Reminders Nobirthday', 'birth_month' => 1, 'birth_day' => 3), $rToday);
$added = reminders_get($rNoBd, REMINDER_BIRTHDAY);
ok($added !== null, 'people_save() CREATES the reminder when a birthday is added');
is_same(
    $added === null ? '' : $added['next_due_date'],
    '2026-12-27',
    'a January 3rd birthday reminds on December 27th — the year boundary, found birthday-first'
);

people_save($rNoBd, array('name' => 'Reminders Nobirthday', 'birth_month' => 6, 'birth_day' => 20), $rToday);
$moved = reminders_get($rNoBd, REMINDER_BIRTHDAY);
is_same(
    $moved === null ? '' : $moved['next_due_date'],
    '2026-06-13',
    'people_save() RECOMPUTES it when the birthday changes'
);
is_same(
    (int) q('SELECT COUNT(*) AS n FROM reminders WHERE person_id = ? AND type = ?', array($rNoBd, REMINDER_BIRTHDAY))->fetch()['n'],
    1,
    'and it is an UPSERT, not a second row — UNIQUE (person_id, type) is the contract'
);

people_save($rNoBd, array('name' => 'Reminders Nobirthday'), $rToday);
is_same(reminders_get($rNoBd, REMINDER_BIRTHDAY), null, 'people_save() DELETES it when the birthday is cleared');

/* The unique key itself, proved rather than assumed. */
ok(
    throws(static function () use ($rAlex): void {
        q(
            'INSERT INTO reminders (person_id, type, recurrence_interval_days, next_due_date) VALUES (?, ?, NULL, ?)',
            array($rAlex, REMINDER_BIRTHDAY, '2026-04-08')
        );
    }),
    'the database refuses a second birthday reminder for the same person'
);

/* ----------------------------------------------- the leap-day clamp, in situ */

$rLeap = people_add(array('name' => 'Reminders Leapling', 'birth_month' => 2, 'birth_day' => 29), $rToday);
reminders_reconcile_birthday($rLeap, '2027-01-01');
is_same(
    reminders_get($rLeap, REMINDER_BIRTHDAY)['next_due_date'],
    '2027-02-21',
    'a February 29 birthday reminds on February 21 in a non-leap year — clamped to the 28th, then the lead'
);

/* ------------------------------------------------------- the full cron pass */

$expectedBirthdays = (int) q('SELECT COUNT(*) AS n FROM people WHERE birth_month IS NOT NULL AND birth_day IS NOT NULL')
    ->fetch()['n'];
q('DELETE FROM reminders WHERE type = ?', array(REMINDER_BIRTHDAY));
reminders_reconcile_birthdays($rToday);
is_same(
    (int) q('SELECT COUNT(*) AS n FROM reminders WHERE type = ?', array(REMINDER_BIRTHDAY))->fetch()['n'],
    $expectedBirthdays,
    'the cron pass rebuilds every birthday reminder from scratch — belt and braces, so one that never fires self-heals'
);

/* An orphan: a birthday reminder whose person no longer has a birthday. The
 * first scan cannot see it, because that person is no longer in the birthday
 * index at all — which is exactly why there is a second scan. */
q('UPDATE people SET birth_month = NULL, birth_day = NULL WHERE id = ?', array($rLeap));
reminders_reconcile_birthdays($rToday);
is_same(
    reminders_get($rLeap, REMINDER_BIRTHDAY),
    null,
    'and it deletes a birthday reminder left behind by a birthday that was cleared'
);

/* -------------------------------------------------- setting a reach-out */

$rSam = people_add(array('name' => 'Reminders Sam'), $rToday);

$never = reminders_set_reach_out($rSam, 60, null, $rToday);
is_same(
    $never === null ? '' : $never['next_due_date'],
    '2026-05-31',
    'a cadence on somebody never contacted counts from today — 400 imported contacts must not all be overdue tomorrow'
);

q('UPDATE people SET last_contact_date = ? WHERE id = ?', array('2026-03-01', $rSam));
$fromLast = reminders_set_reach_out($rSam, 60, null, $rToday);
is_same(
    $fromLast === null ? '' : $fromLast['next_due_date'],
    '2026-04-30',
    'and on somebody you have spoken to it counts from the last contact, not from today'
);
is_same($fromLast === null ? 0 : $fromLast['recurrence_interval_days'], 60, 'the cadence is stored as the interval');

$once = reminders_set_reach_out($rSam, null, '2026-04-20', $rToday);
is_same($once === null ? '' : $once['next_due_date'], '2026-04-20', 'a one-off is due on exactly the day asked for');
is_same(
    $once === null ? 0 : $once['recurrence_interval_days'],
    null,
    'and switching a cadence to a one-off CLEARS the interval — otherwise it would quietly keep repeating'
);
is_same(
    (int) q('SELECT COUNT(*) AS n FROM reminders WHERE person_id = ? AND type = ?', array($rSam, REMINDER_REACH_OUT))->fetch()['n'],
    1,
    'changing a reminder is an upsert, so there is still exactly one'
);

is_same(reminders_set_reach_out($rSam, null, null, $rToday), null, 'passing neither clears the reminder');
is_same(reminders_get($rSam, REMINDER_REACH_OUT), null, 'and it is really gone');

/* A date in the PAST is accepted, and is not a mistake. */
$overdue = reminders_set_reach_out($rSam, null, '2026-03-20', $rToday);
is_same(
    $overdue === null ? '' : $overdue['next_due_date'],
    '2026-03-20',
    'a due date in the past is stored as given — `<= today` fires it now rather than skipping it for a year'
);

/* ------------------------------------------------ logging a contact */

reminders_set_reach_out($rSam, 60, null, $rToday);
reminders_contact_logged($rSam, $rToday, $rToday);
is_same(
    reminders_get($rSam, REMINDER_REACH_OUT)['next_due_date'],
    '2026-05-31',
    'logging a contact resets a cadence to the logged date plus the interval'
);

reminders_contact_logged($rSam, $rToday, $rToday);
is_same(
    reminders_get($rSam, REMINDER_REACH_OUT)['next_due_date'],
    '2026-05-31',
    'logging twice in one day computes the same date twice — two conversations, one cadence clock'
);

/* A backdated entry counts from the day it happened, not from today. */
reminders_contact_logged($rSam, '2026-03-10', $rToday);
is_same(
    reminders_get($rSam, REMINDER_REACH_OUT)['next_due_date'],
    '2026-05-09',
    'a backdated contact counts from the day it happened'
);

/* A one-off that had come due is satisfied and goes. */
reminders_set_reach_out($rSam, null, '2026-03-25', $rToday);
reminders_contact_logged($rSam, $rToday, $rToday);
is_same(
    reminders_get($rSam, REMINDER_REACH_OUT),
    null,
    'logging a contact deletes a one-off that had come due — it has been satisfied and has no next occurrence'
);

/* A one-off still in the future has not asked for anything yet. */
reminders_set_reach_out($rSam, null, '2026-09-01', $rToday);
reminders_contact_logged($rSam, $rToday, $rToday);
$future = reminders_get($rSam, REMINDER_REACH_OUT);
is_same(
    $future === null ? '' : $future['next_due_date'],
    '2026-09-01',
    'but a one-off still in the FUTURE survives — an unrelated chat today must not consume a dated reminder'
);

/* And nothing here touches the birthday. */
reminders_reconcile_birthday($rAlex, $rToday);
reminders_set_reach_out($rAlex, 30, null, $rToday);
reminders_contact_logged($rAlex, $rToday, $rToday);
is_same(
    reminders_get($rAlex, REMINDER_BIRTHDAY)['next_due_date'],
    '2026-04-08',
    'logging a contact leaves the birthday reminder alone — calling somebody is not their birthday happening'
);

reminders_contact_logged($rNoBd, $rToday, $rToday);
ok(true, 'logging a contact for somebody with no reach-out reminder is a no-op, not an error');

/* --------------------------------------------------- the due query */

q('DELETE FROM reminders');
$rDue = people_add(array('name' => 'Reminders Due'), $rToday);
$rTom = people_add(array('name' => 'Reminders Tomorrow'), $rToday);
$rWk  = people_add(array('name' => 'Reminders Weekend'), $rToday);
$rFar = people_add(array('name' => 'Reminders Far'), $rToday);
$rLate = people_add(array('name' => 'Reminders Late'), $rToday);

reminders_set_reach_out($rLate, null, '2026-03-02', $rToday);   // 30 days overdue
reminders_set_reach_out($rDue, null, $rToday, $rToday);         // today
reminders_set_reach_out($rTom, null, '2026-04-02', $rToday);    // tomorrow
reminders_set_reach_out($rWk, null, '2026-04-07', $rToday);     // today + 6, the last day in the week bucket
reminders_set_reach_out($rFar, null, '2026-04-08', $rToday);    // today + 7, out of the window

is_same(count(reminders_due($rToday)), 2, 'reminders_due() is `<= today`, so it takes the overdue one AND today\'s');
is_same(count(reminders_due('2026-04-02')), 3, 'and widening the horizon by a day takes one more');

$board = reminders_dashboard($rToday);
is_same(array_keys($board), array('overdue', 'today', 'week'), 'the dashboard has exactly three buckets');
is_same(count($board['overdue']), 1, 'overdue holds what fell due before today');
is_same(count($board['today']), 1, 'today holds what falls due today');
is_same(count($board['week']), 2, 'this week reaches six days ahead — as far as fmt_relative_due() still names a weekday');
is_same($board['overdue'][0]['person_name'], 'Reminders Late', 'the rows carry the person, from one joined query');
is_same(
    $board['week'][0]['next_due_date'],
    '2026-04-02',
    'and they come back in due-date order, soonest first'
);

/* ---------------------------------- the asymmetry, and the send ledger */

q('DELETE FROM reminders');
reminders_reconcile_birthday($rAlex, $rToday);
$bd = reminders_get($rAlex, REMINDER_BIRTHDAY);
q('UPDATE people SET last_contact_date = ? WHERE id = ?', array('2026-01-01', $rSam));
$ro = reminders_set_reach_out($rSam, 60, null, $rToday);   // due 2026-03-02, a month overdue

is_same($ro === null ? '' : $ro['next_due_date'], '2026-03-02', 'a reach-out sits where the cadence put it');

/* Asked on 2026-04-16, the day AFTER the April 15th birthday — not on the lead
 * date, which is when the email actually goes out.
 *
 * These two assertions originally asked on 2026-04-08 and passed, because the
 * function used to advance the moment it was sent. That was the bug: the email
 * goes out seven days early, so advancing on send hid the birthday from the
 * dashboard for the whole week you were meant to act on it. The rule is now the
 * same one the reach-out follows — the ledger stops the duplicate email, the due
 * date does not move until the thing is done. See PLAN.md §7.2.
 *
 * The lead-week and birthday-day cases, where this must NOT advance, are in
 * tools/tests-delivery.php beside the fix. */
ok(reminders_advance_after_send($bd['id'], '2026-04-16'), 'a BIRTHDAY reminder advances once the birthday has PASSED');
is_same(
    reminders_get($rAlex, REMINDER_BIRTHDAY)['next_due_date'],
    '2027-04-08',
    'to next year\'s lead date — the birthday is over, and nothing more is expected this year'
);

is_same(
    reminders_advance_after_send($ro['id'], $rToday),
    false,
    'a REACH-OUT reminder does not advance when it is sent, and saying so is the point of the function'
);
is_same(
    reminders_get($rSam, REMINDER_REACH_OUT)['next_due_date'],
    '2026-03-02',
    'IT STAYS OVERDUE. Advancing on send would mean the app quietly forgives you for not calling your sister'
);

/* reminder_sends is what stops it emailing again tomorrow. */
ok(reminders_claim_send($ro['id'], '2026-03-02'), 'the first claim on a (reminder, due date) pair says send');
ok(
    reminders_claim_send($ro['id'], '2026-03-02'),
    'a claim after a FAILED attempt still says send — a hung SMTP connection must not cost you the reminder'
);
is_same(
    (int) q('SELECT attempts AS n FROM reminder_sends WHERE reminder_id = ? AND due_date = ?', array($ro['id'], '2026-03-02'))->fetch()['n'],
    2,
    'and the attempt count goes up, through MySQL ON DUPLICATE KEY UPDATE translated for SQLite'
);

reminders_mark_sent($ro['id'], '2026-03-02', '2026-03-02 07:00:00');
is_same(
    reminders_claim_send($ro['id'], '2026-03-02'),
    false,
    'once it has been delivered, tomorrow\'s run claims the SAME pair and is refused — no second email'
);

is_same(
    (int) q('SELECT COUNT(*) AS n FROM reminder_sends WHERE reminder_id = ?', array($ro['id']))->fetch()['n'],
    1,
    'and there is still exactly one ledger row: (reminder_id, due_date) IS the primary key'
);

reminders_mark_failed($ro['id'], '2026-03-02', 'SMTP connect timed out');
$ledger = q('SELECT sent_at, last_error FROM reminder_sends WHERE reminder_id = ?', array($ro['id']))->fetch();
is_same((string) $ledger['last_error'], 'SMTP connect timed out', 'a failure reason is kept beside the row as a diagnostic');
ok($ledger['sent_at'] !== null, 'and recording one does not un-send an email that already went');

ok(
    throws(static function () use ($ro): void {
        q(
            'INSERT INTO reminder_sends (reminder_id, due_date) VALUES (?, ?)',
            array($ro['id'], '2026-03-02')
        );
    }),
    'the composite primary key refuses a duplicate send row on every path, including one written without reading the comment'
);

/* ------------------------------------------------------- cleaning and wording */

is_same(reminders_clean_cadence('60'), 60, 'a typed cadence is read as a number');
is_same(reminders_clean_cadence(0), null, 'zero is not a cadence');
is_same(reminders_clean_cadence(REMINDER_CADENCE_MAX + 1), null, 'and neither is a fat-fingered 3651 — refused, not clamped');
is_same(reminders_clean_cadence('soon'), null, 'nor is a word');
is_same(reminders_clean_date('2026-04-15'), '2026-04-15', 'a typed date is read');
is_same(reminders_clean_date('2026-02-30'), null, 'a date that does not exist is refused rather than rolled into March');
is_same(reminders_clean_date(''), null, 'and so is nothing at all');

is_same(
    reminders_label(array('type' => REMINDER_REACH_OUT, 'recurrence_interval_days' => 60, 'next_due_date' => '2026-04-01'), '2026-04-01'),
    'Every 60 days · Today',
    'a cadence reads as a cadence'
);
is_same(
    reminders_label(array('type' => REMINDER_REACH_OUT, 'recurrence_interval_days' => null, 'next_due_date' => '2026-03-01'), '2026-04-01'),
    'Once · 31 days ago',
    'a one-off reads as a one-off, and overdue stays in days however far back it goes'
);
is_same(
    reminders_label(array('type' => REMINDER_BIRTHDAY, 'recurrence_interval_days' => null, 'next_due_date' => '2026-04-02'), '2026-04-01'),
    'Birthday · Tomorrow',
    'and a birthday says which kind it is'
);

/* -------------------------------------------------- the screens R owns */

$dashSrc = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
ok(str_contains($dashSrc, 'require_login_page()'), 'the dashboard is behind the gate like every other screen');
ok(str_contains($dashSrc, 'id="logout-form"'), 'index.php carries the hidden logout form menu.js submits');
ok(str_contains($dashSrc, "asset('assets/dashboard.js')"), 'and loads its entry script cache-busted');
ok(str_contains($dashSrc, 'page_foot(\'today\')'), 'and marks the Today tab active');

/* No clock, and no date arithmetic in SQL, in either of the two files that
 * decide what is due. This is the rule the whole track is built on, so it is
 * checked mechanically rather than trusted to review.
 *
 * Scanned with the COMMENTS STRIPPED, because both files explain at length why
 * there is no DATE_ADD in them and a check that failed on its own rationale
 * would be deleted within a week. */
foreach (array('lib/reminders.php', 'public/index.php') as $rFile) {
    $src = php_strip_whitespace(dirname(__DIR__) . '/' . $rFile);
    $bad = array();
    foreach (array('DATE_ADD', 'INTERVAL ', 'CURDATE', 'NOW()', "strtotime(") as $needle) {
        if (str_contains($src, $needle)) {
            $bad[] = $needle;
        }
    }
    ok($bad === array(), $rFile . ' has no DATE_ADD, INTERVAL, CURDATE, NOW() or strtotime()', implode(', ', $bad));
}

/* crm_today() exactly once per screen, at the top. Twice is how a dashboard
 * rendered a millisecond before midnight disagrees with itself. */
is_same(
    substr_count(php_strip_whitespace(dirname(__DIR__) . '/public/index.php'), 'crm_today()'),
    1,
    'and index.php asks what day it is exactly once'
);
is_same(
    substr_count(php_strip_whitespace(dirname(__DIR__) . '/lib/reminders.php'), 'crm_today()'),
    0,
    'while lib/reminders.php never asks at all — every function there takes $today'
);

/* R's region in the two shared files is filled in, and only R's. */
$personSrcR = (string) file_get_contents(dirname(__DIR__) . '/public/person.php');
$reminderRegion = substr(
    $personSrcR,
    (int) strpos($personSrcR, '<!-- REGION: reminders'),
    (int) strpos($personSrcR, '<!-- END REGION: reminders -->') - (int) strpos($personSrcR, '<!-- REGION: reminders')
);
ok(str_contains($reminderRegion, 'id="person-reminders"'), 'person.php\'s reminders region carries R\'s card');
ok(!str_contains($reminderRegion, 'REGION: gifts'), 'and stops where it should — nothing of I\'s is inside it');

$personJsR = (string) file_get_contents(dirname(__DIR__) . '/public/assets/person.js');
$jsRegion = substr(
    $personJsR,
    (int) strpos($personJsR, '/* REGION: reminders'),
    (int) strpos($personJsR, '/* END REGION: reminders */') - (int) strpos($personJsR, '/* REGION: reminders')
);
ok(str_contains($jsRegion, 'startReachOut()'), 'person.js\'s reminders region carries R\'s attach call');

/* The endpoints exist under the contracted names. The gate on them is checked
 * by the foundation's loop over public/api/*.php, which now includes these. */
foreach (array('reminder-save.php', 'reminder-delete.php') as $rEndpoint) {
    ok(
        is_file(dirname(__DIR__) . '/public/api/' . $rEndpoint),
        'public/api/' . $rEndpoint . ' exists under its contracted name'
    );
}

/* Clean up after itself, so a track file that runs later starts from the same
 * place it would have without this one. */
foreach (array($rAlex, $rNoBd, $rLeap, $rSam, $rDue, $rTom, $rWk, $rFar, $rLate) as $rPersonId) {
    people_delete($rPersonId);
}
