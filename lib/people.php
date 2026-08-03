<?php
/* All person and relationship-tag data access. The spine of the app.
 *
 * Nothing outside this file writes SQL against people, relationship_tags or
 * person_tag_map — the screens and the endpoints call these functions and
 * nothing else. Every statement is prepared, via q().
 *
 * THREE THINGS ABOUT THIS FILE THAT LOOK ARBITRARY AND ARE NOT:
 *
 *   1. name_key IS DERIVED HERE, ALWAYS, AND NEVER PASSED IN. It is the
 *      normalized copy of the name that the import's duplicate check reads
 *      (schema.sql), and a row written with a stale or absent key is a
 *      duplicate that will never be spotted — silently, months later, on a
 *      screen that has no way to report it. Making it impossible for a caller
 *      to forget costs one line in each of the two writers.
 *
 *   2. people_add() AND people_save() TAKE $today AND DO NOT USE IT YET.
 *      docs/CONTRACTS.md §2 and schema.sql both name people_save() as one of
 *      the two places a birthday reminder is reconciled; the other is the
 *      cron. Phase 2B (Reminders) owns lib/reminders.php and will add that
 *      call here. The parameter is in the signature NOW so that landing it is
 *      one line in one file rather than a signature change rippling through
 *      add.php, person.php and two endpoints owned by somebody else. See the
 *      marked hook in each function.
 *
 *   3. THE UNTAGGED GROUP IS NOT A TAG. It has no row, no id, and cannot be
 *      assigned. It is the bucket people_grouped() puts everyone who has no
 *      tag link into, and it is appended last structurally rather than sorted
 *      last by a number — see PEOPLE_UNTAGGED_SORT.
 *
 * Nothing here escapes for output; the templates do that with h(). The one
 * exception is people_form_fields() at the foot of the file, which is markup
 * and says why it is here.
 *
 * PORTABILITY NOTE, the same one the sibling app's repos carry. MySQL is the
 * production target, but tools/test-harness.php runs these functions against
 * SQLite because the build environment has no MySQL. Every statement below is
 * in the intersection of the two: no INTERVAL arithmetic, no DATE_ADD, no
 * NOW(), and the one INSERT IGNORE is guarded by an explicit existence check
 * because the two databases disagree about whether IGNORE also swallows a
 * foreign-key violation. A statement outside that intersection cannot be
 * tested at all. */

declare(strict_types=1);

/* Column widths, mirrored from schema.sql so the app truncates rather than
 * letting MySQL's strict mode reject a whole INSERT over one long paste. notes
 * is TEXT and has no ceiling worth mirroring — see people_clean_notes(). */
const PEOPLE_NAME_MAX    = 190;   // people.name and people.name_key
const PEOPLE_ADDRESS_MAX = 500;   // people.address
const PEOPLE_PHONE_MAX   = 64;    // people.phone
const PEOPLE_EMAIL_MAX   = 190;   // people.email
const TAG_NAME_MAX       = 64;    // relationship_tags.name

/* The People list's last group: everybody with no tag link at all.
 *
 * It sorts last for the same reason the sibling app's Uncategorized does — it
 * is the "I haven't decided yet" pile, and putting it above Family would make
 * the screen open on the least useful thing on it. The number is documentary:
 * the ordering is structural (people_grouped() appends this group after the
 * loop over the real tags), so a run of custom tags numbered past 99 cannot
 * quietly float the untagged pile back to the top. */
const PEOPLE_UNTAGGED      = 'Untagged';
const PEOPLE_UNTAGGED_SORT = 99;

/* The columns every read selects, in schema order. One constant so a column
 * added later is added in one place rather than in six SELECTs, five of which
 * get it. */
const PEOPLE_COLUMNS = 'id, name, name_key, birth_year, birth_month, birth_day,'
    . ' address, phone, email, notes, last_contact_date, created_at';

/* ================================================================ cleaning ==*/

/**
 * Trim and cap a typed name, or null if there is nothing left.
 *
 * TRIMMED AND NOTHING ELSE. No case fixing, no punctuation stripping, no
 * "helpful" title-casing — schema.sql promises people.name is stored exactly
 * as typed, and "Mum", "Dr. Okafor" and "Alex (from climbing)" are all names
 * somebody chose on purpose. The normalizing happens in people_name_key(),
 * which is a separate column precisely so the displayed name never has to be
 * touched.
 */
function people_clean_name(string $raw): ?string
{
    $name = trim($raw);
    if ($name === '') {
        return null;
    }
    return mb_substr($name, 0, PEOPLE_NAME_MAX, 'UTF-8');
}

/* Accent folding for people_name_key(). An explicit table rather than
 * iconv('UTF-8', 'ASCII//TRANSLIT'), whose output depends on the server's
 * locale and on which iconv implementation is installed: on glibc "é" folds to
 * "e", on musl it folds to "?" — so the same name imported on two hosts would
 * produce two different keys and the duplicate check would stop finding
 * anything. A table is boring, deterministic and testable.
 *
 * Covers Latin-1 Supplement and the common Latin Extended-A letters, which is
 * what actually turns up in a phone's contacts. Anything not listed survives
 * unchanged and simply compares as itself — a Greek or Cyrillic name still
 * gets a stable key, it just isn't folded onto a Latin one, which is correct. */
