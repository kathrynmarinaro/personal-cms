<?php
/**
 * Interaction track tests — lib/contact.php, the six gift-* and contact-*
 * endpoints, and the contracts the two shared files have to keep.
 *
 * Required at global scope by tools/run-tests.php, so ok(), is_same(),
 * throws(), section() and $T behave exactly as they would inline. Helpers are
 * prefixed ctest_ so they cannot collide with another track's.
 *
 * THREE THINGS THIS FILE IS REALLY FOR:
 *
 *   1. "LOGGED TODAY" IS THREE WRITES AND THEY ONLY MEAN ANYTHING TOGETHER —
 *      the contact_log row, people.last_contact_date, and what
 *      reminders_contact_logged() then does to the reach-out reminder. The
 *      reminder rules themselves are Phase 2B's and are tested in
 *      tools/tests-reminders.php; what is asserted here is that the CALL
 *      HAPPENS, because a logging path that quietly forgets it leaves a
 *      reminder overdue forever with nothing on any screen to explain why.
 *
 *   2. THE TWO DECISIONS THAT LOOK LIKE BUGS. Logging twice in one day writes
 *      two rows and moves nothing; a backdated entry never rewinds the clock.
 *      Both are in CLAUDE.md, both would look like a de-duplication bug to
 *      somebody reading the code cold, and both now cost a failing test to
 *      "fix".
 *
 *   3. THE UNDO BEHIND THE SWIPE. swipe.js deletes immediately and treats Undo
 *      as a RESTORE, so gift_restore() has to be able to bring a row back —
 *      and back in the same PLACE, which is why it keeps the id.
 *
 * Everything with a database in it runs against the SQLite translation of
 * schema.sql. MySQL is the production target; nothing below depends on SQLite
 * semantics.
 *
 * NOTHING HERE ASKS WHAT DAY IT IS. Every date is CTEST_TODAY or a literal, so
 * a test that passes today passes in February. The one exception is deliberate
 * and is stated where it happens: a contact logged ON $today leaves logged_at
 * to the column's DEFAULT CURRENT_TIMESTAMP, so no assertion below reads that
 * row's timestamp — only the values this app computed.
 */

declare(strict_types=1);

require_once $appRoot . '/lib/contact.php';

/** A fixed clock. Never crm_today() — see lib/dates.php. */
const CTEST_TODAY = '2026-08-03';

/** Empty everything this track writes to, plus the people it hangs off. */
function ctest_reset(): void
{
    /* Children first. The harness has foreign keys ON (unlike SQLite's default)
     * so the order matters here in exactly the way it would in MySQL. */
    q('DELETE FROM contact_log');
    q('DELETE FROM gift_ideas');
    q('DELETE FROM reminder_sends');
    q('DELETE FROM reminders');
    q('DELETE FROM person_tag_map');
    q('DELETE FROM people');
}

/** A person to hang gift ideas and conversations off. */
function ctest_person(string $name): int
{
    return people_add(array('name' => $name), CTEST_TODAY);
}

/** The idea_text of every gift on a person, in the order the app renders them. */
function ctest_ideas(int $personId): array
{
    return array_map(
        static fn (array $gift): string => $gift['idea_text'],
        gifts_for_person($personId)
    );
}

/** people.last_contact_date, read straight out of the table. */
function ctest_last_contact(int $personId): ?string
{
    $person = people_get($personId);
    return $person === null ? null : $person['last_contact_date'];
}

/**
 * A PHP file with its comments removed.
 *
 * Borrowed wholesale from the Import track's reasoning: the strings these
 * assertions look for — "NOW()", "swipe" — appear in the very comments that
 * explain why the code does not contain them, so a naive grep fails a file
 * precisely because it documents itself properly.
 */
function ctest_code_only(string $source): string
{
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $code .= $token[1];
            continue;
        }
        $code .= $token;
    }
    return $code;
}

/** A JS module with its comments removed. Same reason as ctest_code_only(). */
function ctest_js_code_only(string $source): string
{
    $stripped = preg_replace('#/\*.*?\*/#s', '', $source);
    $stripped = preg_replace('#(^|[^:])//[^\n]*#', '$1', (string) $stripped);
    return (string) $stripped;
}

