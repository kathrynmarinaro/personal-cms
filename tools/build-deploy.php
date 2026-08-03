<?php
/* Assemble the FTP bundle: the app, laid out the way it has to sit on
 * Hostinger, as a directory and a zip of that directory.
 *
 * THIS SCRIPT DELIBERATELY FAILED UNTIL THE APP WAS FINISHED. Its manifests
 * named DEPLOY.txt and public/index.php before either existed, because a
 * packaging tool that cheerfully produces a bundle with no screens in it is a
 * tool that lets you upload one. Phase 3 wrote the last of them; the manifest
 * now also names the pieces that make reminders actually leave the building —
 * public/cron.php, tools/cron-reminders.php and the three vendored PHPMailer
 * files — because a bundle missing any of those deploys an app that looks
 * completely healthy and never sends anything.
 *
 * Deployment here is a person dragging files into a file manager, so the
 * failure modes are human ones. This script exists to remove the three that
 * actually bite:
 *
 *   1. The tree has to sit on the server the way the root .htaccess shim
 *      expects it to. Getting the public/ folder's name wrong doesn't error —
 *      asset() silently stops cache-busting and a deploy just appears not to
 *      have taken effect. See the note by the public/ copy below.
 *   2. The four .htaccess files are the only thing keeping config.php and
 *      uploads/ off the public internet, and every file manager hides dotfiles
 *      by default. This script REFUSES to produce a bundle missing one.
 *   3. config.php holds a live database password, the Gmail app password and
 *      the cron token. It is gitignored, but a build that copies a directory
 *      tree would happily sweep it into a zip that then gets emailed around.
 *      It is excluded, and the exclusion is asserted.
 *
 * Test files, the harness, docs/ and CLAUDE.md are left out — not to hide them,
 * but because every file uploaded is a file someone has to look at and decide
 * about later, and none of these run on the server.
 *
 * Usage:
 *   php tools/build-deploy.php
 */

declare(strict_types=1);

/* Deliberately does NOT require lib/bootstrap.php, unlike every other script in
 * tools/. Bootstrap exits when config.php is absent — correct for anything that
 * touches the database, and exactly wrong here: packaging the app for its first
 * upload is the one job you do BEFORE there is a config to read. */
define('APP_ROOT', dirname(__DIR__));

const BUNDLE_NAME = 'personal-crm-deploy';

/* Copied as-is, relative to the app root. Order is only for the manifest. */
const INCLUDE_ROOT_FILES = array(
    '.htaccess',
    'config.example.php',
    'schema.sql',
    'DEPLOY.txt',
);

/* Whole directories, copied recursively, minus SKIP_FILES below.
 *
 * uploads/ is here for ONE FILE: its deny-all .htaccess. The directory has to
 * exist on the server before the first import, and it has to be denied before
 * it exists — creating it by hand later means creating it without the
 * .htaccess, which is how a folder full of .vcf files ends up on the web.
 * Nothing else in it is ever tracked (see .gitignore). */
const INCLUDE_DIRS = array('lib', 'tools', 'uploads');

/* Never leaves this machine.
 *
 * config.php is the dangerous one: real credentials, and gitignored, so it is
 * exactly the file a naive tree copy picks up and a git-based check misses. */
const SKIP_FILES = array(
    'config.php',
    'run-tests.php',
    'test-harness.php',
    'build-deploy.php',
    '.DS_Store',
);

/* Test files are tools/tests-<track>.php, matched by prefix. */
const SKIP_PREFIXES = array('tests-');

/* Must all be present in the finished bundle or the build fails. Hiding
 * dotfiles is the file manager default, so a missing one is invisible until
 * someone fetches /config.php over HTTP and gets it. */
/* uploads/.htaccess earns its place on this list more than any of the others:
 * an uploaded .vcf is names, phone numbers and home addresses for everyone in
 * somebody's phone. The file is supposed to be deleted the instant it is
 * parsed, so this only matters on the run where the parser threw — which is
 * exactly the run nobody is watching. */
const REQUIRED_HTACCESS = array(
    '.htaccess',
    'lib/.htaccess',
    'tools/.htaccess',
    'uploads/.htaccess',
);

