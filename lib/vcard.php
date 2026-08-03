<?php
/* The .vcf parser. Physical lines in off a file handle, contacts out.
 *
 * NO DATABASE, NO CONFIG, NO CLOCK. Everything here is a pure function over a
 * stream, which is what makes it testable against the fixture files in
 * tests/fixtures/ with no MySQL anywhere near it — and there is no MySQL in the
 * build environment, so anything that needed one would ship unexercised.
 * lib/import.php is the file that knows about batches, drafts and duplicates;
 * this one only knows what a vCard says.
 *
 * IT READS LINE BY LINE OFF A HANDLE AND NEVER file_get_contents(). A
 * 400-contact export out of a phone can be 30 MB of which 29 MB is base64
 * photographs, and photos are out of scope for v1 (PLAN.md §12) — so PHOTO is
 * recognised and its body is DISCARDED AS IT ARRIVES rather than accumulated
 * and then thrown away. Slurping the file would put those 29 MB in memory on a
 * shared host with a 128 MB limit, for data nothing in the app wants.
 *
 * THE SEVEN THINGS REAL EXPORTS DO, all of which are handled below and none of
 * which are hypothetical (PLAN.md §6.3):
 *
 *   1. LINE FOLDING (RFC 6350). A logical line continues on the next physical
 *      line, marked by a leading space or tab. Unfolded FIRST, before anything
 *      is parsed — every naive parser produces truncated addresses because it
 *      parsed a physical line.
 *   2. PHOTO, skipped without buffering. See above.
 *   3. QUOTED-PRINTABLE (vCard 2.1, still emitted by Android contact apps),
 *      including its `=` soft line breaks, which are NOT folds: the
 *      continuation line does not have to be indented.
 *   4. N vs FN. FN is the display name and is preferred; N is
 *      `Last;First;Middle;Prefix;Suffix` and is assembled when FN is absent. A
 *      card with neither is SKIPPED AND COUNTED, never imported as a nameless
 *      row.
 *   5. REPEATED TEL/EMAIL. Several of each is normal. The best-ranked one wins
 *      and the rest are dropped — the schema has one phone column and one email
 *      column, and the draft review screen is where you notice.
 *   6. ADR is `pobox;ext;street;locality;region;postal;country`, joined into
 *      one readable line, because people.address is one readable line.
 *   7. ESCAPING (`\,` `\;` `\n` `\\`) is undone LAST, after the structural
 *      splits. Undoing it first turns a literal `\;` in a street name into a
 *      field separator and silently cuts the address in half.
 *
 * FAIL SOFT, EVERYWHERE. A property with no colon, a card with no END, junk
 * between cards, an unparseable BDAY: each of those degrades exactly itself.
 * Nothing in this file throws on bad input — the whole point of importing a
 * file somebody's phone wrote is that you do not get to specify it.
 */

declare(strict_types=1);

/* Ceiling on ONE unfolded logical line, in bytes. A vCard line is a name, a
 * number or an address; nothing legitimate is close to this. It exists because
 * folding is unbounded by the format: a file with a leading space on every line
 * would otherwise be assembled into one string the size of the file, which is
 * the memory failure the streaming read was written to avoid, arriving by the
 * other door. Past the ceiling the tail of the value is dropped and the parse
 * continues. */
const VCARD_MAX_LOGICAL_LINE = 16384;

/* Properties whose value is a base64 blob and is never wanted. PHOTO is the one
 * that matters and the one the plan names; the others are the same shape and
 * the same non-answer, and recognising them costs one array entry each. */
const VCARD_SKIP_PROPERTIES = array('PHOTO', 'LOGO', 'SOUND', 'KEY');

/* How much of the file the BEGIN:VCARD sniff will read before giving up. A real
 * vCard says so on its first non-blank line; this much slack covers a BOM, a
 * blank line or two and a stray comment from a desktop client. */
const VCARD_SNIFF_BYTES = 4096;

/* Which TEL wins when a card carries several, which is the ordinary case.
 *
 * The rule from PLAN.md §6.3 is "take the first, preferring a TYPE=CELL or
 * TYPE=HOME over an untyped one", and these numbers are that sentence: a mobile
 * beats a landline, a landline beats a number with no type at all, and a number
 * with no type beats a work switchboard — because an untyped number in a
 * personal address book is nearly always the personal one. A fax is worth less
 * than nothing to a reminder app.
 *
 * TIES GO TO THE FIRST ONE SEEN, so a card with two mobiles keeps the one the
 * phone listed first, which is the one it considers primary. */
