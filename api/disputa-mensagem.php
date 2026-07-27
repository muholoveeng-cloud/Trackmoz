<?php
/**
 * API: Mensagem na disputa (partes + admin)
 * POST: disputa_id, mensagem, interno? (admin), csrf_token
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/disputas-helpers.php';

session_start();

$uid = (int)($_SESSION['user_id'] ?? 0);
$tipo = $_SESSION['user_type'] ?? '';

if (!$uid || !in_array($tipo, ['admin', 'empresa', 'transportador', 'caminhoneiro'], true)) {
    echo json_encode(['success' => false, 'message' => 'Não autorizado.']);
    exit;
}

require_csrf_json();

$disputa_id = (int)($_POST['disputa_id'] ?? 0);
$mensagem = trim($_POST['mensagem'] ?? '');
$interno = $tipo === 'admin' && !empty($_POST['interno']);

if ($disputa_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Disputa inválida.']);
    exit;
}

try {
    $conn = getConnection();
    $res = disputa_adicionar_mensagem($conn, $disputa_id, $uid, $tipo, $mensagem, $interno);
    if (!$res['ok']) {
        echo json_encode(['success' => false, 'message' => implode(' ', $res['erros'] ?? ['Falha.'])]);
        exit;
    }
    echo json_encode(['success' => true, 'message' => 'Mensagem enviada.']);
} catch (Throwable $e) {
    error_log('disputa-mensagem: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno.']);
}