const PEOPLE_FOLD = array(
    'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
    'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
    'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ĉ' => 'c',
    'ď' => 'd', 'đ' => 'd', 'ð' => 'd',
    'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ė' => 'e',
    'ę' => 'e', 'ě' => 'e',
    'ğ' => 'g', 'ģ' => 'g',
    'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'į' => 'i',
    'ı' => 'i',
    'ķ' => 'k', 'ĺ' => 'l', 'ľ' => 'l', 'ł' => 'l',
    'ñ' => 'n', 'ń' => 'n', 'ň' => 'n', 'ņ' => 'n',
    'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o',
    'ō' => 'o', 'ő' => 'o',
    'ŕ' => 'r', 'ř' => 'r',
    'ś' => 's', 'š' => 's', 'ş' => 's', 'ș' => 's',
    'ť' => 't', 'ţ' => 't', 'ț' => 't', 'þ' => 'th',
    'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ů' => 'u',
    'ű' => 'u', 'ų' => 'u',
    'ý' => 'y', 'ÿ' => 'y',
    'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
    'æ' => 'ae', 'œ' => 'oe', 'ß' => 'ss',
);

/**
 * The normalized form of a name, for the duplicate check and for search.
 *
 *   "Dr. Ana-Maria O'Brien "  ->  "dr ana maria obrien"
 *
 * WHAT THIS IS FOR, AND WHAT IT IS NOT FOR. schema.sql: name_key exists so the
 * import's duplicate check is one indexed lookup instead of a `LOWER(name)`
 * full scan. It FLAGS a possible duplicate; it never refuses one, and nothing
 * anywhere may treat two matching keys as the same person. Two people really
 * can be called James Smith.
 *
 * Apostrophes are REMOVED rather than turned into a space, so "O'Brien" and
 * "OBrien" — the same surname typed by two different phones — land on the same
 * key. Every other punctuation mark becomes a space, so "Ana-Maria" and "Ana
 * Maria" also meet. Collapsing runs of whitespace afterwards is what makes the
 * two rules compose instead of leaving a double space behind.
 *
 * It is also the People list's search haystack, which is why it is worth
 * folding accents at all: typing "jose" finds "José" without anyone having to
 * find the accent key on a phone.
 */
function people_name_key(string $raw): string
{
    $key = mb_strtolower(trim($raw), 'UTF-8');
    $key = strtr($key, PEOPLE_FOLD);

    /* Both the ASCII apostrophe and the curly one iOS substitutes as you type. */
    $key = str_replace(array("'", '’', '‘', '`', '´'), '', $key);

    /* Anything that is not a letter or a digit becomes a separator. The /u
     * modifier can fail outright on invalid UTF-8 — a truncated multi-byte
     * sequence out of a bad import — and preg_replace returns null when it
     * does, so both calls fall back to an empty key rather than to a TypeError
     * halfway through saving somebody. */
    $key = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $key) ?? '';
    $key = trim((string) preg_replace('/\s+/u', ' ', $key));

    return mb_substr($key, 0, PEOPLE_NAME_MAX, 'UTF-8');
}

/**
 * Trim and cap a phone number, or null.
 *
 * Stored as typed or as exported, formatting and all — schema.sql is explicit
 * that this is not normalized to E.164. The app never dials it, it renders it
 * into a tel: link, and a phone handles "+44 20 7946 0958" perfectly well.
 * Normalizing would throw away the author's own spacing for no gain.
 */
function people_clean_phone(?string $raw): ?string
{
    $phone = trim((string) $raw);
    return $phone === '' ? null : mb_substr($phone, 0, PEOPLE_PHONE_MAX, 'UTF-8');
}

/** Trim and cap an email, or null. Stored as typed; see people_mailto(). */
function people_clean_email(?string $raw): ?string
{
    $email = trim((string) $raw);
    return $email === '' ? null : mb_substr($email, 0, PEOPLE_EMAIL_MAX, 'UTF-8');
}

/**
 * Trim and cap an address, or null.
 *
 * Newlines are collapsed to ", " rather than kept: the column is one readable
 * line (schema.sql — an ADR out of a vCard is seven semicolon-separated parts
 * joined together), and a textarea is offered only because typing a street
 * address into a single-line input on a phone is miserable.
 */
function people_clean_address(?string $raw): ?string
{
    $address = trim((string) $raw);
    if ($address === '') {
        return null;
    }
    $address = (string) preg_replace('/\s*\R+\s*/u', ', ', $address);
    return mb_substr($address, 0, PEOPLE_ADDRESS_MAX, 'UTF-8');
}

/**
 * Trim notes, or null.
 *
 * NOT capped. This is the one genuinely open-ended field on a person —
 * "allergic to shellfish", "ask about the move", years of accumulated context —
 * it is TEXT in the schema, nothing about a long note breaks a row, and
 * truncating one would cut a thought in half. Newlines are kept, unlike the
 * address, because paragraphs are the point.
 */
function people_clean_notes(?string $raw): ?string
{
    $notes = trim((string) $raw);
    return $notes === '' ? null : $notes;
}