const VCARD_TEL_RANKS = array(
    'cell' => 4, 'mobile' => 4, 'iphone' => 4,
    'home' => 3, 'main' => 3,
    'work' => 1, 'other' => 1,
    'fax' => 0, 'pager' => 0, 'modem' => 0, 'car' => 0, 'isdn' => 0,
);
const VCARD_EMAIL_RANKS = array(
    'home' => 3, 'iphone' => 3,
    'work' => 1, 'other' => 1,
);
const VCARD_ADR_RANKS = array(
    'home' => 3, 'work' => 1, 'other' => 1,
);

/* Types that say nothing about which of two numbers you would rather have.
 * INTERNET is on essentially every EMAIL line ever exported and VOICE is on
 * essentially every TEL line; treating either as "typed" would make an untyped
 * number rank below a work fax. */
const VCARD_NOISE_TYPES = array('internet', 'voice', 'pref', 'preferred', 'x-apple');

/* The baseline for a property with no informative type. Deliberately between
 * work (1) and home (3) — see VCARD_TEL_RANKS. */
const VCARD_RANK_UNTYPED = 2;

/* TYPE=PREF is the exporter saying "this is the one". Worth exactly enough to
 * break a tie and never enough to beat a category outright. */
const VCARD_RANK_PREF_BONUS = 1;

/* ================================================================= entry ====*/

/**
 * Parse an open .vcf stream.
 *
 * @param resource $handle       readable stream, read from wherever it is now
 * @param int      $maxContacts  stop after this many contacts, 0 for no limit
 *
 * @return array{contacts: array<int, array>, parsed: int, skipped: int, truncated: bool}
 *
 * `parsed` counts every card the parser reached, INCLUDING the ones it then
 * refused for having no name — schema.sql's import_batches.total_parsed wants
 * that number so the review screen can say "218 of 224 contacts" rather than
 * quietly showing fewer rows than the file had. `skipped` is how many of those
 * were refused. `truncated` says the cap was hit and there was more file: the
 * screen reports it, because silently dropping the tail of somebody's address
 * book is the failure they find out about a year later.
 *
 * Each contact is:
 *   array{name: string, birth_year: ?int, birth_month: ?int, birth_day: ?int,
 *         address: ?string, phone: ?string, email: ?string}
 *
 * Nothing here is cleaned to the column widths or checked against today's date;
 * that is import_stage()'s job, because it needs people_clean_*() and a clock
 * and this file has neither.
 */
