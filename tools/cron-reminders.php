<?php
/* The daily job. Reconcile birthdays, find what is due, email about it once.
 *
 *   php tools/cron-reminders.php
 *
 * Once a day, early morning in the configured timezone (PLAN.md §7.2).
 *
 * ---------------------------------------------------------------------------
 * THIS FILE IS BOTH A SCRIPT AND A LIBRARY, DELIBERATELY.
 * ---------------------------------------------------------------------------
 *
 * Hostinger plans differ: some give a real command cron, others only a URL
 * fetch, and tools/ is denied over HTTP. public/cron.php is the wrapper for the
 * second case, and it REQUIRES THIS FILE AND CALLS cron_reminders_run() — the
 * two paths run identical code rather than two copies that drift.
 *
 * So the run happens at the bottom only when this file is the entry point.
 * Required from anywhere else it just declares two functions and does nothing,
 * which is also what makes it testable (tools/tests-delivery.php) and what
 * keeps the tools/.htaccess promise: reachable over HTTP it would still not
 * run, on top of being denied.
 *
 * ---------------------------------------------------------------------------
 * THE SEQUENCE, AND WHY IT IS THIS ORDER (PLAN.md §7.2).
 * ---------------------------------------------------------------------------
 *
 *   $today = crm_today();                    -- ONCE, at the top, and passed down
 *   reminders_reconcile_birthdays($today);   -- 1. the full pass, before anything
 *   foreach (reminders_due($today) ...)      -- 2. WHERE next_due_date <= ?
 *       reminders_claim_send()               -- 3a/3b. the ledger, which is what
 *                                            --        makes a second email
 *                                            --        impossible
 *       send_reminder_email()                -- 3c. and mark sent or failed
 *       reminders_advance_after_send()       -- 3d. which mostly answers "no"
 *
 * RECONCILE FIRST. A birthday added today by any path at all is correct by the
 * time the same run decides what to email about; a reminder that silently never
 * fires is undetectable by the person relying on it, so the app does the work
 * twice (lib/reminders.php, schema.sql).
 *
 * NOT ONE LINE OF REMINDER LOGIC LIVES HERE. All of it is lib/reminders.php's,
 * where it is tested without SMTP and without a clock. This file is the order
 * they happen in, the fail-soft, and the exit code.
 *
 * ---------------------------------------------------------------------------
 * STEP 3d ANSWERS "NO" ALMOST EVERY TIME, AND THAT IS CORRECT.
 * ---------------------------------------------------------------------------
 *
 * A reach-out NEVER advances on send: it stays overdue until you log an actual
 * contact, which is what "overdue" means. A birthday advances only once the
 * birthday has PASSED — the email goes out on the lead date, seven days before,
 * and rolling it forward then would take the birthday off the Today dashboard
 * for the whole week you are supposed to be acting on it.
 *
 * The guard is inside reminders_advance_after_send(), not here, so every caller
 * inherits it. This file just counts the answer.
 *
 * ---------------------------------------------------------------------------
 * FAIL SOFT, LOUDLY.
 * ---------------------------------------------------------------------------
 *
 * One person's broken row, missing person or refused SMTP connection costs that
 * one email and nothing else — the loop catches per row. But the run EXITS
 * NON-ZERO if anything failed, because a cron nobody watches is a cron whose
 * only signal is its exit status, and a failure that reports success is worse
 * than no cron at all. A failed send leaves sent_at NULL and is retried
 * tomorrow; a delivered one is never sent again. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/people.php';
require_once __DIR__ . '/../lib/reminders.php';
require_once __DIR__ . '/../lib/mailer.php';

/**
 * One whole run. Returns the tally; throws nothing it can help throwing.
 *
 * @param string $today Y-m-d, from ONE crm_today() at the entry point.
 * @return array{today: string, reconciled: int, due: int, sent: int, skipped: int, failed: int, advanced: int, errors: array<int, string>}
 */
