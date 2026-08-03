<?php
/* The person profile, reached as person.php?id=.
 *
 * =============================================================================
 *  THIS FILE IS SHARED. READ THIS BEFORE EDITING IT.
 * =============================================================================
 *
 * docs/CONTRACTS.md §1: four tracks write into this one screen. P owns the file
 * and the skeleton; three other tracks each fill exactly one marked region and
 * touch nothing outside it. The markers are literal and the order is fixed:
 *
 *     identity  (P)  ->  tags  (P)  ->  reminders  (R)
 *                                   ->  gifts      (I)
 *                                   ->  log        (I)
 *                                   ->  danger     (P)
 *
 * Every region is delimited by a matched pair of HTML comments naming its
 * owner. Add markup INSIDE your region. Do not reorder them, do not nest them,
 * and do not add a region without adding it to CONTRACTS first — three agents
 * are editing this file in parallel and the markers are the whole reason that
 * is possible.
 *
 * The danger region is P's and is deliberately BELOW the log: deleting a person
 * is the last thing on the screen because it is the last thing you should be
 * able to reach for.
 *
 * assets/person.js carries the same six markers in the same order for the
 * attach* calls.
 *
 * -----------------------------------------------------------------------------
 *
 * WHAT WORKS WITH NO JAVASCRIPT AT ALL, which is everything on this screen:
 *
 *   * reading the profile
 *   * calling and emailing   — .row-link is a real tel:/mailto:
 *   * editing every identity field, including a birthday with no year
 *                            — the pencil is a LINK to ?edit=1, which renders a
 *                              real form that POSTs back here
 *   * adding and removing a tag, and creating a custom one
 *                            — a <select> and a .composer, both real forms
 *   * deleting the person    — a LINK to ?delete=1, which renders the confirm
 *
 * The script replaces the tag <select> with the .sheet picker and turns the
 * delete link into a confirm-and-fetch. It does NOT intercept the ?edit=1 link:
 * seven fields including two textareas is a page, not a bottom sheet, and on a
 * phone a sheet that tall is a form you scroll inside a thing that also scrolls.
 *
 * DELETING A PERSON IS A TWO-STEP CONFIRMATION AND NOT A SWIPE. CLAUDE.md and
 * PLAN.md §10 both say why: a person carries notes, gift ideas and years of
 * contact history, and swipe.js's undo window is five seconds long. The confirm
 * is a rendered page rather than a JS confirm() so that it exists without the
 * script too — a destructive action that loses its guard when a module fails to
 * load is worse than no guard, because nothing about the screen looks different.
 *
 * Like the sibling app's screens, this handles its own POSTs and answers with a
 * 303 rather than rendering the result, so the back button and a pull-to-refresh
 * cannot offer to save the same edit twice. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/people.php';

require_login_page();

$today = crm_today();
$id    = (int) ($_GET['id'] ?? 0);

/* The no-JS write path.
 *
 * NOT guarded by require_same_origin(): a plain browser form cannot set the
 * X-Requested-With header, so the check would 403 exactly the case this branch
 * exists for. What protects it instead is the session cookie's SameSite=Lax
 * (lib/auth.php) — a cross-site POST arrives without it, so require_login_page()
 * above has already bounced it to the login screen. Same reasoning, same shape,
 * as the sibling app's tabs. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $back   = 'person.php?id=' . $id;

    if ($id <= 0 || people_get($id) === null) {
        header('Location: people.php', true, 303);
        exit;
    }

    try {
        switch ($action) {
            case 'save':
                $name = people_clean_name((string) ($_POST['name'] ?? ''));
                if ($name === null) {
                    /* An emptied name is not a delete — delete is its own
                     * confirmed action further down the page. Go back to the
                     * form rather than silently dropping the whole edit. */
                    $back = 'person.php?id=' . $id . '&edit=1';
                    break;
                }
                $birthday = people_clean_birthday(
                    $_POST['birth_year'] ?? null,
                    $_POST['birth_month'] ?? null,
                    $_POST['birth_day'] ?? null,
                    $today
                );
                people_save($id, array(
                    'name'        => $name,
                    'birth_year'  => $birthday['birth_year'],
                    'birth_month' => $birthday['birth_month'],
                    'birth_day'   => $birthday['birth_day'],
                    'address'     => people_clean_address((string) ($_POST['address'] ?? '')),
                    'phone'       => people_clean_phone((string) ($_POST['phone'] ?? '')),
                    'email'       => people_clean_email((string) ($_POST['email'] ?? '')),
                    'notes'       => people_clean_notes((string) ($_POST['notes'] ?? '')),
                ), $today);
                break;

            case 'tag-assign':
                $tagId = (int) ($_POST['tag_id'] ?? 0);
                if ($tagId > 0) {
                    people_assign_tag($id, $tagId);
                }
                break;

            case 'tag-unassign':
                $tagId = (int) ($_POST['tag_id'] ?? 0);
                if ($tagId > 0) {
                    people_unassign_tag($id, $tagId);
                }
                break;

            case 'tag-create':
                /* Creating and assigning in one submission, because that is what
                 * typing a name into a box on somebody's profile means. A tag
                 * that already exists under that name is reused rather than
                 * refused — see tags_add(). */
                $tag = tags_add((string) ($_POST['name'] ?? ''));
                if ($tag !== null) {
                    people_assign_tag($id, $tag['id']);
                }
                break;

            case 'delete':
                people_delete($id);
                /* Back to the list, not to a profile that no longer exists. */
                header('Location: people.php', true, 303);
                exit;
        }
    } catch (Throwable $e) {
        error_log('person: ' . $action . ' failed: ' . $e->getMessage());
        fatal_error('person_write_failed', 'That change could not be saved.', 500);
    }

    header('Location: ' . $back, true, 303);
    exit;
}

