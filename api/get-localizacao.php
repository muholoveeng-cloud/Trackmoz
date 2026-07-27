<?php
/**
 * API: Localização em tempo real do motorista para uma missão
 * GET: missao_id, historico=1, rota=1
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

include_once('../config/database.php');
include_once('../includes/geocode.php');
include_once('../includes/localizacao-service.php');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Não autenticado']);
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? '';
$missao_id = isset($_GET['missao_id']) ? (int)$_GET['missao_id'] : 0;
$com_hist  = isset($_GET['historico']) && $_GET['historico'] === '1';
$com_rota  = isset($_GET['rota']) && $_GET['rota'] === '1';

if ($missao_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missao_id inválido']);
    exit;
}

try {
    $stmt = $conn->prepare(
        "SELECT m.id, m.titulo, m.status, m.status_viagem, m.origem, m.destino,
                m.caminhoneiro_id, m.transportador_id, m.empresa_id,
                m.distancia_km, m.tempo_estimado_min,
                u.nome AS motorista_nome, u.telefone AS motorista_telefone,
                COALESCE(pc.ultima_localizacao_lat, pc.latitude) AS lat,
                COALESCE(pc.ultima_localizacao_lng, pc.longitude) AS lng,
                pc.ultima_atualizacao_local AS atualizado_em,
                pc.disponibilidade,
                lo.nome AS origem_nome, lo.endereco AS origem_endereco,
                lo.latitude AS origem_lat, lo.longitude AS origem_lng,
                ld.nome AS destino_nome, ld.endereco AS destino_endereco,
                ld.latitude AS destino_lat, ld.longitude AS destino_lng
         FROM missoes m
         LEFT JOIN usuarios u ON m.caminhoneiro_id = u.id
         LEFT JOIN perfil_caminhoneiro pc ON m.caminhoneiro_id = pc.usuario_id
         LEFT JOIN locais lo ON m.local_origem_id = lo.id
         LEFT JOIN locais ld ON m.local_destino_id = ld.id
         WHERE m.id = :mid"
    );
    $stmt->execute([':mid' => $missao_id]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Missão não encontrada']);
        exit;
    }

    $missao = enriquecer_missao_mapa($missao);

    if (!tms_pode_ver_missao($conn, $user_id, $user_type, $missao)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acesso negado']);
        exit;
    }

    $resposta = [
        'ok'      => true,
        'missao'  => [
            'id'            => (int)$missao['id'],
            'titulo'        => $missao['titulo'],
            'status'        => $missao['status'],
            'status_viagem' => $missao['status_viagem'] ?? null,
            'origem'        => $missao['origem'],
            'destino'       => $missao['destino'],
            'distancia_km'  => $missao['distancia_km'] ? (float)$missao['distancia_km'] : null,
            'tempo_min'     => $missao['tempo_estimado_min'] ? (int)$missao['tempo_estimado_min'] : null,
        ],
        'motorista' => [
            'nome'     => $missao['motorista_nome'] ?? null,
            'telefone' => $missao['motorista_telefone'] ?? null,
        ],
        'localizacao' => null,
        'eta'         => null,
        'gps_offline' => null,
        'pontos_rota' => null,
        'checkpoints' => tms_listar_checkpoints($conn, $missao_id),
        'origem'  => $missao['origem_lat'] ? [
            'nome' => $missao['origem_nome'] ?? null,
            'endereco' => $missao['origem_endereco'] ?? $missao['origem'],
            'lat' => (float)$missao['origem_lat'],
            'lng' => (float)$missao['origem_lng'],
        ] : null,
        'destino' => $missao['destino_lat'] ? [
            'nome' => $missao['destino_nome'] ?? null,
            'endereco' => $missao['destino_endereco'] ?? $missao['destino'],
            'lat' => (float)$missao['destino_lat'],
            'lng' => (float)$missao['destino_lng'],
        ] : null,
        'rota' => null,
    ];

    if ($missao['lat'] && $missao['lng']) {
        $resposta['localizacao'] = [
            'lat'           => (float)$missao['lat'],
            'lng'           => (float)$missao['lng'],
            'atualizado_em' => $missao['atualizado_em'],
        ];
        $resposta['eta'] = tms_calcular_eta($conn, $missao_id, (float)$missao['lat'], (float)$missao['lng']);
    }

    $resposta['gps_offline'] = tms_detectar_gps_offline($conn, $missao_id);

    if ($com_hist) {
        $resposta['pontos_rota'] = tms_historico_missao($conn, $missao_id);
    }

    if ($com_rota && tms_gps_tabelas_prontas($conn)) {
        $stmtR = $conn->prepare(
            'SELECT distance_km, duration_min, route_geojson FROM mission_routes WHERE mission_id = :mid'
        );
        $stmtR->execute([':mid' => $missao_id]);
        $rota = $stmtR->fetch(PDO::FETCH_ASSOC);
        if ($rota) {
            $resposta['rota'] = [
                'distancia_km' => $rota['distance_km'] ? (float)$rota['distance_km'] : null,
                'duration_min' => $rota['duration_min'] ? (int)$rota['duration_min'] : null,
                'geojson' => $rota['route_geojson'] ? json_decode($rota['route_geojson'], true) : null,
            ];
        }
    }

    echo json_encode($resposta);

} catch (Throwable $e) {
    error_log('get-localizacao.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erro interno']);
}