/** The text between one region's markers in a shared file. */
function ctest_region(string $source, string $open, string $close): string
{
    $from = strpos($source, $open);
    $to   = strpos($source, $close);
    if ($from === false || $to === false || $to < $from) {
        return '';
    }
    return substr($source, $from, $to - $from);
}

ctest_reset();

/* ================================================================ cleaning ==*/

section('I · Interaction — cleaning what was typed');

is_same(gift_clean_text('  a walnut cutting board  '), 'a walnut cutting board', 'a gift idea is trimmed');
is_same(gift_clean_text(''), null, 'an empty one is null, not an empty row');
is_same(gift_clean_text('   '), null, 'and so is one that was only spaces');
is_same(gift_clean_text(null), null, 'and so is nothing at all');
is_same(
    mb_strlen((string) gift_clean_text(str_repeat('x', 900)), 'UTF-8'),
    GIFT_TEXT_MAX,
    'a long paste is capped at the column width rather than rejected by MySQL'
);
is_same(gift_clean_text('iPhone case'), 'iPhone case', 'and nothing else is touched — no case fixing, ever');

is_same(contact_clean_note('  about the move '), 'about the move', 'a note is trimmed');
is_same(contact_clean_note(''), null, 'AN EMPTY NOTE IS NULL AND IS THE ORDINARY CASE: the 1-tap button sends one');
is_same(contact_clean_note(null), null, 'and so does a caller that sends no note at all');
is_same(
    mb_strlen((string) contact_clean_note(str_repeat('y', 900)), 'UTF-8'),
    CONTACT_NOTE_MAX,
    'a long note is capped at the column width'
);

/* ============================================================= gift ideas ==*/

section('I · Interaction — gift ideas');

$cAlex = ctest_person('Alex Chen');
$cSam  = ctest_person('Sam Okafor');

is_same(gifts_for_person($cAlex), array(), 'a fresh person has no gift ideas, and that is an empty list not a null');

$gBoard = gift_add($cAlex, 'the walnut board from the shop on Grand');
ok($gBoard !== null, 'a gift idea saves');
is_same($gBoard['person_id'], $cAlex, 'against the right person');
is_same($gBoard['idea_text'], 'the walnut board from the shop on Grand', 'with the text as typed');

$gSocks = gift_add($cAlex, '  wool socks  ');
is_same($gSocks['idea_text'], 'wool socks', 'and cleaned on the way in');

is_same(
    ctest_ideas($cAlex),
    array('wool socks', 'the walnut board from the shop on Grand'),
    'THE LIST IS NEWEST FIRST — there is no sort_order column and none is wanted (PLAN.md §4.6)'
);
is_same(ctest_ideas($cSam), array(), 'and it is scoped to one person');

is_same(gift_add($cAlex, '   '), null, 'an empty idea is refused rather than stored as a blank row');
is_same(gift_add(999999, 'a bicycle'), null, 'and so is one for somebody who is not here');

/* ---- renaming, which is the tap-to-edit half of the row ------------------- */

$renamed = gift_rename($gSocks['id'], '  thick wool socks  ');
is_same($renamed['idea_text'], 'thick wool socks', 'tapping the text and typing renames it, trimmed');
is_same(gift_get($gSocks['id'])['idea_text'], 'thick wool socks', 'and it is the stored row that changed');
is_same(
    gift_rename($gSocks['id'], '   '),
    null,
    'AN EMPTIED EDITOR IS REFUSED, NOT TREATED AS A DELETE — delete has its own gesture and its own undo'
);
is_same(gift_get($gSocks['id'])['idea_text'], 'thick wool socks', 'so the old text is still there');
is_same(gift_rename(999999, 'anything'), null, 'renaming something that is gone is null rather than an error');

/* ---- deleting, and the undo that has to be a restore ---------------------- */

$deleted = gift_delete($gSocks['id']);
ok($deleted !== null, 'a swipe deletes immediately — the server is the source of truth for those five seconds');
is_same($deleted['idea_text'], 'thick wool socks', 'and hands back what it was, because Undo is a RESTORE and needs the fields');
is_same(gift_get($gSocks['id']), null, 'the row really is gone');
is_same(gift_delete($gSocks['id']), null, 'deleting it twice is null rather than an error');

