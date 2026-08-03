<?php
/* The Today tab — the dashboard, and the screen this app opens on.
 *
 * Who is overdue, who is due today, and who is coming up this week. Reach-outs
 * and birthdays TOGETHER rather than in two lists: the question the screen
 * answers is "who am I forgetting", and which mechanism produced a row is a
 * detail of the answer, not a way to organise it.
 *
 * WHAT WORKS WITH NO JAVASCRIPT AT ALL, which is everything except one
 * shortcut:
 *
 *   * reading it        — the buckets, the counts and the sublines are all
 *                         server-rendered from one query
 *   * opening a profile — every row carries a real <a> to person.php?id=
 *   * logging a contact — the profile has its own control for it
 *
 * What the script adds is the 1-tap "Logged today" button on the row itself,
 * which is the difference between clearing the morning's list in four taps and
 * in four page loads. It is rendered disabled-looking-but-real and wired up by
 * assets/dashboard.js; with the script blocked the row is still a link to a
 * screen that can do the same job.
 *
 * THE DASHBOARD IS ALLOWED TO ACCUMULATE. A reach-out reminder does not move
 * when the cron emails about it — only logging an actual contact moves it
 * (PLAN.md §7.2) — so an overdue row stays here, and the number of days on it
 * keeps climbing, until you have spoken to somebody. That is the app working.
 *
 * A next_due_date in the past is likewise not a bug: the query is
 * `<= today + a week`, so an overdue reminder surfaces immediately instead of
 * being skipped for a year. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/people.php';
require_once __DIR__ . '/../lib/reminders.php';

require_login_page();

/* Once, at the top, and passed down. lib/dates.php's header explains why this
 * is the only place on this screen allowed to ask what day it is — asking twice
 * is how a dashboard rendered at 23:59:59.9 disagrees with itself. */
$today = crm_today();

try {
    $buckets = reminders_dashboard($today);
} catch (Throwable $e) {
    error_log('index: dashboard read failed: ' . $e->getMessage());
    fatal_error('dashboard_read_failed', 'Today’s list could not be loaded.', 500);
}

/* The three buckets in the order they are read, with the heading each one
 * gets. Overdue first: it is the part of the screen that is actually asking
 * something of you, and it is also the part that grows. */
$sections = array(
    'overdue' => 'Overdue',
    'today'   => 'Today',
    'week'    => 'This week',
);

$total = 0;
foreach ($buckets as $rows) {
    $total += count($rows);
}

page_head('Today', 'today');
screen_head('Today', page_menu());
?>

  <div id="dashboard" data-today="<?= h($today) ?>">

    <?php /* Decided by the server and left alone by the script: a logged row is
             struck through where it is rather than removed (see
             assets/dashboard.js), so the list never empties mid-tap and this
             never has to appear out of nothing. The link is the honest next
             step — nothing due does not mean nothing to do. */ ?>
    <div class="empty<?= $total > 0 ? ' hidden' : '' ?>" id="dashboard-empty">
      <p>Nobody to reach out to this week.</p>
      <p class="row"><a class="btn-secondary" href="people.php">Browse everybody</a></p>
    </div>

<?php foreach ($sections as $key => $heading): ?>
<?php $rows = $buckets[$key]; ?>
    <?php /* Every bucket is rendered and the empty ones hidden, so the script
             can move a row's group — or hide a heading that has just been
             emptied — without inventing a section mid-tap. NOT .is-sticky: two
             or three short groups never scroll far enough for a pinned heading
             to earn the space it takes (docs/CONTRACTS.md §4). */ ?>
    <section class="cat-group<?= $rows === array() ? ' hidden' : '' ?>" data-bucket="<?= h($key) ?>">
      <h2 class="cat-head"><?= h($heading) ?> <span class="cat-count"><?= count($rows) ?></span></h2>
      <ul class="list">
