<?php
/**
 * The test entry point.
 *
 *   php tools/run-tests.php
 *
 * Exits non-zero on any failure, so it works as a pre-commit or pre-deploy
 * check.
 *
 * These are the FOUNDATION tests: the schema, the seed data, the constraints,
 * the shared helpers in lib/, and all of lib/dates.php. They deliberately do
 * not test any feature track — People, Interaction, Reminders and Import belong
 * to other agents, and their tests belong beside their code. Add sections here;
 * don't rewrite these.
 *
 * The database is SQLite in memory, translated from schema.sql by
 * tools/test-harness.php. Read that file's header before adding a test: MySQL
 * is the production target and a test that only passes because of SQLite
 * semantics is worse than no test at all.
 *
 * lib/dates.php needs no database at all, which is the point of it being pure.
 * That section is the one to run first when something about a due date looks
 * wrong, because it is the only place in the app that can be wrong about one.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$appRoot = dirname(__DIR__);

/* lib/bootstrap.php refuses to load without config.php, and config.php is
 * gitignored — so a fresh checkout has none. Stand one up from the tracked
 * example for the duration of the run and take it away afterwards, so that
 * `git clone && php tools/run-tests.php` works with no setup at all.
 *
 * Only ever created when absent, and only ever removed when this script was
 * the thing that created it: a real config.php with real credentials in it must
 * survive a test run untouched. */
$configPath = $appRoot . '/config.php';
$madeConfig = false;
if (!is_file($configPath)) {
    if (!@copy($appRoot . '/config.example.php', $configPath)) {
        fwrite(STDERR, "Could not create a temporary config.php from config.example.php\n");
        exit(1);
    }
    $madeConfig = true;
    register_shutdown_function(static function () use ($configPath): void {
        @unlink($configPath);
    });
}

require_once __DIR__ . '/test-harness.php';
require_once $appRoot . '/lib/bootstrap.php';
require_once $appRoot . '/lib/dates.php';
require_once $appRoot . '/lib/layout.php';

/* ------------------------------------------------------------------ harness */

$T = array('pass' => 0, 'fail' => 0, 'failures' => array());

function ok(bool $condition, string $label, string $detail = ''): bool
{
    global $T;
    if ($condition) {
        $T['pass']++;
        printf("  \033[32m✓\033[0m %s\n", $label);
        return true;
    }
    $T['fail']++;
    $T['failures'][] = $label . ($detail !== '' ? ' — ' . $detail : '');
    printf("  \033[31m✗\033[0m %s%s\n", $label, $detail !== '' ? "  ($detail)" : '');
    return false;
}

function is_same($actual, $expected, string $label): bool
{
    return ok(
        $actual === $expected,
        $label,
        $actual === $expected ? '' : 'got ' . var_export($actual, true) . ', want ' . var_export($expected, true)
    );
}

/** True when running $fn throws. Used to prove a constraint actually bites. */
function throws(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (Throwable $e) {
        return true;
    }
}

function section(string $title): void
{
    printf("\n\033[1m%s\033[0m\n", $title);
}

printf("\033[1mPersonal CRM — foundation tests\033[0m\n");
if ($madeConfig) {
    printf("  (using a temporary config.php copied from config.example.php)\n");
}

/* ==================================================================== schema */

section('Schema loads under the SQLite harness');

$pdo = null;
try {
    $pdo = harness_pdo();
    ok(true, 'schema.sql translates and creates cleanly');
} catch (Throwable $e) {
    ok(false, 'schema.sql translates and creates cleanly', $e->getMessage());
}

if ($pdo === null) {
    printf("\n\033[31mAborting: no database.\033[0m\n");
    exit(1);
}

$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
    ->fetchAll(PDO::FETCH_COLUMN);

$expectedTables = array(
    'contact_log',
    'gift_ideas',
    'import_batches',
    'import_drafts',
    'login_attempts',
    'people',
    'person_tag_map',
    'relationship_tags',
    'reminder_sends',
    'reminders',
);
is_same($tables, $expectedTables, 'exactly the ten contracted tables exist');

$indexes = $pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name NOT LIKE 'sqlite_%'")
    ->fetchAll(PDO::FETCH_COLUMN);
ok(count($indexes) >= 10, 'the KEY declarations became real indexes', 'found ' . count($indexes));

/* db() must hand back the harness connection, or every repo function the later
 * agents write is untestable. This is the single most important assertion in
 * the file. */
ok(db() === $pdo, 'db() resolves to the harness connection, so q() is testable');
is_same((int) q('SELECT COUNT(*) AS n FROM relationship_tags')->fetch()['n'], 5, 'q() runs against it');

/* ================================================================ seed data */

section('Seed data');

$tags = q('SELECT name, sort_order, is_preset FROM relationship_tags ORDER BY sort_order, id')->fetchAll();

is_same(
    array_column($tags, 'name'),
    array('Family', 'Friend', 'Close Friend', 'Colleague', 'Acquaintance'),
    'the five relationship tags seed in closeness order, not alphabetically'
);

is_same(
    array_map('intval', array_column($tags, 'sort_order')),
    array(1, 2, 3, 4, 5),
    'sort_order is 1-5, which is the People list\'s grouping order'
);

is_same(
    array_map('intval', array_column($tags, 'is_preset')),
    array(1, 1, 1, 1, 1),
    'all five are flagged as presets, which is a UI hint and not a permission'
);

/* is_preset is a hint for the delete control, nothing more. The database has
 * to allow deleting a preset, or the flag has quietly become a constraint. */