$restored = gift_restore($deleted['id'], $cAlex, $deleted['idea_text']);
ok($restored !== null, 'Undo puts it back');
is_same(
    $restored['id'],
    $deleted['id'],
    'WITH ITS ORIGINAL id, so a list that sorts newest-first by id puts it back where it was rather than at the top'
);
is_same(
    ctest_ideas($cAlex),
    array('thick wool socks', 'the walnut board from the shop on Grand'),
    'which is what makes the list after a reload agree with the list on screen'
);

/* Restoring onto an id that is now taken. Reachable from a double-tapped undo
 * or a database restored from a dump, and it must still come back — swipe.js
 * adopts whatever id the response carries. */
$clash = gift_restore($gBoard['id'], $cAlex, 'a second copy');
ok($clash !== null, 'a restore onto an id that is taken still comes back');
ok($clash['id'] !== $gBoard['id'], 'under a new id, which swipe.js adopts into the row');
is_same(gift_get($gBoard['id'])['idea_text'], 'the walnut board from the shop on Grand', 'and the row that was already there is untouched');
gift_delete($clash['id']);

is_same(gift_restore(0, 999999, 'a bicycle'), null, 'restoring onto somebody who is not here is null');
is_same(gift_restore(0, $cAlex, '  '), null, 'and so is restoring nothing');

/* ============================================================ contact log ==*/

section('I · Interaction — the contact log');

$cLog = ctest_person('Priya Raman');

is_same(contact_log_for_person($cLog), array(), 'a fresh person has no history');
is_same(contact_log_count($cLog), 0, 'and the accordion counts zero');
is_same(ctest_last_contact($cLog), null, 'and last_contact_date is NULL, which is "never" and is a real answer');

$logged = contact_log_add($cLog, 'about the move', CTEST_TODAY, CTEST_TODAY);
ok($logged !== null, 'logging a contact writes a row');
is_same($logged['note'], 'about the move', 'with the note on it');
is_same(ctest_last_contact($cLog), CTEST_TODAY, 'and moves the cadence clock to the date that was logged');

$again = contact_log_add($cLog, null, CTEST_TODAY, CTEST_TODAY);
ok($again !== null, 'logging again the same day writes a SECOND row');
is_same($again['note'], null, 'a one-tap log has no note at all, and that is not a failure');
is_same(contact_log_count($cLog), 2, 'TWO CONVERSATIONS IN ONE DAY ARE TWO CONVERSATIONS — nothing de-duplicates them');
is_same(
    ctest_last_contact($cLog),
    CTEST_TODAY,
    'and last_contact_date does not move, because it is a DATE and the cadence clock runs off the date (CLAUDE.md)'
);

is_same(contact_log_add(999999, null, CTEST_TODAY, CTEST_TODAY), null, 'logging against somebody who is not here is null');
is_same(contact_log_add($cLog, null, 'the 3rd', CTEST_TODAY), null, 'and so is a date that cannot be read');
is_same(contact_log_add($cLog, null, '2026-02-30', CTEST_TODAY), null, 'including one that looks like a date and is not a day');
is_same(contact_log_count($cLog), 2, 'and neither of those left a row behind');

/* ---- backdating ---------------------------------------------------------- */

$cBack = ctest_person('Dana Whitfield');

$older = contact_log_add($cBack, 'coffee', '2026-06-01', CTEST_TODAY);
is_same(ctest_last_contact($cBack), '2026-06-01', 'a backdated entry sets the clock when there was nothing there');
is_same(
    substr($older['logged_at'], 0, 10),
    '2026-06-01',
    'and is stamped with the date it was for, not with the day it was typed'
);
is_same(
    substr($older['logged_at'], 10),
    CONTACT_BACKDATE_TIME,
    'at midday, which is the time furthest from both edges of the day it has to stay inside'
);

$newer = contact_log_add($cBack, 'the wedding', '2026-07-15', CTEST_TODAY);
is_same(ctest_last_contact($cBack), '2026-07-15', 'a later entry moves the clock forward');

