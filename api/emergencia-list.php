<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
include_once('../config/database.php');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false, 'error'=>'Não autenticado']);
    exit;
}

$uid = (int)$_SESSION['user_id'];
$utype = $_SESSION['user_type'] ?? '';

// Filtros
$status = $_GET['status'] ?? '';
$missao_id = isset($_GET['missao_id']) ? (int)$_GET['missao_id'] : 0;
$limite = isset($_GET['limite']) ? min((int)$_GET['limite'], 100) : 50;

$where = [];
$params = [];

if ($utype === 'caminhoneiro') {
    $where[] = 'e.caminhoneiro_id = :uid';
    $params[':uid'] = $uid;
} elseif ($utype === 'empresa') {
    $where[] = 'm.empresa_id = :uid';
    $params[':uid'] = $uid;
} // admin vê tudo

if ($status && in_array($status, ['aberta','em_atendimento','resolvida','cancelada'], true)) {
    $where[] = 'e.status = :status';
    $params[':status'] = $status;
}
if ($missao_id > 0) {
    $where[] = 'e.missao_id = :mid';
    $params[':mid'] = $missao_id;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $stmt = $conn->prepare(
        "SELECT e.*, m.titulo AS missao_titulo, m.status AS missao_status,
                u.nome AS motorista_nome, u.telefone AS motorista_telefone,
                adm.nome AS resolvido_por_nome
         FROM emergencias e
         JOIN missoes m ON e.missao_id = m.id
         JOIN usuarios u ON e.caminhoneiro_id = u.id
         LEFT JOIN usuarios adm ON e.resolvido_por = adm.id
         $whereSql
         ORDER BY e.data_criacao DESC
         LIMIT $limite"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok'=>true, 'emergencias'=>$rows]);
} catch (Throwable $e) {
    error_log('emergencia-list.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Erro interno']);
}
