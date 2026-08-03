<?php
/**
 * =============================================================================
 *  MYSQL IS THE PRODUCTION TARGET. THIS FILE IS FOR TESTS AND NOTHING ELSE.
 * =============================================================================
 *
 * schema.sql is written for MySQL/MariaDB and that is the only database this
 * app is ever deployed against. There is no MySQL and no Docker in the build
 * environment, so without something like this file the schema and every repo
 * function in the app would ship completely unexercised.
 *
 * So: this loads schema.sql, rewrites the handful of MySQL-isms it uses into
 * SQLite, and hands back an in-memory PDO connection with the whole thing
 * created and seeded. It installs itself as db()'s connection (see the CLI-only
 * override in lib/db.php), which means tests call the REAL q() and the REAL
 * repo functions rather than a parallel query path that could drift.
 *
 * WHAT THIS PROVES: the schema parses, the tables and their relationships are
 * coherent, the seed data is present and correctly ordered, constraints reject
 * what they should, and repo code executes end to end.
 *
 * WHAT IT DOES NOT PROVE: anything about MySQL. It does not prove index choice,
 * collation behaviour, utf8mb4 index length limits, ENUM semantics, or that
 * MySQL's own parser accepts the file. A passing test run is not a substitute
 * for loading schema.sql into a real MariaDB once before deploying.
 *
 * TWO RULES FOR ANYONE EXTENDING THIS:
 *   1. schema.sql is written for MySQL. Never edit it to make this file's job
 *      easier. If a construct won't translate, teach the translator.
 *   2. Never write a query that only works on SQLite. Repo code targets MySQL;
 *      anything that passes here and fails there is worse than no test.
 *
 * SCHEMA constructs handled, which is exactly what schema.sql uses and no more:
 *
 *   ENGINE=/DEFAULT CHARSET=/COLLATE=          dropped
 *   INT UNSIGNED ... AUTO_INCREMENT + PRIMARY KEY (id)
 *                                              -> INTEGER PRIMARY KEY AUTOINCREMENT
 *   UNSIGNED                                   dropped
 *   TINYINT / INT / BIGINT / SMALLINT          -> INTEGER
 *   ENUM('a','b')                              -> TEXT + CHECK (col IN ('a','b'))
 *   ON UPDATE CURRENT_TIMESTAMP                dropped (SQLite has no equivalent)
 *   KEY / UNIQUE KEY inside CREATE TABLE       -> separate CREATE [UNIQUE] INDEX
 *   INSERT IGNORE                              -> INSERT OR IGNORE
 *   PRIMARY KEY (a, b)                         -> kept, with NOT NULL forced onto
 *                                                 every column in it, because
 *                                                 SQLite permits NULLs in a
 *                                                 primary key and MySQL does not
 *
 * QUERY constructs handled, on the connection itself (see HarnessPdo), because
 * this app's repo code writes upserts and the tests run the real repo code:
 *
 *   INSERT IGNORE                              -> INSERT OR IGNORE
 *   ON DUPLICATE KEY UPDATE ...                -> ON CONFLICT DO UPDATE SET ...
 *   VALUES(col) inside that clause             -> excluded.col
 *
 * Note what is NOT here, and does not need to be: there is no DATE_ADD, no
 * INTERVAL and no DAYOFYEAR to fake. Every due-date query in this app is
 * `WHERE next_due_date <= ?` against a DATE column, with the date computed in
 * PHP by lib/dates.php. That was a design decision made partly FOR this file —
 * see PLAN.md §5 — and it is why the riskiest logic in the app is also the
 * easiest to test.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Remove `-- ...` comments without touching apostrophes inside string
 * literals. A seed value like `'Lowe''s'` in the sibling app is exactly the
 * case a naive line-based strip gets wrong the moment a comment marker follows
 * one. Nothing in THIS schema's seeds has an apostrophe today — a user-created
 * relationship tag can, and so can any seed added later.
 */
function harness_strip_comments(string $sql): string
{
    $out = '';
    $len = strlen($sql);
    $inString = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];

        if ($inString) {
            $out .= $ch;
            if ($ch === "'") {
                // '' is an escaped quote, not the end of the string.
                if (($sql[$i + 1] ?? '') === "'") {
                    $out .= "'";
                    $i++;
                } else {
                    $inString = false;
                }
            }
            continue;
        }

        if ($ch === "'") {
            $inString = true;
            $out .= $ch;
            continue;
        }

        if ($ch === '-' && ($sql[$i + 1] ?? '') === '-') {
            $nl = strpos($sql, "\n", $i);
            if ($nl === false) {
                break;
            }
            $i = $nl;
            $out .= "\n";
            continue;
        }

        $out .= $ch;
    }

    return $out;
}

