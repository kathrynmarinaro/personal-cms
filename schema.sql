-- Personal CRM — schema
-- Load with:  mysql -u root personalcrm < schema.sql
--
-- FROZEN CONTRACT. The Phase 1 and Phase 2 tracks (People, Interaction,
-- Reminders, Import) are built in parallel against this file. Nothing here
-- changes without updating docs/CONTRACTS.md first — someone else is writing
-- queries against it right now.
--
-- MySQL/MariaDB is the production target: InnoDB, utf8mb4, and every DATETIME
-- default is CURRENT_TIMESTAMP so the server clock is the only clock. Note
-- that tools/test-harness.php translates this file into SQLite for the test
-- run, because the build environment has no MySQL. That translation is a
-- TEST convenience and proves nothing about MySQL — it is not a second
-- supported backend, and this file must stay written for MySQL.
--
-- Four shapes recur and are deliberate:
--
--   * Anything indexed is VARCHAR(190), not 255. A utf8mb4 index entry is
--     capped at 191 characters on the older InnoDB row formats Hostinger may
--     still be using, and 190 leaves that alone rather than requiring a prefix
--     index.
--   * DATE, not DATETIME, for everything the reminder logic reads. A birthday
--     and a due date have no time of day, and giving them one invents a
--     midnight that then has to be reasoned about at a DST boundary. Every
--     due-date query in the app is `WHERE next_due_date <= ?` against a date
--     string computed once per request by crm_today() — see lib/dates.php and
--     PLAN.md §5. There is no DATE_ADD anywhere, on purpose.
--   * NO SENTINELS. A missing birth year is NULL, not 1900. A person never
--     contacted has last_contact_date NULL, not the epoch. The suite's rule,
--     inherited from Grocery's wanttobuy_items.purchased_at: an invented date
--     is indistinguishable from a real one forever after.
--   * Foreign keys CASCADE from people. Deleting a person deletes their gift
--     ideas, their contact log, their tag links and their reminders, because
--     none of those mean anything without them. Deleting a person is a
--     deliberate, confirmed action on the profile screen — never a swipe. See
--     CLAUDE.md.


-- ======================================================= PEOPLE ==============

-- ------------------------------------------------------------------- people

