# Interface Contracts

Four feature tracks are built in parallel against these contracts. **Nothing
here changes without updating this file first** — someone else is writing code
against it right now. If you need a change in someone else's territory, note it
in your final report rather than editing across the line.

The Foundation layer is done. Everything in §2–§6 already exists, is linted, and
is exercised by `php tools/run-tests.php` (189 assertions, green). You should not
need to write a line of CSS, a gesture handler, or a single line of date
arithmetic.

`docs/PLAN.md` is the specification this was built from and the record of why
the data model looks the way it does. `CLAUDE.md` has the house style and the
"things that look like bugs but are decisions" list. Read both.

---

## 0. Ground rules for every module

- **No build step.** The deployed app is plain files uploaded by FTP to
  Hostinger. ES modules (`<script type="module">`), no bundler, no npm in
  `public/`.
- **PHP 8.4**, `declare(strict_types=1)`, PDO with prepared statements only.
  Never interpolate a variable into SQL — always `q($sql, $params)`.
- `array()` long syntax, matching the sibling apps. `tools/run-tests.php`
  checks this mechanically.
- **Mobile-first.** Minimum tap target `--tap` (48px). Inputs are 16px or iOS
  zooms on focus and never zooms back.
- **Use the design system.** `public/assets/styles.css` is Foundation-owned and
  complete (§4), and is a verbatim copy of Grocery's. **Do not add a `<style>`
  block and do not edit `styles.css`** — if you genuinely need a new component,
  report it.
- **Use the shared JS modules** (§5). Import them; don't fork them.
- **Never ask what day it is.** `crm_today()` is the only function in the app
  allowed to, and it is called once per request. Everything else takes `$today`.
  See §3 and `lib/dates.php`.
- **Fail soft.** A person with an unparseable birthday renders without one. A
  failed write shows a snackbar. A malformed row degrades one row, never a
  screen.
- **Comment the "why", not the "what."** Match the density in `lib/auth.php`,
  `lib/dates.php` and `schema.sql`.
- Every entry point starts with `require_once __DIR__ . '/../lib/bootstrap.php';`
- Escape on output with `h()`. `<?= h($x) ?>`, never a bare echo of DB data.

---

## 1. File ownership

| Owner | Files |
|---|---|
| **Foundation** (done) | `schema.sql`, `config.example.php`, `.htaccess`, `lib/.htaccess`, `tools/.htaccess`, `uploads/.htaccess`, `lib/bootstrap.php`, `lib/db.php`, `lib/auth.php`, `lib/layout.php`, `lib/dates.php`, `public/login.php`, `public/logout.php`, `public/assets/styles.css`, `public/assets/api.js`, `public/assets/swipe.js`, `public/assets/inline-edit.js`, `public/assets/reorder.js`, `public/assets/menu.js`, `tools/make-hash.php`, `tools/test-harness.php`, `tools/run-tests.php`, `tools/build-deploy.php`, `CLAUDE.md`, `docs/CONTRACTS.md` |
| **P · People** (Phase 1) | `public/people.php`, `public/person.php`, `public/add.php`, `lib/people.php`, `public/api/person-*.php`, `public/api/tag-*.php`, `public/assets/people.js`, `public/assets/person.js`, `public/assets/add.js` |
| **I · Interaction** (Phase 2A) | `lib/contact.php`, `public/api/gift-*.php`, `public/api/contact-*.php` |
| **R · Reminders** (Phase 2B) | `lib/reminders.php`, `public/index.php`, `public/assets/dashboard.js`, `public/api/reminder-*.php` |
| **M · Import** (Phase 2C) | `lib/vcard.php`, `lib/import.php`, `public/import.php`, `public/assets/import.js`, `public/api/import-*.php`, `tests/fixtures/*.vcf` |
| **Delivery** (Phase 3) | `lib/mailer.php`, `lib/vendor/PHPMailer/*`, `tools/cron-reminders.php`, `tools/send-test-email.php`, `public/cron.php`, `DEPLOY.txt` |

Add your own tests to `tools/run-tests.php` in a new `section()`. Don't rewrite
the foundation sections; they are the regression net for the schema, the date
arithmetic and the helpers everyone shares.

### The two shared files, and the rule that keeps them shared