contact_log_add($cBack, 'a call I forgot to log', '2026-05-04', CTEST_TODAY);
is_same(
    ctest_last_contact($cBack),
    '2026-07-15',
    'AND AN OLDER ONE DOES NOT MOVE IT BACK — remembering last month must not rewind a call you made yesterday'
);
is_same(contact_log_count($cBack), 3, 'though it is still on the record');

is_same(
    array_map(static fn (array $e): string => substr($e['logged_at'], 0, 10), contact_log_for_person($cBack)),
    array('2026-07-15', '2026-06-01', '2026-05-04'),
    'the history reads newest first, which is the only order a profile ever wants it in'
);

/* ---- the two lines each history row shows -------------------------------- */

is_same(
    contact_log_lines($newer, CTEST_TODAY),
    array('text' => 'the wedding', 'sub' => 'July 15, 2026 · 19 days ago'),
    'a row with a note leads with the note and dates it underneath'
);
is_same(
    contact_log_lines(array('logged_at' => '2026-08-03 09:12:00', 'note' => null), CTEST_TODAY),
    array('text' => 'August 3, 2026', 'sub' => 'Today'),
    'a row with no note leads with the date, so .row-text never falls back to "(untitled)"'
);
is_same(
    contact_log_lines(array('logged_at' => '2026-08-02 09:12:00', 'note' => null), CTEST_TODAY),
    array('text' => 'August 2, 2026', 'sub' => 'Yesterday'),
    'and says how long ago in the words fmt_relative_due() already uses everywhere else'
);
is_same(
    contact_log_lines(array('logged_at' => 'not a date', 'note' => null), CTEST_TODAY),
    array('text' => 'not a date', 'sub' => ''),
    'a logged_at that will not parse degrades to one unreadable ROW, never to a screen'
);

/* ---- removing an entry --------------------------------------------------- */

$removed = contact_log_delete($newer['id']);
ok($removed !== null, 'an entry logged by mistake can be removed');
is_same($removed['note'], 'the wedding', 'and hands back what it was, for the message');
is_same(contact_log_count($cBack), 2, 'it is gone from the history');
is_same(
    ctest_last_contact($cBack),
    '2026-06-01',
    'AND last_contact_date GOES BACK to whatever the remaining history says — nothing else in the app writes that column'
);

is_same(contact_log_delete($removed['id']), null, 'removing it twice is null rather than an error');

foreach (contact_log_for_person($cBack) as $entry) {
    contact_log_delete($entry['id']);
}
is_same(
    ctest_last_contact($cBack),
    null,
    'and removing the last entry puts it back to NULL — "never contacted" is a real state, not a reason to reach for created_at'
);

/* ================================== logging is what moves a reach-out ======*/

section('I · Interaction — logging a contact moves the reach-out reminder');

/* The RULES here are Phase 2B's and are tested in tools/tests-reminders.php.
 * What these assertions are for is the WIRING: that lib/contact.php actually
 * calls reminders_contact_logged(), on the logged date, after the writes. A
 * logging path that forgot it would leave a reminder overdue forever with
 * nothing on any screen to explain why — which is the one failure this app
 * cannot afford, because the person relying on it cannot see it. */

$cCadence = ctest_person('Marisol Vega');
reminders_set_reach_out($cCadence, 60, null, CTEST_TODAY);
is_same(
    reminders_get($cCadence, REMINDER_REACH_OUT)['next_due_date'],
    '2026-10-02',
    'a fresh cadence on somebody never contacted counts from today'
);

contact_log_add($cCadence, null, '2026-07-01', CTEST_TODAY);
is_same(
    reminders_get($cCadence, REMINDER_REACH_OUT)['next_due_date'],
    '2026-08-30',
    'LOGGING A CONTACT RESETS IT to the logged date plus the interval — the only thing in the app that moves a reach-out'
);

$cDue = ctest_person('Theo Marsh');
reminders_set_reach_out($cDue, null, '2026-08-01', CTEST_TODAY);
contact_log_add($cDue, null, CTEST_TODAY, CTEST_TODAY);
is_same(
    reminders_get($cDue, REMINDER_REACH_OUT),
    null,
    'a one-off that had already come due is satisfied by talking to them, and goes'
);

