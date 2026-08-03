<?php
/* The login screen.
 *
 * Deliberately NOT gated — require_login_page() redirects here, so gating it
 * would be an infinite redirect.
 *
 * Rendered standalone rather than through layout.php: a signed-out visitor must
 * not be shown the tab bar, which is a list of the private URLs. Ported from
 * Grocery unchanged apart from the title, using the shared .login-* classes. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

noindex();

/* Already signed in? Nothing to do here. */
if (auth_is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';
$wait  = auth_blocked_for();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!auth_is_configured()) {
        /* No hash in config.php. Say so plainly instead of failing as a wrong
         * password — with the gate failing open, the app is fully usable in
         * this state, and "incorrect password" would send you hunting for a
         * typo that isn't there. */
        $error = 'No password is set yet. Run tools/make-hash.php and add the hash to config.php.';
    } elseif ($wait > 0) {
        // Refuse without checking the password at all — the whole point is to
        // stop this address testing more guesses.
        $minutes = (int) ceil($wait / 60);
        $error   = sprintf(
            'Too many attempts. Try again in %d minute%s.',
            $minutes,
            $minutes === 1 ? '' : 's'
        );
    } elseif (auth_attempt_login((string) ($_POST['password'] ?? ''))) {
        /* Back to whichever screen you were headed for, but only if it's a path on
         * this site — an absolute URL here would make this an open redirect. */
        $next = (string) ($_POST['next'] ?? 'index.php');
        if ($next === '' || str_contains($next, '://') || str_starts_with($next, '//')) {
            $next = 'index.php';
        }
        header('Location: ' . $next);
        exit;
    } else {
        $error = 'Incorrect password.';
        $wait  = auth_blocked_for();
    }
}

$next = (string) ($_GET['next'] ?? $_POST['next'] ?? 'index.php');
if ($next === '' || str_contains($next, '://') || str_starts_with($next, '//')) {
    $next = 'index.php';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#41b7ab">
<title>Personal CRM</title>
<link rel="stylesheet" href="<?= asset('assets/styles.css') ?>">
</head>
<body class="login-body">
  <main class="login-card">
    <h1 class="login-title">Personal CRM</h1>

    <form method="post" action="login.php" class="login-form" autocomplete="on">
      <input type="hidden" name="next" value="<?= h($next) ?>">

      <label class="field">
        <span class="label">Password</span>
        <?php /* autofocus so the keyboard is already up on a phone; the whole
                 screen is one field and there is nothing else to tap. */ ?>
        <input
          class="input"
          type="password"
          id="password"
          name="password"
          autocomplete="current-password"
          autofocus
          required
          <?= $wait > 0 ? 'disabled' : '' ?>>
      </label>

      <?php if ($error !== ''): ?>
        <p class="login-error" role="alert"><?= h($error) ?></p>
      <?php endif; ?>

      <button class="btn-primary" type="submit" <?= $wait > 0 ? 'disabled' : '' ?>>Enter</button>
    </form>
  </main>
</body>
</html>
