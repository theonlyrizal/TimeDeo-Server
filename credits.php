<?php
/**
 * credits.php  —  buy time credits (DEMO bKash checkout — no real money moves).
 *
 *   GET  credits.php        -> { packages: [...], purchases: [... my last 20] }
 *   POST credits.php        -> buy a package
 *        body: { "package_id": 2, "payer_account": "01712345678" }
 *
 * The price and credit amount always come from Credit_Packages on the server —
 * the client only names a package. The bKash wallet number is validated and
 * stored MASKED; the demo PIN / OTP never leave the browser.
 *
 * DEMONSTRATES: a TRANSACTION — INSERT the purchase row and UPDATE the wallet
 * together (both or neither), with the wallet row locked.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_lib.php';

$uid    = require_login();
$pdo    = Database::pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        $packages = array_map(static fn (array $p): array => [
            'package_id' => (int) $p['package_id'],
            'label'      => $p['label'],
            'credits'    => (float) $p['credits'],
            'price_bdt'  => (float) $p['price_bdt'],
        ], $pdo->query('SELECT package_id, label, credits, price_bdt FROM Credit_Packages
                         WHERE is_active = 1 ORDER BY credits')->fetchAll());

        $q = $pdo->prepare('SELECT purchase_id, credits, amount_bdt, method, payer_account, trx_id, created_at
                              FROM Credit_Purchases WHERE user_id = :uid ORDER BY created_at DESC LIMIT 20');
        $q->execute([':uid' => $uid]);
        $purchases = array_map(static fn (array $p): array => [
            'purchase_id'   => (int) $p['purchase_id'],
            'credits'       => (float) $p['credits'],
            'amount_bdt'    => (float) $p['amount_bdt'],
            'method'        => $p['method'],
            'payer_account' => $p['payer_account'],
            'trx_id'        => $p['trx_id'],
            'created_at'    => iso_dt($p['created_at']),
        ], $q->fetchAll());

        json_ok(['packages' => $packages, 'purchases' => $purchases]);
    }

    if ($method !== 'POST') {
        json_error('Method not allowed.', 405);
    }

    $in = read_json_body();
    require_fields($in, ['package_id', 'payer_account']);
    $packageId = (int) $in['package_id'];
    $account   = preg_replace('/\D/', '', (string) $in['payer_account']);
    if (!preg_match('/^01[3-9]\d{8}$/', $account)) {
        json_error('Enter an 11-digit bKash account number (01XXXXXXXXX).', 400);
    }
    $masked = substr($account, 0, 3) . str_repeat('*', 6) . substr($account, -2);

    $pdo->beginTransaction();

    $p = $pdo->prepare('SELECT credits, price_bdt FROM Credit_Packages WHERE package_id = :id AND is_active = 1');
    $p->execute([':id' => $packageId]);
    $pkg = $p->fetch();
    if (!$pkg) {
        throw new ApiError('That package is not available.', 404);
    }
    $credits = (float) $pkg['credits'];

    lock_wallets($pdo, $uid, $uid);

    // Demo transaction id in bKash's style: 10 uppercase alphanumerics.
    $trxId = strtoupper(substr(bin2hex(random_bytes(8)), 0, 10));

    $pdo->prepare('INSERT INTO Credit_Purchases (user_id, package_id, credits, amount_bdt, payer_account, trx_id)
                   VALUES (:u, :p, :c, :a, :acc, :trx)')
        ->execute([
            ':u' => $uid, ':p' => $packageId, ':c' => $credits, ':a' => (float) $pkg['price_bdt'],
            ':acc' => $masked, ':trx' => $trxId,
        ]);
    $purchaseId = (int) $pdo->lastInsertId();

    $pdo->prepare('UPDATE Wallets SET available_balance = available_balance + :c WHERE user_id = :uid')
        ->execute([':c' => $credits, ':uid' => $uid]);

    $pdo->commit();

    $w = $pdo->prepare('SELECT available_balance, escrow_balance FROM Wallets WHERE user_id = :uid');
    $w->execute([':uid' => $uid]);
    $wallet = $w->fetch();

    json_ok([
        'purchase_id'       => $purchaseId,
        'trx_id'            => $trxId,
        'credits'           => $credits,
        'amount_bdt'        => (float) $pkg['price_bdt'],
        'payer_account'     => $masked,
        'available_balance' => (float) $wallet['available_balance'],
        'escrow_balance'    => (float) $wallet['escrow_balance'],
    ], 201);
} catch (Throwable $e) {
    fail_tx($pdo, $e, 'The payment could not be completed; no credits were added.');
}
