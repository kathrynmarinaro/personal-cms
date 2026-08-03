# Personal CRM — Implementation Plan

A self-hosted tool for staying in touch with people. PHP + MySQL on Hostinger,
FTP deploy, mobile-first, single user.

This plan is written against the **Grocery** app as the reference sibling
(`kathrynmarinaro/grocery`), because it is the newest and most complete of the
suite and its own `CLAUDE.md` records that its `lib/` is a direct port of Book
Tracker, which ports the Workout Generator, which ports the Inspiration Gallery.
Porting from Grocery therefore inherits four apps' worth of accumulated fixes in
one step.

> **Note on sources.** Book Tracker, Workout-App and inspiration are private
> repos this session could not attach, so every "reuse" claim below is verified
> against Grocery only. Where Grocery's own comments say a helper came from a
> sibling, that is quoted, not independently confirmed. Nothing in the plan
> depends on a file I have not read.

---

## 1. The headline: how much of this is already written

Roughly **60% of this app already exists** in Grocery and gets ported with a
name change or less. The genuinely new surface is the vCard importer, the SMTP
mailer, the cron job, and the date arithmetic — and that last one is where the
risk lives, not in the CRUD.

### 1.1 Ported verbatim (no logic change)

| File | Change needed |
|---|---|
| `public/assets/styles.css` | **None.** This is the house design system — same tokens, same teal, same `--tap`, same components. Copy the file. |
| `public/assets/swipe.js` | None. Generic, callback-driven. |
| `public/assets/inline-edit.js` | None. Generic. |
| `public/assets/reorder.js` | None (only needed if we reorder gift ideas — see §4.6). |
| `public/logout.php` | None. |
| `lib/db.php` | One identifier: `$GLOBALS['grocery_pdo_override']` → `crm_pdo_override`. |
| `.htaccess`, `lib/.htaccess`, `tools/.htaccess` | None, plus a new `uploads/.htaccess` (§6.4). |
| `tools/make-hash.php` | None. |
| `tools/test-harness.php` | Extended, not rewritten (§8). |
| `login_attempts` table | Copy the DDL verbatim out of Grocery's `schema.sql`. |

### 1.2 Ported with a rename

| File | Change |
|---|---|
| `lib/auth.php` | `session_name('grocerylist')` → `'personalcrm'` — its own cookie so signing in/out here never disturbs the RSS Reader or Grocery on the same host. `CSRF_HEADER_VALUE = 'Grocery'` → `'CRM'`. **The gate is ON**, like Grocery, unlike Book Tracker. Everything else — the throttling curve, the SQL-side window arithmetic, the fail-open-when-unconfigured behaviour — ports untouched. |
| `public/assets/api.js` | `CSRF_VALUE = 'Grocery'` → `'CRM'`. Nothing else. |
| `public/login.php` | Title and `<h1>` → "Personal CRM". Structurally identical. |
| `lib/bootstrap.php` | App name in the `fatal_error()` fallback HTML. **Add** `date_default_timezone_set()` (§5) and `fmt_date()` (Grocery deliberately dropped it; this app is all dates). Drop Grocery's `SHARED_MODULES` list only if we end up with fewer shared modules — we won't. |
| `lib/layout.php` | New `nav_tabs()` (§3). `APP_NAME`. Everything else — `shared_module_map()`, `page_head/screen_head/page_foot`, the import-map cache-busting fix — ports as-is. **Keep pinch-zoom on**, following Grocery and Book Tracker. |
| `config.example.php` | Same shape; new blocks for `timezone`, `smtp`, `reminders`, `import`. |
| `tools/build-deploy.php` | Same script; update the include/exclude manifests for this app's tree. |
| `tools/run-tests.php` | Same harness pattern and the same self-standing-up-a-temp-config trick. New sections. |
| `DEPLOY.txt` | Same structure, this app's steps (plus the cron and SMTP setup, which Grocery has no equivalent of). |
| `CLAUDE.md`, `docs/CONTRACTS.md` | Same structure — file-ownership table, component vocabulary, "things that look like bugs but are decisions". |

### 1.3 Reused as a *pattern*, rewritten for this domain

- **The list-row primitive.** `.list` / `.list-row` / `.row-slide` / `.row-text` /
  `.row-body` + `.row-sub` / `.row-cat` / `.row-check` covers the people list,
  the gift-ideas list, the contact-log list and the import-review list without a
  single new CSS rule. `.row-sub` is exactly the "last contacted 34 days ago"
  secondary line.
