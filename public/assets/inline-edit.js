/* Tap a row's TEXT to edit it in place.
 *
 * The author asked for exactly this: "I want to tap the text to edit the text."
 * Not an edit button, not a detail screen, not a long-press menu — the text is
 * the control.
 *
 * The consequence, and it is the important one for whoever builds the Grocery
 * tab: tapping the text can no longer mean anything else. Re-categorizing an
 * item therefore needs its OWN control, which is why .row-cat exists as a
 * <button> in styles.css. Don't put a second meaning on the text.
 *
 * WHAT COUNTS AS A TAP is the whole difficulty. A swipe and a scroll both begin
 * with a pointerdown on the text and both may end with a click event, so
 * listening for `click` alone opens the editor every time you flick past the
 * list. This module tracks the pointer itself and opens only when the pointer
 * moved less than 10px, matching swipe.js's lock distance exactly — so there is
 * no gesture that both starts a swipe and opens an editor.
 *
 * DEGRADATION: with no JS the text is plain text. Nothing else on the row
 * depends on this module.
 */

const MOVE_TOLERANCE = 10;   // px — must match LOCK_DISTANCE in swipe.js

function resolveRoot(root) {
  return typeof root === 'string' ? document.querySelector(root) : root;
}

/**
 * Attach tap-to-edit to every row's text inside `root`, now and in future.
 *
 * Delegated to the root, so a re-rendered list needs no re-attach.
 *
 * @param {Element|string} root
 * @param {object} opts
 * @param {(id: string, text: string, row: Element) => (void|Promise<any>)} opts.onSave
 *        Called with the trimmed new text, only when it actually differs.
 *        Reject to refuse the edit: the old text is put back.
 *        Resolve to a string to have THAT rendered instead of what was typed —
 *        for a server that normalizes, so the row shows what was really stored.
 * @param {string} [opts.textSelector='.row-text']
 * @param {string} [opts.rowSelector='.list-row']
 * @param {number} [opts.maxLength] mirrors the column width; see docs/CONTRACTS.md
 * @param {(row: Element) => boolean} [opts.canEdit] veto per row
 * @returns {() => void} detach
 */
