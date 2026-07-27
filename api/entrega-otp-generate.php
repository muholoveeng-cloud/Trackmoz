<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
include_once('../config/app.php');
include_once('../config/database.php');
include_once('../includes/helpers.php');
include_once('../includes/otp-entrega.php');
include_once('../includes/sms-helpers.php');

require_csrf_json();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'] ?? '', ['admin', 'empresa'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acesso negado. Apenas empresa ou admin podem gerar códigos.']);
    exit;
}

$uid = (int)$_SESSION['user_id'];
$missao_id = isset($_POST['missao_id']) ? (int)$_POST['missao_id'] : 0;
$regenerar = !empty($_POST['regenerar']);
$telefone = trim($_POST['telefone'] ?? '');

if ($missao_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missão inválida']);
    exit;
}

try {
    $stmt = $conn->prepare('SELECT id, empresa_id, titulo FROM missoes WHERE id = ?');
    $stmt->execute([$missao_id]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Missão não encontrada']);
        exit;
    }

    if ($_SESSION['user_type'] === 'empresa' && (int)$missao['empresa_id'] !== $uid) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Sem permissão']);
        exit;
    }

    $result = otp_gerar_para_missao($conn, $missao_id, $uid, $regenerar);
    if (!$result['ok']) {
        http_response_code(400);
        echo json_encode($result);
        exit;
    }

    $smsResult = null;
    if ($telefone !== '') {
        $smsResult = otp_notificar_destinatario(
            $conn,
            $missao_id,
            $telefone,
            $result['codigo'],
            $result['expira_em'],
            (string)($missao['titulo'] ?? '')
        );
    }

    $response = [
        'ok'        => true,
        'codigo'    => $result['codigo'],
        'expira_em' => $result['expira_em'],
        'message'   => $smsResult && !empty($smsResult['enviado_automatico'])
            ? 'Código gerado e SMS enviado ao destinatário.'
            : 'Código gerado. Use WhatsApp/SMS ou partilhe manualmente.',
    ];
    if ($smsResult) {
        $response['sms'] = [
            'enviado_automatico' => !empty($smsResult['enviado_automatico']),
            'metodo'             => $smsResult['metodo'] ?? null,
            'whatsapp_url'       => $smsResult['whatsapp_url'] ?? null,
            'sms_url'            => $smsResult['sms_url'] ?? null,
            'instrucao'          => $smsResult['instrucao'] ?? null,
        ];
    }
    echo json_encode($response);
} catch (Throwable $e) {
    error_log('entrega-otp-generate.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Erro ao gerar código OTP.',
        'detail'=> $e->getMessage(),
    ]);
}
