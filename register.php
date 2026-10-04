<?php
/**
 * register.php  —  POST  —  create a new user + their wallet + a contact method.
 *
 * DEMONSTRATES:
 *   - DML: INSERT into three tables
 *   - TRANSACTION: the Users row, its 1:1 Wallets row and its first
 *     User_Contacts row are created together; if any fails, none is written
 *     (BEGIN / COMMIT / ROLLBACK).
 *   - Security: password stored with password_hash() (bcrypt), never plain text.
 *
 * Body: { "full_name": "...", "email": "...", "password": "...",
 *         "contact_method": "phone"|"whatsapp"|"telegram"|"email"|"facebook",
 *         "contact_value": "...", "headline"?: "..." }
 *
 * The contact is how members you trade with can reach you. It is never shown
 * publicly — only to someone who books you, offers to help you, or reveals it
 * from one of your posts (see contacts.php).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_lib.php';
require_method('POST');

$in = read_json_body();
require_fields($in, ['full_name', 'email', 'password', 'contact_method', 'contact_value']);

$fullName = clean_text($in['full_name'], 120, 'Name');
$email    = trim((string) $in['email']);
$password = (string) $in['password'];
$headline = clean_text($in['headline'] ?? null, 120, 'Headline');

if ($fullName === null || mb_strlen($fullName) < 2) {
    json_error('Please enter your name.', 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error('Please provide a valid email address.', 400);
}
if (strlen($password) < 6) {
    json_error('Password must be at least 6 characters.', 400);
}
$contactMethod = (string) $in['contact_method'];
$contactValue  = normalize_contact($contactMethod, $in['contact_value']);

$pdo  = Database::pdo();
$hash = password_hash($password, PASSWORD_DEFAULT); // bcrypt; never store the raw password

try {
    // --- TRANSACTION: user + wallet + contact are created atomically ---
    $pdo->beginTransaction();

    $u = $pdo->prepare(
        'INSERT INTO Users (full_name, email, password_hash, headline) VALUES (:name, :email, :hash, :headline)'
    );
    $u->execute([':name' => $fullName, ':email' => $email, ':hash' => $hash, ':headline' => $headline]);
    $userId = (int) $pdo->lastInsertId();

    // New members get a small welcome grant so they can book right away.
    $w = $pdo->prepare(
        'INSERT INTO Wallets (user_id, available_balance, escrow_balance) VALUES (:uid, :grant, 0)'
    );
    $w->execute([':uid' => $userId, ':grant' => 2.00]);

    $c = $pdo->prepare('INSERT INTO User_Contacts (user_id, method, value) VALUES (:uid, :m, :v)');
    $c->execute([':uid' => $userId, ':m' => $contactMethod, ':v' => $contactValue]);

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