/** Split on top-level semicolons, string-aware. */
function harness_split_statements(string $sql): array
{
    $statements = array();
    $current = '';
    $len = strlen($sql);
    $inString = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];

        if ($inString) {
            $current .= $ch;
            if ($ch === "'") {
                if (($sql[$i + 1] ?? '') === "'") {
                    $current .= "'";
                    $i++;
                } else {
                    $inString = false;
                }
            }
            continue;
        }

        if ($ch === "'") {
            $inString = true;
            $current .= $ch;
            continue;
        }

        if ($ch === ';') {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            continue;
        }

        $current .= $ch;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

/** Split a CREATE TABLE body on commas that are not inside parens or strings. */
function harness_split_definitions(string $body): array
{
    $parts = array();
    $current = '';
    $depth = 0;
    $len = strlen($body);
    $inString = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $body[$i];

        if ($inString) {
            $current .= $ch;
            if ($ch === "'") {
                if (($body[$i + 1] ?? '') === "'") {
                    $current .= "'";
                    $i++;
                } else {
                    $inString = false;
                }
            }
            continue;
        }

        if ($ch === "'") {
            $inString = true;
            $current .= $ch;
            continue;
        }
        if ($ch === '(') {
            $depth++;
        } elseif ($ch === ')') {
            $depth--;
        } elseif ($ch === ',' && $depth === 0) {
            $parts[] = trim($current);
            $current = '';
            continue;
        }

        $current .= $ch;
    }

    $last = trim($current);
    if ($last !== '') {
        $parts[] = $last;
    }

    return $parts;
}

/** Rewrite one column definition. Returns array{sql:string, autoinc:bool}. */
function harness_translate_column(string $def): array
{
    // The column name is the first token; everything after it is type + attrs.
    if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s+(.*)$/s', $def, $m)) {
        return array('sql' => $def, 'autoinc' => false);
    }
    $name = $m[1];
    $rest = $m[2];

    if (preg_match('/\bAUTO_INCREMENT\b/i', $rest)) {
        /* SQLite only allows AUTOINCREMENT on an INTEGER PRIMARY KEY declared
         * inline, so the whole definition is replaced rather than patched — and
         * the table-level PRIMARY KEY (id) that went with it is dropped by the
         * caller. */
        return array('sql' => $name . ' INTEGER PRIMARY KEY AUTOINCREMENT', 'autoinc' => true);
    }

    // ENUM has no SQLite equivalent; a CHECK constraint enforces the same set.
    if (preg_match('/\bENUM\s*\((.*?)\)/is', $rest, $enum)) {
        $rest = preg_replace('/\bENUM\s*\(.*?\)/is', 'TEXT', $rest, 1);
        $rest .= ' CHECK (' . $name . ' IN (' . $enum[1] . '))';
    }

    $rest = preg_replace('/\bUNSIGNED\b/i', '', $rest);
    $rest = preg_replace('/\bON\s+UPDATE\s+CURRENT_TIMESTAMP\b/i', '', $rest);
    /* The \b after the alternation matters: without it, `INT NOT NULL` matched
     * "INT " including the trailing space and produced "INTEGERNOT NULL", which
     * SQLite silently accepts as a column of type INTEGERNOT. It ran, and the
     * tests passed, on a schema that had quietly lost its NOT NULL. */
    $rest = preg_replace('/\b(TINYINT|SMALLINT|MEDIUMINT|BIGINT|INT)\b(\s*\(\s*\d+\s*\))?/i', 'INTEGER', $rest);
    $rest = preg_replace('/\s+/', ' ', trim($rest));

    return array('sql' => $name . ' ' . $rest, 'autoinc' => false);
}

/**
 * Translate one MySQL statement into one or more SQLite statements.
 *
 * @return string[]
 */
