<?php
/**
 * Import track tests — lib/vcard.php, lib/import.php, and the contracts
 * public/import.php and the four endpoints have to keep.
 *
 * Required at global scope by tools/run-tests.php, so ok(), is_same(),
 * throws(), section() and $T behave exactly as they would inline. Helpers are
 * prefixed itest_ so they cannot collide with another track's.
 *
 * THE PARSER IS THE POINT OF THIS FILE. It is the one component in the app fed
 * by something nobody here wrote — a file a phone exported — so every assertion
 * about it is made against a REAL FIXTURE committed under tests/fixtures/,
 * never against a string built in the test. Following the reasoning in the
 * sibling app's .gitignore about un-refetchable data: the fixture is the input
 * and the evidence at once, and "the parser handles what iOS actually writes"
 * is then something a test can prove rather than something a comment claims.
 *
 * The endpoints themselves are not exercised end to end: standing up sessions,
 * headers and a request body under CLI would test the harness rather than the
 * import. What IS checked is that each of them carries the three gates, and
 * every rule underneath them runs through the real repo functions.
 *
 * Everything with a database in it runs against the SQLite translation of
 * schema.sql. MySQL is the production target; nothing below depends on SQLite
 * semantics.
 */

declare(strict_types=1);

require_once $appRoot . '/lib/import.php';

/** A committed fixture, by name. */
function itest_fixture(string $name): string
{
    return dirname(__DIR__) . '/tests/fixtures/' . $name;
}

/** Parse a fixture. */
function itest_parse(string $name, int $max = 0): array
{
    return vcard_parse_file(itest_fixture($name), $max);
}

/** One parsed contact by name, or an empty row so an assertion can still read it. */
function itest_contact(array $result, string $name): array
{
    foreach ($result['contacts'] as $contact) {
        if ($contact['name'] === $name) {
            return $contact;
        }
    }
    return array(
        'name' => '', 'birth_year' => null, 'birth_month' => null, 'birth_day' => null,
        'address' => null, 'phone' => null, 'email' => null,
    );
}

/** The names a parse produced, in file order. @return string[] */
function itest_names(array $result): array
{
    return array_map(static fn (array $c): string => (string) $c['name'], $result['contacts']);
}

/** Empty the three tables this track writes to, plus people. */
function itest_reset(): void
{
    /* Drafts first: they are the child rows, and the harness has foreign keys
     * ON (unlike SQLite's default), so the order matters here in exactly the
     * way it would in MySQL. */
    q('DELETE FROM import_drafts');
    q('DELETE FROM import_batches');
    q('DELETE FROM person_tag_map');
    q('DELETE FROM people');
}

/**
 * A PHP file with its comments removed.
 *
 * Tokenised rather than grepped, because the strings the assertions look for —
 * "db()", "Add all" — appear in the very comments that explain why the code
 * does not contain them, and a naive grep therefore fails a file precisely
 * because it documents itself properly.
 */
function itest_code_only(string $source): string
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

/**
 * A JS module with its comments removed.
 *
 * Same reason as itest_code_only(): the phrase the assertion below looks for
 * ("add all") appears in the very comment that explains why the screen has no
 * such button, so a plain grep fails the file for documenting itself. PHP's
 * tokenizer cannot help here — it would read the whole module as inline HTML —
 * so this is the two comment forms JavaScript has, and a `//` is only treated
 * as one when it is not part of a URL.
 */
function itest_js_code_only(string $source): string
{
    $stripped = preg_replace('#/\*.*?\*/#s', '', $source);
    $stripped = preg_replace('#(^|[^:])//[^\n]*#', '$1', (string) $stripped);
    return (string) $stripped;
}

/** A fixed clock for the staging tests. Never crm_today() — see lib/dates.php. */
const ITEST_TODAY = '2026-08-03';

/* ====================================================== the parser, purity ==*/

section('lib/vcard.php is pure — no database, no config, no clock');