$person = $id > 0 ? people_get($id) : null;
if ($person === null) {
    /* A stale bookmark, a back button onto somebody just deleted, a hand-typed
     * id. There is nothing to fix and nothing was lost, so this is the list with
     * a 404 rather than an error screen. */
    http_response_code(404);
    page_head('Not found', 'people');
    screen_head('Not found', page_menu());
    ?>
  <p class="empty">That person isn’t here any more.</p>
  <p class="row"><a class="btn-secondary" href="people.php">Back to People</a></p>
  <form id="logout-form" method="post" action="logout.php" class="hidden"></form>
  <script type="module" src="<?= asset('assets/person.js') ?>"></script>
<?php
    page_foot('people');
    exit;
}

$editing   = ($_GET['edit'] ?? '') !== '';
$confirming = ($_GET['delete'] ?? '') !== '';

$personTags = people_tags($id);
$allTags    = tags_all();

/* Tag ids this person already has, for the picker's "already assigned" state
 * and for the <select>, which only offers the ones they don't. */
$heldTagIds = array();
foreach ($personTags as $tag) {
    $heldTagIds[] = $tag['id'];
}

/* The tags they don't have, which is all the no-JS <select> should offer —
 * "add a tag you already have" is a control that does nothing. */
$availableTags = array();
foreach ($allTags as $tag) {
    if (!in_array($tag['id'], $heldTagIds, true)) {
        $availableTags[] = $tag;
    }
}

$birthday = people_birthday_label($person, $today);
$tel      = people_tel($person['phone']);
$mailto   = people_mailto($person['email']);

/* The pencil sits in the heading row beside the hamburger, where the thumb
 * already is. Not rendered while the form is open — there is nowhere to go. */
$editButton = $editing || $confirming ? '' :
    '<a class="icon-btn" href="person.php?id=' . $id . '&amp;edit=1" aria-label="Edit details">'
    . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4L18 10l-4-4L4 16v4zM14 6l4 4"/></svg></a>';

page_head($person['name'], 'people');
screen_head($person['name'], $editButton . page_menu());
?>

