<?php
/**
 * get_profile.php  —  GET  —  a member's profile page.
 *
 *   ?user_id=3   -> that member's PUBLIC profile
 *   (no user_id) -> the signed-in member's own profile (public + private parts)
 *
 * Public:  name, headline, location, bio, join date, skills, ratings (as a
 *          provider and as a requester), reviews received, active posts, and
 *          the history of completed trades (title, category, role, hours, date,
 *          rating) — never counterparties' names, wallets, or contact details.
 * Private (own profile only): email, wallet balances, contact methods.
 *
 * DEMONSTRATES:
 *   - JOINs: User_Skills -> Skills -> Categories (skill chips);
 *            Reviews -> Bookings -> Users/Listings (reviews with author + service).
 *   - Aggregation: AVG/COUNT ratings split by role, SUM hours earned/spent.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_lib.php';
require_method('GET');

$viewer = current_user_id();
$uid    = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int) $_GET['user_id'] : $viewer;
if ($uid === null) {
    json_error('Please sign in to continue.', 401);
}
$isMe = $viewer !== null && $viewer === $uid;

$pdo = Database::pdo();

try {
    if ($isMe) {
        settle_due_bookings($pdo);   // own wallet must reflect auto-released payouts
    }

    /* ---- Core user (+ wallet for the owner) ---- */
    $u = $pdo->prepare('
        SELECT u.user_id, u.full_name, u.email, u.headline, u.location, u.bio, u.join_date,
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
        SELECT s.skill_id, s.skill_name, c.category_id, c.category_name
          FROM User_Skills us
          INNER JOIN Skills     s ON s.skill_id     = us.skill_id
          INNER JOIN Categories c ON c.category_id  = s.category_id
         WHERE us.user_id = :uid
         ORDER BY s.skill_name
    ');
    $sk->execute([':uid' => $uid]);
    $skills = array_map(static fn (array $s): array => [
        'skill_id'      => (int) $s['skill_id'],
        'skill_name'    => $s['skill_name'],
        'category_id'   => (int) $s['category_id'],
        'category_name' => $s['category_name'],
    ], $sk->fetchAll());

    /* ---- Ratings split by role ---- */
    $ratings = rating_summary($pdo, $uid);

    /* ---- Hours earned / spent (ledger sums) ---- */
    $earned = $pdo->prepare('SELECT COALESCE(SUM(hours_transferred),0) FROM Transactions WHERE receiver_id = :uid');
    $earned->execute([':uid' => $uid]);
    $spent = $pdo->prepare('SELECT COALESCE(SUM(hours_transferred),0) FROM Transactions WHERE sender_id = :uid');
    $spent->execute([':uid' => $uid]);

    /* ---- Reviews received (JOIN to the reviewer's name + the service) ---- */
    $rv = $pdo->prepare('
        SELECT r.review_id, r.rating, r.comment, r.created_at,
               author.user_id   AS author_id,
               author.full_name AS author_name,
               l.title          AS service_title,
               b.agreed_hours,
               (b.provider_id = r.reviewee_id) AS as_provider
          FROM Reviews r
          INNER JOIN Bookings b      ON b.booking_id    = r.booking_id
          INNER JOIN Users    author ON author.user_id  = r.reviewer_id
          INNER JOIN Listings l      ON l.listing_id    = b.listing_id
         WHERE r.reviewee_id = :uid
         ORDER BY r.created_at DESC
    ');
    $rv->execute([':uid' => $uid]);
    $reviews = array_map(static fn (array $r): array => [
        'review_id'     => (int) $r['review_id'],
        'rating'        => (int) $r['rating'],
        'comment'       => $r['comment'],
        'created_at'    => iso_dt($r['created_at']),
        'author_id'     => (int) $r['author_id'],
        'author_name'   => $r['author_name'],
        'service_title' => $r['service_title'],
        'hours'         => (float) $r['agreed_hours'],
        'role'          => ((int) $r['as_provider']) === 1 ? 'provider' : 'requester',
    ], $rv->fetchAll());

    /* ---- Active posts (offers + open requests) ---- */
    $ls = $pdo->prepare('
        SELECT l.listing_id, l.title, l.description, l.listing_type, l.estimated_hours,
               l.delivery_mode, l.preferred_at, l.created_at, s.skill_name, c.category_name
          FROM Listings l
          INNER JOIN Skills     s ON s.skill_id    = l.skill_id
          INNER JOIN Categories c ON c.category_id = s.category_id
         WHERE l.user_id = :uid AND l.status = \'active\'
         ORDER BY l.listing_type, l.created_at DESC
    ');
    $ls->execute([':uid' => $uid]);
    $listings = array_map(static fn (array $l): array => [
        'listing_id'      => (int) $l['listing_id'],
        'title'           => $l['title'],
        'description'     => $l['description'],
        'listing_type'    => $l['listing_type'],
        'estimated_hours' => (float) $l['estimated_hours'],
        'delivery_mode'   => $l['delivery_mode'],
        'preferred_at'    => iso_dt($l['preferred_at']),
        'created_at'      => iso_dt($l['created_at']),
        'skill_name'      => $l['skill_name'],
        'category_name'   => $l['category_name'],
    ], $ls->fetchAll());

    /* ---- Public trade history: completed bookings, no counterparty names ---- */
    $hs = $pdo->prepare('
        SELECT b.booking_id, b.agreed_hours, b.completed_at, l.title, c.category_name,
               (b.provider_id = :uid1) AS as_provider,
               r.rating AS rating_received
          FROM Bookings b
          INNER JOIN Listings   l ON l.listing_id  = b.listing_id
          INNER JOIN Skills     s ON s.skill_id    = l.skill_id
          INNER JOIN Categories c ON c.category_id = s.category_id
          LEFT  JOIN Reviews    r ON r.booking_id  = b.booking_id AND r.reviewee_id = :uid2
         WHERE b.booking_status = \'completed\' AND :uid3 IN (b.requester_id, b.provider_id)
         ORDER BY b.completed_at DESC
         LIMIT 50
    ');
    $hs->execute([':uid1' => $uid, ':uid2' => $uid, ':uid3' => $uid]);
    $history = array_map(static fn (array $h): array => [
        'booking_id'      => (int) $h['booking_id'],
        'title'           => $h['title'],
        'category_name'   => $h['category_name'],
        'hours'           => (float) $h['agreed_hours'],
        'completed_at'    => iso_dt($h['completed_at']),
        'role'            => ((int) $h['as_provider']) === 1 ? 'provider' : 'requester',
        'rating_received' => $h['rating_received'] === null ? null : (int) $h['rating_received'],
    ], $hs->fetchAll());

    $out = [
        'user' => [
            'user_id'   => (int) $user['user_id'],
            'full_name' => $user['full_name'],
            'headline'  => $user['headline'],
            'location'  => $user['location'],
            'bio'       => $user['bio'],
            'join_date' => $user['join_date'],
        ],
        'is_me'        => $isMe,
        'skills'       => $skills,
        'ratings'      => $ratings,
        // Back-compat: the headline rating is the one earned as a provider.
        'review_count' => $ratings['as_provider']['count'],
        'avg_rating'   => $ratings['as_provider']['avg'],
        'hours_earned' => (float) $earned->fetchColumn(),
        'hours_spent'  => (float) $spent->fetchColumn(),
        'reviews'      => $reviews,
        'listings'     => $listings,
        'history'      => $history,
        'contact'      => [
            'methods' => array_column(fetch_contacts($pdo, $uid), 'method'),
            'visible' => $viewer !== null && can_view_contacts($pdo, $viewer, $uid),
        ],
    ];

    if ($isMe) {
        $out['user']['email']             = $user['email'];
        $out['user']['available_balance'] = (float) $user['available_balance'];
        $out['user']['escrow_balance']    = (float) $user['escrow_balance'];
        $out['contacts']                  = fetch_contacts($pdo, $uid);
    }

    json_ok($out);
} catch (PDOException $e) {
    json_error('Could not load profile.', 500, $e->getMessage());
}