$vcardCode = itest_code_only((string) file_get_contents($appRoot . '/lib/vcard.php'));

ok(!str_contains($vcardCode, 'db()') && !str_contains($vcardCode, 'q('), 'the parser touches no database');
ok(!str_contains($vcardCode, 'cfg('), 'the parser reads no config — the caps are the importer\'s business');
ok(
    !str_contains($vcardCode, 'crm_today') && !str_contains($vcardCode, 'date(')
        && !str_contains($vcardCode, 'time()') && !str_contains($vcardCode, 'strtotime'),
    'the parser never asks what day it is, so an implausible birth year is staging\'s problem'
);
ok(
    !str_contains($vcardCode, 'file_get_contents'),
    'the parser never slurps the file — a 30 MB export is mostly photographs'
);

/* ============================================================== iOS, 3.0 ===*/

section('vCard 3.0 out of an iPhone');

$ios = itest_parse('ios-3.0.vcf');

is_same($ios['parsed'], 2, 'both cards in the iOS export are found');
is_same($ios['skipped'], 0, 'and neither is refused');
is_same(itest_names($ios), array('Alex Chen', 'Dr. Ana-Maria O\'Brien'), 'FN is the name, verbatim');

$alex = itest_contact($ios, 'Alex Chen');

/* The file is CRLF, like every real export. A parser that only strips \n
 * stores a carriage return on the end of every value, and the symptom is a
 * tel: link that silently does not dial. */
ok(
    !str_contains(json_encode($ios['contacts']) ?: '', '\\r'),
    'CRLF line endings leave no carriage return in any value'
);

is_same($alex['phone'], '+1 512 555 0199', 'TYPE=CELL beats an earlier TYPE=WORK and a later TYPE=HOME;TYPE=FAX');
is_same($alex['email'], 'alex@example.org', 'TYPE=HOME;TYPE=pref beats the work address that came first');
is_same(
    $alex['address'],
    '1600 Sycamore Ave, Austin, TX, 78702, USA',
    'ADR\'s seven parts join into one readable line, empties dropped'
);
is_same($alex['birth_year'], 1991, 'BDAY:1991-04-15 gives a year');
is_same($alex['birth_month'], 4, '...a month');
is_same($alex['birth_day'], 15, '...and a day');

$ana = itest_contact($ios, 'Dr. Ana-Maria O\'Brien');
is_same($ana['phone'], '512 555 0142', 'an untyped TEL is taken when it is the only one');
is_same($ana['birth_month'], 7, 'BDAY;value=date:--0703 parses through its parameter');
is_same($ana['birth_year'], null, 'and a yearless birthday keeps a NULL year, not a sentinel');

/* item1.ADR — iOS ties a value to its custom label with a group prefix. A
 * parser that reads the group as the property name finds no ADR at all. */
ok($alex['address'] !== null, 'a group prefix (item1.ADR) does not hide the property');

/* ============================================ Android 2.1, quoted-printable */

section('vCard 2.1 out of an Android phone — quoted-printable');

$android = itest_parse('android-2.1-qp.vcf');

is_same($android['parsed'], 3, 'all three cards are found');
is_same(itest_names($android), array('José Muñoz', 'Anné Schönberg', 'Ngozi Okafor'), 'quoted-printable decodes to real letters');

$jose = itest_contact($android, 'José Muñoz');
is_same(
    $jose['address'],
    'Calle Mayor 12, 2º B, Madrid, 28013, España',
    'a QUOTED-PRINTABLE soft line break rejoins mid-value, before the semicolons are split'
);
is_same($jose['phone'], '+34 600 123 456', 'the bare 2.1 parameter TEL;CELL ranks as a mobile');
is_same($jose['email'], 'jose@example.es', 'EMAIL;INTERNET is untyped as far as ranking goes');
is_same($jose['birth_year'], 1988, 'BDAY:1988-11-02');

