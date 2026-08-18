<?php
/**
 * login.php  —  POST  —  authenticate a user by email + password.
 *
 * DEMONSTRATES:
 *   - DML: SELECT with a JOIN (Users LEFT JOIN Wallets) via a prepared statement.
 *   - Security: password_verify() checks the bcrypt hash; the hash is stripped
 *     from the response so it never leaves the server.
 *
 * Body: { "email": "...", "password": "..." }
 * (Session-less by design — returns the user id for the SPA to hold onto.)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_method('POST');

$in = read_json_body();
require_fields($in, ['email', 'password']);

$pdo = Database::pdo();

$stmt = $pdo->prepare(
    'SELECT u.user_id, u.full_name, u.email, u.password_hash, u.join_date,
            w.available_balance, w.escrow_balance
       FROM Users u
       LEFT JOIN Wallets w ON w.user_id = u.user_id
      WHERE u.email = :email'
);
$stmt->execute([':email' => trim($in['email'])]);
$user = $stmt->fetch();

// Same generic message whether the email is unknown or the password is wrong,
// so we don't reveal which emails exist.
if (!$user || !password_verify($in['password'], $user['password_hash'])) {
    json_error('Invalid email or password.', 401);
}

unset($user['password_hash']); // never send the hash to the client

// Return numbers as numbers, not DECIMAL-as-string.
$user['user_id']           = (int) $user['user_id'];
$user['available_balance'] = (float) $user['available_balance'];
$user['escrow_balance']    = (float) $user['escrow_balance'];

// Establish the server-side session so subsequent /me.php calls stay logged in.
login_user($user['user_id']);

json_ok($user);
