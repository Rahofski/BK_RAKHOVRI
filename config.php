<?php
// ============================================================
// config.php  —  Application Configuration
// ============================================================
// For PRODUCTION HOSTING:
//   1. Change DB_HOST, DB_NAME, DB_USER, DB_PASS to hosting values
//   2. Change BASE_URL to '' if app is at domain root,
//      or to '/subfolder' if deployed in a subfolder
//   3. Set APP_ENV to 'production' to suppress PHP error output
// ============================================================

// --- Database ---
define('DB_HOST',    'localhost');
define('DB_NAME',    'bk_rakhovri');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_CHARSET', 'utf8mb4');

// --- Application ---
define('APP_NAME',   'BK RakhovRI');
define('APP_ENV',    'development'); // 'development' | 'production'

// --- URL ---
// Set to '' if deployed at domain root (e.g. https://example.com/)
// Set to '/BK_RakhovRI' for local XAMPP subfolder
define('BASE_URL',   '');

// --- Session ---
define('SESSION_LIFETIME', 7200); // seconds (2 hours)
