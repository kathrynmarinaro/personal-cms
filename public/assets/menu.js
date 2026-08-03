/* The app menu: a bottom sheet raised from the hamburger in the screen header.
 *
 * ---------------------------------------------------------------------------
 * WHERE APP-LEVEL ACTIONS LIVE
 *
 * Three tabs are the app's three daily jobs. Everything that is a job you do
 * once — import a contacts file, sign out, export later — goes here instead of
 * spending a permanent third of the tab bar on itself. That is the pattern the
 * Inspiration Gallery and Book Tracker already use for export, and this is it
 * ported rather than a second idea about the same problem.
 * ---------------------------------------------------------------------------
 *
 * BUILT ON DEMAND AND REMOVED ON CLOSE, not rendered into every page and
 * hidden. lib/layout.php's page_foot() gives the reasoning for the snackbar and
 * it is the same reasoning here: an empty fixed-position element sitting in
 * every page is one z-index mistake away from swallowing every tap on the tab
 * bar underneath it, and that failure looks like "the app stopped responding"
 * rather than like a menu bug.
 *
 * GENERIC ON PURPOSE, like the other shared modules. It takes a trigger and a
 * list of items and knows nothing about this app's screens — the items are
 * links, or callbacks, or a <form> to submit. Adding "Export" later is one more
 * entry in one array, in lib/layout.php.
 *
 * NO CSS OF ITS OWN. Everything it builds uses .sheet / .sheet-panel /
 * .sheet-cancel from styles.css, which the stylesheet already carries because
 * the sheet is the house picker component.
 *
 * DEGRADATION: with no JS at all the hamburger is a <button> that does nothing,
 * so page_menu() renders it only where there is somewhere else to go — and
 * every destination in the menu is also a real URL. Nothing here is the only
 * way to reach anything.
 */

/** Accept a selector or an element, like every other module here. */
function resolveRoot(root) {
  return typeof root === 'string' ? document.querySelector(root) : root;
}

/* One sheet at a time, module-global — the same rule swipe.js uses for the
   snackbar. Two open sheets would stack their backdrops and the second Cancel
   would appear to do nothing. */
let openSheet = null;

/**
 * Close whatever sheet is open. Exported so a caller can dismiss the menu
 * after starting something slow, without holding a handle to it.
 */
export function closeMenu() {
  if (openSheet === null) { return; }

  const { el, onKeydown, restoreFocus } = openSheet;
  openSheet = null;

  document.removeEventListener('keydown', onKeydown);
  el.remove();

  /* Put the caret back where it came from. Without this, dismissing the menu
     drops focus onto <body> and the next Tab starts from the top of the
     document — which on a screen reader means being read the page again. */
  if (restoreFocus && typeof restoreFocus.focus === 'function') {
    restoreFocus.focus();
  }
}

/**
 * Wire a trigger button to an app menu.
 *
 *   import { attachMenu } from './menu.js';
 *
 *   attachMenu('#app-menu', {
 *     items: [
 *       { label: 'Import contacts', href: 'import.php' },
 *       { label: 'Sign out', form: '#logout-form', danger: true },
 *     ],
 *   });
 *
 * @param {string|Element} trigger The hamburger button, or a selector for it.
 * @param {object} opts
 * @param {Array} opts.items REQUIRED. Each item is an object with `label` and
 *        exactly one action:
 *          `href`     — render an <a>. A plain link, so it works with a long
 *                       press, opens in a new tab, and is a real URL.
 *          `onSelect` — render a <button> and call this. Return a promise if
 *                       you like; the sheet closes first either way, because a
 *                       menu that stays open while something happens behind it
 *                       reads as a menu that did not register the tap.
 *          `form`     — render a <button> that submits this form (selector or
 *                       element). For POST-only actions such as signing out,
 *                       where a link would let any <img src> trigger it.
 *        Optional per item: `current: true` marks it as where you already are
 *        (`.is-current`), `danger: true` is unused by the stylesheet today and
 *        reserved so a destructive entry can be styled without a signature
 *        change here.
 * @param {string} [opts.label='Menu'] Accessible name for the sheet.
 * @param {string} [opts.cancelLabel='Cancel']
 * @returns {() => void} detach
 */
export function attachMenu(trigger, opts = {}) {
  const button = resolveRoot(trigger);
  const noop = () => {};
  if (!button) { return noop; }

  const {
    items = [],
    label = 'Menu',
    cancelLabel = 'Cancel',
  } = opts;

  if (!Array.isArray(items) || items.length === 0) {
    throw new TypeError('attachMenu: items is required and must not be empty');
  }

  function open() {
    /* Tapping the hamburger while the menu is open closes it, rather than
       building a second sheet on top of the first. */
    if (openSheet !== null) {
      closeMenu();
      return;
    }

    const el = document.createElement('div');
    el.className = 'sheet';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.setAttribute('aria-label', label);

    const panel = document.createElement('div');
    panel.className = 'sheet-panel';

    items.forEach((item) => {
      panel.appendChild(buildItem(item));
    });

    /* Cancel is ALWAYS the last row, and it is a real control rather than
       "tap outside to dismiss". On a phone the backdrop above a bottom sheet is
       the part of the screen your hand is not near, and an escape route you
       have to reach for is not an escape route. */
    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'sheet-cancel';
    cancel.textContent = cancelLabel;
    cancel.addEventListener('click', closeMenu);
    panel.appendChild(cancel);

    el.appendChild(panel);

    /* Backdrop tap closes; a tap that lands on the panel must not. Checking
       the target rather than stopping propagation inside the panel keeps this
       to one listener and leaves the panel's own clicks untouched. */
    el.addEventListener('click', (event) => {
      if (event.target === el) { closeMenu(); }
    });

    const onKeydown = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        closeMenu();
      }
    };
    document.addEventListener('keydown', onKeydown);

    document.body.appendChild(el);
    openSheet = { el, onKeydown, restoreFocus: button };

    /* Focus the first entry, so a keyboard or switch user lands inside the
       thing that just opened rather than behind it. */
    const first = panel.querySelector('a, button');
    if (first && typeof first.focus === 'function') { first.focus(); }
  }

  function buildItem(item) {
    const text = String(item && item.label ? item.label : '');

    if (item && typeof item.href === 'string' && item.href !== '') {
      const link = document.createElement('a');
      link.href = item.href;
      link.textContent = text;
      if (item.current) { link.classList.add('is-current'); }
      /* No preventDefault and no close(): the page is navigating away and the
         sheet goes with it. Closing first would make the menu flicker shut
         under a tap that is already committed. */
      return link;
    }

    const entry = document.createElement('button');
    entry.type = 'button';
    entry.textContent = text;
    if (item && item.current) { entry.classList.add('is-current'); }
    if (item && item.danger) { entry.classList.add('is-danger'); }

    entry.addEventListener('click', () => {
      /* Close BEFORE acting. Whatever this does — a fetch, a form submit — the
         menu has served its purpose, and leaving it up while a request is in
         flight is how a second tap sends a second request. */
      closeMenu();

      if (item && typeof item.onSelect === 'function') {
        item.onSelect();
        return;
      }

      if (item && item.form) {
        const form = resolveRoot(item.form);
        /* requestSubmit, not submit: it runs validation and fires the submit
           event, so a form with a confirm handler on it still gets to ask. */
        if (form && typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else if (form) {
          form.submit();
        }
      }
    });

    return entry;
  }

  button.addEventListener('click', open);

  return function detach() {
    button.removeEventListener('click', open);
    closeMenu();
  };
}