/* The second card has no FN at all, which is ordinary in 2.1 — and its N is
 * split by a soft line break in the middle of a name. */
$anne = itest_contact($android, 'Anné Schönberg');
ok($anne['name'] === 'Anné Schönberg', 'with no FN, N is assembled: given then family');
is_same($anne['email'], 'anne@example.de', 'and the rest of the card still parses after the fold');

$ngozi = itest_contact($android, 'Ngozi Okafor');
is_same($ngozi['phone'], '+234 803 555 0177', 'TEL;CELL beats a TEL;VOICE that came first');
is_same($ngozi['birth_month'], 9, 'BDAY:19770930 — the unseparated form');
is_same($ngozi['birth_day'], 30, '...day and month the right way round');

/* ======================================================== folding, escapes ==*/

section('Line folding and escaping');

$folded = itest_parse('folded.vcf');
$priya  = itest_contact($folded, 'Priya Raghunathan');

is_same(
    $priya['address'],
    'Flat 3, Belvedere House; rear entrance, Kingston upon Thames, Greater London, KT1 2AB, United Kingdom',
    'THE LOAD-BEARING ONE: an escaped \\; inside a street does not split the address'
);
ok(str_contains((string) $priya['address'], 'Kingston upon Thames'), 'a fold in the middle of a word rejoins exactly');
ok(!str_contains((string) $priya['address'], 'Th ames'), 'and does not leave the fold\'s own space behind');
is_same($priya['birth_month'], 4, 'BDAY:--04-15');

$tomasz = itest_contact($folded, 'Tomasz Wójcik');
is_same($tomasz['name'], 'Tomasz Wójcik', 'a fold marked with a TAB rather than a space is still a fold');

/* The rules, on their own, away from a file. */
is_same(vcard_unescape('a\\,b'), 'a,b', 'vcard_unescape: \\, is a comma');
is_same(vcard_unescape('a\\;b'), 'a;b', 'vcard_unescape: \\; is a semicolon');
is_same(vcard_unescape('a\\nb'), "a\nb", 'vcard_unescape: \\n is a newline');
is_same(vcard_unescape('a\\Nb'), "a\nb", 'vcard_unescape: \\N is one too — exporters write both');
is_same(vcard_unescape('a\\\\b'), 'a\\b', 'vcard_unescape: \\\\ is a backslash');
is_same(vcard_unescape('C:\\Users'), 'C:\\Users', 'an undefined escape keeps its backslash rather than being edited');

is_same(vcard_split_escaped('a;b;c', ';'), array('a', 'b', 'c'), 'vcard_split_escaped splits on the separator');
is_same(vcard_split_escaped('a\\;b;c', ';'), array('a\\;b', 'c'), '...and steps over an escaped one, leaving it escaped for later');
is_same(vcard_split_escaped(';;x', ';'), array('', '', 'x'), '...and keeps the empty components, which carry position');

is_same(vcard_split_at_colon('TEL;TYPE=CELL:+1 555'), array('TEL;TYPE=CELL', '+1 555'), 'the head/value split');
is_same(
    vcard_split_at_colon('TEL;TYPE="voice:home":+1 555'),
    array('TEL;TYPE="voice:home"', '+1 555'),
    'a colon inside a quoted parameter is not the value separator'
);
is_same(vcard_split_at_colon('NO COLON HERE'), null, 'a line with no colon is not a property');

/* =============================================================== photos ====*/

section('PHOTO is skipped, not buffered');

$photoPath  = itest_fixture('photo.vcf');
$photoBytes = (int) filesize($photoPath);

$peakBefore = memory_get_peak_usage();
$photo      = itest_parse('photo.vcf');
$peakAfter  = memory_get_peak_usage();

is_same($photo['parsed'], 2, 'both cards in a photo-heavy export are found');
is_same(itest_names($photo), array('Marcus Webb', 'Simone Delacroix'), 'and both keep their names');

