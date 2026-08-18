<?php
/**
 * listings.php  —  full DML CRUD for marketplace listings, routed by HTTP method.
 *
 * DEMONSTRATES (assignment §3 "DML"): SELECT, INSERT, UPDATE, DELETE — all via
 * PDO prepared statements.
 *
 *   GET    listings.php                -> list all listings (joined for context)
 *   GET    listings.php?listing_id=4   -> one listing
 *   POST   listings.php                -> INSERT   body: {user_id, skill_id, listing_type, title, description?, estimated_hours}
 *   PUT    listings.php                -> UPDATE   body: {listing_id, title?, description?, estimated_hours?, status?}
 *   DELETE listings.php?listing_id=4   -> DELETE
 */

require_once __DIR__ . '/db.php';

$pdo    = Database::pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($method) {

    /* ------------------------------------------------------------------ SELECT */
    case 'GET':
        try {
            if (isset($_GET['listing_id']) && $_GET['listing_id'] !== '') {
                $stmt = $pdo->prepare('
                    SELECT l.listing_id, l.title, l.description, l.listing_type,
                           l.estimated_hours, l.status, l.created_at,
                           l.user_id AS provider_id, u.full_name AS provider_name,
                           s.skill_name, c.category_name
                      FROM Listings l
                      INNER JOIN Users      u ON u.user_id     = l.user_id
                      INNER JOIN Skills     s ON s.skill_id    = l.skill_id
                      INNER JOIN Categories c ON c.category_id = s.category_id
                     WHERE l.listing_id = :id
                ');
                $stmt->execute([':id' => (int) $_GET['listing_id']]);
                $row = $stmt->fetch();
                if (!$row) {
                    json_error('Listing not found.', 404);
                }
                json_ok($row);
            }

            // List all (optionally by author: ?user_id=)
            $params = [];
            $sql = '
                SELECT l.listing_id, l.title, l.description, l.listing_type,
                       l.estimated_hours, l.status, l.created_at,
                       l.user_id AS provider_id, u.full_name AS provider_name,
                       s.skill_name, c.category_name
                  FROM Listings l
                  INNER JOIN Users      u ON u.user_id     = l.user_id
                  INNER JOIN Skills     s ON s.skill_id    = l.skill_id
                  INNER JOIN Categories c ON c.category_id = s.category_id';
            if (isset($_GET['user_id']) && $_GET['user_id'] !== '') {
                $sql .= ' WHERE l.user_id = :uid';
                $params[':uid'] = (int) $_GET['user_id'];
            }
            $sql .= ' ORDER BY l.created_at DESC';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            json_ok($stmt->fetchAll());
        } catch (PDOException $e) {
            json_error('Could not load listings.', 500, $e->getMessage());
        }
        break;

    /* ------------------------------------------------------------------ INSERT */
    case 'POST':
        $in = read_json_body();
        require_fields($in, ['user_id', 'skill_id', 'listing_type', 'title', 'estimated_hours']);

        if (!in_array($in['listing_type'], ['Offer', 'Request'], true)) {
            json_error("listing_type must be 'Offer' or 'Request'.", 400);
        }
        if ((float) $in['estimated_hours'] <= 0) {
            json_error('estimated_hours must be greater than 0.', 400);
        }

        try {
            $stmt = $pdo->prepare('
                INSERT INTO Listings (user_id, skill_id, listing_type, title, description, estimated_hours, status)
                VALUES (:uid, :skill, :type, :title, :descr, :hours, :status)
            ');
            $stmt->execute([
                ':uid'    => (int) $in['user_id'],
                ':skill'  => (int) $in['skill_id'],
                ':type'   => $in['listing_type'],
                ':title'  => trim($in['title']),
                ':descr'  => $in['description'] ?? null,
                ':hours'  => (float) $in['estimated_hours'],
                ':status' => $in['status'] ?? 'active',
            ]);
            json_ok(['listing_id' => (int) $pdo->lastInsertId()], 201);
        } catch (PDOException $e) {
            // 23000 here usually means user_id / skill_id FK doesn't exist.
            if ($e->getCode() === '23000') {
                json_error('Invalid user_id or skill_id.', 400, $e->getMessage());
            }
            json_error('Could not create listing.', 500, $e->getMessage());
        }
        break;

    /* ------------------------------------------------------------------ UPDATE */
    case 'PUT':
        $in = read_json_body();
        require_fields($in, ['listing_id']);

        // Build the SET clause from only the fields the caller sent.
        $sets   = [];
        $params = [':id' => (int) $in['listing_id']];
        foreach (['title', 'description', 'estimated_hours', 'status'] as $field) {
            if (array_key_exists($field, $in)) {
                $sets[] = "$field = :$field";
                $params[":$field"] = $field === 'estimated_hours' ? (float) $in[$field] : $in[$field];
            }
        }
        if (!$sets) {
            json_error('No updatable fields provided.', 400);
        }

        try {
            $stmt = $pdo->prepare('UPDATE Listings SET ' . implode(', ', $sets) . ' WHERE listing_id = :id');
            $stmt->execute($params);
            if ($stmt->rowCount() === 0) {
                // No row changed: either the id doesn't exist or values were identical.
                $chk = $pdo->prepare('SELECT 1 FROM Listings WHERE listing_id = :id');
                $chk->execute([':id' => (int) $in['listing_id']]);
                if (!$chk->fetchColumn()) {
                    json_error('Listing not found.', 404);
                }
            }
            json_ok(['listing_id' => (int) $in['listing_id'], 'updated' => true]);
        } catch (PDOException $e) {
            json_error('Could not update listing.', 500, $e->getMessage());
        }
        break;

    /* ------------------------------------------------------------------ DELETE */
    case 'DELETE':
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

        try {
            $stmt = $pdo->prepare('DELETE FROM Listings WHERE listing_id = :id');
            $stmt->execute([':id' => $id]);
            if ($stmt->rowCount() === 0) {
                json_error('Listing not found.', 404);
            }
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
