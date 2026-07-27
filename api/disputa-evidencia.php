<?php
/**
 * API: Anexar evidência à disputa
 * POST multipart: disputa_id, ficheiro, descricao?, csrf_token
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
$descricao = trim($_POST['descricao'] ?? '');

if ($disputa_id <= 0 || empty($_FILES['ficheiro'])) {
    echo json_encode(['success' => false, 'message' => 'Dados incompletos.']);
    exit;
}

try {
    $conn = getConnection();
    $res = disputa_adicionar_evidencia($conn, $disputa_id, $uid, $tipo, $_FILES['ficheiro'], $descricao);
    if (!$res['ok']) {
        echo json_encode(['success' => false, 'message' => implode(' ', $res['erros'] ?? ['Falha no upload.'])]);
        exit;
    }
    echo json_encode(['success' => true, 'message' => 'Evidência anexada.', 'caminho' => $res['caminho'] ?? null]);
} catch (Throwable $e) {
    error_log('disputa-evidencia: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno.']);
}