function vcard_parse_stream($handle, int $maxContacts = 0): array
{
    $contacts  = array();
    $parsed    = 0;
    $skipped   = 0;
    $truncated = false;

    $card = null;          // the card being accumulated, or null between cards

    $pending    = null;    // the logical line being unfolded, or null
    $pendingQp  = false;   // ... and it declared ENCODING=QUOTED-PRINTABLE
    $qpOpen     = false;   // ... and it currently ends on a soft line break
    $skipping   = false;   // inside a PHOTO, discarding as it arrives
    $skipBase64 = false;   // ... one whose continuation lines may not be indented
    $first      = true;

    while (($raw = fgets($handle)) !== false) {
        $line = rtrim($raw, "\r\n");

        /* A UTF-8 BOM on the first line would otherwise make the first property
         * name "\xEF\xBB\xBFBEGIN" and the whole file one long stretch of junk
         * outside any card. Windows exporters write one. */
        if ($first) {
            $line  = vcard_strip_bom($line);
            $first = false;
        }

        /* ---- 1. inside a photo we are throwing away ---------------------- */
        if ($skipping) {
            if (vcard_is_fold($line)) {
                continue;
            }
            /* vCard 2.1 writes base64 as unindented lines terminated by a blank
             * one, so indentation alone cannot find the end of the value. The
             * safe test is the other direction: keep discarding until something
             * that looks like a property name arrives. Over-consuming a line of
             * a photo costs nothing; under-consuming would feed base64 to the
             * property parser. */
            if ($skipBase64 && $line !== '' && !vcard_looks_like_property($line)) {
                continue;
            }
            $skipping   = false;
            $skipBase64 = false;
        }

        /* ---- 2. continuation of the line being assembled ------------------ */
        if ($pending !== null) {
            if ($qpOpen) {
                /* A quoted-printable SOFT LINE BREAK, which is not a fold: the
                 * `=` at the end of the line is the break itself and the
                 * continuation starts at column zero. Some exporters indent it
                 * anyway, so one leading space is tolerated and removed. */
                $chunk   = vcard_is_fold($line) ? substr($line, 1) : $line;
                $pending = vcard_append(substr($pending, 0, -1), $chunk);
                $qpOpen  = str_ends_with($pending, '=');
                continue;
            }
            if (vcard_is_fold($line)) {
                $pending = vcard_append($pending, substr($line, 1));
                $qpOpen  = $pendingQp && str_ends_with($pending, '=');
                continue;
            }

            /* Not a continuation, so the pending line is complete. */
            $done    = $pending;
            $pending = null;
            $result  = vcard_consume($done, $card, $contacts, $parsed, $skipped);
            if ($result === true && $maxContacts > 0 && count($contacts) >= $maxContacts) {
                $truncated = vcard_has_more($handle);
                return vcard_result($contacts, $parsed, $skipped, $truncated);
            }
        }

        if (trim($line) === '') {
            continue;
        }

        /* ---- 3. a new logical line ---------------------------------------- */
        if (vcard_is_skipped_property($line)) {
            $skipping   = true;
            $skipBase64 = stripos($line, 'BASE64') !== false || stripos($line, 'ENCODING=B') !== false;
            continue;
        }

        $pending   = $line;
        $pendingQp = vcard_line_is_qp($line);
        $qpOpen    = $pendingQp && str_ends_with($line, '=');
    }

    /* EOF with a line still being assembled: a file that was truncated in
     * transit, or one whose last line has no newline. Use what there is. */
    if ($pending !== null) {
        vcard_consume($pending, $card, $contacts, $parsed, $skipped);
    }

    /* EOF inside a card — no END:VCARD, because the upload was cut short or the
     * exporter died. The contact is as complete as it is going to get and is
     * worth more staged than discarded; the review screen is where a half card
     * gets noticed. */
    if ($card !== null) {
        vcard_close_card($card, $contacts, $parsed, $skipped);
    }

    return vcard_result($contacts, $parsed, $skipped, $truncated);
}

/**
 * Parse a .vcf by path. The convenience wrapper the endpoint actually calls.
 *
 * @throws RuntimeException when the file cannot be opened at all — which is a
 *         server problem (a bad move_uploaded_file, a full disk), not a bad
 *         file, and is the one thing here worth failing loudly on.
 */
function vcard_parse_file(string $path, int $maxContacts = 0): array
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Could not open the uploaded file for reading.');
    }

    try {
        return vcard_parse_stream($handle, $maxContacts);
    } finally {
        fclose($handle);
    }
}

/**
 * Does this stream begin like a vCard?
 *
 * PLAN.md §6.4 asks for this by name: it is the one place in the app where
 * somebody hands it a file, and "nothing happened" is the worst possible
 * response to the wrong one. Checking the first non-blank line rather than the
 * extension, because a .vcf that is really a photo library export is a file
 * that parses to zero contacts and reports nothing.
 *
 * Leaves the stream where it found it, so the caller can parse it afterwards.
 */
function vcard_sniff($handle): bool
{
    $start = ftell($handle);
    $read  = 0;
    $found = false;
    $first = true;

    while ($read < VCARD_SNIFF_BYTES && ($line = fgets($handle)) !== false) {
        $read += strlen($line);
        if ($first) {
            $line  = vcard_strip_bom($line);
            $first = false;
        }
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $found = strcasecmp($line, 'BEGIN:VCARD') === 0;
        break;
    }

    if ($start !== false) {
        fseek($handle, $start);
    }
    return $found;
}

/** vcard_sniff() by path. False when the file cannot even be opened. */
function vcard_sniff_file(string $path): bool
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    try {
        return vcard_sniff($handle);
    } finally {
        fclose($handle);
    }
}

/* =============================================================== the loop ===*/

/** The parse result, in one shape, from the three places that return one. */
function vcard_result(array $contacts, int $parsed, int $skipped, bool $truncated): array
{
    return array(
        'contacts'  => $contacts,
        'parsed'    => $parsed,
        'skipped'   => $skipped,
        'truncated' => $truncated,
    );
}

/**
 * Handle one complete logical line: BEGIN, END, or a property of the open card.
 *
 * Returns true when the line closed a card, which is the only moment the caller
 * has to check the contact cap.
 */
