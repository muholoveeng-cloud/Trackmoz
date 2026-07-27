<?php
/**
 * API: Assumir disputa / passar a em_analise — admin
 * POST: disputa_id, csrf_token
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/disputas-helpers.php';

session_start();

$uid = (int)($_SESSION['user_id'] ?? 0);
if (!$uid || ($_SESSION['user_type'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Não autorizado.']);
    exit;
}

require_csrf_json();

$disputa_id = (int)($_POST['disputa_id'] ?? 0);
if ($disputa_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Disputa inválida.']);
    exit;
}

try {
    $conn = getConnection();
    $res = disputa_assumir($conn, $disputa_id, $uid);
    if (!$res['ok']) {
        echo json_encode(['success' => false, 'message' => implode(' ', $res['erros'] ?? ['Falha.'])]);
        exit;
    }
    echo json_encode(['success' => true, 'message' => 'Disputa em análise.']);
} catch (Throwable $e) {
    error_log('disputa-status: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno.']);
}