$marcus = itest_contact($photo, 'Marcus Webb');
is_same($marcus['phone'], '+1 206 555 0123', 'the properties AFTER a folded PHOTO still parse');
is_same($marcus['email'], 'marcus@example.com', '...all of them');
is_same($marcus['birth_month'], 12, '...including the BDAY');

/* The 2.1 spelling: ENCODING=BASE64 with an UNINDENTED body ending at a blank
 * line, which folding alone cannot find the end of. */
$simone = itest_contact($photo, 'Simone Delacroix');
is_same($simone['phone'], '+33 6 12 34 56 78', 'an unindented 2.1 BASE64 photo body ends at the blank line');
is_same($simone['email'], 'simone@example.fr', '...and the card resumes cleanly after it');

$encoded = json_encode($photo['contacts']) ?: '';
$sample  = substr((string) file_get_contents($photoPath), 200, 40);
ok(!str_contains($encoded, $sample), 'no base64 from the photo reaches any stored value');
ok(strlen($encoded) < 1000, 'the whole parse result is smaller than one line of the photo', strlen($encoded) . ' bytes');

/* THE ASSERTION THE STREAMING READ EXISTS FOR. A 400-contact export can be
 * 30 MB of photographs; if the parser buffered them, peak memory would track
 * the file size. It reads a line at a time, so it does not. */
ok(
    $peakAfter - $peakBefore < $photoBytes / 4,
    'peak memory does not track the size of the photos',
    'file ' . $photoBytes . ' bytes, peak grew ' . ($peakAfter - $peakBefore)
);

/* ============================================================= birthdays ====*/

section('BDAY — a yearless birthday is the ordinary case');

$bdays = itest_parse('bday.vcf');
is_same($bdays['parsed'], 10, 'every card in the birthday fixture is read');

$cases = array(
    'Yearless April'        => array(null, 4, 15),
    'Yearless Dashed'       => array(null, 4, 15),
    'Basic Eight Digits'    => array(1991, 4, 15),
    'Extended With Dashes'  => array(1991, 4, 15),
    'With A Time Of Day'    => array(1984, 2, 29),
    'Single Digit Parts'    => array(1991, 4, 5),
    'Impossible Day'        => array(null, 6, 31),
    'Placeholder Zero Year' => array(null, 4, 15),
    'Not A Date At All'     => array(null, null, null),
    'No Birthday'           => array(null, null, null),
);
foreach ($cases as $name => $want) {
    $contact = itest_contact($bdays, (string) $name);
    is_same(
        array($contact['birth_year'], $contact['birth_month'], $contact['birth_day']),
        $want,
        'BDAY on "' . $name . '"'
    );
}

/* --0631 is stored as the file said it. lib/dates.php clamps an impossible day
 * on READ, to the last day of that month; clamping here as well would quietly
 * rewrite what the export claimed and leave nothing to notice later. */
is_same(itest_contact($bdays, 'Impossible Day')['birth_day'], 31, 'an impossible day is stored as written, not clamped here');

is_same(vcard_parse_bday(''), null, 'an empty BDAY is no birthday');
is_same(vcard_parse_bday('--0000'), null, 'a zero month and day is no birthday');
is_same(vcard_parse_bday('1991-13-01'), null, 'a thirteenth month is no birthday');
is_same(vcard_parse_bday('--0432'), null, 'a thirty-second day is no birthday');

/* ============================================================== fail soft ===*/

section('Malformed files degrade one line at a time');

$malformed = itest_parse('malformed.vcf');
is_same($malformed['parsed'], 3, 'a card with no END:VCARD is still counted');
is_same(
    itest_names($malformed),
    array('Half A Card', 'Second Card', 'Third Card'),
    'junk outside a card, a line with no colon and a missing END each cost only themselves'
);
is_same(itest_contact($malformed, 'Third Card')['phone'], '+1 555 0203', 'a card left open at EOF is kept, as complete as it got');

