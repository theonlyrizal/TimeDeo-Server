<?php
/**
 * register.php  —  POST  —  create a new user + their wallet.
 *
 * DEMONSTRATES:
 *   - DML: INSERT
 *   - TRANSACTION: the Users row and its 1:1 Wallets row are created together;
 *     if either fails, neither is written (BEGIN / COMMIT / ROLLBACK).
 *   - Security: password stored with password_hash() (bcrypt), never plain text.
 *
 * Body: { "full_name": "...", "email": "...", "password": "..." }
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_method('POST');

$in = read_json_body();
require_fields($in, ['full_name', 'email', 'password']);

$fullName = trim($in['full_name']);
$email    = trim($in['email']);
$password = $in['password'];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error('Please provide a valid email address.', 400);
}
if (strlen($password) < 6) {
    json_error('Password must be at least 6 characters.', 400);
}

$pdo  = Database::pdo();
$hash = password_hash($password, PASSWORD_DEFAULT); // bcrypt; never store the raw password

try {
    // --- TRANSACTION: user + wallet are created atomically ---
    $pdo->beginTransaction();

    $u = $pdo->prepare(
        'INSERT INTO Users (full_name, email, password_hash) VALUES (:name, :email, :hash)'
    );
    $u->execute([':name' => $fullName, ':email' => $email, ':hash' => $hash]);
    $userId = (int) $pdo->lastInsertId();

    // New members get a small welcome grant so they can book right away.
    $w = $pdo->prepare(
        'INSERT INTO Wallets (user_id, available_balance, escrow_balance) VALUES (:uid, :grant, 0)'
    );
    $w->execute([':uid' => $userId, ':grant' => 2.00]);

    $pdo->commit();

    // Auto-login the new member (same session mechanism as login.php).
    login_user($userId);

    json_ok([
        'user_id'           => $userId,
        'full_name'         => $fullName,
        'email'             => $email,
        'available_balance' => 2.00,
        'escrow_balance'    => 0.00,
    ], 201);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // 23000 = integrity constraint violation; here it means the UNIQUE email clashed.
    if ($e->getCode() === '23000') {
        json_error('That email is already registered.', 409);
    }
    json_error('Registration failed.', 500, $e->getMessage());
}