Two screens are written by more than one track. Both are **owned by P**, which
builds the skeleton; the other tracks fill in one marked region each and touch
nothing outside it.

**`public/person.php`** — P renders the identity block (name, birthday, phone,
email, address, notes) and the tag pills, and leaves three empty regions,
literally marked.

**As built (Phase 1), the file carries SIX regions, every one of them opened and
closed, in this order and no other.** P's three are marked too, so that the
boundary between P's markup and yours is explicit rather than inferred:

```php
<!-- REGION: identity — owned by P -->
<!-- END REGION: identity -->

<!-- REGION: tags — owned by P -->
<!-- END REGION: tags -->

<!-- REGION: reminders — owned by R -->
<!-- END REGION: reminders -->

<!-- REGION: gifts — owned by I -->
<!-- END REGION: gifts -->

<!-- REGION: log — owned by I -->
<!-- END REGION: log -->

<!-- REGION: danger — owned by P -->
<!-- END REGION: danger -->
```

Copy those strings verbatim — the em dash is a real em dash and
`tools/run-tests.php` asserts the set, the order and the owner letters. Add
markup **inside** your region only. `danger` is the "Delete this person" control
and sits below the log deliberately: it is the last thing on the screen because
it is the last thing you should be able to reach for.

All six live inside an `if` that renders the profile; the `?delete=1`
confirmation screen renders in place of all of them, and `?edit=1` swaps the
identity region's read view for the edit form. Neither affects your region's
markers.

`public/assets/person.js` is P's file and each track appends its own `attach*`
call at the bottom, in the same order, inside the matching JS markers P laid
down there:

```js
/* REGION: reminders — owned by R */
/* END REGION: reminders */
```

(plus `gifts` and `log`). `identity`, `tags` and `danger` have no JS region —
P's own wiring sits above the marked block.

**`public/index.php`** — the Today dashboard, and **R owns it outright**. P may
land a placeholder so the app has a home page during Phase 1; R replaces it
wholesale and owes it nothing. As built it is nine lines: bootstrap,
`require_login_page()`, a 302 to `people.php`. Delete all of it.

### What Phase 1 landed that the other tracks need to know

Four things settled while building People that are not in the original text:

- **`people_add(array $fields, string $today)` and
  `people_save(int $id, array $fields, string $today)` already take `$today`,
  and Phase 1 does not read it.** It is there for R: §2 and `schema.sql` both
  name `people_save()` as one of the two places a birthday reminder is
  reconciled, and both functions carry a marked `HOOK, PHASE 2B` comment at
  exactly the point the call goes. **R adds one line inside each hook and a
  `require_once` for `lib/reminders.php` at the top of `lib/people.php` — that
  is the whole of R's footprint in P's file**, and it needs no signature change
  and no edit to `add.php`, `person.php`, `person-add.php` or
  `person-update.php`.

- **`lib/people.php` carries one function that renders markup**,
  `people_form_fields(?array $person)`: the seven identity controls, including
  the three birthday inputs. It is shared because `add.php` and `person.php`
  both render that form and the yearless-birthday case is the thing two copies
  would drift on. Nothing else in `lib/` prints anything.

- **`tag-delete.php` was deliberately not built** — see §6.

- **`tools/run-tests.php`'s SHARED_MODULES check changed shape.** It used to
  assert that the `.js` files on disk were exactly `SHARED_MODULES`, which only
  held while no screen had an entry script. It now scans every module for
  `from './x.js'` and asserts each imported name is declared — which is what
  `lib/layout.php`'s comment always described, and what actually catches the
  bug. Same assertion count; a feature module of your own needs no change to it.

---

## 2. The database

`schema.sql` is the contract — read it, it's commented, and it's exercised by
the test harness. Ten tables:

`people` · `relationship_tags` · `person_tag_map` · `gift_ideas` ·
`contact_log` · `reminders` · `reminder_sends` · `import_batches` ·
`import_drafts` · `login_attempts`

Nine things that will bite you if you miss them:

- **A birthday is three columns.** `birth_year` / `birth_month` / `birth_day`,
  all nullable. `birth_month IS NULL` means **no birthday recorded**;
  `birth_year IS NULL` alongside a month and day means **the birthday is known
  and the year is not** — which is the normal case out of a phone's vCard
  export (`BDAY:--0415`). Those are different states and the UI must not
  collapse them. There is no sentinel year, ever.

