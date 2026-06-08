<?php
/**
 * connection.php — Opens the MySQLi database connection.
 * Reads credentials from config.php (which reads from env vars).
 * Included at the top of every page that needs the database.
 */
require_once __DIR__ . '/config.php';

$con = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if (!$con) {
    die("Database connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($con, DB_CHARSET);