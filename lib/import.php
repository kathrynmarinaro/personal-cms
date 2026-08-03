<?php
/* Staging, duplicate flagging and promotion for a vCard import.
 *
 * lib/vcard.php turns a file into contacts and knows nothing else; this file is
 * everything between that and a real person: the batch, the draft rows, the
 * duplicate flag, and the one function that promotes a draft into people.
 * Nothing outside this file writes SQL against import_batches or import_drafts.
 *
 * WHY DRAFTS EXIST AT ALL. The brief asks that an import stage its contacts for
 * review rather than creating people directly, "to prevent bulk-importing junk
 * contacts" — a phone's address book is full of taxi firms, one-off delivery
 * drivers and people whose surname you no longer remember. The staging is the
 * feature, which is why there is DELIBERATELY NO "ADD ALL" ANYWHERE: one button
 * would quietly defeat the entire design (CLAUDE.md, PLAN.md §6.1).
 *
 * THREE RULES THAT LOOK LIKE OVERSIGHTS AND ARE NOT:
 *
 *   1. dup_person_id IS A FLAG AND NOTHING ELSE. It is not a foreign key
 *      (schema.sql), nothing joins through it, nothing merges on it, and
 *      promoting a flagged draft creates a SECOND person — which is correct,
 *      because two people really can be called James Smith. The only thing in
 *      the app allowed to act on it is the warning pill on the review screen.
 *   2. PROMOTION GOES THROUGH people_add() AND NOWHERE ELSE. Writing the INSERT
 *      here would be a second writer of people, and the second writer is the
 *      one that forgets name_key — which is invisible until an import six
 *      months later stops spotting duplicates.
 *   3. THE UPLOADED FILE IS NOT KEPT. import.php deletes it the moment this
 *      file has the contacts; the drafts are the artifact. Keeping the .vcf
 *      would mean keeping every phone number you decided not to import.
 *
 * PORTABILITY, the same note the other repos carry: MySQL is the production
 * target, tools/test-harness.php runs these functions against SQLite, and every
 * statement below is in the intersection — no INTERVAL, no NOW(), no DATE_ADD.
 */

declare(strict_types=1);

require_once __DIR__ . '/people.php';
require_once __DIR__ . '/vcard.php';

/* Mirrored from schema.sql. import_batches.filename is display-only — it is
 * shown once, in the review screen's heading. */
const IMPORT_FILENAME_MAX = 255;

/* The extensions a contacts export actually arrives with. Checked as well as
 * the BEGIN:VCARD sniff, not instead of it: the extension is what stops a
 * 40 MB video being read line by line before anything notices, and the sniff is
 * what stops a .vcf that is really a spreadsheet. */
const IMPORT_EXTENSIONS = array('vcf', 'vcard');

/* Every column of a draft, in schema order. One constant so a column added
 * later is added in one place. */
const IMPORT_DRAFT_COLUMNS = 'id, batch_id, name, name_key, birth_year, birth_month, birth_day,'
    . ' address, phone, email, dup_person_id, status';

/* ================================================================= limits ==*/

/** Drafts allowed out of one file. See config.example.php's import block. */
function import_max_contacts(): int
{
    $max = (int) cfg('import.max_contacts', 2000);
    return $max > 0 ? $max : 2000;
}

/** The configured upload ceiling, in bytes. */
function import_max_upload_bytes(): int
{
    $mb = (int) cfg('import.max_upload_mb', 10);
    return ($mb > 0 ? $mb : 10) * 1024 * 1024;
}

/**
 * An ini size shorthand ("10M", "512K", "2G") as bytes. 0 means no limit.
 *
 * Written out rather than reached for with a regex because the shorthand is
 * case-insensitive, the suffix is optional, and `ini_get()` returns '' for a
 * directive that is not set at all — which is not zero, it is "unlimited", and
 * treating it as zero would refuse every upload.
 */
function import_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return 0;
    }

    $number = (int) $value;
    $suffix = strtolower(substr($value, -1));

    if ($suffix === 'g') {
        return $number * 1024 * 1024 * 1024;
    }
    if ($suffix === 'm') {
        return $number * 1024 * 1024;
    }
    if ($suffix === 'k') {
        return $number * 1024;
    }
    return $number;
}