function vcard_consume(string $line, ?array &$card, array &$contacts, int &$parsed, int &$skipped): bool
{
    $flat = trim($line);

    if (strcasecmp($flat, 'BEGIN:VCARD') === 0) {
        /* A BEGIN while a card is open means the previous card never got its
         * END — see the EOF case for why it is closed rather than dropped. */
        $closed = $card !== null;
        if ($closed) {
            vcard_close_card($card, $contacts, $parsed, $skipped);
        }
        $card = vcard_new_card();
        return $closed;
    }

    if (strcasecmp($flat, 'END:VCARD') === 0) {
        if ($card === null) {
            return false;   // an END with no BEGIN: junk between cards
        }
        vcard_close_card($card, $contacts, $parsed, $skipped);
        $card = null;
        return true;
    }

    if ($card === null) {
        return false;       // a property outside any card: junk, ignored
    }

    $property = vcard_property($line);
    if ($property !== null) {
        vcard_apply_property($card, $property);
    }
    return false;
}

/** Finish a card: count it, and keep it if it has a name. */
function vcard_close_card(?array &$card, array &$contacts, int &$parsed, int &$skipped): void
{
    if ($card === null) {
        return;
    }

    $parsed++;
    $contact = vcard_contact($card);
    $card    = null;

    if ($contact === null) {
        /* PLAN.md §6.3, rule 4: a card with neither FN nor N is skipped,
         * counted and reported — never staged as a nameless row somebody has to
         * work out from a phone number. */
        $skipped++;
        return;
    }

    $contacts[] = $contact;
}

/** Is there anything left in the stream? Only asked when the cap was hit. */
function vcard_has_more($handle): bool
{
    /* feof() is false until a read has actually failed, so it cannot answer
     * this on its own — a stream sitting exactly at the last byte reports
     * false. One byte, put back. */
    $byte = fgetc($handle);
    if ($byte === false) {
        return false;
    }
    fseek($handle, -1, SEEK_CUR);
    return true;
}

/** Append to a logical line, stopping at the ceiling. See VCARD_MAX_LOGICAL_LINE. */
function vcard_append(string $line, string $chunk): string
{
    if (strlen($line) >= VCARD_MAX_LOGICAL_LINE) {
        return $line;
    }
    return substr($line . $chunk, 0, VCARD_MAX_LOGICAL_LINE);
}

/* ============================================================ line shapes ===*/

/** A folded continuation line: RFC 6350 says one leading space or tab. */
function vcard_is_fold(string $line): bool
{
    return $line !== '' && ($line[0] === ' ' || $line[0] === "\t");
}

/**
 * Does this line start with something that could be a property name?
 *
 * Used only to find the end of an unindented base64 photo, where the
 * alternative is guessing. Deliberately strict: a name, optionally in a group
 * (`item1.TEL`), followed by a `;` or a `:`. Base64 has neither in its
 * alphabet, so a body line can never pass this.
 */
function vcard_looks_like_property(string $line): bool
{
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-]*[;:]/', $line) === 1;
}

/** One of the base64 blobs that never gets stored. See VCARD_SKIP_PROPERTIES. */
function vcard_is_skipped_property(string $line): bool
{
    $split = vcard_split_at_colon($line);
    $head  = $split === null ? $line : $split[0];

    $segments = explode(';', $head);
    $name     = (string) $segments[0];

    $dot = strrpos($name, '.');
    if ($dot !== false) {
        $name = substr($name, $dot + 1);
    }

    return in_array(strtoupper(trim($name)), VCARD_SKIP_PROPERTIES, true);
}

/**
 * Does this line declare quoted-printable?
 *
 * Answered off the raw text rather than by parsing the property, because the
 * reader has to know BEFORE the value is complete: a `=` at the end of a
 * quoted-printable line is a soft break and the next physical line belongs to
 * it, while a `=` at the end of any other line is just an equals sign. Only the
 * parameter section is searched, so a value that happens to contain the words
 * cannot flip it.
 */
function vcard_line_is_qp(string $line): bool
{
    $split = vcard_split_at_colon($line);
    $head  = $split === null ? $line : $split[0];

    return stripos($head, 'QUOTED-PRINTABLE') !== false;
}

/** Drop a UTF-8 byte order mark. */
function vcard_strip_bom(string $line): string
{
    return str_starts_with($line, "\xEF\xBB\xBF") ? substr($line, 3) : $line;
}

/* ============================================================== splitting ===*/