/**
 * Sort three loose birthday values into the three columns, or into three NULLs.
 *
 * THE THREE COLUMNS ARE NOT INDEPENDENT AND THIS IS WHERE THAT IS ENFORCED.
 * schema.sql: birth_month NULL means no birthday recorded; birth_year NULL
 * beside a month and a day means the birthday is known and the year is not.
 * There is no third state — a year with no month is not a birthday, it is a
 * fragment, and storing it would make `WHERE birth_month IS NOT NULL` (the
 * cron's reconciliation pass) silently disagree with what the profile shows.
 *
 * So: month and day together, or nothing at all.
 *
 * AN IMPLAUSIBLE YEAR IS DROPPED AND THE MONTH AND DAY SURVIVE. The year is a
 * display nicety — it is what lets the profile say "turning 34" — while the
 * month and day are what the reminder actually runs on. Refusing the whole
 * birthday over a mistyped year would lose the half that matters.
 *
 * The DAY IS NOT CLAMPED here. A vCard can carry `BDAY:--0631` and lib/dates.php
 * clamps generally, on read, to the last day of whatever month; clamping on
 * write as well would quietly rewrite what the export said and leave nothing to
 * notice later. Only the 1-31 range is enforced, because outside it the value
 * is not a day at all.
 *
 * @param mixed $year  as typed; '' and null both mean "not given"
 * @return array{birth_year: int|null, birth_month: int|null, birth_day: int|null}
 */
function people_clean_birthday($year, $month, $day, string $today): array
{
    $none = array('birth_year' => null, 'birth_month' => null, 'birth_day' => null);

    $m = is_numeric($month) ? (int) $month : 0;
    $d = is_numeric($day) ? (int) $day : 0;
    if ($m < 1 || $m > 12 || $d < 1 || $d > 31) {
        return $none;
    }

    $y = is_numeric($year) ? (int) $year : 0;

    /* The upper bound comes from $today rather than from date('Y'), for the
     * reason lib/dates.php gives at length: crm_today() is the only thing in
     * the app allowed to ask what day it is. A birth year in the future is a
     * typo every time. 1900 is the floor because a two-digit slip ("91" for
     * "1991") has to land outside it to be caught. */
    $thisYear = (int) substr($today, 0, 4);
    if ($y < 1900 || $y > $thisYear) {
        $y = 0;
    }

    return array(
        'birth_year'  => $y === 0 ? null : $y,
        'birth_month' => $m,
        'birth_day'   => $d,
    );
}

/**
 * Trim and cap a relationship-tag name, or null.
 *
 * Whitespace is collapsed, unlike a person's name: a tag is a heading on the
 * People list and "Close  Friend" would render as a second group beside
 * "Close Friend" for the rest of time.
 */
function people_clean_tag_name(string $raw): ?string
{
    $name = trim((string) preg_replace('/\s+/u', ' ', trim($raw)));
    if ($name === '') {
        return null;
    }
    return mb_substr($name, 0, TAG_NAME_MAX, 'UTF-8');
}

/* ================================================================= reading ==*/

/** Cast one people row out of the database into the shape the app uses. */
function people_row(array $row): array
{
    $orNull = static fn ($v): ?string => $v === null || $v === '' ? null : (string) $v;

    return array(
        'id'                => (int) $row['id'],
        'name'              => (string) $row['name'],
        'name_key'          => (string) $row['name_key'],
        'birth_year'        => $row['birth_year'] === null ? null : (int) $row['birth_year'],
        'birth_month'       => $row['birth_month'] === null ? null : (int) $row['birth_month'],
        'birth_day'         => $row['birth_day'] === null ? null : (int) $row['birth_day'],
        'address'           => $orNull($row['address']),
        'phone'             => $orNull($row['phone']),
        'email'             => $orNull($row['email']),
        'notes'             => $orNull($row['notes']),
        'last_contact_date' => $orNull($row['last_contact_date']),
        'created_at'        => (string) ($row['created_at'] ?? ''),
    );
}

/** One person, or null. */
function people_get(int $id): ?array
{
    $row = q('SELECT ' . PEOPLE_COLUMNS . ' FROM people WHERE id = ?', array($id))->fetch();
    return $row === false ? null : people_row($row);
}

/**
 * Everyone, name order, optionally narrowed by a search term.
 *
 * THE SEARCH RUNS AGAINST name_key, NOT name. That is the whole reason the
 * column is stored rather than derived: `WHERE LOWER(name) LIKE ?` is a
 * function on a column — non-sargable, unindexable, a full scan every time —
 * and it would also fail to match "jose" against "José", because lowercasing
 * is not folding. Typing an accent on a phone keyboard is not something anyone
 * should have to do to find their own friend.
 *
 * Email and phone are matched raw, because nobody searches for a half-remembered
 * email with the accents taken out of it.
 *
 * The wildcards in the term are escaped with '!' rather than with a backslash.
 * Both databases accept an explicit ESCAPE character; a backslash is the one
 * character whose own quoting differs between them (MySQL treats it as an
 * escape inside a string literal, SQLite does not), so choosing anything else
 * keeps the statement identical on both.
 */