<?php if ($confirming): ?>
  <?php /* The confirmation, rendered in place of the whole profile so there is
           exactly one thing on screen to decide about. Everything it lists is a
           real cascade in schema.sql — saying "and their notes" while quietly
           also dropping the contact log would make the confirm a lie. */ ?>
  <section class="card" id="person-confirm-delete">
    <p>Delete <strong><?= h($person['name']) ?></strong>?</p>
    <p class="hint">
      This also deletes their notes, their gift ideas, their whole contact
      history and any reminders you have set. It cannot be undone.
    </p>
    <form method="post" action="person.php?id=<?= $id ?>">
      <button class="btn-danger" type="submit" name="action" value="delete">Yes, delete this person</button>
    </form>
    <hr class="rule">
    <a class="btn-secondary" href="person.php?id=<?= $id ?>">Keep them</a>
  </section>
<?php else: ?>

  <!-- REGION: identity — owned by P -->
<?php if ($editing): ?>
  <section class="card" id="person-edit">
    <form class="stack" id="person-form" method="post" action="person.php?id=<?= $id ?>" data-id="<?= $id ?>">
      <input type="hidden" name="action" value="save">
<?php people_form_fields($person); ?>
      <div class="row">
        <button class="btn-primary" type="submit">Save</button>
        <a class="btn-secondary" href="person.php?id=<?= $id ?>">Cancel</a>
      </div>
      <p class="field-err hidden" id="person-form-err" role="alert"></p>
    </form>
  </section>
<?php else: ?>
  <?php /* One fact per row, value over label. The .list primitive rather than a
           definition list: it is already the app's row, it already gives every
           .row-link its own 48px target, and it costs no new CSS. Nothing here
           is swipeable and nothing is inline-editable — the pencil in the
           heading is the one way to change any of it, so a tap on a row is
           never ambiguous. */ ?>
  <ul class="list" id="person-facts">
    <li class="list-row">
      <div class="row-slide">
        <div class="row-body">
          <span class="row-text"><?= h(people_contact_label($person['last_contact_date'], $today)) ?></span>
          <span class="row-sub">Contact</span>
        </div>
      </div>
    </li>
<?php if ($birthday !== ''): ?>
    <li class="list-row">
      <div class="row-slide">
        <div class="row-body">
          <span class="row-text"><?= h($birthday) ?></span>
          <span class="row-sub">Birthday</span>
        </div>
      </div>
    </li>
<?php endif; ?>
<?php if ($person['phone'] !== null): ?>
    <li class="list-row">
      <div class="row-slide">
        <div class="row-body">
          <span class="row-text"><?= h($person['phone']) ?></span>
          <span class="row-sub">Phone</span>
        </div>
<?php if ($tel !== null): ?>
        <a class="row-link" href="<?= h($tel) ?>" aria-label="Call <?= h($person['name']) ?>">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h4l2 5-3 2a12 12 0 0 0 5 5l2-3 5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 4 5a2 2 0 0 1 2-2z"/></svg>
        </a>
<?php endif; ?>
      </div>
    </li>
<?php endif; ?>
<?php if ($person['email'] !== null): ?>
    <li class="list-row">
      <div class="row-slide">
        <div class="row-body">
          <span class="row-text"><?= h($person['email']) ?></span>
          <span class="row-sub">Email</span>
        </div>
<?php if ($mailto !== null): ?>
        <a class="row-link" href="<?= h($mailto) ?>" aria-label="Email <?= h($person['name']) ?>">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18v12H3zM3 6l9 7 9-7"/></svg>
        </a>
<?php endif; ?>
      </div>
    </li>
<?php endif; ?>
<?php if ($person['address'] !== null): ?>
    <li class="list-row">
      <div class="row-slide">
        <div class="row-body">
          <span class="row-text"><?= h($person['address']) ?></span>
          <span class="row-sub">Address</span>
        </div>
      </div>
    </li>
<?php endif; ?>
  </ul>

<?php if ($person['notes'] !== null): ?>
  <?php /* Notes get their own card rather than a row: they are paragraphs, and
           a .row-text is one line that ellipsises. white-space is not set here
           — the stylesheet is Foundation-owned — so the newlines render as
           spaces, which is the honest limit of the components available. */ ?>
  <section class="card" id="person-notes">
    <p class="hint">Notes</p>
    <p><?= h($person['notes']) ?></p>
  </section>