$nameless = itest_parse('no-name.vcf');
is_same($nameless['parsed'], 4, 'cards with no name are COUNTED');
is_same($nameless['skipped'], 3, '...and reported as skipped');
is_same(itest_names($nameless), array('Only Real Name'), '...and never staged as a nameless row');

/* The cap, and the flag that says the cap bit. Silently dropping the tail of
 * somebody's address book is a thing they find out about a year later. */
$capped = itest_parse('bday.vcf', 3);
is_same(count($capped['contacts']), 3, 'import.max_contacts stops the parse');
ok($capped['truncated'], 'and says there was more file');
ok(!itest_parse('bday.vcf', 10)['truncated'], 'a file that exactly fills the cap is not "truncated"');

/* ============================================================== sniffing ====*/

section('Upload safety');

ok(vcard_sniff_file(itest_fixture('ios-3.0.vcf')), 'a real export sniffs as a vCard');
ok(!vcard_sniff_file($appRoot . '/schema.sql'), 'something that is not one does not');
ok(!vcard_sniff_file($appRoot . '/nothing-here.vcf'), 'and a file that cannot be opened is not one either');

is_same(import_ini_bytes('10M'), 10 * 1024 * 1024, 'ini shorthand: 10M');
is_same(import_ini_bytes('512K'), 512 * 1024, 'ini shorthand: 512K');
is_same(import_ini_bytes('1G'), 1024 * 1024 * 1024, 'ini shorthand: 1G');
is_same(import_ini_bytes('20971520'), 20971520, 'ini shorthand: a bare byte count');
is_same(import_ini_bytes(''), 0, 'an unset directive is NO limit, not a limit of zero');
is_same(import_ini_bytes('-1'), 0, '...and so is -1');

is_same(import_max_upload_bytes(), 10 * 1024 * 1024, 'the configured cap is import.max_upload_mb');
is_same(import_max_contacts(), 2000, 'the draft cap is import.max_contacts');
ok(import_effective_limit()['bytes'] > 0, 'the effective limit is whichever of PHP and config is smaller');

is_same(import_check_upload(null), 'Choose a .vcf file to import.', 'no file at all is a sentence, not a stack trace');
is_same(
    import_check_upload(array('error' => UPLOAD_ERR_NO_FILE)),
    'Choose a .vcf file to import.',
    'an empty file field says the same thing'
);
ok(
    str_contains((string) import_check_upload(array('error' => UPLOAD_ERR_INI_SIZE)), 'limit'),
    'an over-size upload names the limit'
);
ok(
    str_contains((string) import_check_upload(array('error' => UPLOAD_ERR_PARTIAL)), 'did not finish'),
    'A TRUNCATED UPLOAD IS REPORTED, not parsed as a shorter address book'
);
is_same(
    import_check_upload(array('error' => UPLOAD_ERR_OK, 'size' => 0, 'name' => 'x.vcf', 'tmp_name' => '/tmp/x')),
    'That file is empty.',
    'an empty file is refused before anything opens it'
);
ok(
    str_contains(
        (string) import_check_upload(array(
            'error' => UPLOAD_ERR_OK,
            'size' => 999 * 1024 * 1024,
            'name' => 'x.vcf',
            'tmp_name' => '/tmp/x',
        )),
        'Nothing was imported'
    ),
    'a file over the configured cap is refused by size before it is read'
);

is_same(import_clean_filename('  ../../etc/passwd  '), 'passwd', 'a filename from a browser is basename()d before it is stored');
is_same(import_clean_filename(''), 'contacts.vcf', 'and an empty one still has something to print');

/* ================================================================ staging ==*/

section('Staging a batch');

itest_reset();

$staged  = itest_parse('ios-3.0.vcf');
$batchId = import_stage('iPhone Contacts.vcf', $staged, ITEST_TODAY);
$batch   = import_batch($batchId);