function people_list(?string $search = null): array
{
    $term = $search === null ? '' : trim($search);

    if ($term === '') {
        $rows = q('SELECT ' . PEOPLE_COLUMNS . ' FROM people ORDER BY name, id')->fetchAll();
    } else {
        $rawLike = '%' . people_like_escape($term) . '%';
        $clauses = array('email LIKE ? ESCAPE \'!\'', 'phone LIKE ? ESCAPE \'!\'');
        $params  = array($rawLike, $rawLike);

        /* The name clause is only added when the term still has something in it
         * after normalizing. A search for "%" or for "!" folds away to nothing,
         * and '%' . '' . '%' is the pattern that matches every row in the
         * table — a search box that answers punctuation with the whole address
         * book. The escaping below is what keeps the raw email and phone
         * clauses from doing the same. */
        $key = people_name_key($term);
        if ($key !== '') {
            array_unshift($clauses, 'name_key LIKE ? ESCAPE \'!\'');
            array_unshift($params, '%' . people_like_escape($key) . '%');
        }

        $rows = q(
            'SELECT ' . PEOPLE_COLUMNS . ' FROM people WHERE ' . implode(' OR ', $clauses) . ' ORDER BY name, id',
            $params
        )->fetchAll();
    }

    $people = array();
    foreach ($rows as $row) {
        $people[] = people_row($row);
    }
    return $people;
}

/** Neutralise LIKE's own wildcards in a user-typed term. See people_list(). */
function people_like_escape(string $term): string
{
    return str_replace(array('!', '%', '_'), array('!!', '!%', '!_'), $term);
}

/**
 * Other people whose normalized name matches this one's.
 *
 * A FLAG AND ONLY A FLAG. It is what the Add screen uses to say "you already
 * have somebody called Alex Chen" once, before adding a second one anyway, and
 * what Phase 2C's import will read to decide whether to render its duplicate
 * pill. Nothing may merge on it, refuse on it, or hide a row because of it —
 * two people really can share a name, and schema.sql leaves name_key
 * deliberately non-unique so the database cannot be talked into disagreeing.
 *
 * @param int|null $excludeId the person being edited, who is not their own duplicate
 */
function people_same_name(string $nameKey, ?int $excludeId = null): array
{
    if ($nameKey === '') {
        return array();
    }

    $rows = $excludeId === null
        ? q('SELECT ' . PEOPLE_COLUMNS . ' FROM people WHERE name_key = ? ORDER BY id', array($nameKey))->fetchAll()
        : q(
            'SELECT ' . PEOPLE_COLUMNS . ' FROM people WHERE name_key = ? AND id <> ? ORDER BY id',
            array($nameKey, $excludeId)
        )->fetchAll();

    $people = array();
    foreach ($rows as $row) {
        $people[] = people_row($row);
    }
    return $people;
}

/** Cast one relationship_tags row. */
function tag_row(array $row): array
{
    return array(
        'id'         => (int) $row['id'],
        'name'       => (string) $row['name'],
        'sort_order' => (int) $row['sort_order'],
        'is_preset'  => (int) $row['is_preset'] === 1,
    );
}

/**
 * Every relationship tag, in the order the People list groups by.
 *
 * sort_order first, name second. The seeded five are ordered by closeness
 * rather than alphabetically (schema.sql) and the tiebreak matters because
 * sort_order has no unique constraint — two custom tags added in the same
 * second would otherwise come out in whatever order the storage engine felt
 * like, and a heading order that changes between page loads reads as a bug.
 */
function tags_all(): array
{
    $rows = q('SELECT id, name, sort_order, is_preset FROM relationship_tags ORDER BY sort_order, name, id')->fetchAll();

    $tags = array();
    foreach ($rows as $row) {
        $tags[] = tag_row($row);
    }
    return $tags;
}

/** One tag, or null. */
function tag_get(int $id): ?array
{
    $row = q('SELECT id, name, sort_order, is_preset FROM relationship_tags WHERE id = ?', array($id))->fetch();
    return $row === false ? null : tag_row($row);
}

/**
 * A tag by name, case-insensitively, or null.
 *
 * The comparison happens in PHP rather than in the WHERE clause, and that is
 * deliberate. relationship_tags.name is UNIQUE under utf8mb4_unicode_ci, which
 * is case-insensitive; SQLite's default collation is not. A `WHERE name = ?`
 * would therefore find "family" for "Family" in production and not in the test
 * run — the exact shape of bug the harness header warns about, where a green
 * test says nothing about the database the app actually runs on. The table is
 * single figures of rows; reading all of them costs nothing and behaves the
 * same everywhere.
 */
function tag_find_by_name(string $name): ?array
{
    $needle = mb_strtolower(trim($name), 'UTF-8');
    if ($needle === '') {
        return null;
    }

    foreach (tags_all() as $tag) {
        if (mb_strtolower($tag['name'], 'UTF-8') === $needle) {
            return $tag;
        }
    }
    return null;
}

