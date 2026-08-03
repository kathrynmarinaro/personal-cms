<?php
/* Outgoing email: the one thing this app does that you are not looking at.
 *
 * ---------------------------------------------------------------------------
 * ONE FUNCTION IS THE CONTRACT (PLAN.md §7.1).
 * ---------------------------------------------------------------------------
 *
 *   send_reminder_email(array $person, string $type, string $dueDate): bool
 *
 * Everything else in this file exists to build that message or to explain why
 * it did not go. Nothing outside this file knows that PHPMailer exists, which
 * is the point: swapping the transport later — for a hand-rolled SMTP client,
 * for an API — touches this file and nothing else.
 *
 * ---------------------------------------------------------------------------
 * WHY SMTP AND NOT mail().
 * ---------------------------------------------------------------------------
 *
 * A message pushed through mail() from a shared host arrives in spam often
 * enough that you would stop trusting the reminders, and a reminder you do not
 * trust is worse than no reminder: you still get the mail, you just stop
 * reading it. The brief asks for SMTP for exactly this reason.
 *
 * PHPMailer is VENDORED AS THREE PLAIN FILES in lib/vendor/PHPMailer/, required
 * directly below. No Composer, no autoloader, no build step — they FTP up with
 * everything else, which is what the suite's no-build-step rule is actually
 * about (not needing a toolchain, rather than not using anyone else's code).
 * Those three files are third-party and are kept byte-identical to the upstream
 * release; do not restyle them to match the house rules, and do not "fix" them.
 *
 * ---------------------------------------------------------------------------
 * THE FAILURE THIS FILE IS MOST AFRAID OF.
 * ---------------------------------------------------------------------------
 *
 * FROM_EMAIL THAT DOES NOT MATCH USER. Gmail rewrites or outright rejects a
 * From address it has not authorized, and the bounce is silent from this app's
 * point of view: the send "succeeds", the ledger records a delivery, and the
 * mail never arrives. Nothing on any screen would ever say so. It is checked
 * before the connection is opened (mailer_config_problem()), it is asserted by
 * tools/send-test-email.php, and it is in DEPLOY.txt in capitals.
 *
 * ---------------------------------------------------------------------------
 * THE CLOCK.
 * ---------------------------------------------------------------------------
 *
 * Every function here takes $today, exactly as lib/reminders.php does and for
 * the same reason — "next Tuesday" and "62 days ago" are what the subject line
 * SAYS, so a one-day skew is a lie printed on a lock screen.
 *
 * send_reminder_email() takes it as an OPTIONAL fourth argument, so that the
 * three-argument contract above still holds and the one crm_today() in this
 * file is the fallback nobody should reach. The cron passes its own $today, and
 * the tests pass a fixed one. */

declare(strict_types=1);

require_once __DIR__ . '/contact.php';

/* Order matters and there is no autoloader: PHPMailer.php and SMTP.php both
 * refer to Exception, and SMTP.php is instantiated from inside PHPMailer.php. */
require_once __DIR__ . '/vendor/PHPMailer/Exception.php';
require_once __DIR__ . '/vendor/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/vendor/PHPMailer/SMTP.php';

/* How many gift ideas go in a birthday email. The email is a nudge, not the
 * screen — if there are fifteen ideas, five of them plus "and 10 more" is what
 * fits on a lock-screen preview and still gets you to open the profile. */
const MAILER_GIFT_LIMIT = 5;

/* Placeholders config.example.php ships with. Sending with either of these in
 * place is a guaranteed authentication failure, and catching it here turns a
 * mystery SMTP error into a sentence naming the key. */
const MAILER_PLACEHOLDER = 'CHANGE_ME';

/**
 * Why the last send failed, for the caller's ledger row. '' when nothing has.
 *
 * A module-level static rather than an exception or a richer return type,
 * because the contracted signature is `: bool` and the cron needs the reason
 * for reminders_mark_failed(). Read it immediately after a false; it is
 * overwritten by the next send.
 */