function cron_reminders_run(string $today): array
{
    $tally = array(
        'today'      => $today,
        'reconciled' => 0,
        'due'        => 0,
        'sent'       => 0,
        'skipped'    => 0,
        'failed'     => 0,
        'advanced'   => 0,
        'errors'     => array(),
    );

    /* 1. The full birthday pass. It already logs and swallows one bad row. */
    $tally['reconciled'] = reminders_reconcile_birthdays($today);

    /* 2. `WHERE next_due_date <= ?`. Overdue rows come too — that is the point
     * of `<=`, and a reach-out that has been overdue for a month has already
     * been emailed about on its own due date, so the ledger will refuse it. */
    $due = reminders_due($today);
    $tally['due'] = count($due);

    /* THE TIMESTAMP FOR THE LEDGER, computed once for the whole run.
     *
     * The DATE half is $today, so a run that crosses midnight cannot stamp a
     * send with a date the rest of the run disagrees about, and so the one
     * clock stays lib/dates.php's. Only the time of day comes from date(), and
     * it is a diagnostic — "when did that email actually go" is a question
     * asked exactly once, in a panic (schema.sql). It is NOT NOW(): the
     * database's idea of the hour is pinned separately in lib/db.php, and this
     * way nothing depends on the two agreeing. */
    $sentAt = $today . ' ' . date('H:i:s');

    foreach ($due as $reminder) {
        $id      = $reminder['id'];
        $dueDate = $reminder['next_due_date'];

        try {
            /* 3a/3b. The one statement that makes a double-send impossible. A
             * previous FAILED attempt still says send; only a delivered one is
             * refused. */
            if (!reminders_claim_send($id, $dueDate)) {
                $tally['skipped']++;
                continue;
            }

            $person = people_get($reminder['person_id']);
            if ($person === null) {
                /* Deleted between the join and here. Nothing to say, and the
                 * cascade has already taken the reminder. */
                $tally['skipped']++;
                continue;
            }

            /* 3c. */
            if (send_reminder_email($person, $reminder['type'], $dueDate, $today)) {
                reminders_mark_sent($id, $dueDate, $sentAt);
                $tally['sent']++;

                /* 3d. False for a reach-out always, and for a birthday until
                 * the birthday itself has passed. Both are the feature. */
                if (reminders_advance_after_send($id, $today)) {
                    $tally['advanced']++;
                }
            } else {
                $why = mailer_last_error();
                reminders_mark_failed($id, $dueDate, $why);
                $tally['failed']++;
                $tally['errors'][] = $person['name'] . ': ' . $why;
            }
        } catch (Throwable $e) {
            /* Anything the loop did not anticipate. The row is left with
             * sent_at NULL, so tomorrow tries again. */
            $tally['failed']++;
            $tally['errors'][] = 'reminder ' . $id . ': ' . $e->getMessage();
            error_log('cron-reminders: reminder ' . $id . ' failed: ' . $e->getMessage());

            try {
                reminders_mark_failed($id, $dueDate, $e->getMessage());
            } catch (Throwable $inner) {
                /* The database itself is unhappy. The exit code still reports
                 * it; there is nowhere else left to write. */
                error_log('cron-reminders: could not record the failure either: ' . $inner->getMessage());
            }
        }
    }

    return $tally;
}

/**
 * The one-line summary. One line because it is what a cron emails you, and a
 * report you skim is a report you read.
 *
 * "skipped" is the healthy number, not a warning: it is every reminder whose
 * email already went out on an earlier day and whose due date has not moved,
 * which for a long-overdue reach-out is every single day thereafter.
 */
function cron_reminders_summary(array $tally): string
{
    return sprintf(
        'cron-reminders %s: %d birthdays reconciled, %d due, %d sent, %d already sent, %d failed, %d advanced',
        $tally['today'],
        $tally['reconciled'],
        $tally['due'],
        $tally['sent'],
        $tally['skipped'],
        $tally['failed'],
        $tally['advanced']
    );
}

/* ------------------------------------------------------------- entry point */

/* Only when this file was the thing invoked. public/cron.php requires it and
 * calls cron_reminders_run() itself; tools/run-tests.php requires it to test
 * the run without sending anything. */
if (PHP_SAPI === 'cli'
    && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__
) {
    $cronToday = crm_today();
    $cronTally = cron_reminders_run($cronToday);

    fwrite(STDOUT, cron_reminders_summary($cronTally) . PHP_EOL);

    foreach ($cronTally['errors'] as $cronError) {
        fwrite(STDERR, '  ' . $cronError . PHP_EOL);
    }

    /* Non-zero so a monitored cron surfaces it. Nothing else in this app has a
     * way to tell you an email did not go. */
    exit($cronTally['failed'] > 0 ? 1 : 0);
}
