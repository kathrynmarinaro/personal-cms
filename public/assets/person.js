/* The person profile's behaviour.
 *
 * =============================================================================
 *  THIS FILE IS SHARED, like public/person.php. Read that file's header first.
 * =============================================================================
 *
 * docs/CONTRACTS.md §1: P owns this file and lays down the skeleton; each of the
 * three Phase 2 tracks appends its own attach* call inside its own marked region
 * at the foot of the file, in the same order as the regions in person.php.
 * Everything above those markers is P's.
 *
 * -----------------------------------------------------------------------------
 *
 * Everything here is an UPGRADE. person.php renders the facts, real tel: and
 * mailto: links, a real <select> for adding a tag, a Remove button per tag, a
 * real composer for creating one, a real edit form behind ?edit=1 and a real
 * confirmation page behind ?delete=1. With this file blocked every one of those
 * still works.
 *
 * What it adds is the .sheet tag picker — one control that both adds and
 * removes, which the no-script markup needs two controls to do — and saving the
 * edit form without losing what was typed if the save fails.
 *
 * WHAT IT DELIBERATELY DOES NOT DO is intercept the pencil. The sibling app
 * turns its edit link into a sheet because that form is three fields; this one
 * is seven, two of them textareas, and a sheet that tall on a phone is a form
 * you scroll inside a thing that also scrolls. A page is the right control for
 * it, so the link stays a link.
 */

import { apiPost, apiTry } from './api.js';
import { attachMenu } from './menu.js';
import { showSnackbar } from './swipe.js';

const TAG_NAME_MAX = 64;   // relationship_tags.name — see lib/people.php

attachMenu('#app-menu', {
  items: [
    { label: 'Import contacts', href: 'import.php' },
    { label: 'Sign out', form: '#logout-form' },
  ],
});

const tagsRoot = document.getElementById('person-tags');
if (tagsRoot) {
  startTags(tagsRoot);
}

startEditForm();
startDelete();

/* ============================================================ the tag picker */