function mailer_last_error(?string $set = null): string
{
    static $last = '';
    if ($set !== null) {
        $last = $set;
    }
    return $last;
}

/* ================================================================== config ==*/

/**
 * The first thing wrong with the smtp config block, or null if nothing is.
 *
 * Checked BEFORE a connection is opened, so the common misconfigurations report
 * themselves as a sentence instead of as a timeout or a 535. Every one of these
 * has exactly one cause and exactly one fix, so each message names both.
 */
function mailer_config_problem(): ?string
{
    $user = trim((string) cfg('smtp.user', ''));
    $from = trim((string) cfg('smtp.from_email', ''));
    $to   = trim((string) cfg('smtp.to', ''));
    $pass = (string) cfg('smtp.pass', '');
    $host = trim((string) cfg('smtp.host', ''));

    if ($host === '') {
        return 'smtp.host is empty in config.php';
    }
    if ($user === '' || $user === MAILER_PLACEHOLDER) {
        return 'smtp.user is not set in config.php';
    }
    if ($pass === '' || $pass === MAILER_PLACEHOLDER) {
        return 'smtp.pass is not set in config.php — it must be a Gmail APP PASSWORD,'
            . ' not the account password';
    }
    if ($to === '' || $to === MAILER_PLACEHOLDER) {
        return 'smtp.to is not set in config.php — there is nobody to send reminders to';
    }

    /* THE ONE THAT FAILS SILENTLY. Everything above produces a visible error;
     * this produces a successful-looking send and no email. */
    if (strcasecmp($from, $user) !== 0) {
        return 'smtp.from_email (' . $from . ') does not match smtp.user (' . $user . ').'
            . ' Gmail rewrites or rejects a From it has not authorized and the bounce is'
            . ' silent — the send appears to succeed and the mail never arrives.';
    }

    return null;
}

/**
 * Where a profile lives on the public internet, for the link in the email.
 *
 * There is no base-URL key in config.example.php, so this works three ways and
 * degrades rather than refusing to send:
 *
 *   1. cfg('app_url') if somebody has added one — the only reliable answer for
 *      a command cron, which has no request and therefore no host name.
 *   2. The current request's own scheme and host, which is exactly right for
 *      the public/cron.php path: the URL the cron fetched IS the app's URL.
 *   3. A bare relative path, so the email still names the screen even when it
 *      cannot link to it. An email with no link is worth more than no email.
 *
 * DEPLOY.txt asks for (1). Adding 'app_url' to config.example.php is a
 * Foundation change and is reported rather than made here.
 */
function mailer_profile_url(int $personId): string
{
    $path = 'person.php?id=' . $personId;

    $base = trim((string) cfg('app_url', ''));
    if ($base !== '') {
        return rtrim($base, '/') . '/' . $path;
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host !== '' && PHP_SAPI !== 'cli') {
        $scheme = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            ? 'https'
            : 'http';
        $dir = rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        return $scheme . '://' . $host . $dir . '/' . $path;
    }

    return $path;
}

/* ================================================================ phrasing ==*/

/**
 * When something falls, said the way a person says it, for a SUBJECT LINE.
 *
 *   "today" · "tomorrow" · "next Wednesday" · "in 12 days" · "9 days ago"
 *
 * Deliberately NOT fmt_relative_due(), which is the on-screen phrasing and
 * capitalises for a list row ("Today", "Friday", "April 15"). A subject line
 * reads as a sentence and lands mid-phrase — "Birthday: Alex Chen — April 15
 * (April 15)" is what fmt_relative_due() produces at the default seven-day
 * lead, because seven days out is where it stops naming weekdays. This is the
 * one place in the app that needs a different register, so it gets its own
 * four-line function rather than a flag on the shared one.
 */
