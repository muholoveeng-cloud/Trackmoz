<?php
/**
 * API: Abrir disputa — RN54, RN55
 * POST: missao_id, motivo, categoria?, prioridade?, csrf_token
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/disputas-helpers.php';

session_start();

$uid = (int)($_SESSION['user_id'] ?? 0);
$tipo = $_SESSION['user_type'] ?? '';

if (!$uid || !in_array($tipo, ['empresa', 'transportador', 'caminhoneiro'], true)) {
    echo json_encode(['success' => false, 'message' => 'Não autorizado.']);
    exit;
}

require_csrf_json();

$missao_id = (int)($_POST['missao_id'] ?? 0);
$motivo = trim($_POST['motivo'] ?? '');
$categoria = trim($_POST['categoria'] ?? 'outro');
$prioridade = trim($_POST['prioridade'] ?? 'normal');

if ($missao_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missão inválida.']);
    exit;
}

try {
    $conn = getConnection();
    $resultado = disputa_criar($conn, $missao_id, $uid, $motivo, $categoria, $prioridade);

    if (!$resultado['ok']) {
        echo json_encode([
            'success' => false,
            'message' => implode(' ', $resultado['erros'] ?? ['Não foi possível abrir a disputa.']),
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Disputa registada. A administração irá analisar o caso.',
        'disputa_id' => $resultado['disputa_id'] ?? null,
        'redirect' => disputa_url_detalhe((int)$resultado['disputa_id'], $tipo),
    ]);
} catch (Throwable $e) {
    error_log('disputa-criar: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno.']);
}