<?php endif; ?>
<?php endif; ?>
  <!-- END REGION: identity -->

  <!-- REGION: tags — owned by P -->
  <section class="card" id="person-tags" data-id="<?= $id ?>">
    <div class="row-between">
      <p class="hint">Tags</p>
      <?php /* Rendered hidden and unhidden by assets/person.js, so a browser
               with no script never sees a button that opens nothing. The
               <select> below is the other half of the same swap. */ ?>
      <button class="link-btn hidden" type="button" id="person-tag-edit">Edit tags</button>
    </div>

    <div class="row" id="person-tag-pills">
<?php if ($personTags === array()): ?>
      <span class="muted" id="person-tag-none">No tags yet</span>
<?php endif; ?>
<?php foreach ($personTags as $tag): ?>
      <span class="pill" data-id="<?= (int) $tag['id'] ?>"><?= h($tag['name']) ?></span>
<?php endforeach; ?>
    </div>

    <?php /* The no-JS tag controls. One <select> to add a tag they don't have,
             one Remove button per tag they do. assets/person.js hides this whole
             block and puts the .sheet picker behind "Edit tags" instead — the
             sheet is a toggle, so it does both jobs in one control, which is
             worth having but is not worth being the ONLY way to tag somebody. */ ?>
    <div id="person-tag-fallback">
      <hr class="rule">
<?php if ($availableTags !== array()): ?>
      <form class="stack" method="post" action="person.php?id=<?= $id ?>">
        <input type="hidden" name="action" value="tag-assign">
        <label class="field">
          <span>Add a tag</span>
          <select name="tag_id">
<?php foreach ($availableTags as $tag): ?>
            <option value="<?= (int) $tag['id'] ?>"><?= h($tag['name']) ?></option>
<?php endforeach; ?>
          </select>
        </label>
        <button class="btn-secondary" type="submit">Add tag</button>
      </form>
<?php endif; ?>
<?php foreach ($personTags as $tag): ?>
      <form class="row-between" method="post" action="person.php?id=<?= $id ?>">
        <input type="hidden" name="action" value="tag-unassign">
        <input type="hidden" name="tag_id" value="<?= (int) $tag['id'] ?>">
        <span class="pill"><?= h($tag['name']) ?></span>
        <button class="tap-text danger" type="submit">Remove</button>
      </form>
<?php endforeach; ?>
    </div>

    <?php /* A custom tag, created and assigned in one submission. The house
             quick-add composer, so Enter on the phone keyboard submits it and it
             degrades to a plain POST with no script at all. */ ?>
    <form class="composer" id="person-tag-composer" method="post" action="person.php?id=<?= $id ?>">
      <input type="hidden" name="action" value="tag-create">
      <input
        class="composer-input"
        type="text"
        name="name"
        id="person-tag-new"
        placeholder="New tag"
        aria-label="Create a new relationship tag and add it"
        autocomplete="off"
        autocorrect="off"
        autocapitalize="words"
        spellcheck="false"
        enterkeyhint="done"
        maxlength="<?= TAG_NAME_MAX ?>">
      <button class="composer-add" type="submit">Add</button>
    </form>
  </section>

  <?php /* Every tag in the app, as data rather than as strings baked into the
           script: tags are a table, custom ones are added at runtime, and the
           picker has to show what is actually there. A JSON <script> because
           there is no build step to import a module from. */ ?>
  <script type="application/json" id="person-tags-data"><?= json_encode(
      array('all' => $allTags, 'held' => $heldTagIds),
      JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
  ) ?></script>
  <!-- END REGION: tags -->

  <!-- REGION: reminders — owned by R -->
