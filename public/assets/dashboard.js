/* The Today dashboard's behaviour.
 *
 * Everything here is an UPGRADE. index.php renders the three buckets, the
 * counts, the sublines and a real <a> on every row. With this file blocked you
 * can still read the whole screen and still open anybody's profile — you just
 * log a contact from their profile instead of from the row.
 *
 * What it adds is the 1-tap "Logged today" button, which is the difference
 * between clearing the morning's list in four taps and in four page loads.
 *
 * THERE IS NO SWIPE ON THIS SCREEN, deliberately. swipe.js deletes on
 * swipe-past-threshold-and-release with a five-second undo, which is right for
 * a grocery item you can retype in two seconds. Nothing on this screen is
 * something you would want to delete by accident with your thumb: a person is
 * never swipe-deletable (CLAUDE.md), and a reminder is a deliberate schedule
 * you set on a profile.
 *
 * A LOGGED ROW STAYS PUT, struck through. Removing it would be tidier for
 * exactly one second and then wrong: the list would shuffle under a thumb still
 * moving toward the next row, and you would lose the record of what you have
 * already done this morning. It is gone on the next load, because logging the
 * contact moved the reminder — which is the only thing that moves a reach-out
 * (PLAN.md §7.2).
 */

import { apiTry } from './api.js';
import { attachMenu } from './menu.js';
import { showSnackbar } from './swipe.js';

attachMenu('#app-menu', {
  items: [
    { label: 'Import contacts', href: 'import.php' },
    { label: 'Sign out', form: '#logout-form' },
  ],
});

const root = document.getElementById('dashboard');
if (root) {
  start(root);
}

function start(container) {
  /* Delegated to the container rather than bound per row, matching every shared
     module in the app: there is one listener whatever the list does, and a
     re-render would need no re-attach. */
  container.addEventListener('click', (event) => {
    const check = event.target.closest('.row-check');
    if (check && container.contains(check)) {
      logContact(check);
      return;
    }

    /* The whole row opens the profile, by following the link the row already
       carries rather than by holding a second copy of the URL. Real controls
       win: the chevron is a link and does this itself, and the check button is
       handled above and must not also navigate. */
    if (event.target.closest('a, button')) { return; }

    const row = event.target.closest('.list-row');
    if (!row || !container.contains(row)) { return; }

    /* A tap that ends a text selection is someone reading, not navigating —
       the same rule inline-edit.js applies before opening an editor. */
    const selection = window.getSelection();
    if (selection && !selection.isCollapsed) { return; }

    const link = row.querySelector('.row-link');
    if (link) { window.location.assign(link.href); }
  });
}

/**
 * Log a contact with this row's person, today.
 *
 * The endpoint is Phase 2A's (docs/CONTRACTS.md §5 and §6:
 * `apiPost('api/contact-log.php', { person_id })`). It writes the contact_log
 * row, sets people.last_contact_date, and resets this reminder's cadence
 * server-side — the date arithmetic lives in PHP, and nothing here decides what
 * "due in sixty days" means.
 *
 * OPTIMISTIC, WITH AN HONEST REVERT. The strike-through is the feedback and it
 * has to be instant on a phone; if the write fails the row comes back exactly
 * as it was and an error snackbar says so, so the screen never shows a
 * conversation that was not recorded.
 *
 * THE BUTTON IS DISABLED ONCE IT SUCCEEDS rather than becoming an un-log.
 * Two conversations in one day are two conversations (CLAUDE.md), so a second
 * tap would honestly mean "log another one" — which is not what a struck-through
 * row looks like it offers, and not what a thumb resting on a button means.
 * Removing a logged contact is a deliberate action in the profile's history.
 */
async function logContact(button) {
  const row = button.closest('.list-row');
  if (!row || button.disabled) { return; }

  const personId = Number(row.dataset.person || 0);
  if (personId <= 0) { return; }

  const sub  = row.querySelector('.row-sub');
  const was  = sub ? sub.textContent : '';

  button.disabled = true;
  button.setAttribute('aria-pressed', 'true');
  row.classList.add('is-checked');
  if (sub) { sub.textContent = 'Logged today'; }

  const result = await apiTry('api/contact-log.php', { person_id: personId });
  if (result.ok) { return; }

  button.disabled = false;
  button.setAttribute('aria-pressed', 'false');
  row.classList.remove('is-checked');
  if (sub) { sub.textContent = was; }
  showSnackbar(errorMessage(result.error), { isError: true });
}

/** Whatever went wrong, in one line. Matches the wording swipe.js uses. */
function errorMessage(err) {
  if (err && err.code === 'unauthorized') { return 'Signed out — reload to sign in again.'; }
  if (err && err.code === 'network_unreachable') { return 'No connection. Nothing was logged.'; }
  if (err && err.detail) { return err.detail; }
  return 'That didn’t save. Nothing was logged.';
}
