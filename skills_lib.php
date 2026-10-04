<?php
/**
 * skills_lib.php — resolve a skill chosen in the UI to a Skills row.
 *
 * The client either picks an existing skill ({ "skill_id": 12 }) or types a new
 * one inside a category ({ "skill_name": "Arabic", "category_id": 8 }). New
 * skills are inserted once; the UNIQUE(skill_name) constraint (case-insensitive
 * collation) means "arabic" and "Arabic" resolve to the same row.
 *
 * Include AFTER db.php and booking_lib.php (uses ApiError).
 */

declare(strict_types=1);

/** Returns a valid skill_id, inserting a new skill if needed. Throws ApiError. */
function resolve_skill(PDO $pdo, array $in): int
{
    if (isset($in['skill_id']) && $in['skill_id'] !== '' && $in['skill_id'] !== null) {
        $q = $pdo->prepare('SELECT skill_id FROM Skills WHERE skill_id = :id');
        $q->execute([':id' => (int) $in['skill_id']]);
        $id = $q->fetchColumn();
        if ($id === false) {
            throw new ApiError('That skill does not exist.', 400);
        }
        return (int) $id;
    }

    $name = trim(preg_replace('/\s+/', ' ', (string) ($in['skill_name'] ?? '')));
    $cat  = (int) ($in['category_id'] ?? 0);
    if ($name === '' || $cat <= 0) {
        throw new ApiError('Choose a skill (or type a new one) and its category.', 400);
    }
    if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
        throw new ApiError('Skill names must be 2–60 characters.', 400);
    }

    $c = $pdo->prepare('SELECT 1 FROM Categories WHERE category_id = :id');
    $c->execute([':id' => $cat]);
    if (!$c->fetchColumn()) {
        throw new ApiError('Unknown category.', 400);
    }

    // Reuse an existing skill with the same name (case-insensitive collation).
    $q = $pdo->prepare('SELECT skill_id FROM Skills WHERE skill_name = :n');
    $q->execute([':n' => $name]);
    $id = $q->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    try {
        $pdo->prepare('INSERT INTO Skills (category_id, skill_name) VALUES (:c, :n)')
            ->execute([':c' => $cat, ':n' => $name]);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        // Lost a race with someone adding the same skill: read theirs.
        if ($e->getCode() === '23000') {
            $q->execute([':n' => $name]);
            return (int) $q->fetchColumn();
        }
        throw $e;
    }
}

/** Make sure the member is listed as having this skill (idempotent). */
function attach_user_skill(PDO $pdo, int $userId, int $skillId): void
{
    $pdo->prepare('INSERT IGNORE INTO User_Skills (user_id, skill_id) VALUES (:u, :s)')
        ->execute([':u' => $userId, ':s' => $skillId]);
}
