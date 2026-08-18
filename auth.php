<?php
/**
 * auth.php — PHP session helpers for TimeDeo login / logout.
 *
 * WHY SESSIONS WORK HERE: the React dev server (Vite, :5173) proxies /api to
 * this PHP server, so from the browser's point of view every request is
 * SAME-ORIGIN. The PHP session cookie therefore round-trips normally — no
 * cross-origin / SameSite=None / HTTPS headaches. (See server/README.md.)
 *
 * Include this AFTER db.php. db.php only emits headers (no body) up front, so
 * the Set-Cookie that session_start() adds is still sent before any output.
 */

declare(strict_types=1);

/** Start (or resume) the app session with safe cookie settings. Idempotent. */
function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('TIMEDEO_SESSID');
    session_set_cookie_params([
        'lifetime' => 0,        // a session cookie: clears when the browser closes
        'path'     => '/',
        'httponly' => true,     // JavaScript cannot read it (XSS mitigation)
        'samesite' => 'Lax',    // correct for the same-origin proxy setup
        // 'secure' left default: local dev is plain http on localhost
    ]);
    session_start();
}

/** Record a successful login. Regenerates the id to prevent session fixation. */
function login_user(int $userId): void
{
    start_app_session();
    session_regenerate_id(true);
    $_SESSION['user_id']  = $userId;
    $_SESSION['login_at'] = time();
}

/** The logged-in user's id, or null if nobody is authenticated. */
function current_user_id(): ?int
{
    start_app_session();
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

/** Tear the session down completely (used by logout.php). */
function logout_user(): void
{
    start_app_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        // Expire the cookie on the client too.
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}