function main(): void
{
    $root    = APP_ROOT;
    $out     = $root . '/' . BUNDLE_NAME;
    $zipPath = $root . '/' . BUNDLE_NAME . '.zip';

    /* A stale bundle is worse than none: it looks current and ships last
     * week's code. Rebuild from empty every time. */
    if (is_dir($out)) {
        rrmdir($out);
    }
    mkdir($out, 0755, true);

    $copied = array();

    foreach (INCLUDE_ROOT_FILES as $file) {
        if (!is_file($root . '/' . $file)) {
            fail($file . ' is missing from the repository');
        }
        copy_file($root . '/' . $file, $out . '/' . $file);
        $copied[] = $file;
    }

    foreach (INCLUDE_DIRS as $dir) {
        $copied = array_merge($copied, copy_tree($root . '/' . $dir, $out . '/' . $dir, $dir));
    }

    /* public/ KEEPS ITS NAME. Grocery's copy of this script used to rename it
     * to public_html here, on the assumption that the domain points one level
     * above the web root — and that shipped a 404, because the root .htaccess
     * is a document-root shim that rewrites every request into `public/` by
     * name. The bundle contradicted the .htaccess it was bundling.
     *
     * Keeping the name is right for BOTH layouts. When the (sub)domain points at
     * the app folder, as it does on this account, the shim finds public/ where
     * it expects it. When a host lets you point the document root straight at
     * .../personal-cms/public, the shim sits above the served tree and never
     * runs, so the name is irrelevant. Renaming only ever helped the layout this
     * app doesn't use. */
    $copied = array_merge($copied, copy_tree($root . '/public', $out . '/public', 'public'));

    /* ---- assertions: a bundle that fails these must not ship ---- */

    foreach (REQUIRED_HTACCESS as $needed) {
        if (!is_file($out . '/' . $needed)) {
            fail('the bundle is missing ' . $needed . ' — that file is what keeps config.php off the web');
        }
    }

    if (is_file($out . '/config.php')) {
        fail('config.php reached the bundle — it holds your database password');
    }

    foreach (array('tools/run-tests.php', 'tools/test-harness.php') as $devFile) {
        if (is_file($out . '/' . $devFile)) {
            fail($devFile . ' reached the bundle');
        }
    }

    if (!is_file($out . '/public/index.php')) {
        fail('public/index.php is missing');
    }

    /* The URL-fetch cron. On a plan with no SSH this is the ONLY way the daily
     * job ever runs, and a bundle without it deploys an app whose reminders
     * silently never fire — which is the one failure this app cannot survive
     * and cannot report. tools/cron-reminders.php ships too, inside tools/,
     * for the plans that do have a command cron. */
    if (!is_file($out . '/public/cron.php')) {
        fail('public/cron.php is missing — a plan with no SSH would have no way to run the cron');
    }
    if (!is_file($out . '/tools/cron-reminders.php')) {
        fail('tools/cron-reminders.php is missing — there would be no daily job to run');
    }

    /* Vendored PHPMailer, three plain files, required directly. No Composer and
     * no autoloader is the point; a bundle missing them is an app that cannot
     * send a single reminder and says so only in error_log. */
    foreach (array('PHPMailer.php', 'SMTP.php', 'Exception.php') as $vendored) {
        if (!is_file($out . '/lib/vendor/PHPMailer/' . $vendored)) {
            fail('lib/vendor/PHPMailer/' . $vendored . ' is missing — no email can be sent without it');
        }
    }

    /* A .vcf in the bundle means somebody's address book is about to be zipped
     * and emailed around. uploads/ is copied for its .htaccess alone; anything
     * else in it is a file that should already have been deleted. */
    foreach (glob($out . '/uploads/*') ?: array() as $stray) {
        if (basename($stray) !== '.htaccess') {
            fail('an uploaded contacts file reached the bundle: uploads/' . basename($stray));
        }
    }

    /* The bundle and the shim have to agree on the folder name, and the last
     * time they disagreed the whole site was a 404 with nothing in any log to
     * say why: Apache rewrote into a directory that wasn't there, so PHP never
     * ran and never got the chance to complain. Assert the agreement here
     * rather than discovering it on a live domain again. */
    $shim = (string) file_get_contents($out . '/.htaccess');
    if (!str_contains($shim, 'public/$1')) {
        fail('the root .htaccess no longer rewrites into public/ — bundle layout and shim disagree');
    }

    /* ---- zip ---- */

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fail('could not create ' . $zipPath);
    }
    foreach ($copied as $relative) {
        $zip->addFile($out . '/' . $relative, $relative);
    }
    $zip->close();

    sort($copied);
    printf("Bundle:  %s/\n", BUNDLE_NAME);
    printf("Archive: %s.zip  (%s)\n", BUNDLE_NAME, human_size((int) filesize($zipPath)));
    printf("Files:   %d\n\n", count($copied));
    foreach ($copied as $relative) {
        printf("  %s\n", $relative);
    }
    printf("\nAll %d .htaccess files present. config.php excluded.\n", count(REQUIRED_HTACCESS));
}

/** Recursively copy $from to $to, returning the bundle-relative paths written. */
function copy_tree(string $from, string $to, string $prefix): array
{
    if (!is_dir($from)) {
        fail($from . ' is missing from the repository');
    }

    $written = array();
    if (!is_dir($to)) {
        mkdir($to, 0755, true);
    }

    $entries = scandir($from);
    if ($entries === false) {
        fail('could not read ' . $from);
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || skipped($entry)) {
            continue;
        }

        $src = $from . '/' . $entry;
        $dst = $to . '/' . $entry;

        if (is_dir($src)) {
            $written = array_merge($written, copy_tree($src, $dst, $prefix . '/' . $entry));
            continue;
        }

        copy_file($src, $dst);
        $written[] = $prefix . '/' . $entry;
    }

    return $written;
}

function skipped(string $name): bool
{
    if (in_array($name, SKIP_FILES, true)) {
        return true;
    }
    foreach (SKIP_PREFIXES as $prefix) {
        if (str_starts_with($name, $prefix)) {
            return true;
        }
    }
    return false;
}

function copy_file(string $src, string $dst): void
{
    $dir = dirname($dst);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    if (!copy($src, $dst)) {
        fail('could not copy ' . $src);
    }
}

function rrmdir(string $dir): void
{
    $entries = scandir($dir);
    if ($entries === false) {
        return;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function human_size(int $bytes): string
{
    return $bytes < 1024 * 1024
        ? sprintf('%.0f KB', $bytes / 1024)
        : sprintf('%.1f MB', $bytes / 1024 / 1024);
}

function fail(string $why): void
{
    fwrite(STDERR, "build-deploy: " . $why . PHP_EOL);
    exit(1);
}

main();