function startTags(card) {
  const personId = Number(card.dataset.id || 0);
  const pills    = document.getElementById('person-tag-pills');
  const none     = document.getElementById('person-tag-none');
  const fallback = document.getElementById('person-tag-fallback');
  const opener   = document.getElementById('person-tag-edit');
  const composer = document.getElementById('person-tag-composer');
  const newTag   = document.getElementById('person-tag-new');
  if (!pills || personId <= 0) { return; }

  /* The tag table as data, not as strings baked in here: tags are rows, custom
     ones appear at runtime, and the picker has to show what is actually there.
     A broken JSON block costs the sheet and nothing else — the no-script
     controls below are still in the page, so this bails by leaving them up. */
  let tags = [];
  let held = new Set();
  try {
    const raw = document.getElementById('person-tags-data');
    const data = raw ? JSON.parse(raw.textContent || '{}') : {};
    tags = Array.isArray(data.all) ? data.all : [];
    held = new Set((Array.isArray(data.held) ? data.held : []).map(Number));
  } catch {
    return;
  }

  /* THE SWAP. The <select> and the per-tag Remove buttons are what the screen
     ships with; the sheet replaces both with one toggle. Done here rather than
     with a `hidden` attribute in the markup so that a browser which fails to
     run this module is left with controls that work, rather than with a button
     that opens nothing. */
  if (fallback) { fallback.classList.add('hidden'); }
  if (opener) {
    opener.classList.remove('hidden');
    opener.addEventListener('click', openSheet);
  }

  /** Repaint the pill row from `held`, in the tag table's own order. */
  function paintPills() {
    pills.querySelectorAll('.pill').forEach((pill) => pill.remove());
    tags.forEach((tag) => {
      if (!held.has(Number(tag.id))) { return; }
      const pill = document.createElement('span');
      pill.className = 'pill';
      pill.dataset.id = String(tag.id);
      /* textContent, never innerHTML: a tag name is typed by hand and this is
         the one place it re-enters the page without going through PHP's h(). */
      pill.textContent = tag.name;
      pills.append(pill);
    });
    if (none) { none.classList.toggle('hidden', held.size > 0); }
  }

  /**
   * The picker: every tag, with the ones this person has marked .is-current.
   *
   * IT STAYS OPEN WHEN YOU TAP ONE, unlike menu.js's sheet, because this is a
   * toggle rather than a menu — putting somebody in three groups should be
   * three taps, not three taps and two re-openings. Cancel and the backdrop
   * close it, and the pills behind it update as you go so the result is visible
   * before you dismiss.
   */
  function openSheet() {
    const sheet = document.createElement('div');
    sheet.className = 'sheet';
    sheet.setAttribute('role', 'dialog');
    sheet.setAttribute('aria-modal', 'true');
    sheet.setAttribute('aria-label', 'Choose relationship tags');

    const panel = document.createElement('div');
    panel.className = 'sheet-panel';

    tags.forEach((tag) => {
      const id = Number(tag.id);
      const option = document.createElement('button');
      option.type = 'button';
      option.textContent = tag.name;
      paintOption(option, id);
      option.addEventListener('click', () => toggle(tag, option));
      panel.append(option);
    });

    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'sheet-cancel';
    cancel.textContent = 'Done';
    cancel.addEventListener('click', close);
    panel.append(cancel);

    sheet.append(panel);

    /* Tapping the backdrop closes. The check is on the sheet itself rather than
       "not the panel", so a tap that starts on a button and drifts doesn't
       dismiss. */
    sheet.addEventListener('click', (event) => {
      if (event.target === sheet) { close(); }
    });

    document.addEventListener('keydown', onKey);
    document.body.append(sheet);
    (panel.querySelector('button') || cancel).focus();

    function paintOption(button, id) {
      const on = held.has(id);
      button.classList.toggle('is-current', on);
      button.setAttribute('aria-pressed', on ? 'true' : 'false');
    }

    async function toggle(tag, button) {
      const id = Number(tag.id);
      const wanted = !held.has(id);

      // Optimistic: the pill appearing is the feedback, and the revert below
      // keeps it honest.
      if (wanted) { held.add(id); } else { held.delete(id); }
      paintOption(button, id);
      paintPills();

      const result = await apiTry('api/tag-assign.php', {
        person_id: personId,
        tag_id: id,
        assigned: wanted,
      });

      if (result.ok) { return; }

      if (wanted) { held.delete(id); } else { held.add(id); }
      paintOption(button, id);
      paintPills();
      showSnackbar(errorMessage(result.error), { isError: true });
    }

    function onKey(event) {
      if (event.key === 'Escape') { close(); }
    }

    function close() {
      document.removeEventListener('keydown', onKey);
      sheet.remove();
      // Focus goes back to what opened it, or it lands on <body> and the next
      // Tab starts from the top of the page.
      if (opener) { opener.focus(); }
    }
  }

  /* ------------------------------------------------------- a brand new tag */

  if (composer && newTag) {
    composer.addEventListener('submit', async (event) => {
      /* Only now is the plain POST given up. Until this listener runs the
         composer is a form and creating a tag works. */
      event.preventDefault();

      const name = newTag.value.trim();
      if (name === '') { return; }

      const button = composer.querySelector('.composer-add');
      if (button) { button.disabled = true; }
      newTag.value = '';

      const result = await apiTry('api/tag-add.php', {
        name: name.slice(0, TAG_NAME_MAX),
        person_id: personId,
      });

      if (button) { button.disabled = false; }

      if (!result.ok) {
        newTag.value = name;
        showSnackbar(errorMessage(result.error), { isError: true });
        return;
      }

      /* The server may have handed back a tag that already existed under that
         name rather than a new one — tags_add() reuses instead of erroring — so
         the list is updated by id and not by assuming this is new. */
      const tag = result.data.tag;
      if (!tags.some((t) => Number(t.id) === Number(tag.id))) { tags.push(tag); }
      held.add(Number(tag.id));
      paintPills();
      newTag.focus();
    });
  }
}

