<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
include_once('../config/database.php');
include_once('../includes/helpers.php');

require_csrf_json();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'] ?? '', ['admin','empresa'], true)) {
    http_response_code(403);
    echo json_encode(['ok'=>false, 'error'=>'Acesso negado']);
    exit;
}

$uid = (int)$_SESSION['user_id'];
$emergencia_id = isset($_POST['emergencia_id']) ? (int)$_POST['emergencia_id'] : 0;
$status = $_POST['status'] ?? '';
$resposta = trim($_POST['resposta'] ?? '');

$statusValidos = ['aberta','em_atendimento','resolvida','cancelada'];
if ($emergencia_id <= 0 || !in_array($status, $statusValidos, true)) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'Dados inválidos']);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT e.*, m.empresa_id FROM emergencias e JOIN missoes m ON e.missao_id = m.id WHERE e.id = ?");
    $stmt->execute([$emergencia_id]);
    $em = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$em) {
        http_response_code(404);
        echo json_encode(['ok'=>false, 'error'=>'Emergência não encontrada']);
        exit;
    }
    // Empresa só pode editar se for dona da missão; admin pode editar tudo
    if ($_SESSION['user_type'] === 'empresa' && (int)$em['empresa_id'] !== $uid) {
        http_response_code(403);
        echo json_encode(['ok'=>false, 'error'=>'Sem permissão']);
        exit;
    }

    $conn->prepare("UPDATE emergencias SET status = ?, resposta_admin = COALESCE(?, resposta_admin), resolvido_por = ?, data_atualizacao = NOW() WHERE id = ?")
         ->execute([$status, $resposta ?: null, $uid, $emergencia_id]);

    // Notificar motorista da atualização
    $conn->prepare("INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, lida) VALUES (?, 'info', ?, ?, 0)")
         ->execute([
             $em['caminhoneiro_id'],
             'Atualização na sua emergência #' . $emergencia_id,
             'Status alterado para: ' . $status . ($resposta ? ' | Resposta: ' . mb_substr($resposta,0,100) : '')
         ]);

    echo json_encode(['ok'=>true, 'message'=>'Emergência atualizada com sucesso.']);
} catch (Throwable $e) {
    error_log('emergencia-update.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Erro interno']);
}
