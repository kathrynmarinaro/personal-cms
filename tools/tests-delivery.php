<?php
/**
 * Delivery track tests — the advance rule, lib/mailer.php's message building,
 * and the cron's orchestration.
 *
 * Required at global scope by tools/run-tests.php, so ok(), is_same(),
 * throws(), section() and $T behave exactly as they would inline. Helpers are
 * prefixed dtest_ so they cannot collide with another track's.
 *
 * ---------------------------------------------------------------------------
 * NOTHING HERE OPENS A SOCKET. EVER.
 * ---------------------------------------------------------------------------
 *
 * A test suite that sends real email is a test suite nobody runs twice, and on
 * a machine with a working config.php it would mail the owner every time
 * anybody typed `php tools/run-tests.php`. The cron section below blanks
 * smtp.pass in the loaded config for its own duration, so mailer_send() refuses
 * before it constructs a connection — which also happens to be the exact path
 * a misconfigured deploy takes, so the failure branch gets tested for free.
 * Everything else here builds messages and never tries to deliver one.
 *
 * ---------------------------------------------------------------------------
 * WHAT THESE ARE REALLY FOR.
 * ---------------------------------------------------------------------------
 *
 *   1. THE ADVANCE GUARD. reminders_advance_after_send() used to roll a
 *      birthday forward the moment the cron emailed — which is seven days
 *      BEFORE the birthday, so the birthday disappeared from the Today
 *      dashboard for the whole week you were supposed to be acting on it. One
 *      email, then silence for a year. The only way to notice is to miss
 *      somebody's birthday, so it gets four tests: the lead day, the birthday
 *      itself, the day after, and a reach-out.
 *
 *   2. THE SUBJECT LINE, which is the entire user experience of this feature.
 *      It arrives on a lock screen and is very often the only part read.
 *
 *   3. THE CRON'S FAIL-SOFT. One broken person must cost one email and nothing
 *      else, and the run must still exit non-zero so a cron nobody watches has
 *      a signal.
 *
 * NOTHING HERE ASKS WHAT DAY IT IS. Every date is DTEST_TODAY or a literal, so
 * a test that passes today passes in February.
 */

declare(strict_types=1);

require_once $appRoot . '/lib/people.php';
require_once $appRoot . '/lib/contact.php';
require_once $appRoot . '/lib/reminders.php';
require_once $appRoot . '/lib/mailer.php';
require_once $appRoot . '/tools/cron-reminders.php';

/** A fixed clock. Never crm_today() — see lib/dates.php. */
const DTEST_TODAY = '2026-04-08';

/** PHP source with its comments removed, so a rule can't pass by being named. */
function dtest_code_only(string $source): string
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

/** Empty everything this track touches. Children first — foreign keys are on. */
function dtest_reset(): void
{
    q('DELETE FROM reminder_sends');
    q('DELETE FROM reminders');
    q('DELETE FROM contact_log');
    q('DELETE FROM gift_ideas');
    q('DELETE FROM person_tag_map');
    q('DELETE FROM people');
}

/* ========================================== 1. the advance rule, per PLAN 7.2 */

section('D · Delivery — a birthday reminder does not advance until the birthday has passed');

dtest_reset();

/* April 15th, seven-day lead: the reminder falls due 2026-04-08 and the email
 * goes out that morning. */
$dAlex = people_add(
    array('name' => 'Delivery Alex', 'birth_month' => 4, 'birth_day' => 15, 'notes' => "Allergic to lilies.\nLikes hot sauce."),
    DTEST_TODAY
);
$dBirthday = reminders_get($dAlex, REMINDER_BIRTHDAY);

is_same(
    $dBirthday === null ? '' : $dBirthday['next_due_date'],
    '2026-04-08',
    'the birthday reminder falls due on the lead date, seven days before April 15'
);

/* THE BUG THIS SECTION EXISTS FOR. On the day the email goes out, the birthday
 * is still a week away and is the single most useful row on the dashboard. */
is_same(
    reminders_advance_after_send($dBirthday['id'], '2026-04-08'),
    false,
    'ON THE LEAD DAY — the day the email goes out — it does NOT advance'
);
is_same(
    reminders_get($dAlex, REMINDER_BIRTHDAY)['next_due_date'],
    '2026-04-08',
    'and the row is untouched, so the birthday stays on the dashboard all week'
);

