<?php
/**
 * get_marketplace.php  —  GET  —  the Explore/marketplace grid.
 *
 * DEMONSTRATES (assignment §3 "Joins"):
 *   - Multiple JOIN types in one statement:
 *       INNER JOIN Listings -> Users, Skills, Categories  (a listing MUST have these)
 *       LEFT  JOIN Bookings -> Reviews                    (a listing MAY have ratings)
 *   - Aggregation over the LEFT-joined side (COUNT/AVG of reviews per listing).
 *
 * Optional query params (all bound as parameters — never string-concatenated):
 *   ?category_id=1   ?type=Offer   ?q=react
 */

require_once __DIR__ . '/db.php';
require_method('GET');

$pdo = Database::pdo();

// Build the WHERE list from placeholders only; user values go into $params.
$where  = ["l.status = 'active'"];
$params = [];

if (isset($_GET['category_id']) && $_GET['category_id'] !== '') {
    $where[] = 'c.category_id = :category_id';
    $params[':category_id'] = (int) $_GET['category_id'];
}
if (isset($_GET['type']) && $_GET['type'] !== '') {
    $where[] = 'l.listing_type = :type';
    $params[':type'] = $_GET['type'];
}
if (isset($_GET['q']) && $_GET['q'] !== '') {
    // Two placeholders bound to the same value: native prepared statements do
    // not allow one named marker (:q) to appear twice in the SQL.
    $where[] = '(l.title LIKE :q_title OR l.description LIKE :q_descr)';
    $params[':q_title'] = '%' . $_GET['q'] . '%';
    $params[':q_descr'] = '%' . $_GET['q'] . '%';
}

$sql = '
    SELECT
        l.listing_id, l.title, l.description, l.listing_type, l.estimated_hours,
        l.status, l.created_at,
        u.user_id  AS provider_id,
        u.full_name AS provider_name,
        s.skill_name,
        c.category_id, c.category_name,
        COUNT(DISTINCT r.review_id) AS review_count,
        ROUND(AVG(r.rating), 2)     AS avg_rating
    FROM Listings   l
    INNER JOIN Users      u ON u.user_id     = l.user_id       -- RUBRIC: INNER JOIN
    INNER JOIN Skills     s ON s.skill_id    = l.skill_id      -- RUBRIC: INNER JOIN
    INNER JOIN Categories c ON c.category_id = s.category_id   -- RUBRIC: INNER JOIN
    LEFT  JOIN Bookings   b ON b.listing_id  = l.listing_id    -- RUBRIC: LEFT JOIN
    LEFT  JOIN Reviews    r ON r.booking_id  = b.booking_id    -- RUBRIC: LEFT JOIN
    WHERE ' . implode(' AND ', $where) . '
    GROUP BY l.listing_id, l.title, l.description, l.listing_type, l.estimated_hours,
             l.status, l.created_at, u.user_id, u.full_name, s.skill_name,
             c.category_id, c.category_name
    ORDER BY l.created_at DESC';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_ok($stmt->fetchAll());
} catch (PDOException $e) {
    json_error('Could not load the marketplace.', 500, $e->getMessage());
}
