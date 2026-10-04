<?php
/**
 * contacts.php  —  reveal contact details only to members who want to trade.
 *
 *   GET  contacts.php?user_id=3   -> { visible, methods, contacts? }
 *        Contacts are returned only when the viewer may see them (a booking, a
 *        help offer, or a reveal between the two — see can_view_contacts()).
 *        Otherwise only the method names ("phone", "whatsapp") are listed.
 *   GET  contacts.php?inbox=1     -> who revealed MY contact, and for which post
 *   POST contacts.php             -> reveal a listing author's contact
 *        body: { listing_id }
 *        The viewer must be signed in and the post must be someone else's and
 *        still open — i.e. the viewer wants to book them (Offer) or help them
 *        (Request). The reveal is recorded and is MUTUAL: the author can now see
 *        the viewer's contact too, so either side can reach out first.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_lib.php';

$uid    = require_login();
$pdo    = Database::pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function contact_payload(PDO $pdo, int $viewer, int $target): array
{
    $contacts = fetch_contacts($pdo, $target);
    if (can_view_contacts($pdo, $viewer, $target)) {
        return ['user_id' => $target, 'visible' => true, 'methods' => array_column($contacts, 'method'), 'contacts' => $contacts];
    }
    return ['user_id' => $target, 'visible' => false, 'methods' => array_column($contacts, 'method')];
}

try {
    if ($method === 'GET') {
        if (($_GET['inbox'] ?? '') === '1') {
            $q = $pdo->prepare('
                SELECT cr.reveal_id, cr.viewer_id, v.full_name AS viewer_name, v.headline AS viewer_headline,
                       cr.listing_id, l.title AS listing_title, l.listing_type, cr.created_at
                  FROM Contact_Reveals cr
                  INNER JOIN Users    v ON v.user_id    = cr.viewer_id
                  INNER JOIN Listings l ON l.listing_id = cr.listing_id
                 WHERE cr.target_id = :uid
                 ORDER BY cr.created_at DESC
                 LIMIT 30');
            $q->execute([':uid' => $uid]);
            json_ok(array_map(static fn (array $r): array => [
                'reveal_id'       => (int) $r['reveal_id'],
                'viewer_id'       => (int) $r['viewer_id'],
                'viewer_name'     => $r['viewer_name'],
                'viewer_headline' => $r['viewer_headline'],
                'listing_id'      => (int) $r['listing_id'],
                'listing_title'   => $r['listing_title'],
                'listing_type'    => $r['listing_type'],
                'created_at'      => iso_dt($r['created_at']),
            ], $q->fetchAll()));
        }

        $target = (int) ($_GET['user_id'] ?? 0);
        if ($target <= 0) {
            json_error('Missing user_id.', 400);
        }
        json_ok(contact_payload($pdo, $uid, $target));
    }

    if ($method !== 'POST') {
        json_error('Method not allowed.', 405);
    }

    $in = read_json_body();
    require_fields($in, ['listing_id']);
    $listingId = (int) $in['listing_id'];

    $l = $pdo->prepare('SELECT user_id, status FROM Listings WHERE listing_id = :id');
    $l->execute([':id' => $listingId]);
    $listing = $l->fetch();
    if (!$listing) {
        json_error('Listing not found.', 404);
    }
    $target = (int) $listing['user_id'];
    if ($target === $uid) {
        json_error('That is your own post.', 400);
    }
    if ($listing['status'] !== 'active') {
        json_error('This post is no longer open.', 409);
    }

    // INSERT IGNORE: revealing twice for the same post is a no-op (UNIQUE key).
    $pdo->prepare('INSERT IGNORE INTO Contact_Reveals (viewer_id, target_id, listing_id) VALUES (:v, :t, :l)')
        ->execute([':v' => $uid, ':t' => $target, ':l' => $listingId]);

    json_ok(contact_payload($pdo, $uid, $target), 201);
} catch (PDOException $e) {
    json_error('Could not load contact details.', 500, $e->getMessage());
}