- **`people.last_contact_date` is NULL for "never", and NULL is a real answer.**
  Do not `COALESCE` it to `created_at`. The People list renders "never" as a
  word. `days_since()` returns `null` for it, deliberately, rather than 0.

- **`people.name_key` is NOT unique**, and neither is `name`. Two people really
  can be called James Smith. `name_key` exists so the import dupe check is one
  indexed lookup instead of a `LOWER(name)` full scan. It **flags**, it never
  refuses.

- **Every due-date query is `WHERE next_due_date <= ?`.** Pass a `Y-m-d` string
  from `crm_today()`. There is no `DATE_ADD`, no `INTERVAL`, no `CURDATE()`
  anywhere in this app, and adding one breaks the test harness as well as the
  one-clock rule.

- **`reminders` has `UNIQUE (person_id, type)`.** One reach-out schedule and one
  birthday schedule per person. Changing a reminder is an UPSERT, not a
  delete-then-insert. `recurrence_interval_days` is NULL for a one-off
  reach-out, and always NULL for a birthday (annual is implicit in the type).

- **`reminder_sends`' primary key is `(reminder_id, due_date)`.** The cron's
  step is:

  ```sql
  INSERT INTO reminder_sends (reminder_id, due_date) VALUES (?, ?)
    ON DUPLICATE KEY UPDATE attempts = attempts + 1
  ```

  and it sends only when it inserted a fresh row or found one with `sent_at`
  still NULL. Write it in MySQL — `tools/test-harness.php` translates it into
  SQLite's `ON CONFLICT DO UPDATE` on the way through, so this exact statement
  is testable.

