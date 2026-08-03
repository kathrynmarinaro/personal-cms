<?php
/* Single-password auth on top of a long-lived PHP session.
 *
 * ---------------------------------------------------------------------------
 * THE GATE IS ON. Every screen in this app is private — there is no public
 * surface, no embeddable view and no second role. One password, one person.
 *
 * It gets its OWN session cookie name (see auth_start_session()), so signing in
 * or out here never disturbs the RSS Reader or Grocery on the same host — the
 * brief asks for that explicitly.
 *
 * Setting it up:
 *   1. php tools/make-hash.php
 *   2. paste the hash into config.php as 'password_hash'
 *
 * It fails OPEN when no hash is configured — see require_login_page(). That
 * matters more here than in any sibling: a leaked grocery list is embarrassing,
 * this database is everyone you know, their addresses and their birthdays. An
 * unconfigured deploy is a PUBLIC deploy.
 * ---------------------------------------------------------------------------
 *
 * Ported from Grocery, which ports it from Book Tracker, which ports it from
 * the Workout Generator, which ports it from the Inspiration Gallery. All five
 * share this app's stack and threat model (one password, public internet, no
 * user table). Keep them in sync when any one is fixed — the throttling logic
 * was hard-won.
 *
 * Ported from GROCERY specifically, because Grocery is the only one of them
 * that has auth_attempt_delay() factored out as a pure function. The others
 * have that arithmetic inline inside auth_attempt_login() and therefore
 * untested; Grocery's CLAUDE.md flags it as worth backporting. We inherit the
 * good version, and tools/run-tests.php exercises the curve.
 *
 * Deliberately DROPPED in the port:
 *   - Book Tracker's public-page concept. Nothing here is public, so there is
 *     no require_admin()/public split to make: one gate, called by everything.
 *   - The Workout Generator's guest passcode and role system. Single user, and
 *     this is the one app in the suite with nothing you would ever share. */

declare(strict_types=1);

function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $days    = (int) cfg('session_days', 90);
    $seconds = $days * 86400;
    $https   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    session_set_cookie_params(array(
        'lifetime' => $seconds,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
    ));
    ini_set('session.gc_maxlifetime', (string) $seconds);

    /* Its own cookie name, so signing in or out of this app doesn't disturb a
     * session for one of the siblings sharing the same host. */
    session_name('personalcrm');
    session_start();
}

function auth_is_logged_in(): bool
{
    auth_start_session();
    return !empty($_SESSION['authed']);
}

/** True once a password hash is configured — i.e. the gate is usable at all. */
function auth_is_configured(): bool
{
    $hash = (string) cfg('password_hash', '');
    return $hash !== '' && $hash !== 'CHANGE_ME';
}

/* ------------------------------------------------------- login throttling */

/* A single password on the public internet needs more than a fixed delay, or
 * an attacker gets unlimited guesses at whatever rate the server allows.
 *
 * Counting is keyed to the client address in the database, not the session —
 * an attacker just drops the cookie, so session counters protect nothing. */

const AUTH_WINDOW_MINUTES = 15;   // how far back failures are counted
const AUTH_LOCK_AFTER     = 10;   // failures in that window before refusing
const AUTH_SLOW_AFTER     = 3;    // failures before delays start escalating
const AUTH_MAX_DELAY      = 4;    // seconds — cap so a request can't hang

/**
 * REMOTE_ADDR only, deliberately.
 *
 * X-Forwarded-For is trivially spoofed, and trusting it would let an attacker
 * present a new address per request and bypass this entirely. The cost is that
 * behind a proxy every visitor may share one address — which is why there is
 * no permanent lockout below, only a window that always expires.
 */
function auth_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : 'unknown';
}

/**
 * Throttle state for this client: recent failure count and, if locked out,
 * how many seconds remain.
 *
 * Fails OPEN if the table is missing — deploying this code before running
 * schema.sql should not lock you out of your own app. It logs instead.
 *
 * @return array{failures:int, blocked_for:int}
 */
function auth_throttle_state(): array
{
    $none = array('failures' => 0, 'blocked_for' => 0);

    /* Every timestamp comparison happens inside SQL, on MySQL's clock.
     *
     * Doing the arithmetic in PHP silently disabled this in a sibling app:
     * PHP ran in UTC while MySQL ran in CDT, so strtotime() read MySQL's
     * local-time string as UTC and produced an unlock time five hours in the
     * past. blocked_for was always 0 and the lockout never fired, while the
     * escalating delays kept making it look like throttling worked.
     * One clock, no conversions. */
    try {
        $row = q(
            'SELECT COUNT(*) AS failures,
                    GREATEST(0, COALESCE(
                      TIMESTAMPDIFF(SECOND, NOW(), MAX(attempted_at) + INTERVAL ? MINUTE), 0
                    )) AS blocked_for
               FROM login_attempts
              WHERE ip = ?
                AND succeeded = 0
                AND attempted_at > NOW() - INTERVAL ? MINUTE',
            array(AUTH_WINDOW_MINUTES, auth_client_ip(), AUTH_WINDOW_MINUTES)
        )->fetch();
    } catch (Throwable $e) {
        error_log('auth: throttle unavailable (run schema.sql): ' . $e->getMessage());
        return $none;
    }

    $failures = (int) ($row['failures'] ?? 0);

    // The window runs from the most recent failure, so hammering the lock
    // keeps it shut rather than letting attempts leak through as it ages out.
    return array(
        'failures'    => $failures,
        'blocked_for' => $failures >= AUTH_LOCK_AFTER ? (int) $row['blocked_for'] : 0,
    );
}

