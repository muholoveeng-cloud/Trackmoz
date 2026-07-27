<?php
/**
 * API: Lista missões activas com GPS do motorista (para admin/mapa-geral)
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
include_once('../config/database.php');
include_once('../includes/geocode.php');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acesso negado']);
    exit;
}

try {
    $stmt = $conn->prepare(
        "SELECT m.id, m.titulo, m.status, m.origem, m.destino,
                m.caminhoneiro_id,
                u.nome AS motorista_nome,
                pe.nome_empresa,
                COALESCE(pc.ultima_localizacao_lat, pc.latitude) AS lat,
                COALESCE(pc.ultima_localizacao_lng, pc.longitude) AS lng,
                pc.ultima_atualizacao_local AS atualizado_em,
                lo.latitude  AS origem_lat, lo.longitude AS origem_lng,
                ld.latitude  AS destino_lat, ld.longitude AS destino_lng
         FROM missoes m
         LEFT JOIN usuarios u            ON m.caminhoneiro_id = u.id
         LEFT JOIN perfil_empresa pe     ON m.empresa_id = pe.usuario_id
         LEFT JOIN perfil_caminhoneiro pc ON m.caminhoneiro_id = pc.usuario_id
         LEFT JOIN locais lo ON m.local_origem_id  = lo.id
         LEFT JOIN locais ld ON m.local_destino_id = ld.id
         WHERE m.status IN ('aberta','aceita','em_andamento','em_transito','em_entrega','aguardando_confirmacao','emergencia')"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $missoes = [];
    foreach ($rows as $row) {
        $row = enriquecer_missao_mapa($row);
        $missoes[] = [
            'id'             => (int)$row['id'],
            'titulo'         => $row['titulo'],
            'status'         => $row['status'],
            'origem'         => $row['origem'],
            'destino'        => $row['destino'],
            'caminhoneiro_id'=> $row['caminhoneiro_id'] ? (int)$row['caminhoneiro_id'] : null,
            'motorista_nome' => $row['motorista_nome'],
            'nome_empresa'   => $row['nome_empresa'],
            'lat'            => $row['lat'] ? (float)$row['lat'] : null,
            'lng'            => $row['lng'] ? (float)$row['lng'] : null,
            'atualizado_em'  => $row['atualizado_em'],
            'origem_lat'     => $row['origem_lat'] ? (float)$row['origem_lat'] : null,
            'origem_lng'     => $row['origem_lng'] ? (float)$row['origem_lng'] : null,
            'destino_lat'    => $row['destino_lat'] ? (float)$row['destino_lat'] : null,
            'destino_lng'    => $row['destino_lng'] ? (float)$row['destino_lng'] : null,
        ];
    }

    echo json_encode(['ok' => true, 'missoes' => $missoes]);

} catch (Throwable $e) {
    error_log('get-missoes-ativas: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erro interno']);
}
