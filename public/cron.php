<?php
/* The URL-fetch cron, for a plan with no SSH.
 *
 *   https://crm.example.com/cron.php?token=<the long random string>
 *
 * ---------------------------------------------------------------------------
 * THE ONLY PUBLIC SURFACE IN THIS APP (docs/CONTRACTS.md §6).
 * ---------------------------------------------------------------------------
 *
 * Everything else is behind require_login_page() or require_login_api(). This
 * one cannot be: the thing fetching it is a scheduler with no session and no
 * way to acquire one. So it is gated on a long random token instead, and the
 * gate is the only thing standing between the open internet and a run that
 * sends email.
 *
 * THREE THINGS ABOUT THE GATE, ALL OF THEM DELIBERATE:
 *
 *   1. hash_equals(), not ===. String comparison short-circuits on the first
 *      differing byte, which leaks the token one character at a time to
 *      anybody patient enough to time the responses.
 *   2. 404, NOT 403. A 403 confirms the endpoint is there and worth guessing
 *      at; a 404 says the same thing as every other path that does not exist.
 *      The body is deliberately as boring as Apache's own.
 *   3. AN EMPTY TOKEN REFUSES, rather than running unauthenticated. This is the
 *      opposite of the login gate's fail-open, and the difference is that
 *      failing open here does not lock anybody out of anything — the command
 *      cron and the app itself both still work. config.example.php ships the
 *      token empty precisely so that a deploy that never sets one has no
 *      reachable cron rather than a public one.
 *
 * NOT ONE LINE OF LOGIC LIVES HERE. tools/cron-reminders.php declares
 * cron_reminders_run() and only runs itself when it is the entry point, so
 * requiring it below gets the identical code path the command cron takes. Two
 * copies of a job that runs unattended once a day is two copies where only one
 * of them ever gets fixed.
 *
 * tools/ is denied over HTTP by tools/.htaccess, which is what makes this
 * wrapper necessary. That deny is about REQUESTS, not about PHP reading a file
 * from disk, so the require below is unaffected. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../tools/cron-reminders.php';

$configured = (string) cfg('cron.token', '');
$offered    = (string) ($_GET['token'] ?? '');

if ($configured === '' || !hash_equals($configured, $offered)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not Found\n";
    exit;
}

/* Past the gate. Nothing below should ever be indexed or cached — a search
 * engine that found a working URL would run the job on every crawl. */
noindex();
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

/* The summary below carries person names straight out of the database and is
 * NOT run through h(), because this response is text/plain and escaping it
 * would print &amp; at a machine. nosniff is what keeps that true: without it a
 * browser is free to decide the body looks like HTML and render it. */
header('X-Content-Type-Options: nosniff');

/* A cron fetcher hangs up as soon as it has an answer, or sooner if it has a
 * short timeout of its own. Without these, a run that is halfway through the
 * third of five emails is killed mid-connection and the remaining reminders
 * wait a day for no reason. The ledger makes the interrupted one safe to retry;
 * this makes the interruption unlikely in the first place. */
ignore_user_abort(true);
@set_time_limit(300);

$today = crm_today();
$tally = cron_reminders_run($today);

echo cron_reminders_summary($tally), "\n";

foreach ($tally['errors'] as $error) {
    echo '  ', $error, "\n";
}

/* The HTTP equivalent of a non-zero exit code. A URL-fetch cron that reports
 * failures is worth having; Hostinger's scheduler and every uptime monitor
 * understand a 500 and none of them read prose. */
if ($tally['failed'] > 0) {
    http_response_code(500);
}
