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

/* The reach-out reminder control.
 *
 * READING the reminder needs none of this — person.php renders the sentence
 * server-side. What this adds is the only way to CHANGE it, which is the one
 * place on this screen that does not degrade to a plain form: person.php's POST
 * handler is P's, so there is no action for a no-script form here to post, and
 * a second write path invented for one card would be a pattern nothing else in
 * the app follows. The controls are therefore rendered hidden in the markup and
 * unhidden here — the same swap the tag picker above does, and for the same
 * reason: a button that opens nothing is worse than no button.
 *
 * The BIRTHDAY reminder is deliberately not editable from here. It is
 * materialized from the person's birthday and reconciled by people_save() and
 * by every cron run (PLAN.md §4.5), so anything set here would be silently
 * corrected within a day. Change the birthday instead.
 */
startReachOut();

function startReachOut() {
  const card = document.getElementById('person-reminders');
  if (!card) { return; }

  const personId = Number(card.dataset.id || 0);
  const state    = document.getElementById('person-reminder-state');
  const opener   = document.getElementById('person-reminder-edit');
  const dateForm = document.getElementById('person-reminder-date-form');
  const dateInput = document.getElementById('person-reminder-date');
  const dateCancel = document.getElementById('person-reminder-date-cancel');
  if (personId <= 0 || !state || !opener) { return; }

  /* The cadences the sheet offers. Roughly monthly, quarterly, half-yearly,
     yearly — the shapes an actual relationship has. Anything else is reachable
     as a one-off date, and a free-text "every N days" box would be a number pad
     in a picker for a choice nobody makes twice. */
  const PRESETS = [30, 60, 90, 180, 365];

  /* The reminder as the server last described it, so the picker opens on the
     right option. A broken JSON block costs the .is-current highlight and
     nothing else. */
  let current = null;
  try {
    const raw = document.getElementById('person-reminder-data');
    const data = raw ? JSON.parse(raw.textContent || '{}') : {};
    current = data.reach_out || null;
  } catch {
    current = null;
  }

  opener.classList.remove('hidden');
  opener.addEventListener('click', openSheet);

  if (dateForm && dateInput) {
    dateForm.addEventListener('submit', (event) => {
      event.preventDefault();
      if (dateInput.value) { save({ due_date: dateInput.value }); }
    });
  }
  if (dateCancel && dateForm) {
    dateCancel.addEventListener('click', () => dateForm.classList.add('hidden'));
  }

  /** The cadence choices, with any stored one that isn't a preset folded in. */
  function cadences() {
    const stored = current && current.recurrence_interval_days
      ? Number(current.recurrence_interval_days)
      : null;
    const all = PRESETS.slice();
    if (stored && !all.includes(stored)) { all.push(stored); }
    return all.sort((a, b) => a - b);
  }

  /**
   * The cadence picker.
   *
   * IT CLOSES ON A CHOICE, unlike the tag sheet above. A tag is a toggle and
   * putting somebody in three groups should be three taps; a reminder is one
   * schedule and picking a second one would only undo the first.
   */
  function openSheet() {
    const sheet = document.createElement('div');
    sheet.className = 'sheet';
    sheet.setAttribute('role', 'dialog');
    sheet.setAttribute('aria-modal', 'true');
    sheet.setAttribute('aria-label', 'Choose a reach-out reminder');

    const panel = document.createElement('div');
    panel.className = 'sheet-panel';

    const isCadence = Boolean(current && current.recurrence_interval_days);

    cadences().forEach((days) => {
      const option = document.createElement('button');
      option.type = 'button';
      option.textContent = 'Every ' + days + ' days';
      if (isCadence && Number(current.recurrence_interval_days) === days) {
        option.classList.add('is-current');
      }
      option.addEventListener('click', () => {
        close();
        save({ cadence_days: days });
      });
      panel.append(option);
    });

    const onDate = document.createElement('button');
    onDate.type = 'button';
    onDate.textContent = 'Just once, on a date';
    if (current && !current.recurrence_interval_days) { onDate.classList.add('is-current'); }
    onDate.addEventListener('click', () => {
      close();
      /* The sheet hands off to the date field rather than trying to hold a
         picker itself: a native <input type="date"> is the phone's own wheel,
         and the stylesheet has no date component to build one out of. */
      if (dateForm && dateInput) {
        dateForm.classList.remove('hidden');
        dateInput.focus();
      }
    });
    panel.append(onDate);

    const clear = document.createElement('button');
    clear.type = 'button';
    clear.textContent = 'No reminder';
    if (!current) { clear.classList.add('is-current'); }
    clear.addEventListener('click', () => {
      close();
      remove();
    });
    panel.append(clear);

    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'sheet-cancel';
    cancel.textContent = 'Cancel';
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

    function onKey(event) {
      if (event.key === 'Escape') { close(); }
    }

    function close() {
      document.removeEventListener('keydown', onKey);
      sheet.remove();
      // Focus goes back to what opened it, or it lands on <body> and the next
      // Tab starts from the top of the page.
      opener.focus();
    }
  }

  /* NOT optimistic, unlike the tag pills. The sentence this paints is "every 60
     days, next Friday", and only the server knows the second half — it counts
     from the last contact, not from today, using the same tested arithmetic the
     cron uses. Guessing it here and correcting it a moment later would show a
     date that was never true. */
  async function save(body) {
    const result = await apiTry('api/reminder-save.php', Object.assign({ person_id: personId }, body));
    if (!result.ok) {
      showSnackbar(errorMessage(result.error), { isError: true });
      return;
    }
    current = result.data.reminder;
    state.textContent = result.data.label;
    if (dateForm) { dateForm.classList.add('hidden'); }
  }

  async function remove() {
    const result = await apiTry('api/reminder-delete.php', { person_id: personId });
    if (!result.ok) {
      showSnackbar(errorMessage(result.error), { isError: true });
      return;
    }
    current = null;
    /* Rebuilt rather than assigned as text, so "no reminder" keeps the same
       .muted grey the server rendered it in — a plain sentence here would read
       as a reminder that says the words "No reach-out reminder". */
    state.textContent = '';
    const none = document.createElement('span');
    none.className = 'muted';
    none.textContent = 'No reach-out reminder';
    state.append(none);
    if (dateForm) { dateForm.classList.add('hidden'); }
  }
}

