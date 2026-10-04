<?php
/**
 * update_profile.php  —  POST  —  the signed-in member edits their profile.
 *
 * Body (every key optional except full_name; omitted lists are left as-is):
 * {
 *   "full_name": "Avery Okafor",
 *   "headline": "Product designer", "location": "Dhaka", "bio": "...",
 *   "skills":   [ { "skill_id": 1 }, { "skill_name": "Arabic", "category_id": 8 } ],
 *   "contacts": [ { "method": "phone", "value": "+8801..." }, { "method": "telegram", "value": "@me" } ]
 * }
 *
 * DEMONSTRATES: a TRANSACTION spanning three tables — UPDATE Users, then
 * replace the member's User_Skills and User_Contacts rows (DELETE + INSERT).
 * Either the whole profile saves or nothing does.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/booking_lib.php';
require_once __DIR__ . '/skills_lib.php';
require_method('POST');

$uid = require_login();
$in  = read_json_body();
require_fields($in, ['full_name']);

$fullName = clean_text($in['full_name'], 120, 'Name');
if ($fullName === null || mb_strlen($fullName) < 2) {
    json_error('Please enter your name.', 400);
}
$headline = clean_text($in['headline'] ?? null, 120, 'Headline');
$location = clean_text($in['location'] ?? null, 120, 'Location');
$bio      = clean_text($in['bio'] ?? null, 600, 'Bio');

// Validate contacts up front (normalize_contact() 400s on bad input).
$contacts = null;
if (array_key_exists('contacts', $in)) {
    if (!is_array($in['contacts'])) {
        json_error('contacts must be a list.', 400);
    }
    $contacts = [];
    foreach ($in['contacts'] as $c) {
        $m = (string) ($c['method'] ?? '');
        if (isset($contacts[$m])) {
            json_error('Add each contact method only once.', 400);
        }
        $contacts[$m] = normalize_contact($m, $c['value'] ?? '');
    }
    if (!$contacts) {
        json_error('Keep at least one way for people you trade with to reach you.', 400);
    }
}

$skills = null;
if (array_key_exists('skills', $in)) {
    if (!is_array($in['skills']) || count($in['skills']) > 20) {
        json_error('List up to 20 skills.', 400);
    }
    $skills = $in['skills'];
}

$pdo = Database::pdo();

try {
    $pdo->beginTransaction();

    $pdo->prepare('UPDATE Users SET full_name = :n, headline = :h, location = :l, bio = :b WHERE user_id = :uid')
        ->execute([':n' => $fullName, ':h' => $headline, ':l' => $location, ':b' => $bio, ':uid' => $uid]);

    if ($skills !== null) {
        $ids = [];
        foreach ($skills as $s) {
            $ids[resolve_skill($pdo, (array) $s)] = true;
        }
        $pdo->prepare('DELETE FROM User_Skills WHERE user_id = :uid')->execute([':uid' => $uid]);
        foreach (array_keys($ids) as $skillId) {
            attach_user_skill($pdo, $uid, $skillId);
        }
    }

    if ($contacts !== null) {
        $pdo->prepare('DELETE FROM User_Contacts WHERE user_id = :uid')->execute([':uid' => $uid]);
        $ins = $pdo->prepare('INSERT INTO User_Contacts (user_id, method, value) VALUES (:uid, :m, :v)');
        foreach ($contacts as $m => $v) {
            $ins->execute([':uid' => $uid, ':m' => $m, ':v' => $v]);
        }
    }

    $pdo->commit();
    json_ok(['user_id' => $uid, 'updated' => true]);
} catch (Throwable $e) {
    fail_tx($pdo, $e, 'Could not save your profile; nothing was changed.');
}