- **The `.sheet` bottom picker.** Grocery uses it for the category picker; we use
  it for the relationship-tag picker and the reminder-cadence picker.
- **The `.composer` quick-add form.** Adding a gift idea, adding a custom tag.
- **`.cat-group` + `.cat-head.is-sticky`.** Grocery groups by category, Shopping
  by store; we group the people list by relationship tag and the dashboard by
  Today / This week.
- **`.accordion`** (`<details>`, no JS). The contact-log history on a profile,
  collapsed by default.
- **Swipe-to-delete + 5-second undo snackbar.** Applies to gift ideas and to
  import drafts. **Deliberately NOT to people** — see §10.
- **The endpoint convention.** `public/api/<noun>-<verb>.php`, each starting
  `require_login_api(); require_same_origin(); require_method('POST');`, failing
  with `json_error('lowercase_code', 4xx)`.

### 1.4 Genuinely new — nothing in the suite does this

1. **`lib/mailer.php`** — SMTP. No sibling app sends email at all.
2. **`tools/cron-reminders.php`** — the daily job. No sibling has a cron.
3. **`lib/vcard.php`** — the .vcf parser. The hardest single file in the app.
4. **`lib/dates.php`** — birthday and cadence arithmetic (§5).
5. **The import staging tables and review screen** (§6).

---

## 2. Repository layout

```
personal-cms/
  .htaccess                     ported
  config.example.php            ported shape, new blocks
  schema.sql                    new (login_attempts ported verbatim)
  DEPLOY.txt                    ported shape
  CLAUDE.md                     ported shape
  docs/
    PLAN.md                     this file
    CONTRACTS.md                ported shape — write before any feature code
  lib/
    .htaccess                   ported
    bootstrap.php               ported + timezone + fmt_date
    db.php                      ported
    auth.php                    ported
    layout.php                  ported + new tabs
    dates.php                   NEW  — all date arithmetic, pure functions
    people.php                  NEW  — person + tag repo
    reminders.php               NEW  — due queries, recurrence, reconcile
    contact.php                 NEW  — contact_log + gift_ideas repo
    vcard.php                   NEW  — streaming .vcf parser, pure
    import.php                  NEW  — staging, dedupe, promote
    mailer.php                  NEW  — SMTP
    vendor/PHPMailer/           NEW  — 3 vendored files (§7.1)
  public/
    .htaccess (via root shim)
    index.php                   Dashboard
    people.php                  People list
    person.php                  Person profile (?id=)
    add.php                     Add person + vCard upload + draft review
    login.php / logout.php      ported
    api/
      person-*.php  tag-*.php  gift-*.php  contact-*.php
      reminder-*.php  import-*.php
    assets/
      styles.css                ported VERBATIM
      api.js  swipe.js  inline-edit.js  reorder.js   ported
      dashboard.js  people.js  person.js  import.js  NEW
  uploads/
    .htaccess                   NEW — deny all (§6.4)
  tools/
    .htaccess                   ported
    make-hash.php               ported
    build-deploy.php            ported
    test-harness.php            ported + extended
    run-tests.php               ported + new sections
    cron-reminders.php          NEW
    send-test-email.php         NEW — verify SMTP before trusting the cron
```

---

## 3. Screens and navigation

Three tabs, matching the house pattern (`page_foot()` renders the bar):

| Tab | File | Contents |
|---|---|---|
| **Today** | `index.php` | Due today / overdue / this week. Reach-outs and birthdays interleaved, each row a link to the profile with a 1-tap "Logged today" button inline. Empty state: "Nobody to reach out to this week." |
| **People** | `people.php` | Searchable list, grouped by relationship tag using `.cat-group`. `.row-sub` shows "last contacted N days ago" or "never". |
| **Add** | `add.php` | Manual add form, plus the vCard upload and the draft-review queue. |

Leftmost tab is the one opened daily, following Grocery's reasoning (thumb
position) — that is **Today**.

The person profile (`person.php?id=`) is not a tab; it is pushed from People or
Today, and marks the tab it came from active.

---

## 4. Data model

MySQL/MariaDB, InnoDB, utf8mb4, `DATETIME DEFAULT CURRENT_TIMESTAMP`, following
Grocery's schema conventions — including `VARCHAR(190)` for anything indexed
(utf8mb4 index-length limit on older InnoDB row formats Hostinger may still run).

The brief's data model is the starting point. **Four deviations, each flagged
and justified.** These are the decisions worth arguing about before code exists.

