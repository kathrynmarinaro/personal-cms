<?php
/* The Add tab — one person, typed in by hand.
 *
 * The far-right tab, and deliberately not the busy one: adding somebody is a
 * destination rather than a habit (lib/layout.php). Importing a contacts file
 * is NOT here — it is its own screen behind the hamburger, because burying it
 * inside Add would make this screen two unrelated things wearing one name.
 *
 * WHAT WORKS WITH NO JAVASCRIPT: all of it. This is a real <form> that POSTs to
 * itself, and on success answers with a 303 to the new profile so the back
 * button and a pull-to-refresh cannot add the same person twice. The script
 * upgrades the submit to fetch() so that a failed save leaves the typing on
 * screen instead of on the previous page.
 *
 * THE DUPLICATE SPEED BUMP, and the rule it obeys. schema.sql leaves name_key
 * deliberately non-unique: two people really can be called James Smith, and a
 * duplicate is FLAGGED, never refused. So the first submission of a name
 * somebody already has comes back with the form intact and a line saying so —
 * and the second one goes through, because by then it has been said out loud
 * and answered. It never refuses twice. That is the same rule the import's
 * duplicate pill follows, arrived at from the other direction.
 *
 * No tags on this form. The tag picker lives on the profile, which is where
 * this redirects to anyway, and a second tag UI here would be a second thing to
 * keep in step with the sheet for no gain — the first thing you see after
 * adding somebody is the screen with the picker on it. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/people.php';

require_login_page();

$today = crm_today();

/* What the form shows on a re-render: whatever was typed, so nothing is lost.
 * Null means a blank form. */
$draft      = null;
$error      = '';
$duplicates = array();

/* Not guarded by require_same_origin() — see the same note in person.php. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $name     = people_clean_name((string) ($_POST['name'] ?? ''));
    $birthday = people_clean_birthday(
        $_POST['birth_year'] ?? null,
        $_POST['birth_month'] ?? null,
        $_POST['birth_day'] ?? null,
        $today
    );

    $draft = array(
        'name'        => (string) ($_POST['name'] ?? ''),
        'birth_year'  => $birthday['birth_year'],
        'birth_month' => $birthday['birth_month'],
        'birth_day'   => $birthday['birth_day'],
        'address'     => people_clean_address((string) ($_POST['address'] ?? '')),
        'phone'       => people_clean_phone((string) ($_POST['phone'] ?? '')),
        'email'       => people_clean_email((string) ($_POST['email'] ?? '')),
        'notes'       => people_clean_notes((string) ($_POST['notes'] ?? '')),
    );

    $confirmed = ($_POST['confirm_duplicate'] ?? '') !== '';

    if ($name === null) {
        $error = 'A person needs a name.';
    } else {
        $duplicates = $confirmed ? array() : people_same_name(people_name_key($name));

        if ($duplicates !== array()) {
            $error = 'You already have somebody called ' . $name . '. Add them again?';
        } else {
            $draft['name'] = $name;
            try {
                $newId = people_add($draft, $today);
            } catch (Throwable $e) {
                error_log('add: insert failed: ' . $e->getMessage());
                fatal_error('person_add_failed', 'That person could not be added.', 500);
            }
            header('Location: person.php?id=' . $newId, true, 303);
            exit;
        }
    }
}

page_head('Add', 'add');
screen_head('Add someone', page_menu());
?>

  <section class="card">
    <form class="stack" id="add-form" method="post" action="add.php">
      <?php /* Flipped to 1 by the duplicate branch above, and by assets/add.js
               when the endpoint answers 409. Either way the SECOND submission
               carries it and goes through — the flag never refuses twice. */ ?>
      <input type="hidden" name="confirm_duplicate" id="add-confirm-duplicate"
             value="<?= $duplicates === array() ? '' : '1' ?>">

<?php people_form_fields($draft); ?>

      <?php /* role="alert" so a screen reader is told about the duplicate rather
               than having to find it, and it stays in the DOM either way so
               assets/add.js can fill it without building a paragraph. */ ?>
      <p class="field-err<?= $error === '' ? ' hidden' : '' ?>" id="add-error" role="alert"><?= h($error) ?></p>

<?php if ($duplicates !== array()): ?>
      <?php /* Who they already are, with a link, because the useful answer to
               "you already have an Alex Chen" is usually "oh — that one". */ ?>
      <ul class="list" id="add-duplicates">
<?php foreach ($duplicates as $existing): ?>
        <li class="list-row" data-id="<?= (int) $existing['id'] ?>">
          <div class="row-slide">
            <div class="row-body">
              <span class="row-text"><?= h($existing['name']) ?></span>
              <span class="row-sub"><?= h(people_contact_label($existing['last_contact_date'], $today)) ?></span>
            </div>
            <a class="row-link" href="person.php?id=<?= (int) $existing['id'] ?>"
               aria-label="Open the person you already have, <?= h($existing['name']) ?>">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
            </a>
          </div>
        </li>
<?php endforeach; ?>
      </ul>
<?php endif; ?>

      <button class="btn-primary" type="submit" id="add-submit">
        <?= $duplicates === array() ? 'Add person' : 'Add them anyway' ?>
      </button>
    </form>
  </section>

  <form id="logout-form" method="post" action="logout.php" class="hidden"></form>

  <script type="module" src="<?= asset('assets/add.js') ?>"></script>

<?php
page_foot('add');
