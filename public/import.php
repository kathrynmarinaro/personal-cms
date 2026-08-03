<?php
/* Import contacts — upload a .vcf, then decide about each contact in it.
 *
 * NOT A TAB, and reached from the hamburger menu on every screen (lib/layout.php,
 * PLAN.md §3). Three tabs are the app's three daily jobs; importing a contacts
 * file is a job you do twice, ever, and burying it inside Add would make that
 * screen two unrelated things wearing one name.
 *
 * THE QUEUE IS THE FEATURE. Every contact is staged as a draft and waits for a
 * decision — the brief asks for the staging by name, "to prevent bulk-importing
 * junk contacts", because a phone's address book is full of taxi firms and
 * one-off delivery drivers. So THERE IS DELIBERATELY NO "ADD ALL" BUTTON on
 * this screen. One would quietly defeat the entire design (CLAUDE.md).
 *
 * WHAT WORKS WITH NO JAVASCRIPT AT ALL:
 *
 *   * uploading   — a real multipart <form> that POSTs here and 303s, so a
 *                   refresh cannot import the same file twice
 *   * adding      — every row's .row-check is a submit button, so the one
 *                   decision this screen is about needs no script
 *   * finishing   — "Done importing" is a plain form POST
 *
 * What the script adds is swipe-to-skip with its five-second undo, and adding
 * somebody without a page reload. Skipping without JS is simply not adding
 * them: whatever is left when you finish is discarded, which the button says
 * out loud.
 *
 * THE UPLOADED FILE IS DELETED THE MOMENT IT IS PARSED — in a finally, so a
 * parse that throws still takes it with it. The drafts are the artifact;
 * keeping the .vcf would mean keeping every phone number you decided not to
 * import (PLAN.md §6.4). */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/import.php';

require_login_page();

/* Once, at the top, and passed down — lib/dates.php's header explains why this
 * is the only place on this screen allowed to ask what day it is. It is needed
 * because a draft's birth year is sanity-checked against the current year on
 * the way in (people_clean_birthday()). */
$today = crm_today();
$error = '';

/* A POST that PHP threw away before this file ran, because it was bigger than
 * post_max_size. $_POST and $_FILES are both empty and there is no error code
 * anywhere — so without this the screen would re-render as though nothing had
 * been submitted, which is the most confusing thing an upload form can do. */
if (import_post_was_dropped()) {
    $limit = import_effective_limit();
    $error = 'That file was too big for the server to accept (the limit is '
        . import_mb_label($limit['bytes']) . '). Nothing was imported.';
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    /* Not guarded by require_same_origin(): a plain browser form cannot set the
     * X-Requested-With header, so the check would 403 exactly the no-JS case
     * these branches exist for. What protects them instead is the session
     * cookie's SameSite=Lax (lib/auth.php) — a cross-site POST arrives without
     * it, so require_login_page() above has already bounced it. Same reasoning
     * and same shape as add.php and person.php. */
    $action = (string) ($_POST['action'] ?? 'upload');

    if ($action === 'finish') {
        $batchId = (int) ($_POST['batch_id'] ?? 0);
        try {
            if ($batchId > 0) {
                import_finish($batchId);
            }
        } catch (Throwable $e) {
            error_log('import: finishing batch ' . $batchId . ' failed: ' . $e->getMessage());
            fatal_error('import_finish_failed', 'That import could not be closed.', 500);
        }
        header('Location: import.php', true, 303);
        exit;
    }

    if ($action === 'promote') {
        /* The no-JS Add. assets/import.js intercepts the same button and posts
         * to api/import-promote.php instead, so both paths run the identical
         * import_promote() and neither is the only way to do it. */
        $draftId = (int) ($_POST['id'] ?? 0);
        try {
            if ($draftId > 0) {
                import_promote($draftId, $today);
            }
        } catch (Throwable $e) {
            error_log('import: promoting draft ' . $draftId . ' failed: ' . $e->getMessage());
            fatal_error('import_promote_failed', 'That person could not be added.', 500);
        }
        header('Location: import.php', true, 303);
        exit;
    }

    /* ---- the upload itself ------------------------------------------------ */
    $file  = isset($_FILES['vcf']) && is_array($_FILES['vcf']) ? $_FILES['vcf'] : null;
    $error = (string) import_check_upload($file);

    if ($error === '') {
        $directory = import_uploads_dir();
        /* A random name, not the uploaded one: the file lives for the length of
         * one parse in a directory that denies everything, and giving it a name
         * out of the request is how a path from a browser ends up on a disk. */
        $stored = $directory . '/' . bin2hex(random_bytes(12)) . '.vcf';

        if (!is_dir($directory) || !@move_uploaded_file((string) $file['tmp_name'], $stored)) {
            error_log('import: could not move an upload into ' . $directory);
            $error = 'The upload could not be saved on the server. Try again.';
        } else {
            $result = null;
            try {
                if (!vcard_sniff_file($stored)) {
                    /* PLAN.md §6.4: check what is IN the file, not just what it
                     * is called. A .vcf that is really a spreadsheet parses to
                     * zero contacts and would otherwise report nothing at all. */
                    $error = 'That file does not look like a vCard export — it should start with BEGIN:VCARD.';
                } else {
                    $result = vcard_parse_file($stored, import_max_contacts());
                }
            } catch (Throwable $e) {
                error_log('import: parsing failed: ' . $e->getMessage());
                $error = 'That file could not be read. Try exporting your contacts again.';
            } finally {
                /* THE POINT AT WHICH THE FILE STOPS EXISTING, and it is in a
                 * finally so that a parse which threw still takes it with it. */
                @unlink($stored);
            }

            if ($result !== null) {
                if ($result['contacts'] === array()) {
                    $error = $result['parsed'] > 0
                        ? 'None of the ' . (int) $result['parsed'] . ' contacts in that file had a name, so there was nothing to import.'
                        : 'There were no contacts in that file.';
                } else {
                    try {
                        $batchId = import_stage((string) ($file['name'] ?? 'contacts.vcf'), $result, $today);
                    } catch (Throwable $e) {
                        error_log('import: staging failed: ' . $e->getMessage());
                        fatal_error('import_stage_failed', 'Those contacts could not be staged for review.', 500);
                    }

                    /* 303 so a refresh re-renders the queue rather than
                     * re-uploading. The two flags survive the redirect because
                     * there is nowhere else to put them — a flash message would
                     * mean session state for one sentence. */
                    header(
                        'Location: import.php?staged=' . $batchId . ($result['truncated'] ? '&capped=1' : ''),
                        true,
                        303
                    );
                    exit;
                }
            }
        }
    }
}

