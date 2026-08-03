/* Drag to reorder rows, by an explicit handle.
 *
 * WHY A HANDLE AND NOT LONG-PRESS. Long-press-to-drag is the familiar iOS
 * pattern and it is the wrong one inside a scrolling list on the web: by the
 * time a 500ms press threshold fires, the browser has usually already claimed
 * the gesture as a pan, so the drag either never starts or starts with the list
 * sliding underneath it. Cancelling the scroll after the fact requires
 * touch-action:none on the rows, which breaks scrolling everywhere else. A
 * handle scopes touch-action:none to 34 pixels — see .drag-handle in
 * styles.css — and is unambiguous from the very first pixel of movement.
 *
 * HOW IT MOVES. The dragged row is translated with the finger via --drag-dy,
 * and the actual DOM order is changed live as it passes each neighbour's
 * midpoint. So the list you are looking at during the drag IS the list, and the
 * drop is just letting go — there is no separate "commit" step that could
 * disagree with what you saw.
 *
 * The translate lives on the <li> while swipe.js's lives on .row-slide inside
 * it, so the two gestures never contend for the same property.
 *
 * KNOWN LIMIT: no edge autoscroll. Dragging to the bottom of the viewport stops
 * at the bottom of the viewport. The Want to Buy list is expected to be dozens
 * of rows, where a scroll-then-drag is fine; adding autoscroll means running a
 * rAF loop for the whole gesture and it is not worth it until the list is long
 * enough to need it.
 *
 * DEGRADATION: no Pointer Events or no JS — the handle is inert decoration and
 * the list renders in its stored order. Nothing else depends on this module.
 */

const MAX_SWAPS_PER_MOVE = 50;   // loop guard; a real drag does 0 or 1

function resolveRoot(root) {
  return typeof root === 'string' ? document.querySelector(root) : root;
}

/**
 * Attach drag-to-reorder to every row with a handle inside `root`.
 *
 * @param {Element|string} root  the container whose CHILD rows are reordered.
 *        Rows must be siblings of each other with no margin between them —
 *        `.list` satisfies this; a list broken into groups does not, and
 *        dragging across a group boundary is not supported.
 * @param {object} opts
 * @param {(orderedIds: string[]) => (void|Promise<any>)} opts.onReorder
 *        Called once on drop, only when the order actually changed, with every
 *        row's data-id in the new top-to-bottom order. Reject to refuse — the
 *        rows snap back to the order they had when the drag started.
 * @param {string} [opts.handleSelector='.drag-handle']
 * @param {string} [opts.rowSelector='.list-row']
 * @returns {() => void} detach
 */