foreach (array('2026-04-09', '2026-04-12', '2026-04-14') as $dMidWeek) {
    is_same(
        reminders_advance_after_send($dBirthday['id'], $dMidWeek),
        false,
        'nor on ' . $dMidWeek . ', mid-lead-week, when there is still time to post a card'
    );
}

is_same(
    reminders_advance_after_send($dBirthday['id'], '2026-04-15'),
    false,
    'nor ON THE BIRTHDAY ITSELF, which is the last day it is any use to you'
);
is_same(
    reminders_get($dAlex, REMINDER_BIRTHDAY)['next_due_date'],
    '2026-04-08',
    'so it is `<= today` for eight days — the lead date through the birthday — and shows on all eight'
);

/* And now it has genuinely passed. */
is_same(
    reminders_advance_after_send($dBirthday['id'], '2026-04-16'),
    true,
    'THE DAY AFTER the birthday it advances — the thing it was about is finished'
);
is_same(
    reminders_get($dAlex, REMINDER_BIRTHDAY)['next_due_date'],
    '2027-04-08',
    'to NEXT year\'s lead date, one year on, not two'
);

/* A reach-out, which never moves here whatever the day. */
$dSam = people_add(array('name' => 'Delivery Sam'), DTEST_TODAY);
q('UPDATE people SET last_contact_date = ? WHERE id = ?', array('2026-02-05', $dSam));
$dReach = reminders_set_reach_out($dSam, 60, null, DTEST_TODAY);   // due 2026-04-06

is_same($dReach === null ? '' : $dReach['next_due_date'], '2026-04-06', 'a cadence reach-out sits where the last contact put it');
foreach (array('2026-04-06', DTEST_TODAY, '2026-05-30') as $dAnyDay) {
    is_same(
        reminders_advance_after_send($dReach['id'], $dAnyDay),
        false,
        'a REACH-OUT never advances on send, asked on ' . $dAnyDay
    );
}
is_same(
    reminders_get($dSam, REMINDER_REACH_OUT)['next_due_date'],
    '2026-04-06',
    'IT STAYS OVERDUE. Only logging an actual contact moves it'
);

/* The leap-day case, one year on. */
$dLeap = people_add(array('name' => 'Delivery Leap', 'birth_month' => 2, 'birth_day' => 29), '2026-02-01');
$dLeapReminder = reminders_get($dLeap, REMINDER_BIRTHDAY);
is_same(
    $dLeapReminder === null ? '' : $dLeapReminder['next_due_date'],
    '2026-02-21',
    'a Feb 29 birthday clamps to Feb 28 in a non-leap year, so its reminder falls on Feb 21'
);
is_same(
    reminders_advance_after_send($dLeapReminder['id'], '2026-02-28'),
    false,
    'and it does not advance on the clamped birthday itself'
);
is_same(
    reminders_advance_after_send($dLeapReminder['id'], '2026-03-01'),
    true,
    'but does the day after'
);
is_same(
    reminders_get($dLeap, REMINDER_BIRTHDAY)['next_due_date'],
    '2027-02-21',
    'landing in the FOLLOWING year rather than on itself'
);

/* The whole reason the guard is safe: the ledger, not the due date, is what
 * stops the second email. */
q('DELETE FROM reminder_sends');
q('UPDATE reminders SET next_due_date = ? WHERE id = ?', array('2026-04-08', $dBirthday['id']));

ok(reminders_claim_send($dBirthday['id'], '2026-04-08'), 'the lead day claims the send');
reminders_mark_sent($dBirthday['id'], '2026-04-08', '2026-04-08 06:00:00');
$dSecondEmails = 0;
foreach (array('2026-04-09', '2026-04-10', '2026-04-13', '2026-04-15') as $dLaterDay) {
    /* The due date has not moved, so every later run claims the same pair. */
    if (reminders_claim_send($dBirthday['id'], '2026-04-08')) {
        $dSecondEmails++;
    }
}
is_same(
    $dSecondEmails,
    0,
    'and every run for the rest of the week is refused — one email across eight visible days'
);

