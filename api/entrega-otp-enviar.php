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
    echo json_encode(['ok' => false, 'error' => 'Acesso negado']);
    exit;
}

$uid = (int)$_SESSION['user_id'];
$missao_id = isset($_POST['missao_id']) ? (int)$_POST['missao_id'] : 0;
$telefone = trim($_POST['telefone'] ?? '');
$codigo = trim($_POST['codigo'] ?? '');

if ($missao_id <= 0 || $telefone === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missão e telefone são obrigatórios']);
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

    $info = otp_info_missao($conn, $missao_id);
    if (!$info || ($info['usado'] ?? false)) {
        echo json_encode(['ok' => false, 'error' => 'Não há código OTP activo para esta missão. Gere um código primeiro.']);
        exit;
    }

    if ($codigo === '') {
        $codigo = (string)(otp_codigo_texto_activo($conn, $missao_id) ?? '');
    }
    if ($codigo === '' || !preg_match('/^\d{6}$/', $codigo)) {
        echo json_encode([
            'ok'    => false,
            'error' => 'Código OTP não disponível nesta sessão. Clique em «Gerar / Regenerar código» e depois envie.',
        ]);
        exit;
    }

    $expira = $info['expira_em'] ?? date('Y-m-d H:i:s', strtotime('+48 hours'));
    $result = otp_notificar_destinatario(
        $conn,
        $missao_id,
        $telefone,
        $codigo,
        $expira,
        (string)($missao['titulo'] ?? '')
    );

    if (!$result['ok'] && empty($result['whatsapp_url'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Erro ao preparar envio']);
        exit;
    }

    registrar_log(
        $conn,
        $uid,
        'otp_enviar',
        'missao',
        $missao_id,
        'OTP enviado/preparado para ' . ($result['telefone'] ?? $telefone)
            . ' via ' . ($result['metodo'] ?? 'link')
    );

    echo json_encode([
        'ok'                 => true,
        'enviado_automatico' => !empty($result['enviado_automatico']),
        'metodo'             => $result['metodo'] ?? 'link',
        'whatsapp_url'       => $result['whatsapp_url'] ?? null,
        'sms_url'            => $result['sms_url'] ?? null,
        'instrucao'          => $result['instrucao'] ?? null,
        'message'            => !empty($result['enviado_automatico'])
            ? 'SMS enviado automaticamente ao destinatário.'
            : 'Abra WhatsApp ou a app SMS para enviar o código.',
    ]);
} catch (Throwable $e) {
    error_log('entrega-otp-enviar.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erro interno']);
}
