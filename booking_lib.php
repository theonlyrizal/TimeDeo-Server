<?php
/**
 * booking_lib.php — shared escrow / booking / contact logic.
 *
 * Include AFTER db.php (and auth.php where needed). Business-rule failures are
 * thrown as ApiError so a caller can roll back its transaction and answer with
 * a clean JSON error; low-level failures stay PDOException.
 *
 * Booking lifecycle (two-sided completion):
 *
 *   pending ──provider accepts──▶ in_progress ──provider delivers──▶ delivered
 *   delivered ──requester confirms──▶ completed  (escrow → provider, ledger row)
 *   delivered ──AUTO_RELEASE_HOURS pass with no answer──▶ completed (reason 'auto')
 *   delivered ──requester "not done yet"──▶ in_progress
 *   pending   ──provider declines / requester cancels──▶ cancelled (escrow refunded)
 *   in_progress ──provider cancels──▶ cancelled (escrow refunded)
 *
 * The auto-release guarantees the provider is paid for delivered work even if
 * the requester never responds. It runs lazily (settle_due_bookings) whenever
 * wallets or bookings are read, so no cron job is needed on XAMPP.
 */

declare(strict_types=1);

const AUTO_RELEASE_HOURS = 72;

/** Statuses during which the requester's hours sit in escrow. */
const OPEN_BOOKING_STATUSES = ['pending', 'in_progress', 'delivered'];

final class ApiError extends RuntimeException
{
    public int $status;

    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

/** Roll back if a transaction is open, then answer with the error and stop. */
function fail_tx(PDO $pdo, Throwable $e, string $fallback): void
{
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof ApiError) {
        json_error($e->getMessage(), $e->status);
    }
    json_error($fallback, 500, $e->getMessage());
}

/**
 * Lock both wallets in a fixed order (lowest user_id first). Two transactions
 * touching the same pair of wallets then always queue instead of deadlocking.
 */