/**
 * Split a property line into its head and its value at the first real colon.
 *
 * THE COLON IS NOT ALWAYS THE FIRST COLON. A quoted parameter value may contain
 * one (`TEL;TYPE="voice:home":+1…`), and so does every value that is a URI. So
 * this walks the line and ignores anything inside double quotes, which is the
 * only quoting the format has.
 *
 * @return array{0: string, 1: string}|null null when there is no colon at all,
 *         which is a malformed line and is dropped by the caller
 */
function vcard_split_at_colon(string $line): ?array
{
    $quoted = false;
    $length = strlen($line);

    for ($i = 0; $i < $length; $i++) {
        $char = $line[$i];
        if ($char === '"') {
            $quoted = !$quoted;
            continue;
        }
        if ($char === ':' && !$quoted) {
            return array(substr($line, 0, $i), substr($line, $i + 1));
        }
    }
    return null;
}

/** Split a parameter section on `;`, ignoring separators inside double quotes. */
function vcard_split_quoted(string $head, string $separator): array
{
    $parts  = array();
    $buffer = '';
    $quoted = false;
    $length = strlen($head);

    for ($i = 0; $i < $length; $i++) {
        $char = $head[$i];
        if ($char === '"') {
            $quoted = !$quoted;
            $buffer .= $char;
            continue;
        }
        if ($char === $separator && !$quoted) {
            $parts[] = $buffer;
            $buffer  = '';
            continue;
        }
        $buffer .= $char;
    }
    $parts[] = $buffer;

    return $parts;
}

/**
 * Split a structured VALUE on its separator, honouring backslash escapes.
 *
 * THIS IS RULE 7 AND IT IS THE ONE THAT LOOKS LIKE PEDANTRY UNTIL IT BITES.
 * `ADR:;;Flat 3\, Belvedere House\; rear entrance;Kingston;…` has a literal
 * semicolon inside its street. Unescaping before splitting turns that into a
 * component boundary and the address silently loses everything after it. So the
 * split happens first, with `\;` skipped over as two ordinary characters, and
 * vcard_unescape() runs afterwards on each component.
 */
function vcard_split_escaped(string $value, string $separator): array
{
    $parts  = array();
    $buffer = '';
    $length = strlen($value);

    for ($i = 0; $i < $length; $i++) {
        $char = $value[$i];

        if ($char === '\\' && $i + 1 < $length) {
            $buffer .= $char . $value[$i + 1];
            $i++;
            continue;
        }
        if ($char === $separator) {
            $parts[] = $buffer;
            $buffer  = '';
            continue;
        }
        $buffer .= $char;
    }
    $parts[] = $buffer;

    return $parts;
}

/**
 * Undo the four escapes the format defines. Runs LAST — see above.
 *
 * An escape sequence nobody defined keeps its backslash. `\U` is far more
 * likely to be an exporter that failed to escape a literal backslash than a
 * fifth escape somebody invented, and dropping it would quietly edit a stored
 * address.
 */
function vcard_unescape(string $value): string
{
    $out    = '';
    $length = strlen($value);

    for ($i = 0; $i < $length; $i++) {
        $char = $value[$i];
        if ($char !== '\\' || $i + 1 >= $length) {
            $out .= $char;
            continue;
        }

        $next = $value[$i + 1];
        $i++;
        switch ($next) {
            case 'n':
            case 'N':
                $out .= "\n";
                break;
            case ',':
                $out .= ',';
                break;
            case ';':
                $out .= ';';
                break;
            case '\\':
                $out .= '\\';
                break;
            default:
                $out .= '\\' . $next;
        }
    }

    return $out;
}

/* =============================================================== decoding ===*/

/**
 * Decode a property value's transfer encoding and land it in UTF-8.
 *
 * QUOTED-PRINTABLE IS UNDONE BEFORE THE STRUCTURAL SPLIT, which is the opposite
 * order from the backslash escapes, and it has to be: the soft line break that
 * joins two physical lines can fall in the middle of any component, so there is
 * nothing to split until the value is decoded. The cost is that a `=3B` inside
 * a quoted-printable component is indistinguishable from a real separator
 * afterwards. Nothing can be done about that from here, and no exporter in the
 * wild encodes its own separators — they encode the non-ASCII characters and
 * nothing else, which is the case this exists for.
 */
