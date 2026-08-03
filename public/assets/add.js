/* The Add tab's behaviour, which is one form and the app menu.
 *
 * Everything here is an UPGRADE. add.php is a real <form> that POSTs to itself
 * and answers with a 303 to the new profile; with this file blocked, adding
 * somebody works exactly as it always did, duplicate warning included.
 *
 * What it adds is that a refused or failed save leaves the typing on screen.
 * That matters more here than anywhere else in the app: this form is seven
 * fields deep, it is filled in on a phone, and the duplicate check refuses the
 * first submission ON PURPOSE — so without this, the one interaction the screen
 * is designed around costs you the form.
 *
 * THE DUPLICATE FLOW, and it is the same rule the server follows. schema.sql
 * leaves people.name_key non-unique because two people really can be called
 * James Smith; a duplicate is FLAGGED, never refused. api/person-add.php
 * answers the first attempt at an existing name with 409 'duplicate_name' and a
 * sentence; this sets the confirm flag, says the sentence, and relabels the
 * button. The second attempt carries the flag and creates the second person.
 * It never refuses twice.
 */

import { apiTry } from './api.js';
import { attachMenu } from './menu.js';

attachMenu('#app-menu', {
  items: [
    { label: 'Import contacts', href: 'import.php' },
    { label: 'Sign out', form: '#logout-form' },
  ],
});

const form = document.getElementById('add-form');
if (form) {
  start(form);
}

function start(el) {
  const confirmField = document.getElementById('add-confirm-duplicate');
  const error  = document.getElementById('add-error');
  const submit = document.getElementById('add-submit');

  el.addEventListener('submit', async (event) => {
    /* Only now is the plain POST given up. Until this listener runs — a slow
       module load, a JS error above — the form is a form and Add works. */
    event.preventDefault();

    const data = new FormData(el);
    if (String(data.get('name') || '').trim() === '') {
      /* The input is `required`, so the browser normally catches this before
         submit fires. This is for the paths where it doesn't. */
      show('A person needs a name.');
      return;
    }

    if (submit) { submit.disabled = true; }
    hide();

    const result = await apiTry('api/person-add.php', {
      name: String(data.get('name') || ''),
      birth_year: String(data.get('birth_year') || ''),
      birth_month: String(data.get('birth_month') || ''),
      birth_day: String(data.get('birth_day') || ''),
      address: String(data.get('address') || ''),
      phone: String(data.get('phone') || ''),
      email: String(data.get('email') || ''),
      notes: String(data.get('notes') || ''),
      confirm_duplicate: String(data.get('confirm_duplicate') || '') !== '',
    });

    if (submit) { submit.disabled = false; }

    if (result.ok) {
      window.location.assign('person.php?id=' + encodeURIComponent(String(result.data.person.id)));
      return;
    }

    if (result.error && result.error.code === 'duplicate_name') {
      /* Said once, and then got out of the way. The flag rides on the form
         itself rather than in a variable here so that it survives a browser
         restoring the page from the back/forward cache with the fields intact. */
      if (confirmField) { confirmField.value = '1'; }
      if (submit) { submit.textContent = 'Add them anyway'; }
      show(result.error.detail || 'You already have somebody with that name. Add them again?');
      return;
    }

    show(errorMessage(result.error));
  });

  function show(message) {
    if (!error) { return; }
    error.textContent = message;
    error.classList.remove('hidden');
  }

  function hide() {
    if (error) { error.classList.add('hidden'); }
  }
}

/** Whatever went wrong, in one line. Matches the wording swipe.js uses. */
function errorMessage(err) {
  if (err && err.code === 'unauthorized') { return 'Signed out — reload to sign in again.'; }
  if (err && err.code === 'network_unreachable') { return 'No connection. Nothing was saved.'; }
  if (err && err.detail) { return err.detail; }
  return 'That didn’t save. Nothing was changed.';
}