- **A reach-out reminder does NOT advance `next_due_date` when it sends.** Only
  birthdays roll forward (to next year's lead date). What moves a reach-out is
  logging a contact: set `last_contact_date`, insert the `contact_log` row, and
  recompute `next_due_date = next_cadence_date(...)` for a cadence reminder, or
  delete the reminder outright for a one-off that has now been satisfied.

- **`import_drafts.dup_person_id` is a flag, not a foreign key.** Nothing may
  act on it except to render a warning pill. It does not cascade, it does not
  block deleting the person it names, and promoting a flagged draft creates a
  **second** person.

- **Everything cascades from `people`.** Deleting a person removes their tag
  links, gift ideas, contact log, reminders, and — two levels down — the send
  ledger for those reminders. Deleting a `relationship_tags` row removes its
  links. Deleting an `import_batches` row prunes its drafts. There are no
  tombstone columns and no soft deletes anywhere in this app.

---

## 3. Shared helpers you already have

| Helper | From | Does |
|---|---|---|
| `cfg('db.host')` | `bootstrap.php` | dot-notation config read, with a default |
| `json_out()` / `json_error()` / `json_body()` / `require_method()` | `bootstrap.php` | JSON endpoint plumbing |
| `h($s)` | `bootstrap.php` | `htmlspecialchars` shorthand; `null` → `''` |
| `fmt_date($date, $format = 'F j, Y')` | `bootstrap.php` | render a stored DATE or DATETIME for a person. **`null` and unparseable both render `''`**, never a guess |
| `asset('assets/people.js')` | `bootstrap.php` | mtime cache-busted URL, already escaped |
| `fatal_error($code, $human)` | `bootstrap.php` | unrecoverable failure, rendered per caller: JSON under `/api/`, HTML elsewhere, STDERR on CLI |
| `db()` / `q($sql, $params)` | `db.php` | PDO handle / bound query |
| `require_login_page()` | `auth.php` | **the gate for every HTML screen.** Redirects to `login.php?next=…` and emits `noindex()` itself |
| `require_login_api()` | `auth.php` | the gate for every JSON endpoint; 401 `unauthorized` |
| `require_same_origin()` | `auth.php` | CSRF check on every mutating endpoint |
| `noindex()` | `auth.php` | `X-Robots-Tag` header; already called by `require_login_page()` |
| `page_head()` / `screen_head()` / `page_foot()` | `layout.php` | page chrome + tab bar |
| `page_menu($id = 'app-menu')` | `layout.php` | the hamburger button, for `screen_head()`'s `$asideHtml`. Returns HTML |
| `nav_tabs()` | `layout.php` | the three tabs; keys `today`, `people`, `add` |
| `harness_pdo()` | `tools/test-harness.php` | in-memory SQLite from `schema.sql`, installed as `db()`'s connection. **CLI/tests only** |

### `lib/dates.php` — all of the date arithmetic, already written

**Already loaded** — `lib/bootstrap.php` requires it alongside `db.php` and
`auth.php`, on purpose: `crm_today()` reads PHP's default zone, and a caller
that required this file on its own would still work while silently running on
the server's clock.

| Function | Does |
|---|---|
| `crm_today(): string` | today as `Y-m-d`, in the configured zone. **The only impure function in the app.** Call it once and pass the string down |
| `next_birthday(int $m, int $d, string $today): string` | the next occurrence of that month/day, **on or after** `$today` |
| `birthday_reminder_date(int $m, int $d, string $today, int $lead): string` | the above, minus `$lead` days. **May return a date in the past**, which is correct — see below |
| `next_cadence_date(?string $lastContact, int $days, string $today): string` | `lastContact + days`, or `today + days` when never contacted |
| `days_since(?string $date, string $today): ?int` | whole days, for the "34 days ago" subline. **`null` in, `null` out** |
| `fmt_relative_due(string $due, string $today): string` | `Today` · `Tomorrow` · `Yesterday` · `3 days ago` · `Friday` · `August 10` |

Four behaviours you can rely on and must not re-implement:

- **Feb 29 becomes Feb 28 in a non-leap year**, so its reminder fires on Feb 21.
  An impossible day out of a bad import (`--0631`) clamps to the end of its
  month rather than throwing.
- **A January 3rd birthday reminds on December 27th of the previous year.**
  `birthday_reminder_date()` finds the birthday first and subtracts second.
  Do not compute this yourself; done backwards it is 358 days late and looks
  right in a spot check.
- **A returned reminder date can be earlier than `$today`.** Both the dashboard
  and the cron read `next_due_date <= $today`, so an overdue reminder fires
  immediately instead of being skipped for a year.
- **All arithmetic happens in UTC internally**, so a DST transition cannot make
  a day 23 hours long and round a diff to the wrong side. `crm_today()` is where
  the configured zone is applied, and it is the only place it needs to be.

### The shape of every screen

```php
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/layout.php';
require_login_page();
page_head('People', 'people');
screen_head('People', page_menu());
// markup
page_foot('people');
```

`page_menu()` goes in the `$asideHtml` slot on **every** screen. The menu itself
is wired up in that screen's entry script — see §5, `menu.js`.

### The shape of every JSON endpoint

```php
require_once __DIR__ . '/../../lib/bootstrap.php';
require_login_api();
require_same_origin();
require_method('POST');
```

Mutating `fetch()` calls **must** send `X-Requested-With: CRM` or
`require_same_origin()` 403s them. `assets/api.js` does this for you.

---

## 4. Component vocabulary — `public/assets/styles.css`

Foundation-owned, and a **verbatim copy of Grocery's stylesheet**. Write markup
against these; don't edit the file.

### Chrome and shared components

| Class | Use |
|---|---|
| `.wrap` | the centred column; already clears the tab bar and the home indicator |
| `.screen-head` + `h1` | title with the signature teal rule |
| `.head-actions` | right-hand slot in the heading row (pass as `screen_head()`'s `$asideHtml` — this is where `page_menu()` goes) |
| `.tabbar` / `.tabbar a.is-active` | rendered by `page_foot()`; you never write this |
| `.card`, `.pill`, `.pill.is-plain`, `.empty`, `.hint`, `.muted`, `.sr-only`, `.hidden` | the usual. `.pill` is the relationship tag; `.pill.is-plain` is the duplicate warning on an import draft |
| `.field` + `<span>` + `input`/`textarea`/`select`, `.input`, `.field-err` | forms |
| `.btn-primary` / `.btn-secondary` / `.btn-ghost` / `.btn-danger` | buttons; work on `<a>` too. `.btn-danger` is "Delete this person" |
| `.link-btn` / `.tap-text` / `.tap-text.danger` | underlined text actions, 48px tall |
| `.icon-btn` (+ `.is-danger`) | 48×48 square icon button; put an inline `<svg viewBox="0 0 24 24">` in it. This is what `page_menu()` renders |
| `.sheet` / `.sheet-panel` / `.sheet-cancel` / `.sheet-panel button.is-current` | bottom sheet on a phone, centred dialog above 620px. **The relationship-tag picker and the cadence picker should use this.** Scrolls internally. `menu.js` builds one of these |
| `.stack`, `.row`, `.row-between`, `.rule` | layout odds and ends |
| `.login-body` / `.login-card` / `.login-title` / `.login-form` / `.login-error` | login only |

### The quick-add composer

A real `<form>`, so Enter on the phone keyboard submits and it degrades to a
normal POST with no JS. Use it for adding a gift idea and for adding a custom
relationship tag.

```html
<form class="composer">
  <input class="composer-input" type="text" placeholder="Add a gift idea" autocomplete="off">
  <button class="composer-add" type="submit">Add</button>
</form>
```

`.composer-add` deliberately overrides `.btn-primary`'s full width — in a flex
row the button sizes to its label.

### The list row primitive

The swipeable row, shared by the people list, the gift-ideas list, the contact
log and the import-review queue. **Two layers, both load-bearing**: the `<li>`
is the fixed frame (borders, the delete affordance, the collapse animation),
`.row-slide` is the only thing that moves under the finger.

```html
<ul class="list">
  <li class="list-row" data-id="12">
    <div class="row-slide">
      <span class="drag-handle" aria-hidden="true"><svg …></svg></span>
      <button class="row-check" type="button" aria-pressed="false" aria-label="Logged today"></button>
      <div class="row-body">
        <span class="row-text">Alex Chen</span>
        <span class="row-sub">last contacted 34 days ago</span>
      </div>
      <button class="row-cat pill" type="button">Close Friend</button>
      <button class="row-del" type="button" aria-label="Delete"><svg …></svg></button>
    </div>
  </li>
</ul>
```

`data-id` is **required** — every JS module reads it and hands it back to your
callbacks as a string.

| Class | Use |
|---|---|
| `.list` | the `<ul>`. Bordered, rounded, clips the sliding rows. Hidden when empty |
| `.list-row` | one row. Declares `touch-action: pan-y`, which is what stops the swipe hijacking the page scroll |
| `.row-slide` | the moving layer. Everything visible goes inside it |
| `.row-text` | the main text. **Tapping it opens the inline editor** |
| `.row-body` + `.row-text` + `.row-sub` | two-line variant. This is the People list's row: name over "last contacted N days ago" |
| `.row-sub` | the secondary line (last contact, a due date, "phone · email" on an import draft) |
| `.row-check` | the round checkbox. A `<button aria-pressed>`, not an `<input>`. On the dashboard this is the 1-tap "Logged today" |
| `.row-cat` | a pill on the right of the row, **and it is a `<button>`.** Because tapping the text edits the text, this is the only way to open a picker from a row. Opens the `.sheet` |
| `.row-link` | a 48px outbound-link target (a `tel:` or `mailto:`) |
| `.row-del` | the pointer-only delete button. Hidden on touch; `swipe.js` wires it to the identical delete path as the gesture. **You render it, `swipe.js` handles it** |
| `.drag-handle` | the reorder grip. Declares `touch-action: none` |
| `.row-edit` | the inline editor input. **Created by `inline-edit.js`; you never write it** |

Row states — all set by the JS modules unless noted:

| Class | Means | Set by |
|---|---|---|
| `.is-checked` | struck through and dimmed | **you**, server-rendered |
| `.is-grace` | a countdown-to-disappearing state with a draining bar, driven by `--grace-ms`. **Inherited from Grocery and unused here** — nothing in this app has a grace window. Don't invent a use for it | — |
| `.is-swiping` | finger tracking, transition off | `swipe.js` |
| `.is-armed` | past the delete threshold; release deletes | `swipe.js` |
| `.is-removing` | collapsed out of the list, still in the DOM through the undo window | `swipe.js` |
| `.is-editing` | inline editor open; swipe and drag stand down | `inline-edit.js` |
| `.is-dragging` (+ `.list.is-reordering`) | picked up and following the finger | `reorder.js` |

Custom properties the JS sets, listed so you don't reuse the names:
`--swipe-dx` (on `.list-row`, consumed by `.row-slide`), `--drag-dy` (on
`.list-row`), `--grace-ms` (unused here).

### Groups, accordion, snackbar

```html
<section class="cat-group">
  <h2 class="cat-head is-sticky">Family <span class="cat-count">6</span></h2>
  <ul class="list">…</ul>
</section>
```

| Class | Use |
|---|---|
| `.cat-group` | one relationship tag on the People list, or one bucket (Today / This week) on the dashboard — same component |
| `.cat-head` | the small uppercase group heading. Add `.is-sticky` to pin it while scrolling (the People list wants this; the dashboard, with two short groups, probably doesn't) |
| `.cat-count` | the count on the right of the heading |
| `.cat-head button` | a group heading that is itself a control (rename a tag), chrome stripped |

```html
<details class="accordion">
  <summary class="accordion-head">History <span class="accordion-count">43</span></summary>
  <div class="accordion-body">
    <ul class="list">…</ul>
  </div>
</details>
```

`<details>`, not a div plus a click handler: it opens with no JS, is keyboard
operable, and gets the accessibility semantics right without a single aria
attribute. `.accordion-body .list` loses its own border on purpose. **This is
what the contact-log history on a profile should use**, collapsed by default.

`.snackbar` / `.snackbar-msg` / `.snackbar-action` / `.snackbar.is-error` are
**created and removed by `swipe.js`**. Don't hand-write one; call
`showSnackbar()` if you want the affordance for something else.

---

## 5. The shared JS modules

Hand-written ES modules, no dependencies, no build step. All of them are
**generic**: they take a root element or selector plus callbacks, and know
nothing about any screen. All of them **delegate** their listeners to the root,
so a list you re-render after a fetch needs no re-attach. All of them **return a
detach function** and **degrade cleanly** — no Pointer Events, or no JS at all,
and the rows still render and still work; they just don't swipe, edit or drag.

**Adding a new shared module means adding it to `SHARED_MODULES` in
`lib/layout.php`.** Read that function's comment first: a module that isn't in
the list is never cache-busted, and the symptom is a feature that is visibly
there and does nothing, with nothing in any log.

### `api.js`

```js
import { apiGet, apiPost, apiTry, ApiError } from './api.js';

const data = await apiGet('api/person-list.php', { q: 'chen' });
await apiPost('api/contact-log.php', { person_id: 12 });
const result = await apiTry('api/gift-delete.php', { id });   // never throws
```

| Export | Signature |
|---|---|
| `request(method, url, {body, params, signal})` | the primitive |
| `apiGet(url, params?, signal?)` | → parsed JSON |
| `apiPost(url, body?, signal?)` | → parsed JSON |
| `apiTry(url, body?)` | → `{ok: true, data}` or `{ok: false, error}`. Never rejects except on abort |
| `ApiError` | `.code`, `.status`, `.detail` |

- Sends `X-Requested-With: CRM` on every non-GET, satisfying
  `require_same_origin()`.
- Throws `ApiError` on any non-2xx, carrying the server's `{"error": code}`
  string. **Branch on `err.code`**, never on the message. Codes you will see:
  `unauthorized` (401, session expired), `csrf_check_failed` (403),
  `method_not_allowed` (405), `invalid_json` (400), `network_unreachable` (no
  connection at all — synthesised client-side, status 0).
- No retry, no queue, no offline cache. A failed write surfaces immediately.

### `swipe.js` — swipe left to delete, with undo

**Gift ideas and import drafts only. NOT people** — see `CLAUDE.md`.

```js
import { attachSwipeDelete, showSnackbar } from './swipe.js';

attachSwipeDelete('#gifts', {
  onDelete: (id) => apiPost('api/gift-delete.php', { id }),
  onUndo:   (id) => apiPost('api/gift-restore.php', { id }).then((r) => r.id),
  label:    (row, id) => 'Deleted “' + row.querySelector('.row-text').textContent + '”',
});
```

| Option | Meaning |
|---|---|
| `onDelete(id)` **required** | Called the moment the gesture completes; the row is already hidden. Return a promise. **Reject to refuse** — the row comes back and an error snackbar shows |
| `onUndo(id)` | Called when Undo is tapped. May resolve to a replacement id (or `{id}`) if the restore re-inserted; the element's `data-id` is updated. **Omit it and there is no Undo button** — only do that if the delete genuinely can't be undone |
| `label` | string, or `(row, id) => string`. Defaults to `Deleted “<row text>”` |
| `rowSelector` | default `.list-row` |
| `deleteSelector` | default `.row-del` — the pointer-only trash button, which runs the identical delete path |
| `canSwipe(row)` | veto per row |

Behaviour you can rely on:

- **The gesture does not lock until it has moved >10px AND is more horizontal
  than vertical.** Until then the browser scrolls normally; a diagonal scroll is
  abandoned, not converted into a delete.
- Past ~40% of the row width, release deletes. Below that it springs back.
- **`onDelete` fires immediately, not when the snackbar expires.** The server is
  the source of truth: if the tab closes during those five seconds the item must
  be gone. **Undo is therefore a RESTORE, not a cancellation** — your undo
  endpoint has to be able to bring the row back, and it may hand back a new id.
- The `<li>` stays in the DOM through the undo window and is removed on expiry,
  so Undo restores that exact element.
- Gestures that start on `.drag-handle`, on an `<input>`, on a row that is
  `.is-editing`, or on one already `.is-removing` are ignored.

`showSnackbar(message, {actionLabel, onAction, onExpire, ms, isError})` is
exported for the same affordance elsewhere — undoing a "Logged today", say. One
at a time; showing a second expires the first.

### `inline-edit.js` — tap the text to edit it

```js
import { attachInlineEdit } from './inline-edit.js';

attachInlineEdit('#gifts', {
  onSave: (id, text) => apiPost('api/gift-rename.php', { id, idea_text: text }),
  maxLength: 500,   // match the column: 190 person name, 500 gift idea / log note
});
```

| Option | Meaning |
|---|---|
| `onSave(id, text, row)` **required** | Called only when the trimmed text actually changed. **Reject to refuse** — the old text is put back. **Resolve to a string** to render that instead, for a server that normalizes |
| `textSelector` | default `.row-text` |
| `rowSelector` | default `.list-row` |
| `maxLength` | mirrors the column width |
| `canEdit(row)` | veto per row |

Behaviour you can rely on:

- Opens only when the pointer moved less than 10px — the same threshold
  `swipe.js` uses to lock, so **no gesture both starts a swipe and opens an
  editor**. A tap that ends a text selection is ignored too.
- The `<input>` is prefilled and fully selected. **Enter saves. Blur saves.
  Escape cancels.** Blur-saves because on a phone tapping elsewhere *is* how you
  finish, and discarding there would silently throw the typing away.
- **An emptied field cancels rather than deleting.** Delete has its own gesture
  with its own undo.
- Autocorrect, autocapitalize and spellcheck are off. `people.name` is stored
  verbatim and the editor must not "help" — it will title-case "iPhone" and
  autocorrect a surname.
- One editor at a time, app-wide.

### `reorder.js` — drag by the handle

Ported, and **currently unused**: gift ideas sort newest-first and have no
`sort_order` column (`CLAUDE.md`). It costs one file; if ordering turns out to
matter the module is already here.

| Option | Meaning |
|---|---|
| `onReorder(orderedIds)` **required** | `data-id` strings, new top-to-bottom order. Fires once on drop, only if the order actually changed. **Reject and the rows snap back** to the pre-drag order |
| `handleSelector` | default `.drag-handle` |
| `rowSelector` | default `.list-row` |

Behaviour: drag starts only from `.drag-handle`, deliberately not long-press,
which fights the scroll on touch. The DOM order changes live as the row passes
each neighbour's midpoint. **Rows must be siblings with no margin between them**
— `.list` satisfies this, and dragging across a group boundary is not supported.
**No edge autoscroll**: scroll first, then drag.

### `menu.js` — the app menu

The hamburger raised from `page_menu()`. **This is where app-level actions
live**: import today, export later. Not a tab, not inside Add.

```js
import { attachMenu, closeMenu } from './menu.js';

attachMenu('#app-menu', {
  items: [
    { label: 'Import contacts', href: 'import.php' },
    { label: 'Sign out', form: '#logout-form' },
  ],
});
```

Every screen wires this up in its own entry script, with the same items. Mark
the current screen with `current: true` if you are already on it.

| Option | Meaning |
|---|---|
| `items` **required** | Array of `{ label, … }`, each with exactly ONE action (below). Must not be empty |
| `label` | accessible name for the sheet. Default `'Menu'` |
| `cancelLabel` | default `'Cancel'` |

Each item takes exactly one action:

| Key | Renders | Use for |
|---|---|---|
| `href` | an `<a>` | a destination. A real link — long-press, open-in-new-tab and middle-click all work |
| `onSelect` | a `<button>` | something that happens in place. The sheet closes **first**, so a slow action can't be double-tapped |
| `form` | a `<button>` that `requestSubmit()`s that form (selector or element) | POST-only actions. **This is how Sign out works** — `logout.php` is POST-only on purpose, so a link would let any `<img src="logout.php">` sign you out |

Optional per item: `current: true` → `.is-current`; `danger: true` →
`.is-danger`.

Behaviour you can rely on:

- **The sheet is built on demand and removed on close.** Nothing sits in the
  page waiting — an empty fixed-position element is one z-index mistake away
  from swallowing every tap on the tab bar.
- One sheet at a time, app-wide. Tapping the hamburger while it is open closes
  it.
- Closes on the backdrop, on `Escape`, and on Cancel. **Cancel is always the
  last row and is always present**: on a phone the backdrop above a bottom sheet
  is the part of the screen your hand is not near.
- Focus moves to the first entry on open and back to the hamburger on close.
- `closeMenu()` is exported so a caller can dismiss it without holding a handle.
- **It adds no CSS.** Everything is `.sheet` / `.sheet-panel` / `.sheet-cancel`,
  which the stylesheet already carries.

For `Sign out` to work, every screen needs the POST form somewhere in its
markup:

```html
<form id="logout-form" method="post" action="logout.php" class="hidden"></form>
```

---

## 6. Endpoints — the naming convention

Foundation builds none of these. Name them `public/api/<noun>-<verb>.php`, so
ownership is readable from the filename and nobody collides:

`person-add.php` · `person-update.php` · `person-delete.php` ·
`tag-add.php` · `tag-rename.php` · `tag-delete.php` · `tag-assign.php` ·
`gift-add.php` · `gift-rename.php` · `gift-delete.php` · `gift-restore.php` ·
`contact-log.php` · `contact-delete.php` ·
`reminder-save.php` · `reminder-delete.php` ·
`import-upload.php` · `import-promote.php` · `import-skip.php` ·
`import-restore.php` · `import-finish.php`

All of them: `require_login_api()`, then `require_same_origin()`, then
`require_method('POST')`. Answer failures with `json_error('some_code', 4xx)` —
the code is what `api.js` puts on `ApiError.code` and what the caller branches
on, so keep it stable and lowercase_with_underscores.

Every one of these is private. **There is no public surface in this app**, with
one Phase 3 exception: `public/cron.php`, which is gated by a long random token
compared with `hash_equals()` and 404s (not 403s) on a bad one.

---

## 7. Local environment

```bash
cp config.example.php config.php     # fill in DB credentials
php tools/make-hash.php              # paste the hash into config.php
mysql -u root personalcrm < schema.sql
php -S 127.0.0.1:8791 -t public      # dev server
php tools/run-tests.php              # foundation tests — keep this green
```

`run-tests.php` stands up a temporary `config.php` from the example if you don't
have one, so it runs on a bare checkout with no setup at all.

**There is no MySQL in the build environment.** `tools/test-harness.php`
translates `schema.sql` into SQLite in memory so the schema and repo code can be
tested at all, and translates a short list of MySQL query constructs on the way
through the connection so repo code stays written for MySQL. Read its header
before adding a test: a query that passes there and fails on MySQL is worse than
no test. **Never edit `schema.sql` to make the translator's job easier — teach
the translator.** Load `schema.sql` into a real MariaDB once before deploying.

`lib/dates.php` needs no database, no config and no clock. When something about
a due date looks wrong, run that section first — it is the only place in the app
that can be wrong about one.
