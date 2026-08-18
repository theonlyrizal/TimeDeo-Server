<?php
/**
 * get_top_providers.php  —  GET  —  providers rated above the platform average.
 *
 * DEMONSTRATES (assignment §3 "Subquery"):
 *   - CORRELATED subquery: the inner AVG(rating) in the WHERE clause references
 *     the OUTER row (u.user_id), so it is recomputed per user — that user's own
 *     average rating as a provider.
 *   - NESTED (non-correlated) subquery: (SELECT AVG(rating) FROM Reviews) yields
 *     the single platform-wide average to compare each provider against.
 *
 * Returns each qualifying provider with their review count and average rating.
 */

require_once __DIR__ . '/db.php';
require_method('GET');

$pdo = Database::pdo();

$sql = '
    SELECT
        u.user_id,
        u.full_name,
        -- correlated scalar subqueries (depend on the outer u.user_id):
        (SELECT COUNT(*)
           FROM Reviews r
           JOIN Bookings b ON b.booking_id = r.booking_id
          WHERE b.provider_id = u.user_id)              AS review_count,
        (SELECT ROUND(AVG(r.rating), 2)
           FROM Reviews r
           JOIN Bookings b ON b.booking_id = r.booking_id
          WHERE b.provider_id = u.user_id)              AS avg_rating
    FROM Users u
    WHERE (
            SELECT AVG(r.rating)                         -- CORRELATED subquery
              FROM Reviews r
              JOIN Bookings b ON b.booking_id = r.booking_id
             WHERE b.provider_id = u.user_id
          ) > (
            SELECT AVG(rating) FROM Reviews              -- NESTED subquery (platform avg)
          )
    ORDER BY avg_rating DESC, review_count DESC';

try {
    // No user input in this query, so query() is safe here; all other endpoints
    // with parameters use prepare()/execute().
    $rows = $pdo->query($sql)->fetchAll();

    $providers = array_map(static function (array $r): array {
        return [
            'user_id'      => (int)   $r['user_id'],
            'full_name'    => $r['full_name'],
            'review_count' => (int)   $r['review_count'],
            'avg_rating'   => (float) $r['avg_rating'],
        ];
    }, $rows);

    json_ok($providers);
} catch (PDOException $e) {
    json_error('Could not load top providers.', 500, $e->getMessage());
}