/* ========================================================= 2. the subject line */

section('D · Delivery — the subject line, which is the whole user experience');

$dPerson = people_get($dAlex);

is_same(
    mailer_subject($dPerson, REMINDER_BIRTHDAY, '2026-04-08', DTEST_TODAY),
    'Birthday: Delivery Alex — April 15 (next Wednesday)',
    'a birthday subject carries the name, the date, and when — in that order, so a truncated one still says who'
);
is_same(
    mailer_subject($dPerson, REMINDER_BIRTHDAY, '2026-04-08', '2026-04-14'),
    'Birthday: Delivery Alex — April 15 (tomorrow)',
    'and the "when" is relative to the day the mail goes, not to the due date'
);
is_same(
    mailer_subject($dPerson, REMINDER_BIRTHDAY, '2026-04-08', '2026-04-15'),
    'Birthday: Delivery Alex — April 15 (today)',
    'on the day itself it says today, not "in 0 days"'
);

/* The birthday named is the one the reminder was written about, found from the
 * DUE date. Asked from today it would say next year's on any day after it. */
is_same(
    mailer_subject($dPerson, REMINDER_BIRTHDAY, '2026-04-08', '2026-04-16'),
    'Birthday: Delivery Alex — April 15 (yesterday)',
    'a late run still names THIS year\'s birthday, because the date comes from the due date'
);

$dSamRow = people_get($dSam);
is_same(
    mailer_subject($dSamRow, REMINDER_REACH_OUT, '2026-04-06', DTEST_TODAY),
    'Time to reach out to Delivery Sam — last contacted 62 days ago',
    'a reach-out subject leads with the ask and closes with how long it has been'
);

$dNever = people_add(array('name' => 'Delivery Never'), DTEST_TODAY);
is_same(
    mailer_subject(people_get($dNever), REMINDER_REACH_OUT, DTEST_TODAY, DTEST_TODAY),
    'Time to reach out to Delivery Never — never contacted',
    'and says "never contacted" rather than "0 days ago", which would be the opposite of the truth'
);

is_same(mailer_when_phrase('2026-04-08', DTEST_TODAY), 'today', 'when: today');
is_same(mailer_when_phrase('2026-04-09', DTEST_TODAY), 'tomorrow', 'when: tomorrow');
is_same(mailer_when_phrase('2026-04-15', DTEST_TODAY), 'next Wednesday', 'when: a weekday name inside the week');
is_same(mailer_when_phrase('2026-04-30', DTEST_TODAY), 'in 22 days', 'when: a count beyond it, because "Wednesday" stops meaning one date');
is_same(mailer_when_phrase('2026-04-01', DTEST_TODAY), '7 days ago', 'when: overdue stays in days, however far back');

/* ============================================================= 3. the body */

section('D · Delivery — the body: notes, gift ideas, a link, and nothing else');

gift_add($dAlex, 'Hot sauce sampler');
gift_add($dAlex, 'That cookbook');

$dBody = mailer_body_text($dPerson, REMINDER_BIRTHDAY, '2026-04-08', DTEST_TODAY);

ok(str_contains($dBody, 'Delivery Alex'), 'the body names the person');
ok(str_contains($dBody, 'Allergic to lilies.'), 'and carries their notes, which is the context you act on');
ok(str_contains($dBody, 'Hot sauce sampler'), 'and their gift ideas — a birthday is exactly when you want them');
ok(str_contains($dBody, 'That cookbook'), 'all of them, up to the cap');
ok(str_contains($dBody, 'person.php?id=' . $dAlex), 'and a link to the profile, so one tap gets you the rest');

$dReachBody = mailer_body_text($dPerson, REMINDER_REACH_OUT, DTEST_TODAY, DTEST_TODAY);
ok(
    !str_contains($dReachBody, 'Hot sauce sampler'),
    'a REACH-OUT body has no gift ideas — the reminder is to talk to somebody, not to buy them something'
);