/**
 * The smallest of PHP's own upload limits, in bytes. 0 when neither is set.
 *
 * PLAN.md §6.4 asks for this by name, and the reason is worth stating: a file
 * over `upload_max_filesize` does not arrive truncated, it arrives with an
 * error code — but a request over `post_max_size` arrives with $_POST AND
 * $_FILES BOTH EMPTY and no error code anywhere. The form appears to have been
 * submitted and nothing happened. Knowing the numbers is what lets
 * import_post_was_dropped() say so out loud instead of rendering an empty
 * upload page.
 */
function import_php_upload_limit(): int
{
    $limits = array();
    foreach (array('upload_max_filesize', 'post_max_size') as $directive) {
        $bytes = import_ini_bytes((string) ini_get($directive));
        if ($bytes > 0) {
            $limits[] = $bytes;
        }
    }

    return $limits === array() ? 0 : min($limits);
}

/**
 * The limit actually in force, and whether PHP is the thing imposing it.
 *
 * @return array{bytes: int, php_capped: bool}
 */
function import_effective_limit(): array
{
    $configured = import_max_upload_bytes();
    $php        = import_php_upload_limit();

    if ($php > 0 && $php < $configured) {
        return array('bytes' => $php, 'php_capped' => true);
    }
    return array('bytes' => $configured, 'php_capped' => false);
}

/** A byte count as something to put in a sentence. */
function import_mb_label(int $bytes): string
{
    $mb = $bytes / (1024 * 1024);
    return ($mb >= 10 ? (string) (int) round($mb) : rtrim(rtrim(number_format($mb, 1), '0'), '.')) . ' MB';
}

/**
 * Did PHP throw the whole request away before this script ran?
 *
 * A POST larger than post_max_size arrives as an empty $_POST and an empty
 * $_FILES, with a Content-Length header that says otherwise and no error code
 * to read. Without this check the screen would re-render as though nobody had
 * pressed anything, which is the single most confusing thing an upload form can
 * do.
 */
function import_post_was_dropped(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return false;
    }
    if ($_POST !== array() || $_FILES !== array()) {
        return false;
    }
    return (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
}

/* ============================================================ the upload ===*/

/**
 * Check an entry from $_FILES. Null when it is fine, a sentence when it is not.
 *
 * PLAIN, READABLE MESSAGES, every one of them. This is the one place in the app
 * where somebody hands it a file, and PLAN.md §6.4 is explicit that "nothing
 * happened" is the worst possible response to the wrong one.
 */
function import_check_upload(?array $file): ?string
{
    $limit = import_effective_limit();
    $cap   = import_mb_label($limit['bytes']);

    if ($file === null || !isset($file['error'])) {
        return 'Choose a .vcf file to import.';
    }

    switch ((int) $file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return 'Choose a .vcf file to import.';
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'That file is bigger than the ' . $cap . ' limit, so nothing was imported.';
        case UPLOAD_ERR_PARTIAL:
            /* The half that arrived would parse perfectly happily as a shorter
             * address book, and the symptom would be "some of my contacts are
             * missing" months later. Refused, loudly. */
            return 'The upload did not finish, so the file was incomplete. Try again.';
        default:
            return 'The server could not accept that upload. Try again.';
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        return 'That file is empty.';
    }
    if ($size > $limit['bytes']) {
        return 'That file is ' . import_mb_label($size) . ', over the ' . $cap . ' limit. Nothing was imported.';
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        /* Not a real upload. Only reachable by something forging $_FILES, and
         * the answer to that is never to touch the path it names. */
        return 'That upload could not be read. Try again.';
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, IMPORT_EXTENSIONS, true)) {
        return 'That is not a .vcf file. Export your contacts as a vCard and try again.';
    }

    return null;
}

/**
 * The uploaded file's own name, safe to store and to print.
 *
 * basename() first, because the browser is allowed to send a path and Windows
 * sends one. Everything else is trimmed to what a filename can be, so the
 * review screen's heading cannot be typed at.
 */
function import_clean_filename(string $raw): string
{
    $name = basename(trim($raw));
    $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
    $name = trim($name);

    if ($name === '') {
        $name = 'contacts.vcf';
    }

    return mb_substr($name, 0, IMPORT_FILENAME_MAX, 'UTF-8');
}