ok($batch !== null, 'staging creates a batch row');
is_same($batch['filename'], 'iPhone Contacts.vcf', 'the file\'s own name is kept for the heading');
is_same($batch['status'], 'open', 'a new batch is open');
is_same($batch['total_parsed'], 2, 'total_parsed is what the parser reached, refusals included');
is_same($batch['promoted'], 0, 'and nothing has been promoted yet');

$drafts = import_pending_drafts($batchId);
is_same(count($drafts), 2, 'one draft per named contact');
is_same($drafts[0]['name'], 'Alex Chen', 'drafts stay in file order');
is_same($drafts[0]['name_key'], 'alex chen', 'name_key is derived at staging, never passed in');
is_same($drafts[1]['name_key'], 'dr ana maria obrien', '...by the same function the People screens use');
is_same($drafts[0]['status'], 'pending', 'and a draft starts pending');
is_same($drafts[0]['dup_person_id'], null, 'with no duplicate flag when nobody matches');

is_same($drafts[0]['birth_year'], 1991, 'the birthday survives staging');
is_same($drafts[1]['birth_year'], null, 'and a yearless one stays yearless — no sentinel');
is_same(
    $drafts[0]['address'],
    '1600 Sycamore Ave, Austin, TX, 78702, USA',
    'the address is cleaned by the same people_clean_address() the form uses'
);
ok(!str_contains((string) $drafts[0]['address'], "\n"), 'and is one line, because the column is one line');

is_same(import_draft_sub($drafts[0]), '+1 512 555 0199 · alex@example.org', 'the row subline is "phone · email"');
is_same(
    import_draft_sub(array('phone' => null, 'email' => null)),
    'No phone or email',
    'a contact with neither says so rather than rendering an empty line'
);

is_same(import_counts($batchId), array('pending' => 2, 'added' => 0, 'skipped' => 0, 'total' => 2), 'the counts add up');
is_same(import_open_batch()['id'], $batchId, 'the review screen opens on the newest open batch');

/* An implausible birth year is dropped and the month and day survive, which is
 * people_clean_birthday()'s rule and is inherited here rather than re-decided. */
itest_reset();
$future = array('contacts' => array(array(
    'name' => 'Time Traveller', 'birth_year' => 2099, 'birth_month' => 3, 'birth_day' => 2,
    'address' => null, 'phone' => null, 'email' => null,
)), 'parsed' => 1, 'skipped' => 0, 'truncated' => false);
$futureBatch = import_stage('future.vcf', $future, ITEST_TODAY);
$futureDraft = import_pending_drafts($futureBatch)[0];
is_same($futureDraft['birth_year'], null, 'a birth year in the future is dropped at staging');
is_same($futureDraft['birth_month'], 3, '...and the month it came with is kept');

/* ============================================================= duplicates ==*/

section('Duplicates are flagged and never merged');

itest_reset();

$existingId = people_add(array('name' => 'Alex Chen'), ITEST_TODAY);
$batchId    = import_stage('again.vcf', itest_parse('ios-3.0.vcf'), ITEST_TODAY);
$drafts     = import_pending_drafts($batchId);

is_same($drafts[0]['dup_person_id'], $existingId, 'a matching name_key flags the draft with that person\'s id');
is_same($drafts[1]['dup_person_id'], null, 'and a name nobody has is not flagged');

/* The flag is not a foreign key and nothing may act on it (schema.sql). The
 * proof that it cannot cascade or block: delete the person it names. */
people_delete($existingId);
is_same(
    import_draft($drafts[0]['id'])['dup_person_id'],
    $existingId,
    'deleting the person it points at neither cascades to the draft nor clears the flag'
);

/* ============================================================== promotion ==*/

section('Promoting a draft');

itest_reset();

$batchId = import_stage('promote.vcf', itest_parse('ios-3.0.vcf'), ITEST_TODAY);
$drafts  = import_pending_drafts($batchId);
$person  = import_promote($drafts[0]['id'], ITEST_TODAY);