q('DELETE FROM relationship_tags WHERE name = ?', array('Acquaintance'));
is_same(
    (int) q('SELECT COUNT(*) AS n FROM relationship_tags')->fetch()['n'],
    4,
    'A PRESET TAG IS DELETABLE — is_preset is a hint, not a lock'
);
q('INSERT INTO relationship_tags (name, sort_order, is_preset) VALUES (?, ?, ?)', array('Acquaintance', 5, 1));

ok(
    throws(static fn () => q('INSERT INTO relationship_tags (name, sort_order) VALUES (?, ?)', array('Family', 9))),
    'tag names are unique, so the picker cannot show "Family" twice'
);

/* A custom tag is an ordinary row with is_preset 0 and nothing else different. */
q('INSERT INTO relationship_tags (name, sort_order, is_preset) VALUES (?, ?, ?)', array('Neighbour', 6, 0));
is_same(
    (int) q('SELECT is_preset FROM relationship_tags WHERE name = ?', array('Neighbour'))->fetch()['is_preset'],
    0,
    'a custom tag is the same row shape with is_preset 0'
);
q('DELETE FROM relationship_tags WHERE name = ?', array('Neighbour'));

/* Re-running schema.sql must not duplicate the seeds — the INSERT IGNORE is
 * what makes the file safe to re-apply after adding a table. */
foreach (harness_translate((string) file_get_contents($appRoot . '/schema.sql')) as $statement) {
    $pdo->exec($statement);
}
is_same(
    (int) q('SELECT COUNT(*) AS n FROM relationship_tags')->fetch()['n'],
    5,
    'schema.sql is re-runnable without duplicating the seeded tags'
);

/* =============================================================== constraints */

section('People — constraints and documented behaviours');

q('INSERT INTO people (name, name_key) VALUES (?, ?)', array('Alex Chen', 'alex chen'));
$alex = (int) db()->lastInsertId();
$row  = q('SELECT * FROM people WHERE id = ?', array($alex))->fetch();

is_same($row['name'], 'Alex Chen', 'people.name is stored exactly as given');
is_same($row['last_contact_date'], null, 'a new person has NEVER been contacted (NULL, not the epoch)');
is_same($row['birth_month'], null, 'and has no birthday recorded (NULL, not a sentinel)');

/* NOT NULL has to actually be NOT NULL. This looks like a test of SQLite and
 * isn't: it guards the translator bug where `INT NOT NULL` became a column of
 * type "INTEGERNOT" that was perfectly nullable, which would make every other
 * constraint test here run against a schema quietly weaker than the shipped
 * one. */
ok(
    throws(static fn () => q('INSERT INTO people (name, name_key) VALUES (?, ?)', array(null, 'x'))),
    'a person must have a name'
);

/* THE SPLIT BIRTHDAY. A yearless birthday is the normal case out of a phone's
 * vCard export (BDAY:--0415) and a single DATE column could not hold it. */
q(
    'INSERT INTO people (name, name_key, birth_month, birth_day) VALUES (?, ?, ?, ?)',
    array('Yearless Yolanda', 'yearless yolanda', 4, 15)
);
$yolanda = q('SELECT birth_year, birth_month, birth_day FROM people ORDER BY id DESC LIMIT 1')->fetch();
is_same($yolanda['birth_year'], null, 'a birthday with no year stores month and day and a NULL year');
is_same((int) $yolanda['birth_month'], 4, 'the month survives');
is_same((int) $yolanda['birth_day'], 15, 'and the day');

/* Two people really can share a name. name_key flags a possible duplicate on
 * import; it must never be able to REFUSE one. */
q('INSERT INTO people (name, name_key) VALUES (?, ?)', array('Alex Chen', 'alex chen'));
is_same(
    (int) q('SELECT COUNT(*) AS n FROM people WHERE name_key = ?', array('alex chen'))->fetch()['n'],
    2,
    'name_key is NOT unique — a duplicate name is flagged, never rejected'
);
q('DELETE FROM people WHERE name_key = ? AND id <> ?', array('alex chen', $alex));

section('Tags — the many-to-many, and its cascades');

q('INSERT INTO person_tag_map (person_id, tag_id) VALUES (?, ?)', array($alex, 1));
q('INSERT INTO person_tag_map (person_id, tag_id) VALUES (?, ?)', array($alex, 4));
is_same(
    (int) q('SELECT COUNT(*) AS n FROM person_tag_map WHERE person_id = ?', array($alex))->fetch()['n'],
    2,
    'a person can hold two tags at once, which is why these are tags and not a category'
);

/* The composite primary key is the constraint. Under SQLite's own rules a
 * nullable primary-key column would let this through; the harness forces
 * NOT NULL to match MySQL. See tools/test-harness.php. */
ok(
    throws(static fn () => q('INSERT INTO person_tag_map (person_id, tag_id) VALUES (?, ?)', array($alex, 1))),
    'THE COMPOSITE PRIMARY KEY REFUSES THE SAME LINK TWICE'
);
ok(
    throws(static fn () => q('INSERT INTO person_tag_map (person_id, tag_id) VALUES (?, ?)', array($alex, 9999))),
    'a tag link cannot point at a tag that does not exist'
);

/* Deleting a TAG drops its links. A tag link is a live pointer with no
 * historical meaning — unlike the sibling app's category names, there is
 * nothing here worth preserving through a delete. */
q('INSERT INTO relationship_tags (name, sort_order, is_preset) VALUES (?, ?, ?)', array('Temp', 7, 0));
$tempTag = (int) db()->lastInsertId();
q('INSERT INTO person_tag_map (person_id, tag_id) VALUES (?, ?)', array($alex, $tempTag));
q('DELETE FROM relationship_tags WHERE id = ?', array($tempTag));
is_same(
    (int) q('SELECT COUNT(*) AS n FROM person_tag_map WHERE tag_id = ?', array($tempTag))->fetch()['n'],
    0,
    'deleting a tag cascades to its links'
);