/** The tags one person holds, in group order. */
function people_tags(int $personId): array
{
    $rows = q(
        'SELECT t.id, t.name, t.sort_order, t.is_preset
           FROM person_tag_map m
           JOIN relationship_tags t ON t.id = m.tag_id
          WHERE m.person_id = ?
          ORDER BY t.sort_order, t.name, t.id',
        array($personId)
    )->fetchAll();

    $tags = array();
    foreach ($rows as $row) {
        $tags[] = tag_row($row);
    }
    return $tags;
}

/**
 * The whole People list, grouped by relationship tag.
 *
 * A PERSON WITH TWO TAGS APPEARS TWICE, once under each — that is the entire
 * reason this is a many-to-many and not a category column (schema.sql). Being
 * both a Colleague and a Close Friend is a normal thing to be, and a list that
 * made you pick one would be answering a question nobody asked.
 *
 * A person with no tags at all lands in the Untagged group, which is appended
 * after the loop and therefore always last regardless of how many custom tags
 * have been added. See PEOPLE_UNTAGGED_SORT.
 *
 * EMPTY GROUPS ARE OMITTED, unlike the sibling app's category groups. There,
 * every category is rendered and the empty ones hidden, because the script
 * moves rows between groups when the pill is used and a group that only exists
 * once it has an item is a group the script would have to invent mid-gesture.
 * Nothing moves rows between groups on this screen — the search filter only
 * hides them — so an empty heading here would be a heading for nobody.
 *
 * @return array<int, array{id: int|null, name: string, sort_order: int, is_preset: bool, people: array}>
 */
function people_grouped(?string $search = null): array
{
    $people = people_list($search);
    if ($people === array()) {
        return array();
    }

    /* One pass over the link table rather than a query per person: the People
     * list is the screen opened most often after the dashboard, and N+1 there
     * is N+1 on every load. */
    $tagIdsByPerson = array();
    foreach (q('SELECT person_id, tag_id FROM person_tag_map')->fetchAll() as $link) {
        $tagIdsByPerson[(int) $link['person_id']][(int) $link['tag_id']] = true;
    }

    $groups   = array();
    $untagged = array();

    foreach (tags_all() as $tag) {
        $members = array();
        foreach ($people as $person) {
            if (isset($tagIdsByPerson[$person['id']][$tag['id']])) {
                $members[] = $person;
            }
        }
        if ($members !== array()) {
            $groups[] = array(
                'id'         => $tag['id'],
                'name'       => $tag['name'],
                'sort_order' => $tag['sort_order'],
                'is_preset'  => $tag['is_preset'],
                'people'     => $members,
            );
        }
    }

    foreach ($people as $person) {
        if (!isset($tagIdsByPerson[$person['id']])) {
            $untagged[] = $person;
        }
    }

    if ($untagged !== array()) {
        $groups[] = array(
            'id'         => null,   // NOT a tag. There is no row to assign.
            'name'       => PEOPLE_UNTAGGED,
            'sort_order' => PEOPLE_UNTAGGED_SORT,
            'is_preset'  => false,
            'people'     => $untagged,
        );
    }

    return $groups;
}

/* ================================================================ rendering ==*/

/**
 * The "last contacted" line under a name.
 *
 * NULL IS A REAL ANSWER AND IS RENDERED AS A WORD. schema.sql and
 * docs/CONTRACTS.md both say it: last_contact_date NULL means never contacted,
 * it is the normal state of a freshly imported contact, and it must not be
 * coalesced into created_at. days_since() returns null rather than 0 for
 * exactly this, because 0 reads as "contacted today" — the opposite of the
 * truth about somebody you have never spoken to.
 *
 * A future date is shown as a date rather than as a negative number of days.
 * Nothing in the app should be writing one, so it degrades to something
 * readable instead of to something that looks like a bug in the arithmetic.
 */
function people_contact_label(?string $lastContact, string $today): string
{
    if ($lastContact === null || $lastContact === '') {
        return 'Never contacted';
    }

    $days = days_since($lastContact, $today);
    if ($days === null) {
        /* Stored but unreadable — a hand-edited row, a bad restore. Degrade
         * this one line rather than the screen it is on. */
        return 'Last contact date unreadable';
    }

    if ($days < 0) {
        return 'Last contacted ' . fmt_date($lastContact);
    }
    if ($days === 0) {
        return 'Contacted today';
    }
    if ($days === 1) {
        return 'Contacted yesterday';
    }
    return 'Last contacted ' . $days . ' days ago';
}

/**
 * A birthday, the way the profile says it: "April 15" or "April 15 (turning 34)".
 *
 * THE TWO STATES MUST NOT COLLAPSE. birth_month NULL is no birthday and returns
 * an empty string; a month and day with no year is a real, common birthday out
 * of a phone's vCard export (`BDAY:--0415`) and gets the plain form. Only a
 * stored year earns the age.
 *
 * The month and day are formatted by asking next_birthday() for their next
 * occurrence on or after the 1st of January 2000, which is a literal and not a
 * clock. Two things fall out of that year being a leap year: February 29 stays
 * February 29 here, so a leap-day birthday READS as itself even though its
 * reminder fires on the 28th in an ordinary year; and an impossible day out of
 * a bad import (`--0631`) is clamped by the same tested function the rest of
 * the app uses rather than by a second copy of the rule written here.
 *
 * "Turning" rather than "aged": the age comes from the NEXT occurrence of the
 * birthday, so it is the number that will be true on the day, which is the one
 * you need when you are writing in a card.
 */