### 4.1 `people` — deviation: birthday is split into parts

Brief says `birthday` (one column). Proposed:

```sql
CREATE TABLE people (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name              VARCHAR(190) NOT NULL,
  name_key          VARCHAR(190) NOT NULL,      -- normalized, for dupe detection
  birth_year        SMALLINT UNSIGNED NULL,     -- NULL = year unknown
  birth_month       TINYINT  UNSIGNED NULL,     -- 1-12, NULL = no birthday
  birth_day         TINYINT  UNSIGNED NULL,     -- 1-31
  address           VARCHAR(500) NULL,
  phone             VARCHAR(64)  NULL,
  email             VARCHAR(190) NULL,
  notes             TEXT         NULL,
  last_contact_date DATE         NULL,          -- NULL = never contacted
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_name_key (name_key),
  KEY idx_birthday (birth_month, birth_day)
);
```

**Why split.** A phone's vCard export very commonly carries `BDAY:--0415` — April
15th, year unknown, which is what you get when a contact was created from a
"birthday" field with no year. A single `DATE` column cannot store that. The
alternatives are a sentinel year (1900, 0000) or refusing to import those
contacts, and the house style explicitly rejects sentinels: Grocery's
`wanttobuy_items.purchased_at` comment says an invented date is indistinguishable
from a real one forever after. Splitting is honest, and the reminder only ever
needs month and day anyway.

Cost: the profile has to reassemble it for display. That is one function in
`lib/dates.php`, and it lets us render "April 15" for a yearless birthday and
"April 15 (turning 34)" when the year is known — which is nicer than what a
single DATE would have given us.

### 4.2 `relationship_tags` and `person_tag_map` — as briefed

```sql
relationship_tags (id, name VARCHAR(64) UNIQUE, sort_order INT, is_preset TINYINT)
person_tag_map    (person_id, tag_id, PRIMARY KEY (person_id, tag_id))
```

Seeded 1–5: Family, Friend, Close Friend, Colleague, Acquaintance. Both FKs
cascade on delete — unlike a category name, a tag link is a live pointer with no
historical meaning, so there is nothing to preserve.

`is_preset` exists so the UI can offer "delete" only on custom tags. The presets
are still deletable in the database; nothing may *treat them as special* beyond
that hint, following Grocery's "all four seeded stores are deletable" rule.

### 4.3 `gift_ideas`, `contact_log` — as briefed

```sql
gift_ideas  (id, person_id FK CASCADE, idea_text VARCHAR(500), created_at)
contact_log (id, person_id FK CASCADE, logged_at DATETIME, note VARCHAR(500) NULL)
```

`contact_log` gets `KEY idx_person_time (person_id, logged_at DESC)` — it is read
newest-first on every profile view.

### 4.4 `reminders` — deviation: add send-idempotency

Brief's columns, plus one table:

```sql
CREATE TABLE reminders (
  id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  person_id                INT UNSIGNED NOT NULL,
  type                     ENUM('reach_out','birthday') NOT NULL,
  recurrence_interval_days INT UNSIGNED NULL,   -- NULL = one-off (reach_out only)
  next_due_date            DATE NOT NULL,
  created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_person_type (person_id, type),   -- see below
  KEY idx_due (next_due_date),
  FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE
);

CREATE TABLE reminder_sends (
  reminder_id INT UNSIGNED NOT NULL,
  due_date    DATE NOT NULL,
  sent_at     DATETIME NULL,          -- NULL = attempted, not yet delivered
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error  VARCHAR(255) NULL,
  PRIMARY KEY (reminder_id, due_date),
  FOREIGN KEY (reminder_id) REFERENCES reminders(id) ON DELETE CASCADE
);
```

**Why `reminder_sends` exists.** The brief says "an individual email sent as each
reminder comes due". It does not say what happens when the cron runs twice, or
when the cron runs, sends, and then dies before it can advance `next_due_date`.
Both are ordinary: Hostinger's scheduler can double-fire, and SMTP can hang.
Without this table the failure mode is "the same birthday email eight times",
which is the kind of thing that makes you stop trusting the app.

Making `(reminder_id, due_date)` the **primary key** means "send at most once per
reminder per due date" is enforced by the database, not by application logic that
has to be right on every path. The cron does `INSERT ... ON DUPLICATE KEY UPDATE
attempts = attempts + 1` and only sends when it inserted a fresh row or found one
with `sent_at IS NULL`. A failed send leaves `sent_at` NULL and retries tomorrow;
a successful one never fires again.

