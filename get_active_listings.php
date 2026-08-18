<?php
/**
 * get_active_listings.php  —  GET  —  storefront read via the VIEW.
 *
 * DEMONSTRATES (assignment §3 "View"):
 *   This endpoint queries the reusable VIEW `vw_active_marketplace_listings`
 *   (defined in init.sql) INSTEAD of re-writing the 4-table join. The view
 *   already encapsulates Listings -> Users/Skills/Categories + rating rollups
 *   and filters to status='active', so the PHP here stays trivially simple.
 *
 * Optional: ?category=Design   (filter on the view's category_name column)
 */

require_once __DIR__ . '/db.php';
require_method('GET');

$pdo = Database::pdo();

$sql    = 'SELECT * FROM vw_active_marketplace_listings';
$params = [];

if (isset($_GET['category']) && $_GET['category'] !== '') {
    $sql .= ' WHERE category_name = :category';
    $params[':category'] = $_GET['category'];
}
$sql .= ' ORDER BY created_at DESC';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_ok($stmt->fetchAll());
} catch (PDOException $e) {
    json_error('Could not load active listings.', 500, $e->getMessage());
}
