<?php
/**
 * Validar OTP de entrega — motorista (caminhoneiro) ou transportadora responsável.
 */
header('Content-Type: application/json; charset=utf-8');
session_start();
include_once('../config/app.php');
include_once('../config/database.php');
include_once('../includes/helpers.php');
include_once('../includes/otp-entrega.php');

$missao_id = isset($_POST['missao_id']) ? (int)$_POST['missao_id'] : 0;
$codigo = trim($_POST['codigo'] ?? '');
$uid = (int)($_SESSION['user_id'] ?? 0);
$userType = (string)($_SESSION['user_type'] ?? '');

if ($missao_id <= 0 || strlen(preg_replace('/\D/', '', $codigo)) !== 6) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Dados inválidos']);
    exit;
}

if ($uid <= 0 || !in_array($userType, ['caminhoneiro', 'transportador'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acesso negado']);
    exit;
}

try {
    if ($userType === 'caminhoneiro') {
        $stmt = $conn->prepare('SELECT id FROM missoes WHERE id = ? AND caminhoneiro_id = ?');
        $stmt->execute([$missao_id, $uid]);
    } else {
        $stmt = $conn->prepare('SELECT id FROM missoes WHERE id = ? AND transportador_id = ?');
        $stmt->execute([$missao_id, $uid]);
    }
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Missão não encontrada']);
        exit;
    }

    $lat = isset($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $lng = isset($_POST['longitude']) ? (float)$_POST['longitude'] : null;

    $result = otp_validar_codigo($conn, $missao_id, $codigo, $uid, $lat, $lng);
    if (!$result['ok']) {
        echo json_encode($result);
        exit;
    }

    otp_marcar_usado($conn, $missao_id, $userType . ':' . $uid);

    try {
        $conn->prepare("UPDATE missoes SET status_entrega = 'codigo_validado', ultima_atualizacao = NOW() WHERE id = ?")
             ->execute([$missao_id]);
    } catch (Throwable $e) {
        $conn->prepare("UPDATE missoes SET status_entrega = 'codigo_validado' WHERE id = ?")
             ->execute([$missao_id]);
    }

    registrar_log($conn, $uid, 'otp_validar', 'missao', $missao_id, 'Código OTP validado por ' . $userType);

    echo json_encode(['ok' => true, 'message' => 'Código validado com sucesso']);
} catch (Throwable $e) {
    error_log('entrega-otp-verify.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erro interno']);
}