It also gives us a send history for free, which is exactly the "history you
didn't start collecting is history you can't backfill" argument Grocery makes for
`grocery_purchase_log`.

**Why `UNIQUE (person_id, type)`.** One reach-out schedule and one birthday
schedule per person. The brief describes reach-out reminders as either a cadence
*or* a one-off date, never both, and never several. The unique key makes
"changing the reminder" an UPSERT rather than a delete-then-insert that can leave
orphans.

### 4.5 Birthday reminders — materialized, not computed

Two options, and this is a real fork:

- **(a) Compute birthdays on the fly** from `people.birth_month/day`, and let
  `reminders` hold only reach-outs. No sync problem, but the dashboard becomes a
  UNION of two differently-shaped queries, and `reminder_sends` has no stable
  `reminder_id` to key on — so idempotency gets harder exactly where it matters
  most (the birthday email is the one that must not double-send).
- **(b) Materialize a `type='birthday'` row per person, reconciled.** Recommended.

**Going with (b)**, which is also what the brief's data model describes. The sync
problem is real and is handled in exactly two places, both of which must be
written together or not at all:

1. `people_save()` reconciles that person's birthday reminder — creates it when a
   birthday is added, deletes it when the birthday is cleared, recomputes
   `next_due_date` when month/day change.
2. The cron reconciles **every** person's birthday reminder at the start of each
   run, before it looks for anything due. That is a full-table pass over a few
   hundred rows, which is nothing, and it means a birthday edited by some path
   that forgot to call the reconciler self-heals within 24 hours instead of
   silently never firing.

Belt and braces on purpose. A reminder that silently never fires is undetectable
by the person relying on it.

### 4.6 Gift-idea ordering — dropped

Grocery's `reorder.js` is ported (it costs nothing, it is one file) but **gift
ideas are not reorderable in v1**. They sort newest-first. The brief calls them a
"freeform list, no status tracking"; adding drag-ordering means a `sort_order`
column and an `-order.php` endpoint for a list that is typically three items long.
If it turns out to matter, the module is already sitting there.

---

## 5. Dates and time — read this before writing any query

**This is the highest-risk area of the app, and the suite has already been bitten
by it once.** `lib/auth.php` carries this comment, about a sibling app:

> Doing the arithmetic in PHP silently disabled this in a sibling app: PHP ran in
> UTC while MySQL ran in CDT, so `strtotime()` read MySQL's local-time string as
> UTC and produced an unlock time five hours in the past. `blocked_for` was always
> 0 and the lockout never fired [...] One clock, no conversions.

Grocery could contain that lesson inside one throttling query. This app cannot:
"due today", "7 days before a birthday", "last contact + 60 days" and "which day
did the cron run on" are the entire product. A one-day skew is not a subtle bug
here — it is birthday cards arriving late, every time, with nothing on screen to
explain it.

### The rule

**One clock, established once, passed explicitly.**

1. `config.php` gets `'timezone' => 'America/Chicago'`. Explicit, not inherited
   from the server, because Hostinger's PHP default and its MySQL default are set
   independently and neither is guaranteed.
2. `lib/bootstrap.php` calls `date_default_timezone_set(cfg('timezone'))`
   immediately after loading config, before anything else can read a date.
3. `lib/db.php` sets the connection's time zone in the same breath as connecting,
   so MySQL's `NOW()` and PHP's `time()` cannot disagree:
   ```php
   PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . (new DateTime('now',
       new DateTimeZone(cfg('timezone'))))->format('P') . "'"
   ```
   (A numeric offset, not a name — Hostinger's MySQL often has the named
   timezone tables unloaded, and `SET time_zone = 'America/Chicago'` then fails
   with a bare error at connect time. The offset always works. It shifts at DST,
   which is fine because the connection is per-request.)
4. **`crm_today(): string` in `lib/dates.php` is the only place in the app that
   asks what day it is.** Everything else takes a `$today` string parameter.

Point 4 is what makes this both testable and safe. It resolves the apparent
conflict with the auth.php lesson: that lesson is about *comparing two clocks*.
Here there is one reading of "today", taken once per request or cron run, passed
down, and compared against `DATE` columns that carry no time component at all.
Every due-date query becomes `WHERE next_due_date <= ?` — portable, index-friendly,
and exercisable by the SQLite test harness, which cannot evaluate `DATE_ADD` or
`INTERVAL` (§8).

### Date arithmetic lives in PHP, in pure functions

`lib/dates.php` — no database access, no `now()`, every function takes its inputs:

