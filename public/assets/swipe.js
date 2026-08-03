/* Swipe left on a row to delete it, with an undo snackbar.
 *
 * ---------------------------------------------------------------------------
 * WHY THE SWIPE DELETES INSTEAD OF REVEALING BUTTONS
 *
 * The two common patterns are "swipe reveals a Delete button you then tap" and
 * "swipe past a threshold and release deletes". The author picked the second
 * explicitly, to try it out — it is one gesture instead of two, which is the
 * whole point when you are holding a phone in one hand in a shop.
 *
 * It is also the destructive one, and a stray flick while scrolling would
 * otherwise silently lose a row. So the delete is ALWAYS paired with a
 * five-second undo snackbar, and this module refuses to be used without one:
 * the snackbar is built in here, not left to the caller. If the pattern turns
 * out to be wrong in practice, switching to reveal-actions is a change to this
 * file and nothing else — no tab module knows how the gesture works.
 * ---------------------------------------------------------------------------
 *
 * NOT HIJACKING THE SCROLL is the hard part, and it is solved in two places:
 *
 *   1. `touch-action: pan-y` on .list-row (styles.css). The compositor gives
 *      vertical panning to the browser and horizontal movement to us, before
 *      any JavaScript runs. This is what makes the list scroll normally.
 *   2. The lock rule below: nothing moves until the gesture has travelled more
 *      than 10px AND is more horizontal than vertical. Until then we are a
 *      passive observer, and a gesture that turns out to be a scroll is
 *      abandoned rather than fought over.
 *
 * DEGRADATION: no Pointer Events, no JS, or JS that failed to load — the rows
 * render, the checkboxes work, the text is still tappable. They just don't
 * swipe. Nothing here is the only way to do anything.
 */

const LOCK_DISTANCE   = 10;    // px before the gesture commits to a direction
const ARM_FRACTION    = 0.4;   // of row width — past this, release deletes
const UNDO_MS         = 5000;
const SLIDE_MS        = 180;   // must match the .row-slide transition in styles.css

/* ------------------------------------------------------------- snackbar */

/* One at a time, module-global. Two stacked snackbars would cover the tab bar,
   and a second delete almost always means the first one was intended. */
let current = null;   // { el, timer, onExpire }

/**
 * Show the undo snackbar. Exported because a tab module may want the same
 * affordance for something that isn't a swipe — undoing a "mark purchased",
 * say. Returns a function that dismisses it early.
 *
 * @param {string} message
 * @param {object} [opts] { actionLabel, onAction, onExpire, ms, isError }
 */
export function showSnackbar(message, opts = {}) {
  const {
    actionLabel = '',
    onAction = null,
    onExpire = null,
    ms = UNDO_MS,
    isError = false,
  } = opts;

  /* Replacing a live snackbar EXPIRES it rather than cancelling it. Its delete
     already happened server-side; letting it just vanish would leave the row it
     was hiding in the DOM forever. */
  dismissCurrent(true);

  const el = document.createElement('div');
  el.className = 'snackbar' + (isError ? ' is-error' : '');
  /* role=status, not alert: alert interrupts a screen reader mid-sentence, and
     this is a confirmation with an optional action, not an emergency. */
  el.setAttribute('role', 'status');
  el.setAttribute('aria-live', 'polite');

  const text = document.createElement('span');
  text.className = 'snackbar-msg';
  text.textContent = message;
  el.append(text);

  if (actionLabel !== '' && typeof onAction === 'function') {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'snackbar-action';
    button.textContent = actionLabel;
    button.addEventListener('click', () => {
      /* Take the action AFTER tearing the snackbar down, so an onAction that
         throws can't leave it on screen forever. */
      teardown();
      onAction();
    });
    el.append(button);
  }

  document.body.append(el);

  const entry = { el, timer: 0, onExpire };
  entry.timer = window.setTimeout(() => dismissCurrent(true), ms);
  current = entry;

  function teardown() {
    if (current === entry) { current = null; }
    window.clearTimeout(entry.timer);
    entry.el.remove();
  }

  return teardown;
}