$cFuture = ctest_person('Nadia Bright');
reminders_set_reach_out($cFuture, null, '2026-09-01', CTEST_TODAY);
contact_log_add($cFuture, null, CTEST_TODAY, CTEST_TODAY);
is_same(
    reminders_get($cFuture, REMINDER_REACH_OUT)['next_due_date'],
    '2026-09-01',
    'A ONE-OFF STILL IN THE FUTURE SURVIVES — an unrelated chat today must not silently eat a dated reminder'
);

$cBirthday = ctest_person('Jonah Reid');
people_save($cBirthday, array('name' => 'Jonah Reid', 'birth_month' => 9, 'birth_day' => 20), CTEST_TODAY);
$beforeLog = reminders_get($cBirthday, REMINDER_BIRTHDAY)['next_due_date'];
contact_log_add($cBirthday, null, CTEST_TODAY, CTEST_TODAY);
is_same(
    reminders_get($cBirthday, REMINDER_BIRTHDAY)['next_due_date'],
    $beforeLog,
    'and the birthday reminder is not touched at all: calling somebody is not their birthday happening'
);

$cNone = ctest_person('Quiet Neighbour');
ok(contact_log_add($cNone, null, CTEST_TODAY, CTEST_TODAY) !== null, 'logging against somebody with no reminder at all works');
is_same(reminders_for_person($cNone), array(), 'and does not invent one');

/* ====================================================== the module's rules ==*/

section('I · Interaction — what lib/contact.php is not allowed to do');

$contactSrc = (string) file_get_contents($appRoot . '/lib/contact.php');
$contactCode = ctest_code_only($contactSrc);

foreach (array('DATE_ADD', 'INTERVAL', 'CURDATE', 'NOW()', 'strtotime') as $forbidden) {
    ok(
        !str_contains($contactCode, $forbidden),
        'lib/contact.php has no ' . $forbidden . ' — the arithmetic is lib/dates.php\'s, in PHP'
    );
}
is_same(
    substr_count($contactCode, 'crm_today()'),
    0,
    'and it never asks what day it is: every function takes $today or a date'
);

is_same(
    substr_count($contactCode, 'reminders_contact_logged('),
    1,
    'it calls Phase 2B\'s reminder rule exactly once, from the one place a contact is logged'
);
foreach (array('next_cadence_date', 'REMINDER_REACH_OUT', 'UPDATE reminders', 'DELETE FROM reminders') as $reimplemented) {
    ok(
        !str_contains($contactCode, $reimplemented),
        'and does not re-implement it — no ' . $reimplemented . ' anywhere in this file'
    );
}

ok(
    !preg_match('/\$\w+\s*\.\s*[\'"]\s*(SELECT|INSERT|UPDATE|DELETE)/i', $contactCode),
    'nothing is interpolated into SQL — every statement is a literal with bound parameters'
);

/* ================================================ the endpoints and screens ==*/

section('I · Interaction — the endpoints and the screen contracts');

/* The three gates on every endpoint are checked by the foundation's loop over
 * public/api/*.php, which now includes these six. What is checked here is that
 * they exist under the names docs/CONTRACTS.md §6 fixed, and that they are
 * endpoints rather than a second copy of the repo. */
$ctestEndpoints = array(
    'gift-add.php', 'gift-rename.php', 'gift-delete.php', 'gift-restore.php',
    'contact-log.php', 'contact-delete.php',
);
foreach ($ctestEndpoints as $endpoint) {
    $path = $appRoot . '/public/api/' . $endpoint;
    ok(is_file($path), 'public/api/' . $endpoint . ' exists under its contracted name');
    if (!is_file($path)) {
        continue;
    }
    $code = ctest_code_only((string) file_get_contents($path));
    ok(
        !preg_match('/\b(SELECT|INSERT INTO|UPDATE \w+ SET|DELETE FROM)\b/i', $code),
        $endpoint . ' writes no SQL of its own — lib/contact.php owns both tables'
    );
}