/* ========================================================== the edit form */

/**
 * Save the identity form without leaving the page.
 *
 * The only thing this buys is the failure case, and it is worth having: a plain
 * POST that 500s takes a long note with it, and the notes field is the one
 * place on this screen where somebody has typed a paragraph they cannot
 * reconstruct. On success it navigates, which is what the form would have done
 * anyway.
 */
function startEditForm() {
  const form = document.getElementById('person-form');
  if (!form) { return; }

  const id  = Number(form.dataset.id || 0);
  const err = document.getElementById('person-form-err');
  if (id <= 0) { return; }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();

    const data = new FormData(form);
    const save = form.querySelector('button[type="submit"]');
    if (save) { save.disabled = true; }
    if (err) { err.classList.add('hidden'); }

    const result = await apiTry('api/person-update.php', {
      id,
      name: String(data.get('name') || ''),
      birth_year: String(data.get('birth_year') || ''),
      birth_month: String(data.get('birth_month') || ''),
      birth_day: String(data.get('birth_day') || ''),
      address: String(data.get('address') || ''),
      phone: String(data.get('phone') || ''),
      email: String(data.get('email') || ''),
      notes: String(data.get('notes') || ''),
    });

    if (save) { save.disabled = false; }

    if (!result.ok) {
      if (err) {
        err.textContent = errorMessage(result.error);
        err.classList.remove('hidden');
      }
      return;
    }

    window.location.assign('person.php?id=' + encodeURIComponent(String(id)));
  });
}

/* ============================================================== deleting */

/**
 * Turn the delete link into a confirm-and-fetch.
 *
 * The link points at person.php?id=…&delete=1, which renders the same question
 * as a page. That page is the guard when this module does not run, and it is
 * why the guard is server-side rather than a confirm() that vanishes with the
 * script — a destructive action whose confirmation silently disappears looks
 * exactly like one that still has it.
 *
 * There is no undo and no snackbar with an Undo button, deliberately: deleting a
 * person cascades to their tags, gift ideas, whole contact log and reminders
 * (schema.sql), and none of that comes back. CLAUDE.md is explicit that this is
 * why a person is not swipeable.
 */
function startDelete() {
  const link = document.getElementById('person-delete');
  if (!link) { return; }

  link.addEventListener('click', async (event) => {
    const id = Number(link.dataset.id || 0);
    const name = link.dataset.name || 'this person';
    if (id <= 0) { return; }

    event.preventDefault();

    const sure = window.confirm(
      'Delete ' + name + '?\n\n'
      + 'This also deletes their notes, gift ideas, contact history and reminders. '
      + 'It cannot be undone.'
    );
    if (!sure) { return; }

    try {
      await apiPost('api/person-delete.php', { id });
    } catch (err) {
      showSnackbar(errorMessage(err), { isError: true });
      return;
    }

    window.location.assign('people.php');
  });
}

/** Whatever went wrong, in one line. Matches the wording swipe.js uses. */
function errorMessage(err) {
  if (err && err.code === 'unauthorized') { return 'Signed out — reload to sign in again.'; }
  if (err && err.code === 'network_unreachable') { return 'No connection. Nothing was changed.'; }
  if (err && err.detail) { return err.detail; }
  return 'That didn’t save. Nothing was changed.';
}

/* ===========================================================================
 * The Phase 2 tracks append their attach* calls below, one per region, in the
 * same order as the regions in public/person.php. Nothing above this line is
 * theirs; nothing inside a region is anybody else's.
 * =========================================================================== */

/* REGION: reminders — owned by R */
/* END REGION: reminders */

/* REGION: gifts — owned by I */
/* END REGION: gifts */

/* REGION: log — owned by I */
/* END REGION: log */