<?php
  /* Required HERE rather than beside the others at the top of the file, because
   * the top of the file is P's and docs/CONTRACTS.md §1 gives R this region and
   * nothing else. It is already loaded in practice — lib/people.php requires it
   * for the birthday hook — and require_once makes leaning on that unnecessary
   * rather than merely lucky. */
  require_once __DIR__ . '/../lib/reminders.php';

  $reminders       = reminders_for_person($id);
  $reachOut        = $reminders[REMINDER_REACH_OUT] ?? null;
  $birthdayRemind  = $reminders[REMINDER_BIRTHDAY] ?? null;
?>
  <?php /* SETTING a reminder needs the script; READING one does not.
           Everything else on this screen degrades to a plain form, and this
           deliberately does not: public/person.php's POST handler is P's, so
           there is no action for a no-script form here to post, and inventing a
           second write path for one card would be a pattern nobody else follows.
           So the state is server-rendered and the controls are rendered hidden
           and unhidden by assets/person.js — the same swap P uses for "Edit
           tags", and for the same reason: a button that opens nothing is worse
           than no button. */ ?>
  <section class="card" id="person-reminders" data-id="<?= $id ?>"
           data-default-cadence="<?= (int) reminders_default_cadence() ?>"
           data-today="<?= h($today) ?>">
    <div class="row-between">
      <p class="hint">Reach out</p>
      <button class="link-btn hidden" type="button" id="person-reminder-edit">Change</button>
    </div>

    <?php /* One line, and it says which of the three states this person is in.
             A cadence and a one-off are different things and must not read the
             same: "Every 60 days" is a promise about the future, "Once" is a
             single appointment that disappears when it is kept. */ ?>
    <p id="person-reminder-state">
<?php if ($reachOut === null): ?>
      <span class="muted">No reach-out reminder</span>
<?php else: ?>
      <?= h(reminders_label($reachOut, $today)) ?>
<?php endif; ?>
    </p>

    <?php /* The one-off date picker, revealed by the sheet's "On a date" option
             and hidden again on cancel. A real <input type="date"> so the phone
             offers its own wheel — there is no date component in the stylesheet
             and writing one would be a new component, which docs/CONTRACTS.md
             says to report rather than add. */ ?>
    <form class="stack hidden" id="person-reminder-date-form">
      <label class="field">
        <span>Remind me on</span>
        <input type="date" id="person-reminder-date" value="<?= h($today) ?>">
      </label>
      <div class="row">
        <button class="btn-primary" type="submit">Set reminder</button>
        <button class="btn-secondary" type="button" id="person-reminder-date-cancel">Cancel</button>
      </div>
    </form>

    <hr class="rule">

    <?php /* The birthday reminder is READ-ONLY here on purpose. It is
             materialized from the three birth_* columns and reconciled by
             people_save() and again by every cron run (PLAN.md §4.5), so a
             control that edited this row would be silently corrected within a
             day. The way to change it is the pencil at the top of the screen.
             Saying so out loud is what stops it looking like a missing feature. */ ?>
<?php if ($birthdayRemind !== null): ?>
    <p class="hint">
      Birthday reminder <?= h(fmt_date($birthdayRemind['next_due_date'], 'F j')) ?> —
      <?= (int) reminders_lead_days() ?> days before. It follows the birthday above.
    </p>
<?php else: ?>
    <p class="hint">No birthday recorded, so there is no birthday reminder.</p>
<?php endif; ?>
  </section>

  <?php /* What the picker needs to open on the right option, as data rather
           than as values scraped back out of the sentence above. A JSON
           <script> because there is no build step to import a module from. */ ?>
  <script type="application/json" id="person-reminder-data"><?= json_encode(
      array('reach_out' => $reachOut),
      JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
  ) ?></script>
  <!-- END REGION: reminders -->

  <!-- REGION: gifts — owned by I -->
<?php
  /* Required HERE rather than beside the others at the top of the file, for the
   * same reason R gives above: the top of the file is P's, and
   * docs/CONTRACTS.md §1 gives I these two regions and nothing else. */
  require_once __DIR__ . '/../lib/contact.php';

  $gifts = gifts_for_person($id);