/* The dashboard was written against this endpoint before it existed
 * (docs/CONTRACTS.md §5). The path and the field name are the contract. */
$dashJs = ctest_js_code_only((string) file_get_contents($appRoot . '/public/assets/dashboard.js'));
ok(str_contains($dashJs, "'api/contact-log.php'"), 'assets/dashboard.js posts to api/contact-log.php');
ok(str_contains($dashJs, 'person_id'), 'with person_id, which is the field name contact-log.php reads');

$logSrc = (string) file_get_contents($appRoot . '/public/api/contact-log.php');
ok(str_contains($logSrc, "\$in['person_id']"), 'and contact-log.php reads exactly that field');
ok(str_contains($logSrc, "'id'"), 'and answers with the new contact_log id, so a caller can offer an Undo');

/* I's two regions in the two shared files are filled in, and only I's. */
$personSrcI = (string) file_get_contents($appRoot . '/public/person.php');
$giftRegion = ctest_region($personSrcI, '<!-- REGION: gifts', '<!-- END REGION: gifts -->');
$logRegion  = ctest_region($personSrcI, '<!-- REGION: log', '<!-- END REGION: log -->');

ok(str_contains($giftRegion, 'id="person-gift-list"'), 'person.php\'s gifts region carries the list');
ok(str_contains($giftRegion, 'class="composer'), 'and the quick-add composer');
ok(str_contains($giftRegion, 'class="row-del"'), 'and the pointer-only delete swipe.js wires up');
ok(
    !str_contains($giftRegion, 'drag-handle'),
    'AND NO DRAG HANDLE: gift ideas are not reorderable, which is a decision and not an omission (PLAN.md §4.6)'
);
ok(!str_contains($giftRegion, 'REGION: log'), 'and it stops where it should');

ok(str_contains($logRegion, 'class="accordion"'), 'person.php\'s log region carries the history accordion');
ok(str_contains($logRegion, 'accordion-count'), 'with the count on the heading');
ok(
    !str_contains($logRegion, 'class="row-del"'),
    'and NO swipe affordance on a log entry — that stays on gift ideas and import drafts (CLAUDE.md)'
);
ok(str_contains($logRegion, 'id="person-log-composer"'), 'and the "Logged today" composer');
ok(!str_contains($logRegion, 'REGION: danger'), 'and it stops where it should');

$personJsI = (string) file_get_contents($appRoot . '/public/assets/person.js');
$giftJs = ctest_region($personJsI, '/* REGION: gifts', '/* END REGION: gifts */');
$logJs  = ctest_region($personJsI, '/* REGION: log', '/* END REGION: log */');

ok(str_contains($giftJs, 'attachSwipeDelete('), 'person.js\'s gifts region attaches the swipe');
ok(str_contains($giftJs, 'attachInlineEdit('), 'and the inline editor');
ok(str_contains($giftJs, 'api/gift-restore.php'), 'and restores on Undo, because the delete already happened');
ok(
    !str_contains(ctest_js_code_only($giftJs), 'attachReorder'),
    'and does not attach reorder.js, which ships wired to nothing on purpose'
);
ok(str_contains($logJs, 'api/contact-log.php'), 'person.js\'s log region posts to the contract endpoint');
ok(str_contains($logJs, 'api/contact-delete.php'), 'and removes an entry through its own');
ok(
    !str_contains(ctest_js_code_only($logJs), 'attachSwipeDelete'),
    'and never swipes a log entry away'
);

/* Deleting a person takes both tables with them. The cascade itself is
 * schema.sql's and is asserted in the People section; what is asserted here is
 * that this track wrote nothing that outlives it. */
$cDoomed = ctest_person('Someone Leaving');
gift_add($cDoomed, 'a farewell card');
contact_log_add($cDoomed, 'goodbye', CTEST_TODAY, CTEST_TODAY);
people_delete($cDoomed);
is_same(gifts_for_person($cDoomed), array(), 'deleting a person takes their gift ideas');
is_same(contact_log_count($cDoomed), 0, 'and their whole contact history, by cascade and not by code here');

/* Clean up after itself, so a track file that runs later starts from the same
 * place it would have without this one. */
ctest_reset();