/* END REGION: reminders */

/* REGION: gifts — owned by I */

/* The gift-ideas list.
 *
 * READING the list needs none of this — person.php renders every row. What this
 * adds is the three interactions: adding without a page load, tap-to-edit, and
 * swipe-to-delete with its five-second undo.
 *
 * THE COMPOSER IS RENDERED HIDDEN AND UNHIDDEN HERE, like R's "Change" above
 * and P's "Edit tags" above that, and for a blunter reason than either: adding
 * a gift idea needs a POST, person.php's POST handler belongs to P, and
 * docs/CONTRACTS.md §1 gives I no way to add a case to it. A box that swallows
 * what you type is worse than no box, so the box only appears once the thing
 * that makes it work is running.
 *
 * NO REORDERING. Gift ideas sort newest-first and have no sort_order column
 * (PLAN.md §4.6) — reorder.js is imported by nothing on this screen on purpose.
 *
 * The imports are down here rather than at the top of the file because the top
 * of the file is P's. `import` is hoisted and legal anywhere at a module's top
 * level, so this is the region rule and the module system agreeing rather than
 * a trick.
 */
import { attachSwipeDelete } from './swipe.js';
import { attachInlineEdit } from './inline-edit.js';

const GIFT_TEXT_MAX = 500;   // gift_ideas.idea_text — see lib/contact.php

startGifts();