-- One row per person. The spine of the app; everything else hangs off it.
CREATE TABLE IF NOT EXISTS people (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- As typed or as the vCard's FN said. Never normalized, never title-cased —
  -- same rule as grocery_items.name in the sibling app. "Mum", "Dr. Okafor"
  -- and "Alex (from climbing)" are all names somebody chose on purpose.
  name              VARCHAR(190) NOT NULL,

  -- The normalized form of name — lowercased, whitespace collapsed, accents
  -- and punctuation folded — used ONLY to spot a possible duplicate during a
  -- vCard import. Stored rather than derived because the dupe check runs once
  -- per draft row against the whole table, and `WHERE LOWER(name) = ?` is a
  -- function on a column: non-sargable, unindexable, a full scan every time.
  --
  -- It is NOT unique. Two people really can be called James Smith, and a
  -- unique constraint here would turn that into a database error on an import
  -- screen with nowhere to show one. The duplicate is FLAGGED, never enforced
  -- and never merged.
  name_key          VARCHAR(190) NOT NULL,

  -- THE BIRTHDAY IS SPLIT INTO THREE COLUMNS, and this is the schema's most
  -- arguable decision, so: a phone's vCard export very commonly carries
  -- `BDAY:--0415` — April 15th, year unknown, which is what you get when the
  -- contact was created from a "birthday" field that never asked for one. A
  -- single DATE column cannot store that. The alternatives are a sentinel year
  -- (1900, 0000) or refusing to import those contacts, and the house style
  -- rejects sentinels outright.
  --
  -- Splitting is also honest about what the reminder needs, which is month and
  -- day and nothing else. The year is a display nicety: it is what lets the
  -- profile say "April 15 (turning 34)" instead of just "April 15".
  --
  -- The cost is that the profile has to reassemble it. That is one function in
  -- lib/dates.php, called from two screens.
  --
  -- birth_month NULL means NO BIRTHDAY RECORDED. birth_year NULL alongside a
  -- month and day means the birthday is known and the year is not. Those are
  -- different states and the UI must not collapse them.
  birth_year        SMALLINT UNSIGNED NULL,
  birth_month       TINYINT  UNSIGNED NULL,
  birth_day         TINYINT  UNSIGNED NULL,

  -- 500 because ADR in a vCard is seven semicolon-separated parts joined into
  -- one readable line, and an international address with a company name in it
  -- passes 255 more often than you would think. One line, not seven columns:
  -- nothing in this app queries an address, it only prints one and hands it to
  -- a maps link.
  address           VARCHAR(500) NULL,

  -- Stored as typed or as exported, formatting and all. Not normalized to
  -- E.164: the app never dials it, it renders it into a tel: link, and a phone
  -- handles "+44 20 7946 0958" perfectly well. Normalizing would lose the
  -- author's own spacing for no gain.
  phone             VARCHAR(64)  NULL,

  email             VARCHAR(190) NULL,

  -- TEXT, not VARCHAR. The one genuinely open-ended field: "allergic to
  -- shellfish", "ask about the move", years of accumulated context. No ceiling
  -- that could ever be the right one.
  notes             TEXT         NULL,

  -- NULL = never contacted, and that is a real and common state for a freshly
  -- imported contact. Do not COALESCE it to created_at; "we have never spoken"
  -- and "we spoke the day I added you" are different facts and the People list
  -- says so out loud ("never" vs "34 days ago").
  --
  -- A DATE, not a DATETIME. Logging a contact twice in one day writes two
  -- contact_log rows and leaves this column unchanged — two conversations in
  -- one day are two conversations, and the cadence clock runs off the date.
  last_contact_date DATE         NULL,

  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  -- Serves the import dupe check, once per parsed draft:
  --   SELECT id FROM people WHERE name_key = ?
  KEY idx_name_key (name_key),

  -- Serves the birthday reconciliation pass the cron runs at the start of
  -- every run, and the "whose birthday is this month" reads:
  --   SELECT id, birth_month, birth_day FROM people WHERE birth_month IS NOT NULL
  KEY idx_birthday (birth_month, birth_day),

  -- Serves the People list's secondary sort and the "who have I not spoken to
  -- in ages" read. NULLs sort first ASC in MySQL, which puts never-contacted
  -- people at the top of exactly that query for free.
  KEY idx_last_contact (last_contact_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------- relationship_tags

-- A TABLE, not an ENUM on people. The brief says custom tags can be added, and
-- an ENUM makes "add a tag" an ALTER TABLE against live data.
--
-- Seeded 1-5 in the order the People list groups by. ALL FIVE ARE DELETABLE —
-- same rule as Grocery's four seeded stores. is_preset exists ONLY so the UI
-- can decide whether to offer a delete control; nothing else in the app may
-- treat a preset as special, and the database certainly doesn't.
CREATE TABLE IF NOT EXISTS relationship_tags (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(64)  NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,

  -- 1 = shipped with the app, 0 = the user made it. A hint for the UI, not a
  -- permission. See above.
  is_preset   TINYINT(1)   NOT NULL DEFAULT 0,

  PRIMARY KEY (id),

  -- Unique because the tag picker would otherwise be able to show "Friend"
  -- twice, and because it is what makes the seed INSERTs below re-runnable.
  UNIQUE KEY uniq_name (name),

  -- Serves: SELECT * FROM relationship_tags ORDER BY sort_order, name
  -- (the tag-picker sheet, and the group order on the People tab).
  KEY idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The order is closeness, not the alphabet: it is the order the People list
-- groups in, and Family reading above Acquaintance is the point.
INSERT IGNORE INTO relationship_tags (name, sort_order, is_preset) VALUES
  ('Family',       1, 1),
  ('Friend',       2, 1),
  ('Close Friend', 3, 1),
  ('Colleague',    4, 1),
  ('Acquaintance', 5, 1);


-- ---------------------------------------------------------- person_tag_map

-- Many-to-many. A person can be a Colleague and a Close Friend at once, which
-- is the whole reason this is tags and not a single category column.
--
-- The composite (person_id, tag_id) IS the primary key: there is no second way
-- to address a link row and nothing points at one, so a surrogate id would buy
-- nothing and would let the same link exist twice.
--
-- BOTH sides cascade, unlike the string-not-foreign-key rule the sibling app
-- uses for categories. A category on a grocery item is a snapshot of a past
-- decision worth preserving through a rename; a tag link is a live pointer
-- with no historical meaning at all. Delete the tag and the links are noise.
CREATE TABLE IF NOT EXISTS person_tag_map (
  person_id  INT UNSIGNED NOT NULL,
  tag_id     INT UNSIGNED NOT NULL,
  PRIMARY KEY (person_id, tag_id),

  -- The reverse direction — "everyone tagged Family" — is what the People
  -- list's grouping reads, and the composite primary key's index cannot serve
  -- it because tag_id is not its leading column.
  KEY idx_tag (tag_id),

  CONSTRAINT fk_ptm_person FOREIGN KEY (person_id)
    REFERENCES people(id) ON DELETE CASCADE,
  CONSTRAINT fk_ptm_tag FOREIGN KEY (tag_id)
    REFERENCES relationship_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ================================================== INTERACTION =============

-- -------------------------------------------------------------- gift_ideas

-- A freeform list per person. No status, no price, no purchased flag — the
-- brief calls it "a freeform list, no status tracking" and adding any of those
-- turns a three-line note into a second app.
--
-- Sorts newest-first by id. There is deliberately NO sort_order column: manual
-- ordering would mean a column and a -order.php endpoint for a list that is
-- typically three items long. public/assets/reorder.js is ported anyway
-- because it costs one file, so if this turns out to matter the module is
-- already sitting there. See PLAN.md §4.6.
CREATE TABLE IF NOT EXISTS gift_ideas (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  person_id   INT UNSIGNED NOT NULL,

  -- 500 for the same reason as the sibling app's shopping_items.name: these
  -- are described wants ("the walnut cutting board from the shop on Grand"),
  -- not product names.
  idea_text   VARCHAR(500) NOT NULL,

  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  -- Serves the profile's read, and the birthday email's:
  --   SELECT * FROM gift_ideas WHERE person_id = ? ORDER BY id DESC
  KEY idx_person (person_id, id),

  CONSTRAINT fk_gift_person FOREIGN KEY (person_id)
    REFERENCES people(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------- contact_log

-- One row per logged interaction. Append-only in practice: the app writes it
-- and reads it, and nothing edits a past row.
--
-- This table and people.last_contact_date are NOT redundant. The column is the
-- one value the cadence arithmetic reads (a DATE, cheap to index, cheap to
-- compare); the table is the history that answers "when did we last actually
-- talk about the wedding". Deriving the column with MAX(logged_at) on every
-- dashboard load would be an aggregate over a growing table on the app's most
-- frequent query, and it would still need a DATE() cast to compare.
CREATE TABLE IF NOT EXISTS contact_log (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  person_id  INT UNSIGNED NOT NULL,

  -- A DATETIME, unlike people.last_contact_date. Two calls in one day are two
  -- rows and the times are what tell them apart in the history list, even
  -- though the cadence clock only ever looks at the date.
  logged_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- Optional. The 1-tap "Logged today" button on the dashboard writes a row
  -- with no note at all, and that has to stay a one-tap action — requiring a
  -- note is how a logging button stops being used.
  note       VARCHAR(500) NULL,

  PRIMARY KEY (id),

  -- Serves the profile's history accordion, which is read newest-first on
  -- every single profile view:
  --   SELECT * FROM contact_log WHERE person_id = ? ORDER BY logged_at DESC
  KEY idx_person_time (person_id, logged_at),

  CONSTRAINT fk_log_person FOREIGN KEY (person_id)
    REFERENCES people(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ==================================================== REMINDERS =============

-- --------------------------------------------------------------- reminders

-- One scheduled nudge. Two kinds, and they behave differently on purpose —
-- see reminder_sends below and PLAN.md §7.2.
--
-- BIRTHDAY REMINDERS ARE MATERIALIZED AS ROWS rather than computed on the fly
-- from people.birth_month/day. The alternative was tempting (no sync problem),
-- but it makes the dashboard a UNION of two differently-shaped queries and,
-- worse, leaves reminder_sends with no stable reminder_id to key idempotency
-- on — for the one email that absolutely must not double-send.
--
-- The sync cost is paid in exactly two places, and they must be written
-- together or not at all:
--   1. people_save() reconciles that person's birthday reminder.
--   2. The cron reconciles EVERY person's at the start of each run, before it
--      looks for anything due. A full pass over a few hundred rows is nothing,
--      and it means a birthday edited by some path that forgot to reconcile
--      self-heals within 24 hours instead of silently never firing.
-- Belt and braces on purpose: a reminder that silently never fires is
-- undetectable by the person relying on it.
CREATE TABLE IF NOT EXISTS reminders (
  id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  person_id                INT UNSIGNED NOT NULL,

  type                     ENUM('reach_out','birthday') NOT NULL,

  -- NULL = a one-off, which only ever makes sense for a reach_out ("remind me
  -- about Sam on the 3rd"). Non-NULL = a repeating cadence in days. A birthday
  -- row leaves this NULL: its recurrence is "annually" and is implicit in the
  -- type, not a number that could drift to 364.
  recurrence_interval_days INT UNSIGNED NULL,

  -- The date this fires. For a birthday that is the birthday MINUS
  -- cfg('reminders.birthday_lead_days'), already computed — the lead is
  -- applied when the row is written, never when it is read, so the cron and
  -- the dashboard cannot disagree about it.
  --
  -- DATE, so the whole due query is `WHERE next_due_date <= ?` with a Y-m-d
  -- string from crm_today(). Index-friendly, portable, and exercisable by the
  -- SQLite test harness, which cannot evaluate DATE_ADD or INTERVAL.
  next_due_date            DATE NOT NULL,

  created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  -- ONE reach-out schedule and ONE birthday schedule per person. The brief
  -- describes a reach-out reminder as either a cadence OR a one-off date,
  -- never both and never several. Making that a database constraint turns
  -- "change the reminder" into an UPSERT rather than a delete-then-insert that
  -- can leave an orphan behind if it dies in the middle.
  UNIQUE KEY uniq_person_type (person_id, type),

  -- Serves the only query the cron and the dashboard run:
  --   SELECT * FROM reminders WHERE next_due_date <= ?
  KEY idx_due (next_due_date),

  CONSTRAINT fk_reminder_person FOREIGN KEY (person_id)
    REFERENCES people(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------- reminder_sends

-- Send idempotency, and a send history for free.
--
-- WHY THIS TABLE EXISTS. The brief says "an individual email sent as each
-- reminder comes due". It does not say what happens when the cron runs twice,
-- or when the cron sends and then dies before it can advance next_due_date.
-- Both are ordinary: Hostinger's scheduler can double-fire, and SMTP can hang.
-- Without this table the failure mode is "the same birthday email eight
-- times", which is the kind of thing that makes you stop trusting the app and
-- start checking the dates yourself — at which point the app has no purpose.
--
-- WHY (reminder_id, due_date) IS THE PRIMARY KEY AND NOT A UNIQUE INDEX BESIDE
-- A SURROGATE id. "At most one send per reminder per due date" is the entire
-- content of this table. Making it the primary key means the DATABASE enforces
-- it, on every path, including the one somebody adds in six months without
-- reading this comment — rather than application logic that has to be right
-- every time. There is also no second way to address a row here and nothing
-- points at one, so the key IS the row.
--
-- The cron's step is then a single statement that is safe to run twice:
--   INSERT INTO reminder_sends (reminder_id, due_date) VALUES (?, ?)
--     ON DUPLICATE KEY UPDATE attempts = attempts + 1
-- and it only sends when it inserted a fresh row or found one with sent_at
-- still NULL. A failed send leaves sent_at NULL and retries tomorrow; a
-- successful one can never fire again.
--
-- Note that a REACH-OUT reminder's next_due_date does not move when it sends
-- (see below), so the same (reminder_id, due_date) pair keeps matching this
-- row day after day — which is exactly what stops the daily cron emailing you
-- about your sister every morning until you call her.
CREATE TABLE IF NOT EXISTS reminder_sends (
  reminder_id INT UNSIGNED NOT NULL,

  -- The due date the send was FOR, copied from reminders.next_due_date at the
  -- moment of the attempt. Not derivable afterwards: a birthday reminder
  -- advances its next_due_date by a year the instant it sends, so by the time
  -- anyone reads this row the reminder no longer knows what day this was.
  due_date    DATE NOT NULL,

  -- NULL = attempted and not yet delivered. Non-NULL = delivered, never again.
  -- The state that matters is the NULL, and it is the reason this is a
  -- timestamp rather than a boolean: "when did that email actually go" is a
  -- question you ask exactly once, in a panic, and only a timestamp answers it.
  sent_at     DATETIME NULL,

  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,

  -- Truncated SMTP failure reason. 255 because it is a diagnostic, not a log:
  -- the full text goes to error_log, this is what a future admin screen would
  -- show next to the row.
  last_error  VARCHAR(255) NULL,

  PRIMARY KEY (reminder_id, due_date),

  CONSTRAINT fk_send_reminder FOREIGN KEY (reminder_id)
    REFERENCES reminders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ======================================================= IMPORT =============

-- The staging tables the brief requires without naming: it asks that a vCard
-- import stage its contacts for review rather than creating people directly,
-- "to prevent bulk-importing junk contacts". These are what "staged" means.
--
-- There is deliberately no "Add all" button anywhere in the UI. It would
-- quietly defeat the entire design.

-- ----------------------------------------------------------- import_batches

CREATE TABLE IF NOT EXISTS import_batches (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- The uploaded file's own name, kept for the review screen's heading only.
  -- The FILE ITSELF is deleted as soon as it is parsed — the drafts are the
  -- artifact, and keeping the raw .vcf means keeping every phone number you
  -- decided not to import.
  filename      VARCHAR(255) NOT NULL,

  uploaded_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- What the parser found, including entries it then refused (a card with
  -- neither FN nor N). Stored so the review screen can say "218 of 224
  -- contacts" rather than silently showing fewer rows than the file had.
  total_parsed  INT UNSIGNED NOT NULL DEFAULT 0,

  promoted      INT UNSIGNED NOT NULL DEFAULT 0,

  -- 'open' = drafts still awaiting a decision. 'done' = finished, and its
  -- drafts are pruned. The row survives the pruning so the import is still in
  -- the record after its drafts are gone.
  status        ENUM('open','done') NOT NULL DEFAULT 'open',

  PRIMARY KEY (id),

  -- Serves: SELECT * FROM import_batches WHERE status = 'open' ORDER BY id DESC
  KEY idx_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------ import_drafts

-- A parsed contact that is NOT a person yet. Deliberately shaped like people
-- rather than sharing it: a draft has no notes, no last_contact_date and no
-- reminders, and giving it those columns would invite code that treats the two
-- as interchangeable.
CREATE TABLE IF NOT EXISTS import_drafts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id      INT UNSIGNED NOT NULL,

  name          VARCHAR(190) NOT NULL,
  name_key      VARCHAR(190) NOT NULL,

  birth_year    SMALLINT UNSIGNED NULL,
  birth_month   TINYINT  UNSIGNED NULL,
  birth_day     TINYINT  UNSIGNED NULL,

  address       VARCHAR(500) NULL,
  phone         VARCHAR(64)  NULL,
  email         VARCHAR(190) NULL,

  -- A FLAG, NOT A LINK WITH MEANING. The brief says to flag a possible
  -- duplicate rather than auto-merging, so nothing in this app may act on this
  -- column except to render a warning pill next to the draft. It is not a
  -- foreign key precisely so that it cannot cascade, cannot be joined through
  -- by accident, and cannot survive as a stale pointer if that person is later
  -- deleted. Promoting a flagged draft creates a SECOND person, and that is
  -- correct: two people really can share a name.
  dup_person_id INT UNSIGNED NULL,

  status        ENUM('pending','added','skipped') NOT NULL DEFAULT 'pending',

  PRIMARY KEY (id),

  -- Serves the review queue:
  --   SELECT * FROM import_drafts WHERE batch_id = ? AND status = 'pending'
  --    ORDER BY id
  KEY idx_batch_status (batch_id, status, id),

  CONSTRAINT fk_draft_batch FOREIGN KEY (batch_id)
    REFERENCES import_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ======================================================= AUTH ===============

-- ------------------------------------------------------------ login_attempts

-- Login throttling. One row per attempt, pruned opportunistically by
-- auth_record_attempt().
--
-- Counting has to be keyed to the client address server-side: a session counter
-- protects nothing, because an attacker simply discards the cookie between
-- guesses. Shape matches the sibling apps exactly — lib/auth.php is a port and
-- its queries assume these column names.
CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip           VARCHAR(45)  NOT NULL,     -- 45 chars covers IPv6
  succeeded    TINYINT(1)   NOT NULL DEFAULT 0,
  attempted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),

  -- Serves auth_throttle_state():
  --   SELECT COUNT(*) ... WHERE ip = ? AND succeeded = 0
  --                         AND attempted_at > NOW() - INTERVAL ? MINUTE
  KEY idx_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
