<?php
/**
 * config.php — database credentials & app constants.
 *
 * Kept separate from db.php so connection details live in one place and can be
 * swapped per machine without touching the connection logic. These are the
 * stock XAMPP defaults (root / no password). Change them if your MySQL differs.
 */

return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'timedeo',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
    // Where the React dev server runs. '*' keeps CORS simple for local lab work;
    // tighten to 'http://localhost:5173' if you prefer an explicit origin.
    'cors_allow_origin' => '*',
];
