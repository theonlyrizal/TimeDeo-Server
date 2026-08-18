<?php
/**
 * create_booking.php  —  POST  —  a requester books a listing (locks escrow).
 *
 * DEMONSTRATES (assignment §3 "Transaction"):
 *   A multi-step operation wrapped in BEGIN / COMMIT / ROLLBACK. Booking a
 *   service must (a) move the agreed hours from the requester's available
 *   balance into escrow and (b) create the pending booking — atomically.
 *   Row-level locks (SELECT ... FOR UPDATE) prevent a double-spend if the same
 *   user fires two bookings at once.
 *
 * Body: { "listing_id": 4, "requester_id": 1, "agreed_hours"?: 2 }
 *       (agreed_hours defaults to the listing's estimated_hours)
 */

require_once __DIR__ . '/db.php';
require_method('POST');

$in = read_json_body();
require_fields($in, ['listing_id', 'requester_id']);

$listingId   = (int) $in['listing_id'];
$requesterId = (int) $in['requester_id'];

$pdo = Database::pdo();

try {
    // ===== BEGIN TRANSACTION =====
    $pdo->beginTransaction();

    // 1. Load + lock the listing to read its provider and hours.
    $l = $pdo->prepare('SELECT user_id AS provider_id, estimated_hours, status
                          FROM Listings WHERE listing_id = :id FOR UPDATE');
    $l->execute([':id' => $listingId]);
    $listing = $l->fetch();
    if (!$listing) {
        $pdo->rollBack();
        json_error('Listing not found.', 404);
    }
    if ($listing['status'] !== 'active') {
        $pdo->rollBack();
        json_error('This listing is not open for booking.', 409);
    }

    $providerId = (int) $listing['provider_id'];
    $hours      = isset($in['agreed_hours']) && $in['agreed_hours'] !== ''
        ? (float) $in['agreed_hours']
        : (float) $listing['estimated_hours'];

    if ($hours <= 0) {
        $pdo->rollBack();
        json_error('agreed_hours must be greater than 0.', 400);
    }
    if ($providerId === $requesterId) {
        $pdo->rollBack();
        json_error('You cannot book your own listing.', 400);
    }

    // 2. Load + lock the requester's wallet, then check the balance.
    $w = $pdo->prepare('SELECT available_balance FROM Wallets WHERE user_id = :uid FOR UPDATE');
    $w->execute([':uid' => $requesterId]);
    $wallet = $w->fetch();
    if (!$wallet) {
        $pdo->rollBack();
        json_error('Requester wallet not found.', 404);
    }
    if ((float) $wallet['available_balance'] < $hours) {
        $pdo->rollBack();
        json_error('Insufficient balance to book this service.', 400);
    }

    // 3. Move hours available -> escrow (distinct placeholders: no marker reuse).
    $move = $pdo->prepare('UPDATE Wallets
                              SET available_balance = available_balance - :h1,
                                  escrow_balance    = escrow_balance    + :h2
                            WHERE user_id = :uid');
    $move->execute([':h1' => $hours, ':h2' => $hours, ':uid' => $requesterId]);

    // 4. Create the pending booking.
    $bk = $pdo->prepare('INSERT INTO Bookings (listing_id, requester_id, provider_id, agreed_hours, booking_status)
                         VALUES (:l, :r, :p, :h, "pending")');
    $bk->execute([':l' => $listingId, ':r' => $requesterId, ':p' => $providerId, ':h' => $hours]);
    $bookingId = (int) $pdo->lastInsertId();

    // ===== COMMIT (both the wallet move and the booking stick together) =====
    $pdo->commit();

    json_ok([
        'booking_id'   => $bookingId,
        'status'       => 'pending',
        'provider_id'  => $providerId,
        'hours_locked' => $hours,
    ], 201);
} catch (PDOException $e) {
    // ===== ROLLBACK on any failure (e.g. CHECK balance >= 0) =====
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_error('Could not create booking; no changes were made.', 500, $e->getMessage());
}
