/* The People tab's behaviour.
 *
 * Everything here is an UPGRADE. people.php renders the groups, the counts, the
 * "last contacted" sublines and a real <a> on every row, and the search box is a
 * real GET form. With this file blocked you can still read the list, still open
 * a profile and still search — you just pay a page load for the search and you
 * have to hit the chevron rather than anywhere on the row.
 *
 * What it adds: filtering as you type, the whole row as a tap target, and
 * renaming a relationship tag by tapping its heading.
 *
 * THE ONE THING TO UNDERSTAND is the relationship between the two searches.
 * The server has already applied ?q= by the time this runs, so the rows in the
 * page are a SUBSET of everyone. Narrowing the query further is a DOM filter and
 * costs nothing. Broadening it cannot be a DOM filter, because the rows that
 * would come back are not here — so when the typed query stops being an
 * extension of the server's, this navigates and lets the server answer. The
 * common case (arriving with no ?q= at all) is always local, because the empty
 * string is a prefix of everything.
 *
 * The gestures themselves are NOT here. inline-edit.js, menu.js and api.js are
 * shared with every other screen and know nothing about people; this file is
 * the part that knows what a relationship tag is.
 */

import { apiPost } from './api.js';
import { attachInlineEdit } from './inline-edit.js';
import { attachMenu } from './menu.js';
import { showSnackbar } from './swipe.js';

const TAG_NAME_MAX = 64;      // relationship_tags.name — see lib/people.php
const SEARCH_DEBOUNCE = 350;  // ms before a broadening query costs a page load

attachMenu('#app-menu', {
  items: [
    { label: 'Import contacts', href: 'import.php' },
    { label: 'Sign out', form: '#logout-form' },
  ],
});

const root = document.getElementById('people-list');
if (root) {
  start(root);
}

