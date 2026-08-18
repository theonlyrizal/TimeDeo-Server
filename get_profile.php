<?php
/**
 * get_profile.php  —  GET  —  everything the Profile page needs for one user.
 *
 * DEMONSTRATES:
 *   - JOINs: User_Skills -> Skills -> Categories (the user's skill chips);
 *            Reviews -> Bookings -> Users (reviews received, with author names).
 *   - Aggregation: AVG rating received, SUM hours earned/spent.
 *
 * Required: ?user_id=1
 */

require_once __DIR__ . '/db.php';
require_method('GET');

if (!isset($_GET['user_id']) || $_GET['user_id'] === '') {
    json_error('Missing required query param: user_id', 400);
}
$uid = (int) $_GET['user_id'];

$pdo = Database::pdo();

try {
    /* ---- Core user + wallet ---- */
    $u = $pdo->prepare('
        SELECT u.user_id, u.full_name, u.email, u.join_date,
               w.available_balance, w.escrow_balance
          FROM Users u
          LEFT JOIN Wallets w ON w.user_id = u.user_id
         WHERE u.user_id = :uid
    ');
    $u->execute([':uid' => $uid]);
    $user = $u->fetch();
    if (!$user) {
        json_error('User not found.', 404);
    }

    /* ---- Skills (JOIN across the M:N bridge) ---- */
    $sk = $pdo->prepare('
        SELECT s.skill_name, c.category_name
          FROM User_Skills us
          INNER JOIN Skills     s ON s.skill_id     = us.skill_id
          INNER JOIN Categories c ON c.category_id  = s.category_id
         WHERE us.user_id = :uid
         ORDER BY s.skill_name
    ');
    $sk->execute([':uid' => $uid]);
    $skills = $sk->fetchAll();

    /* ---- Rating summary received as a provider (aggregation) ---- */
    $rs = $pdo->prepare('
        SELECT COUNT(*) AS review_count, ROUND(AVG(r.rating), 2) AS avg_rating
          FROM Reviews r
          INNER JOIN Bookings b ON b.booking_id = r.booking_id
         WHERE b.provider_id = :uid
    ');
    $rs->execute([':uid' => $uid]);
    $ratings = $rs->fetch();

    /* ---- Hours earned / spent (ledger sums) ---- */
    $earned = $pdo->prepare('SELECT COALESCE(SUM(hours_transferred),0) FROM Transactions WHERE receiver_id = :uid');
    $earned->execute([':uid' => $uid]);
    $spent = $pdo->prepare('SELECT COALESCE(SUM(hours_transferred),0) FROM Transactions WHERE sender_id = :uid');
    $spent->execute([':uid' => $uid]);

    /* ---- Reviews received (JOIN to the reviewer's name) ---- */
    $rv = $pdo->prepare('
        SELECT r.review_id, r.rating, r.comment, r.created_at,
               author.full_name AS author_name,
               l.title          AS service_title
          FROM Reviews r
          INNER JOIN Bookings b      ON b.booking_id = r.booking_id
          INNER JOIN Users    author ON author.user_id = r.reviewer_id
          INNER JOIN Listings l      ON l.listing_id = b.listing_id
         WHERE b.provider_id = :uid
         ORDER BY r.created_at DESC
    ');
    $rv->execute([':uid' => $uid]);
    $reviews = array_map(static function (array $r): array {
        return [
            'review_id'     => (int) $r['review_id'],
            'rating'        => (int) $r['rating'],
            'comment'       => $r['comment'],
            'created_at'    => $r['created_at'],
            'author_name'   => $r['author_name'],
            'service_title' => $r['service_title'],
        ];
    }, $rv->fetchAll());

    json_ok([
        'user' => [
            'user_id'           => (int) $user['user_id'],
            'full_name'         => $user['full_name'],
            'email'             => $user['email'],
            'join_date'         => $user['join_date'],
            'available_balance' => (float) $user['available_balance'],
            'escrow_balance'    => (float) $user['escrow_balance'],
        ],
        'skills'       => $skills,
        'review_count' => (int) ($ratings['review_count'] ?? 0),
        'avg_rating'   => $ratings['avg_rating'] === null ? null : (float) $ratings['avg_rating'],
        'hours_earned' => (float) $earned->fetchColumn(),
        'hours_spent'  => (float) $spent->fetchColumn(),
        'reviews'      => $reviews,
    ]);
} catch (PDOException $e) {
    json_error('Could not load profile.', 500, $e->getMessage());
}
