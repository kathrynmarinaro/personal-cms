<?php
/* Every piece of date arithmetic in this app, in one file, as pure functions.
 *
 * ---------------------------------------------------------------------------
 * THE RULE: crm_today() IS THE ONLY FUNCTION HERE THAT ASKS WHAT DAY IT IS.
 * ---------------------------------------------------------------------------
 *
 * Everything else takes a $today parameter. Nothing else in the app anywhere
 * may call date(), time(), 'now' or NOW() to decide a due date.
 *
 * Why it is worth being this strict. lib/auth.php carries a comment about a
 * sibling app whose lockout silently never fired: PHP ran in UTC while MySQL
 * ran in CDT, strtotime() read MySQL's local-time string as UTC, and the unlock
 * time came out five hours in the past. Grocery could contain that lesson
 * inside one throttling query. This app cannot — "due today", "seven days
 * before a birthday", "last contact plus sixty days" and "which day did the
 * cron run on" ARE the product. A one-day skew is not subtle here; it is
 * birthday cards arriving late, every time, with nothing on screen to explain
 * it.
 *
 * One reading of "today", taken once per request or cron run, passed down, and
 * compared against DATE columns that carry no time component at all. That is
 * what makes every due query `WHERE next_due_date <= ?` — index-friendly,
 * portable, free of DATE_ADD and INTERVAL, and exercisable by the SQLite test
 * harness. It is also what makes this file testable at all: five of these six
 * functions need no clock, no database and no config.
 *
 * ---------------------------------------------------------------------------
 * ALL ARITHMETIC HAPPENS IN UTC, DELIBERATELY, AND THAT IS NOT A CONTRADICTION.
 * ---------------------------------------------------------------------------
 *
 * The strings these functions handle are plain calendar dates. They carry no
 * time of day and no zone, so there is nothing to convert — but doing the
 * arithmetic in a zone that observes DST breaks it anyway:
 *
 *     (new DateTimeImmutable('2026-03-08'))->diff(new DateTimeImmutable('2026-03-09'))->days
 *
 * is 0, not 1, in America/Chicago, because those two midnights are 23 hours
 * apart and ->days floors. That would make a reminder due "today" for two days
 * running, once a year, in March. Parsing in UTC makes every day exactly 86400
 * seconds long and the counting exact.
 *
 * crm_today() is where the configured zone is applied, and it is the only
 * place it needs to be.
 */

declare(strict_types=1);

/**
 * Today, as Y-m-d, in the app's configured timezone.
 *
 * THE ONLY IMPURE FUNCTION IN THIS FILE. Call it once at the top of a request
 * or a cron run and pass the string down; calling it twice in one run is how a
 * dashboard rendered at 23:59:59.9 ends up disagreeing with itself.
 *
 * It reads PHP's default timezone rather than cfg('timezone') again, on
 * purpose. lib/bootstrap.php sets that default from the config value as its
 * very first act, and lib/db.php pins MySQL's connection to the same zone.
 * Re-reading the config here would be a second, independent interpretation of
 * the same setting — one more place for the app's idea of "today" to fork.
 */
function crm_today(): string
{
    return date('Y-m-d');
}

/**
 * Parse a Y-m-d date into a UTC midnight. Internal to this file.
 *
 * Tolerates a trailing time, so a DATETIME straight out of contact_log
 * (`2026-04-15 09:30:00`) can be handed to days_since() without every caller
 * remembering to slice it. Returns null for anything it cannot read, so the
 * callers can degrade one row rather than throwing a screen away.
 */
function crm_parse_date(?string $date): ?DateTimeImmutable
{
    if ($date === null || strlen($date) < 10) {
        return null;
    }

    /* '!' resets the time to 00:00:00 — without it, createFromFormat fills the
     * unspecified time from the CURRENT clock, which makes a "pure" function
     * return different answers depending on when you run it. */
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10), new DateTimeZone('UTC'));

    /* createFromFormat happily rolls 2026-02-30 forward into March rather than
     * failing, so the round trip is the actual validity check. */
    if ($parsed === false || $parsed->format('Y-m-d') !== substr($date, 0, 10)) {
        return null;
    }

    return $parsed;
}

/**
 * Whole days from $from to $to. Negative when $to is earlier. Internal.
 *
 * Both sides are UTC midnights, so this is exact integer division and no DST
 * transition can round it to the wrong side. See the file header.
 */
function crm_days_between(DateTimeImmutable $from, DateTimeImmutable $to): int
{
    return (int) (($to->getTimestamp() - $from->getTimestamp()) / 86400);
}

/**
 * The next occurrence of a month/day, on or after $today.
 *
 * FEBRUARY 29 BECOMES FEBRUARY 28 IN A NON-LEAP YEAR. A leap-day birthday has
 * no occurrence in three years out of four and something has to be chosen; the
 * alternative is March 1, which means the card arrives in the wrong month. The
 * reminder for a Feb 29 birthday therefore fires on Feb 21 in an ordinary
 * year. This is a decision, not a rounding artifact — see CLAUDE.md.
 *
 * The clamp is written generally (to the last day of whatever month) rather
 * than special-casing 2/29, because a vCard can carry a `BDAY:--0631` and the
 * import has no business crashing a dashboard six months later.
 *
 * "On or after" matters: on somebody's actual birthday, the next occurrence is
 * today. A `>` here would silently skip the whole point of the app once a year
 * per person.
 *
 * @param int    $month 1-12
 * @param int    $day   1-31
 * @param string $today Y-m-d
 * @return string Y-m-d
 */
