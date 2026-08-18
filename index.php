<?php
/**
 * index.php — health check / API root.
 *
 * Served for GET / so the platform (Railway/Fly) and uptime monitors get a 200,
 * and so hitting the bare API URL returns something friendly instead of a 404.
 * The real endpoints are the sibling *.php files (get_profile.php, etc.).
 */
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'service' => 'timedeo-api',
    'status'  => 'ok',
    'time'    => gmdate('c'),
], JSON_UNESCAPED_UNICODE);