function startGifts() {
  const card     = document.getElementById('person-gifts');
  const list     = document.getElementById('person-gift-list');
  const empty    = document.getElementById('person-gift-empty');
  const composer = document.getElementById('person-gift-composer');
  const input    = document.getElementById('person-gift-new');
  if (!card || !list) { return; }

  const personId = Number(card.dataset.id || 0);
  if (personId <= 0) { return; }

  /* id -> the row as the server described it when it was deleted. Held only for
     the five seconds the undo snackbar is up: the row is already gone from the
     database, and api/gift-restore.php needs the fields to put it back. Same
     shape as the sibling app's wish list, for the same reason. */
  const deleted = new Map();

  attachSwipeDelete(list, {
    /* Fires the moment the gesture completes, so the idea is gone from the
       database before the snackbar appears. Undo is therefore a RESTORE. */
    onDelete: (id) => apiPost('api/gift-delete.php', { id: Number(id) })
      .then((response) => {
        deleted.set(String(id), response.gift || null);
        refresh();
      }),

    onUndo: (id) => {
      const gift = deleted.get(String(id));
      if (!gift) {
        /* Nothing to restore from — a reload between the delete and the tap.
           Rejecting is honest: swipe.js hides the row again and says it didn't
           save, rather than leaving a row on screen that is not in the
           database. */
        return Promise.reject(new Error('nothing to restore'));
      }
      return apiPost('api/gift-restore.php', {
        id: Number(gift.id),
        person_id: personId,
        idea_text: gift.idea_text,
      }).then((response) => {
        deleted.delete(String(id));
        refresh();
        /* Usually the id it went out with — gift_restore() keeps it so the list
           sorts the same after a reload — but swipe.js adopts whatever comes
           back, so a fallback insert is handled too. */
        return response.id;
      });
    },

    label: (row) => {
      const text = row.querySelector('.row-text');
      const idea = text ? text.textContent.trim() : '';
      return idea === '' ? 'Deleted.' : 'Deleted “' + idea + '”';
    },
  });

  attachInlineEdit(list, {
    /* Resolving to the server's own string rather than to nothing: it trims and
       caps at the column width, and the row should show what was stored rather
       than what was typed. */
    onSave: (id, text) => apiPost('api/gift-rename.php', {
      id: Number(id),
      idea_text: text,
    }).then((response) => response.idea_text),
    maxLength: GIFT_TEXT_MAX,
  });

  if (composer && input) {
    composer.classList.remove('hidden');

    composer.addEventListener('submit', async (event) => {
      /* Only now is the plain POST given up — and there is nothing behind it on
         this screen, which is why the form was hidden until this listener
         existed. See the region comment above. */
      event.preventDefault();

      const idea = input.value.trim();
      if (idea === '') { return; }

      const button = composer.querySelector('.composer-add');
      if (button) { button.disabled = true; }
      /* Cleared first: the phone keyboard stays up and the next idea can be
         typed while this one is in flight. Put back if the write fails. */
      input.value = '';

      const result = await apiTry('api/gift-add.php', {
        person_id: personId,
        idea_text: idea.slice(0, GIFT_TEXT_MAX),
      });

      if (button) { button.disabled = false; }

      if (!result.ok) {
        input.value = idea;
        showSnackbar(errorMessage(result.error), { isError: true });
        return;
      }

      list.prepend(buildGiftRow(result.data.gift));
      refresh();
      input.focus();
    });
  }

  /** A row that matches what person.php renders, so the two cannot drift. */
  function buildGiftRow(gift) {
    const row = document.createElement('li');
    row.className = 'list-row';
    row.dataset.id = String(gift.id);

    const slide = document.createElement('div');
    slide.className = 'row-slide';

    const text = document.createElement('span');
    text.className = 'row-text';
    /* textContent, never innerHTML: this is whatever was typed, and this is the
       one place it re-enters the page without going through PHP's h(). */
    text.textContent = gift.idea_text;
    slide.append(text);

    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'row-del';
    del.setAttribute('aria-label', 'Delete ' + gift.idea_text);
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', 'M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13');
    svg.append(path);
    del.append(svg);
    slide.append(del);

    row.append(slide);
    return row;
  }

  /**
   * The empty state, and the list's own border with it.
   *
   * Recomputed from the DOM rather than tracked, because three different things
   * change the count and one of them is swipe.js dropping an <li> when its undo
   * window expires — which happens inside another module with no callback to
   * hang this off. A row mid-undo is still in the DOM but is not on the list.
   *
   * `.list` is a bordered, rounded box, so an emptied one is a stray rule under
   * the "no gift ideas yet" line. `.list:empty` cannot help: the whitespace of
   * an empty foreach is a text node.
   */
  function refresh() {
    const live = Array.from(list.querySelectorAll('.list-row'))
      .filter((row) => !row.classList.contains('is-removing')).length;

    list.classList.toggle('hidden', live === 0);
    if (empty) { empty.classList.toggle('hidden', live > 0); }
  }

  const observer = new MutationObserver(() => refresh());
  observer.observe(list, { childList: true });
  refresh();
}

