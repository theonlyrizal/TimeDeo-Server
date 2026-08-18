<?php
/**
 * config.example.php — reference for the environment variables the API reads.
 *
 * You do NOT normally copy this over config.php — the committed config.php already
 * reads these env vars (with local XAMPP defaults). This file just documents what
 * to set in your host's environment (Railway → service → Variables). Never put
 * real credentials in a committed file.
 *
 *   DB_HOST      database host          (Railway: ${{MySQL.MYSQLHOST}})
 *   DB_PORT      database port          (Railway: ${{MySQL.MYSQLPORT}})     default 3306
 *   DB_NAME      database name          (Railway: ${{MySQL.MYSQLDATABASE}})
 *   DB_USER      database user          (Railway: ${{MySQL.MYSQLUSER}})
 *   DB_PASS      database password      (Railway: ${{MySQL.MYSQLPASSWORD}})
 *   CORS_ORIGIN  allowed browser origin — '*' for same-origin/dev, or your exact
 *                Vercel URL (https://your-app.vercel.app) for direct cross-origin
 *   APP_DEBUG    '1' to include error detail in responses; leave '0' in production
 */
return [
    'db' => [
        'host'    => getenv('DB_HOST') ?: '127.0.0.1',
        'port'    => (int) (getenv('DB_PORT') ?: 3306),
        'name'    => getenv('DB_NAME') ?: 'timedeo',
        'user'    => getenv('DB_USER') ?: 'CHANGE_ME',
        'pass'    => getenv('DB_PASS') ?: 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    'cors_allow_origin' => getenv('CORS_ORIGIN') ?: 'https://your-app.vercel.app',
    'debug'             => (getenv('APP_DEBUG') ?: '0') === '1',
];
