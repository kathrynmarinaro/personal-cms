/* The import screen's behaviour.
 *
 * Everything here is an UPGRADE. public/import.php renders the upload form, the
 * queue and the finish button as real forms that POST, so with this file
 * blocked you can still upload a .vcf, still add somebody (the circle on each
 * row is a submit button) and still finish the batch — you just pay a page load
 * for each one and you cannot swipe.
 *
 * What it adds: swipe-to-skip with its five-second undo, adding without a
 * reload, and refusing an over-size file before it is uploaded rather than
 * after.
 *
 * TWO THINGS ABOUT THIS SCREEN THAT ARE DELIBERATE AND WILL LOOK LIKE
 * OMISSIONS:
 *
 *   1. THERE IS NO "ADD ALL". The staged queue exists to stop junk contacts
 *      being bulk-imported, and one button would quietly defeat it (CLAUDE.md).
 *      Do not add one here on the grounds that the markup makes it easy.
 *   2. ADDING SOMEBODY HAS NO UNDO, unlike skipping. Undoing an add would mean
 *      deleting a person, and deleting a person is a deliberate action on their
 *      profile with a confirmation — never something a five-second timer does
 *      on your behalf. Skipping is undoable precisely because it destroys
 *      nothing.
 *
 * The gestures are NOT here. swipe.js, menu.js and api.js are shared with every
 * other screen and know nothing about contacts; this file is the part that
 * knows what a draft is.
 */

import { apiPost } from './api.js';
import { attachSwipeDelete, showSnackbar } from './swipe.js';
import { attachMenu } from './menu.js';

attachMenu('#app-menu', {
  items: [
    // Marked current because this IS the import screen — menu.js renders it
    // with .is-current rather than as somewhere else to go.
    { label: 'Import contacts', href: 'import.php', current: true },
    { label: 'Sign out', form: '#logout-form' },
  ],
});

const errorLine = document.getElementById('import-error');
const emptyLine = document.getElementById('import-empty');
const list      = document.getElementById('import-drafts');
const finish    = document.getElementById('import-finish-form');
const upload    = document.getElementById('import-upload-form');

if (list) { startQueue(list); }
if (finish) { startFinish(finish); }
if (upload) { startUpload(upload); }

/* ------------------------------------------------------------------ queue */

function startQueue(root) {
  /* Skip, not delete: the draft row is marked and can come back with the same
     id, which is what makes the restore honest. See api/import-skip.php. */
  attachSwipeDelete(root, {
    onDelete: (id) => apiPost('api/import-skip.php', { id: Number(id) })
      .then((response) => { applyPending(response.pending); }),
    onUndo: (id) => apiPost('api/import-restore.php', { id: Number(id) })
      .then((response) => { applyPending(response.pending); return response.id; }),
    label: (row) => {
      const name = rowName(row);
      return name === '' ? 'Skipped.' : 'Skipped “' + name + '”';
    },
  });

  /* Every row is its own <form> whose submit button is the circle, so one
     delegated listener on the list covers all of them — including rows that
     were never re-rendered after an add. */
  root.addEventListener('submit', (event) => {
    const row = event.target.closest('.list-row');
    if (!row || !root.contains(row)) { return; }

    event.preventDefault();
    promote(row);
  });
}

function promote(row) {
  const id     = Number(row.dataset.id || 0);
  const button = row.querySelector('.row-check');
  if (!id || (button && button.disabled)) { return; }

  /* Disabled for the round trip, not for the animation: on a slow connection
     the row is still sitting there and a second tap would be a second person. */
  if (button) { button.disabled = true; }

  apiPost('api/import-promote.php', { id })
    .then((response) => {
      const name = response.person && response.person.name ? response.person.name : rowName(row);
      /* No Undo on this one — see the file header. */
      showSnackbar(name === '' ? 'Added.' : 'Added “' + name + '”');
      row.remove();
      applyPending(response.pending);
    })
    .catch((err) => {
      if (button) { button.disabled = false; }
      showSnackbar(errorMessage(err), { isError: true });
    });
}

/**
 * The queue's empty state, driven by the server's own count of what is left.
 *
 * The count comes back on every response rather than being counted in the DOM,
 * because the DOM is briefly wrong on purpose: a swiped row stays in the page
 * through the five-second undo window, and counting rows would say there is
 * still one there.
 *
 * The list itself is hidden as well as the paragraph shown. `.list` is a
 * bordered box and an emptied one is a stray horizontal rule under the "nothing
 * left" line.
 */
function applyPending(pending) {
  if (typeof pending !== 'number') { return; }

  if (emptyLine) { emptyLine.classList.toggle('hidden', pending > 0); }
  if (list) { list.classList.toggle('hidden', pending === 0); }
}

/** The name shown on a row, for the snackbar. */
function rowName(row) {
  const text = row.querySelector('.row-text');
  return text ? text.textContent.trim() : '';
}

/* ----------------------------------------------------------------- finish */

function startFinish(form) {
  form.addEventListener('submit', (event) => {
    event.preventDefault();

    const field  = form.querySelector('[name="batch_id"]');
    const button = form.querySelector('button[type="submit"]');
    const batchId = Number(field ? field.value : 0);
    if (!batchId) { return; }

    if (button) { button.disabled = true; }

    apiPost('api/import-finish.php', { batch_id: batchId })
      /* Straight to the People tab: the whole point of the import was to end up
         with people, and leaving the empty import screen on screen makes the
         last tap feel like it did nothing. */
      .then(() => { window.location.assign('people.php'); })
      .catch((err) => {
        if (button) { button.disabled = false; }
        showSnackbar(errorMessage(err), { isError: true });
      });
  });
}

/* ----------------------------------------------------------------- upload */

/**
 * Refuse an over-size file before it is uploaded.
 *
 * The server checks this too and is the one that decides — this only saves
 * pushing 30 MB up a phone connection to be told no at the end of it, which on
 * a train is a minute of progress bar followed by an error.
 */
function startUpload(form) {
  const input = form.querySelector('input[type="file"]');
  const max   = Number(form.querySelector('[name="MAX_FILE_SIZE"]')?.value || 0);
  if (!input || !max) { return; }

  form.addEventListener('submit', (event) => {
    const file = input.files && input.files[0] ? input.files[0] : null;
    if (!file || file.size <= max) { return; }

    event.preventDefault();
    showError('That file is bigger than the ' + Math.round(max / (1024 * 1024))
      + ' MB limit, so it was not uploaded.');
  });
}

function showError(message) {
  if (!errorLine) { return; }
  errorLine.textContent = message;
  errorLine.classList.remove('hidden');
}

/* Whatever went wrong, in one line. Matches the wording swipe.js uses. */
function errorMessage(err) {
  if (err && err.code === 'unauthorized') { return 'Signed out — reload to sign in again.'; }
  if (err && err.code === 'network_unreachable') { return 'No connection. Nothing was changed.'; }
  if (err && err.detail) { return err.detail; }
  return 'That didn’t save. Nothing was changed.';
}
