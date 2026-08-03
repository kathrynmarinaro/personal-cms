<?php
/* The People tab — the reference book. Everyone, grouped by relationship tag.
 *
 * WHAT WORKS WITH NO JAVASCRIPT AT ALL, and it is the whole screen:
 *
 *   * reading it        — the groups, the counts and the "last contacted"
 *                         sublines are all server-rendered
 *   * opening a profile — every row carries a real <a> to person.php?id=
 *   * searching         — the box is a real GET <form>, so submitting it
 *                         reloads this page with ?q= and the server does the
 *                         filtering (people_list()'s LIKE against name_key)
 *
 * What the script adds is filtering as you type without a round trip, and
 * renaming a tag by tapping its heading. Both are upgrades; neither is the only
 * way to do anything.
 *
 * THE ONE THING TO UNDERSTAND about the search: the server has already applied
 * ?q= by the time this renders, so the rows the script filters are a subset of
 * everyone. Narrowing further is free; BROADENING is not, because the rows that
 * would come back are not in the page. assets/people.js watches for that and
 * navigates instead. See the note there.
 *
 * A person with several tags appears under each of them, which is the point of
 * tags rather than a category — being both a Colleague and a Close Friend is a
 * normal thing to be. A person with none appears under Untagged, which is
 * always the last group. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/people.php';

require_login_page();

/* Once, at the top, and passed down. lib/dates.php's header explains why this
 * is the only place on this screen allowed to ask. */
$today  = crm_today();
$search = trim((string) ($_GET['q'] ?? ''));

try {
    $groups = people_grouped($search === '' ? null : $search);
} catch (Throwable $e) {
    error_log('people: list failed: ' . $e->getMessage());
    fatal_error('people_read_failed', 'The people list could not be loaded.', 500);
}

$total = 0;
foreach ($groups as $group) {
    $total += count($group['people']);
}

/* Whether there is anybody at all, as opposed to nobody matching the search.
 * Two different empty states: one says "add somebody", the other says "no
 * matches", and showing the wrong one is how a search for a typo reads as an
 * app that lost its database. Only asked when the search found nothing, so the
 * ordinary load is still one query. */
$anyone = $total > 0 || ($search !== '' && people_list() !== array());

page_head('People', 'people');
screen_head('People', page_menu());
?>

  <?php /* A real GET form. type="search" rather than "text", unlike the sibling
           app's quick-add box: this genuinely is a search, so the clear button
           iOS puts inside a search field is wanted rather than in the way.
           enterkeyhint makes the phone keyboard's blue key say what it does. */ ?>
  <form class="composer" id="people-search-form" method="get" action="people.php"
        data-server-query="<?= h($search) ?>">
    <input
      class="composer-input"
      type="search"
      name="q"
      id="people-search"
      value="<?= h($search) ?>"
      placeholder="Search people"
      aria-label="Search people by name, email or phone"
      autocomplete="off"
      autocorrect="off"
      autocapitalize="off"
      spellcheck="false"
      enterkeyhint="search">
    <button class="composer-add" type="submit">Search</button>
  </form>

  <div id="people-list">
    <?php /* Both empty states live in the DOM and are toggled, so the script can
             show the "no matches" one without having to build a paragraph out of
             nothing mid-keystroke. */ ?>
    <p class="empty<?= $anyone ? ' hidden' : '' ?>" id="people-empty">
      Nobody here yet. Add your first person from the Add tab.
    </p>
    <p class="empty<?= $anyone && $total === 0 ? '' : ' hidden' ?>" id="people-no-matches">
      Nobody matches that.
    </p>

<?php foreach ($groups as $group): ?>
    <?php /* data-id is the tag's id, and the Untagged group has none — which is
             also what assets/people.js vetoes the rename on. Untagged is not a
             tag: there is no row, so there is nothing to rename. */ ?>
    <section class="cat-group"<?= $group['id'] === null ? '' : ' data-id="' . (int) $group['id'] . '"' ?>>
      <h2 class="cat-head is-sticky">
<?php if ($group['id'] === null): ?>
        <span><?= h($group['name']) ?></span>
<?php else: ?>
        <?php /* A heading that is itself a control, per docs/CONTRACTS.md §4:
                 tapping it opens the inline editor and renames the tag. The
                 button is here with no JS too, where it does nothing — which is
                 why it is not the only way to fix a tag name (retyping one on a
                 profile creates the corrected tag and moves the person to it). */ ?>
        <button type="button" aria-label="Rename the tag <?= h($group['name']) ?>"><?= h($group['name']) ?></button>
<?php endif; ?>
        <span class="cat-count"><?= count($group['people']) ?></span>
      </h2>
      <ul class="list">
<?php foreach ($group['people'] as $person): ?>
        <?php
        /* One lowercase haystack per row, built server-side out of the same
         * normalized key the SQL search uses, so the live filter and a
         * submitted search agree about what "matches" means. Building it in the
         * script instead would mean re-implementing people_name_key() in
         * JavaScript, and the two would drift the first time either changed. */
        $haystack = trim($person['name_key'] . ' ' . mb_strtolower((string) $person['email'], 'UTF-8')
            . ' ' . (string) $person['phone']);
        ?>
        <li class="list-row" data-id="<?= (int) $person['id'] ?>" data-search="<?= h($haystack) ?>">
          <div class="row-slide">
            <?php /* .row-body so the "last contacted" line is a SIBLING of
                     .row-text rather than inside it — docs/CONTRACTS.md §4. */ ?>
            <div class="row-body">
              <span class="row-text"><?= h($person['name']) ?></span>
              <span class="row-sub"><?= h(people_contact_label($person['last_contact_date'], $today)) ?></span>
            </div>
            <?php /* The row's real link, and its own 48px target. assets/people.js
                     makes the whole row tappable by following this href, so with
                     the script the chevron is a hint and without it it is the
                     control. There is no swipe on a person and no inline editor
                     on this list, so nothing else competes for the tap. */ ?>
            <a class="row-link" href="person.php?id=<?= (int) $person['id'] ?>"
               aria-label="Open <?= h($person['name']) ?>">
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
           usable before a byte of this runs. */ ?>
  <script type="module" src="<?= asset('assets/people.js') ?>"></script>

<?php
page_foot('people');