function dismissCurrent(expire) {
  if (current === null) { return; }
  const entry = current;
  current = null;
  window.clearTimeout(entry.timer);
  entry.el.remove();
  if (expire && typeof entry.onExpire === 'function') {
    entry.onExpire();
  }
}

/* ------------------------------------------------------------ the gesture */

function resolveRoot(root) {
  return typeof root === 'string' ? document.querySelector(root) : root;
}

/** The text used in the default snackbar message, trimmed to one readable line. */
function rowLabel(row) {
  const node = row.querySelector('.row-text');
  const raw = (node ? node.textContent : '').trim();
  if (raw === '') { return ''; }
  return raw.length > 48 ? raw.slice(0, 47) + '…' : raw;
}

/**
 * Attach swipe-to-delete to every .list-row inside `root`, now and in future.
 *
 * Listeners are DELEGATED to the root, so rows added or replaced after this
 * runs are covered without re-attaching. A tab module that re-renders its list
 * from a fetch response does not have to remember to call this again.
 *
 * @param {Element|string} root
 * @param {object} opts
 * @param {(id: string) => (void|Promise<any>)} opts.onDelete
 *        Called the moment the gesture completes. The row is already hidden.
 *        Reject (or throw) to refuse the delete — the row comes back and an
 *        error snackbar is shown instead of the undo one.
 * @param {(id: string) => (void|Promise<any>)} [opts.onUndo]
 *        Called when Undo is tapped. May resolve to a replacement id (or an
 *        object with an `id`) if the restore created a new row server-side;
 *        the element's data-id is updated to match. Omit it and the snackbar
 *        appears without an Undo button — for a caller whose delete genuinely
 *        cannot be undone.
 * @param {string|((row: Element, id: string) => string)} [opts.label]
 *        The snackbar message, or a function producing it.
 * @param {string} [opts.rowSelector='.list-row']
 * @param {(row: Element) => boolean} [opts.canSwipe] veto per row.
 * @returns {() => void} detach
 */
