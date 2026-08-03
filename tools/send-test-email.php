<?php
/* Prove SMTP works, before anything depends on it.
 *
 *   php tools/send-test-email.php
 *
 * ---------------------------------------------------------------------------
 * WHY THIS SCRIPT EXISTS AT ALL.
 * ---------------------------------------------------------------------------
 *
 * The cron runs at six in the morning while you are asleep and its only output
 * is an exit code. If SMTP is misconfigured you find out by NOT getting a
 * birthday reminder — which looks exactly like not having set the birthday, or
 * like the app being fine and you having forgotten. There is nothing on any
 * screen that can tell you the difference.
 *
 * So this sends one real message through the real mailer, over the real
 * connection, to the real address, and says out loud what happened. Run it once
 * after deploying and once after ever touching the smtp block.
 *
 * ---------------------------------------------------------------------------
 * THE ASSERTION THAT MATTERS MOST IS THE ONE THAT DOES NOT SEND ANYTHING.
 * ---------------------------------------------------------------------------
 *
 * from_email MUST equal user. Gmail rewrites or outright rejects a From address
 * it has not authorized, and the bounce is silent from this app's point of
 * view: the send succeeds, the ledger records a delivery, and the mail never
 * arrives. It is the single most common way this setup fails, and it is the one
 * failure that looks like success from every angle inside the app. Checked
 * before the connection is opened, and named in the output either way — a check
 * that is silent when it passes is a check you do not know ran. */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    /* tools/.htaccess denies this directory over HTTP; this is the second lock,
     * and it needs no Apache modules. A hash oracle and a mail cannon are
     * exactly the two things in tools/ worth locking twice. */
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/mailer.php';

$user = trim((string) cfg('smtp.user', ''));
$from = trim((string) cfg('smtp.from_email', ''));
$pass = (string) cfg('smtp.pass', '');

printf("SMTP settings from config.php\n");
printf("  host        %s:%d (%s)\n", (string) cfg('smtp.host', ''), (int) cfg('smtp.port', 0), (string) cfg('smtp.secure', ''));
printf("  user        %s\n", $user);
printf("  password    %s\n", $pass === '' ? '(empty)' : ($pass === 'CHANGE_ME' ? 'CHANGE_ME — not set' : str_repeat('*', 12)));
printf("  from        %s <%s>\n", (string) cfg('smtp.from_name', ''), $from);
printf("  to          %s\n", (string) cfg('smtp.to', ''));
printf("\n");

/* Said explicitly on the way past, whichever way it goes. */
if (strcasecmp($from, $user) === 0 && $from !== '') {
    printf("  OK   from_email matches user\n");
}

$problem = mailer_config_problem();
if ($problem !== null) {
    fwrite(STDERR, "\nNOT SENDING — the config is wrong:\n\n  " . $problem . "\n\n");
    fwrite(STDERR, "Fix the smtp block in config.php and run this again. See DEPLOY.txt, step 3.\n");
    exit(1);
}

/* A real message through the real path, so what is proved is the thing that
 * runs at six in the morning — not a simplified version of it that shares no
 * code with it. */
$stamp = crm_today() . ' ' . date('H:i:s');

printf("Sending a test message to %s ...\n", (string) cfg('smtp.to', ''));

$ok = mailer_send(
    'Personal CRM — test message (' . $stamp . ')',
    "This is tools/send-test-email.php.\n\n"
        . "If you are reading it on your phone, SMTP works and the reminder cron\n"
        . "has somewhere to send to. Sent " . $stamp . ".\n",
    '<div style="font:16px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;color:#222">'
        . '<p>This is <code>tools/send-test-email.php</code>.</p>'
        . '<p>If you are reading it on your phone, SMTP works and the reminder cron '
        . 'has somewhere to send to. Sent ' . h($stamp) . '.</p></div>'
);

if (!$ok) {
    fwrite(STDERR, "\nFAILED: " . mailer_last_error() . "\n\n");
    fwrite(STDERR, "The usual causes, in the order they happen:\n");
    fwrite(STDERR, "  * smtp.pass is the ACCOUNT password. It has to be an app password —\n");
    fwrite(STDERR, "    Google stopped accepting account passwords for SMTP in 2022.\n");
    fwrite(STDERR, "  * two-factor authentication is off on the Google account, so there is\n");
    fwrite(STDERR, "    no app-password screen to generate one from.\n");
    fwrite(STDERR, "  * the host blocks outbound port 587. Try 'ssl' on port 465 instead.\n");
    exit(1);
}

printf("\nSent. Now go and look at %s — on the phone, not in a browser.\n", (string) cfg('smtp.to', ''));
printf("If it is not there in a minute, check spam: the first message from a new\n");
printf("sender is the one most likely to be filed there, and every one after it\n");
printf("inherits whatever you do with it.\n");
exit(0);
