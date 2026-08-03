<?php
/* Shared page chrome. Every screen in this app is:
 *
 *   require_once __DIR__ . '/../lib/bootstrap.php';
 *   require_once __DIR__ . '/../lib/layout.php';
 *   require_login_page();
 *   page_head('People', 'people');
 *   screen_head('People', page_menu());
 *   ... markup ...
 *   page_foot('people');
 *
 * There is no bare variant and no public variant. Every screen is private and
 * the same shape — which is why this file is shorter than the siblings'.
 *
 * Ported from Grocery. Two changes: nav_tabs(), and page_menu() — Grocery has
 * no menu because it has nothing app-level to put in one. */

declare(strict_types=1);

const APP_NAME = 'Personal CRM';

/**
 * The bottom tab bar. Keys are the $tab values page_head()/page_foot() accept.
 *
 * THREE TABS, AND THE WHOLE STRUCTURE IS THIS ONE FUNCTION. Changing it later
 * is deliberately cheap — the tab bar is the only thing that knows how many
 * there are, and nothing else in the app enumerates them.
 *
 * Today is leftmost because it is the tab opened daily and the thumb reaches
 * that corner, following the same reasoning Grocery used for its own order.
 * People is the reference book you go to on purpose. Add is a destination, not
 * a habit, so it takes the far corner.
 *
 * vCard import is NOT a tab and is NOT part of Add. It lives in the hamburger
 * menu (page_menu(), below), which is where the Inspiration Gallery and Book
 * Tracker already keep export — app-level things you do once rather than
 * daily. Importing a contacts file would otherwise spend a permanent quarter
 * of the tab bar on a job you do twice, ever, and burying it inside Add would
 * make the Add screen two unrelated things wearing one name.
 *
 * person.php is not here on purpose. It is pushed from Today or People and
 * marks whichever tab it came from active — it is a detail view, not a section.
 */
function nav_tabs(): array
{
    return array(
        'today'  => array('label' => 'Today',  'href' => 'index.php'),
        'people' => array('label' => 'People', 'href' => 'people.php'),
        'add'    => array('label' => 'Add',    'href' => 'add.php'),
    );
}

/**
 * Open a page: doctype through the opening of the content container.
 *
 * @param string      $title Screen title, for <title>.
 * @param string|null $tab   Which tab to mark active, or null for none.
 */
/* The shared ES modules, imported by every screen's entry script as
 * './swipe.js' and friends. Kept here rather than derived from a glob so that
 * adding one is a deliberate act — the test suite checks this list against what
 * the feature modules actually import. */
const SHARED_MODULES = array('api.js', 'swipe.js', 'inline-edit.js', 'reorder.js', 'menu.js');

/**
 * Cache-bust the shared modules, which asset() cannot reach.
 *
 * <script src> goes through asset() and gets ?v=<mtime>, so a changed
 * people.js is fetched fresh. But people.js then does
 *
 *     import { attachSwipeDelete } from './swipe.js';
 *
 * and that specifier is a literal inside a .js file — no PHP runs on it, so
 * there is no version on it, so the browser serves whatever copy it already
 * has. FOREVER. The entry point updates and the modules underneath it do not.
 *
 * This shipped. A trash button was added to every row, the stylesheet updated
 * (asset() covers CSS), the button appeared — wired to a cached swipe.js that
 * had never heard of it. A control that is visibly there and does nothing, with
 * nothing in any log, and "it works on my machine" being literally true because
 * the developer's browser had no old copy to keep.
 *
 * An import map remaps the resolved URLs before any module loads. Keys are
 * document-relative, matching what './swipe.js' resolves to from
 * /assets/<screen>.js, so this keeps working if the app ever moves into a
 * subdirectory.
 *
 * Fails safe twice over: a browser with no import-map support ignores it and
 * behaves exactly as before, and the Cache-Control rule in .htaccess makes the
 * browser revalidate anyway. Neither is trusted alone — the header needs
 * mod_headers, and a silently absent module is how this class of bug happens in
 * the first place.
 */
