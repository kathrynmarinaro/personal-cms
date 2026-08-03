<?php
/* =====================================================================
 * Personal CRM — configuration
 * ---------------------------------------------------------------------
 * Copy this file to config.php and fill in your real values.
 * config.php is gitignored and must never be committed.
 *
 * On Hostinger this file lives ONE LEVEL ABOVE your web root, next to
 * lib/ — so it can never be served over the web even if PHP is off.
 * The root .htaccess denies it a second time, belt and braces.
 * ===================================================================== */

return array(

    /* ---- database ---------------------------------------------------
     * Create the DB in hPanel, then paste those values here.
     * Host is usually 'localhost'.
     */
    'db' => array(
        'host'    => 'localhost',
        'name'    => 'personalcrm',
        'user'    => 'CHANGE_ME',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ),

    /* ---- the clock --------------------------------------------------
     * READ THIS ONE. It is the highest-consequence value in the file.
     *
     * EXPLICIT, NOT INHERITED FROM THE SERVER. Hostinger sets its PHP
     * default and its MySQL default independently, and neither is
     * guaranteed to be the zone you live in — the usual answer is UTC
     * for one and something else for the other. This app is entirely
     * made of dates: "due today", "seven days before a birthday",
     * "last contact + 60 days", "which day did the cron run on". A
     * one-day skew is not a subtle bug here, it is birthday cards
     * arriving late, every time, with nothing on screen to explain it.
     *
     * Set once, used twice: lib/bootstrap.php calls
     * date_default_timezone_set() with it the instant config loads, and
     * lib/db.php pins the MySQL connection to the same offset at
     * connect time. One clock, no conversions. Nothing else in the app
     * asks what day it is except crm_today() in lib/dates.php.
     *
     * A named zone here, NOT an offset: DST is handled correctly, and
     * lib/db.php converts it to the numeric offset MySQL needs.
     *
     * Change it and everything moves together. Change it after there is
     * data and nothing breaks — every stored date is a plain calendar
     * date with no zone attached.
     */
    'timezone' => 'America/Chicago',

    /* ---- the gate ---------------------------------------------------
     * Single user, password only. Store the HASH, never the password.
     *
     *   php tools/make-hash.php
     *
     * THE GATE IS ON, and this app gets its own session cookie
     * ('personalcrm'), so signing in or out here never disturbs the RSS
     * Reader or Grocery on the same host.
     *
     * Leaving this empty or as CHANGE_ME does NOT lock the app: the gate
     * fails open, so a deploy with no hash is reachable by anyone who
     * finds the URL rather than being reachable by nobody including you.
     *
     * SET IT BEFORE POINTING A DOMAIN AT THIS. A leaked grocery list is
     * embarrassing; this database is everyone you know, their home
     * addresses and their birthdays.
     */
    'password_hash' => 'CHANGE_ME',

    // How long a login lasts on a device, in days.
    'session_days'  => 90,

    /* ---- web root ---------------------------------------------------
     * Name of the directory this app's public files live in, relative to
     * the folder holding lib/ and this file. Leave empty to auto-detect
     * "public" (a local checkout) or "public_html" (Hostinger).
     *
     * Only set it if your host uses some other name. Getting it wrong
     * doesn't error — it silently stops asset() cache-busting the CSS and
     * JS, so a deploy appears not to have taken effect.
     */
    'public_dir' => '',

    /* ---- reminders --------------------------------------------------
     * Read in ONE place each, so the cron and the dashboard cannot
     * disagree about them. Same reasoning as Grocery's grace_seconds.
     */
    'reminders' => array(
        /* How many days BEFORE a birthday the reminder fires. Seven is
         * enough to order something and have it arrive.
         *
         * This is applied when a reminder row is WRITTEN, not when it is
         * read (see reminders.next_due_date in schema.sql). Changing it
         * therefore only affects reminders written afterwards — the
         * cron's nightly reconciliation pass recomputes every birthday
         * reminder, so the change lands everywhere within 24 hours.
         */
        'birthday_lead_days' => 7,

        /* The default cadence offered when you add a reach-out reminder
         * and don't pick one. Per-person cadences are stored on the
         * reminder row; this is only the picker's initial position.
         */
        'default_cadence_days' => 60,
    ),

    /* ---- outgoing email ---------------------------------------------
     * SMTP rather than mail(), for deliverability: a message sent
     * through mail() from a shared host arrives in spam often enough
     * that you would stop trusting the reminders.
     *
     * DECIDED: PHPMailer, vendored. Three plain files
     * (PHPMailer.php, SMTP.php, Exception.php) in lib/vendor/PHPMailer/,
     * required directly — no Composer, no autoloader, no build step, so
     * they FTP up with everything else. lib/mailer.php wraps them so
     * nothing else in the app knows which choice was made.
     *
     * FROM_EMAIL MUST MATCH USER. Gmail rewrites or outright rejects a
     * From address it has not authorized, and the bounce is silent from
     * this app's point of view — the send "succeeds" and the mail never
     * arrives. This is the single most common way this setup fails.
     *
     * 'pass' is a Gmail APP PASSWORD, not the account password. Regular
     * passwords have not worked for SMTP since 2022.
     *
     * Verify it with `php tools/send-test-email.php` BEFORE trusting a
     * cron you cannot watch run.
     */
    'smtp' => array(
        'host'       => 'smtp.gmail.com',
        'port'       => 587,
        'secure'     => 'tls',        // 'tls' (587, STARTTLS) or 'ssl' (465)
        'user'       => 'kathrynmarinaro@gmail.com',
        'pass'       => 'CHANGE_ME',  // Gmail APP PASSWORD
        'from_email' => 'kathrynmarinaro@gmail.com',  // MUST equal 'user'
        'from_name'  => 'Personal CRM',

        /* Single user: every reminder goes to this one address. Make it
         * the one you actually read on your phone — a reminder in an
         * inbox you check weekly is not a reminder.
         */
        'to'         => 'kathrynmarinaro@gmail.com',
    ),

    /* ---- vCard import ------------------------------------------------ */
    'import' => array(
        /* Refused above this size. Checked against $_FILES['size'] AND
         * against PHP's own upload_max_filesize at boot, because a file
         * larger than PHP's limit arrives SILENTLY TRUNCATED — and a
         * truncated .vcf parses perfectly happily as a shorter one, so
         * the failure is "some of my contacts are missing" months later
         * rather than an error at upload time.
         *
         * 10 MB is generous for a photo-free export; photos are skipped
         * at parse time and never stored (PLAN.md §6.3), but they are
         * still in the uploaded file, and a 400-contact export with
         * photos can be 30 MB of which 29 MB is images.
         */
        'max_upload_mb' => 10,

        /* Cap on drafts staged from one file, so a pathological or
         * malicious .vcf cannot fill the database. Anything past this is
         * reported, not silently dropped.
         */
        'max_contacts'  => 2000,
    ),

    /* ---- cron -------------------------------------------------------
     * Hostinger plans differ. Some give a real command cron:
     *
     *   php /home/uXXXX/domains/.../tools/cron-reminders.php
     *
     * Others only offer a URL fetch, and tools/ is denied over HTTP —
     * so public/cron.php exists as a thin wrapper around the same code,
     * gated by this token compared with hash_equals().
     *
     * Generate one and paste it in:
     *
     *   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
     *
     * Leave it empty if you have a command cron; public/cron.php refuses
     * to run at all without a token rather than running unauthenticated.
     * A bad token 404s rather than 403s — a 403 confirms the endpoint is
     * there and worth guessing at.
     */
    'cron' => array(
        'token' => '',
    ),
);