function mailer_when_phrase(string $date, string $today): string
{
    $days = days_since($today, $date);   // positive = in the future
    if ($days === null) {
        return $date;
    }

    if ($days === 0) {
        return 'today';
    }
    if ($days === 1) {
        return 'tomorrow';
    }
    if ($days === -1) {
        return 'yesterday';
    }
    if ($days < 0) {
        return abs($days) . ' days ago';
    }
    if ($days <= 7) {
        $parsed = crm_parse_date($date);
        return $parsed === null ? 'in ' . $days . ' days' : 'next ' . $parsed->format('l');
    }

    return 'in ' . $days . ' days';
}

/** "last contacted 62 days ago", or "never contacted" — and never "0 days". */
function mailer_contact_phrase(?string $lastContact, string $today): string
{
    $days = days_since($lastContact, $today);
    if ($days === null) {
        return 'never contacted';
    }
    if ($days === 0) {
        return 'last contacted today';
    }
    if ($days === 1) {
        return 'last contacted yesterday';
    }
    return 'last contacted ' . $days . ' days ago';
}

/**
 * The birthday this reminder is about, as Y-m-d.
 *
 * Found from the reminder's own DUE date and not from today, for the same
 * reason reminders_advance_after_send() does it: next_birthday() answers "on or
 * after", so asking it from today would name next year's birthday on any day
 * after this year's. The due date is the lead date, which sits before the
 * birthday it was written for, whichever day the cron actually runs.
 */
function mailer_birthday_date(array $person, string $dueDate): ?string
{
    $month = $person['birth_month'] ?? null;
    $day   = $person['birth_day'] ?? null;
    if ($month === null || $day === null) {
        return null;
    }

    return next_birthday((int) $month, (int) $day, $dueDate);
}

/**
 * THE SUBJECT LINE, which is the whole user experience here (PLAN.md §7.3).
 *
 * This arrives on a phone lock screen and is very often the only part that is
 * ever read, so it carries the name, the thing, and when — in that order,
 * because a truncated subject must still say who it is about.
 *
 *   Birthday: Alex Chen — April 15 (next Wednesday)
 *   Time to reach out to Alex Chen — last contacted 62 days ago
 */
function mailer_subject(array $person, string $type, string $dueDate, string $today): string
{
    $name = (string) ($person['name'] ?? 'Someone');

    if ($type === REMINDER_BIRTHDAY) {
        $birthday = mailer_birthday_date($person, $dueDate);
        if ($birthday === null) {
            /* No birthday on the person any more — the reminder is about to be
             * reconciled away. Say something true rather than "on ". */
            return 'Birthday: ' . $name;
        }
        return 'Birthday: ' . $name . ' — ' . fmt_date($birthday, 'F j')
            . ' (' . mailer_when_phrase($birthday, $today) . ')';
    }

    return 'Time to reach out to ' . $name . ' — '
        . mailer_contact_phrase($person['last_contact_date'] ?? null, $today);
}

/* ================================================================== bodies ==*/

/**
 * The pieces of the body, assembled once and rendered twice.
 *
 * Notes, gift ideas and a link, and NOTHING ELSE (PLAN.md §7.3). The email is a
 * nudge with enough context to act on without opening anything; everything else
 * is one tap away and stays there.
 *
 * GIFT IDEAS ONLY ON A BIRTHDAY, because that is when you want them. On a
 * reach-out they are noise, and worse, they are the wrong prompt: the reminder
 * is to talk to somebody, not to buy them something.
 *
 * @return array{headline: string, notes: string, gifts: array<int, string>, more: int, url: string}
 */
