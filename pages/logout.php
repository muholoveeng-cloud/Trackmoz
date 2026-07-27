<?php
session_start();
include_once('../config/app.php');
include_once('../config/database.php');

$userId = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

try {
    require_once __DIR__ . '/../includes/analytics-helpers.php';
    if (isset($conn) && $conn instanceof PDO) {
        tmz_analytics_track($conn, 'logout', $userId, '/pages/logout.php');
    }
} catch (Throwable $e) { /* ignore */ }

$_SESSION = array();

if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

session_destroy();

header('Location: ' . BASE_URL . '/pages/login.php');
exit;
