<?php
/**
 * db.php — PDO connection + shared REST helpers for the TimeDeo API.
 *
 * Every endpoint starts with:   require_once __DIR__ . '/db.php';
 * That single include:
 *   1. Sends the CORS + JSON headers (and short-circuits OPTIONS preflight).
 *   2. Exposes Database::pdo() — a single, reused PDO connection.
 *   3. Provides json_ok() / json_error() / read_json_body() / require_fields().
 *
 * DATA-ACCESS POLICY (assignment §4): the ONLY way SQL runs in this project is
 * PDO prepared statements (prepare + execute). No ORM, no query builder, no
 * string-concatenated user input. db.php configures PDO to enforce that safely.
 */

declare(strict_types=1);

/* ---------------------------------------------------------------------------
 * CORS + content type. The React client runs on a different local port
 * (Vite :5173) than PHP (XAMPP :80), so the browser treats API calls as
 * cross-origin. These headers make the browser accept the responses.
 * ------------------------------------------------------------------------- */
$__config = require __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: ' . $__config['cors_allow_origin']);
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Browsers send a preflight OPTIONS request before POST/PUT/DELETE. Answer it
// with 204 and stop — there is no body to process.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ---------------------------------------------------------------------------
 * Database — lazy singleton around one PDO connection.
 * ------------------------------------------------------------------------- */
final class Database
{
    private static ?PDO $instance = null;

    /** Returns the shared PDO connection, creating it on first use. */
    public static function pdo(): PDO
    {
        if (self::$instance === null) {
            global $__config;
            $c = $__config['db'];

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $c['host'], $c['port'], $c['name'], $c['charset']
            );

            try {
                self::$instance = new PDO($dsn, $c['user'], $c['pass'], [
                    // Throw exceptions on error so we can catch + return clean JSON.
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    // Rows come back as associative arrays (clean for json_encode).
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Use REAL server-side prepared statements (true anti-injection).
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                // Never leak connection internals to the client.
                json_error('Database connection failed.', 500, $e->getMessage());
            }
        }
        return self::$instance;
    }
}

/* ---------------------------------------------------------------------------
 * JSON response helpers.
 * ------------------------------------------------------------------------- */

/** Send a success payload and stop. */
function json_ok($data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send an error payload and stop.
 * $detail is only surfaced for local debugging; keep it out of production.
 */
function json_error(string $message, int $status = 400, ?string $detail = null): void
{
    global $__config;
    http_response_code($status);
    $body = ['success' => false, 'error' => $message];
    // Only surface internal detail when APP_DEBUG=1 (off in production), so raw
    // DB / exception messages never leak to clients. Set APP_DEBUG=1 locally to
    // see them while developing.
    if ($detail !== null && !empty($__config['debug'])) {
        $body['detail'] = $detail;
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------------------------------------------------------------------
 * Request helpers.
 * ------------------------------------------------------------------------- */

/** Parse and return the JSON request body as an associative array. */
function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_error('Request body must be valid JSON.', 400);
    }
    return $data;
}

/**
 * Ensure every required key is present and non-empty in $data.
 * Returns nothing; sends a 400 and stops if anything is missing.
 */
function require_fields(array $data, array $required): void
{
    $missing = [];
    foreach ($required as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            $missing[] = $field;
        }
    }
    if ($missing) {
        json_error('Missing required field(s): ' . implode(', ', $missing), 400);
    }
}

/** Restrict an endpoint to a single HTTP method (or list of methods). */
function require_method($allowed): void
{
    $allowed = (array) $allowed;
    $method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, $allowed, true)) {
        json_error('Method not allowed. Use: ' . implode(', ', $allowed), 405);
    }
}