section('Interaction — gift ideas and the contact log');

q('INSERT INTO gift_ideas (person_id, idea_text) VALUES (?, ?)', array($alex, 'the walnut cutting board from the shop on Grand'));
$longIdea = str_repeat('a', 500);
q('INSERT INTO gift_ideas (person_id, idea_text) VALUES (?, ?)', array($alex, $longIdea));
is_same(
    strlen((string) q('SELECT idea_text FROM gift_ideas ORDER BY id DESC LIMIT 1')->fetch()['idea_text']),
    500,
    'a 500-character gift idea survives the round trip'
);

ok(
    throws(static fn () => q('INSERT INTO gift_ideas (person_id, idea_text) VALUES (?, ?)', array(9999, 'orphan'))),
    'a gift idea cannot belong to a person who does not exist'
);

/* The 1-tap "Logged today" button writes a row with no note, and that has to
 * stay possible or the button stops being one tap. */
q('INSERT INTO contact_log (person_id, logged_at) VALUES (?, ?)', array($alex, '2026-07-01 09:00:00'));
q('INSERT INTO contact_log (person_id, logged_at, note) VALUES (?, ?, ?)', array($alex, '2026-07-01 18:30:00', 'called about the move'));
is_same(
    (int) q('SELECT COUNT(*) AS n FROM contact_log WHERE person_id = ?', array($alex))->fetch()['n'],
    2,
    'TWO CONVERSATIONS IN ONE DAY ARE TWO ROWS — logging is not deduplicated'
);
is_same(
    q('SELECT note FROM contact_log WHERE person_id = ? ORDER BY logged_at ASC LIMIT 1', array($alex))->fetch()['note'],
    null,
    'a log row with no note is valid — the dashboard button must stay one tap'
);
is_same(
    array_column(q('SELECT note FROM contact_log WHERE person_id = ? ORDER BY logged_at DESC', array($alex))->fetchAll(), 'note'),
    array('called about the move', null),
    'the history reads newest-first, which is the only order a profile shows it in'
);

section('Reminders — the unique key and the send ledger');

q(
    'INSERT INTO reminders (person_id, type, recurrence_interval_days, next_due_date) VALUES (?, ?, ?, ?)',
    array($alex, 'reach_out', 60, '2026-09-01')
);
$reachOut = (int) db()->lastInsertId();

ok(
    throws(static fn () => q(
        'INSERT INTO reminders (person_id, type, next_due_date) VALUES (?, ?, ?)',
        array($alex, 'reach_out', '2026-10-01')
    )),
    'a person cannot have two reminders of the same type — UNIQUE (person_id, type)'
);

ok(
    throws(static fn () => q(
        'INSERT INTO reminders (person_id, type, next_due_date) VALUES (?, ?, ?)',
        array($alex, 'anniversary', '2026-10-01')
    )),
    'an unknown reminder type is rejected — reminders are reach_out or birthday'
);

/* A birthday reminder alongside the reach-out is fine: the unique key is on
 * the PAIR. */
q(
    'INSERT INTO reminders (person_id, type, next_due_date) VALUES (?, ?, ?)',
    array($alex, 'birthday', '2026-04-08')
);
$birthday = (int) db()->lastInsertId();
is_same(
    (int) q('SELECT COUNT(*) AS n FROM reminders WHERE person_id = ?', array($alex))->fetch()['n'],
    2,
    'one reach-out AND one birthday per person is allowed — the key is on the pair'
);

is_same(
    q('SELECT recurrence_interval_days FROM reminders WHERE id = ?', array($birthday))->fetch()['recurrence_interval_days'],
    null,
    'a birthday reminder has no interval — annual is implicit in the type'
);

/* THE WHOLE POINT OF reminder_sends. Hostinger's scheduler can double-fire and
 * SMTP can hang, and "the same birthday email eight times" is what makes you
 * stop trusting the app. */
ok(
    throws(static function () use ($birthday) {
        q('INSERT INTO reminder_sends (reminder_id, due_date) VALUES (?, ?)', array($birthday, '2026-04-08'));
        q('INSERT INTO reminder_sends (reminder_id, due_date) VALUES (?, ?)', array($birthday, '2026-04-08'));
    }),
    'THE DATABASE REFUSES A SECOND SEND FOR THE SAME REMINDER AND DUE DATE'
);

/* Which is why the cron uses an upsert. Written in MySQL; tools/test-harness.php
 * translates ON DUPLICATE KEY UPDATE into SQLite's ON CONFLICT DO UPDATE, so
 * this is the exact statement the cron will run. */
$upsert = 'INSERT INTO reminder_sends (reminder_id, due_date) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE attempts = attempts + 1';
q($upsert, array($birthday, '2026-04-08'));
q($upsert, array($birthday, '2026-04-08'));
$send = q('SELECT attempts, sent_at FROM reminder_sends WHERE reminder_id = ? AND due_date = ?', array($birthday, '2026-04-08'))->fetch();
is_same((int) $send['attempts'], 2, 'the cron\'s upsert counts attempts instead of failing');
is_same($send['sent_at'], null, 'and an attempted-but-undelivered send leaves sent_at NULL, so it retries tomorrow');

is_same(
    (int) q('SELECT COUNT(*) AS n FROM reminder_sends WHERE reminder_id = ?', array($birthday))->fetch()['n'],
    1,
    'three attempts at one due date are still exactly one ledger row'
);