export function attachReorder(root, opts = {}) {
  const el = resolveRoot(root);
  const noop = () => {};
  if (!el) { return noop; }
  if (typeof window.PointerEvent !== 'function') { return noop; }

  const {
    onReorder,
    handleSelector = '.drag-handle',
    rowSelector = '.list-row',
  } = opts;

  if (typeof onReorder !== 'function') {
    throw new TypeError('attachReorder: onReorder is required');
  }

  let drag = null;

  /** Rows in DOM order, skipping anything mid-delete or hidden. */
  function rows() {
    return Array.from(el.querySelectorAll(rowSelector))
      .filter((row) => !row.classList.contains('is-removing') && !row.hidden);
  }

  function neighbour(row, direction) {
    let node = direction > 0 ? row.nextElementSibling : row.previousElementSibling;
    while (node && (!node.matches(rowSelector)
      || node.classList.contains('is-removing')
      || node.hidden)) {
      node = direction > 0 ? node.nextElementSibling : node.previousElementSibling;
    }
    return node;
  }

  function onPointerDown(event) {
    if (drag !== null) { return; }
    if (event.pointerType === 'mouse' && event.button !== 0) { return; }

    const handle = event.target.closest(handleSelector);
    if (!handle || !el.contains(handle)) { return; }

    const row = handle.closest(rowSelector);
    if (!row || !el.contains(row)) { return; }
    if (row.classList.contains('is-editing') || row.classList.contains('is-removing')) { return; }

    /* Stops the press turning into a text selection or an image drag. The touch
       case is already handled by touch-action:none on the handle; this is the
       mouse and the iOS selection magnifier. */
    event.preventDefault();

    drag = {
      row,
      pointerId: event.pointerId,
      baseY: event.clientY,
      lastY: event.clientY,
      before: rows().map((r) => r.dataset.id || ''),
      moved: false,
    };

    try { handle.setPointerCapture(event.pointerId); } catch { /* not fatal */ }
    drag.handle = handle;

    row.classList.add('is-dragging');
    el.classList.add('is-reordering');
  }

  function apply() {
    drag.row.style.setProperty('--drag-dy', (drag.lastY - drag.baseY) + 'px');
  }

  function onPointerMove(event) {
    if (drag === null || event.pointerId !== drag.pointerId) { return; }

    drag.lastY = event.clientY;
    apply();

    if (Math.abs(drag.lastY - drag.baseY) > 2) { drag.moved = true; }

    /* Walk past as many neighbours as the finger has overtaken. Normally that
       is zero or one per event; the loop exists for a flick, and for the case
       where rows have very different heights because one of them wrapped onto
       three lines.

       Each swap moves the row in the DOM, which shifts its LAYOUT position by
       the neighbour's height. baseY absorbs exactly that shift, so the row does
       not visibly jump — it stays under the finger while the list rearranges
       around it. That compensation is the whole trick, and it is why rows must
       have no margin between them: a margin is layout movement this arithmetic
       doesn't know about. */
    for (let guard = 0; guard < MAX_SWAPS_PER_MOVE; guard++) {
      const rect = drag.row.getBoundingClientRect();
      const center = rect.top + (rect.height / 2);

      const next = neighbour(drag.row, 1);
      if (next) {
        const nextRect = next.getBoundingClientRect();
        if (center > nextRect.top + (nextRect.height / 2)) {
          next.after(drag.row);
          drag.baseY += nextRect.height;
          apply();
          continue;
        }
      }

      const prev = neighbour(drag.row, -1);
      if (prev) {
        const prevRect = prev.getBoundingClientRect();
        if (center < prevRect.top + (prevRect.height / 2)) {
          prev.before(drag.row);
          drag.baseY -= prevRect.height;
          apply();
          continue;
        }
      }

      break;
    }
  }

  function finish(event, cancelled) {
    if (drag === null || event.pointerId !== drag.pointerId) { return; }
    const { row, handle, before, moved } = drag;
    drag = null;

    try { handle.releasePointerCapture(event.pointerId); } catch { /* not fatal */ }

    row.style.removeProperty('--drag-dy');
    row.classList.remove('is-dragging');
    el.classList.remove('is-reordering');

    if (cancelled || !moved) { return; }

    const after = rows().map((r) => r.dataset.id || '');
    if (after.length === before.length && after.every((id, i) => id === before[i])) { return; }

    Promise.resolve()
      .then(() => onReorder(after))
      .catch(() => {
        /* The save failed, so put the list back the way it was. Leaving it in
           the new order would show an order the database does not have, and the
           next reload would silently undo work the user watched succeed. */
        restore(before);
      });
  }

  function restore(order) {
    const byId = new Map(rows().map((row) => [row.dataset.id || '', row]));
    let anchor = null;
    for (const id of order) {
      const row = byId.get(id);
      if (!row) { continue; }
      if (anchor === null) {
        row.parentNode.prepend(row);
      } else {
        anchor.after(row);
      }
      anchor = row;
    }
  }

  const onUp = (event) => finish(event, false);
  const onCancel = (event) => finish(event, true);

  el.addEventListener('pointerdown', onPointerDown);
  document.addEventListener('pointermove', onPointerMove);
  document.addEventListener('pointerup', onUp);
  document.addEventListener('pointercancel', onCancel);

  return function detach() {
    el.removeEventListener('pointerdown', onPointerDown);
    document.removeEventListener('pointermove', onPointerMove);
    document.removeEventListener('pointerup', onUp);
    document.removeEventListener('pointercancel', onCancel);
    drag = null;
  };
}
