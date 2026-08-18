<?php
/**
 * logout.php  —  POST  —  sign the current user out.
 *
 * Destroys the server-side session and expires the session cookie. Safe to call
 * even when nobody is logged in (idempotent) — always returns success so the
 * client can clear its state unconditionally.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_method('POST');

logout_user();

json_ok(['loggedOut' => true]);