function lock_wallets(PDO $pdo, int $a, int $b): array
{
    $ids = [$a, $b];
    sort($ids);
    $out = [];
    $q = $pdo->prepare('SELECT user_id, available_balance, escrow_balance
                          FROM Wallets WHERE user_id = :uid FOR UPDATE');
    foreach (array_unique($ids) as $id) {
        $q->execute([':uid' => $id]);
        $row = $q->fetch();
        if (!$row) {
            throw new ApiError('Wallet not found.', 404);
        }
        $out[$id] = $row;
    }
    return $out;
}

/** Load + lock one booking row (inside an open transaction). */
function lock_booking(PDO $pdo, int $bookingId): array
{
    $b = $pdo->prepare('SELECT booking_id, listing_id, requester_id, provider_id, agreed_hours,
                               booking_status, delivered_at
                          FROM Bookings WHERE booking_id = :id FOR UPDATE');
    $b->execute([':id' => $bookingId]);
    $row = $b->fetch();
    if (!$row) {
        throw new ApiError('Booking not found.', 404);
    }
    return $row;
}

/**
 * THE ESCROW RELEASE (call inside an open transaction, booking already locked):
 *   1. deduct the agreed hours from the requester's escrow
 *   2. credit the provider's available balance
 *   3. mark the booking completed
 *   4. write the immutable Transactions ledger row (UNIQUE booking_id = no double pay)
 */
function release_escrow_locked(PDO $pdo, array $booking, string $reason): array
{
    $bookingId   = (int) $booking['booking_id'];
    $requesterId = (int) $booking['requester_id'];
    $providerId  = (int) $booking['provider_id'];
    $hours       = (float) $booking['agreed_hours'];

    $wallets = lock_wallets($pdo, $requesterId, $providerId);
    if ((float) $wallets[$requesterId]['escrow_balance'] < $hours) {
        throw new ApiError('The requester does not have enough hours in escrow.', 409);
    }

    $pdo->prepare('UPDATE Wallets SET escrow_balance = escrow_balance - :h WHERE user_id = :uid')
        ->execute([':h' => $hours, ':uid' => $requesterId]);

    $pdo->prepare('UPDATE Wallets SET available_balance = available_balance + :h WHERE user_id = :uid')
        ->execute([':h' => $hours, ':uid' => $providerId]);

    $pdo->prepare("UPDATE Bookings
                      SET booking_status = 'completed', completed_at = UTC_TIMESTAMP(),
                          delivered_at = COALESCE(delivered_at, UTC_TIMESTAMP())
                    WHERE booking_id = :id")
        ->execute([':id' => $bookingId]);

    $pdo->prepare('INSERT INTO Transactions (booking_id, sender_id, receiver_id, hours_transferred, release_reason)
                   VALUES (:b, :s, :r, :h, :why)')
        ->execute([':b' => $bookingId, ':s' => $requesterId, ':r' => $providerId, ':h' => $hours, ':why' => $reason]);

    return [
        'booking_id'     => $bookingId,
        'status'         => 'completed',
        'transaction_id' => (int) $pdo->lastInsertId(),
        'hours_released' => $hours,
        'from_requester' => $requesterId,
        'to_provider'    => $providerId,
        'release_reason' => $reason,
    ];
}

/** Return a booking's escrowed hours to the requester (inside an open transaction). */
function refund_escrow_locked(PDO $pdo, array $booking): void
{
    $requesterId = (int) $booking['requester_id'];
    $hours       = (float) $booking['agreed_hours'];

    $wallets = lock_wallets($pdo, $requesterId, $requesterId);
    if ((float) $wallets[$requesterId]['escrow_balance'] < $hours) {
        throw new ApiError('Escrow is out of balance for this booking.', 409);
    }
    $pdo->prepare('UPDATE Wallets
                      SET escrow_balance    = escrow_balance    - :h1,
                          available_balance = available_balance + :h2
                    WHERE user_id = :uid')
        ->execute([':h1' => $hours, ':h2' => $hours, ':uid' => $requesterId]);
}

/**
 * Lazy auto-release sweep: pays out every 'delivered' booking whose requester
 * has not answered within AUTO_RELEASE_HOURS. Each booking settles in its own
 * transaction and re-checks its status under a row lock, so concurrent sweeps
 * are safe. Failures are skipped (the next sweep retries). Returns the count.
 */
function settle_due_bookings(PDO $pdo): int
{
    $due = $pdo->prepare("SELECT booking_id FROM Bookings
                           WHERE booking_status = 'delivered'
                             AND delivered_at <= UTC_TIMESTAMP() - INTERVAL :hrs HOUR");
    $due->execute([':hrs' => AUTO_RELEASE_HOURS]);
    $settled = 0;

    foreach ($due->fetchAll(PDO::FETCH_COLUMN) as $id) {
        try {
            $pdo->beginTransaction();
            $booking = lock_booking($pdo, (int) $id);
            if ($booking['booking_status'] !== 'delivered') {
                $pdo->rollBack();          // someone else settled it first
                continue;
            }
            release_escrow_locked($pdo, $booking, 'auto');
            $pdo->commit();
            $settled++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }
    return $settled;
}

/* ---------------------------------------------------------------------------
 * Contacts
 * ------------------------------------------------------------------------- */

const CONTACT_METHODS = ['phone', 'whatsapp', 'telegram', 'email', 'facebook'];

/**
 * Validate + normalize one contact value for its method. Returns the cleaned
 * value or sends a 400 and stops.
 */
function normalize_contact(string $method, $value): string
{
    if (!in_array($method, CONTACT_METHODS, true)) {
        json_error('Unknown contact method.', 400);
    }
    $v = trim((string) $value);
    if ($v === '' || mb_strlen($v) > 160) {
        json_error('Enter a contact value (max 160 characters).', 400);
    }
    if ($method === 'phone' || $method === 'whatsapp') {
        $digits = preg_replace('/[\s\-()]/', '', $v);
        if (!preg_match('/^\+?\d{8,15}$/', $digits)) {
            json_error('Enter a valid phone number, e.g. +8801XXXXXXXXX.', 400);
        }
        return $digits;
    }
    if ($method === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
        json_error('Enter a valid contact email.', 400);
    }
    if ($method === 'telegram' && !preg_match('/^@?[A-Za-z0-9_]{5,32}$/', $v)) {
        json_error('Enter a Telegram username, e.g. @yourname.', 400);
    }
    return $v;
}

function fetch_contacts(PDO $pdo, int $userId): array
{
    $q = $pdo->prepare('SELECT method, value FROM User_Contacts WHERE user_id = :uid
                         ORDER BY FIELD(method, "phone", "whatsapp", "telegram", "email", "facebook")');
    $q->execute([':uid' => $userId]);
    return $q->fetchAll();
}

/**
 * May $viewer see $target's contact details? Only when they have shown intent
 * to trade with each other: a booking between them, a help offer between them,
 * or a contact reveal in either direction (reveals are mutual).
 */
function can_view_contacts(PDO $pdo, int $viewer, int $target): bool
{
    if ($viewer === $target) {
        return true;
    }
    $q = $pdo->prepare('
        SELECT
          EXISTS(SELECT 1 FROM Bookings
                  WHERE (requester_id = :a1 AND provider_id = :b1)
                     OR (requester_id = :b2 AND provider_id = :a2))
          OR EXISTS(SELECT 1 FROM Help_Offers h
                      JOIN Listings l ON l.listing_id = h.listing_id
                     WHERE (h.helper_id = :a3 AND l.user_id = :b3)
                        OR (h.helper_id = :b4 AND l.user_id = :a4))
          OR EXISTS(SELECT 1 FROM Contact_Reveals
                     WHERE (viewer_id = :a5 AND target_id = :b5)
                        OR (viewer_id = :b6 AND target_id = :a6))');
    $q->execute([
        ':a1' => $viewer, ':b1' => $target, ':a2' => $viewer, ':b2' => $target,
        ':a3' => $viewer, ':b3' => $target, ':a4' => $viewer, ':b4' => $target,
        ':a5' => $viewer, ':b5' => $target, ':a6' => $viewer, ':b6' => $target,
    ]);
    return (bool) $q->fetchColumn();
}

/* ---------------------------------------------------------------------------
 * Ratings
 * ------------------------------------------------------------------------- */

/**
 * A member's ratings split by role: as a provider (helping others) and as a
 * requester (getting help). Uses the Reviews.reviewee_id index.
 */
function rating_summary(PDO $pdo, int $userId): array
{
    $q = $pdo->prepare('
        SELECT
          SUM(CASE WHEN b.provider_id  = r.reviewee_id THEN 1 ELSE 0 END)            AS provider_count,
          ROUND(AVG(CASE WHEN b.provider_id  = r.reviewee_id THEN r.rating END), 2)   AS provider_avg,
          SUM(CASE WHEN b.requester_id = r.reviewee_id THEN 1 ELSE 0 END)            AS requester_count,
          ROUND(AVG(CASE WHEN b.requester_id = r.reviewee_id THEN r.rating END), 2)   AS requester_avg
        FROM Reviews r
        INNER JOIN Bookings b ON b.booking_id = r.booking_id
        WHERE r.reviewee_id = :uid');
    $q->execute([':uid' => $userId]);
    $r = $q->fetch() ?: [];
    return [
        'as_provider'  => [
            'count' => (int) ($r['provider_count'] ?? 0),
            'avg'   => isset($r['provider_avg']) ? (float) $r['provider_avg'] : null,
        ],
        'as_requester' => [
            'count' => (int) ($r['requester_count'] ?? 0),
            'avg'   => isset($r['requester_avg']) ? (float) $r['requester_avg'] : null,
        ],
    ];
}

/* ---------------------------------------------------------------------------
 * Booking read model (shared by get_bookings.php and the action endpoints)
 * ------------------------------------------------------------------------- */

/** SELECT list + JOINs for a booking as seen by :viewer (bound by the caller). */
function booking_select_sql(): string
{
    return "
        SELECT
            b.booking_id, b.listing_id, b.requester_id, b.provider_id, b.agreed_hours,
            b.scheduled_at, b.note, b.booking_status, b.status_note, b.created_at,
            b.accepted_at, b.delivered_at, b.completed_at, b.cancelled_at, b.cancelled_by,
            l.title, l.listing_type, l.delivery_mode,
            c.category_name,
            req.full_name  AS requester_name,
            prov.full_name AS provider_name,
            t.release_reason,
            mine.rating    AS my_rating,
            theirs.rating  AS their_rating
        FROM Bookings b
        INNER JOIN Listings   l    ON l.listing_id  = b.listing_id
        INNER JOIN Skills     s    ON s.skill_id    = l.skill_id
        INNER JOIN Categories c    ON c.category_id = s.category_id
        INNER JOIN Users      req  ON req.user_id   = b.requester_id
        INNER JOIN Users      prov ON prov.user_id  = b.provider_id
        LEFT  JOIN Transactions t  ON t.booking_id  = b.booking_id
        LEFT  JOIN Reviews mine    ON mine.booking_id   = b.booking_id AND mine.reviewer_id   = :viewer1
        LEFT  JOIN Reviews theirs  ON theirs.booking_id = b.booking_id AND theirs.reviewee_id = :viewer2";
}

/** Shape one booking row for the viewer (role, counterparty, ISO dates). */
function booking_out(array $b, int $viewer): array
{
    $isRequester = ((int) $b['requester_id']) === $viewer;
    $autoAt = null;
    if ($b['booking_status'] === 'delivered' && $b['delivered_at']) {
        $autoAt = (new DateTimeImmutable($b['delivered_at'], new DateTimeZone('UTC')))
            ->modify('+' . AUTO_RELEASE_HOURS . ' hours')
            ->format('Y-m-d H:i:s');
    }
    return [
        'booking_id'        => (int) $b['booking_id'],
        'listing_id'        => (int) $b['listing_id'],
        'listing_type'      => $b['listing_type'],
        'title'             => $b['title'],
        'category_name'     => $b['category_name'],
        'delivery_mode'     => $b['delivery_mode'],
        'agreed_hours'      => (float) $b['agreed_hours'],
        'booking_status'    => $b['booking_status'],
        'status_note'       => $b['status_note'],
        'note'              => $b['note'],
        'scheduled_at'      => iso_dt($b['scheduled_at']),
        'created_at'        => iso_dt($b['created_at']),
        'accepted_at'       => iso_dt($b['accepted_at']),
        'delivered_at'      => iso_dt($b['delivered_at']),
        'completed_at'      => iso_dt($b['completed_at']),
        'cancelled_at'      => iso_dt($b['cancelled_at']),
        'cancelled_by_me'   => $b['cancelled_by'] !== null && (int) $b['cancelled_by'] === $viewer,
        'auto_release_at'   => iso_dt($autoAt),
        'release_reason'    => $b['release_reason'],
        // get (requester, spends) vs give (provider, earns) — matches client/src/lib/flow.js
        'role'              => $isRequester ? 'requester' : 'provider',
        'counterparty_id'   => (int) ($isRequester ? $b['provider_id'] : $b['requester_id']),
        'counterparty_name' => $isRequester ? $b['provider_name'] : $b['requester_name'],
        'my_rating'         => $b['my_rating'] === null ? null : (int) $b['my_rating'],
        'their_rating'      => $b['their_rating'] === null ? null : (int) $b['their_rating'],
    ];
}

/** Re-read one booking for the viewer after a change. */
function fetch_booking_for(PDO $pdo, int $bookingId, int $viewer): ?array
{
    $q = $pdo->prepare(booking_select_sql() . ' WHERE b.booking_id = :id');
    $q->execute([':viewer1' => $viewer, ':viewer2' => $viewer, ':id' => $bookingId]);
    $row = $q->fetch();
    return $row ? booking_out($row, $viewer) : null;
}
