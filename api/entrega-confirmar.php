<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
include_once('../config/app.php');
include_once('../config/database.php');
include_once('../includes/helpers.php');
include_once('../includes/regras-negocio.php');
include_once('../includes/otp-entrega.php');

require_csrf_json();

$missao_id = isset($_POST['missao_id']) ? (int)$_POST['missao_id'] : 0;
$metodo = $_POST['metodo'] ?? 'otp';
$nome_recebedor = trim($_POST['nome_recebedor'] ?? '');
$documento_recebedor = trim($_POST['documento_recebedor'] ?? '');
$telefone_recebedor = trim($_POST['telefone_recebedor'] ?? '');
$estado_carga = $_POST['estado_carga'] ?? 'sem_danos';
$observacoes = trim($_POST['observacoes'] ?? '');
$otp = trim($_POST['otp'] ?? '');
$latitude = isset($_POST['latitude']) ? (float)$_POST['latitude'] : null;
$longitude = isset($_POST['longitude']) ? (float)$_POST['longitude'] : null;

$metodosValidos = ['otp', 'destinatario_cadastrado', 'manual_assistida'];
$estadosValidos = ['sem_danos', 'com_danos', 'parcial', 'recusada'];

if ($missao_id <= 0 || !in_array($metodo, $metodosValidos, true) || !in_array($estado_carga, $estadosValidos, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Dados inválidos']);
    exit;
}

if ($nome_recebedor === '' || $telefone_recebedor === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Nome e telefone do recebedor são obrigatórios']);
    exit;
}

$uid = (int)($_SESSION['user_id'] ?? 0);
$utype = $_SESSION['user_type'] ?? '';

try {
    $stmt = $conn->prepare('SELECT m.*, u.nome AS motorista_nome FROM missoes m LEFT JOIN usuarios u ON m.caminhoneiro_id = u.id WHERE m.id = ?');
    $stmt->execute([$missao_id]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Missão não encontrada']);
        exit;
    }

    $permitido = ($utype === 'admin')
        || (int)$missao['caminhoneiro_id'] === $uid
        || (int)$missao['empresa_id'] === $uid
        || $metodo === 'destinatario_cadastrado';

    if (!$permitido) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Sem permissão']);
        exit;
    }

    if ($latitude === null || $longitude === null) {
        echo json_encode(['ok' => false, 'error' => 'Localização GPS obrigatória para confirmar entrega']);
        exit;
    }

    $proximidade = validar_proximidade_destino($conn, $missao_id, $latitude, $longitude);
    if (!$proximidade['ok']) {
        registrar_log($conn, $uid, 'entrega_gps_distante', 'missao', $missao_id,
            'Tentativa de entrega longe do destino (' . ($proximidade['distancia_m'] ?? '?') . 'm)');
        notificar_usuario(
            $conn,
            (int)$missao['empresa_id'],
            'alerta',
            'Alerta GPS — Missão #' . $missao_id,
            'Motorista tentou confirmar entrega a ' . ($proximidade['distancia_m'] ?? '?') . 'm do destino.',
            BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id
        );
        echo json_encode(['ok' => false, 'error' => $proximidade['error']]);
        exit;
    }

    $temFoto = !empty($_FILES['foto_carga']) && $_FILES['foto_carga']['error'] === UPLOAD_ERR_OK;
    $temAssinatura = !empty($_FILES['assinatura']) && $_FILES['assinatura']['error'] === UPLOAD_ERR_OK;

    if (!$temFoto && !$temAssinatura) {
        echo json_encode(['ok' => false, 'error' => 'Anexe foto da carga ou assinatura do recebedor']);
        exit;
    }

    if ($metodo === 'otp') {
        if ($otp === '') {
            echo json_encode(['ok' => false, 'error' => 'Código OTP obrigatório']);
            exit;
        }
        $otpCheck = otp_validar_codigo($conn, $missao_id, $otp, $uid, $latitude, $longitude);
        if (!$otpCheck['ok']) {
            echo json_encode(['ok' => false, 'error' => $otpCheck['error'] ?? 'Código OTP inválido']);
            exit;
        }
    }

    $stmt = $conn->prepare('SELECT COUNT(*) FROM entregas_confirmacao WHERE missao_id = ?');
    $stmt->execute([$missao_id]);
    if ((int)$stmt->fetchColumn() > 0) {
        echo json_encode(['ok' => false, 'error' => 'Esta missão já possui entrega confirmada']);
        exit;
    }

    $assinatura_url = $foto_carga_url = $foto_doc_url = null;
    $uploadDir = __DIR__ . '/../uploads/entregas/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    foreach (['assinatura' => 'assinatura_url', 'foto_carga' => 'foto_carga_url', 'foto_doc' => 'foto_doc_url'] as $field => $var) {
        if (!empty($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION) ?: 'jpg');
            $safe = bin2hex(random_bytes(8)) . '_' . $field . '.' . $ext;
            if (move_uploaded_file($_FILES[$field]['tmp_name'], $uploadDir . $safe)) {
                $$var = BASE_URL . '/uploads/entregas/' . $safe;
            }
        }
    }

    if (!$assinatura_url && !$foto_carga_url) {
        echo json_encode(['ok' => false, 'error' => 'Falha ao guardar foto ou assinatura']);
        exit;
    }

    otp_entrega_bootstrap($conn);

    $statusEntrega = match ($estado_carga) {
        'recusada' => 'entrega_recusada',
        'com_danos', 'parcial' => 'entrega_divergencia',
        default => 'entrega_confirmada',
    };

    $stmt = $conn->prepare("INSERT INTO entregas_confirmacao
        (missao_id, motorista_id, empresa_id, metodo, nome_recebedor, documento_recebedor, telefone_recebedor,
         assinatura_url, foto_carga_url, foto_doc_url, otp_usado, latitude, longitude, estado_carga, observacoes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $missao_id,
        $missao['caminhoneiro_id'],
        $missao['empresa_id'],
        $metodo,
        $nome_recebedor,
        $documento_recebedor ?: null,
        $telefone_recebedor,
        $assinatura_url,
        $foto_carga_url,
        $foto_doc_url,
        $metodo === 'otp' ? '******' : null,
        $latitude,
        $longitude,
        $estado_carga,
        $observacoes ?: null,
    ]);
    $entrega_id = (int)$conn->lastInsertId();

    if ($metodo === 'otp') {
        otp_marcar_usado($conn, $missao_id, $nome_recebedor);
    }

    $novoStatus = $statusEntrega === 'entrega_confirmada' ? 'entrega_confirmada' : 'aguardando_confirmacao';
    $conn->prepare("UPDATE missoes SET status = ?, status_entrega = ?, ultima_atualizacao = NOW() WHERE id = ?")
         ->execute([$novoStatus, $statusEntrega, $missao_id]);

    registrar_log($conn, $uid, 'entrega_confirmar', 'missao', $missao_id,
        'Entrega confirmada — recebedor: ' . $nome_recebedor,
        ['ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'gps' => [$latitude, $longitude]]);

    notificar_usuario(
        $conn,
        (int)$missao['empresa_id'],
        'info',
        'Entrega confirmada — Missão #' . $missao_id,
        'Carga entregue por ' . ($missao['motorista_nome'] ?? 'motorista') . '. Recebedor: ' . $nome_recebedor,
        BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id
    );

    echo json_encode([
        'ok'         => true,
        'entrega_id' => $entrega_id,
        'message'    => 'Entrega confirmada com sucesso.',
    ]);
} catch (Throwable $e) {
    error_log('entrega-confirmar.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erro interno ao confirmar entrega']);
}