function shared_module_map(): void
{
    $map = array();
    foreach (SHARED_MODULES as $module) {
        $path = 'assets/' . $module;
        $stamp = @filemtime(PUBLIC_DIR . '/' . $path);
        if ($stamp === false) {
            /* Unstat-able: leave it unmapped rather than mapping it to a URL
             * with no version, which would look handled and not be. */
            continue;
        }
        $map['./' . $path] = './' . $path . '?v=' . $stamp;
    }

    if ($map === array()) {
        return;
    }

    /* JSON_UNESCAPED_SLASHES so the paths read as paths in view-source. The
     * values are filenames from a hardcoded list and integer mtimes, so there
     * is nothing here that could carry a </script>. */
    echo '<script type="importmap">' . "\n";
    echo json_encode(array('imports' => $map), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    echo '</script>' . "\n";
}

function page_head(string $title, ?string $tab = null): void
{
    /* Pinch-zoom stays ON, following Book Tracker rather than the Workout
     * Generator. That app pins maximum-scale=1 because a stray pinch mid-set
     * throws the countdown off-screen and you're holding a dumbbell. Nothing
     * here is time-critical, the rows carry small print (a relationship pill,
     * a "last contacted 34 days ago" subline, an address), and locking zoom on
     * a screen full of small print is an accessibility cost with no matching
     * benefit.
     *
     * viewport-fit=cover is what makes the --safe-* insets in styles.css do
     * anything; without it the fixed tab bar sits above the home indicator with
     * a white band under it. */
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#41b7ab">
<?php /* No " · Personal CRM" suffix when the screen IS the app name — the
         doubled title helps nobody. */ ?>
<title><?= $title === APP_NAME ? h(APP_NAME) : h($title) . ' · ' . h(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= asset('assets/styles.css') ?>">
<?php shared_module_map(); ?>
</head>
<body>
<main class="wrap">
<?php
}

/**
 * The hamburger, for screen_head()'s $asideHtml slot.
 *
 *   screen_head('People', page_menu());
 *
 * WHY THIS IS IN LAYOUT AND NOT IN EACH SCREEN. Every screen gets the same
 * menu, and the moment it is copied into three files it is three files that
 * have to be edited to add "Export" — which is exactly how one of them ends up
 * with a menu one item shorter than the others and nobody notices for a month.
 *
 * The menu is where APP-LEVEL actions live: things you do once rather than
 * daily, which is why they are not tabs. Importing contacts and signing out
 * today; exporting later, following the Inspiration Gallery and Book Tracker,
 * where export lives in exactly this menu. A future action goes in the items
 * array in the screen's entry script, and nowhere else.
 *
 * Renders only the BUTTON. The sheet itself is built on demand by
 * assets/menu.js, for the reason page_foot() gives about the snackbar: an empty
 * fixed-position element in every page is one z-index mistake away from
 * swallowing every tap on the tab bar.
 *
 * The id is fixed rather than a parameter because menu.js is attached by
 * selector and there is exactly one of these per page. The <svg> is inline
 * because .icon-btn styles `svg` directly (stroke: currentColor), and an
 * external icon file would be a second HTTP request and a second thing to
 * cache-bust for three lines of markup.
 */
function page_menu(string $id = 'app-menu'): string
{
    return '<button class="icon-btn" type="button" id="' . h($id) . '" aria-label="Menu" aria-haspopup="dialog">'
        . '<svg viewBox="0 0 24 24" aria-hidden="true">'
        . '<path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/>'
        . '</svg></button>';
}

/** The h1 with its teal rule, plus optional right-hand content. */
function screen_head(string $title, string $asideHtml = ''): void
{
    ?>
  <header class="screen-head">
    <h1><?= h($title) ?></h1>
    <?php if ($asideHtml !== ''): ?>
    <div class="head-actions"><?= $asideHtml ?></div>
    <?php endif; ?>
  </header>
<?php
}

/**
 * Close a page. Pass the same $tab you gave page_head().
 *
 * The snackbar host is NOT rendered here. assets/swipe.js creates it on demand
 * and removes it when it expires, the same way the siblings' menu sheet works —
 * an empty fixed-position element sitting in every page is one z-index mistake
 * away from swallowing taps on the tab bar.
 */
function page_foot(?string $tab = null): void
{
    echo "</main>\n";
    ?>
<nav class="tabbar" aria-label="Sections">
<?php foreach (nav_tabs() as $key => $t): ?>
  <a href="<?= h($t['href']) ?>"<?= $key === $tab ? ' class="is-active" aria-current="page"' : '' ?>><?= h($t['label']) ?></a>
<?php endforeach; ?>
</nav>
<?php
    echo "</body>\n</html>\n";
}