function vcard_decode_value(string $value, ?string $encoding, ?string $charset): string
{
    if ($encoding !== null && str_contains($encoding, 'quoted-printable')) {
        /* PHP's own decoder. It also handles a trailing `=` (a soft break the
         * reader has already joined) and a lone `=` before a non-hex pair,
         * which is exactly the malformed case a hand-rolled loop gets wrong. */
        $value = quoted_printable_decode($value);
    }

    return vcard_to_utf8($value, $charset);
}

/**
 * Whatever bytes arrived, as valid UTF-8.
 *
 * A stored value that is not valid UTF-8 is not a display problem, it is an
 * INSERT that MySQL rejects under utf8mb4 — one bad contact taking down a whole
 * batch. So this always returns something storable: the declared charset when
 * there is one, Latin-1 as the guess when the bytes are not UTF-8 and nothing
 * said otherwise (it is the only single-byte encoding worth guessing and it can
 * never fail), and the original when it is already fine.
 */
function vcard_to_utf8(string $value, ?string $charset): string
{
    $from = $charset === null ? '' : strtoupper(trim($charset));

    if ($from !== '' && $from !== 'UTF-8' && $from !== 'UTF8') {
        try {
            $converted = mb_convert_encoding($value, 'UTF-8', $from);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        } catch (Throwable $e) {
            /* An encoding name mb_* has never heard of. Fall through and treat
             * the bytes as what they look like. */
        }
    }

    if (mb_check_encoding($value, 'UTF-8')) {
        return $value;
    }

    try {
        $fallback = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
        return is_string($fallback) ? $fallback : '';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Trim a decoded value and take the control characters out of it.
 *
 * Newlines survive: `\n` is a real escape in the format and an ADR component
 * can legitimately carry one. people_clean_address() folds those into ", " when
 * the draft is staged, which is where that decision belongs.
 */
function vcard_clean_text(string $value): string
{
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    if ($clean === null) {
        /* Invalid UTF-8 made the /u pattern fail outright rather than match
         * nothing. The bytes have already been through vcard_to_utf8(), so this
         * is close to impossible — but "close to impossible" is how a whole
         * import ends up refusing to run. */
        $clean = $value;
    }
    return trim($clean);
}

/* ============================================================= properties ===*/

/**
 * One logical line, taken apart.
 *
 * @return array{name: string, types: array<int, string>, encoding: ?string,
 *               charset: ?string, value: string}|null
 */
function vcard_property(string $line): ?array
{
    $split = vcard_split_at_colon($line);
    if ($split === null) {
        return null;    // no colon: not a property. Dropped, and only this line.
    }

    $segments = vcard_split_quoted($split[0], ';');
    $name     = (string) array_shift($segments);

    /* A GROUP PREFIX. iOS writes `item1.ADR` and `item1.X-ABLabel` to tie a
     * value to its custom label; the group is meaningless to us and the
     * property after the dot is the real one. Nothing here reads X-ABLabel —
     * "Home"/"Work" labels are exactly what the TYPE parameters already say. */
    $dot = strrpos($name, '.');
    if ($dot !== false) {
        $name = substr($name, $dot + 1);
    }

    $name = strtoupper(trim($name));
    if ($name === '') {
        return null;
    }

    $types    = array();
    $encoding = null;
    $charset  = null;

    foreach ($segments as $segment) {
        $equals = strpos($segment, '=');

        if ($equals === false) {
            /* A BARE PARAMETER: `TEL;CELL:` — vCard 2.1's spelling of
             * `TEL;TYPE=CELL:`, and still what several Android exporters
             * write. Treated as a type, which is what it is. */
            $bare = strtolower(trim($segment));
            if ($bare !== '') {
                $types[] = $bare;
            }
            continue;
        }

        $key   = strtolower(trim(substr($segment, 0, $equals)));
        $value = trim(substr($segment, $equals + 1), " \t\"");

        if ($key === 'type') {
            /* `TYPE=HOME,VOICE` and `TYPE=HOME;TYPE=VOICE` are the same thing
             * written two ways, and both turn up in the same export. */
            foreach (explode(',', $value) as $type) {
                $type = strtolower(trim($type, " \t\""));
                if ($type !== '') {
                    $types[] = $type;
                }
            }
            continue;
        }
        if ($key === 'encoding') {
            $encoding = strtolower($value);
            continue;
        }
        if ($key === 'charset') {
            $charset = $value;
        }
    }

    return array(
        'name'     => $name,
        'types'    => $types,
        'encoding' => $encoding,
        'charset'  => $charset,
        'value'    => $split[1],
    );
}

/**
 * How much this TEL/EMAIL/ADR is wanted, given its types.
 *
 * A property with no informative type scores VCARD_RANK_UNTYPED rather than
 * zero: an untyped number in somebody's phone is nearly always their personal
 * one, and ranking it below a work fax would be a strange way to read an
 * address book. See VCARD_TEL_RANKS.
 */
function vcard_type_rank(array $types, array $ranks): int
{
    $rank      = 0;
    $informed  = false;
    $preferred = false;

    foreach ($types as $type) {
        if ($type === 'pref' || $type === 'preferred') {
            $preferred = true;
        }
        if (in_array($type, VCARD_NOISE_TYPES, true)) {
            continue;
        }
        $informed = true;
        $rank     = max($rank, $ranks[$type] ?? 0);
    }

    if (!$informed) {
        $rank = VCARD_RANK_UNTYPED;
    }

    return $rank + ($preferred ? VCARD_RANK_PREF_BONUS : 0);
}

/* ================================================================== cards ===*/

/** A card in progress. The `_rank` fields are how repeated properties compete. */
function vcard_new_card(): array
{
    return array(
        'fn'           => null,
        'n'            => null,
        'phone'        => null,
        'phone_rank'   => -1,
        'email'        => null,
        'email_rank'   => -1,
        'address'      => null,
        'address_rank' => -1,
        'bday'         => null,
    );
}

/** Fold one property into the card being accumulated. */
function vcard_apply_property(array &$card, array $property): void
{
    $name  = $property['name'];
    $value = vcard_decode_value($property['value'], $property['encoding'], $property['charset']);

    switch ($name) {
        case 'FN':
            $text = vcard_clean_text(vcard_unescape($value));
            if ($text !== '' && $card['fn'] === null) {
                $card['fn'] = $text;
            }
            return;

        case 'N':
            if ($card['n'] === null) {
                $assembled = vcard_assemble_name(vcard_split_escaped($value, ';'));
                if ($assembled !== '') {
                    $card['n'] = $assembled;
                }
            }
            return;

        case 'TEL':
            $text = vcard_clean_text(vcard_unescape($value));
            if ($text === '') {
                return;
            }
            $rank = vcard_type_rank($property['types'], VCARD_TEL_RANKS);
            if ($rank > $card['phone_rank']) {
                $card['phone']      = $text;
                $card['phone_rank'] = $rank;
            }
            return;

        case 'EMAIL':
            $text = vcard_clean_text(vcard_unescape($value));
            if ($text === '') {
                return;
            }
            $rank = vcard_type_rank($property['types'], VCARD_EMAIL_RANKS);
            if ($rank > $card['email_rank']) {
                $card['email']      = $text;
                $card['email_rank'] = $rank;
            }
            return;

        case 'ADR':
            $text = vcard_assemble_address(vcard_split_escaped($value, ';'));
            if ($text === '') {
                return;
            }
            $rank = vcard_type_rank($property['types'], VCARD_ADR_RANKS);
            if ($rank > $card['address_rank']) {
                $card['address']      = $text;
                $card['address_rank'] = $rank;
            }
            return;

        case 'BDAY':
            if ($card['bday'] === null) {
                $card['bday'] = vcard_parse_bday(vcard_clean_text($value));
            }
            return;

        default:
            /* ORG, TITLE, NOTE, URL, X-*, VERSION, PRODID, REV, UID and the
             * rest. There is nowhere to put them: import_drafts carries the
             * seven columns a person is made of and nothing else (schema.sql),
             * and a draft with fields the person screen cannot show would be a
             * promise the promote path could not keep. */
            return;
    }
}

/**
 * The display name for a card, or '' when it has none.
 *
 * FN FIRST, N SECOND. FN is what the phone shows and is the name somebody
 * actually chose — "Mum", "Alex (from climbing)", "Dr. Okafor". N is five
 * structured parts that were often typed by an importer rather than a person.
 */
function vcard_card_name(array $card): string
{
    if ($card['fn'] !== null && $card['fn'] !== '') {
        return $card['fn'];
    }
    return (string) ($card['n'] ?? '');
}

/**
 * `Last;First;Middle;Prefix;Suffix` in reading order.
 *
 * Assembled Prefix First Middle Last Suffix, so "Dr." lands in front and "PhD"
 * at the back — the order somebody would say the name out loud, because that is
 * what the People list renders and what the search matches against.
 */
function vcard_assemble_name(array $parts): string
{
    $family = vcard_clean_text(vcard_unescape((string) ($parts[0] ?? '')));
    $given  = vcard_clean_text(vcard_unescape((string) ($parts[1] ?? '')));
    $middle = vcard_clean_text(vcard_unescape((string) ($parts[2] ?? '')));
    $prefix = vcard_clean_text(vcard_unescape((string) ($parts[3] ?? '')));
    $suffix = vcard_clean_text(vcard_unescape((string) ($parts[4] ?? '')));

    $ordered = array();
    foreach (array($prefix, $given, $middle, $family, $suffix) as $piece) {
        if ($piece !== '') {
            $ordered[] = $piece;
        }
    }

    return implode(' ', $ordered);
}

/**
 * `pobox;ext;street;locality;region;postal;country` as one readable line.
 *
 * File order, empties dropped, joined with ", ". people.address is one line
 * (schema.sql) because nothing in this app queries an address — it prints one
 * and hands it to a maps link, and a maps link wants exactly this.
 */
function vcard_assemble_address(array $parts): string
{
    $pieces = array();
    foreach ($parts as $part) {
        $piece = vcard_clean_text(vcard_unescape((string) $part));
        if ($piece !== '') {
            $pieces[] = $piece;
        }
    }

    return implode(', ', $pieces);
}

/**
 * A BDAY value, as the three columns people/import_drafts actually have.
 *
 * THE YEARLESS FORM IS THE ORDINARY ONE, not an edge case. `BDAY:--0415` is
 * what a phone writes when the contact's birthday was entered without a year,
 * which is most of them — schema.sql splits the birthday into three columns
 * precisely so this can be stored honestly instead of behind a sentinel year.
 *
 * Accepted, all of which turn up in real exports:
 *   19910415 · 1991-04-15 · 1991-4-5 · --0415 · --04-15 · 1991-04-15T00:00:00Z
 *
 * Returns null for anything else, including the `1604-01-01` and `0000-00-00`
 * placeholders some exporters write for "no birthday" — a wrong birthday is
 * worse than none, because none is visible and wrong is not.
 *
 * The YEAR IS NOT SANITY-CHECKED HERE. That needs to know what year it is now,
 * and this file has no clock on purpose; people_clean_birthday() does it at
 * staging time with the $today it was handed. The DAY is not clamped here
 * either — `--0631` is stored as the file said and lib/dates.php clamps it on
 * read, so nothing quietly rewrites what the export claimed.
 *
 * @return array{year: ?int, month: int, day: int}|null
 */
function vcard_parse_bday(string $raw): ?array
{
    $value = trim($raw);
    if ($value === '') {
        return null;
    }

    /* A date-time value: everything from the T (or the first space) is a time
     * of day, and a birthday has none. */
    $cut = strcspn($value, 'T ');
    $value = substr($value, 0, $cut);

    $year = null;

    if (str_starts_with($value, '--')) {
        if (preg_match('/^--(\d{1,2})-?(\d{1,2})$/', $value, $m) !== 1) {
            return null;
        }
        $month = (int) $m[1];
        $day   = (int) $m[2];
    } elseif (preg_match('/^(\d{4})-?(\d{1,2})-?(\d{1,2})$/', $value, $m) === 1) {
        $year  = (int) $m[1];
        $month = (int) $m[2];
        $day   = (int) $m[3];
    } else {
        return null;
    }

    if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
        return null;
    }
    if ($year !== null && $year < 1000) {
        /* `0000-04-15` — "the year is unknown", spelled by an exporter that
         * would not leave the field empty. The month and day are real and are
         * kept; only the year is dropped. */
        $year = null;
    }

    return array('year' => $year, 'month' => $month, 'day' => $day);
}

/**
 * A finished card as a contact, or null when it has no name.
 *
 * @return array{name: string, birth_year: ?int, birth_month: ?int, birth_day: ?int,
 *               address: ?string, phone: ?string, email: ?string}|null
 */
function vcard_contact(array $card): ?array
{
    $name = vcard_card_name($card);
    if ($name === '') {
        return null;
    }

    $bday = $card['bday'];

    return array(
        'name'        => $name,
        'birth_year'  => $bday === null ? null : $bday['year'],
        'birth_month' => $bday === null ? null : $bday['month'],
        'birth_day'   => $bday === null ? null : $bday['day'],
        'address'     => $card['address'],
        'phone'       => $card['phone'],
        'email'       => $card['email'],
    );
}
