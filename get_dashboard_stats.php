<?php
/**
 * get_dashboard_stats.php  —  GET  —  platform + per-user dashboard numbers.
 *
 * DEMONSTRATES (assignment §3 "Aggregations — GROUP BY & HAVING"):
 *   The "popular categories" query GROUPs listings by category and uses every
 *   requested aggregate — COUNT, SUM, AVG, MIN, MAX — then filters the GROUPS
 *   with HAVING COUNT(...) > :min (categories with more than N active listings).
 *   HAVING (not WHERE) is required because we're filtering on an aggregate.
 *
 * Optional: ?min=1        (HAVING threshold; default 1 -> "more than one listing")
 *           ?user_id=1    (adds that user's wallet + booking snapshot)
 */

require_once __DIR__ . '/db.php';
require_method('GET');

$pdo = Database::pdo();

try {
    /* ---- Platform-wide totals (scalar sub-selects) ---- */
    $totals = $pdo->query('
        SELECT
            (SELECT COUNT(*) FROM Users)                              AS total_users,
            (SELECT COUNT(*) FROM Listings WHERE status = "active")   AS active_listings,
            (SELECT COUNT(*) FROM Bookings)                           AS total_bookings,
            (SELECT COALESCE(SUM(hours_transferred), 0) FROM Transactions) AS hours_transacted,
            (SELECT ROUND(AVG(rating), 2) FROM Reviews)              AS platform_avg_rating
    ')->fetch();

    // Cast to numbers for a clean JSON shape.
    $totals = [
        'total_users'         => (int)   $totals['total_users'],
        'active_listings'     => (int)   $totals['active_listings'],
        'total_bookings'      => (int)   $totals['total_bookings'],
        'hours_transacted'    => (float) $totals['hours_transacted'],
        'platform_avg_rating' => $totals['platform_avg_rating'] === null ? null : (float) $totals['platform_avg_rating'],
    ];

    /* ---- RUBRIC: GROUP BY + HAVING with COUNT/SUM/AVG/MIN/MAX ---- */
    $min  = isset($_GET['min']) ? (int) $_GET['min'] : 1;
    $stmt = $pdo->prepare('
        SELECT
            c.category_id,
            c.category_name,
            COUNT(l.listing_id)              AS listing_count,   -- COUNT
            SUM(l.estimated_hours)           AS total_hours,     -- SUM
            ROUND(AVG(l.estimated_hours), 2) AS avg_hours,       -- AVG
            MIN(l.estimated_hours)           AS min_hours,       -- MIN
            MAX(l.estimated_hours)           AS max_hours        -- MAX
        FROM Categories c
        INNER JOIN Skills   s ON s.category_id = c.category_id
        INNER JOIN Listings l ON l.skill_id    = s.skill_id AND l.status = "active"
        GROUP BY c.category_id, c.category_name
        HAVING COUNT(l.listing_id) > :min                       -- filter on the aggregate
        ORDER BY listing_count DESC, c.category_name ASC
    ');
    $stmt->execute([':min' => $min]);
    $popular = array_map(static function (array $row): array {
        return [
            'category_id'   => (int)   $row['category_id'],
            'category_name' => $row['category_name'],
            'listing_count' => (int)   $row['listing_count'],
            'total_hours'   => (float) $row['total_hours'],
            'avg_hours'     => (float) $row['avg_hours'],
            'min_hours'     => (float) $row['min_hours'],
            'max_hours'     => (float) $row['max_hours'],
        ];
    }, $stmt->fetchAll());

    /* ---- Optional per-user snapshot (wallet + booking counts) ---- */
    $user = null;
    if (isset($_GET['user_id']) && $_GET['user_id'] !== '') {
        $uid = (int) $_GET['user_id'];

        $w = $pdo->prepare('SELECT available_balance, escrow_balance FROM Wallets WHERE user_id = :uid');
        $w->execute([':uid' => $uid]);
        $wallet = $w->fetch();

        if ($wallet) {
            // Active bookings on each side, plus lifetime hours earned/spent.
            $b = $pdo->prepare('
                SELECT
                    SUM(CASE WHEN requester_id = :u1 AND booking_status IN ("pending","in_progress") THEN 1 ELSE 0 END) AS active_as_requester,
                    SUM(CASE WHEN provider_id  = :u2 AND booking_status IN ("pending","in_progress") THEN 1 ELSE 0 END) AS active_as_provider
                FROM Bookings
                WHERE requester_id = :u3 OR provider_id = :u4
            ');
            // Note: named placeholders are not reused (native prepared statements
            // don't allow one marker to appear twice), so we bind :u1.. :u4.
            $b->execute([':u1' => $uid, ':u2' => $uid, ':u3' => $uid, ':u4' => $uid]);
            $counts = $b->fetch();

            $earned = $pdo->prepare('SELECT COALESCE(SUM(hours_transferred),0) FROM Transactions WHERE receiver_id = :u');
            $earned->execute([':u' => $uid]);
            $spent  = $pdo->prepare('SELECT COALESCE(SUM(hours_transferred),0) FROM Transactions WHERE sender_id = :u');
            $spent->execute([':u' => $uid]);

            $user = [
                'user_id'             => $uid,
                'available_balance'   => (float) $wallet['available_balance'],
                'escrow_balance'      => (float) $wallet['escrow_balance'],
                'active_as_requester' => (int) ($counts['active_as_requester'] ?? 0),
                'active_as_provider'  => (int) ($counts['active_as_provider'] ?? 0),
                'hours_earned'        => (float) $earned->fetchColumn(),
                'hours_spent'         => (float) $spent->fetchColumn(),
            ];
        }
    }

    json_ok([
        'totals'             => $totals,
        'popular_categories' => $popular,
        'user'               => $user,
    ]);
} catch (PDOException $e) {
    json_error('Could not load dashboard stats.', 500, $e->getMessage());
}