/* Escaping, on the one output in this app that is not a template. */
$dNasty = people_add(array('name' => 'Delivery <script>', 'notes' => 'Watch & wait'), DTEST_TODAY);
$dNastyHtml = mailer_body_html(people_get($dNasty), REMINDER_REACH_OUT, DTEST_TODAY, DTEST_TODAY);
ok(!str_contains($dNastyHtml, '<script>'), 'the HTML part escapes a name with markup in it');
ok(str_contains($dNastyHtml, 'Watch &amp; wait'), 'and the notes, through h() like every other output in the app');

$dNastyText = mailer_body_text(people_get($dNasty), REMINDER_REACH_OUT, DTEST_TODAY, DTEST_TODAY);
ok(str_contains($dNastyText, 'Watch & wait'), 'while the plain-text part does NOT escape — it is not HTML and &amp; would be a typo');

/* The gift cap. Six ideas, five shown, one counted. */
$dMany = people_add(array('name' => 'Delivery Many', 'birth_month' => 4, 'birth_day' => 15), DTEST_TODAY);
foreach (array('one', 'two', 'three', 'four', 'five', 'six') as $dIdea) {
    gift_add($dMany, 'Gift ' . $dIdea);
}
$dManyBody = mailer_body_text(people_get($dMany), REMINDER_BIRTHDAY, '2026-04-08', DTEST_TODAY);
is_same(substr_count($dManyBody, '  - Gift '), MAILER_GIFT_LIMIT, 'a long gift list is capped at MAILER_GIFT_LIMIT');
ok(str_contains($dManyBody, 'and 1 more'), 'and the remainder is counted rather than dropped');

/* ======================================================= 4. the config check */

section('D · Delivery — the misconfiguration that looks like success');

$dSavedSmtp = $GLOBALS['config']['smtp'];

$GLOBALS['config']['smtp'] = array(
    'host' => 'smtp.gmail.com', 'port' => 587, 'secure' => 'tls',
    'user' => 'her@gmail.com', 'pass' => 'an-app-password',
    'from_email' => 'her@gmail.com', 'from_name' => 'Personal CRM', 'to' => 'her@gmail.com',
);
is_same(mailer_config_problem(), null, 'a complete, consistent smtp block has nothing wrong with it');

$GLOBALS['config']['smtp']['from_email'] = 'someone.else@example.com';
$dMismatch = mailer_config_problem();
ok(
    $dMismatch !== null && str_contains($dMismatch, 'does not match'),
    'FROM_EMAIL THAT DOES NOT MATCH USER is caught before a connection is opened — Gmail\'s rejection is silent'
);

$GLOBALS['config']['smtp']['from_email'] = 'her@gmail.com';
$GLOBALS['config']['smtp']['pass'] = 'CHANGE_ME';
$dPlaceholder = mailer_config_problem();
ok(
    $dPlaceholder !== null && str_contains($dPlaceholder, 'APP PASSWORD'),
    'and the shipped CHANGE_ME placeholder reports itself, naming the app password'
);

$GLOBALS['config']['smtp']['pass'] = 'an-app-password';
$GLOBALS['config']['smtp']['to'] = '';
ok(mailer_config_problem() !== null, 'an empty smtp.to is refused — there is nobody to send reminders to');

$GLOBALS['config']['smtp'] = $dSavedSmtp;

/* ============================================================== 5. the cron */

section('D · Delivery — the cron: order, fail-soft, and the exit code');

/* SMTP DISABLED FOR THIS WHOLE SECTION. mailer_config_problem() answers before
 * mailer_send() builds a connection, so nothing below can reach the network
 * even on a machine whose config.php holds a real app password. */
$dRealSmtp = $GLOBALS['config']['smtp'];
$GLOBALS['config']['smtp']['pass'] = '';

dtest_reset();

$dOne = people_add(array('name' => 'Cron One', 'birth_month' => 4, 'birth_day' => 15), DTEST_TODAY);
$dTwo = people_add(array('name' => 'Cron Two'), DTEST_TODAY);
reminders_set_reach_out($dTwo, null, '2026-03-01', DTEST_TODAY);       // overdue
$dThree = people_add(array('name' => 'Cron Three'), DTEST_TODAY);
reminders_set_reach_out($dThree, null, '2026-06-01', DTEST_TODAY);     // not due yet

$dRun = cron_reminders_run(DTEST_TODAY);