function harness_translate_statement(string $statement): array
{
    if (preg_match('/^INSERT\s+IGNORE\s+/i', $statement)) {
        return array(preg_replace('/^INSERT\s+IGNORE\s+/i', 'INSERT OR IGNORE ', $statement, 1));
    }

    if (!preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*\(/is', $statement, $m)) {
        return array($statement);
    }

    $table = $m[1];
    $open = strpos($statement, '(');
    $close = strrpos($statement, ')');
    if ($open === false || $close === false || $close <= $open) {
        return array($statement);
    }

    // Everything after the closing paren is table options (ENGINE, CHARSET...).
    $body = substr($statement, $open + 1, $close - $open - 1);

    $columns = array();
    /* Table-level constraints are collected SEPARATELY from the columns so the
     * deferred PRIMARY KEY can be emitted between the two. schema.sql writes
     * its FOREIGN KEYs above its PRIMARY KEY in some tables and below in
     * others; SQLite tolerates either order, but generated DDL that reads like
     * hand-written DDL is what makes a translator bug visible when you print
     * the statement to look at it. */
    $constraints = array();
    $indexes = array();
    $autoCol = null;
    $deferredPrimary = null;

    foreach (harness_split_definitions($body) as $def) {
        $def = trim($def);
        if ($def === '') {
            continue;
        }

        if (preg_match('/^PRIMARY\s+KEY\s*\((.*)\)$/is', $def, $pk)) {
            $deferredPrimary = trim($pk[1]);
            continue;
        }

        if (preg_match('/^(UNIQUE\s+)?KEY\s+([A-Za-z_][A-Za-z0-9_]*)\s*\((.*)\)$/is', $def, $key)) {
            /* Index names are unique per-table in MySQL and global in SQLite, so
             * they get the table name prefixed — otherwise idx_sort on two
             * tables collides.
             *
             * IF NOT EXISTS because schema.sql is written to be re-runnable
             * (every CREATE TABLE says so). A KEY inside a CREATE TABLE IF NOT
             * EXISTS is skipped along with the table on a second run; hoisting
             * it out of the statement loses that, and re-applying the file
             * would fail on the index instead. */
            $indexes[] = sprintf(
                'CREATE %sINDEX IF NOT EXISTS %s_%s ON %s (%s)',
                trim($key[1]) !== '' ? 'UNIQUE ' : '',
                $table,
                $key[2],
                $table,
                trim($key[3])
            );
            continue;
        }

        if (preg_match('/^(CONSTRAINT\s|FOREIGN\s+KEY|CHECK\s*\()/i', $def)) {
            // SQLite understands these as written.
            $constraints[] = $def;
            continue;
        }

        $translated = harness_translate_column($def);
        if ($translated['autoinc']) {
            $autoCol = preg_split('/\s+/', $def)[0];
        }
        $columns[] = $translated['sql'];
    }

    /* A PRIMARY KEY (id) beside an AUTO_INCREMENT id is now expressed inline and
     * must not be repeated; any other primary key (a composite, or a single
     * non-integer column) is kept as written. */
    if ($deferredPrimary !== null && strcasecmp($deferredPrimary, (string) $autoCol) !== 0) {
        /* SQLITE LETS A PRIMARY KEY COLUMN BE NULL. MySQL does not — it makes
         * every part of a primary key implicitly NOT NULL — and the divergence
         * is one of the quieter ways this harness could claim a constraint
         * works when it doesn't.
         *
         * person_tag_map (person_id, tag_id) and reminder_sends
         * (reminder_id, due_date) both exist to make a rule the DATABASE's job
         * rather than the application's: one tag link per pair, one send per
         * reminder per due date. Under SQLite's rules a NULL on either side
         * slips past the uniqueness check every time, because NULLs never
         * compare equal — so a test asserting "the same send cannot be
         * recorded twice" would pass against a schema that permits exactly
         * that.
         *
         * schema.sql already declares all four of those columns NOT NULL, so
         * today this changes nothing. It is here so that the day somebody adds
         * a composite key and forgets, the harness enforces MySQL's rule
         * instead of silently relaxing it. */
        foreach (harness_key_columns($deferredPrimary) as $pkColumn) {
            foreach ($columns as $i => $column) {
                if (preg_match('/^' . preg_quote($pkColumn, '/') . '\s/i', $column)
                    && !preg_match('/\bNOT\s+NULL\b/i', $column)
                ) {
                    $columns[$i] = preg_replace('/\bNULL\b/i', '', $column) . ' NOT NULL';
                }
            }
        }

        $columns[] = 'PRIMARY KEY (' . $deferredPrimary . ')';
    }

    $create = sprintf(
        "CREATE TABLE IF NOT EXISTS %s (\n  %s\n)",
        $table,
        implode(",\n  ", array_merge($columns, $constraints))
    );

    return array_merge(array($create), $indexes);
}

/** The bare column names out of a `(a, b)` key list. @return string[] */
function harness_key_columns(string $list): array
{
    $names = array();
    foreach (explode(',', $list) as $part) {
        /* Strip a MySQL prefix length — KEY idx_x (name(20)) — and any
         * ASC/DESC, neither of which is part of the name. */
        $name = trim(preg_replace('/\(.*\)|\s+(ASC|DESC)\b/i', '', $part));
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return $names;
}

/**
 * Translate one MySQL QUERY — as opposed to a schema statement — into SQLite.
 *
 * Installed on the connection itself (see HarnessPdo below) rather than being
 * called by tests, because the whole point of the CLI-only override in
 * lib/db.php is that tests run the REAL repo functions through the REAL q().
 * A test that had to hand-translate its own SQL would be testing a query the
 * app does not run.
 *
 * TWO CONSTRUCTS, AND THIS FILE HANDLES NO OTHERS ON PURPOSE. Every rewrite
 * here is a place where the tested query and the shipped query differ, so the
 * list stays as short as the app can manage. If a repo function needs a third
 * MySQL-ism, think about whether it needs it before teaching this.
 *
 *   INSERT IGNORE                -> INSERT OR IGNORE
 *   ON DUPLICATE KEY UPDATE ...  -> ON CONFLICT DO UPDATE SET ...
 *   VALUES(col)  (inside the update clause only)  -> excluded.col
 *
 * The conflict target is deliberately LEFT OFF. SQLite allows that on a
 * trailing DO UPDATE (3.35+) and it is the faithful translation: MySQL's
 * ON DUPLICATE KEY UPDATE fires on a violation of ANY unique constraint, which
 * is exactly what a bare ON CONFLICT does. Naming a target would pick one key
 * and quietly stop testing the others — and reminders has two (its id and
 * uniq_person_type), which is precisely the table where guessing wrong matters.
 */
function harness_translate_query(string $sql): string
{
    if (preg_match('/^\s*INSERT\s+IGNORE\s+/i', $sql)) {
        $sql = preg_replace('/^(\s*)INSERT\s+IGNORE\s+/i', '$1INSERT OR IGNORE ', $sql, 1);
    }

    $sql = preg_replace('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', 'ON CONFLICT DO UPDATE SET', $sql, 1);

    /* MySQL's VALUES(col) means "the value this row tried to insert"; SQLite
     * spells that excluded.col. Rewritten ONLY in the tail after ON CONFLICT —
     * applied to the whole statement it would also mangle a literal
     * `VALUES (name)` in the insert's own value list. */
    $at = stripos($sql, 'ON CONFLICT DO UPDATE SET');
    if ($at !== false) {
        $sql = substr($sql, 0, $at) . preg_replace(
            '/\bVALUES\s*\(\s*([A-Za-z_][A-Za-z0-9_]*)\s*\)/i',
            'excluded.$1',
            substr($sql, $at)
        );
    }

    return $sql;
}

/**
 * A PDO that translates MySQL queries on their way through.
 *
 * Subclassing rather than wrapping so it IS a PDO: lib/db.php's override
 * checks `instanceof PDO`, q() calls prepare() on whatever db() hands back,
 * and repo code must not be able to tell the difference. Anything that reaches
 * the database goes through prepare(), exec() or query(), so those three are
 * the whole surface.
 */
class HarnessPdo extends PDO
{
    public function prepare(string $query, array $options = array()): PDOStatement|false
    {
        return parent::prepare(harness_translate_query($query), $options);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec(harness_translate_query($statement));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return parent::query(harness_translate_query($query), $fetchMode, ...$fetchModeArgs);
    }
}

/** Translate a whole MySQL schema file. @return string[] */
function harness_translate(string $mysql): array
{
    $out = array();
    foreach (harness_split_statements(harness_strip_comments($mysql)) as $statement) {
        foreach (harness_translate_statement($statement) as $translated) {
            $out[] = $translated;
        }
    }
    return $out;
}

/**
 * An in-memory SQLite database with schema.sql loaded, installed as db()'s
 * connection so that q() and every repo function work against it.
 *
 * Foreign keys are ON. SQLite ships with them OFF, which would quietly make
 * every ON DELETE CASCADE from people a no-op and let a test claim a cascade
 * works when nothing was ever enforced. This app cascades five ways off one
 * table, so that is five tests that would all pass for no reason.
 */
function harness_pdo(?string $schemaPath = null): PDO
{
    $schemaPath = $schemaPath ?? dirname(__DIR__) . '/schema.sql';
    $sql = @file_get_contents($schemaPath);
    if ($sql === false) {
        throw new RuntimeException('Cannot read ' . $schemaPath);
    }

    $pdo = new HarnessPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    foreach (harness_translate($sql) as $statement) {
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Statement failed:\n" . $statement . "\n\n" . $e->getMessage(),
                0,
                $e
            );
        }
    }

    $GLOBALS['crm_pdo_override'] = $pdo;
    return $pdo;
}