/* ------------------------------------------------------------------ reading */

try {
    $batch  = import_open_batch();
    $drafts = $batch === null ? array() : import_pending_drafts($batch['id']);
    $counts = $batch === null ? array('pending' => 0, 'added' => 0, 'skipped' => 0, 'total' => 0)
        : import_counts($batch['id']);
} catch (Throwable $e) {
    error_log('import: reading the queue failed: ' . $e->getMessage());
    fatal_error('import_read_failed', 'The import queue could not be loaded.', 500);
}

$justStaged = $batch !== null && (int) ($_GET['staged'] ?? 0) === $batch['id'];
$wasCapped  = $justStaged && ($_GET['capped'] ?? '') !== '';
$limit      = import_effective_limit();

page_head('Import contacts');
screen_head('Import contacts', page_menu());
?>

  <?php /* Always in the DOM so assets/import.js can fill it, and role="alert"
           so a screen reader is told rather than having to find it. */ ?>
  <p class="field-err<?= $error === '' ? ' hidden' : '' ?>" id="import-error" role="alert"><?= h($error) ?></p>

<?php if ($batch === null): ?>
  <section class="card">
    <form class="stack" id="import-upload-form" method="post" action="import.php" enctype="multipart/form-data">
      <input type="hidden" name="action" value="upload">
      <?php /* Advisory only — a browser is free to ignore it and a forged POST
               certainly will. It is here because PHP honours it and aborts an
               over-size upload early with UPLOAD_ERR_FORM_SIZE, which is a
               better answer than reading 30 MB to refuse it. import_check_upload()
               is the one that actually decides. */ ?>
      <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int) $limit['bytes'] ?>">

      <label class="field">
        <span>Contacts file</span>
        <?php /* accept= is a hint to the file picker, not a check: it narrows
                 what the phone offers and nothing more. The extension and the
                 first line are both checked server-side. */ ?>
        <input type="file" name="vcf" id="import-file" accept=".vcf,.vcard,text/vcard,text/x-vcard" required>
      </label>

      <p class="hint">
        On an iPhone: Contacts → select a contact → Share → Mail or Files, or export the
        whole list from iCloud.com. Up to <?= h(import_mb_label($limit['bytes'])) ?>.
        Photos in the file are ignored, and the file itself is deleted as soon as it is read.
      </p>

      <button class="btn-primary" type="submit">Upload</button>
    </form>
  </section>

  <p class="empty">Nothing waiting to be reviewed.</p>
