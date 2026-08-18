<?php
/**
 * me.php  —  GET  —  the currently signed-in user (session restore).
 *
 * The SPA calls this on load: if a valid session cookie is present it returns
 * the fresh user + wallet (same shape as login.php); otherwise 401 so the app
 * knows to show the login screen. Balances are re-read live, not cached.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_method('GET');

$uid = current_user_id();
if ($uid === null) {
    json_error('Not authenticated.', 401);
}

$pdo = Database::pdo();

$stmt = $pdo->prepare(
    'SELECT u.user_id, u.full_name, u.email, u.join_date,
            w.available_balance, w.escrow_balance
       FROM Users u
       LEFT JOIN Wallets w ON w.user_id = u.user_id
      WHERE u.user_id = :id'
);
$stmt->execute([':id' => $uid]);
$user = $stmt->fetch();

if (!$user) {
    // The session points at a user that no longer exists — clear it.
    logout_user();
    json_error('Session user no longer exists.', 401);
}

$user['user_id']           = (int) $user['user_id'];
$user['available_balance'] = (float) $user['available_balance'];
$user['escrow_balance']    = (float) $user['escrow_balance'];

json_ok($user);