/* A different due date is a different send. A reach-out reminder does NOT
 * advance its next_due_date when it fires, so the pair keeps matching the same
 * row day after day — which is exactly what stops the daily cron emailing you
 * about your sister every morning. */
q($upsert, array($reachOut, '2026-09-01'));
q($upsert, array($reachOut, '2026-09-01'));
is_same(
    (int) q('SELECT COUNT(*) AS n FROM reminder_sends WHERE reminder_id = ?', array($reachOut))->fetch()['n'],
    1,
    'an unadvanced reach-out reminder cannot email twice for the same due date'
);
q($upsert, array($reachOut, '2026-11-01'));
is_same(
    (int) q('SELECT COUNT(*) AS n FROM reminder_sends WHERE reminder_id = ?', array($reachOut))->fetch()['n'],
    2,
    'but a genuinely new due date is a genuinely new send'
);

section('Deleting a person takes everything with them');

/* Five cascades off one table. Deleting a person is a deliberate, confirmed
 * action on the profile screen — never a swipe (see CLAUDE.md) — and when it
 * happens nothing may be left pointing at a row that is gone. */
q('DELETE FROM people WHERE id = ?', array($alex));

is_same((int) q('SELECT COUNT(*) AS n FROM person_tag_map WHERE person_id = ?', array($alex))->fetch()['n'], 0, 'tag links go');
is_same((int) q('SELECT COUNT(*) AS n FROM gift_ideas WHERE person_id = ?', array($alex))->fetch()['n'], 0, 'gift ideas go');
is_same((int) q('SELECT COUNT(*) AS n FROM contact_log WHERE person_id = ?', array($alex))->fetch()['n'], 0, 'the contact log goes');
is_same((int) q('SELECT COUNT(*) AS n FROM reminders WHERE person_id = ?', array($alex))->fetch()['n'], 0, 'the reminders go');
is_same((int) q('SELECT COUNT(*) AS n FROM reminder_sends WHERE reminder_id = ?', array($birthday))->fetch()['n'], 0, 'and the send ledger goes with the reminders, two levels down');

section('Import staging');

q('INSERT INTO import_batches (filename, total_parsed) VALUES (?, ?)', array('contacts.vcf', 224));
$batch = (int) db()->lastInsertId();
$batchRow = q('SELECT status, promoted FROM import_batches WHERE id = ?', array($batch))->fetch();
is_same($batchRow['status'], 'open', 'a new batch is open');
is_same((int) $batchRow['promoted'], 0, 'with nothing promoted yet');

ok(
    throws(static fn () => q('INSERT INTO import_batches (filename, status) VALUES (?, ?)', array('x.vcf', 'reviewing'))),
    'an unknown batch status is rejected'
);

q(
    'INSERT INTO import_drafts (batch_id, name, name_key, birth_month, birth_day) VALUES (?, ?, ?, ?, ?)',
    array($batch, 'Sam Okafor', 'sam okafor', 4, 15)
);
$draft = q('SELECT status, dup_person_id FROM import_drafts ORDER BY id DESC LIMIT 1')->fetch();
is_same($draft['status'], 'pending', 'a parsed draft is pending — NO ROW IN people HAS BEEN CREATED');
is_same($draft['dup_person_id'], null, 'and carries no duplicate flag unless the parser found one');

/* dup_person_id is a FLAG, not a foreign key. It must survive the flagged
 * person being deleted, because nothing may act on it except to render a
 * warning pill — and a real key would either cascade the draft away or refuse
 * the delete. */
q('INSERT INTO people (name, name_key) VALUES (?, ?)', array('Sam Okafor', 'sam okafor'));
$sam = (int) db()->lastInsertId();
q('UPDATE import_drafts SET dup_person_id = ? WHERE batch_id = ?', array($sam, $batch));
q('DELETE FROM people WHERE id = ?', array($sam));
is_same(
    (int) q('SELECT COUNT(*) AS n FROM import_drafts WHERE dup_person_id = ?', array($sam))->fetch()['n'],
    1,
    'dup_person_id IS A FLAG, NOT A LINK — it does not cascade and cannot block a delete'
);

q('DELETE FROM import_batches WHERE id = ?', array($batch));
is_same(
    (int) q('SELECT COUNT(*) AS n FROM import_drafts WHERE batch_id = ?', array($batch))->fetch()['n'],
    0,
    'but deleting a batch does prune its drafts'
);

section('login_attempts, which lib/auth.php ports unchanged');

q('INSERT INTO login_attempts (ip, succeeded) VALUES (?, ?)', array('203.0.113.9', 0));
$attempt = q('SELECT ip, succeeded, attempted_at FROM login_attempts ORDER BY id DESC LIMIT 1')->fetch();
is_same($attempt['ip'], '203.0.113.9', 'login_attempts records the client address');
ok(is_string($attempt['attempted_at']) && $attempt['attempted_at'] !== '', 'attempted_at defaults to now');

/* ================================================================== helpers */

section('lib/bootstrap.php helpers');

is_same(cfg('db.host'), 'localhost', 'cfg() reads a nested value by dot path');
is_same(cfg('db.charset'), 'utf8mb4', 'cfg() reads a second one');
is_same(cfg('nope.not.here', 'fallback'), 'fallback', 'cfg() returns the default for a missing path');
is_same(cfg('db.host.deeper', 'fallback'), 'fallback', 'cfg() returns the default when the path runs into a scalar');
is_same(cfg('reminders.birthday_lead_days'), 7, 'cfg() exposes the birthday lead');
is_same(cfg('import.max_upload_mb'), 10, 'and the upload cap');