/** Seconds this client must wait, or 0 when it may try. */
function auth_blocked_for(): int
{
    return auth_throttle_state()['blocked_for'];
}

/**
 * How long to stall this attempt, in seconds, given how many failures this
 * address already has in the window.
 *
 * Baseline delay so a wrong password can't be timed, then escalation once the
 * address starts looking like a guessing loop: 0.25s flat, then 0.5, 1, 2, 4,
 * capped. An attacker's throughput collapses while one honest typo still costs
 * nothing noticeable.
 *
 * Pulled out of auth_attempt_login() as a pure function so tools/run-tests.php
 * can check the curve without a database, a session or a real password. This
 * is the shape it has in Grocery; every other sibling still has the arithmetic
 * inline inside auth_attempt_login() and therefore untested. Keep it factored
 * out — the curve is the part of the throttle worth regression-testing.
 */
function auth_attempt_delay(int $failures): float
{
    if ($failures < AUTH_SLOW_AFTER) {
        return 0.25;
    }
    return min(0.25 * (2 ** ($failures - AUTH_SLOW_AFTER + 1)), (float) AUTH_MAX_DELAY);
}

function auth_record_attempt(bool $succeeded): void
{
    try {
        q(
            'INSERT INTO login_attempts (ip, succeeded) VALUES (?, ?)',
            array(auth_client_ip(), $succeeded ? 1 : 0)
        );

        // Clear this address's failures on success so one good login resets
        // the counter, and prune old rows so the table can't grow unbounded.
        if ($succeeded) {
            q('DELETE FROM login_attempts WHERE ip = ? AND succeeded = 0', array(auth_client_ip()));
        }
        if (random_int(1, 20) === 1) {
            q('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 30 DAY');
        }
    } catch (Throwable $e) {
        error_log('auth: could not record attempt: ' . $e->getMessage());
    }
}

/**
 * Verify a password attempt and start the session. Returns success.
 *
 * Callers must check auth_blocked_for() first — this records the attempt and
 * escalates its own delay, but does not enforce the lockout itself.
 */
function auth_attempt_login(string $password): bool
{
    auth_start_session();

    if (!auth_is_configured()) {
        return false;
    }
    $hash = (string) cfg('password_hash', '');

    usleep((int) round(auth_attempt_delay(auth_throttle_state()['failures']) * 1_000_000));

    if (!password_verify($password, $hash)) {
        auth_record_attempt(false);
        return false;
    }

    auth_record_attempt(true);

    session_regenerate_id(true);
    $_SESSION['authed']   = true;
    $_SESSION['login_at'] = time();
    return true;
}

function auth_logout(): void
{
    auth_start_session();
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', array(
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'httponly' => true,
            'secure'   => $p['secure'],
            'samesite' => 'Lax',
        ));
    }
    session_destroy();
}

/* ------------------------------------------------------------- THE GATE */

/**
 * Gate an HTML screen. Called by EVERY entry point in public/ except
 * login.php — every screen in this app is private.
 *
 * Emits the noindex header itself rather than making each screen remember to.
 * Every gated screen wants it, and a screen that forgets is exactly the screen
 * that ends up in a search result.
 *
 * Fails OPEN when no password hash is configured. That is deliberate: someone
 * who deploys this without setting a hash would otherwise be locked out of
 * their own app with no way in, since login.php cannot authenticate against a
 * hash that does not exist. Setting 'password_hash' in config.php is what arms
 * the gate.
 */
function require_login_page(): void
{
    noindex();

    if (!auth_is_configured()) {
        return;
    }
    if (!auth_is_logged_in()) {
        /* Come back to the screen you were on rather than dumping you on
         * Today and making you navigate back to a profile you were reading. */
        $next = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
        $qs   = (string) ($_SERVER['QUERY_STRING'] ?? '');
        if ($qs !== '') {
            $next .= '?' . $qs;
        }
        header('Location: login.php?next=' . rawurlencode($next));
        exit;
    }
}

/**
 * The same gate for JSON endpoints. 401s instead of redirecting.
 *
 * Kept separate from require_login_page() rather than branching inside it: a
 * fetch() that follows a 302 to an HTML login page produces a JSON parse error
 * at the caller, which is a genuinely confusing way to learn you're signed out.
 * assets/api.js turns this 401 into an ApiError with code 'unauthorized', which
 * is a thing a tab module can act on.
 */
function require_login_api(): void
{
    if (!auth_is_configured()) {
        return;
    }
    if (!auth_is_logged_in()) {
        json_error('unauthorized', 401);
    }
}

/**
 * Keep this app out of search results.
 *
 * NOT redundant with the gate: the gate fails open when no hash is set, and an
 * unconfigured deploy is exactly when you least want a crawler indexing your
 * friends' home addresses. Called automatically by require_login_page().
 */
function noindex(): void
{
    header('X-Robots-Tag: noindex, nofollow');
}

/* CSRF: mutating endpoints require a custom header. A cross-origin form post
 * cannot set one without passing a CORS preflight we never answer, so this
 * plus SameSite=Lax is sufficient for a single-user app.
 *
 * assets/api.js sends this on every non-GET automatically. A hand-rolled
 * fetch() that forgets it gets a 403 with code 'csrf_check_failed'. */
const CSRF_HEADER_VALUE = 'CRM';

function require_same_origin(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, array('GET', 'HEAD', 'OPTIONS'), true)) {
        return;
    }
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== CSRF_HEADER_VALUE) {
        json_error('csrf_check_failed', 403);
    }
}
