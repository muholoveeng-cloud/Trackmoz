<?php
session_start();
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/analytics-helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$data = tmz_analytics_dashboard_payload($conn);
echo json_encode([
    'ok' => true,
    'agora' => date('Y-m-d H:i:s'),
    'data' => $data,
], JSON_UNESCAPED_UNICODE);