is_same(h('Ben & Jerry\'s "half baked" <b>'), 'Ben &amp; Jerry&#039;s &quot;half baked&quot; &lt;b&gt;', 'h() escapes quotes, ampersands and tags');
is_same(h(null), '', 'h() renders NULL as an empty string rather than "NULL"');

is_same(fmt_date('2026-04-15'), 'April 15, 2026', 'fmt_date() renders a DATE for a person');
is_same(fmt_date('2026-04-15 09:30:00'), 'April 15, 2026', 'and tolerates a DATETIME, so callers need not slice');
is_same(fmt_date('2026-04-15', 'M j'), 'Apr 15', 'the format is overridable');
is_same(fmt_date(null), '', 'A MISSING DATE RENDERS AS NOTHING, never as "Jan 1, 1970"');
is_same(fmt_date(''), '', 'and so does an empty string');
is_same(fmt_date('not a date'), '', 'and so does something unparseable, rather than throwing a screen away');

$css = asset('assets/styles.css');
ok((bool) preg_match('/^assets\/styles\.css\?v=\d+$/', $css), 'asset() cache-busts with the file mtime', $css);
is_same(asset('assets/does-not-exist.css'), 'assets/does-not-exist.css', 'asset() degrades to an unversioned path when the file is missing');
is_same(asset('/assets/styles.css'), $css, 'asset() tolerates a leading slash');

ok(defined('PUBLIC_DIR') && is_dir(PUBLIC_DIR), 'PUBLIC_DIR resolved to a real directory', (string) (defined('PUBLIC_DIR') ? PUBLIC_DIR : ''));

/* ================================================================ the clock */

section('One clock');

is_same(
    date_default_timezone_get(),
    (string) cfg('timezone'),
    'lib/bootstrap.php set PHP\'s zone from config, before anything could read a date'
);
is_same(cfg('timezone'), 'America/Chicago', 'and the configured zone is the one PLAN.md assumes');

