<?php
/**
 * get_skills.php  —  GET  —  every skill, grouped under its category.
 *
 * Powers the skill pickers (Offer a skill, Ask for help, profile editing), so a
 * listing is filed under the skill the member actually chose — not a default
 * per category. Also returns how many members have each skill.
 *
 * DEMONSTRATES: INNER JOIN + LEFT JOIN with GROUP BY (member count per skill).
 */

require_once __DIR__ . '/db.php';
require_method('GET');

$pdo = Database::pdo();

try {
    $rows = $pdo->query('
        SELECT c.category_id, c.category_name, s.skill_id, s.skill_name,
               COUNT(us.user_id) AS member_count
          FROM Categories c
          LEFT JOIN Skills      s  ON s.category_id = c.category_id
          LEFT JOIN User_Skills us ON us.skill_id   = s.skill_id
         GROUP BY c.category_id, c.category_name, s.skill_id, s.skill_name
         ORDER BY c.category_name, s.skill_name
    ')->fetchAll();

    $byCat = [];
    foreach ($rows as $r) {
        $cid = (int) $r['category_id'];
        if (!isset($byCat[$cid])) {
            $byCat[$cid] = [
                'category_id'   => $cid,
                'category_name' => $r['category_name'],
                'skills'        => [],
            ];
        }
        if ($r['skill_id'] !== null) {
            $byCat[$cid]['skills'][] = [
                'skill_id'     => (int) $r['skill_id'],
                'skill_name'   => $r['skill_name'],
                'member_count' => (int) $r['member_count'],
            ];
        }
    }

    json_ok(array_values($byCat));
} catch (PDOException $e) {
    json_error('Could not load skills.', 500, $e->getMessage());
}