is_same($dRun['today'], DTEST_TODAY, 'the run stamps the day it was given, never one it looked up');
is_same($dRun['reconciled'], 1, 'step 1 reconciles every birthday BEFORE anything is selected as due');
is_same($dRun['due'], 2, 'step 2 is `<= today`, so the overdue reach-out comes too and the June one does not');
is_same($dRun['sent'], 0, 'with SMTP unconfigured nothing is sent');
is_same($dRun['failed'], 2, 'and both are counted as failures, which is what makes the exit code non-zero');
is_same(count($dRun['errors']), 2, 'each failure is reported with the person it belongs to');
ok(str_contains(implode(' ', $dRun['errors']), 'Cron One'), 'by name, so a cron email says who did not get theirs');

$dLedger = q('SELECT reminder_id, due_date, attempts, sent_at, last_error FROM reminder_sends ORDER BY reminder_id')->fetchAll();
is_same(count($dLedger), 2, 'a ledger row is claimed for each — the claim happens before the send, not after');
is_same($dLedger[0]['sent_at'], null, 'A FAILED SEND LEAVES sent_at NULL, which is what makes tomorrow retry');
ok((string) $dLedger[0]['last_error'] !== '', 'with the reason beside it as a diagnostic');

/* Tomorrow. Same reminders, same due dates, so the same pairs are claimed. */
$dRerun = cron_reminders_run(DTEST_TODAY);
is_same($dRerun['failed'], 2, 'the next run tries again — a hung SMTP connection must not cost you the birthday');
is_same(
    (int) q('SELECT attempts AS n FROM reminder_sends WHERE reminder_id = ?', array($dLedger[0]['reminder_id']))->fetch()['n'],
    2,
    'and the attempt count climbs rather than a second ledger row appearing'
);
is_same(
    (int) q('SELECT COUNT(*) AS n FROM reminder_sends')->fetch()['n'],
    2,
    'still exactly two rows: (reminder_id, due_date) IS the primary key'
);

/* Now pretend the first one went. */
reminders_mark_sent((int) $dLedger[0]['reminder_id'], (string) $dLedger[0]['due_date'], '2026-04-08 06:00:00');
$dAfterSent = cron_reminders_run(DTEST_TODAY);
is_same($dAfterSent['skipped'], 1, 'a delivered reminder is skipped on every later run — no second email');
is_same($dAfterSent['failed'], 1, 'while the one that never went is still being retried');

/* Nothing due at all. */
dtest_reset();
$dQuiet = cron_reminders_run(DTEST_TODAY);
is_same($dQuiet['due'], 0, 'a day with nothing due does no work');
is_same($dQuiet['failed'], 0, 'and exits zero, which is the only way a cron ever says "fine"');
ok(str_contains(cron_reminders_summary($dQuiet), 'cron-reminders ' . DTEST_TODAY), 'the summary is one line and names the day it ran');

/* Fail soft: a person deleted between the join and the send costs one row. */
$GLOBALS['config']['smtp'] = $dRealSmtp;

/* ============================================ 6. what these files may not do */

section('D · Delivery — the rules the delivery files have to keep');

$dMailerCode = dtest_code_only((string) file_get_contents($appRoot . '/lib/mailer.php'));
$dCronCode   = dtest_code_only((string) file_get_contents($appRoot . '/tools/cron-reminders.php'));
$dWebCode    = dtest_code_only((string) file_get_contents($appRoot . '/public/cron.php'));

foreach (array('DATE_ADD', 'INTERVAL ', 'CURDATE', 'NOW()', 'strtotime(') as $dForbidden) {
    ok(
        !str_contains($dMailerCode, $dForbidden) && !str_contains($dCronCode, $dForbidden),
        'no ' . $dForbidden . ' in the mailer or the cron — the arithmetic is lib/dates.php\'s'
    );
}

is_same(
    substr_count($dMailerCode, 'crm_today()'),
    1,
    'lib/mailer.php asks what day it is exactly once, as the fallback for a caller that did not pass $today'
);
is_same(
    substr_count($dCronCode, 'crm_today()'),
    1,
    'and the cron exactly once, at its entry point, passed down from there'
);
is_same(
    substr_count($dWebCode, 'crm_today()'),
    1,
    'as does public/cron.php — one clock per run, whichever way the run started'
);

