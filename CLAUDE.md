# Personal CRM — working notes

**Read `docs/CONTRACTS.md` before writing any code.** The Phase 1 and Phase 2
tracks are built in parallel against the interfaces defined there; it also holds
the file-ownership table that keeps them from colliding, and the full catalogue
of CSS classes and JS module APIs the Foundation layer already built for you.

A self-hosted tool for staying in touch with people: birthdays, reach-out
reminders, gift ideas, a contact log, and a vCard importer to fill it. One
person, one phone, one password. `docs/PLAN.md` is the full specification and
the record of why the data model looks the way it does.

## Stack, non-negotiable

- PHP 8.4, `declare(strict_types=1)` at the top of every file.
- MySQL/MariaDB via PDO. **Prepared statements only** — never interpolate into
  SQL. Use `q($sql, $params)` from `lib/db.php`.
- **No build step, ever.** Deployment is an FTP upload of plain files to
  Hostinger. No npm, no bundler, no transpiler, no CSS preprocessor. Browser JS
  is hand-written ES modules. (PHPMailer is vendored as three plain files in
  Phase 3 — that is not a toolchain, it is somebody else's code copied in.)
- Mobile-first. The phone is the only device that matters. 48px minimum tap
  target (`--tap`), 16px minimum font on inputs — below 16px iOS zooms on focus
  and never zooms back.
- `array()` long syntax, matching the sibling apps.

## House style

- Comments explain **why**, not what. Look at `lib/auth.php`, `lib/dates.php`
  and `schema.sql` for the register: they document the decisions a future reader
  can't reconstruct, not the syntax they can already see.
- Escape on output with `h()`. Templates use `<?= h($x) ?>`, never bare echo of
  DB data.
- Entry points start with `require_once __DIR__ . '/../lib/bootstrap.php';`
- Fail soft: a malformed row, a failed write, or a person with an unparseable
  birthday degrades that one row. It never 500s a screen.

**`public/assets/styles.css` is Foundation-owned and complete.** It is a
**verbatim copy of Grocery's**, token for token. Write markup against the
existing classes (catalogued in `docs/CONTRACTS.md` §4). Don't add `<style>`
blocks, don't add inline `style=` for anything structural, don't edit the
stylesheet. Need a new component? Report it instead.

The same goes for `assets/api.js`, `assets/swipe.js`, `assets/inline-edit.js`,
`assets/reorder.js` and `assets/menu.js` — import them, don't fork them. They
are generic on purpose, they take callbacks, and every screen uses the same
copies.

## Dates: read this before writing a query

**This is the highest-risk area of the app.** `lib/dates.php` has the long
version; the short version is:

- **`crm_today()` is the only thing in the app that asks what day it is.**
  Everything else takes a `$today` string parameter. Do not call `date()`,
  `time()`, `strtotime('now')` or `NOW()` to decide a due date.
- Every due-date query is `WHERE next_due_date <= ?` against a `DATE` column.
  There is no `DATE_ADD` and no `INTERVAL` anywhere, on purpose — the
  arithmetic lives in pure PHP functions that are tested without a database.
- The timezone is one config value, applied in exactly two places:
  `date_default_timezone_set()` in `lib/bootstrap.php` and
  `MYSQL_ATTR_INIT_COMMAND` in `lib/db.php`. One clock, no conversions.

## Shared with the other apps

`lib/bootstrap.php`, `lib/db.php`, `lib/auth.php` and `lib/layout.php` are ports
from **Grocery**, which ports them from Book Tracker, which ports the Workout
Generator, which ports the Inspiration Gallery. The design tokens in
`public/assets/styles.css`, and all four of the JS modules ported with it, are
the house system shared with those apps. If you fix a bug in any of these, it
should land in the siblings too — say so in your report.

Grocery was chosen as the source deliberately: it is the newest of the suite and
inherits four apps' worth of accumulated fixes in one step.

Local divergences from the siblings, all deliberate:

- `db()` has a **CLI-only PDO override** so `tools/test-harness.php` can point
  it at an in-memory SQLite database. Without it, nothing that calls `q()` is
  testable, because there is no MySQL in the build environment.
- `db()` also **pins the connection's time zone** at connect time, which no
  sibling does. See the comment there; the offset is numeric on purpose.
- `bootstrap.php` calls **`date_default_timezone_set()`** immediately after the
  config load, and carries **`fmt_date()`**, which Grocery deliberately dropped.
- `auth_attempt_delay()` is factored out of `auth_attempt_login()` as a pure
  function, so the throttle curve is tested. Grocery has this; the older
  siblings have the arithmetic inline and untested. Worth backporting to them.
- `layout.php` has **`page_menu()`** and `menu.js`, which Grocery has no need
  for — it has nothing app-level to put in a menu.

## Things that look like bugs but are decisions

- **The gate is ON, with its own session cookie.** `session_name('personalcrm')`,
  independent of the RSS Reader and Grocery on the same host, as the brief
  requires. It still **fails open when no `password_hash` is configured**,
  matching every sibling: locking someone out of their own app with no way back
  in is worse than an unlisted URL. **An unconfigured deploy is a public
  deploy.** That matters more here than anywhere else in the suite — a leaked
  grocery list is embarrassing; this database is everyone you know, their home
  addresses and their birthdays.
- **People are not swipe-deletable.** `swipe.js` deletes on
  swipe-past-threshold-and-release with a five-second undo, and that is right
  for a grocery item you can retype in two seconds. A person carries notes, gift
  ideas and years of contact history, and the undo window is five seconds long.
  Deleting a person is a deliberate action on the profile screen with a confirm.
  Swipe-to-delete stays on **gift ideas and import drafts**, where it belongs.
- **A reach-out reminder stays overdue until you log a contact.** The cron does
  not advance `next_due_date` when it sends one — only birthdays roll forward.
  The dashboard is allowed to accumulate. That is what "overdue" means, and
  advancing on send would mean the app quietly forgives you for not calling your
  sister. `reminder_sends` is what stops it emailing you again tomorrow.
- **`last_contact_date` is a DATE, and logging is idempotent-ish by design.**
  Tapping "Logged today" twice writes two `contact_log` rows and leaves
  `last_contact_date` unchanged. That is correct: two conversations in one day
  are two conversations, and the cadence clock runs off the date.
- **A birthday is three columns, not one DATE.** `BDAY:--0415` — April 15th,
  year unknown — is the ordinary case out of a phone's vCard export, and a
  single `DATE` cannot hold it. `birth_month IS NULL` means no birthday;
  `birth_year IS NULL` beside a month and day means the year is unknown. Those
  are different states and the UI must not collapse them.
- **February 29 becomes February 28 in a non-leap year**, so the reminder fires
  on Feb 21. March 1 was the alternative and it puts the card in the wrong
  month. `lib/dates.php` clamps generally, to the last day of whatever month, so
  a junk `BDAY:--0631` out of an import cannot crash a dashboard later.
- **`birthday_reminder_date()` finds the birthday first and subtracts second.**
  A January 3rd birthday reminds on December 27th of the *previous* year. Done
  the other way round it is 358 days late and looks approximately right in a
  spot check. It has a test; leave it there.
- **A reminder date in the past is not a bug.** `next_due_date` can be earlier
  than today, and the dashboard and cron both read `<= today`, so an overdue
  reminder fires immediately rather than being skipped for a year.
- **Birthday reminders are materialized rows, reconciled twice.** Once by
  `people_save()` and again by a full pass at the start of every cron run. Belt
  and braces on purpose: a reminder that silently never fires is undetectable by
  the person relying on it.
- **`reminder_sends` has a composite primary key and no surrogate id.** "At most
  one send per reminder per due date" is the entire content of the table, and
  making it the primary key means the database enforces it on every path —
  including the one somebody adds in six months without reading the comment.
- **`dup_person_id` never merges anything.** It is a flag, per the brief.
  Deliberately not a foreign key, so it cannot cascade, cannot be joined through
  by accident, and cannot block deleting the person it points at. Promoting a
  flagged draft creates a second person, and that is correct — two people really
  can share a name.
- **`people.name_key` is not unique.** Same reason.
- **No "Add all" on the import queue.** The staged design exists to prevent
  bulk-importing junk contacts; one button would quietly defeat it.
- **Photos are dropped at parse time, not stored and ignored.** A 400-contact
  export can be 30 MB of which 29 MB is base64 images, which is why the parser
  reads off a file handle rather than `file_get_contents()`.
- **The uploaded .vcf is deleted the moment it is parsed.** The drafts are the
  artifact. Keeping the file means keeping every phone number you decided not to
  import.
- **App-level actions live in the hamburger menu, not in a tab.** Import today,
  export later — following the Inspiration Gallery and Book Tracker, where
  export lives in exactly this menu. Three tabs are the app's three daily jobs;
  a job you do twice ever does not get a quarter of the tab bar, and burying
  import inside Add would make Add two unrelated things wearing one name.
  **Anything app-level added later goes in this menu.** Do not invent a second
  pattern for it.
- **Gift ideas have no `sort_order` and are not reorderable.** They sort
  newest-first. `reorder.js` is ported anyway because it costs one file, so if
  it turns out to matter the module is already sitting there.
- **All five seeded relationship tags are deletable.** `is_preset` exists only
  so the UI can decide whether to offer a delete control. Nothing may treat a
  preset as special beyond that hint, and the database certainly doesn't.
- **`tools/test-harness.php` translates `schema.sql` into SQLite.** MySQL is the
  production target and the only one. That file exists solely because the build
  environment has no MySQL and no Docker; a green test run says the schema is
  coherent, not that MySQL accepts it. **Never edit `schema.sql` to make the
  translator's job easier — teach the translator.** It now also translates
  queries on their way through the connection (`ON DUPLICATE KEY UPDATE` →
  `ON CONFLICT DO UPDATE`), so repo code can be written in MySQL and still be
  tested.

## Out of scope for v1

Two-way sync back to phone contacts · multi-user or shared access · automated
card sending · anniversaries and other recurring date types · SMS · contact
photos · a PWA or service worker · offline mode · attachments · merge/dedupe
tooling beyond the duplicate flag · gift-idea ordering or status.

Don't build these, and don't leave hooks for them.