function next_birthday(int $month, int $day, string $today): string
{
    $from = crm_parse_date($today);
    if ($from === null || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
        return $today;
    }

    $year = (int) $from->format('Y');

    /* Only two candidates are ever possible: this year's occurrence and next
     * year's. A loop over a range would suggest otherwise. */
    for ($y = $year; $y <= $year + 1; $y++) {
        $candidate = crm_clamped_date($y, $month, $day);
        if ($candidate >= $today) {
            return $candidate;
        }
    }

    /* Unreachable for a valid month/day — next year's occurrence is always in
     * the future — but returning $today rather than falling off the end means a
     * corrupt row shows up as "due now" instead of as a TypeError. */
    return $today;
}

/**
 * A calendar date with the day clamped to the end of the month. Internal.
 * 2027-02-29 becomes 2027-02-28; 2026-06-31 becomes 2026-06-30.
 */
function crm_clamped_date(int $year, int $month, int $day): string
{
    $first = DateTimeImmutable::createFromFormat(
        '!Y-n-j',
        $year . '-' . $month . '-1',
        new DateTimeZone('UTC')
    );
    if ($first === false) {
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    $lastDay = (int) $first->format('t');
    return sprintf('%04d-%02d-%02d', $year, $month, min($day, $lastDay));
}

/**
 * When to be reminded about a birthday: the next occurrence, minus $lead days.
 *
 * FIND THE BIRTHDAY FIRST, THEN SUBTRACT. NEVER THE OTHER WAY AROUND.
 *
 * This is the one function in the app where a plausible-looking implementation
 * is off by nearly a year. A January 3rd birthday with a 7-day lead reminds on
 * December 27th — of the PREVIOUS year. Written backwards, as "the next
 * occurrence of (birthday minus 7 days)", asking on January 2nd gives the next
 * December 27th, which is 358 days late and looks approximately right in a
 * spot check because it is still, technically, seven days before a birthday.
 *
 * The returned date CAN BE IN THE PAST relative to $today, and that is correct:
 * if today is January 2nd and the birthday is January 3rd, the lead date passed
 * six days ago and the reminder is overdue. The dashboard and the cron both
 * read this as `next_due_date <= today`, so an overdue reminder shows and
 * sends immediately rather than being skipped for a year.
 *
 * @param int    $lead Days of warning. cfg('reminders.birthday_lead_days'),
 *                     read in one place so the cron and the dashboard cannot
 *                     disagree about it.
 * @return string Y-m-d
 */
function birthday_reminder_date(int $month, int $day, string $today, int $lead): string
{
    $birthday = crm_parse_date(next_birthday($month, $day, $today));
    if ($birthday === null) {
        return $today;
    }

    return $birthday->modify('-' . max(0, $lead) . ' days')->format('Y-m-d');
}

/**
 * When the next reach-out is due for a cadence of $days.
 *
 * From the last contact when there was one, and from TODAY when there wasn't.
 *
 * Not from created_at, and emphatically not from the epoch: a contact imported
 * from a phone book has never been spoken to and has no history, so counting
 * from anywhere in the past would make every one of four hundred imported
 * people overdue on the morning after an import. The dashboard would open with
 * four hundred rows on it and be closed again immediately.
 *
 * Starting the clock today says the honest thing instead — "I'll ask you about
 * this person in sixty days" — and the first logged contact moves it onto the
 * real cadence.
 *
 * @param string|null $lastContact people.last_contact_date, or null for never.
 * @return string Y-m-d
 */
function next_cadence_date(?string $lastContact, int $days, string $today): string
{
    $from = crm_parse_date($lastContact) ?? crm_parse_date($today);
    if ($from === null) {
        return $today;
    }

    return $from->modify('+' . max(1, $days) . ' days')->format('Y-m-d');
}

/**
 * Whole days from $date to $today. NULL in, null out.
 *
 * The People list's secondary line: "last contacted 34 days ago", or "never"
 * when this returns null. Null is a real answer and the caller must render it
 * as one — a 0 here would read as "contacted today", which is the opposite of
 * the truth about somebody you have never spoken to.
 *
 * A future date gives a NEGATIVE number rather than 0 or an absolute value.
 * Nothing in the app should be producing one, so making it visible beats
 * quietly rounding it into something that looks reasonable.
 */
function days_since(?string $date, string $today): ?int
{
    $then = crm_parse_date($date);
    $now  = crm_parse_date($today);
    if ($then === null || $now === null) {
        return null;
    }

    return crm_days_between($then, $now);
}

/**
 * A due date phrased the way a person would say it.
 *
 *   Today · Tomorrow · Yesterday · 3 days ago · Friday · April 15
 *
 * Overdue dates stay in days ("34 days ago") however far back they go, rather
 * than switching to a calendar date like the future side does. That asymmetry
 * is deliberate: how overdue something is, is the information. A reach-out
 * reminder deliberately does not advance until you log a contact (see
 * CLAUDE.md), so the dashboard is allowed to accumulate, and "34 days ago"
 * carries the weight that "March 2" does not.
 *
 * Inside the coming week the weekday name is more useful than a date — you
 * know whether you can call somebody on Friday without counting. Beyond that a
 * weekday name is ambiguous, so it becomes a date.
 */
function fmt_relative_due(string $due, string $today): string
{
    $dueDate   = crm_parse_date($due);
    $todayDate = crm_parse_date($today);
    if ($dueDate === null || $todayDate === null) {
        return $due;
    }

    $diff = crm_days_between($todayDate, $dueDate);

    if ($diff === 0) {
        return 'Today';
    }
    if ($diff === 1) {
        return 'Tomorrow';
    }
    if ($diff === -1) {
        return 'Yesterday';
    }
    if ($diff < -1) {
        return abs($diff) . ' days ago';
    }
    if ($diff <= 6) {
        return $dueDate->format('l');
    }

    /* No year. Within a rolling year "April 15" is unambiguous, and the year
     * on a due date reads as an error rather than as information. */
    return $dueDate->format('F j');
}
