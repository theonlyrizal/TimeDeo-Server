<?php
/**
 * complete_booking.php  —  POST  —  release escrow when a booking finishes.
 *
 * *** THE FLAGSHIP TRANSACTION (assignment §3 "Transaction") ***
 *
 * When a booking completes, four things must happen as ONE atomic unit:
 *   1. deduct the agreed hours from the REQUESTER's escrow_balance
 *   2. add those hours to the PROVIDER's available_balance
 *   3. update the booking's status to 'completed'
 *   4. write an immutable row into the Transactions ledger
 * If ANY step fails, ROLLBACK undoes all of them so credits can never be lost
 * or duplicated. On success, COMMIT makes all four permanent together.
 *
 * Body: { "booking_id": 1 }
 */

require_once __DIR__ . '/db.php';
require_method('POST');

$in = read_json_body();
require_fields($in, ['booking_id']);
$bookingId = (int) $in['booking_id'];

$pdo = Database::pdo();

try {
    // ===================== BEGIN TRANSACTION =====================
    $pdo->beginTransaction();

    // 0. Load + lock the booking so it can't be completed twice concurrently.
    $b = $pdo->prepare('SELECT requester_id, provider_id, agreed_hours, booking_status
                          FROM Bookings WHERE booking_id = :id FOR UPDATE');
    $b->execute([':id' => $bookingId]);
    $booking = $b->fetch();

    if (!$booking) {
        $pdo->rollBack();
        json_error('Booking not found.', 404);
    }
    if ($booking['booking_status'] === 'completed') {
        $pdo->rollBack();
        json_error('This booking is already completed.', 409);
    }
    if ($booking['booking_status'] === 'cancelled') {
        $pdo->rollBack();
        json_error('A cancelled booking cannot be completed.', 409);
    }

    $requesterId = (int)   $booking['requester_id'];
    $providerId  = (int)   $booking['provider_id'];
    $hours       = (float) $booking['agreed_hours'];

    // 0b. Lock the requester wallet and verify enough escrow is held. (The
    //     chk_escrow_nonneg CHECK is the DB-level backstop; this gives a clean
    //     error message instead of a constraint violation.)
    $rw = $pdo->prepare('SELECT escrow_balance FROM Wallets WHERE user_id = :uid FOR UPDATE');
    $rw->execute([':uid' => $requesterId]);
    $reqWallet = $rw->fetch();
    if (!$reqWallet) {
        $pdo->rollBack();
        json_error('Requester wallet not found.', 404);
    }
    if ((float) $reqWallet['escrow_balance'] < $hours) {
        $pdo->rollBack();
        json_error('Requester does not have enough hours in escrow.', 409);
    }

    // 1. Deduct from the requester's escrow.
    $deduct = $pdo->prepare('UPDATE Wallets SET escrow_balance = escrow_balance - :h WHERE user_id = :uid');
    $deduct->execute([':h' => $hours, ':uid' => $requesterId]);

    // 2. Credit the provider's available balance.
    $credit = $pdo->prepare('UPDATE Wallets SET available_balance = available_balance + :h WHERE user_id = :uid');
    $credit->execute([':h' => $hours, ':uid' => $providerId]);

    // 3. Mark the booking completed.
    $mark = $pdo->prepare('UPDATE Bookings SET booking_status = "completed" WHERE booking_id = :id');
    $mark->execute([':id' => $bookingId]);

    // 4. Write the ledger row. The UNIQUE(booking_id) constraint guarantees a
    //    booking can only ever produce one payout (idempotency at the DB level).
    $ledger = $pdo->prepare('INSERT INTO Transactions (booking_id, sender_id, receiver_id, hours_transferred)
                             VALUES (:b, :s, :r, :h)');
    $ledger->execute([':b' => $bookingId, ':s' => $requesterId, ':r' => $providerId, ':h' => $hours]);
    $transactionId = (int) $pdo->lastInsertId();

    // ===================== COMMIT (all four steps succeed together) =====================
    $pdo->commit();

    json_ok([
        'booking_id'       => $bookingId,
        'status'           => 'completed',
        'transaction_id'   => $transactionId,
        'hours_released'   => $hours,
        'from_requester'   => $requesterId,
        'to_provider'      => $providerId,
    ]);
} catch (PDOException $e) {
    // ===================== ROLLBACK: undo every step above =====================
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_error('Escrow release failed; the booking was left unchanged.', 500, $e->getMessage());
}