/* The two cron paths must be one code path. */
ok(str_contains($dWebCode, 'cron_reminders_run('), 'public/cron.php calls the same function the command cron does');
foreach (array('reminders_due(', 'reminders_claim_send(', 'send_reminder_email(') as $dDuplicated) {
    ok(
        !str_contains($dWebCode, $dDuplicated),
        'and does not re-implement the run — no ' . $dDuplicated . ' of its own'
    );
}

/* The token gate. */
ok(str_contains($dWebCode, 'hash_equals('), 'the cron token is compared with hash_equals(), not ===, so it cannot be timed out of the server');
ok(str_contains($dWebCode, 'http_response_code(404)'), 'a bad token gets a 404');
ok(!str_contains($dWebCode, '403'), 'and never a 403 — a 403 confirms the endpoint is there and worth guessing at');
ok(
    str_contains($dWebCode, "cfg('cron.token', '')") && str_contains($dWebCode, "\$configured === ''"),
    'an unset token refuses every request rather than running the job unauthenticated'
);

/* The command-line scripts stay off the web. */
$dTestMailCode = dtest_code_only((string) file_get_contents($appRoot . '/tools/send-test-email.php'));
ok(str_contains($dTestMailCode, "PHP_SAPI !== 'cli'"), 'tools/send-test-email.php refuses to run anywhere but the command line');
ok(
    str_contains($dCronCode, "PHP_SAPI === 'cli'") && str_contains($dCronCode, 'SCRIPT_FILENAME'),
    'and tools/cron-reminders.php only RUNS when it is the entry point, so requiring it from public/cron.php just declares it'
);

/* The vendored library. Third-party, byte-identical, and exempt from the house
 * style checks in tools/run-tests.php — so assert that nothing of OURS has
 * quietly moved in under that exemption. */
$dVendor = $appRoot . '/lib/vendor/PHPMailer';
foreach (array('PHPMailer.php', 'SMTP.php', 'Exception.php') as $dVendored) {
    ok(is_file($dVendor . '/' . $dVendored), 'lib/vendor/PHPMailer/' . $dVendored . ' is vendored, required directly, with no Composer');
}
$dVendorFiles = array_values(array_diff(scandir($dVendor) ?: array(), array('.', '..')));
sort($dVendorFiles);
is_same(
    $dVendorFiles,
    array('Exception.php', 'PHPMailer.php', 'SMTP.php'),
    'and those THREE FILES are all that is in there — the house-style exemption covers nothing else'
);
ok(
    class_exists('PHPMailer\\PHPMailer\\PHPMailer'),
    'the three requires are enough to load it: no autoloader, no build step'
);

/* DEPLOY.txt earns its place by covering the things that go wrong silently. */
$dDeploy = (string) file_get_contents($appRoot . '/DEPLOY.txt');
$dMustSay = array(
    'NOT public_html'      => 'that public/ must not be renamed',
    'FOUR .htaccess'       => 'that there are four .htaccess files to look for',
    'SHOW HIDDEN FILES'    => 'that the file manager hides them by default',
    'APP PASSWORD'         => 'that Gmail needs an app password, not the account password',
    'MUST EQUAL'           => 'that from_email must equal user',
    'FAILS OPEN'           => 'that an unconfigured gate is a public deploy',
    'upload_max_filesize'  => 'that PHP\'s upload limit caps the configured one',
    'cron-reminders.php'   => 'the command cron',
    'cron.php?token='      => 'the URL-fetch cron',
);
foreach ($dMustSay as $dNeedle => $dWhy) {
    ok(str_contains($dDeploy, $dNeedle), 'DEPLOY.txt says ' . $dWhy);
}

/* The bundle has to carry what was just written. */
$dBuild = (string) file_get_contents($appRoot . '/tools/build-deploy.php');
foreach (array('DEPLOY.txt', 'public/cron.php') as $dShipped) {
    ok(str_contains($dBuild, $dShipped), 'tools/build-deploy.php accounts for ' . $dShipped);
}

/* Clean up after itself, the same as every other track file. */
dtest_reset();