?>
  <?php /* NO DRAG HANDLES AND NO REORDERING. Gift ideas sort newest-first and
           have no sort_order column — a decision (PLAN.md §4.6, CLAUDE.md), not
           an omission. assets/reorder.js ships wired to nothing; if ordering
           ever turns out to matter the module is already sitting there.

           SWIPE-TO-DELETE IS RIGHT HERE, unlike on a person. Five seconds is
           not a window in which you notice losing somebody's notes and years of
           contact history; it is plenty for a phrase you can retype. */ ?>
  <section class="card" id="person-gifts" data-id="<?= $id ?>">
    <p class="hint">Gift ideas</p>

    <?php /* Rendered hidden and unhidden by assets/person.js — the same swap P
             uses for "Edit tags" and R for "Change", and here for a blunter
             reason: THERE IS NO NO-SCRIPT WRITE PATH FOR THIS REGION. Adding a
             gift idea needs a POST, person.php's POST handler is P's, and the
             region rule gives I no way to add a case to it. So the composer is
             a real <form> that a script turns into a fetch, and a browser with
             no script is shown a list it can read rather than a box that
             swallows what you type.

             method and action are set even so, and the hidden action field with
             them: it means a stray submit is a 303 straight back to this
             profile rather than a GET that drops the id, and it means the day
             P's handler grows a `case 'gift-add':` this works with no script at
             all. Reported rather than done here — see the track's report. */ ?>
    <form class="composer hidden" id="person-gift-composer"
          method="post" action="person.php?id=<?= $id ?>">
      <input type="hidden" name="action" value="gift-add">
      <input
        class="composer-input"
        type="text"
        name="idea_text"
        id="person-gift-new"
        placeholder="Add a gift idea"
        aria-label="Add a gift idea"
        autocomplete="off"
        autocapitalize="sentences"
        enterkeyhint="done"
        maxlength="<?= GIFT_TEXT_MAX ?>">
      <button class="composer-add" type="submit">Add</button>
    </form>
  </section>

  <p class="empty<?= $gifts === array() ? '' : ' hidden' ?>" id="person-gift-empty">
    No gift ideas yet.
  </p>

  <?php /* The <ul> is always rendered, and carries .hidden when it is empty
           rather than being left out. `.list:empty` cannot do this job — the
           whitespace an empty foreach leaves behind is a text node, so the
           element is not empty and the CSS never matches, leaving a stray
           rounded box under the "no gift ideas yet" line. It stays in the DOM
           because assets/person.js attaches the swipe and the inline editor to
           it once, by delegation, and needs a root that exists before the first
           row does. */ ?>
  <ul class="list<?= $gifts === array() ? ' hidden' : '' ?>" id="person-gift-list">
<?php foreach ($gifts as $gift): ?>
    <li class="list-row" data-id="<?= (int) $gift['id'] ?>">
      <div class="row-slide">
        <span class="row-text"><?= h($gift['idea_text']) ?></span>
        <?php /* The pointer-only backup for the swipe, wired by swipe.js to the
                 identical delete path. There is no gesture with a mouse, and
                 without this a gift idea would be undeletable on a desktop. */ ?>
        <button class="row-del" type="button" aria-label="Delete <?= h($gift['idea_text']) ?>">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13"/></svg>
        </button>
      </div>
    </li>
<?php endforeach; ?>
  </ul>
  <!-- END REGION: gifts -->

  <!-- REGION: log — owned by I -->
<?php
  /* Idempotent, and here so this region stands on its own rather than on the
   * gifts region above happening to be rendered first. */
  require_once __DIR__ . '/../lib/contact.php';

  $logEntries = contact_log_for_person($id);