export function attachSwipeDelete(root, opts = {}) {
  const el = resolveRoot(root);
  const noop = () => {};
  if (!el) { return noop; }

  /* Pointer Events are in every browser this app will ever see, but a missing
     API must degrade to "rows don't swipe" rather than to a thrown error that
     takes the rest of the module's page setup down with it. */
  if (typeof window.PointerEvent !== 'function') { return noop; }

  const {
    onDelete,
    onUndo = null,
    label = null,
    rowSelector = '.list-row',
    canSwipe = null,
    deleteSelector = '.row-del',
  } = opts;

  if (typeof onDelete !== 'function') {
    throw new TypeError('attachSwipeDelete: onDelete is required');
  }

  let active = null;

  function reset(row, animate) {
    row.classList.remove('is-swiping', 'is-armed');
    if (animate) {
      row.style.setProperty('--swipe-dx', '0px');
    } else {
      row.style.removeProperty('--swipe-dx');
    }
  }

  function onPointerDown(event) {
    if (active !== null) { return; }
    if (event.pointerType === 'mouse' && event.button !== 0) { return; }

    const row = event.target.closest(rowSelector);
    if (!row || !el.contains(row)) { return; }

    /* Three things own a gesture more specifically than the swipe does, and all
       three start inside a row. The drag handle belongs to reorder.js; a live
       inline editor belongs to the caret; a row already on its way out has
       nothing left to delete. */
    if (event.target.closest('.drag-handle')) { return; }
    if (row.classList.contains('is-editing') || row.classList.contains('is-removing')) { return; }
    if (event.target.closest('input, textarea, select')) { return; }
    if (canSwipe && !canSwipe(row)) { return; }

    const width = row.getBoundingClientRect().width;
    if (width <= 0) { return; }

    active = {
      row,
      id: row.dataset.id || '',
      pointerId: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      width,
      locked: false,
      armed: false,
    };
  }

  function onPointerMove(event) {
    if (active === null || event.pointerId !== active.pointerId) { return; }

    const dx = event.clientX - active.startX;
    const dy = event.clientY - active.startY;

    if (!active.locked) {
      if (Math.abs(dx) < LOCK_DISTANCE && Math.abs(dy) < LOCK_DISTANCE) { return; }

      /* More vertical than horizontal: this is a scroll. Let go of it
         completely — the browser is already panning, and continuing to watch
         would mean a diagonal scroll ends in a delete. */
      if (Math.abs(dx) <= Math.abs(dy)) {
        active = null;
        return;
      }

      active.locked = true;
      active.row.classList.add('is-swiping');
      /* Capture so the gesture survives the finger leaving the row — at 40% of
         the row width your thumb is often over the row below by the time you
         release. */
      try { active.row.setPointerCapture(event.pointerId); } catch { /* not fatal */ }
    }

    /* Left only. A rightward pull resolves to 0 rather than sliding the row the
       other way: there is nothing revealed on that side, so movement there
       would promise an action that doesn't exist. */
    const offset = Math.min(0, dx);
    active.row.style.setProperty('--swipe-dx', offset + 'px');

    const armed = Math.abs(offset) >= active.width * ARM_FRACTION;
    if (armed !== active.armed) {
      active.armed = armed;
      active.row.classList.toggle('is-armed', armed);
    }
  }

  function onPointerUp(event) {
    if (active === null || event.pointerId !== active.pointerId) { return; }
    const { row, id, locked, armed } = active;
    active = null;

    try { row.releasePointerCapture(event.pointerId); } catch { /* not fatal */ }

    /* Never locked: this was a tap or a scroll. Leave it entirely alone so the
       click event still reaches inline-edit.js. */
    if (!locked) {
      reset(row, false);
      return;
    }

    if (!armed) {
      /* Removing .is-swiping first restores the CSS transition, so setting the
         offset to zero on the next line animates the spring-back instead of
         snapping. Order matters. */
      reset(row, true);
      return;
    }

    commitDelete(row, id);
  }

  function onPointerCancel(event) {
    if (active === null || event.pointerId !== active.pointerId) { return; }
    const row = active.row;
    active = null;
    reset(row, true);
  }

  /**
   * The row is gone from the screen from this point on; the only question is
   * whether it comes back.
   */
  function commitDelete(row, id) {
    const message = typeof label === 'function'
      ? label(row, id)
      : (typeof label === 'string' && label !== ''
        ? label
        : (rowLabel(row) === '' ? 'Deleted.' : 'Deleted “' + rowLabel(row) + '”'));

    hide(row);

    /* Fire the delete immediately rather than at the end of the undo window.
       The server is the source of truth: if the tab is closed or the phone
       sleeps during those five seconds, the item must be gone, not resurrected
       by a timer that never ran. Undo is therefore a RESTORE, not a
       cancellation — see the onUndo contract. */
    Promise.resolve()
      .then(() => onDelete(id))
      .then(() => {
        showSnackbar(message, {
          actionLabel: typeof onUndo === 'function' ? 'Undo' : '',
          onAction: typeof onUndo === 'function' ? () => restore(row, id) : null,
          /* Expiry is when the deletion becomes permanent as far as the DOM is
             concerned. Dropping the element here rather than on delete is what
             lets Undo bring back this exact node, with whatever listeners and
             scroll position it had. */
          onExpire: () => row.remove(),
        });
      })
      .catch((err) => {
        /* The server refused. Put the row back — the user's list must never
           disagree with the database in the direction of showing less than
           there is. */
        unhide(row);
        showSnackbar(errorMessage(err), { isError: true });
      });
  }

  function restore(row, id) {
    unhide(row);
    Promise.resolve()
      .then(() => onUndo(id))
      .then((result) => {
        /* A restore that re-INSERTs rather than un-deletes hands back a new id.
           Adopting it here means the caller doesn't have to re-render the list
           just to fix one attribute. */
        const newId = result && typeof result === 'object' ? result.id : result;
        if (newId !== undefined && newId !== null && String(newId) !== '') {
          row.dataset.id = String(newId);
        }
      })
      .catch((err) => {
        /* The row is already deleted server-side and the restore failed, so it
           has to go for good. hide() only collapses it — the snackbar that owned
           `onExpire: row.remove()` was torn down by the Undo click that got us
           here, and a dismissed snackbar never fires onExpire. Without handing
           the removal to this one, the <li> stays in the DOM forever, collapsed
           and invisible, and the only visible symptom is a category heading
           that lingers reporting a count of 0. */
        hide(row);
        showSnackbar(errorMessage(err), { isError: true, onExpire: () => row.remove() });
      });
  }

  function hide(row) {
    row.classList.remove('is-swiping', 'is-armed');
    row.classList.add('is-removing');
    row.style.setProperty('--swipe-dx', '-' + Math.round(row.getBoundingClientRect().width || 400) + 'px');
    row.setAttribute('aria-hidden', 'true');
  }

  function unhide(row) {
    row.classList.remove('is-removing');
    row.removeAttribute('aria-hidden');
    /* The transition on max-height runs from the class change; the offset has
       to be cleared in the same frame or the row reappears already slid out. */
    row.style.setProperty('--swipe-dx', '0px');
    window.setTimeout(() => { row.style.removeProperty('--swipe-dx'); }, SLIDE_MS);
  }

  /**
   * The pointer-free way to delete: a trash button on the row.
   *
   * It lives here rather than in the three tab modules so that a button press
   * and a swipe are not two implementations of "delete" that can drift — this
   * runs the identical commitDelete(), so the snackbar, the five-second undo,
   * the restore and the id adoption all behave the same whichever way you
   * reached them.
   *
   * Delegated from the root, so rows added by fetch() after page load are
   * covered without anyone remembering to re-bind. The button is a <button
   * type="button"> inside the row's form, so no-JS keeps its own POST path and
   * this never sees the click.
   */
  function onDeleteClick(event) {
    const button = event.target.closest(deleteSelector);
    if (!button || !el.contains(button)) { return; }

    const row = button.closest(rowSelector);
    if (!row || row.classList.contains('is-removing')) { return; }

    /* Mid-edit, the visible text is an unsaved <input>. Deleting out from under
       it would throw away something typed and never stored. */
    if (row.classList.contains('is-editing')) { return; }

    event.preventDefault();
    commitDelete(row, row.dataset.id || '');
  }

  el.addEventListener('click', onDeleteClick);
  el.addEventListener('pointerdown', onPointerDown);
  /* Move and up go on the document, not the root: a fast swipe leaves the row —
     and sometimes the list — before the pointer is released, and a listener
     scoped to the root would then never see the release and leave the row stuck
     mid-slide. */
  document.addEventListener('pointermove', onPointerMove);
  document.addEventListener('pointerup', onPointerUp);
  document.addEventListener('pointercancel', onPointerCancel);

  return function detach() {
    el.removeEventListener('click', onDeleteClick);
    el.removeEventListener('pointerdown', onPointerDown);
    document.removeEventListener('pointermove', onPointerMove);
    document.removeEventListener('pointerup', onPointerUp);
    document.removeEventListener('pointercancel', onPointerCancel);
    active = null;
  };
}

/** Whatever the failure was, said in one line a person can read. */
function errorMessage(err) {
  if (err && err.code === 'unauthorized') { return 'Signed out — reload to sign in again.'; }
  if (err && err.code === 'network_unreachable') { return 'No connection. Nothing was changed.'; }
  return 'That didn’t save. Nothing was changed.';
}
