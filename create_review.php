<?php
/**
 * create_review.php  —  POST  —  leave a rating for a completed booking.
 *
 * DEMONSTRATES:
 *   - DML: INSERT
 *   - Business rules enforced with prepared SELECTs + the DB constraints:
 *       * only a COMPLETED booking can be reviewed
 *       * only a participant (requester or provider) can review it
 *       * UNIQUE(booking_id, reviewer_id) blocks a second review from one person
 *       * CHECK(rating BETWEEN 1 AND 5) backs up the app-level range check
 *
 * Body: { "booking_id": 4, "reviewer_id": 1, "rating": 5, "comment"?: "..." }
 */

require_once __DIR__ . '/db.php';
require_method('POST');

$in = read_json_body();
require_fields($in, ['booking_id', 'reviewer_id', 'rating']);

$bookingId  = (int) $in['booking_id'];
$reviewerId = (int) $in['reviewer_id'];
$rating     = (int) $in['rating'];
$comment    = $in['comment'] ?? null;

if ($rating < 1 || $rating > 5) {
    json_error('Rating must be between 1 and 5.', 400);
}

$pdo = Database::pdo();

try {
    // The booking must exist, be completed, and include this reviewer.
    $b = $pdo->prepare('SELECT requester_id, provider_id, booking_status
                          FROM Bookings WHERE booking_id = :id');
    $b->execute([':id' => $bookingId]);
    $booking = $b->fetch();

    if (!$booking) {
        json_error('Booking not found.', 404);
    }
    if ($booking['booking_status'] !== 'completed') {
        json_error('You can only review a completed booking.', 400);
    }
    if ($reviewerId !== (int) $booking['requester_id'] && $reviewerId !== (int) $booking['provider_id']) {
        json_error('Only a participant in this booking can review it.', 403);
    }

    $ins = $pdo->prepare('INSERT INTO Reviews (booking_id, reviewer_id, rating, comment)
                          VALUES (:b, :u, :r, :c)');
    $ins->execute([':b' => $bookingId, ':u' => $reviewerId, ':r' => $rating, ':c' => $comment]);

    json_ok(['review_id' => (int) $pdo->lastInsertId()], 201);
} catch (PDOException $e) {
    // 23000 = UNIQUE(booking_id, reviewer_id) violated -> already reviewed.
    if ($e->getCode() === '23000') {
        json_error('You have already reviewed this booking.', 409);
    }
    json_error('Could not save the review.', 500, $e->getMessage());
}