| Function | Purpose |
|---|---|
| `crm_today(): string` | `Y-m-d` in the configured zone. **The only impure function in the file.** |
| `next_birthday(int $m, int $d, string $today): string` | Next occurrence of this month/day on or after today. |
| `birthday_reminder_date(int $m, int $d, string $today, int $lead): string` | The above, minus `$lead` days. |
| `next_cadence_date(?string $lastContact, int $days, string $today): string` | `last_contact + days`, or `today + days` when never contacted. |
| `days_since(?string $date, string $today): ?int` | For the "34 days ago" subline. |
| `fmt_relative_due(string $due, string $today): string` | "Today", "Tomorrow", "3 days ago", "Friday". |

### Two edge cases that need a stated rule

**February 29.** A birthday on Feb 29 has no occurrence in three years out of
four. Rule: **treat it as Feb 28 in non-leap years.** The reminder therefore fires
on Feb 21. The alternative (March 1) means the card arrives in the wrong month.
This gets a test.

**The 7-day lead crossing a year boundary.** A January 3rd birthday reminds on
December 27th — of the *previous* year. `next_birthday()` must find the next
occurrence *of the birthday* and then subtract, never subtract first and then look
for a birthday. Getting this backwards produces a reminder that is 358 days late
and looks approximately right in a spot check. This gets a test.

The 7-day lead is `cfg('reminders.birthday_lead_days', 7)` — one value, read in
one place, so the cron and the dashboard cannot disagree about it. Same reasoning
as Grocery's `grace_seconds`.

---

## 6. vCard import

The most involved new component, and the one most likely to be underestimated.

### 6.1 The flow

1. `add.php` → upload a `.vcf`.
2. `import-upload.php` streams and parses it into `import_drafts` under a new
   `import_batches` row. **No `people` row is created.**
3. `add.php` shows the draft queue: each draft is a `.list-row` with the parsed
   name, a `.row-sub` of "phone · email", a duplicate flag if any, and tag pills.
4. Per draft: **Add** (promote to `people`), **Skip** (delete the draft), or tap
   to edit fields first. Swipe-to-delete + undo = Skip, reusing `swipe.js`.
5. Batch finished → the batch row is marked done and its drafts pruned.

Draft rows are never silently promoted. That is the whole point of the staged
design in the brief — "prevents bulk-importing junk contacts" — and a "Add all"
button would quietly defeat it. There is deliberately no such button.

### 6.2 Staging tables (not in the brief — required by it)

```sql
import_batches (id, filename VARCHAR(255), uploaded_at, total_parsed INT,
                promoted INT DEFAULT 0, status ENUM('open','done'))
import_drafts  (id, batch_id FK CASCADE, name VARCHAR(190), name_key VARCHAR(190),
                birth_year/month/day, address, phone, email,
                dup_person_id INT UNSIGNED NULL,   -- possible duplicate, not merged
                status ENUM('pending','added','skipped') DEFAULT 'pending')
```

`dup_person_id` is a **flag, not a link with meaning** — the brief says flag as a
possible duplicate rather than auto-merging, so nothing in the app may act on it
except to render a warning pill.

### 6.3 Parsing — the details that will bite

`lib/vcard.php` is a pure function over a line iterator. It never touches the
database, which is what makes it testable against fixture files with no MySQL.

Seven things the parser must handle, all of which appear in real iOS/Android
exports:

1. **Line folding (RFC 6350).** A logical line can continue on the next physical
   line, marked by a leading space or tab. Unfold *before* parsing anything.
   Every naive parser gets truncated addresses because of this.
2. **`PHOTO` must be skipped, including its continuation.** A contacts export
   with photos is mostly base64 image data — a 400-contact export can be 30 MB of
   which 29 MB is photos. Photos are out of scope for v1, so the parser must
   recognise a `PHOTO` property and discard its folded body **without
   accumulating it in memory**. This is why the parser reads line-by-line off a
   file handle rather than `file_get_contents()`.
3. **Quoted-printable.** vCard 2.1 exports (still emitted by some Android
   contact apps) encode non-ASCII as `;ENCODING=QUOTED-PRINTABLE` with `=` soft
   line breaks. Decode when the parameter is present.
4. **`N` vs `FN`.** `N` is structured (`Last;First;Middle;Prefix;Suffix`), `FN` is
   the display name. Prefer `FN`; fall back to assembling `N`. A contact with
   neither is skipped, counted, and reported — not imported as an empty row.