ok($person !== null, 'promoting a draft returns the new person');
is_same($person['name'], 'Alex Chen', 'with the name the draft had');
is_same($person['name_key'], 'alex chen', 'and a name_key, because it went through people_add()');
is_same($person['birth_month'], 4, 'the birthday came with it');
is_same($person['phone'], '+1 512 555 0199', 'and the phone number');
is_same($person['notes'], null, 'a promoted draft has no notes — a vCard has nowhere to put ours');

is_same(import_draft($drafts[0]['id'])['status'], 'added', 'the draft is marked added');
is_same(import_batch($batchId)['promoted'], 1, 'and the batch counts it');
is_same(count(import_pending_drafts($batchId)), 1, 'so it leaves the queue');

is_same(import_promote($drafts[0]['id'], ITEST_TODAY), null, 'promoting it twice is refused, not done twice');
is_same((int) q('SELECT COUNT(*) AS n FROM people')->fetch()['n'], 1, '...so a double tap cannot make two people');

/* THE RULE THAT LOOKS LIKE A BUG. Promoting a flagged draft creates a SECOND
 * person on purpose: the flag was a warning to a human, the human answered it,
 * and two people really can be called Alex Chen (schema.sql). */
$second = import_stage('duplicate.vcf', itest_parse('ios-3.0.vcf'), ITEST_TODAY);
$flagged = import_pending_drafts($second)[0];
is_same($flagged['dup_person_id'], $person['id'], 'the second import flags the person the first one created');
$twin = import_promote($flagged['id'], ITEST_TODAY);
ok($twin !== null && $twin['id'] !== $person['id'], 'PROMOTING A FLAGGED DRAFT CREATES A SECOND PERSON');
is_same(count(people_same_name('alex chen')), 2, '...and both are in the address book, unmerged');

/* ========================================================= skip and undo ===*/

section('Skip, and the undo that has to be a restore');

itest_reset();

$batchId = import_stage('skip.vcf', itest_parse('ios-3.0.vcf'), ITEST_TODAY);
$drafts  = import_pending_drafts($batchId);
$victim  = $drafts[1]['id'];

ok(import_skip($victim), 'a pending draft can be skipped');
is_same(import_draft($victim)['status'], 'skipped', 'which marks it rather than deleting it');
is_same(count(import_pending_drafts($batchId)), 1, 'and takes it out of the queue');
ok(import_skip($victim), 'skipping it again is a success — the end state is what was asked for');

/* swipe.js fires onDelete immediately and treats Undo as a RESTORE, so the row
 * has to come back with the SAME id — which is only possible because the skip
 * was a status change (docs/CONTRACTS.md §5). */
ok(import_restore($victim), 'and it can be restored');
is_same(import_draft($victim)['id'], $victim, 'WITH THE SAME ID, which is what makes swipe.js\'s undo honest');
is_same(import_draft($victim)['status'], 'pending', 'and back in the queue');
ok(import_restore($victim), 'restoring something already pending is a success too');

$added = $drafts[0]['id'];
import_promote($added, ITEST_TODAY);
ok(!import_skip($added), 'a draft that has already been added CANNOT be skipped');
ok(!import_restore($added), '...nor restored — that person exists, and deleting one is a deliberate act');

/* =============================================================== finishing ==*/

section('Finishing a batch prunes its drafts and keeps the record');

itest_reset();

$batchId = import_stage('finish.vcf', itest_parse('android-2.1-qp.vcf'), ITEST_TODAY);
import_promote(import_pending_drafts($batchId)[0]['id'], ITEST_TODAY);

