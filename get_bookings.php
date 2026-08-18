<?php
/**
 * get_bookings.php  —  GET  —  every booking a user is part of (either side).
 *
 * DEMONSTRATES:
 *   - JOINs: Bookings -> Listings -> Skills -> Categories, plus Users twice
 *     (once as requester, once as provider) so we can show both names.
 *   - Filtering with a single bound parameter used via  :uid IN (col1, col2)
 *     so one placeholder covers "I'm the requester OR the provider".
 *
 * Required: ?user_id=1
 * Optional: ?scope=active | history      (active = pending/in_progress)
 *
 * The `role` and `counterparty_name` fields are derived in PHP so the React
 * BookingCard can render the get/give direction directly.
 */

require_once __DIR__ . '/db.php';
require_method('GET');

if (!isset($_GET['user_id']) || $_GET['user_id'] === '') {
    json_error('Missing required query param: user_id', 400);
}
$uid   = (int) $_GET['user_id'];
$scope = $_GET['scope'] ?? null;

$pdo = Database::pdo();

// Scope maps to a fixed, safe SQL fragment (no user text reaches the query).
$scopeSql = '';
if ($scope === 'active') {
    $scopeSql = " AND b.booking_status IN ('pending','in_progress')";
} elseif ($scope === 'history') {
    $scopeSql = " AND b.booking_status IN ('completed','cancelled')";
}

$sql = "
    SELECT
        b.booking_id, b.agreed_hours, b.booking_status, b.created_at,
        b.requester_id, b.provider_id,
        l.listing_id, l.title,
        c.category_name,
        req.full_name  AS requester_name,
        prov.full_name AS provider_name
    FROM Bookings b
    INNER JOIN Listings   l    ON l.listing_id  = b.listing_id
    INNER JOIN Skills     s    ON s.skill_id    = l.skill_id
    INNER JOIN Categories c    ON c.category_id = s.category_id
    INNER JOIN Users      req  ON req.user_id   = b.requester_id
    INNER JOIN Users      prov ON prov.user_id  = b.provider_id
    WHERE :uid IN (b.requester_id, b.provider_id)" . $scopeSql . "
    ORDER BY b.created_at DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $uid]);

    $bookings = array_map(static function (array $b) use ($uid): array {
        $isRequester = ((int) $b['requester_id']) === $uid;
        return [
            'booking_id'        => (int) $b['booking_id'],
            'listing_id'        => (int) $b['listing_id'],
            'title'             => $b['title'],
            'category_name'     => $b['category_name'],
            'agreed_hours'      => (float) $b['agreed_hours'],
            'booking_status'    => $b['booking_status'],
            'created_at'        => $b['created_at'],
            // get (requester, spends) vs give (provider, earns) — matches client/src/lib/flow.js
            'role'              => $isRequester ? 'requester' : 'provider',
            'counterparty_name' => $isRequester ? $b['provider_name'] : $b['requester_name'],
        ];
    }, $stmt->fetchAll());

    json_ok($bookings);
} catch (PDOException $e) {
    json_error('Could not load bookings.', 500, $e->getMessage());
}
