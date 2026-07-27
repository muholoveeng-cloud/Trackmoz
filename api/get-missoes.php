<?php
/**
 * API: Lista missões com filtros (para caminhoneiro, empresa, admin)
 * GET params:
 *   status - filtra por status (opcional: aberta, em_andamento, concluida, etc.)
 *   user_type - tipo de usuário (opcional, para ajustar query)
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
include_once('../config/database.php');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Não autenticado']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_type = $_SESSION['user_type'];
$status = isset($_GET['status']) ? $_GET['status'] : null;

try {
    $where = [];
    $params = [];

    // Filtro por usuário baseado no tipo
    if ($user_type === 'caminhoneiro') {
        $where[] = "m.caminhoneiro_id = :user_id";
        $params[':user_id'] = $user_id;
    } elseif ($user_type === 'empresa') {
        $where[] = "m.empresa_id = :user_id";
        $params[':user_id'] = $user_id;
    } elseif ($user_type === 'transportador') {
        $where[] = "m.transportador_id = :user_id";
        $params[':user_id'] = $user_id;
    }
    // Admin vê tudo (sem filtro de usuário)

    // Filtro por status
    if ($status) {
        $where[] = "m.status = :status";
        $params[':status'] = $status;
    }

    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT m.*, 
            u.nome AS caminhoneiro_nome,
            pe.nome_empresa,
            lo.latitude AS origem_lat, lo.longitude AS origem_lng,
            ld.latitude AS destino_lat, ld.longitude AS destino_lng
            FROM missoes m
            LEFT JOIN usuarios u ON m.caminhoneiro_id = u.id
            LEFT JOIN perfil_empresa pe ON m.empresa_id = pe.usuario_id
            LEFT JOIN locais lo ON m.local_origem_id = lo.id
            LEFT JOIN locais ld ON m.local_destino_id = ld.id
            $whereClause
            ORDER BY m.data_criacao DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $missoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'missoes' => $missoes]);

} catch (PDOException $e) {
    error_log('get-missoes: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erro interno']);
}