function start(container) {
  const form  = document.getElementById('people-search-form');
  const input = document.getElementById('people-search');
  const emptyAll     = document.getElementById('people-empty');
  const emptyMatches = document.getElementById('people-no-matches');

  /* What the server was asked for, normalized the same way the typed query is,
     so the prefix test below compares like with like. */
  const serverQuery = normalize(form ? form.dataset.serverQuery || '' : '');

  let pending = null;

  /* ------------------------------------------------------------- filtering */

  /**
   * A typed query, flattened for comparison against a row's data-search.
   *
   * DELIBERATELY AN APPROXIMATION of lib/people.php's people_name_key(), and
   * only ever applied to the QUERY. The rows' haystacks are built server-side
   * by the real function, so the stored side is never normalized twice; this
   * only has to be close enough that typing "obrien" finds "O'Brien" and "jose"
   * finds "José" without a round trip. The authority is still the server: press
   * Enter and people_list()'s LIKE against name_key answers.
   */
  function normalize(raw) {
    return String(raw || '')
      .toLowerCase()
      /* NFD splits "é" into "e" plus a combining accent, which the next line
         then drops — the same fold PEOPLE_FOLD does with a table in PHP, where
         iconv's output is not portable enough to rely on. */
      .normalize('NFD')
      .replace(/\p{Diacritic}/gu, '')
      .replace(/['’‘`´]/g, '')
      .replace(/[^\p{L}\p{N}]+/gu, ' ')
      .trim();
  }

  function filter(query) {
    const groups = container.querySelectorAll('.cat-group');
    let shown = 0;

    groups.forEach((group) => {
      let visible = 0;
      group.querySelectorAll('.list-row').forEach((row) => {
        const hit = query === '' || (row.dataset.search || '').includes(query);
        row.classList.toggle('hidden', !hit);
        if (hit) { visible++; }
      });

      /* A group with nothing left in it goes too, heading and all. A heading
         over an empty list reads as a group you have somehow emptied rather
         than as one nobody in it matched. */
      group.classList.toggle('hidden', visible === 0);

      const count = group.querySelector('.cat-count');
      if (count && count.textContent !== String(visible)) {
        count.textContent = String(visible);
      }
      shown += visible;
    });

    /* Two different empty states. "Nobody here yet" is only ever right when
       there is genuinely nobody, and the server decided that; all this can
       know is whether the current filter matched, so it only ever touches the
       no-matches one. */
    if (emptyMatches && !(emptyAll && !emptyAll.classList.contains('hidden'))) {
      emptyMatches.classList.toggle('hidden', shown > 0);
    }
  }

  if (form && input) {
    /* Only now is the plain GET given up. Until this listener runs — a slow
       module load, a syntax error above — the form is a form and Search works. */
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      apply();
    });

    input.addEventListener('input', () => {
      if (pending !== null) { clearTimeout(pending); pending = null; }
      apply();
    });
  }

  function apply() {
    const query = normalize(input.value);

    if (serverQuery !== '' && !query.startsWith(serverQuery)) {
      /* Broadening past what the server filtered on. The missing rows are not
         in the page, so this has to be a real request — debounced, because
         holding backspace would otherwise fire one per character. */
      if (pending !== null) { clearTimeout(pending); }
      pending = setTimeout(() => {
        const raw = input.value.trim();
        window.location.assign('people.php' + (raw === '' ? '' : '?q=' + encodeURIComponent(raw)));
      }, SEARCH_DEBOUNCE);
      return;
    }

    filter(query);
  }

  /* ----------------------------------------------------- the row as a target */

  /* The whole row opens the profile, by following the link the row already
     carries rather than by holding a second copy of the URL. Real controls win:
     the chevron is a link and does this itself, and the heading's rename button
     must not be swallowed by a row handler it isn't even inside. */
  container.addEventListener('click', (event) => {
    if (event.target.closest('a, button, input, select, textarea')) { return; }

    const row = event.target.closest('.list-row');
    if (!row || !container.contains(row)) { return; }

    /* A tap that ends a text selection is someone reading, not navigating —
       the same rule inline-edit.js applies before opening an editor. */
    const selection = window.getSelection();
    if (selection && !selection.isCollapsed) { return; }

    const link = row.querySelector('.row-link');
    if (link) { window.location.assign(link.href); }
  });

  /* ------------------------------------------------------- renaming a tag */

  /* docs/CONTRACTS.md §4: `.cat-head button` is a group heading that is itself a
     control. The generic options on attachInlineEdit are what make this work
     without a second module — the "row" is the .cat-group carrying the tag's
     data-id, and the "text" is the heading's button.

     The Untagged group has no data-id because it is not a tag: there is no row
     to rename, so canEdit vetoes it rather than letting the editor open on
     something that cannot be saved. */
  attachInlineEdit(container, {
    rowSelector: '.cat-group',
    textSelector: '.cat-head button',
    maxLength: TAG_NAME_MAX,
    canEdit: (group) => Boolean(group.dataset.id),
    onSave: (id, text, group) => apiPost('api/tag-rename.php', { id: Number(id), name: text })
      .then((response) => {
        const button = group.querySelector('.cat-head button');
        if (button) { button.setAttribute('aria-label', 'Rename the tag ' + response.name); }
        // Returned so inline-edit.js renders what the server actually stored,
        // which may have been trimmed or had its whitespace collapsed.
        return response.name;
      })
      .catch((err) => {
        showSnackbar(errorMessage(err), { isError: true });
        // Rethrown so inline-edit.js puts the old heading back — a heading
        // showing a name the database refused is worse than no rename.
        throw err;
      }),
  });

  filter(normalize(input ? input.value : ''));
}

/** Whatever went wrong, in one line. Matches the wording swipe.js uses. */
function errorMessage(err) {
  if (err && err.code === 'unauthorized') { return 'Signed out — reload to sign in again.'; }
  if (err && err.code === 'network_unreachable') { return 'No connection. Nothing was changed.'; }
  if (err && err.code === 'name_taken') { return err.detail || 'There is already a tag with that name.'; }
  return 'That didn’t save. Nothing was changed.';
}