function people_birthday_label(array $person, string $today): string
{
    $month = $person['birth_month'];
    $day   = $person['birth_day'];
    if ($month === null || $day === null) {
        return '';
    }

    $label = fmt_date(next_birthday((int) $month, (int) $day, '2000-01-01'), 'F j');
    if ($label === '') {
        return '';
    }

    $year = $person['birth_year'];
    if ($year === null) {
        return $label;
    }

    $turning = (int) substr(next_birthday((int) $month, (int) $day, $today), 0, 4) - (int) $year;
    if ($turning < 0 || $turning > 130) {
        /* An age outside living memory means a bad year got in some other way.
         * Show the date without it rather than showing "turning -7". */
        return $label;
    }

    return $label . ' (turning ' . $turning . ')';
}

/**
 * A tel: href for a stored phone number, or null when it isn't one.
 *
 * The stored value keeps the author's own spacing (schema.sql); a tel: URI
 * cannot. Everything that is not a digit, a leading plus, or one of the
 * separators a dialler understands is dropped, and a value left with fewer
 * than three digits gets no link at all — the number is still shown, it just
 * isn't offered as something to tap.
 */
function people_tel(?string $phone): ?string
{
    if ($phone === null) {
        return null;
    }

    $dial = (string) preg_replace('/[^0-9+,;*#]/', '', $phone);
    $dial = '+' === substr($dial, 0, 1) ? '+' . str_replace('+', '', $dial) : str_replace('+', '', $dial);

    return strlen((string) preg_replace('/[^0-9]/', '', $dial)) >= 3 ? 'tel:' . $dial : null;
}

/**
 * A mailto: href for a stored email, or null when it isn't one.
 *
 * The column stores what was typed, so this is where the shape is checked
 * rather than at save time — refusing to save an address because it looked odd
 * would lose the only contact detail somebody had. A value with no "@", or with
 * whitespace or a newline in it, is shown as text and not linked.
 */
function people_mailto(?string $email): ?string
{
    if ($email === null) {
        return null;
    }
    if (preg_match('/^[^\s@]+@[^\s@]+$/', $email) !== 1) {
        return null;
    }
    return 'mailto:' . $email;
}

/* ================================================================= writing ==*/

/**
 * Add one person. Returns the new id.
 *
 * name_key is derived here and cannot be passed in — see the file header.
 *
 * @param array  $fields cleaned values; only 'name' is required
 * @param string $today  from crm_today(), for the Phase 2B hook below
 */
function people_add(array $fields, string $today): int
{
    $name = (string) $fields['name'];

    q(
        'INSERT INTO people (name, name_key, birth_year, birth_month, birth_day, address, phone, email, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        array(
            $name,
            people_name_key($name),
            $fields['birth_year'] ?? null,
            $fields['birth_month'] ?? null,
            $fields['birth_day'] ?? null,
            $fields['address'] ?? null,
            $fields['phone'] ?? null,
            $fields['email'] ?? null,
            $fields['notes'] ?? null,
        )
    );

    $id = (int) db()->lastInsertId();

    /* ---- HOOK, PHASE 2B (Reminders) ------------------------------------
     * A new person with a birthday needs a type='birthday' reminder row.
     * schema.sql explains why those are materialized rather than computed,
     * and names this function as one of the two places they are reconciled.
     * R adds a require_once for lib/reminders.php at the top of this file
     * and one call here:
     *
     *     reminders_reconcile_birthday($id, $today);
     *
     * $today is already a parameter so that landing it needs no signature
     * change and no edit to any caller. Nothing else in this file is R's.
     * -------------------------------------------------------------------- */

    return $id;
}

/**
 * Overwrite a person's identity fields. Returns false if there is no such row.
 *
 * WHOLE-ROW, INCLUDING THE NULLS. Every identity field is set to exactly what
 * is passed, because this is the one control that can clear one — an address
 * that moved, an email that bounced. It is the caller's job to pass the fields
 * it did not mean to change, which is why the edit form renders all of them and
 * the endpoint reads all of them.
 *
 * It deliberately does not touch last_contact_date. That column belongs to
 * Phase 2A's logging path (docs/CONTRACTS.md §2): it is the cadence clock, and
 * an identity edit is not a conversation.
 */
function people_save(int $id, array $fields, string $today): bool
{
    if (people_get($id) === null) {
        return false;
    }

    $name = (string) $fields['name'];

    q(
        'UPDATE people
            SET name = ?, name_key = ?, birth_year = ?, birth_month = ?, birth_day = ?,
                address = ?, phone = ?, email = ?, notes = ?
          WHERE id = ?',
        array(
            $name,
            people_name_key($name),
            $fields['birth_year'] ?? null,
            $fields['birth_month'] ?? null,
            $fields['birth_day'] ?? null,
            $fields['address'] ?? null,
            $fields['phone'] ?? null,
            $fields['email'] ?? null,
            $fields['notes'] ?? null,
            $id,
        )
    );

    /* ---- HOOK, PHASE 2B (Reminders) ------------------------------------
     * The birthday may have been added, changed or cleared. R adds:
     *
     *     reminders_reconcile_birthday($id, $today);
     *
     * and it must handle all three: create, recompute, delete. See the
     * people_add() hook above and schema.sql's note on the reminders table.
     * -------------------------------------------------------------------- */

    return true;
}

