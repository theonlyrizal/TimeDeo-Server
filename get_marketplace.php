<?php
/**
 * get_marketplace.php  —  GET  —  the Find help grid (Offers) and the Help
 * wanted board (Requests).
 *
 * DEMONSTRATES (assignment §3 "Joins"):
 *   - Multiple JOIN types in one statement:
 *       INNER JOIN Listings -> Users, Skills, Categories  (a listing MUST have these)
 *       LEFT  JOIN Bookings -> Reviews                    (a listing MAY have ratings)
 *   - Aggregation over the LEFT-joined side (COUNT/AVG of the provider's reviews).
 *   - Correlated subqueries for per-viewer facts (my offer on a request, whether a
 *     request matches my skills) and the author's overall rating.
 *
 * Optional query params (all bound as parameters — never string-concatenated):
 *   ?type=Offer|Request   (default Offer)
 *   ?category_id=1   ?q=react
 *   ?match=1              Requests only: just the ones in categories I have skills in
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_method('GET');

$pdo = Database::pdo();
$me  = current_user_id() ?? 0;   // browsing works signed out; per-viewer fields need a session

$type = ($_GET['type'] ?? 'Offer') === 'Request' ? 'Request' : 'Offer';

// Build the WHERE list from placeholders only; user values go into $params.
$where  = ["l.status = 'active'", 'l.listing_type = :type'];
$params = [':type' => $type, ':me1' => $me, ':me2' => $me, ':me3' => $me];

if (isset($_GET['category_id']) && $_GET['category_id'] !== '') {
    $where[] = 'c.category_id = :category_id';
    $params[':category_id'] = (int) $_GET['category_id'];
}
if (isset($_GET['q']) && $_GET['q'] !== '') {
    // Separate placeholders bound to the same value: native prepared statements
    // do not allow one named marker (:q) to appear twice in the SQL.
    $where[] = '(l.title LIKE :q_title OR l.description LIKE :q_descr OR s.skill_name LIKE :q_skill)';
    $params[':q_title'] = '%' . $_GET['q'] . '%';
    $params[':q_descr'] = '%' . $_GET['q'] . '%';
    $params[':q_skill'] = '%' . $_GET['q'] . '%';
}
if ($type === 'Request' && ($_GET['match'] ?? '') === '1') {
    $where[] = 'EXISTS (SELECT 1 FROM User_Skills us4
                          INNER JOIN Skills s4 ON s4.skill_id = us4.skill_id
                         WHERE us4.user_id = :me4 AND s4.category_id = c.category_id)';
    $params[':me4'] = $me;
}

$sql = '
    SELECT
        l.listing_id, l.title, l.description, l.listing_type, l.estimated_hours,
        l.delivery_mode, l.preferred_at, l.status, l.created_at,
        u.user_id   AS author_id,
        u.full_name AS author_name,
        u.headline  AS author_headline,
        s.skill_id, s.skill_name,
        c.category_id, c.category_name,
        COUNT(DISTINCT r.review_id) AS review_count,
        ROUND(AVG(r.rating), 2)     AS avg_rating,
        (SELECT ROUND(AVG(ra.rating), 2)                                -- author as a provider
           FROM Reviews ra
           INNER JOIN Bookings ba ON ba.booking_id = ra.booking_id
          WHERE ra.reviewee_id = u.user_id AND ba.provider_id = u.user_id) AS author_rating,
        (SELECT COUNT(*) FROM Help_Offers h
          WHERE h.listing_id = l.listing_id AND h.status = \'pending\')  AS offer_count,
        (SELECT h2.status FROM Help_Offers h2
          WHERE h2.listing_id = l.listing_id AND h2.helper_id = :me1)    AS my_offer_status,
        EXISTS (SELECT 1 FROM User_Skills us
                 WHERE us.user_id = :me2 AND us.skill_id = l.skill_id)  AS skill_match,
        EXISTS (SELECT 1 FROM User_Skills us3
                  INNER JOIN Skills s3 ON s3.skill_id = us3.skill_id
                 WHERE us3.user_id = :me3 AND s3.category_id = c.category_id) AS category_match
    FROM Listings   l
    INNER JOIN Users      u ON u.user_id     = l.user_id       -- RUBRIC: INNER JOIN
    INNER JOIN Skills     s ON s.skill_id    = l.skill_id      -- RUBRIC: INNER JOIN
    INNER JOIN Categories c ON c.category_id = s.category_id   -- RUBRIC: INNER JOIN
    LEFT  JOIN Bookings   b ON b.listing_id  = l.listing_id    -- RUBRIC: LEFT JOIN
    LEFT  JOIN Reviews    r ON r.booking_id  = b.booking_id    -- RUBRIC: LEFT JOIN
                           AND r.reviewee_id = b.provider_id   -- only ratings the provider received
    WHERE ' . implode(' AND ', $where) . '
    GROUP BY l.listing_id, l.title, l.description, l.listing_type, l.estimated_hours,
             l.delivery_mode, l.preferred_at, l.status, l.created_at,
             u.user_id, u.full_name, u.headline, s.skill_id, s.skill_name,
             c.category_id, c.category_name
    ORDER BY skill_match DESC, l.created_at DESC';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = array_map(static function (array $r) use ($me): array {
        return [
            'listing_id'      => (int) $r['listing_id'],
            'title'           => $r['title'],
            'description'     => $r['description'],
            'listing_type'    => $r['listing_type'],
            'estimated_hours' => (float) $r['estimated_hours'],
            'delivery_mode'   => $r['delivery_mode'],
            'preferred_at'    => iso_dt($r['preferred_at']),
            'created_at'      => iso_dt($r['created_at']),
            'author_id'       => (int) $r['author_id'],
            'author_name'     => $r['author_name'],
            'author_headline' => $r['author_headline'],
            'author_rating'   => $r['author_rating'] === null ? null : (float) $r['author_rating'],
            'skill_id'        => (int) $r['skill_id'],
            'skill_name'      => $r['skill_name'],
            'category_id'     => (int) $r['category_id'],
            'category_name'   => $r['category_name'],
            'review_count'    => (int) $r['review_count'],
            'avg_rating'      => $r['avg_rating'] === null ? null : (float) $r['avg_rating'],
            'offer_count'     => (int) $r['offer_count'],
            'my_offer_status' => $r['my_offer_status'],
            'skill_match'     => (bool) $r['skill_match'],
            'category_match'  => (bool) $r['category_match'],
            'mine'            => $me !== 0 && (int) $r['author_id'] === $me,
        ];
    }, $stmt->fetchAll());

    json_ok($rows);
} catch (PDOException $e) {
    json_error('Could not load the marketplace.', 500, $e->getMessage());
}
