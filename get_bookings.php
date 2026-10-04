<?php
/**
 * get_bookings.php  —  GET  —  every booking the signed-in member is part of.
 *
 * DEMONSTRATES:
 *   - JOINs: Bookings -> Listings -> Skills -> Categories, plus Users twice
 *     (once as requester, once as provider) and LEFT JOINs to Transactions and
 *     Reviews (my rating / their rating) — see booking_select_sql().
 *   - Filtering with a single bound parameter used via  :uid IN (col1, col2)
 *     so one placeholder covers "I'm the requester OR the provider".
 *
 * Optional: ?scope=active | history   (active = pending/in_progress/delivered)
 *
 * The member is ALWAYS the session user. Before reading, the lazy auto-release
 * sweep pays out any delivery the requester left unanswered for 72 hours.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_lib.php';
require_method('GET');

$uid   = require_login();
$scope = $_GET['scope'] ?? null;

$pdo = Database::pdo();

// Scope maps to a fixed, safe SQL fragment (no user text reaches the query).
$scopeSql = '';
if ($scope === 'active') {
    $scopeSql = " AND b.booking_status IN ('pending','in_progress','delivered')";
} elseif ($scope === 'history') {
    $scopeSql = " AND b.booking_status IN ('completed','cancelled')";
}

try {
    settle_due_bookings($pdo);

    $stmt = $pdo->prepare(booking_select_sql() . '
        WHERE :uid IN (b.requester_id, b.provider_id)' . $scopeSql . '
        ORDER BY COALESCE(b.completed_at, b.cancelled_at, b.scheduled_at) DESC, b.booking_id DESC');
    $stmt->execute([':viewer1' => $uid, ':viewer2' => $uid, ':uid' => $uid]);

    $bookings = array_map(
        static fn (array $b): array => booking_out($b, $uid),
        $stmt->fetchAll()
    );

    json_ok($bookings);
} catch (PDOException $e) {
    json_error('Could not load bookings.', 500, $e->getMessage());
}
