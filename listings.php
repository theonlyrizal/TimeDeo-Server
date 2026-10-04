<?php
/**
 * listings.php  —  full DML CRUD for marketplace listings, routed by HTTP method.
 *
 * DEMONSTRATES (assignment §3 "DML"): SELECT, INSERT, UPDATE, DELETE — all via
 * PDO prepared statements.
 *
 *   GET    listings.php                -> list all listings (joined for context)
 *   GET    listings.php?listing_id=4   -> one listing
 *   GET    listings.php?user_id=2      -> one member's listings
 *   POST   listings.php                -> INSERT (author = session user)
 *          body: { listing_type: 'Offer'|'Request', title, description?, estimated_hours,
 *                  delivery_mode?: 'online'|'local', preferred_at?: ISO (Requests),
 *                  skill_id | (skill_name + category_id) }
 *   PUT    listings.php                -> UPDATE own listing
 *          body: { listing_id, title?, description?, estimated_hours?, status?: 'active'|'inactive' }
 *   DELETE listings.php?listing_id=4   -> DELETE own listing
 *
 * The skill is the one the member picked (or typed); offering a skill also adds
 * it to the member's profile skills.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_lib.php';
require_once __DIR__ . '/skills_lib.php';

$pdo    = Database::pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

const LISTING_SELECT = '
    SELECT l.listing_id, l.title, l.description, l.listing_type,
           l.estimated_hours, l.delivery_mode, l.preferred_at, l.status, l.created_at,
           l.user_id AS author_id, u.full_name AS author_name,
           s.skill_id, s.skill_name, c.category_id, c.category_name
      FROM Listings l
      INNER JOIN Users      u ON u.user_id     = l.user_id
      INNER JOIN Skills     s ON s.skill_id    = l.skill_id
      INNER JOIN Categories c ON c.category_id = s.category_id';

function listing_out(array $r): array
{
    return [
        'listing_id'      => (int) $r['listing_id'],
        'title'           => $r['title'],
        'description'     => $r['description'],
        'listing_type'    => $r['listing_type'],
        'estimated_hours' => (float) $r['estimated_hours'],
        'delivery_mode'   => $r['delivery_mode'],
        'preferred_at'    => iso_dt($r['preferred_at']),
        'status'          => $r['status'],
        'created_at'      => iso_dt($r['created_at']),
        'author_id'       => (int) $r['author_id'],
        'author_name'     => $r['author_name'],
        'skill_id'        => (int) $r['skill_id'],
        'skill_name'      => $r['skill_name'],
        'category_id'     => (int) $r['category_id'],
        'category_name'   => $r['category_name'],
    ];
}

/** Load a listing and make sure the session user wrote it. */
function require_own_listing(PDO $pdo, int $listingId, int $uid): array
{
    $q = $pdo->prepare('SELECT listing_id, user_id, listing_type, status FROM Listings WHERE listing_id = :id');
    $q->execute([':id' => $listingId]);
    $row = $q->fetch();
    if (!$row) {
        json_error('Listing not found.', 404);
    }
    if ((int) $row['user_id'] !== $uid) {
        json_error('You can only change your own listings.', 403);
    }
    return $row;
}