ok(import_finish($batchId), 'a batch can be finished');
is_same(import_counts($batchId)['total'], 0, 'THE REMAINING DRAFTS ARE PRUNED — they are contacts you decided not to keep');
$finished = import_batch($batchId);
is_same($finished['status'], 'done', 'the batch is marked done');
is_same($finished['total_parsed'], 3, 'and survives its drafts, still saying what was in the file');
is_same($finished['promoted'], 1, '...and how many people came out of it');
is_same(import_open_batch(), null, 'so the screen goes back to the upload form');
is_same((int) q('SELECT COUNT(*) AS n FROM people')->fetch()['n'], 1, 'the person who was added is untouched by the pruning');
ok(!import_finish($batchId + 9999), 'finishing a batch that is not there is refused');

itest_reset();

/* ================================================== the screen and the API ==*/

section('The import screen and its endpoints');

$screen = (string) file_get_contents($appRoot . '/public/import.php');

ok(str_contains($screen, "require_once __DIR__ . '/../lib/bootstrap.php';"), 'import.php starts with the bootstrap');
ok(str_contains($screen, 'require_login_page();'), 'and is behind the gate, like every screen');
ok(str_contains($screen, 'page_menu()'), 'and carries the hamburger, which is how it was reached');
ok(
    str_contains($screen, '<form id="logout-form" method="post" action="logout.php" class="hidden"></form>'),
    'and the POST-only logout form the menu\'s Sign out submits'
);
ok(str_contains($screen, 'enctype="multipart/form-data"'), 'the upload is a real multipart form, so it works with no JS');
ok(str_contains($screen, 'class="row-check"'), 'each draft\'s Add is a submit button, so that works with no JS too');
ok(str_contains($screen, 'class="pill is-plain"'), 'the duplicate warning is the pill docs/CONTRACTS.md §4 names for it');
ok(str_contains($screen, 'class="row-del"'), 'and the row carries the pointer-only delete swipe.js wires up');
ok(str_contains($screen, "asset('assets/import.js')"), 'the entry script goes through asset(), so it is cache-busted');
ok(str_contains($screen, '@unlink($stored);'), 'THE UPLOADED FILE IS DELETED as soon as it has been read');
ok(str_contains($screen, 'finally {'), '...in a finally, so a parse that throws still takes it with it');

ok(!str_contains($screen, '<style'), 'no <style> block — styles.css is Foundation-owned and complete');
ok(!str_contains($screen, ' style="'), 'and no inline style attribute either');

/* The one absence that is a feature. PLAN.md §6.1 and CLAUDE.md: the staged
 * queue exists to stop junk being bulk-imported and one button would defeat it. */
$screenCode = itest_code_only($screen);
ok(
    stripos($screenCode, 'add all') === false && stripos($screenCode, 'addall') === false,
    'THERE IS NO "ADD ALL" BUTTON, and there deliberately never will be'
);
ok(
    stripos(itest_js_code_only((string) file_get_contents($appRoot . '/public/assets/import.js')), 'add all') === false,
    '...not in the script either'
);

foreach (array('import-promote', 'import-skip', 'import-restore', 'import-finish') as $endpoint) {
    $path = $appRoot . '/public/api/' . $endpoint . '.php';
    if (!ok(is_file($path), $endpoint . '.php exists')) {
        continue;
    }
    $source = (string) file_get_contents($path);
    ok(str_contains($source, 'require_login_api();'), $endpoint . ' is behind the gate');
    ok(str_contains($source, 'require_same_origin();'), $endpoint . ' checks the origin');
    ok(str_contains($source, "require_method('POST');"), $endpoint . ' is POST only');
}

/* The screen's entry script imports the shared modules and forks none of them.
 * (run-tests.php's SHARED_MODULES check proves they are declared and therefore
 * cache-busted; this is the other half — that this screen uses them at all.) */
$script = (string) file_get_contents($appRoot . '/public/assets/import.js');
foreach (array('./api.js', './swipe.js', './menu.js') as $module) {
    ok(str_contains($script, "from '" . $module . "'"), 'import.js imports the shared ' . $module);
}
ok(str_contains($script, 'import-restore.php'), 'and its swipe undo restores rather than cancels');