/** Where an upload lands while it is being parsed. Outside public/, deny-all. */
function import_uploads_dir(): string
{
    return APP_ROOT . '/uploads';
}

/* ================================================================ staging ==*/

/**
 * Write a parsed file into one batch and its drafts. Returns the batch id.
 *
 * $result is exactly what vcard_parse_stream() handed back. total_parsed is its
 * `parsed` count INCLUDING the cards it refused for having no name, because
 * schema.sql wants the review screen able to say "218 of 224 contacts" rather
 * than silently showing fewer rows than the file had.
 *
 * EVERY VALUE GOES THROUGH THE SAME people_clean_*() FUNCTIONS THE FORMS USE.
 * A draft is a person-in-waiting and it has to be cleaned by the same rules, or
 * a name that was truncated at 190 characters on the Add screen arrives at 400
 * from a .vcf and MySQL's strict mode rejects the whole INSERT.
 *
 * ONE BAD CONTACT DEGRADES ITSELF. The per-row try/catch is the whole of the
 * "fail soft" rule for this file: a card with a name MySQL will not take must
 * not cost the other 223.
 */
function import_stage(string $filename, array $result, string $today): int
{
    $contacts = isset($result['contacts']) && is_array($result['contacts']) ? $result['contacts'] : array();
    $parsed   = (int) ($result['parsed'] ?? count($contacts));

    q(
        'INSERT INTO import_batches (filename, total_parsed) VALUES (?, ?)',
        array(import_clean_filename($filename), $parsed)
    );
    $batchId = (int) db()->lastInsertId();

    /* One transaction for up to 2000 inserts, because 2000 implicit commits on
     * shared hosting is the difference between an import that takes a second
     * and one that times out. A row that throws inside it is caught below and
     * skipped; MySQL leaves the transaction usable after a statement error. */
    $ownTransaction = !db()->inTransaction();
    if ($ownTransaction) {
        db()->beginTransaction();
    }

    try {
        foreach ($contacts as $contact) {
            try {
                import_stage_one($batchId, is_array($contact) ? $contact : array(), $today);
            } catch (Throwable $e) {
                error_log('import: a draft could not be staged: ' . $e->getMessage());
            }
        }
        if ($ownTransaction) {
            db()->commit();
        }
    } catch (Throwable $e) {
        if ($ownTransaction && db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }

    return $batchId;
}

/** One parsed contact as one draft row. */
function import_stage_one(int $batchId, array $contact, string $today): void
{
    $name = people_clean_name((string) ($contact['name'] ?? ''));
    if ($name === null) {
        /* The parser already refuses a nameless card and counts it, so this is
         * only reachable for a name that was nothing but whitespace. Same
         * answer: not staged, because a draft with no name is a row nobody can
         * make a decision about. */
        return;
    }

    $nameKey  = people_name_key($name);
    $birthday = people_clean_birthday(
        $contact['birth_year'] ?? null,
        $contact['birth_month'] ?? null,
        $contact['birth_day'] ?? null,
        $today
    );

    q(
        'INSERT INTO import_drafts
            (batch_id, name, name_key, birth_year, birth_month, birth_day, address, phone, email, dup_person_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        array(
            $batchId,
            $name,
            $nameKey,
            $birthday['birth_year'],
            $birthday['birth_month'],
            $birthday['birth_day'],
            people_clean_address((string) ($contact['address'] ?? '')),
            people_clean_phone((string) ($contact['phone'] ?? '')),
            people_clean_email((string) ($contact['email'] ?? '')),
            import_duplicate_of($nameKey),
        )
    );
}

/**
 * The id of somebody already in the address book with this name, or null.
 *
 * ONE INDEXED LOOKUP, which is the entire reason people.name_key is a stored
 * column rather than `LOWER(name)` in a WHERE clause (schema.sql): a function
 * on a column is non-sargable and a 400-contact import would be 400 full table
 * scans.
 *
 * The FIRST match, by id, and it is only ever rendered as a warning pill. If
 * there are three James Smiths already, which one this names does not matter —
 * the pill says "you may already have this person", the answer is a human's,
 * and promoting anyway creates another one.
 */
function import_duplicate_of(string $nameKey): ?int
{
    if ($nameKey === '') {
        return null;
    }

    $row = q('SELECT id FROM people WHERE name_key = ? ORDER BY id', array($nameKey))->fetch();
    return $row === false ? null : (int) $row['id'];
}

/* ================================================================ reading ==*/

/** Cast one import_batches row. */
function import_batch_row(array $row): array
{
    return array(
        'id'           => (int) $row['id'],
        'filename'     => (string) $row['filename'],
        'uploaded_at'  => (string) ($row['uploaded_at'] ?? ''),
        'total_parsed' => (int) $row['total_parsed'],
        'promoted'     => (int) $row['promoted'],
        'status'       => (string) $row['status'],
    );
}

/** Cast one import_drafts row. */
function import_draft_row(array $row): array
{
    $orNull = static fn ($v): ?string => $v === null || $v === '' ? null : (string) $v;

    return array(
        'id'            => (int) $row['id'],
        'batch_id'      => (int) $row['batch_id'],
        'name'          => (string) $row['name'],
        'name_key'      => (string) $row['name_key'],
        'birth_year'    => $row['birth_year'] === null ? null : (int) $row['birth_year'],
        'birth_month'   => $row['birth_month'] === null ? null : (int) $row['birth_month'],
        'birth_day'     => $row['birth_day'] === null ? null : (int) $row['birth_day'],
        'address'       => $orNull($row['address']),
        'phone'         => $orNull($row['phone']),
        'email'         => $orNull($row['email']),
        'dup_person_id' => $row['dup_person_id'] === null ? null : (int) $row['dup_person_id'],
        'status'        => (string) $row['status'],
    );
}

/** One batch, or null. */
function import_batch(int $id): ?array
{
    $row = q(
        'SELECT id, filename, uploaded_at, total_parsed, promoted, status FROM import_batches WHERE id = ?',
        array($id)
    )->fetch();

    return $row === false ? null : import_batch_row($row);
}

/**
 * The batch the review screen opens on: the newest one still open.
 *
 * There is normally at most one. Two would mean an import was abandoned
 * mid-review — the older one is not deleted (its drafts are somebody's contacts
 * and deleting them silently is not this app's habit), it is simply not the one
 * the screen offers first.
 */
function import_open_batch(): ?array
{
    $row = q("SELECT id, filename, uploaded_at, total_parsed, promoted, status
                FROM import_batches WHERE status = 'open' ORDER BY id DESC")->fetch();

    return $row === false ? null : import_batch_row($row);
}

/** One draft, or null. */
function import_draft(int $id): ?array
{
    $row = q('SELECT ' . IMPORT_DRAFT_COLUMNS . ' FROM import_drafts WHERE id = ?', array($id))->fetch();
    return $row === false ? null : import_draft_row($row);
}

/** The queue: everything in this batch still awaiting a decision, in file order. */
function import_pending_drafts(int $batchId): array
{
    $rows = q(
        'SELECT ' . IMPORT_DRAFT_COLUMNS . " FROM import_drafts
          WHERE batch_id = ? AND status = 'pending' ORDER BY id",
        array($batchId)
    )->fetchAll();

    $drafts = array();
    foreach ($rows as $row) {
        $drafts[] = import_draft_row($row);
    }
    return $drafts;
}

/**
 * How many drafts of each status this batch has.
 *
 * @return array{pending: int, added: int, skipped: int, total: int}
 */
function import_counts(int $batchId): array
{
    $counts = array('pending' => 0, 'added' => 0, 'skipped' => 0, 'total' => 0);

    foreach (q('SELECT status, COUNT(*) AS n FROM import_drafts WHERE batch_id = ? GROUP BY status', array($batchId))
        ->fetchAll() as $row) {
        $status = (string) $row['status'];
        $n      = (int) $row['n'];
        if (isset($counts[$status])) {
            $counts[$status] = $n;
        }
        $counts['total'] += $n;
    }

    return $counts;
}

/**
 * The "phone · email" line under a draft's name.
 *
 * The two things that tell you whether this is somebody you want. A draft with
 * neither says so rather than rendering an empty line — an address book export
 * is full of names attached to nothing at all, and that is exactly the row you
 * want to swipe away.
 */
function import_draft_sub(array $draft): string
{
    $parts = array();
    foreach (array('phone', 'email') as $field) {
        $value = $draft[$field] ?? null;
        if ($value !== null && $value !== '') {
            $parts[] = (string) $value;
        }
    }

    return $parts === array() ? 'No phone or email' : implode(' · ', $parts);
}

/* ================================================================ writing ==*/

/**
 * Promote one draft into a real person. Returns the new person, or null.
 *
 * THROUGH people_add(), ALWAYS — see rule 2 in the file header. It derives
 * name_key, and Phase 2B hangs the birthday-reminder reconciliation off it, so
 * a draft promoted by a second INSERT here would be a person whose birthday
 * never fires and whose duplicates are never spotted again.
 *
 * A FLAGGED DRAFT IS PROMOTED LIKE ANY OTHER AND CREATES A SECOND PERSON. That
 * is the contract for dup_person_id (schema.sql, docs/CONTRACTS.md §2): the
 * flag was a warning to a human, the human answered it by tapping Add, and
 * merging is out of scope for v1 and is not being invented here.
 *
 * Null when the draft is gone or is not pending — a stale tab, or the same row
 * tapped twice on a slow connection. Idempotent by refusal rather than by
 * creating a second copy of somebody.
 */
function import_promote(int $draftId, string $today): ?array
{
    $draft = import_draft($draftId);
    if ($draft === null || $draft['status'] !== 'pending') {
        return null;
    }

    $personId = people_add(array(
        'name'        => $draft['name'],
        'birth_year'  => $draft['birth_year'],
        'birth_month' => $draft['birth_month'],
        'birth_day'   => $draft['birth_day'],
        'address'     => $draft['address'],
        'phone'       => $draft['phone'],
        'email'       => $draft['email'],
        'notes'       => null,
    ), $today);

    q("UPDATE import_drafts SET status = 'added' WHERE id = ?", array($draftId));
    q('UPDATE import_batches SET promoted = promoted + 1 WHERE id = ?', array($draft['batch_id']));

    return people_get($personId);
}

/**
 * Skip a draft: it stays in the table, marked, and leaves the queue.
 *
 * NOT A DELETE, which is what makes the five-second undo honest. swipe.js fires
 * onDelete the moment the gesture completes and treats Undo as a RESTORE
 * (docs/CONTRACTS.md §5) — so the row has to be able to come back with the same
 * id, and a deleted row cannot. The whole batch is pruned by import_finish()
 * anyway, so nothing accumulates.
 *
 * Skipping something already skipped is a success: the end state is the one
 * that was asked for. Skipping something already ADDED is not — that person
 * exists now, and deleting a person is a deliberate act on their profile with a
 * confirmation (CLAUDE.md), never a side effect of a swipe on another screen.
 */
function import_skip(int $draftId): bool
{
    $draft = import_draft($draftId);
    if ($draft === null || $draft['status'] === 'added') {
        return false;
    }
    if ($draft['status'] === 'skipped') {
        return true;
    }

    q("UPDATE import_drafts SET status = 'skipped' WHERE id = ?", array($draftId));
    return true;
}

/** Undo a skip. The other half of swipe.js's restore-not-cancel contract. */
function import_restore(int $draftId): bool
{
    $draft = import_draft($draftId);
    if ($draft === null || $draft['status'] === 'added') {
        return false;
    }
    if ($draft['status'] === 'pending') {
        return true;
    }

    q("UPDATE import_drafts SET status = 'pending' WHERE id = ?", array($draftId));
    return true;
}

/**
 * Finish a batch: mark it done and prune its drafts.
 *
 * THE PRUNING IS THE POINT, and it is why "Done importing" says out loud that
 * the rest will be discarded. A draft is a copy of a phone number out of
 * somebody's contacts export, sitting in the database, that has already been
 * looked at and not wanted. Keeping it is keeping exactly what deleting the
 * uploaded .vcf was for.
 *
 * The batch row survives its drafts (schema.sql) so the import is still in the
 * record afterwards: what the file was called, how much was in it, how many
 * people came out of it.
 */
function import_finish(int $batchId): bool
{
    $batch = import_batch($batchId);
    if ($batch === null) {
        return false;
    }

    q('DELETE FROM import_drafts WHERE batch_id = ?', array($batchId));
    q("UPDATE import_batches SET status = 'done' WHERE id = ?", array($batchId));

    return true;
}
