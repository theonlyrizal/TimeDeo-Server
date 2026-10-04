<?php
/**
 * booking_action.php  —  POST  —  move a booking through its two-sided lifecycle.
 *
 * Body: { "booking_id": 12, "action": "accept" | "decline" | "cancel" | "deliver" | "reject",
 *         "reason"?: "..." }
 *
 *   accept   provider   pending      -> in_progress
 *   decline  provider   pending      -> cancelled   (escrow refunded to requester)
 *   cancel   requester  pending      -> cancelled   (escrow refunded)
 *            provider   in_progress  -> cancelled   (escrow refunded — provider backs out)
 *   deliver  provider   in_progress  -> delivered   (starts the auto-release clock)
 *   reject   requester  delivered    -> in_progress ("not done yet"; reason required)
 *
 * Confirming a delivery (escrow release) lives in complete_booking.php.
 * Every action runs in one transaction with the booking row locked, so two
 * people clicking at once can never both win.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_lib.php';
require_method('POST');

$uid = require_login();

$in = read_json_body();
require_fields($in, ['booking_id', 'action']);
$bookingId = (int) $in['booking_id'];
$action    = (string) $in['action'];
$reason    = clean_text($in['reason'] ?? null, 280, 'Reason');

$allowed = ['accept', 'decline', 'cancel', 'deliver', 'reject'];
if (!in_array($action, $allowed, true)) {
    json_error('Unknown action.', 400);
}

$pdo = Database::pdo();

try {
    $pdo->beginTransaction();
    $booking = lock_booking($pdo, $bookingId);

    $isRequester = (int) $booking['requester_id'] === $uid;
    $isProvider  = (int) $booking['provider_id'] === $uid;
    if (!$isRequester && !$isProvider) {
        throw new ApiError('You are not part of this booking.', 403);
    }
    $status = $booking['booking_status'];

    switch ($action) {
        case 'accept':
            if (!$isProvider) {
                throw new ApiError('Only the provider can accept a booking.', 403);
            }
            if ($status !== 'pending') {
                throw new ApiError('Only a pending booking can be accepted.', 409);
            }
            $pdo->prepare("UPDATE Bookings SET booking_status = 'in_progress', accepted_at = UTC_TIMESTAMP(),
                                  status_note = NULL
                            WHERE booking_id = :id")
                ->execute([':id' => $bookingId]);
            break;

        case 'decline':
        case 'cancel':
            $providerMayCancel  = $isProvider && in_array($status, ['pending', 'in_progress'], true);
            $requesterMayCancel = $isRequester && $status === 'pending' && $action === 'cancel';
            if ($action === 'decline' && !($isProvider && $status === 'pending')) {
                throw new ApiError('Only the provider can decline a pending booking.', 403);
            }
            if ($action === 'cancel' && !$providerMayCancel && !$requesterMayCancel) {
                throw new ApiError(
                    $isRequester
                        ? 'Once the provider has accepted, the booking can only be cancelled by them.'
                        : 'This booking can no longer be cancelled.',
                    409
                );
            }
            // Refund the escrowed hours, then close the booking.
            refund_escrow_locked($pdo, $booking);
            $pdo->prepare("UPDATE Bookings SET booking_status = 'cancelled', cancelled_at = UTC_TIMESTAMP(),
                                  cancelled_by = :by, status_note = :why
                            WHERE booking_id = :id")
                ->execute([':by' => $uid, ':why' => $reason, ':id' => $bookingId]);
            // A cancelled help-request booking re-opens the request for other helpers
            // (the accepted offer is retired so that helper could offer again later).
            $reopen = $pdo->prepare("UPDATE Listings SET status = 'active'
                                      WHERE listing_id = :lid AND listing_type = 'Request' AND status = 'filled'");
            $reopen->execute([':lid' => (int) $booking['listing_id']]);
            if ($reopen->rowCount() > 0) {
                $pdo->prepare("UPDATE Help_Offers SET status = 'withdrawn'
                                WHERE listing_id = :lid AND status = 'accepted'")
                    ->execute([':lid' => (int) $booking['listing_id']]);
            }
            break;

        case 'deliver':
            if (!$isProvider) {
                throw new ApiError('Only the provider can mark the work as delivered.', 403);
            }
            if ($status !== 'in_progress') {
                throw new ApiError('Only an accepted booking can be marked delivered.', 409);
            }
            $pdo->prepare("UPDATE Bookings SET booking_status = 'delivered', delivered_at = UTC_TIMESTAMP(),
                                  status_note = NULL
                            WHERE booking_id = :id")
                ->execute([':id' => $bookingId]);
            break;

        case 'reject':
            if (!$isRequester) {
                throw new ApiError('Only the person who received the help can send it back.', 403);
            }
            if ($status !== 'delivered') {
                throw new ApiError('Only a delivered booking can be sent back.', 409);
            }
            if ($reason === null) {
                throw new ApiError('Tell them what is still missing.', 400);
            }
            $pdo->prepare("UPDATE Bookings SET booking_status = 'in_progress', delivered_at = NULL,
                                  status_note = :why
                            WHERE booking_id = :id")
                ->execute([':why' => $reason, ':id' => $bookingId]);
            break;
    }

    $pdo->commit();
    json_ok(fetch_booking_for($pdo, $bookingId, $uid));
} catch (Throwable $e) {
    fail_tx($pdo, $e, 'Could not update the booking; nothing was changed.');
}