/**
 * Delete a person, and hand back who they were.
 *
 * NOT A SWIPE, ANYWHERE, EVER. docs/PLAN.md §10 and CLAUDE.md: swipe-to-delete
 * with a five-second undo is right for a grocery item you can retype in two
 * seconds. A person carries notes, gift ideas and years of contact history, and
 * five seconds is not a window in which you notice. This is reached from a
 * confirmation screen on the profile and from nowhere else.
 *
 * There is correspondingly NO restore. Everything cascades — tag links, gift
 * ideas, the contact log, the reminders and, two levels down, the send ledger
 * for those reminders (schema.sql) — and re-inserting the person would bring
 * back a shell with none of it. The returned row is for the caller's message
 * ("Alex Chen was deleted"), not for an undo that cannot honestly be offered.
 *
 * @return array|null the person as they were, or null if they were already gone
 */
function people_delete(int $id): ?array
{
    $person = people_get($id);
    if ($person === null) {
        return null;
    }

    q('DELETE FROM people WHERE id = ?', array($id));
    return $person;
}

/**
 * Give a person a tag. Idempotent; false when either side does not exist.
 *
 * INSERT IGNORE makes assigning a tag somebody already has a no-op rather than
 * a primary-key error, which matters because the picker is a toggle and a
 * double tap on a phone is one gesture. The existence checks above it are NOT
 * redundant with the foreign keys: MySQL's INSERT IGNORE downgrades a foreign
 * key violation to a warning and inserts nothing, while SQLite's INSERT OR
 * IGNORE still raises it — so without these two lines the same call would
 * return success in production and throw in the test run.
 */
function people_assign_tag(int $personId, int $tagId): bool
{
    if (people_get($personId) === null || tag_get($tagId) === null) {
        return false;
    }

    q('INSERT IGNORE INTO person_tag_map (person_id, tag_id) VALUES (?, ?)', array($personId, $tagId));
    return true;
}

/** Take a tag off a person. True whether or not they had it — the end state is what was asked for. */
function people_unassign_tag(int $personId, int $tagId): bool
{
    q('DELETE FROM person_tag_map WHERE person_id = ? AND tag_id = ?', array($personId, $tagId));
    return true;
}

/** Where a newly created tag sorts: after every existing one. */
function tags_next_sort_order(): int
{
    $row = q('SELECT MAX(sort_order) AS top FROM relationship_tags')->fetch();
    $top = $row === false || $row['top'] === null ? 0 : (int) $row['top'];
    return $top + 1;
}

/**
 * Create a custom relationship tag, or hand back the one that already exists.
 *
 * RETURNING THE EXISTING TAG IS NOT A SILENT FAILURE, it is the honest answer
 * to what was asked. The only caller is a box on a profile that says "new tag",
 * and typing "family" into it means "put this person in Family" — creating a
 * second row would be impossible anyway (name is UNIQUE) and erroring would be
 * a lecture about capitalisation.
 *
 * New tags land at the BOTTOM of the group order. The seeded five are ordered
 * by closeness on purpose (schema.sql) and a tag typed just now has not been
 * ranked against them.
 *
 * is_preset is 0, and that is the only difference between a custom tag and a
 * seeded one. It is a hint so the UI can decide whether to offer a delete
 * control, never a permission — all five presets are deletable and the database
 * does not know which is which.
 */
function tags_add(string $name): ?array
{
    $clean = people_clean_tag_name($name);
    if ($clean === null) {
        return null;
    }

    $existing = tag_find_by_name($clean);
    if ($existing !== null) {
        return $existing;
    }

    q(
        'INSERT INTO relationship_tags (name, sort_order, is_preset) VALUES (?, ?, 0)',
        array($clean, tags_next_sort_order())
    );

    return tag_get((int) db()->lastInsertId());
}

/**
 * Rename a tag. Returns the tag as it now is, or null if the name is taken.
 *
 * Renaming to the name it already has is a success, not a collision — the
 * inline editor on the People list's group heading can send one after a
 * whitespace-only edit, and refusing it would put the old text back and look
 * like the save failed.
 *
 * Because the link table points at the id and not at the name (schema.sql,
 * where both sides cascade), a rename moves every person under the new heading
 * with no data migration at all. That is also why a rename is offered and a
 * merge is not: there is nothing here that would make merging two tags anything
 * other than a second feature.
 */
function tags_rename(int $id, string $name): ?array
{
    $clean = people_clean_tag_name($name);
    if ($clean === null) {
        return null;
    }

    $existing = tag_find_by_name($clean);
    if ($existing !== null && $existing['id'] !== $id) {
        return null;
    }
    if (tag_get($id) === null) {
        return null;
    }

    q('UPDATE relationship_tags SET name = ? WHERE id = ?', array($clean, $id));
    return tag_get($id);
}

/* ================================================================== markup ==*/