/* END REGION: gifts */

/* REGION: log — owned by I */

/* Logging a conversation, and removing one that was logged by mistake.
 *
 * THE 1-TAP BUTTON IS THE APP'S DAILY ACTION and the note is optional: tapping
 * "Logged today" with an empty box is one tap and writes a row with no note.
 * Tapping it twice in one day writes two rows and moves last_contact_date
 * nowhere — two conversations in one day are two conversations, and the cadence
 * clock runs off the date (CLAUDE.md). Nothing here de-duplicates that.
 *
 * BOTH ACTIONS RELOAD THE PROFILE ON SUCCESS, which is the one thing worth
 * arguing about here. Logging a contact changes three things this screen has
 * already rendered: the last-contact line in P's identity region, the reach-out
 * reminder's next date in R's (server-computed, from the last contact, by the
 * same arithmetic the cron uses), and this history. Two of those are other
 * tracks' markup, and the reminder's new date is not something this file could
 * work out without re-implementing the cadence rules. Painting the parts it
 * owns and leaving the rest stale would show a profile that was never true, so
 * it re-renders instead — the same choice the edit form above makes, for the
 * same reason.
 *
 * The failure path is why this is a fetch and not a form: a note typed and lost
 * to a 500 is the only thing on this screen you cannot reconstruct.
 */
startContactLog();

function startContactLog() {
  const card     = document.getElementById('person-log');
  const composer = document.getElementById('person-log-composer');
  const note     = document.getElementById('person-log-note');
  const list     = document.getElementById('person-log-list');
  if (!card) { return; }

  const personId = Number(card.dataset.id || 0);
  if (personId <= 0) { return; }

  const profileUrl = 'person.php?id=' + encodeURIComponent(String(personId));

  if (composer && note) {
    composer.classList.remove('hidden');

    composer.addEventListener('submit', async (event) => {
      /* Nothing is behind this form — see the region comment in person.php. */
      event.preventDefault();

      const button = composer.querySelector('.composer-add');
      if (button) { button.disabled = true; }

      const result = await apiTry('api/contact-log.php', {
        person_id: personId,
        note: note.value.trim(),
      });

      if (!result.ok) {
        /* The note stays in the box. It is the one thing on this screen that
           cannot be reconstructed from what is on screen. */
        if (button) { button.disabled = false; }
        showSnackbar(errorMessage(result.error), { isError: true });
        return;
      }

      window.location.assign(profileUrl);
    });
  }

  if (list) {
    /* Delegated, matching every shared module in the app: one listener whatever
       the list does. */
    list.querySelectorAll('.tap-text').forEach((button) => button.classList.remove('hidden'));

    list.addEventListener('click', async (event) => {
      const button = event.target.closest('.tap-text');
      if (!button || !list.contains(button) || button.disabled) { return; }

      const row = button.closest('.list-row');
      const id  = Number(row ? row.dataset.id || 0 : 0);
      if (id <= 0) { return; }

      /* A confirm, not an undo snackbar. There is no api/contact-restore.php:
         a log entry is a dated record of something that happened, and CLAUDE.md
         keeps the five-second undo on gift ideas and import drafts, where the
         worst case is retyping a phrase. Deleting a person asks the same way,
         a few lines up. */
      const sure = window.confirm(
        'Remove this from the contact history?\n\n'
        + 'The last-contact date goes back to whatever is left. '
        + 'It does not change any reminder you have set.'
      );
      if (!sure) { return; }

      button.disabled = true;

      const result = await apiTry('api/contact-delete.php', { id });
      if (!result.ok) {
        button.disabled = false;
        showSnackbar(errorMessage(result.error), { isError: true });
        return;
      }

      /* Reloaded rather than removed from the list: deleting the newest entry
         moves last_contact_date, which P's identity region has already
         rendered. Same reasoning as logging, above. */
      window.location.assign(profileUrl);
    });
  }
}

/* END REGION: log */