export function attachInlineEdit(root, opts = {}) {
  const el = resolveRoot(root);
  const noop = () => {};
  if (!el) { return noop; }

  const {
    onSave,
    textSelector = '.row-text',
    rowSelector = '.list-row',
    maxLength = 0,
    canEdit = null,
  } = opts;

  if (typeof onSave !== 'function') {
    throw new TypeError('attachInlineEdit: onSave is required');
  }

  /* Where the pointer went down, and whether it stayed put. Recorded on the
     way down because by the time `click` fires the movement is history. */
  let down = null;

  /* Exactly one editor at a time. A second one would be two inputs racing to
     save the same row on blur. */
  let editing = null;

  function onPointerDown(event) {
    if (event.pointerType === 'mouse' && event.button !== 0) { return; }
    const text = event.target.closest(textSelector);
    down = text && el.contains(text)
      ? { x: event.clientX, y: event.clientY, text, moved: false }
      : null;
  }

  function onPointerMove(event) {
    if (down === null || down.moved) { return; }
    if (Math.abs(event.clientX - down.x) >= MOVE_TOLERANCE
      || Math.abs(event.clientY - down.y) >= MOVE_TOLERANCE) {
      down.moved = true;
    }
  }

  function onClick(event) {
    const record = down;
    down = null;

    const text = event.target.closest(textSelector);
    if (!text || !el.contains(text)) { return; }

    /* No pointer record at all means the click came from somewhere that isn't
       a pointer — a keyboard Enter on a focused element, a synthetic click.
       Those are legitimate; only a recorded, moved pointer is disqualifying. */
    if (record !== null && (record.moved || record.text !== text)) { return; }

    /* A tap that ends a text selection is someone reading, not editing.
       Replacing the node would drop their selection mid-gesture. */
    const selection = window.getSelection();
    if (selection && !selection.isCollapsed && selection.containsNode(text, true)) { return; }

    const row = text.closest(rowSelector);
    if (!row) { return; }
    if (row.classList.contains('is-removing') || row.classList.contains('is-dragging')) { return; }
    if (canEdit && !canEdit(row)) { return; }

    open(row, text);
  }

  function open(row, text) {
    if (editing !== null) { return; }

    const original = text.textContent;

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'row-edit';
    input.value = original;
    /* No autocorrect or autocapitalize. grocery_items.name stores exactly what
       was typed, parentheses and all — an editor that helpfully capitalises
       "oat milk (barista)" is rewriting data the schema promises not to
       change. */
    input.setAttribute('autocomplete', 'off');
    input.setAttribute('autocorrect', 'off');
    input.setAttribute('autocapitalize', 'off');
    input.setAttribute('spellcheck', 'false');
    input.setAttribute('aria-label', 'Edit item');
    input.enterKeyHint = 'done';
    if (maxLength > 0) { input.maxLength = maxLength; }

    text.hidden = true;
    text.after(input);
    row.classList.add('is-editing');

    editing = { row, text, input, original, closed: false };

    input.focus();
    /* Select all, so the common case — retyping the whole thing — is one
       gesture, while tapping again inside the field places a caret for the
       other common case, fixing a typo. */
    input.select();

    input.addEventListener('keydown', onKeyDown);
    input.addEventListener('blur', onBlur);
  }

  function onKeyDown(event) {
    if (editing === null) { return; }
    if (event.key === 'Enter') {
      event.preventDefault();
      commit();
    } else if (event.key === 'Escape') {
      event.preventDefault();
      cancel();
    }
  }

  function onBlur() {
    /* Blur saves, matching Enter. On a phone there is no reliable "done" —
       tapping elsewhere on the list IS how you finish, and discarding the edit
       there would silently throw the typing away. Escape is the explicit
       cancel, and it closes the editor before the blur it causes arrives. */
    if (editing !== null && !editing.closed) { commit(); }
  }

  function close(session, finalText) {
    session.closed = true;
    session.input.removeEventListener('keydown', onKeyDown);
    session.input.removeEventListener('blur', onBlur);
    session.input.remove();
    session.text.textContent = finalText;
    session.text.hidden = false;
    session.row.classList.remove('is-editing');
    if (editing === session) { editing = null; }
  }

  function cancel() {
    if (editing === null) { return; }
    close(editing, editing.original);
  }

  function commit() {
    if (editing === null) { return; }
    const session = editing;
    const value = session.input.value.trim();

    /* An empty name is a delete in disguise, and delete has its own gesture
       with its own undo. Silently turning a cleared field into a deletion is
       exactly the sort of thing you cannot undo because you didn't notice it.
       Treat it as a cancel. */
    if (value === '' || value === session.original.trim()) {
      close(session, session.original);
      return;
    }

    /* Render the new text immediately. The row reads correctly during the round
       trip, and the failure path below puts the old text back — better than a
       row that sits showing the old value while the server already has the new
       one. */
    close(session, value);

    const id = session.row.dataset.id || '';
    Promise.resolve()
      .then(() => onSave(id, value, session.row))
      .then((stored) => {
        /* A server that normalizes (trims, collapses whitespace) can hand back
           what it actually saved, so the row shows the truth rather than the
           request. */
        if (typeof stored === 'string' && stored !== '' && stored !== value) {
          session.text.textContent = stored;
        }
      })
      .catch(() => {
        session.text.textContent = session.original;
      });
  }

  el.addEventListener('pointerdown', onPointerDown);
  el.addEventListener('pointermove', onPointerMove);
  el.addEventListener('click', onClick);

  return function detach() {
    cancel();
    el.removeEventListener('pointerdown', onPointerDown);
    el.removeEventListener('pointermove', onPointerMove);
    el.removeEventListener('click', onClick);
  };
}
