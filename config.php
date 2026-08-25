<?php
/**
 * config.php — database credentials & app constants.
 *
 * Values come from ENVIRONMENT VARIABLES when present (production: Railway, RDS,
 * etc. inject them), and fall back to the stock XAMPP defaults for local dev
 * (root / no password / db "timedeo"). This keeps real secrets OUT of the repo:
 * commit config.example.php, set the real values in your host's env vars, and
 * (recommended) add config.php to .gitignore if you ever hardcode anything here.
 *
 * Env vars read:
 *   DB_HOST  DB_PORT  DB_NAME  DB_USER  DB_PASS   — database connection
 *   CORS_ORIGIN                                   — allowed browser origin
 *   APP_DEBUG                                     — "1" to include error detail
 *
 * Note: Railway's MySQL plugin exposes MYSQLHOST / MYSQLUSER / MYSQLPASSWORD /
 * MYSQLDATABASE / MYSQLPORT — map those onto DB_* in the service's Variables
 * (e.g. DB_HOST = ${{MySQL.MYSQLHOST}}), or rename the reads below to match.
 */

$env = static function (string $key, ?string $default = null): ?string {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
};

return [
    'db' => [
        'host'    => $env('DB_HOST', '127.0.0.1'),
        'port'    => (int) $env('DB_PORT', '3306'),
        'name'    => $env('DB_NAME', 'timedeo'),
        'user'    => $env('DB_USER', 'root'),
        'pass'    => $env('DB_PASS', ''),
        'charset' => 'utf8mb4',
    ],
    // Browser origin(s) allowed to call this API. For a same-origin setup (Vite
    // proxy in dev, Vercel rewrite in prod) CORS is moot. For a DIRECT
    // cross-origin call from Vercel WITH the session cookie, the value must be an
    // EXACT origin (never '*') — db.php echoes it back and adds
    // Allow-Credentials: true. Comma-separate multiple origins; db.php reflects
    // whichever one matches the incoming request. Override via CORS_ORIGIN env.
    'cors_allow_origin' => $env('CORS_ORIGIN', 'https://time-deo-client.vercel.app'),

    // When '1', json_error() includes the raw exception message in `detail`.
    // Keep this OFF (unset / '0') in production so internals never leak.
    'debug' => $env('APP_DEBUG', '0') === '1',
];
