<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
include_once('../config/database.php');
include_once('../includes/helpers.php');
include_once('../includes/regras-negocio.php');

require_csrf_json();

$missao_id = isset($_POST['missao_id']) ? (int)$_POST['missao_id'] : 0;
$entrega_id = isset($_POST['entrega_id']) ? (int)$_POST['entrega_id'] : 0;
$nota_geral = isset($_POST['nota_geral']) ? min(5, max(1, (int)$_POST['nota_geral'])) : null;
$nota_pontualidade = isset($_POST['nota_pontualidade']) ? min(5, max(1, (int)$_POST['nota_pontualidade'])) : null;
$nota_estado_carga = isset($_POST['nota_estado_carga']) ? min(5, max(1, (int)$_POST['nota_estado_carga'])) : null;
$nota_comunicacao = isset($_POST['nota_comunicacao']) ? min(5, max(1, (int)$_POST['nota_comunicacao'])) : null;
$comentario = trim($_POST['comentario'] ?? '');
$problema = trim($_POST['problema'] ?? '');

if ($missao_id <= 0 || $entrega_id <= 0 || !$nota_geral) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'Missão, entrega e nota geral são obrigatórios']);
    exit;
}

try {
    $avaliacaoCheck = validar_missao_pode_avaliar($conn, $missao_id);
    if (!$avaliacaoCheck['ok']) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => regras_erro_mensagem($avaliacaoCheck)]);
        exit;
    }

    $stmt = $conn->prepare("SELECT id FROM entregas_confirmacao WHERE id = ? AND missao_id = ?");
    $stmt->execute([$entrega_id, $missao_id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['ok'=>false, 'error'=>'Entrega não encontrada']);
        exit;
    }

    $conn->prepare("INSERT INTO avaliacoes_entrega
        (missao_id, entrega_id, nota_geral, nota_pontualidade, nota_estado_carga, nota_comunicacao, comentario, problema_reportado)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
         ->execute([$missao_id, $entrega_id, $nota_geral, $nota_pontualidade, $nota_estado_carga, $nota_comunicacao, $comentario ?: null, $problema ?: null]);

    echo json_encode(['ok'=>true, 'message'=>'Avaliação registada com sucesso. Obrigado!']);
} catch (PDOException $e) {
    if ((int)($e->errorInfo[1] ?? 0) === 1062) {
        http_response_code(409);
        echo json_encode(['ok'=>false, 'error'=>'Esta missão já foi avaliada.']);
        exit;
    }
    error_log('entrega-avaliar.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Erro interno']);
} catch (Throwable $e) {
    error_log('entrega-avaliar.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Erro interno']);
}