?>
  <section class="card" id="person-log" data-id="<?= $id ?>">
    <p class="hint">Contact log</p>

    <?php /* ONE TAP LOGS A CONTACT, and the note is optional. Requiring a note
             is how a logging button stops being used, and an app nobody logs
             into knows nothing about when you last called anyone (schema.sql).

             Tapping it twice in one day writes TWO rows and leaves
             last_contact_date alone. That is correct and is not de-duplicated
             anywhere: two conversations in one day are two conversations, and
             the cadence clock runs off the date (CLAUDE.md).

             Hidden until assets/person.js unhides it, for the same reason as
             the gift composer above — see that comment. This one costs more:
             with no script there is no way to log a contact from this screen,
             and the dashboard's 1-tap button needs a script too, so the app's
             daily action needs one. The <noscript> below says so out loud
             rather than leaving a card with nothing in it. */ ?>
    <form class="composer hidden" id="person-log-composer"
          method="post" action="person.php?id=<?= $id ?>">
      <input type="hidden" name="action" value="contact-log">
      <input
        class="composer-input"
        type="text"
        name="note"
        id="person-log-note"
        placeholder="What did you talk about? (optional)"
        aria-label="An optional note about this conversation"
        autocomplete="off"
        autocapitalize="sentences"
        enterkeyhint="done"
        maxlength="<?= CONTACT_NOTE_MAX ?>">
      <button class="composer-add" type="submit">Logged today</button>
    </form>

    <noscript>
      <p class="hint">Logging a conversation needs JavaScript. The history below reads without it.</p>
    </noscript>
  </section>

  <?php /* <details>, not a div and a click handler: it opens with no script, it
           is keyboard operable, and the accessibility semantics come free
           (docs/CONTRACTS.md §4). COLLAPSED BY DEFAULT — the history is the
           answer to "when did we last talk about the wedding", which is a
           question you ask occasionally, and forty rows open by default would
           push the delete control off the bottom of a phone screen. */ ?>
  <details class="accordion" id="person-log-history">
    <summary class="accordion-head">
      History <span class="accordion-count" id="person-log-count"><?= count($logEntries) ?></span>
    </summary>
    <div class="accordion-body">
      <p class="empty<?= $logEntries === array() ? '' : ' hidden' ?>" id="person-log-empty">
        Nothing logged yet.
      </p>

      <ul class="list<?= $logEntries === array() ? ' hidden' : '' ?>" id="person-log-list">
<?php foreach ($logEntries as $entry): ?>
<?php $lines = contact_log_lines($entry, $today); ?>
        <li class="list-row" data-id="<?= (int) $entry['id'] ?>">
          <div class="row-slide">
            <?php /* .row-body so the date is a SIBLING of .row-text rather than
                     inside it. Nothing here is inline-editable — a log entry is
                     a dated record of something that happened, not a phrase you
                     revise — but the two-line shape is the same one. */ ?>
            <div class="row-body">
              <span class="row-text"><?= h($lines['text']) ?></span>
<?php if ($lines['sub'] !== ''): ?>
              <span class="row-sub"><?= h($lines['sub']) ?></span>
<?php endif; ?>
            </div>

            <?php /* NOT A SWIPE AND NOT A .row-del. CLAUDE.md keeps
                     swipe-to-delete on gift ideas and import drafts, where the
                     worst case is retyping a phrase; there is no
                     contact-restore endpoint and no undo, so this asks first
                     instead. Hidden until the script can wire it up, like the
                     composer above. */ ?>
            <button class="tap-text danger hidden" type="button">Remove</button>
          </div>
        </li>
<?php endforeach; ?>
      </ul>
    </div>
  </details>
  <!-- END REGION: log -->

  <!-- REGION: danger — owned by P -->
  <?php /* Last on the screen on purpose. A LINK, not a button: with no script it
           is a page that asks first, and assets/person.js turns it into a
           confirm() and a fetch so the answer costs one tap instead of a page
           load. One affordance, two implementations, no second control. */ ?>
  <p class="row">
    <a class="btn-danger" id="person-delete" href="person.php?id=<?= $id ?>&amp;delete=1"
       data-id="<?= $id ?>" data-name="<?= h($person['name']) ?>">Delete this person</a>
  </p>
  <!-- END REGION: danger -->
<?php endif; ?>

  <form id="logout-form" method="post" action="logout.php" class="hidden"></form>

  <script type="module" src="<?= asset('assets/person.js') ?>"></script>

<?php
page_foot('people');
