<?php
/**
 * get_categories.php  —  GET  —  categories with their active-listing counts.
 *
 * DEMONSTRATES: LEFT JOIN + GROUP BY aggregation. Powers the Explore facet list
 * (every category shows, even ones with zero active listings).
 */

require_once __DIR__ . '/db.php';
require_method('GET');

$pdo = Database::pdo();

try {
    $rows = $pdo->query('
        SELECT
            c.category_id,
            c.category_name,
            COUNT(l.listing_id) AS listing_count
        FROM Categories c
        LEFT JOIN Skills   s ON s.category_id = c.category_id
        LEFT JOIN Listings l ON l.skill_id    = s.skill_id AND l.status = "active"
        GROUP BY c.category_id, c.category_name
        ORDER BY c.category_name ASC
    ')->fetchAll();

    $out = array_map(static function (array $r): array {
        return [
            'category_id'   => (int) $r['category_id'],
            'category_name' => $r['category_name'],
            'listing_count' => (int) $r['listing_count'],
        ];
    }, $rows);

    json_ok($out);
} catch (PDOException $e) {
    json_error('Could not load categories.', 500, $e->getMessage());
}
