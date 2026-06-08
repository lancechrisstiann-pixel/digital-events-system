<?php
/*
|--------------------------------------------------------------------------
| config.php  — Centralised environment configuration
|--------------------------------------------------------------------------
*/

define('DB_HOST',   getenv('DB_HOST')   ?: 'localhost');
define('DB_USER',   getenv('DB_USER')   ?: 'root');
define('DB_PASS',   getenv('DB_PASS')   ?: '');
define('DB_NAME',   getenv('DB_NAME')   ?: 'digital_events_system');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME',  'Digital Events System');
define('APP_ENV',   getenv('APP_ENV')   ?: 'development');  // production | development