switch ($method) {

    /* ------------------------------------------------------------------ SELECT */
    case 'GET':
        try {
            if (isset($_GET['listing_id']) && $_GET['listing_id'] !== '') {
                $stmt = $pdo->prepare(LISTING_SELECT . ' WHERE l.listing_id = :id');
                $stmt->execute([':id' => (int) $_GET['listing_id']]);
                $row = $stmt->fetch();
                if (!$row) {
                    json_error('Listing not found.', 404);
                }
                json_ok(listing_out($row));
            }

            // List all (optionally by author: ?user_id=)
            $params = [];
            $sql    = LISTING_SELECT;
            if (isset($_GET['user_id']) && $_GET['user_id'] !== '') {
                $sql .= ' WHERE l.user_id = :uid';
                $params[':uid'] = (int) $_GET['user_id'];
            }
            $sql .= ' ORDER BY l.created_at DESC';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            json_ok(array_map('listing_out', $stmt->fetchAll()));
        } catch (PDOException $e) {
            json_error('Could not load listings.', 500, $e->getMessage());
        }
        break;

    /* ------------------------------------------------------------------ INSERT */
    case 'POST':
        $uid = require_login();
        $in  = read_json_body();
        require_fields($in, ['listing_type', 'title', 'estimated_hours']);

        $type  = (string) $in['listing_type'];
        $title = clean_text($in['title'], 160, 'Title');
        $descr = clean_text($in['description'] ?? null, 2000, 'Description');
        $hours = round((float) $in['estimated_hours'], 2);
        $mode  = (string) ($in['delivery_mode'] ?? 'online');

        if (!in_array($type, ['Offer', 'Request'], true)) {
            json_error("listing_type must be 'Offer' or 'Request'.", 400);
        }
        if ($title === null || mb_strlen($title) < 4) {
            json_error('Give it a clear title (at least 4 characters).', 400);
        }
        if ($hours < 0.25 || $hours > 40) {
            json_error('Estimated hours must be between 0.25 and 40.', 400);
        }
        if (!in_array($mode, ['online', 'local'], true)) {
            json_error("delivery_mode must be 'online' or 'local'.", 400);
        }

        $preferredAt = null;
        if ($type === 'Request' && !empty($in['preferred_at'])) {
            $preferredAt = parse_client_dt($in['preferred_at']);
            if ($preferredAt === null || $preferredAt <= gmdate('Y-m-d H:i:s')) {
                json_error('The preferred time must be a valid future date.', 400);
            }
        }

        try {
            $pdo->beginTransaction();

            $skillId = resolve_skill($pdo, $in);
            if ($type === 'Offer') {
                attach_user_skill($pdo, $uid, $skillId);   // offering it = having it
            }

            $stmt = $pdo->prepare('
                INSERT INTO Listings (user_id, skill_id, listing_type, title, description,
                                      estimated_hours, delivery_mode, preferred_at, status)
                VALUES (:uid, :skill, :type, :title, :descr, :hours, :mode, :pref, \'active\')
            ');
            $stmt->execute([
                ':uid'   => $uid,
                ':skill' => $skillId,
                ':type'  => $type,
                ':title' => $title,
                ':descr' => $descr,
                ':hours' => $hours,
                ':mode'  => $mode,
                ':pref'  => $preferredAt,
            ]);
            $listingId = (int) $pdo->lastInsertId();
            $pdo->commit();

            $q = $pdo->prepare(LISTING_SELECT . ' WHERE l.listing_id = :id');
            $q->execute([':id' => $listingId]);
            json_ok(listing_out($q->fetch()), 201);
        } catch (Throwable $e) {
            fail_tx($pdo, $e, 'Could not create listing.');
        }
        break;

    /* ------------------------------------------------------------------ UPDATE */
    case 'PUT':
        $uid = require_login();
        $in  = read_json_body();
        require_fields($in, ['listing_id']);
        $listingId = (int) $in['listing_id'];
        $current   = require_own_listing($pdo, $listingId, $uid);

        // Build the SET clause from only the fields the caller sent (whitelisted).
        $sets   = [];
        $params = [':id' => $listingId];
        if (array_key_exists('title', $in)) {
            $title = clean_text($in['title'], 160, 'Title');
            if ($title === null || mb_strlen($title) < 4) {
                json_error('Give it a clear title (at least 4 characters).', 400);
            }
            $sets[] = 'title = :title';
            $params[':title'] = $title;
        }
        if (array_key_exists('description', $in)) {
            $sets[] = 'description = :description';
            $params[':description'] = clean_text($in['description'], 2000, 'Description');
        }
        if (array_key_exists('estimated_hours', $in)) {
            $hours = round((float) $in['estimated_hours'], 2);
            if ($hours < 0.25 || $hours > 40) {
                json_error('Estimated hours must be between 0.25 and 40.', 400);
            }
            $sets[] = 'estimated_hours = :estimated_hours';
            $params[':estimated_hours'] = $hours;
        }
        if (array_key_exists('status', $in)) {
            if (!in_array($in['status'], ['active', 'inactive'], true)) {
                json_error("status must be 'active' or 'inactive'.", 400);
            }
            if ($current['status'] === 'filled') {
                json_error('A filled request cannot be re-opened by hand.', 409);
            }
            $sets[] = 'status = :status';
            $params[':status'] = $in['status'];
        }
        if (!$sets) {
            json_error('No updatable fields provided.', 400);
        }

        try {
            $stmt = $pdo->prepare('UPDATE Listings SET ' . implode(', ', $sets) . ' WHERE listing_id = :id');
            $stmt->execute($params);
            json_ok(['listing_id' => $listingId, 'updated' => true]);
        } catch (PDOException $e) {
            json_error('Could not update listing.', 500, $e->getMessage());
        }
        break;

    /* ------------------------------------------------------------------ DELETE */
    case 'DELETE':
        $uid = require_login();
        // Accept the id from the query string or the JSON body.
        $id = null;
        if (isset($_GET['listing_id']) && $_GET['listing_id'] !== '') {
            $id = (int) $_GET['listing_id'];
        } else {
            $body = read_json_body();
            if (isset($body['listing_id'])) {
                $id = (int) $body['listing_id'];
            }
        }
        if (!$id) {
            json_error('Missing listing_id.', 400);
        }
        require_own_listing($pdo, $id, $uid);

        try {
            $stmt = $pdo->prepare('DELETE FROM Listings WHERE listing_id = :id');
            $stmt->execute([':id' => $id]);
            json_ok(['listing_id' => $id, 'deleted' => true]);
        } catch (PDOException $e) {
            // FK restrict: listing still referenced by bookings.
            if ($e->getCode() === '23000') {
                json_error('Cannot delete: this listing has bookings. Set status to "inactive" instead.', 409);
            }
            json_error('Could not delete listing.', 500, $e->getMessage());
        }
        break;

    default:
        json_error('Method not allowed.', 405);
}
