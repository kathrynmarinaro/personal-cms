<?php
/**
 * Print a password hash to paste into config.php.
 *
 *   php tools/make-hash.php
 *   php tools/make-hash.php 'my password'
 *
 * Given with no argument it reads the password from a prompt, which keeps it out
 * of your shell history — worth preferring, since this is the only credential the
 * app has.
 *
 * CLI only. tools/ is denied over HTTP by tools/.htaccess, so this is already
 * unreachable, but the guard means an accidental copy into public/ still can't
 * turn into a hash oracle for anyone who finds it.
 *
 * Ported from the Workout Generator unchanged.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$password = $argv[1] ?? null;

if ($password === null) {
    // Echo off, so the password isn't left on screen or in a screen-share.
    $silent = @shell_exec('stty -g 2>/dev/null');
    if (is_string($silent) && $silent !== '') {
        shell_exec('stty -echo');
    }
    fwrite(STDOUT, 'Password: ');
    $password = rtrim((string) fgets(STDIN), "\r\n");
    if (is_string($silent) && $silent !== '') {
        shell_exec('stty ' . trim($silent));
        fwrite(STDOUT, PHP_EOL);
    }
}

if ($password === '') {
    fwrite(STDERR, "No password given.\n");
    exit(1);
}

if (strlen($password) < 8) {
    // Warn rather than refuse: it's your app, but this password is the only thing
    // between the open internet and your data, and login throttling only slows a
    // guessing attack down — it doesn't stop a short password being guessed.
    fwrite(STDERR, "Warning: shorter than 8 characters.\n");
}

fwrite(STDOUT, PHP_EOL . "Paste this into config.php as 'password_hash':" . PHP_EOL . PHP_EOL);
fwrite(STDOUT, "    'password_hash' => '" . password_hash($password, PASSWORD_DEFAULT) . "'," . PHP_EOL . PHP_EOL);