is_same(
    crm_today(),
    (new DateTimeImmutable('now', new DateTimeZone((string) cfg('timezone'))))->format('Y-m-d'),
    'crm_today() is today IN THE CONFIGURED ZONE, not the server\'s'
);
ok(
    (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', crm_today()),
    'and it is a plain Y-m-d, which is what every DATE column compares against'
);

/* lib/db.php pins MySQL to the same zone as a numeric offset. There is no
 * MySQL here to check that against, so what is asserted is the thing that
 * would break silently: that the offset is computed from the same config value
 * and is a numeric offset rather than a zone NAME. Hostinger's MySQL commonly
 * has the named-timezone tables unloaded, and `SET time_zone = 'America/
 * Chicago'` then fails AT CONNECT TIME, taking the whole app down. */
$dbSrc = (string) file_get_contents($appRoot . '/lib/db.php');
ok(
    str_contains($dbSrc, 'MYSQL_ATTR_INIT_COMMAND') && str_contains($dbSrc, 'SET time_zone'),
    'lib/db.php pins the connection\'s time zone at connect time'
);
ok(
    (bool) preg_match("/new DateTimeZone\(\(string\) cfg\('timezone'/", $dbSrc)
        && str_contains($dbSrc, "->format('P')"),
    'and derives it from cfg(\'timezone\') as a NUMERIC OFFSET, not a zone name'
);

/* ================================================================== dates */

/* lib/dates.php needs no database, no config and no clock (except crm_today,
 * checked above). This is the highest-risk logic in the app and the cheapest
 * to test, which is the entire reason it is shaped this way. */

section('lib/dates.php — next_birthday()');

is_same(next_birthday(4, 15, '2026-01-01'), '2026-04-15', 'a birthday later this year is this year\'s');
is_same(next_birthday(4, 15, '2026-04-14'), '2026-04-15', 'the day before, it is tomorrow');
is_same(next_birthday(4, 15, '2026-04-15'), '2026-04-15', 'ON THE DAY ITSELF, the next occurrence is TODAY');
is_same(next_birthday(4, 15, '2026-04-16'), '2027-04-15', 'the day after, it rolls to next year');
is_same(next_birthday(1, 1, '2026-12-31'), '2027-01-01', 'and a January birthday rolls across the year boundary');

/* FEBRUARY 29. Three years in four it does not exist, and something has to be
 * chosen. Feb 28 keeps the card in the right month; March 1 does not. */
is_same(next_birthday(2, 29, '2028-01-01'), '2028-02-29', 'in a leap year, Feb 29 is Feb 29');
is_same(next_birthday(2, 29, '2026-01-01'), '2026-02-28', 'IN A NON-LEAP YEAR, FEB 29 BECOMES FEB 28');
is_same(next_birthday(2, 29, '2026-03-01'), '2027-02-28', 'and once it has passed it rolls to the next year\'s Feb 28');
is_same(next_birthday(2, 29, '2027-12-31'), '2028-02-29', 'rolling from a non-leap year into a leap one restores the 29th');

/* 2100 is divisible by 4 and is NOT a leap year. Nobody will ever hit this;
 * it costs one line and proves the clamp is real arithmetic rather than a
 * hardcoded "divisible by four". */
is_same(next_birthday(2, 29, '2100-01-01'), '2100-02-28', 'the century rule is handled too, because the clamp asks the calendar');

/* A vCard can carry BDAY:--0631. The clamp is written generally so a junk
 * import cannot crash a dashboard six months later. */
is_same(next_birthday(6, 31, '2026-01-01'), '2026-06-30', 'an impossible day clamps to the end of its month');
is_same(next_birthday(13, 1, '2026-01-01'), '2026-01-01', 'and an impossible month degrades to today rather than throwing');

section('lib/dates.php — birthday_reminder_date()');

is_same(birthday_reminder_date(4, 15, '2026-01-01', 7), '2026-04-08', 'seven days before the birthday');
is_same(birthday_reminder_date(4, 15, '2026-01-01', 0), '2026-04-15', 'a zero lead is the day itself');
is_same(birthday_reminder_date(4, 15, '2026-01-01', 30), '2026-03-16', 'and a long lead crosses back into the previous month');

/* THE YEAR-BOUNDARY CASE. A January 3rd birthday reminds on December 27th of
 * the PREVIOUS year. Written backwards — "the next occurrence of (birthday
 * minus 7 days)" — asking on January 2nd returns 2026-12-27: 358 days late,
 * and it looks approximately right in a spot check because it is still seven
 * days before a birthday. */
is_same(birthday_reminder_date(1, 3, '2025-12-20', 7), '2025-12-27', 'a January birthday reminds in DECEMBER, of the previous year');
is_same(birthday_reminder_date(1, 3, '2026-01-02', 7), '2025-12-27', 'AND ASKING AFTER THE LEAD DATE STILL RETURNS IT — an overdue reminder is overdue, not skipped for a year');
ok(
    birthday_reminder_date(1, 3, '2026-01-02', 7) < '2026-01-02',
    'which means the returned date can be in the past, so `next_due_date <= today` fires it'
);
is_same(birthday_reminder_date(1, 3, '2026-01-04', 7), '2026-12-27', 'once the birthday itself has passed, the reminder moves to next year\'s lead date');

/* Feb 29 plus the lead: the reminder lands on Feb 21 in an ordinary year. */
is_same(birthday_reminder_date(2, 29, '2026-01-01', 7), '2026-02-21', 'a leap-day birthday reminds on Feb 21 in a non-leap year');
is_same(birthday_reminder_date(2, 29, '2028-01-01', 7), '2028-02-22', 'and on Feb 22 in a leap year, because the birthday itself moved');

/* A March 1st birthday with a long lead crosses February, whose length varies.
 * Subtracting from the birthday rather than adding to a month index is what
 * makes this fall out for free. */
is_same(birthday_reminder_date(3, 1, '2026-01-01', 7), '2026-02-22', 'a March 1st birthday counts back through a 28-day February');
is_same(birthday_reminder_date(3, 1, '2028-01-01', 7), '2028-02-23', 'and through a 29-day one');

section('lib/dates.php — next_cadence_date()');

is_same(next_cadence_date('2026-07-01', 60, '2026-08-03'), '2026-08-30', 'the cadence runs from the last contact');
is_same(next_cadence_date(null, 60, '2026-08-03'), '2026-10-02', 'NEVER CONTACTED STARTS THE CLOCK TODAY, not at the epoch');
ok(
    next_cadence_date(null, 60, '2026-08-03') > '2026-08-03',
    'so a freshly imported address book is not four hundred overdue rows on day one'
);
is_same(next_cadence_date('2026-07-01 18:30:00', 60, '2026-08-03'), '2026-08-30', 'a DATETIME is tolerated, so contact_log rows can be passed straight in');
is_same(next_cadence_date('2026-08-03', 1, '2026-08-03'), '2026-08-04', 'a one-day cadence is the shortest one allowed');
is_same(next_cadence_date('2026-08-03', 0, '2026-08-03'), '2026-08-04', 'and a zero cadence is clamped to one, because "due again today" never clears');
is_same(next_cadence_date('2026-12-15', 30, '2026-12-20'), '2027-01-14', 'the cadence crosses a year boundary without help');

section('lib/dates.php — days_since()');

is_same(days_since('2026-06-30', '2026-08-03'), 34, 'the "34 days ago" subline');
is_same(days_since('2026-08-03', '2026-08-03'), 0, 'contacted today is 0');
is_same(days_since(null, '2026-08-03'), null, 'NEVER CONTACTED IS NULL, NOT 0 — the People list renders it as "never"');
is_same(days_since('2026-08-04', '2026-08-03'), -1, 'a future date gives a negative rather than a plausible-looking 0');
is_same(days_since('2026-06-30 18:30:00', '2026-08-03'), 34, 'a DATETIME is tolerated');
is_same(days_since('nonsense', '2026-08-03'), null, 'and a malformed value degrades to null rather than to a wrong number');
is_same(days_since('2025-08-03', '2026-08-03'), 365, 'a full year is 365 days');
is_same(days_since('2027-08-03', '2028-08-03'), 366, 'and a leap year is 366');

section('lib/dates.php — DST cannot move a day');

/* America/Chicago springs forward on 2026-03-08 and falls back on 2026-11-01.
 * Midnight to midnight across a spring-forward is 23 hours, and a naive
 * ->diff()->days floors that to 0 — a reminder due "today" for two days
 * running, once a year, in March. All arithmetic in lib/dates.php happens in
 * UTC for exactly this reason. */
is_same(days_since('2026-03-08', '2026-03-09'), 1, 'the day the clocks go FORWARD is still one day long');
is_same(days_since('2026-11-01', '2026-11-02'), 1, 'and so is the day they go back');
is_same(days_since('2026-03-01', '2026-03-31'), 30, 'a month spanning the transition counts every day exactly once');
is_same(next_cadence_date('2026-03-01', 10, '2026-03-01'), '2026-03-11', 'a cadence counted across the transition lands on the right date');
is_same(fmt_relative_due('2026-03-09', '2026-03-08'), 'Tomorrow', 'and "tomorrow" is still tomorrow on the morning the clocks change');

section('lib/dates.php — fmt_relative_due()');

is_same(fmt_relative_due('2026-08-03', '2026-08-03'), 'Today', 'today');
is_same(fmt_relative_due('2026-08-04', '2026-08-03'), 'Tomorrow', 'tomorrow');
is_same(fmt_relative_due('2026-08-02', '2026-08-03'), 'Yesterday', 'yesterday');
is_same(fmt_relative_due('2026-07-31', '2026-08-03'), '3 days ago', 'three days ago');
is_same(fmt_relative_due('2026-06-30', '2026-08-03'), '34 days ago', 'AND STILL IN DAYS AT 34 — how overdue it is, is the information');
is_same(fmt_relative_due('2026-08-07', '2026-08-03'), 'Friday', 'inside the coming week it is a weekday name');
is_same(fmt_relative_due('2026-08-09', '2026-08-03'), 'Sunday', 'up to six days out');
is_same(fmt_relative_due('2026-08-10', '2026-08-03'), 'August 10', 'beyond that a weekday is ambiguous, so it becomes a date');
is_same(fmt_relative_due('nonsense', '2026-08-03'), 'nonsense', 'an unreadable date renders as itself rather than as a lie');

/* ===================================================================== auth */

section('lib/auth.php');

ok(AUTH_SLOW_AFTER < AUTH_LOCK_AFTER, 'delays start escalating before the lockout, not after');
ok(AUTH_MAX_DELAY > 0 && AUTH_MAX_DELAY <= 10, 'the delay cap keeps a request from hanging');
ok(AUTH_WINDOW_MINUTES > 0, 'the throttle window is finite, so a lockout always expires');

/* The escalation curve: flat until AUTH_SLOW_AFTER, then doubling, then
 * capped. Written out as literals rather than recomputed from the constants —
 * a test that repeats the implementation's arithmetic proves nothing.
 *
 * This section exists at all because Grocery factored auth_attempt_delay() out
 * of auth_attempt_login() as a pure function. The other siblings have the same
 * arithmetic inline and therefore untested; this app inherits the good copy. */
is_same(auth_attempt_delay(0), 0.25, 'no failures: the baseline delay only');
is_same(auth_attempt_delay(2), 0.25, 'still baseline just below the escalation point');
is_same(auth_attempt_delay(3), 0.5, 'the third failure starts the doubling');
is_same(auth_attempt_delay(4), 1.0, 'and it doubles');
is_same(auth_attempt_delay(5), 2.0, 'and again');
is_same(auth_attempt_delay(6), 4.0, 'reaching the cap');
is_same(auth_attempt_delay(9), 4.0, 'at the lockout threshold it is still capped');
is_same(auth_attempt_delay(40), 4.0, 'and an absurd count cannot make a request hang');

ok(
    auth_attempt_delay(AUTH_LOCK_AFTER) <= AUTH_MAX_DELAY,
    'the delay is capped at every value up to the lockout'
);

is_same(auth_is_configured(), false, 'the example config leaves the gate unarmed (CHANGE_ME)');

/* ============================================== cross-file consistency ===== */

section('Contracts that span two files');

/* These are the pairs no single-file test can catch, and every one of them
 * fails silently in production rather than loudly. */

$apiJs = (string) file_get_contents($appRoot . '/public/assets/api.js');
ok(
    (bool) preg_match("/CSRF_VALUE\s*=\s*'([^']+)'/", $apiJs, $m) && $m[1] === CSRF_HEADER_VALUE,
    'api.js sends the CSRF value lib/auth.php checks for',
    'auth.php has ' . CSRF_HEADER_VALUE . ', api.js has ' . ($m[1] ?? 'nothing')
);

/* The session cookie name has to be this app's own, or signing in here signs
 * you out of Grocery on the same host. */
$authSrc = (string) file_get_contents($appRoot . '/lib/auth.php');
ok(
    str_contains($authSrc, "session_name('personalcrm')"),
    'the session cookie is this app\'s own, independent of the siblings on the same host'
);

/* swipe.js and inline-edit.js must agree on how far a finger may travel and
 * still count as a tap. If they drift, a gesture can both start a swipe and
 * open the editor. */
$swipeJs = (string) file_get_contents($appRoot . '/public/assets/swipe.js');
$editJs  = (string) file_get_contents($appRoot . '/public/assets/inline-edit.js');
preg_match('/LOCK_DISTANCE\s*=\s*(\d+)/', $swipeJs, $a);
preg_match('/MOVE_TOLERANCE\s*=\s*(\d+)/', $editJs, $b);
ok(
    isset($a[1], $b[1]) && $a[1] === $b[1],
    'swipe.js and inline-edit.js share one tap-vs-drag threshold',
    'swipe ' . ($a[1] ?? '?') . 'px, inline-edit ' . ($b[1] ?? '?') . 'px'
);

/* The two touch-action declarations that make the gestures coexist with
 * scrolling. Losing either is invisible until you test on a phone. */
$stylesCss = (string) file_get_contents($appRoot . '/public/assets/styles.css');
ok(str_contains($stylesCss, 'touch-action: pan-y'), '.list-row still declares touch-action: pan-y');
ok(str_contains($stylesCss, 'touch-action: none'), '.drag-handle still declares touch-action: none');

/* The stylesheet is ported VERBATIM from Grocery and is Foundation-owned. The
 * components docs/CONTRACTS.md §4 promises the feature agents have to actually
 * be in it — a missing one is a screen that renders unstyled. */
foreach (array('.list-row', '.row-slide', '.row-sub', '.cat-group', '.sheet', '.sheet-panel', '.sheet-cancel', '.icon-btn', '.composer', '.accordion', '.snackbar') as $class) {
    ok(str_contains($stylesCss, $class), 'styles.css still defines ' . $class);
}

section('The app menu');

/* menu.js builds its sheet out of the classes the stylesheet already carries.
 * If it ever grows a class of its own, that class is missing from a
 * Foundation-owned stylesheet nobody is allowed to edit — so the screen just
 * renders an unstyled list over the page. */
$menuJs = (string) file_get_contents($appRoot . '/public/assets/menu.js');
ok(str_contains($menuJs, 'export function attachMenu'), 'menu.js exports attachMenu()');
ok(str_contains($menuJs, 'export function closeMenu'), 'menu.js exports closeMenu()');
ok(str_contains($menuJs, "'sheet'") && str_contains($menuJs, "'sheet-panel'"), 'and builds its sheet from the house .sheet classes');
ok(
    !preg_match('/\.style\.(?!removeProperty|setProperty)/', $menuJs) && !str_contains($menuJs, '<style'),
    'menu.js adds no CSS of its own — styles.css is Foundation-owned'
);

/* THE BUG shared_module_map() EXISTS FOR. A module in SHARED_MODULES that is
 * not on disk is left unmapped and silently served from cache forever; a
 * module on disk that is not in the list is never cache-busted at all. Both
 * present as "the feature is there and does nothing". */
foreach (SHARED_MODULES as $module) {
    ok(is_file(PUBLIC_DIR . '/assets/' . $module), $module . ' is in SHARED_MODULES and on disk');
}
$onDisk = array_map('basename', glob(PUBLIC_DIR . '/assets/*.js') ?: array());
sort($onDisk);
$listed = SHARED_MODULES;
sort($listed);
is_same($onDisk, $listed, 'every shared JS module is declared in SHARED_MODULES, so all of them get cache-busted');

$hamburger = page_menu();
ok(str_contains($hamburger, 'class="icon-btn"'), 'page_menu() renders the house 48px icon button');
ok(str_contains($hamburger, 'id="app-menu"'), 'with the id menu.js attaches to');
ok(str_contains($hamburger, '<svg viewBox="0 0 24 24"'), 'and an inline 24-unit svg, which is what .icon-btn svg styles');
ok(str_contains($hamburger, 'aria-label="Menu"'), 'and an accessible name, since it has no text');

/* Every screen links to every other screen, and the hrefs have to be the files
 * the later agents are actually building. */
is_same(
    array_keys(nav_tabs()),
    array('today', 'people', 'add'),
    'the three tab keys are the contracted ones'
);
is_same(
    array_column(nav_tabs(), 'href'),
    array('index.php', 'people.php', 'add.php'),
    'the tab hrefs point at the three files the feature agents own'
);
is_same(APP_NAME, 'Personal CRM', 'the app name is what the login screen and <title> say');

/* The four .htaccess files are the only thing keeping config.php and the
 * uploads folder off the public internet, and every file manager hides
 * dotfiles by default — so an absent one is invisible until someone fetches
 * /config.php over HTTP and gets it. */
foreach (array('.htaccess', 'lib/.htaccess', 'tools/.htaccess', 'uploads/.htaccess') as $htaccess) {
    ok(is_file($appRoot . '/' . $htaccess), $htaccess . ' is present');
}
ok(
    str_contains((string) file_get_contents($appRoot . '/uploads/.htaccess'), 'Require all denied'),
    'uploads/ is deny-all — an uploaded .vcf is everyone in somebody\'s phone'
);

/* config.example.php is what run-tests.php and a fresh checkout both stand up
 * from, so a key added to config.php without being added here is a key that
 * exists on one laptop. */
foreach (array('timezone', 'reminders', 'smtp', 'import', 'cron') as $key) {
    ok(cfg($key) !== null, 'config.example.php carries the ' . $key . ' block');
}
is_same(cfg('smtp.from_email'), cfg('smtp.user'), 'SMTP from_email matches user — Gmail silently rejects a From it has not authorized');

/* House style, checked mechanically because it is the thing that erodes. */
$phpFiles = array();
foreach (array('lib', 'public', 'tools') as $dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appRoot . '/' . $dir)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $phpFiles[] = $file->getPathname();
        }
    }
}
$missingStrict = array();
foreach ($phpFiles as $file) {
    if (!str_contains((string) file_get_contents($file), 'declare(strict_types=1);')) {
        $missingStrict[] = basename($file);
    }
}
ok($missingStrict === array(), 'every PHP file declares strict_types', implode(', ', $missingStrict));

/* array() long syntax, matching the siblings. Checked on lib/ only: the
 * short-array literal is legal PHP and nothing breaks, which is exactly why it
 * drifts in without anyone noticing. */
$shortArray = array();
foreach ($phpFiles as $file) {
    $src = (string) file_get_contents($file);
    /* '[' after '=', '(' or ',' is an array literal; '[' after an identifier or
     * a ']' is a subscript, which is fine. */
    if (preg_match('/(?:=>|=|\(|,)\s*\[\s*(?:\'|"|\d|\))/', $src)) {
        $shortArray[] = basename($file);
    }
}
ok($shortArray === array(), 'array() long syntax throughout, matching the siblings', implode(', ', $shortArray));

ok(count($phpFiles) >= 8, 'the foundation PHP files are all present', count($phpFiles) . ' found');

/* ===================================================================== done */

printf("\n\033[1m%d passed, %d failed\033[0m\n", $T['pass'], $T['fail']);
if ($T['fail'] > 0) {
    foreach ($T['failures'] as $failure) {
        printf("  \033[31m%s\033[0m\n", $failure);
    }
    exit(1);
}
exit(0);