<?php else: ?>
  <section class="card">
    <p><strong><?= h($batch['filename']) ?></strong></p>
    <?php /* total_parsed counts cards the parser refused for having no name too
             (schema.sql), which is why this says "of": showing fewer rows than
             the file had, without saying so, is how you find out a year later
             that six contacts never arrived. */ ?>
    <p class="hint">
      <?= (int) $counts['total'] ?> of <?= (int) $batch['total_parsed'] ?> contacts staged<?php
        if ($counts['added'] > 0 || $counts['skipped'] > 0): ?> ·
        <?= (int) $counts['added'] ?> added, <?= (int) $counts['skipped'] ?> skipped<?php
        endif; ?>
    </p>
<?php if ($wasCapped): ?>
    <p class="field-err">
      That file had more than <?= (int) import_max_contacts() ?> contacts in it. The rest were not read —
      finish this batch and upload the file again to carry on.
    </p>
<?php endif; ?>
    <?php /* Said once, above the list, because the two gestures are the whole
             interaction and neither is discoverable. There is NO "add all"
             here and there never will be — see the file header. */ ?>
    <p class="hint" id="import-help">Tap the circle to add somebody. Swipe a row away to skip it.</p>
  </section>

  <p class="empty<?= $drafts === array() ? '' : ' hidden' ?>" id="import-empty">
    Nothing left to review. Tap “Done importing” below.
  </p>

  <?php /* Not rendered at all when there is nothing pending. `.list` is a
           bordered, rounded box, and an empty one is a stray horizontal rule
           under the "nothing left to review" line — `.list:empty` cannot help,
           because the whitespace of an empty foreach is a text node. The
           script hides it the same way once the last row goes. */ ?>
<?php if ($drafts !== array()): ?>
  <ul class="list" id="import-drafts">
<?php foreach ($drafts as $draft): ?>
    <li class="list-row" data-id="<?= (int) $draft['id'] ?>">
      <?php /* The .row-slide IS the form, exactly as the sibling app's rows do
               it: everything visible has to live inside .row-slide
               (docs/CONTRACTS.md §4) and the Add control has to be a submit
               button for the no-JS path, so the moving layer is the <form>
               rather than containing one. Same class, same box, no new CSS. */ ?>
      <form class="row-slide" method="post" action="import.php">
        <input type="hidden" name="action" value="promote">
        <input type="hidden" name="id" value="<?= (int) $draft['id'] ?>">

        <?php /* aria-pressed="false" and never true: this is a one-way action,
                 not a toggle — the row leaves the queue the moment it is
                 added. */ ?>
        <button class="row-check" type="submit"
                aria-pressed="false"
                aria-label="Add <?= h($draft['name']) ?> to your people"></button>

        <div class="row-body">
          <span class="row-text"><?= h($draft['name']) ?></span>
          <span class="row-sub"><?= h(import_draft_sub($draft)) ?></span>
        </div>

<?php if ($draft['dup_person_id'] !== null): ?>
        <?php /* .pill.is-plain, which docs/CONTRACTS.md §4 names for exactly
                 this. A WARNING AND NOTHING ELSE: dup_person_id never merges,
                 never blocks, and adding this draft creates a second person —
                 two people really can share a name (schema.sql). */ ?>
        <span class="pill is-plain">Possible duplicate</span>
<?php endif; ?>

        <?php /* The pointer-only backup for the swipe, wired by swipe.js to the
                 identical skip path. type="button" so it can never submit the
                 form it is sitting in. */ ?>
        <button class="row-del" type="button" aria-label="Skip <?= h($draft['name']) ?>">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13"/></svg>
        </button>
      </form>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>

  <form class="stack" id="import-finish-form" method="post" action="import.php">
    <input type="hidden" name="action" value="finish">
    <input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>">
    <p class="hint">
      Finishing discards whatever is still in this list. Nobody is deleted — the people you
      added are on the People tab, and the file itself was deleted when it was read.
    </p>
    <button class="btn-secondary" type="submit" id="import-finish">Done importing</button>
  </form>
<?php endif; ?>

  <?php /* logout.php is POST-only on purpose — a link would let any
           <img src="logout.php"> sign you out — so assets/menu.js submits this
           form. Every screen carries one. */ ?>
  <form id="logout-form" method="post" action="logout.php" class="hidden"></form>

  <?php /* type="module" is also what defers it: the queue is fully rendered and
           usable before a byte of this runs. */ ?>
  <script type="module" src="<?= asset('assets/import.js') ?>"></script>

<?php
page_foot();
