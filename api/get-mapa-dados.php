<?php
/**
 * API: Dados completos para mapas TMS (missões, motoristas, viaturas)
 * GET: scope=admin|empresa|transportador|operacoes
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

include_once(__DIR__ . '/../config/database.php');
include_once(__DIR__ . '/../config/app.php');
include_once(__DIR__ . '/../includes/geocode.php');
include_once(__DIR__ . '/../includes/localizacao-service.php');
include_once(__DIR__ . '/../includes/ops-automation-helpers.php');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Não autenticado']);
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? '';
$scope     = $_GET['scope'] ?? $user_type;

// Evitar bloquear outras páginas enquanto a query do mapa corre.
session_write_close();

$statusAtivos = "'aberta','aceita','em_andamento','em_transito','em_entrega','aguardando_confirmacao','emergencia'";

function tms_mapa_estado_operacional(string $status, ?string $statusViagem, ?string $atualizadoEm): string
{
    if ($status === 'emergencia') {
        return 'emergencia';
    }

    $gpsFresco = false;
    if ($atualizadoEm) {
        $ts = strtotime($atualizadoEm);
        if ($ts !== false) {
            $gpsFresco = (time() - $ts) <= OPS_GPS_OFFLINE_SEG;
        }
    }

    if (in_array($statusViagem ?? '', ['aguardando_recolha', 'a_caminho_recolha', 'coleta'], true)) {
        return $gpsFresco ? 'em_recolha' : 'offline';
    }
    if (in_array($statusViagem ?? '', ['entrega', 'em_entrega', 'a_caminho_entrega'], true)
        || in_array($status, ['em_transito', 'em_andamento', 'em_entrega'], true)) {
        return $gpsFresco ? 'em_transito' : 'offline';
    }
    if ($status === 'aberta') {
        return 'parado';
    }
    if (!$gpsFresco && $atualizadoEm === null) {
        // Sem GPS nunca enviado — parado na origem (não “offline” cinzento)
        return 'parado';
    }
    if (!$gpsFresco) {
        return 'offline';
    }
    return 'parado';
}

try {
    $whereMissao = "m.status IN ($statusAtivos)";
    $params      = [];

    if ($scope === 'empresa' || ($scope !== 'admin' && $scope !== 'operacoes' && $user_type === 'empresa')) {
        $whereMissao .= ' AND m.empresa_id = :eid';
        $params[':eid'] = $user_id;
    } elseif ($scope === 'transportador' || $user_type === 'transportador') {
        $whereMissao .= ' AND m.transportador_id = :tid';
        $params[':tid'] = $user_id;
    } elseif ($user_type !== 'admin' && $scope !== 'operacoes') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acesso negado']);
        exit;
    }

    // Colunas opcionais (esquemas antigos)
    $missoesCols = $conn->query('SHOW COLUMNS FROM missoes')->fetchAll(PDO::FETCH_COLUMN);
    $hasStatusViagem = in_array('status_viagem', $missoesCols, true);
    $hasDistKm = in_array('distancia_km', $missoesCols, true);
    $hasVeiculo = in_array('veiculo_id', $missoesCols, true);
    $hasTransportador = in_array('transportador_id', $missoesCols, true);
    $hasPrazo = in_array('prazo_entrega', $missoesCols, true);

    $selStatusViagem = $hasStatusViagem ? 'm.status_viagem' : 'NULL AS status_viagem';
    $selDist = $hasDistKm ? 'm.distancia_km' : 'NULL AS distancia_km';
    $selVeiculo = $hasVeiculo ? 'm.veiculo_id' : 'NULL AS veiculo_id';
    $selTranspId = $hasTransportador ? 'm.transportador_id' : 'NULL AS transportador_id';
    $selPrazo = $hasPrazo ? 'm.prazo_entrega' : 'NULL AS prazo_entrega';

    $joinTransp = '';
    $selTranspNome = 'NULL AS transportador_nome';
    try {
        $conn->query('SELECT 1 FROM perfil_transportador LIMIT 1');
        if ($hasTransportador) {
            $joinTransp = 'LEFT JOIN perfil_transportador pt ON m.transportador_id = pt.usuario_id';
            $selTranspNome = 'pt.nome_empresa AS transportador_nome';
        }
    } catch (Throwable $e) { /* tabela pode não existir */ }

    $sql = "SELECT m.id, m.titulo, m.status, m.origem, m.destino,
                   m.caminhoneiro_id, m.empresa_id,
                   $selTranspId, $selVeiculo, $selDist, $selStatusViagem, $selPrazo,
                   u_mot.nome AS motorista_nome,
                   u_mot.telefone AS motorista_telefone,
                   pe.nome_empresa,
                   $selTranspNome,
                   COALESCE(pc.ultima_localizacao_lat, pc.latitude) AS lat,
                   COALESCE(pc.ultima_localizacao_lng, pc.longitude) AS lng,
                   pc.ultima_atualizacao_local AS atualizado_em,
                   lo.latitude AS origem_lat, lo.longitude AS origem_lng,
                   ld.latitude AS destino_lat, ld.longitude AS destino_lng
            FROM missoes m
            LEFT JOIN usuarios u_mot ON m.caminhoneiro_id = u_mot.id
            LEFT JOIN perfil_empresa pe ON m.empresa_id = pe.usuario_id
            $joinTransp
            LEFT JOIN perfil_caminhoneiro pc ON m.caminhoneiro_id = pc.usuario_id
            LEFT JOIN locais lo ON m.local_origem_id = lo.id
            LEFT JOIN locais ld ON m.local_destino_id = ld.id
            WHERE $whereMissao
            ORDER BY m.data_criacao DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $missoes = [];
    $opsUrl = (defined('BASE_URL') ? BASE_URL : '') . '/pages/shared/centro-operacoes.php';
    foreach ($rows as $row) {
        $enriquecida = enriquecer_missao_mapa($row);
        $estado = tms_mapa_estado_operacional(
            $enriquecida['status'],
            $enriquecida['status_viagem'] ?? null,
            $enriquecida['atualizado_em'] ?? null
        );

        $item = [
            'id'              => (int)$enriquecida['id'],
            'titulo'          => $enriquecida['titulo'],
            'status'          => $enriquecida['status'],
            'status_viagem'   => $enriquecida['status_viagem'] ?? null,
            'estado_mapa'     => $estado,
            'origem'          => $enriquecida['origem'],
            'destino'         => $enriquecida['destino'],
            'caminhoneiro_id' => $enriquecida['caminhoneiro_id'] ? (int)$enriquecida['caminhoneiro_id'] : null,
            'empresa_id'      => $enriquecida['empresa_id'] ? (int)$enriquecida['empresa_id'] : null,
            'transportador_id'=> !empty($enriquecida['transportador_id']) ? (int)$enriquecida['transportador_id'] : null,
            'motorista_nome'  => $enriquecida['motorista_nome'],
            'motorista_telefone' => $enriquecida['motorista_telefone'] ?? null,
            'nome_empresa'    => $enriquecida['nome_empresa'],
            'transportador'   => $enriquecida['transportador_nome'] ?? null,
            'prazo_entrega'   => $enriquecida['prazo_entrega'] ?? null,
            'lat'             => $enriquecida['lat'] ? (float)$enriquecida['lat'] : null,
            'lng'             => $enriquecida['lng'] ? (float)$enriquecida['lng'] : null,
            'atualizado_em'   => $enriquecida['atualizado_em'],
            'origem_lat'      => $enriquecida['origem_lat'] ? (float)$enriquecida['origem_lat'] : null,
            'origem_lng'      => $enriquecida['origem_lng'] ? (float)$enriquecida['origem_lng'] : null,
            'destino_lat'     => $enriquecida['destino_lat'] ? (float)$enriquecida['destino_lat'] : null,
            'destino_lng'     => $enriquecida['destino_lng'] ? (float)$enriquecida['destino_lng'] : null,
            'distancia_km'    => $enriquecida['distancia_km'] ? (float)$enriquecida['distancia_km'] : null,
        ];
        $item['automacao'] = ops_analisar_missao($item);
        $missoes[] = $item;
    }

    // Ordenar por prioridade operacional
    usort($missoes, static function ($a, $b) {
        $pa = (int)($a['automacao']['prioridade'] ?? 0);
        $pb = (int)($b['automacao']['prioridade'] ?? 0);
        if ($pa !== $pb) {
            return $pb <=> $pa;
        }
        return ($b['id'] ?? 0) <=> ($a['id'] ?? 0);
    });

    $sqlMot = "SELECT u.id, u.nome, u.telefone,
                      pc.ultima_localizacao_lat AS lat,
                      pc.ultima_localizacao_lng AS lng,
                      pc.ultima_atualizacao_local AS atualizado_em,
                      pc.disponibilidade
               FROM usuarios u
               INNER JOIN perfil_caminhoneiro pc ON u.id = pc.usuario_id
               WHERE u.tipo_usuario = 'caminhoneiro' AND u.status = 'ativo'
                 AND pc.ultima_localizacao_lat IS NOT NULL";

    $motParams = [];
    if ($scope === 'empresa' || $user_type === 'empresa') {
        $sqlMot .= " AND u.id IN (
            SELECT DISTINCT caminhoneiro_id FROM missoes WHERE empresa_id = :eid2 AND caminhoneiro_id IS NOT NULL
        )";
        $motParams[':eid2'] = $user_id;
    } elseif ($scope === 'transportador' || $user_type === 'transportador') {
        $sqlMot .= " AND u.id IN (
            SELECT tm.usuario_id FROM transportador_motoristas tm
            WHERE tm.transportador_id = :tid2 AND tm.usuario_id IS NOT NULL
            UNION
            SELECT DISTINCT m.caminhoneiro_id FROM missoes m WHERE m.transportador_id = :tid3
        )";
        $motParams[':tid2'] = $user_id;
        $motParams[':tid3'] = $user_id;
    }

    $stmtMot = $conn->prepare($sqlMot);
    $stmtMot->execute($motParams);
    $motoristas = [];
    foreach ($stmtMot->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $estado = tms_mapa_estado_operacional('aceita', null, $r['atualizado_em']);
        $motoristas[] = [
            'id'             => (int)$r['id'],
            'nome'           => $r['nome'],
            'telefone'       => $r['telefone'],
            'lat'            => (float)$r['lat'],
            'lng'            => (float)$r['lng'],
            'atualizado_em'  => $r['atualizado_em'],
            'disponibilidade'=> $r['disponibilidade'],
            'estado_mapa'    => $estado,
        ];
    }

    $viaturas = [];
    if ($scope === 'transportador' || $scope === 'operacoes' || $user_type === 'transportador' || $user_type === 'admin') {
        try {
            $sqlV = "SELECT v.id, v.placa, v.tipo_veiculo, v.estado_operacional,
                            v.transportador_id, vp.latitude, vp.longitude, vp.estado, vp.created_at
                     FROM transportador_veiculos v
                     LEFT JOIN (
                         SELECT vehicle_id, latitude, longitude, estado, created_at,
                                ROW_NUMBER() OVER (PARTITION BY vehicle_id ORDER BY created_at DESC) AS rn
                         FROM vehicle_positions
                     ) vp ON vp.vehicle_id = v.id AND vp.rn = 1
                     WHERE v.estado_operacional != 'inativo'";
            $vParams = [];
            if ($user_type === 'transportador') {
                $sqlV .= ' AND v.transportador_id = :vtid';
                $vParams[':vtid'] = $user_id;
            }
            $stmtV = $conn->prepare($sqlV);
            $stmtV->execute($vParams);
            foreach ($stmtV->fetchAll(PDO::FETCH_ASSOC) as $v) {
                $viaturas[] = [
                    'id'    => (int)$v['id'],
                    'placa' => $v['placa'],
                    'tipo'  => $v['tipo_veiculo'],
                    'lat'   => $v['latitude'] ? (float)$v['latitude'] : null,
                    'lng'   => $v['longitude'] ? (float)$v['longitude'] : null,
                    'estado_mapa' => $v['estado'] ?? ($v['estado_operacional'] === 'ativo' ? 'parado' : 'offline'),
                ];
            }
        } catch (Throwable $e) {
            // vehicle_positions ou ROW_NUMBER podem não existir em MySQL antigo
        }
    }

    $stats = [
        'total'       => count($missoes),
        'em_transito' => count(array_filter($missoes, fn($m) => $m['estado_mapa'] === 'em_transito')),
        'em_recolha'  => count(array_filter($missoes, fn($m) => $m['estado_mapa'] === 'em_recolha')),
        'parado'      => count(array_filter($missoes, fn($m) => $m['estado_mapa'] === 'parado')),
        'emergencia'  => count(array_filter($missoes, fn($m) => $m['estado_mapa'] === 'emergencia')),
        'offline'     => count(array_filter($missoes, fn($m) => $m['estado_mapa'] === 'offline')),
        'com_gps'     => count(array_filter($missoes, fn($m) => !empty($m['lat']) && !empty($m['lng']))),
        'atraso'      => count(array_filter($missoes, fn($m) => !empty($m['automacao']['atraso']))),
        'em_risco'    => count(array_filter($missoes, fn($m) => !empty($m['automacao']['em_risco']))),
        'desvio'      => count(array_filter($missoes, fn($m) => !empty($m['automacao']['desvio_rota']))),
        'geofence'    => count(array_filter($missoes, fn($m) => !empty($m['automacao']['near_origem']) || !empty($m['automacao']['near_destino']))),
        'motoristas'  => 0,
        'viaturas'    => count($viaturas),
    ];

    // Motoristas (continuação — bloco abaixo define $motoristas antes; reordenar)
    // Nota: $motoristas já foi preenchido acima
    $stats['motoristas'] = count($motoristas);

    // Sugestões de reatribuição para missões críticas
    foreach ($missoes as &$mm) {
        $critico = ($mm['estado_mapa'] === 'emergencia')
            || !empty($mm['automacao']['offline_longo'])
            || !empty($mm['automacao']['atraso']);
        $mm['candidatos'] = $critico ? ops_sugerir_reatribuicao($mm, $motoristas) : [];
    }
    unset($mm);

    $emergencias = [];
    try {
        $eqSql = "SELECT e.id, e.tipo, e.gravidade, e.status, e.data_criacao, e.missao_id,
                         m.titulo AS missao_titulo
                  FROM emergencias e
                  LEFT JOIN missoes m ON m.id = e.missao_id
                  WHERE e.status IN ('aberta','em_atendimento','pendente')";
        $eqParams = [];
        if ($scope === 'empresa' || $user_type === 'empresa') {
            $eqSql .= ' AND m.empresa_id = :eeid';
            $eqParams[':eeid'] = $user_id;
        } elseif (($scope === 'transportador' || $user_type === 'transportador') && $hasTransportador) {
            $eqSql .= ' AND m.transportador_id = :etid';
            $eqParams[':etid'] = $user_id;
        }
        $eqSql .= ' ORDER BY e.data_criacao DESC LIMIT 15';
        $eq = $conn->prepare($eqSql);
        $eq->execute($eqParams);
        foreach ($eq->fetchAll(PDO::FETCH_ASSOC) as $er) {
            $emergencias[] = [
                'id'        => (int)$er['id'],
                'tipo'      => $er['tipo'],
                'gravidade' => $er['gravidade'],
                'status'    => $er['status'],
                'missao_id' => $er['missao_id'] ? (int)$er['missao_id'] : null,
                'titulo'    => $er['missao_titulo'] ?: ($er['tipo'] ?: 'Emergência'),
                'quando'    => $er['data_criacao'],
            ];
        }
        $stats['emergencias_abertas'] = count($emergencias);
    } catch (Throwable $e) {
        $stats['emergencias_abertas'] = $stats['emergencia'];
    }

    // Escalação offline (throttled)
    foreach ($missoes as $mEsc) {
        try {
            ops_escalar_offline($conn, $mEsc, $opsUrl);
        } catch (Throwable $e) { /* ignore */ }
    }

    $eventos = ops_construir_eventos($missoes, $emergencias);
    $resumo = ops_resumo_texto($stats, $missoes);

    echo json_encode([
        'ok'           => true,
        'missoes'      => $missoes,
        'motoristas'   => $motoristas,
        'viaturas'     => $viaturas,
        'emergencias'  => $emergencias,
        'eventos'      => $eventos,
        'resumo'       => $resumo,
        'stats'        => $stats,
        'timestamp'    => date('c'),
        'center'       => [-18.665, 35.529],
        'zoom'         => 6,
        'config'       => [
            'geofence_m'     => OPS_GEOFENCE_M,
            'offline_seg'    => OPS_GPS_OFFLINE_SEG,
            'parado_seg'     => OPS_PARADO_SEG,
            'desvio_m'       => OPS_DESVIO_M,
        ],
    ]);

} catch (Throwable $e) {
    error_log('get-mapa-dados: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erro interno']);
}
