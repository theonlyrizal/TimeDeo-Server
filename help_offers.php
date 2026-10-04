<?php
/**
 * help_offers.php  —  skilled members respond to "Ask for help" requests.
 *
 *   GET  help_offers.php?listing_id=13   -> the request's offers
 *                                           (author: every offer; others: only their own)
 *   GET  help_offers.php?mine=1          -> offers I have made, with their requests
 *   POST help_offers.php                 -> make an offer
 *        body: { listing_id, proposed_at: ISO, message? }
 *   POST help_offers.php                 -> act on an offer
 *        body: { offer_id, action: 'accept' | 'decline' | 'withdraw' }
 *
 * ACCEPT is a TRANSACTION (BEGIN / COMMIT / ROLLBACK): the request's author
 * picks a helper, which atomically
 *   1. locks the author's hours in escrow,
 *   2. creates the booking (author = requester, helper = provider) already
 *      'in_progress' at the helper's proposed time,
 *   3. marks this offer accepted and every other pending offer declined,
 *   4. marks the request 'filled' so it leaves the Help wanted board.
 * From there it follows the normal two-sided completion flow.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_lib.php';

$uid    = require_login();
$pdo    = Database::pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function offer_out(array $r): array
{
    $out = [
        'offer_id'     => (int) $r['offer_id'],
        'listing_id'   => (int) $r['listing_id'],
        'helper_id'    => (int) $r['helper_id'],
        'helper_name'  => $r['helper_name'],
        'message'      => $r['message'],
        'proposed_at'  => iso_dt($r['proposed_at']),
        'status'       => $r['status'],
        'created_at'   => iso_dt($r['created_at']),
    ];
    foreach (['helper_headline', 'listing_title', 'listing_status', 'estimated_hours', 'author_id', 'author_name'] as $k) {
        if (array_key_exists($k, $r)) {
            $out[$k] = $k === 'estimated_hours' ? (float) $r[$k] : ($k === 'author_id' ? (int) $r[$k] : $r[$k]);
        }
    }
    if (array_key_exists('helper_rating', $r)) {
        $out['helper_rating']       = $r['helper_rating'] === null ? null : (float) $r['helper_rating'];
        $out['helper_review_count'] = (int) $r['helper_review_count'];
        $out['skill_match']         = (bool) $r['skill_match'];
    }
    return $out;
}

if ($method === 'GET') {
    try {
        if (($_GET['mine'] ?? '') === '1') {
            $q = $pdo->prepare('
                SELECT h.*, me.full_name AS helper_name,
                       l.title AS listing_title, l.status AS listing_status, l.estimated_hours,
                       a.user_id AS author_id, a.full_name AS author_name
                  FROM Help_Offers h
                  INNER JOIN Listings l  ON l.listing_id = h.listing_id
                  INNER JOIN Users    a  ON a.user_id    = l.user_id
                  INNER JOIN Users    me ON me.user_id   = h.helper_id
                 WHERE h.helper_id = :uid
                 ORDER BY h.created_at DESC');
            $q->execute([':uid' => $uid]);
            json_ok(array_map('offer_out', $q->fetchAll()));
        }

        $listingId = (int) ($_GET['listing_id'] ?? 0);
        if ($listingId <= 0) {
            json_error('Missing listing_id.', 400);
        }
        $l = $pdo->prepare('SELECT user_id, skill_id FROM Listings WHERE listing_id = :id');
        $l->execute([':id' => $listingId]);
        $listing = $l->fetch();
        if (!$listing) {
            json_error('Request not found.', 404);
        }
        $isAuthor = (int) $listing['user_id'] === $uid;

        // Correlated subqueries: each helper's rating as a provider + whether
        // they list the requested skill (helps the author choose).
        $sql = '
            SELECT h.*, u.full_name AS helper_name, u.headline AS helper_headline,
                   (SELECT ROUND(AVG(r.rating), 2) FROM Reviews r
                      INNER JOIN Bookings b ON b.booking_id = r.booking_id
                     WHERE r.reviewee_id = h.helper_id AND b.provider_id = h.helper_id) AS helper_rating,
                   (SELECT COUNT(*) FROM Reviews r
                      INNER JOIN Bookings b ON b.booking_id = r.booking_id
                     WHERE r.reviewee_id = h.helper_id AND b.provider_id = h.helper_id) AS helper_review_count,
                   EXISTS (SELECT 1 FROM User_Skills us
                            WHERE us.user_id = h.helper_id AND us.skill_id = :skill) AS skill_match
              FROM Help_Offers h
              INNER JOIN Users u ON u.user_id = h.helper_id
             WHERE h.listing_id = :lid';
        $params = [':skill' => (int) $listing['skill_id'], ':lid' => $listingId];
        if (!$isAuthor) {
            $sql .= ' AND h.helper_id = :uid';
            $params[':uid'] = $uid;
        }
        $sql .= " ORDER BY FIELD(h.status, 'accepted', 'pending', 'declined', 'withdrawn'), h.created_at";

        $q = $pdo->prepare($sql);
        $q->execute($params);
        json_ok(array_map('offer_out', $q->fetchAll()));
    } catch (PDOException $e) {
        json_error('Could not load offers.', 500, $e->getMessage());
    }
}

if ($method !== 'POST') {
    json_error('Method not allowed.', 405);
}

$in = read_json_body();

/* ----------------------------------------------------------------- make an offer */
if (!isset($in['action'])) {
    require_fields($in, ['listing_id', 'proposed_at']);
    $listingId  = (int) $in['listing_id'];
    $message    = clean_text($in['message'] ?? null, 1000, 'Message');
    $proposedAt = parse_client_dt($in['proposed_at']);
    if ($proposedAt === null || $proposedAt <= gmdate('Y-m-d H:i:s')) {
        json_error('Propose a future date and time.', 400);
    }
    if ($proposedAt > gmdate('Y-m-d H:i:s', strtotime('+90 days'))) {
        json_error('Propose a time within the next 90 days.', 400);
    }

    try {
        $pdo->beginTransaction();
        $l = $pdo->prepare('SELECT user_id, listing_type, status FROM Listings WHERE listing_id = :id FOR UPDATE');
        $l->execute([':id' => $listingId]);
        $listing = $l->fetch();
        if (!$listing || $listing['listing_type'] !== 'Request') {
            throw new ApiError('Request not found.', 404);
        }
        if ($listing['status'] !== 'active') {
            throw new ApiError('This request is no longer open.', 409);
        }
        if ((int) $listing['user_id'] === $uid) {
            throw new ApiError('You cannot offer to help with your own request.', 400);
        }

        // One offer per helper per request; only a withdrawn one can be renewed
        // (a declined helper should not be able to keep re-asking).
        $ex = $pdo->prepare('SELECT offer_id, status FROM Help_Offers
                              WHERE listing_id = :l AND helper_id = :u FOR UPDATE');
        $ex->execute([':l' => $listingId, ':u' => $uid]);
        $existing = $ex->fetch();
        if ($existing && $existing['status'] === 'pending') {
            throw new ApiError('You have already offered to help with this request.', 409);
        }
        if ($existing && $existing['status'] === 'accepted') {
            throw new ApiError('Your offer was already accepted.', 409);
        }
        if ($existing && $existing['status'] === 'declined') {
            throw new ApiError('They chose not to take up your offer on this request.', 409);
        }
        if ($existing) {
            $pdo->prepare("UPDATE Help_Offers SET status = 'pending', message = :m, proposed_at = :p,
                                  created_at = UTC_TIMESTAMP()
                            WHERE offer_id = :id")
                ->execute([':m' => $message, ':p' => $proposedAt, ':id' => (int) $existing['offer_id']]);
            $offerId = (int) $existing['offer_id'];
        } else {
            $pdo->prepare('INSERT INTO Help_Offers (listing_id, helper_id, message, proposed_at)
                           VALUES (:l, :u, :m, :p)')
                ->execute([':l' => $listingId, ':u' => $uid, ':m' => $message, ':p' => $proposedAt]);
            $offerId = (int) $pdo->lastInsertId();
        }
        $pdo->commit();
        json_ok(['offer_id' => $offerId, 'status' => 'pending'], 201);
    } catch (Throwable $e) {
        fail_tx($pdo, $e, 'Could not send your offer.');
    }
}

/* ----------------------------------------------------------- act on an offer */
require_fields($in, ['offer_id', 'action']);
$offerId = (int) $in['offer_id'];
$action  = (string) $in['action'];
if (!in_array($action, ['accept', 'decline', 'withdraw'], true)) {
    json_error('Unknown action.', 400);
}

try {
    $pdo->beginTransaction();

    $o = $pdo->prepare('SELECT h.offer_id, h.listing_id, h.helper_id, h.proposed_at, h.status,
                               l.user_id AS author_id, l.estimated_hours, l.status AS listing_status
                          FROM Help_Offers h
                          INNER JOIN Listings l ON l.listing_id = h.listing_id
                         WHERE h.offer_id = :id
                           FOR UPDATE');
    $o->execute([':id' => $offerId]);
    $offer = $o->fetch();
    if (!$offer) {
        throw new ApiError('Offer not found.', 404);
    }
    if ($offer['status'] !== 'pending') {
        throw new ApiError('This offer has already been ' . $offer['status'] . '.', 409);
    }
    $authorId = (int) $offer['author_id'];
    $helperId = (int) $offer['helper_id'];

    if ($action === 'withdraw') {
        if ($helperId !== $uid) {
            throw new ApiError('Only the helper can withdraw this offer.', 403);
        }
        $pdo->prepare("UPDATE Help_Offers SET status = 'withdrawn' WHERE offer_id = :id")
            ->execute([':id' => $offerId]);
        $pdo->commit();
        json_ok(['offer_id' => $offerId, 'status' => 'withdrawn']);
    }

    if ($authorId !== $uid) {
        throw new ApiError('Only the person who asked for help can do that.', 403);
    }

    if ($action === 'decline') {
        $pdo->prepare("UPDATE Help_Offers SET status = 'declined' WHERE offer_id = :id")
            ->execute([':id' => $offerId]);
        $pdo->commit();
        json_ok(['offer_id' => $offerId, 'status' => 'declined']);
    }

    // ===== ACCEPT =====
    if ($offer['listing_status'] !== 'active') {
        throw new ApiError('This request already has a helper.', 409);
    }
    if ($offer['proposed_at'] <= gmdate('Y-m-d H:i:s')) {
        throw new ApiError('The proposed time has passed — ask the helper to propose a new one.', 409);
    }
    $hours = (float) $offer['estimated_hours'];

    // 1. Lock the author's hours in escrow.
    $wallets = lock_wallets($pdo, $authorId, $authorId);
    if ((float) $wallets[$authorId]['available_balance'] < $hours) {
        throw new ApiError('Insufficient balance to accept this offer.', 400);
    }
    $pdo->prepare('UPDATE Wallets
                      SET available_balance = available_balance - :h1,
                          escrow_balance    = escrow_balance    + :h2
                    WHERE user_id = :uid')
        ->execute([':h1' => $hours, ':h2' => $hours, ':uid' => $authorId]);

    // 2. The booking — the helper already committed, so it starts in progress.
    $pdo->prepare("INSERT INTO Bookings (listing_id, requester_id, provider_id, agreed_hours, scheduled_at,
                                         booking_status, accepted_at)
                   VALUES (:l, :r, :p, :h, :at, 'in_progress', UTC_TIMESTAMP())")
        ->execute([
            ':l' => (int) $offer['listing_id'], ':r' => $authorId, ':p' => $helperId,
            ':h' => $hours, ':at' => $offer['proposed_at'],
        ]);
    $bookingId = (int) $pdo->lastInsertId();

    // 3. This offer wins; every other pending offer is declined.
    $pdo->prepare("UPDATE Help_Offers SET status = 'accepted' WHERE offer_id = :id")
        ->execute([':id' => $offerId]);
    $pdo->prepare("UPDATE Help_Offers SET status = 'declined'
                    WHERE listing_id = :l AND offer_id <> :id AND status = 'pending'")
        ->execute([':l' => (int) $offer['listing_id'], ':id' => $offerId]);

    // 4. The request leaves the board.
    $pdo->prepare("UPDATE Listings SET status = 'filled' WHERE listing_id = :l")
        ->execute([':l' => (int) $offer['listing_id']]);

    $pdo->commit();
    json_ok([
        'offer_id'     => $offerId,
        'status'       => 'accepted',
        'booking_id'   => $bookingId,
        'hours_locked' => $hours,
    ]);
} catch (Throwable $e) {
    fail_tx($pdo, $e, 'Could not update the offer; nothing was changed.');
}