5. **Repeated properties.** Multiple `TEL` and `EMAIL` lines are normal. Take the
   first, preferring one with a `TYPE=CELL`/`TYPE=HOME` parameter over an
   untyped one. The brief's schema has one phone and one email column; the
   discards are dropped, and the draft review is where you notice.
6. **`ADR` is semicolon-structured** (`pobox;ext;street;locality;region;postal;country`)
   and needs joining into one readable line.
7. **Escaping.** `\,` `\;` `\n` `\\` are escapes inside values and must be
   unescaped last, after the structural splits — unescaping first turns a literal
   `\;` in a street name into a field separator.

**`BDAY` parsing** feeds §4.1 directly: accept `19910415`, `1991-04-15`,
`--0415` and `--04-15`, yielding year-or-null plus month and day.

### 6.4 Upload safety

- Size cap from config (`import.max_upload_mb`, default 10), enforced against
  `$_FILES['size']` **and** checked against `upload_max_filesize` at boot so a
  silently-truncated PHP upload is reported rather than parsed as a short file.
- Extension and a sniff of the first non-blank line for `BEGIN:VCARD`. Reject
  anything else with a plain message — this is the one place a user hands the app
  a file, and "nothing happened" is the worst possible response.
- Uploads land in `uploads/`, which is **outside `public/`** and additionally
  carries its own deny-all `.htaccess`, following the same belt-and-braces
  reasoning as Grocery's `config.php` protection.
- **The file is deleted as soon as it is parsed.** The drafts are the artifact;
  keeping the raw file means keeping every phone number you decided not to
  import.
- Cap on drafts per batch (`import.max_contacts`, default 2000) so a pathological
  file cannot fill the database.

---

## 7. Email and the cron job

### 7.1 SMTP — recommendation: vendor PHPMailer

The brief specifies SMTP over `mail()` for deliverability. Two ways to get there:

- **Hand-rolled SMTP client** (~200 lines over `stream_socket_client`): no
  dependency, but you own STARTTLS negotiation, AUTH mechanism selection, header
  encoding, and dot-stuffing. Getting any of those subtly wrong produces mail that
  sends fine to yourself and gets filed as spam by everyone else — which is the
  exact problem we adopted SMTP to avoid.
- **Vendor PHPMailer** — recommended. Copy exactly three files
  (`PHPMailer.php`, `SMTP.php`, `Exception.php`) into `lib/vendor/PHPMailer/` and
  `require_once` them directly. **No Composer, no autoloader, no build step** —
  they are three plain PHP files that FTP up with everything else, fully
  consistent with the suite's no-build-step rule, which is about not needing a
  toolchain rather than about not using anyone else's code.

`lib/mailer.php` wraps it so nothing else in the app knows which choice was made —
one function, `send_reminder_email(array $person, string $type, string $dueDate)`,
returning `bool` and logging the failure reason. Swapping implementations later
touches one file.

Config block:

```php
'smtp' => array(
    'host'       => 'smtp.gmail.com',
    'port'       => 587,
    'secure'     => 'tls',        // 'tls' (587, STARTTLS) or 'ssl' (465)
    'user'       => 'you@gmail.com',
    'pass'       => 'CHANGE_ME',  // Gmail APP PASSWORD, not the account password
    'from_email' => 'you@gmail.com',
    'from_name'  => 'Personal CRM',
    'to'         => 'you@gmail.com',   // single user: every reminder goes here
),
```

**`from_email` must match `user`.** Gmail rewrites or rejects a From it has not
authorized, and the resulting bounce is silent from the app's point of view. This
is the single most common way this setup fails, so it goes in `DEPLOY.txt` in
capitals and is asserted by `tools/send-test-email.php`.

`tools/send-test-email.php` exists so SMTP is verified *before* anyone trusts a
cron they cannot watch run.

### 7.2 The cron job

`tools/cron-reminders.php`, once daily. Hostinger's scheduler, early morning in
the configured timezone.

```
$today = crm_today();

1. Reconcile every person's birthday reminder (§4.5).
2. Select reminders WHERE next_due_date <= $today.
3. For each:
     a. INSERT INTO reminder_sends (reminder_id, due_date) VALUES (?, $due)
        ON DUPLICATE KEY UPDATE attempts = attempts + 1
     b. If that row already has sent_at IS NOT NULL -> skip, already handled.
     c. Send. On success: sent_at = NOW().
        On failure: leave sent_at NULL, record last_error. Retry tomorrow.
     d. Birthday reminders: advance next_due_date to next year's lead date.
        Reach-out reminders: leave next_due_date ALONE.
4. Print a one-line summary; exit non-zero if any send failed.
```

