<?php
/**
 * create_booking.php  —  POST  —  the signed-in member books an Offer (locks escrow).
 *
 * DEMONSTRATES (assignment §3 "Transaction"):
 *   A multi-step operation wrapped in BEGIN / COMMIT / ROLLBACK. Booking a
 *   service must (a) move the agreed hours from the requester's available
 *   balance into escrow and (b) create the pending booking — atomically.
 *   Row-level locks (SELECT ... FOR UPDATE) prevent a double-spend if the same
 *   user fires two bookings at once.
 *
 * Body: { "listing_id": 4, "scheduled_at": "2026-10-08T10:30:00.000Z", "note"?: "..." }
 *   - the requester is ALWAYS the session user (never trusted from the body)
 *   - agreed_hours is the listing's estimated_hours (the price is not client-set)
 *   - scheduled_at must be in the future (at most 90 days ahead)
 *
 * The booking starts 'pending' until the provider accepts it.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_lib.php';
require_method('POST');

$requesterId = require_login();

$in = read_json_body();
require_fields($in, ['listing_id', 'scheduled_at']);

$listingId   = (int) $in['listing_id'];
$scheduledAt = parse_client_dt($in['scheduled_at']);
$note        = clean_text($in['note'] ?? null, 1000, 'Note');

if ($scheduledAt === null) {
    json_error('Pick a valid date and time for the session.', 400);
}
$when = new DateTimeImmutable($scheduledAt, new DateTimeZone('UTC'));
$now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
if ($when <= $now) {
    json_error('The session time must be in the future.', 400);
}
if ($when > $now->modify('+90 days')) {
    json_error('Sessions can be booked up to 90 days ahead.', 400);
}

$pdo = Database::pdo();

try {
    // ===== BEGIN TRANSACTION =====
    $pdo->beginTransaction();

    // 1. Load + lock the listing to read its provider and hours.
    $l = $pdo->prepare('SELECT user_id AS provider_id, estimated_hours, status, listing_type
                          FROM Listings WHERE listing_id = :id FOR UPDATE');
    $l->execute([':id' => $listingId]);
    $listing = $l->fetch();
    if (!$listing) {
        throw new ApiError('Listing not found.', 404);
    }
    if ($listing['listing_type'] !== 'Offer') {
        throw new ApiError('Help requests are answered with an offer, not booked.', 400);
    }
    if ($listing['status'] !== 'active') {
        throw new ApiError('This listing is not open for booking.', 409);
    }

    $providerId = (int) $listing['provider_id'];
    $hours      = (float) $listing['estimated_hours'];
    if ($providerId === $requesterId) {
        throw new ApiError('You cannot book your own listing.', 400);
    }

    // 2. Lock the requester's wallet, then check the balance.
    $wallets = lock_wallets($pdo, $requesterId, $requesterId);
    if ((float) $wallets[$requesterId]['available_balance'] < $hours) {
        throw new ApiError('Insufficient balance to book this service.', 400);
    }

    // 3. Move hours available -> escrow (distinct placeholders: no marker reuse).
    $pdo->prepare('UPDATE Wallets
                      SET available_balance = available_balance - :h1,
                          escrow_balance    = escrow_balance    + :h2
                    WHERE user_id = :uid')
        ->execute([':h1' => $hours, ':h2' => $hours, ':uid' => $requesterId]);

    // 4. Create the pending booking.
    $pdo->prepare("INSERT INTO Bookings (listing_id, requester_id, provider_id, agreed_hours, scheduled_at, note, booking_status)
                   VALUES (:l, :r, :p, :h, :at, :note, 'pending')")
        ->execute([
            ':l' => $listingId, ':r' => $requesterId, ':p' => $providerId,
            ':h' => $hours, ':at' => $scheduledAt, ':note' => $note,
        ]);
    $bookingId = (int) $pdo->lastInsertId();

    // ===== COMMIT (both the wallet move and the booking stick together) =====
    $pdo->commit();

    json_ok([
        'booking_id'   => $bookingId,
        'status'       => 'pending',
        'provider_id'  => $providerId,
        'hours_locked' => $hours,
        'scheduled_at' => iso_dt($scheduledAt),
    ], 201);
} catch (Throwable $e) {
    // ===== ROLLBACK on any failure (e.g. CHECK balance >= 0) =====
    fail_tx($pdo, $e, 'Could not create booking; no changes were made.');
}