/**
 * The seven identity fields, as form controls.
 *
 * THE ONE PIECE OF MARKUP IN THIS FILE, AND WHY IT IS HERE. Two screens render
 * this form — public/add.php creating a person and public/person.php editing
 * one — and they must agree on every field, every maxlength and, above all, on
 * the three birthday inputs, whose whole difficulty is that a birthday with no
 * year has to be enterable. Two copies of that is how one of them ends up
 * without the year box and nobody notices until an import writes one.
 *
 * It is a function and not an include so that both callers pass their own
 * $person and get their own values back with no shared state.
 *
 * @param array|null $person the row being edited, or null for a blank form
 */
function people_form_fields(?array $person): void
{
    $value = static fn (string $key): string => $person === null ? '' : (string) ($person[$key] ?? '');
    $birthMonth = $person === null ? null : $person['birth_month'];
    $birthDay   = $person === null ? null : $person['birth_day'];
    $birthYear  = $person === null ? null : $person['birth_year'];
    ?>
      <label class="field">
        <span>Name</span>
        <?php /* No autocorrect and no autocapitalize, matching the inline editor
                 and schema.sql's promise that people.name is stored verbatim: an
                 editor that "helps" will title-case "iPhone" and autocorrect a
                 surname it has never seen. */ ?>
        <input type="text" name="name" value="<?= h($value('name')) ?>"
               maxlength="<?= PEOPLE_NAME_MAX ?>" required
               autocomplete="off" autocorrect="off" autocapitalize="words" spellcheck="false">
      </label>

      <?php /* A <div> with its own labelled group rather than a <label>, because
               a birthday is three controls and a <label> can only point at one
               of them. Each input carries its own accessible name and the group
               carries the heading. */ ?>
      <div class="field">
        <span id="birthday-label">Birthday</span>
        <div class="row" role="group" aria-labelledby="birthday-label">
          <select name="birth_month" aria-label="Birth month">
            <option value="">Month</option>
<?php foreach (array('January', 'February', 'March', 'April', 'May', 'June',
                     'July', 'August', 'September', 'October', 'November', 'December') as $i => $monthName): ?>
            <option value="<?= $i + 1 ?>"<?= $birthMonth === $i + 1 ? ' selected' : '' ?>><?= h($monthName) ?></option>
<?php endforeach; ?>
          </select>
          <?php /* type="text" with inputmode="numeric", never type="number". A
                   number input answers the scroll wheel, so a flick past this
                   field on a laptop silently changes somebody's birth year; it
                   also turns on the browser's own validation, which would refuse
                   the form rather than let people_clean_birthday() decide. The
                   inputmode is what actually puts the numeric keypad on a
                   phone, which is the whole interaction here. */ ?>
          <input type="text" name="birth_day" value="<?= h($birthDay === null ? '' : (string) $birthDay) ?>"
                 inputmode="numeric" maxlength="2" placeholder="Day" aria-label="Birth day"
                 autocomplete="off" spellcheck="false">
          <input type="text" name="birth_year" value="<?= h($birthYear === null ? '' : (string) $birthYear) ?>"
                 inputmode="numeric" maxlength="4" placeholder="Year" aria-label="Birth year, optional"
                 autocomplete="off" spellcheck="false">
        </div>
        <?php /* Said out loud because it is the unusual bit: most forms make a
                 birthday all-or-nothing, and a phone's vCard export routinely
                 carries a month and day with no year at all. */ ?>
        <p class="hint">The year is optional — leave it blank if you only know the day.</p>
      </div>

      <label class="field">
        <span>Phone</span>
        <input type="tel" name="phone" value="<?= h($value('phone')) ?>"
               maxlength="<?= PEOPLE_PHONE_MAX ?>" inputmode="tel"
               autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
      </label>

      <label class="field">
        <span>Email</span>
        <?php /* inputmode="email" rather than type="email". The inputmode is
                 what puts "@" and "." on the phone keyboard; type="email" would
                 also turn on the browser's validation and refuse to submit the
                 whole form over a field nothing depends on. people_mailto()
                 decides whether it is linkable at render time instead, so an
                 odd address is still stored and still shown. */ ?>
        <input type="text" name="email" value="<?= h($value('email')) ?>"
               maxlength="<?= PEOPLE_EMAIL_MAX ?>" inputmode="email"
               autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
      </label>

      <label class="field">
        <span>Address</span>
        <?php /* A textarea for one stored line: typing a street address into a
                 single-line input on a phone means scrolling it sideways to
                 check what you typed. people_clean_address() folds the newlines
                 back into ", " on the way in. */ ?>
        <textarea name="address" rows="2" maxlength="<?= PEOPLE_ADDRESS_MAX ?>"
                  autocapitalize="words"><?= h($value('address')) ?></textarea>
      </label>

      <label class="field">
        <span>Notes</span>
        <?php /* No maxlength: the column is TEXT and this is the field that
                 holds "allergic to shellfish" and "ask about the move" for
                 years. Spellcheck stays ON here, unlike every other field —
                 this is prose, not a name. */ ?>
        <textarea name="notes" rows="5" placeholder="Allergic to shellfish. Ask about the move."><?= h($value('notes')) ?></textarea>
      </label>
<?php
}