**Step 3d is the important asymmetry.** A birthday reminder rolls forward on its
own — it has fired, the birthday is coming, nothing more is expected of you this
year. A **reach-out reminder stays overdue until you actually log a reach-out**,
because that is what "overdue" means: the dashboard must keep showing it, and it
must not email you again tomorrow (which `reminder_sends` already prevents,
keyed on the unchanged due date). Advancing it on send would mean the app quietly
forgives you for not calling your sister.

Logging a contact is what moves it: `contact-log.php` sets `last_contact_date`,
inserts the log row, and recomputes `next_due_date = last_contact_date +
recurrence_interval_days` for a cadence reminder, or deletes the reminder outright
for a one-off that has now been satisfied.

**Cron access fallback.** Hostinger plans differ — some give real SSH and a
command cron (`php /home/uXXXX/domains/.../tools/cron-reminders.php`), others only
a URL-fetch cron. `tools/.htaccess` denies web access to `tools/`, so the URL case
needs `public/cron.php`: a thin wrapper gated by a long random
`cfg('cron.token')` compared with `hash_equals()`, which 404s (not 403s — a 403
confirms the endpoint exists) on a bad token and otherwise calls the same
function. Both paths run identical code. Build both; `DEPLOY.txt` explains which
to set up.

### 7.3 Email content

Plain text plus a minimal HTML part. Subject lines are the whole UX here, because
this arrives on a phone lock screen:

- `Birthday: Alex Chen — April 15 (next Tuesday)`
- `Time to reach out to Alex Chen — last contacted 62 days ago`

Body: the person's notes, gift ideas (for birthdays — this is when you want
them), and a deep link to the profile. Nothing else.

---

## 8. Testing

Port Grocery's harness wholesale: `tools/test-harness.php` translates
`schema.sql` into in-memory SQLite, installs itself as `db()`'s connection via the
CLI-only override, and `tools/run-tests.php` runs sections and exits non-zero on
failure. There is no MySQL in the build environment; this is the only way any of
it gets exercised.

**The translator needs work for this app.** Grocery's version handles
`ENGINE=`/`CHARSET=`, `AUTO_INCREMENT`, `UNSIGNED`, and `ENUM`. This schema adds
`ON DUPLICATE KEY UPDATE` (SQLite: `ON CONFLICT ... DO UPDATE`) and composite
primary keys on non-integer columns. Both are teachable to the translator. **The
rule from Grocery's harness header stands: `schema.sql` is written for MySQL and
must never be edited to make the translator's job easier.**

The design choice in §5 pays off here. Because every due-date query is
`WHERE next_due_date <= ?` against a `DATE` column with the date computed in PHP,
there is no `DATE_ADD`/`INTERVAL`/`DAYOFYEAR` for the translator to fake, and the
date logic itself is pure functions that need no database at all.

Test sections, in build order:

1. **Schema** — tables, seeds (5 tags), constraints, cascades.
2. **`lib/dates.php`** — no database. Feb 29. The January-birthday year-boundary
   case. Cadence from a null `last_contact_date`. DST boundaries.
3. **`lib/vcard.php`** — fixture `.vcf` files committed under `tests/fixtures/`:
   an iOS 3.0 export, an Android 2.1 quoted-printable export, one with folded
   lines, one with a large `PHOTO` (asserting it is skipped, not buffered), one
   with `BDAY:--0415`, one malformed. Tracked, not gitignored, following the
   reasoning in Grocery's `.gitignore` about un-refetchable data.
4. **Reminder logic** — logging a contact resets a cadence; a birthday advances a
   year on send; a reach-out does *not* advance; `reminder_sends` refuses a
   double-send.
5. **Repos** — people/tags/gifts/contact-log CRUD through the real `q()`.
6. **Auth** — port Grocery's throttle-curve tests, which already exist because it
   factored `auth_attempt_delay()` out as a pure function. (Grocery's `CLAUDE.md`
   notes the siblings have that arithmetic inline and therefore untested, and
   suggests backporting. We inherit the good version.)

The mailer is not unit-tested — it talks to Gmail. `tools/send-test-email.php`
covers it manually, once, at deploy.

---

## 9. Build order

Mirroring the Foundation-then-parallel-modules structure that Grocery used, since
that is what `docs/CONTRACTS.md` exists to support.