<?php foreach ($rows as $reminder): ?>
<?php
        $isBirthday = $reminder['type'] === REMINDER_BIRTHDAY;
        $when       = fmt_relative_due($reminder['next_due_date'], $today);

        if ($isBirthday) {
            /* The date that matters on a birthday row is the BIRTHDAY, not the
             * reminder's own due date — the row exists a week early on purpose,
             * and "Today" against a birthday next Tuesday would read as a
             * mistake. people_birthday_label() reassembles the three columns and
             * adds the age when the year is known; it returns '' for a person
             * whose birthday has gone missing since the reminder was written,
             * and that one row degrades to the due phrasing rather than
             * emptying the screen. */
            $label = people_birthday_label($reminder, $today);
            if ($label === '') {
                $sub = 'Birthday · ' . $when;
            } else {
                /* "in 12 days", not fmt_relative_due()'s phrasing. A birthday
                 * is always at least the lead days away from its own reminder,
                 * so the relative form is nearly always the calendar date —
                 * which the label has just said. The countdown is the fact the
                 * date does not carry. days_since() counts it, rather than a
                 * subtraction written here for the third time. */
                $away = days_since(
                    $today,
                    next_birthday((int) $reminder['birth_month'], (int) $reminder['birth_day'], $today)
                );
                $sub = 'Birthday ' . $label . ' · ' . match (true) {
                    $away === null => $when,
                    $away === 0    => 'today',
                    $away === 1    => 'tomorrow',
                    default        => 'in ' . $away . ' days',
                };
            }
        } else {
            /* Two different facts, and both are wanted: when this fell due, and
             * how long it has actually been. For a cadence they move together;
             * for a one-off, and for anybody you have never spoken to, they do
             * not. */
            $sub = $when . ' · ' . people_contact_label($reminder['last_contact_date'], $today);
        }
?>
        <?php /* data-id is the REMINDER's id, not the person's: one person can
                 be on this screen twice, once for a birthday and once for a
                 reach-out, and the row is what the script acts on. The person id
                 rides along for the endpoints, which take that. */ ?>
        <li class="list-row" data-id="<?= (int) $reminder['id'] ?>"
            data-person="<?= (int) $reminder['person_id'] ?>"
            data-type="<?= h($reminder['type']) ?>">
          <div class="row-slide">
<?php if (!$isBirthday): ?>
            <?php /* The 1-tap log, on reach-out rows ONLY. Logging a contact is
                     what satisfies a reach-out and takes the row off this list;
                     it does not satisfy a birthday, which stands until the
                     birthday itself has been and gone. A checkbox that ticks and
                     changes nothing is worse than no checkbox.

                     A <button aria-pressed>, not an <input> — docs/CONTRACTS.md
                     §4. There is no swipe on this screen: a person is never
                     swipe-deletable (CLAUDE.md) and neither is a reminder you
                     did not mean to touch. */ ?>
            <button class="row-check" type="button" aria-pressed="false"
                    aria-label="Log a contact with <?= h($reminder['person_name']) ?> today"></button>
<?php endif; ?>
            <div class="row-body">
              <span class="row-text"><?= h($reminder['person_name']) ?></span>
              <span class="row-sub"><?= h($sub) ?></span>
            </div>
            <?php /* The row's real link and its own 48px target. The script
                     makes the whole row tappable by following this href, so with
                     the script the chevron is a hint and without it it is the
                     control. */ ?>
            <a class="row-link" href="person.php?id=<?= (int) $reminder['person_id'] ?>"
               aria-label="Open <?= h($reminder['person_name']) ?>">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
            </a>
          </div>
        </li>
<?php endforeach; ?>
      </ul>
    </section>
<?php endforeach; ?>
  </div>

  <?php /* logout.php is POST-only on purpose — a link would let any
           <img src="logout.php"> sign you out — so assets/menu.js submits this
           form. Every screen carries one; there is no other way to sign out. */ ?>
  <form id="logout-form" method="post" action="logout.php" class="hidden"></form>

  <?php /* type="module" is also what defers it: the list is fully rendered and
           readable before a byte of this runs. */ ?>
  <script type="module" src="<?= asset('assets/dashboard.js') ?>"></script>

<?php
page_foot('today');
