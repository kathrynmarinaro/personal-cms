<?php
/* PDO connection, created once per request on first use.
 *
 * Ported from Grocery, which ports it from Book Tracker and the Workout
 * Generator. Fixes here should land in the siblings too.
 *
 * ONE ADDITION over the siblings: the connection's time zone is pinned to the
 * app's configured zone at connect time. See the MYSQL_ATTR_INIT_COMMAND
 * comment below — this app is entirely made of dates, and it cannot afford
 * PHP and MySQL disagreeing about what day it is. */

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    /* THE ONE DEVIATION FROM THE SIBLINGS' db.php.
     *
     * tools/test-harness.php builds an in-memory SQLite database from
     * schema.sql and puts it here, so that tests exercise the REAL repo
     * functions through the real q() rather than a parallel query path that
     * could drift from the shipped one. There is no MySQL in the build
     * environment, so the alternative is testing nothing.
     *
     * Gated on CLI so this is not reachable over HTTP under any
     * circumstances — not even with register_globals-style variable
     * injection, because $GLOBALS is not populated from the request. */
    if (PHP_SAPI === 'cli'
        && isset($GLOBALS['crm_pdo_override'])
        && $GLOBALS['crm_pdo_override'] instanceof PDO
    ) {
        $pdo = $GLOBALS['crm_pdo_override'];
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        cfg('db.host', 'localhost'),
        cfg('db.name'),
        cfg('db.charset', 'utf8mb4')
    );

    /* ONE CLOCK. PHP's default zone is set in lib/bootstrap.php; this is the
     * other half, so MySQL's NOW() and PHP's time() cannot land on different
     * days at midnight. Nothing in this app compares the two directly — every
     * due-date query is `WHERE next_due_date <= ?` with the date computed by
     * crm_today() — but login_attempts throttling does its arithmetic entirely
     * inside SQL, and reminder_sends.sent_at is stamped by NOW(). A skew there
     * is a birthday email that fires on the wrong date with nothing on screen
     * to explain it.
     *
     * A NUMERIC OFFSET, NOT A NAME. Hostinger's MySQL commonly ships with the
     * named time-zone tables (mysql.time_zone_name) unloaded, and
     * `SET time_zone = 'America/Chicago'` then fails at connect time with an
     * unhelpful error — which, as an init command, means the connection itself
     * fails and the whole app 500s. The offset always works.
     *
     * The offset is computed fresh per request, so DST is handled by the fact
     * that a connection never outlives the request that opened it. */
    $offset = (new DateTime('now', new DateTimeZone((string) cfg('timezone', 'UTC'))))->format('P');

    try {
        $pdo = new PDO($dsn, cfg('db.user'), cfg('db.pass'), array(
            PDO::ATTR_ERRMODE              => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE   => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES     => false,
            PDO::MYSQL_ATTR_INIT_COMMAND   => "SET time_zone = '" . $offset . "'",
        ));
    } catch (PDOException $e) {
        // The real reason goes to the log, never to a WEB response — a
        // connection error message can carry the host, database name and user.
        error_log('DB connect failed: ' . $e->getMessage());

        /* On the command line, say it out loud. There is nobody to hide it
         * from: you are already logged into the account, standing in the
         * directory, with config.php open in the next window. Swallowing it
         * here is what turns "your database is named u123456_crm, not
         * personalcrm" into a bare "db_unavailable" and a hunt through error_log
         * for a line you have to know exists.
         *
         * Hostinger is the specific reason this matters. It prefixes both the
         * database name and the username with the account id, so the values in
         * config.example.php are wrong on every real deploy, and the resulting
         * failure is the FIRST thing that happens when you try to import. */
        if (PHP_SAPI === 'cli') {
            fatal_error(
                'db_unavailable',
                'Could not connect to the database: ' . $e->getMessage()
                    . "\n\nCheck the db block in config.php. On Hostinger the database name and"
                    . "\nuser are both prefixed with your account id (u123456_crm), not the"
                    . "\nbare name in config.example.php.",
                500
            );
        }

        fatal_error('db_unavailable', 'The database is unavailable right now.', 500);
    }

    return $pdo;
}

/** Run a query with bound params and return the statement. */
function q(string $sql, array $params = array()): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}