**Phase 0 — Foundation.** Port `lib/bootstrap.php`, `db.php`, `auth.php`,
`layout.php`, all four `.htaccess`, `login.php`/`logout.php`, `styles.css`, the
four JS modules, `make-hash.php`, the test harness. Write `schema.sql`,
`config.example.php`, `lib/dates.php`, `CLAUDE.md` and `docs/CONTRACTS.md`.
Nothing else starts until CONTRACTS is written — it is the file-ownership table
that keeps parallel work from colliding.

**Phase 1 — People (the spine).** `people.php`, `person.php`, `lib/people.php`,
tags, `person-*`/`tag-*` endpoints. Manual add. At the end of this phase the app
is already useful as a contact book.

**Phase 2 — three tracks, parallelizable:**
- **A · Interaction** — contact log, gift ideas, `lib/contact.php`, the profile's
  1-tap logging button.
- **B · Reminders** — `lib/reminders.php`, the dashboard, cadence and one-off
  editing, birthday reconciliation.
- **C · Import** — `lib/vcard.php`, `lib/import.php`, `add.php`'s upload and
  draft-review queue.

C is the biggest and least coupled; it is the one to start earliest if anything
runs in parallel.

**Phase 3 — Delivery.** `lib/mailer.php`, `tools/cron-reminders.php`,
`public/cron.php`, `tools/send-test-email.php`, `DEPLOY.txt`,
`tools/build-deploy.php`.

Phase 3 depends on B. Everything in Phase 2 depends only on Phase 1.

---

## 10. Decisions worth recording now

Following Grocery's "things that look like bugs but are decisions" convention —
these go into `CLAUDE.md` when it is written.

- **The gate is ON, with its own session cookie.** `session_name('personalcrm')`,
  independent of the RSS Reader as the brief requires. It still **fails open when
  no `password_hash` is configured**, matching every sibling: locking someone out
  of their own app with no way back in is worse than an unlisted URL. An
  unconfigured deploy is a public deploy, and `DEPLOY.txt` says so in capitals.
  This matters more here than in Grocery — a leaked grocery list is embarrassing;
  this database is everyone you know, their addresses and their birthdays.
- **People are not swipe-deletable.** Grocery's swipe-past-threshold-and-release
  deletes immediately with a 5-second undo, and that is right for a grocery item
  you can retype in two seconds. A person carries notes, gift ideas and years of
  contact history, and the undo window is five seconds long. Deleting a person is
  a deliberate action on the profile screen with a confirm. Swipe-to-delete stays
  on gift ideas and import drafts, where it belongs.
- **`last_contact_date` is a DATE, and logging is idempotent-ish by design.**
  Tapping "Logged today" twice writes two `contact_log` rows and leaves
  `last_contact_date` unchanged. That is correct: two conversations in one day
  are two conversations, and the cadence clock runs off the date.
- **A reach-out reminder stays overdue until you log a contact** (§7.2). The
  dashboard is allowed to accumulate. This is the app working, not a bug.
- **`dup_person_id` never merges anything.** Flag only, per the brief.
- **No "Add all" on the import queue** (§6.1).
- **Photos are dropped at parse time, not stored and ignored** (§6.3).

---

## 11. Open questions

Four things I would rather you decide than have me assume. None of them block
Phase 0 or Phase 1, so work can start regardless.

1. **Timezone.** I have assumed `America/Chicago` throughout. If that is wrong
   it is a one-line config change, but every date in the app depends on it.
2. **Where reminder emails go.** Assumed: one fixed address in config, same as
   the SMTP account. Confirm it is the address you actually read on your phone.
3. **The third tab.** I have proposed **Today · People · Add**, with the vCard
   import living inside Add. The alternative is Import as its own tab, which is
   more discoverable but permanently spends a third of the tab bar on a one-time
   task. Worth a moment's thought since changing it later means moving screens.
4. **PHPMailer vs hand-rolled SMTP** (§7.1). My recommendation is vendoring the
   three PHPMailer files; it does not break the no-build-step rule. Say the word
   if you would rather the app had zero third-party code, and I will write the
   SMTP client instead.

---

## 12. Out of scope for v1

Per the brief, and to be stated in `CLAUDE.md` so nobody leaves hooks for them:

Two-way sync back to phone contacts · multi-user or shared access · automated
card sending · anniversaries and other recurring date types · SMS · contact
photos · a PWA or service worker · offline mode · attachments · merge/dedupe
tooling beyond the duplicate flag · gift-idea ordering or status (§4.6).