function mailer_body_parts(array $person, string $type, string $dueDate, string $today): array
{
    $personId = (int) ($person['id'] ?? 0);

    if ($type === REMINDER_BIRTHDAY) {
        $birthday = mailer_birthday_date($person, $dueDate);
        $headline = $birthday === null
            ? 'A birthday is coming up.'
            : fmt_date($birthday, 'F j') . ' — ' . mailer_when_phrase($birthday, $today) . '.';
    } else {
        $headline = ucfirst(mailer_contact_phrase($person['last_contact_date'] ?? null, $today)) . '.';
    }

    $gifts = array();
    $more  = 0;
    if ($type === REMINDER_BIRTHDAY && $personId > 0) {
        /* Fail soft: a broken gift-ideas read must cost the list, not the
         * email. The birthday is the thing that has to arrive. */
        try {
            $all = gifts_for_person($personId);
            foreach ($all as $gift) {
                $gifts[] = (string) $gift['idea_text'];
            }
            $more  = max(0, count($gifts) - MAILER_GIFT_LIMIT);
            $gifts = array_slice($gifts, 0, MAILER_GIFT_LIMIT);
        } catch (Throwable $e) {
            error_log('mailer: gift ideas unavailable for person ' . $personId . ': ' . $e->getMessage());
        }
    }

    return array(
        'headline' => $headline,
        'notes'    => trim((string) ($person['notes'] ?? '')),
        'gifts'    => $gifts,
        'more'     => $more,
        'url'      => mailer_profile_url($personId),
    );
}

/** The plain-text part. The one that actually gets read on a watch. */
function mailer_body_text(array $person, string $type, string $dueDate, string $today): string
{
    $parts = mailer_body_parts($person, $type, $dueDate, $today);
    $name  = (string) ($person['name'] ?? 'Someone');

    $lines = array($name, $parts['headline'], '');

    if ($parts['notes'] !== '') {
        $lines[] = 'Notes';
        $lines[] = $parts['notes'];
        $lines[] = '';
    }

    if ($parts['gifts'] !== array()) {
        $lines[] = 'Gift ideas';
        foreach ($parts['gifts'] as $gift) {
            $lines[] = '  - ' . $gift;
        }
        if ($parts['more'] > 0) {
            $lines[] = '  ... and ' . $parts['more'] . ' more';
        }
        $lines[] = '';
    }

    $lines[] = $parts['url'];

    return implode("\n", $lines) . "\n";
}

/**
 * The HTML part: the same words, in a readable size, and nothing else.
 *
 * No layout, no images, no tracking, no web fonts. An email client is the one
 * rendering engine nobody can test against, and this only has to be legible.
 * The inline styles here are the exception the no-inline-style rule allows —
 * there is no stylesheet in an email, and styles.css is not involved.
 *
 * Everything from the database goes through h(), same as every template.
 */
function mailer_body_html(array $person, string $type, string $dueDate, string $today): string
{
    $parts = mailer_body_parts($person, $type, $dueDate, $today);
    $name  = (string) ($person['name'] ?? 'Someone');

    $html = '<div style="font:16px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;color:#222">'
        . '<h1 style="font-size:20px;margin:0 0 4px">' . h($name) . '</h1>'
        . '<p style="margin:0 0 16px;color:#555">' . h($parts['headline']) . '</p>';

    if ($parts['notes'] !== '') {
        $html .= '<h2 style="font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:#777;margin:16px 0 4px">Notes</h2>'
            /* nl2br over an escaped string, never the other way round. */
            . '<p style="margin:0">' . nl2br(h($parts['notes'])) . '</p>';
    }

    if ($parts['gifts'] !== array()) {
        $html .= '<h2 style="font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:#777;margin:16px 0 4px">Gift ideas</h2><ul style="margin:0;padding-left:20px">';
        foreach ($parts['gifts'] as $gift) {
            $html .= '<li>' . h($gift) . '</li>';
        }
        if ($parts['more'] > 0) {
            $html .= '<li style="color:#777">and ' . (int) $parts['more'] . ' more</li>';
        }
        $html .= '</ul>';
    }

    $html .= '<p style="margin:20px 0 0"><a href="' . h($parts['url']) . '">Open ' . h($name) . '</a></p>'
        . '</div>';

    return $html;
}

