<?php
/* PLACEHOLDER. Phase 2B (Reminders) replaces this file outright with the Today
 * dashboard — docs/CONTRACTS.md §1 gives public/index.php to R, and R owes this
 * nothing.
 *
 * It exists now for two reasons: the `today` tab and the site root would
 * otherwise 404, and tools/build-deploy.php refuses to package a bundle without
 * it. Redirecting rather than rendering a stub keeps it honest — there is no
 * dashboard yet, so it sends you to the screen that does work. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

require_login_page();

header('Location: people.php', true, 302);
exit;
