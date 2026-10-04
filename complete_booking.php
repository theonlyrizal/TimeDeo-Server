<?php
/**
 * complete_booking.php  —  POST  —  the REQUESTER confirms the work is done,
 * releasing the escrowed hours to the provider.
 *
 * *** THE FLAGSHIP TRANSACTION (assignment §3 "Transaction") ***
 *
 * When a booking completes, four things must happen as ONE atomic unit
 * (see release_escrow_locked() in booking_lib.php):
 *   1. deduct the agreed hours from the REQUESTER's escrow_balance
 *   2. add those hours to the PROVIDER's available_balance
 *   3. update the booking's status to 'completed'
 *   4. write an immutable row into the Transactions ledger
 * If ANY step fails, ROLLBACK undoes all of them so credits can never be lost
 * or duplicated. On success, COMMIT makes all four permanent together.
 *
 * Two-sided sync: only the requester can confirm (the person who received the
 * help), from 'in_progress' or after the provider marked it 'delivered'. If the
 * requester never answers a delivery, booking_lib's auto-release pays the
 * provider after AUTO_RELEASE_HOURS.
 *
 * Body: { "booking_id": 1 }
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_lib.php';
require_method('POST');

$uid = require_login();

$in = read_json_body();
require_fields($in, ['booking_id']);
$bookingId = (int) $in['booking_id'];

$pdo = Database::pdo();

try {
    // ===================== BEGIN TRANSACTION =====================
    $pdo->beginTransaction();

    // 0. Load + lock the booking so it can't be completed twice concurrently.
    $booking = lock_booking($pdo, $bookingId);

    if ((int) $booking['requester_id'] !== $uid) {
        throw new ApiError('Only the person who received the help can confirm it.', 403);
    }
    $status = $booking['booking_status'];
    if ($status === 'completed') {
        throw new ApiError('This booking is already completed.', 409);
    }
    if ($status === 'cancelled') {
        throw new ApiError('A cancelled booking cannot be completed.', 409);
    }
    if ($status === 'pending') {
        throw new ApiError('The provider has not accepted this booking yet.', 409);
    }

    // 1-4. Escrow -> provider, booking completed, ledger row (all or nothing).
    $result = release_escrow_locked($pdo, $booking, 'confirmed');

    // ===================== COMMIT (all four steps succeed together) =====================
    $pdo->commit();

    $result['booking'] = fetch_booking_for($pdo, $bookingId, $uid);
    json_ok($result);
} catch (Throwable $e) {
    // ===================== ROLLBACK: undo every step above =====================
    fail_tx($pdo, $e, 'Escrow release failed; the booking was left unchanged.');
}