/* ================================================================ delivery ==*/

/**
 * Hand one message to the SMTP server. The only place PHPMailer is touched.
 *
 * Returns false and records mailer_last_error() rather than throwing: a cron
 * run sends several emails and one refused connection must not cost the rest of
 * them (CLAUDE.md, fail soft). The caller writes the reason beside the ledger
 * row, so tomorrow's retry has something to read.
 *
 * SMTPDebug stays off. Its output goes to stdout, which for the URL-fetch cron
 * is the HTTP response body — the whole SMTP conversation, app password
 * included, served to whoever fetched the URL.
 */
function mailer_send(string $subject, string $textBody, string $htmlBody): bool
{
    $problem = mailer_config_problem();
    if ($problem !== null) {
        mailer_last_error($problem);
        error_log('mailer: ' . $problem);
        return false;
    }

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = (string) cfg('smtp.host', 'smtp.gmail.com');
        $mail->Port       = (int) cfg('smtp.port', 587);
        $mail->SMTPAuth   = true;
        $mail->Username   = (string) cfg('smtp.user', '');
        $mail->Password   = (string) cfg('smtp.pass', '');

        /* 'tls' is STARTTLS on 587; 'ssl' is implicit TLS on 465. Anything else
         * in config becomes STARTTLS rather than plaintext — a typo in this key
         * must not quietly send the app password in the clear. */
        $mail->SMTPSecure = ((string) cfg('smtp.secure', 'tls')) === 'ssl'
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

        /* A cron that hangs on a dead SMTP host holds the run open until PHP's
         * own limit kills it, which on the URL-fetch path means a request that
         * never returns and a "did it run?" nobody can answer. Fail in a
         * quarter of a minute and let tomorrow retry — the ledger row is what
         * makes that safe. */
        $mail->Timeout = 15;

        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64;

        $mail->setFrom((string) cfg('smtp.from_email', ''), (string) cfg('smtp.from_name', 'Personal CRM'));
        $mail->addAddress((string) cfg('smtp.to', ''));

        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $htmlBody;
        $mail->AltBody = $textBody;

        $mail->send();
        mailer_last_error('');
        return true;
    } catch (Throwable $e) {
        /* PHPMailer's ErrorInfo is the useful half — it carries the server's
         * own refusal ("Username and Password not accepted") where the
         * exception message is often just "SMTP Error: Could not authenticate". */
        $why = trim($mail->ErrorInfo) !== '' ? $mail->ErrorInfo : $e->getMessage();
        mailer_last_error($why);
        error_log('mailer: send failed: ' . $why);
        return false;
    }
}

/**
 * ===================== THE FUNCTION THE CRON CALLS ==========================
 *
 *   send_reminder_email(array $person, string $type, string $dueDate): bool
 *
 * $person is a people row as people_get() returns it — id, name, notes, the
 * three birthday columns and last_contact_date are the parts used.
 * $type is REMINDER_BIRTHDAY or REMINDER_REACH_OUT.
 * $dueDate is the reminder's next_due_date, which for a birthday is the LEAD
 * date and is what names the birthday being talked about.
 * ============================================================================
 *
 * True means the SMTP server accepted the message. It does not mean it was
 * delivered, and nothing in this app can know that — which is why
 * mailer_config_problem() refuses the one misconfiguration that produces an
 * accepted message nobody receives.
 *
 * $today is optional only so that the three-argument contract in PLAN.md §7.1
 * still holds. Pass it. The cron does, from its single crm_today().
 */
function send_reminder_email(array $person, string $type, string $dueDate, ?string $today = null): bool
{
    $today = $today ?? crm_today();

    $subject = mailer_subject($person, $type, $dueDate, $today);

    return mailer_send(
        $subject,
        mailer_body_text($person, $type, $dueDate, $today),
        mailer_body_html($person, $type, $dueDate, $today)
    );
}
