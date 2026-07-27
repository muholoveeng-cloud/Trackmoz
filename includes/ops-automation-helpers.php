<?php
/**
 * Automações do Centro de Operações — geofence, risco, eventos, escalação.
 */

const OPS_GEOFENCE_M = 500;
const OPS_GPS_OFFLINE_SEG = 900;       // 15 min
const OPS_OFFLINE_ESCALA_SEG = 1800;   // 30 min → notificar
const OPS_PARADO_SEG = 1200;           // 20 min parado em trânsito
const OPS_DESVIO_M = 800;              // desvio da rota recta
const OPS_PRAZO_RISCO_H = 6;           // horas antes do prazo
const OPS_REATRIB_RAIO_KM = 40;

function ops_haversine_m(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $R = 6371000.0;
    $toR = static fn($d) => $d * M_PI / 180;
    $dLat = $toR($lat2 - $lat1);
    $dLng = $toR($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
        + cos($toR($lat1)) * cos($toR($lat2)) * sin($dLng / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function ops_gps_idade_seg(?string $atualizadoEm): ?int
{
    if (!$atualizadoEm) {
        return null;
    }
    $ts = strtotime($atualizadoEm);
    if ($ts === false) {
        return null;
    }
    return max(0, time() - $ts);
}

/**
 * Analisa missão enriquecida e devolve flags de automação.
 */
function ops_analisar_missao(array $m): array
{
    $lat = isset($m['lat']) ? (float)$m['lat'] : null;
    $lng = isset($m['lng']) ? (float)$m['lng'] : null;
    $oLat = isset($m['origem_lat']) ? (float)$m['origem_lat'] : null;
    $oLng = isset($m['origem_lng']) ? (float)$m['origem_lng'] : null;
    $dLat = isset($m['destino_lat']) ? (float)$m['destino_lat'] : null;
    $dLng = isset($m['destino_lng']) ? (float)$m['destino_lng'] : null;
    $estado = $m['estado_mapa'] ?? 'parado';
    $idade = ops_gps_idade_seg($m['atualizado_em'] ?? null);

    $nearOrigem = false;
    $nearDestino = false;
    $distOrigemM = null;
    $distDestinoM = null;
    $desvioRota = false;
    $distRotaM = null;

    if ($lat && $lng && $oLat && $oLng) {
        $distOrigemM = ops_haversine_m($lat, $lng, $oLat, $oLng);
        $nearOrigem = $distOrigemM <= OPS_GEOFENCE_M;
    }
    if ($lat && $lng && $dLat && $dLng) {
        $distDestinoM = ops_haversine_m($lat, $lng, $dLat, $dLng);
        $nearDestino = $distDestinoM <= OPS_GEOFENCE_M;
    }

    // Desvio vs linha recta origem→destino (aproximação sem OSRM no servidor)
    if ($lat && $lng && $oLat && $oLng && $dLat && $dLng
        && in_array($estado, ['em_transito', 'em_recolha'], true)) {
        $distRotaM = ops_distancia_ponto_segmento_m($lat, $lng, $oLat, $oLng, $dLat, $dLng);
        $desvioRota = $distRotaM > OPS_DESVIO_M;
    }

    $atraso = false;
    $emRisco = false;
    $prazoTs = null;
    if (!empty($m['prazo_entrega'])) {
        $prazoTs = strtotime($m['prazo_entrega']);
        if ($prazoTs !== false) {
            $horas = ($prazoTs - time()) / 3600;
            if ($horas < 0) {
                $atraso = true;
                $emRisco = true;
            } elseif ($horas <= OPS_PRAZO_RISCO_H) {
                $emRisco = true;
            }
        }
    }

    $offlineLongo = ($estado === 'offline') && $idade !== null && $idade >= OPS_OFFLINE_ESCALA_SEG;
    $gpsOffline = ($estado === 'offline');

    $prioridade = 0;
    if ($estado === 'emergencia') {
        $prioridade = 100;
    } elseif ($atraso) {
        $prioridade = 80;
    } elseif ($offlineLongo) {
        $prioridade = 70;
    } elseif ($desvioRota) {
        $prioridade = 55;
    } elseif ($emRisco) {
        $prioridade = 50;
    } elseif ($gpsOffline) {
        $prioridade = 40;
    } elseif ($nearDestino) {
        $prioridade = 30;
    } elseif ($nearOrigem) {
        $prioridade = 25;
    }

    $alertas = [];
    if ($estado === 'emergencia') {
        $alertas[] = 'emergencia';
    }
    if ($atraso) {
        $alertas[] = 'atraso';
    } elseif ($emRisco) {
        $alertas[] = 'prazo_risco';
    }
    if ($gpsOffline) {
        $alertas[] = 'offline';
    }
    if ($offlineLongo) {
        $alertas[] = 'offline_escalado';
    }
    if ($desvioRota) {
        $alertas[] = 'desvio';
    }
    if ($nearOrigem && in_array($estado, ['parado', 'em_recolha', 'offline'], true)) {
        $alertas[] = 'geofence_recolha';
    }
    if ($nearDestino && in_array($estado, ['em_transito', 'em_recolha', 'parado'], true)) {
        $alertas[] = 'geofence_entrega';
    }

    return [
        'near_origem'       => $nearOrigem,
        'near_destino'      => $nearDestino,
        'dist_origem_m'     => $distOrigemM !== null ? (int)round($distOrigemM) : null,
        'dist_destino_m'    => $distDestinoM !== null ? (int)round($distDestinoM) : null,
        'desvio_rota'       => $desvioRota,
        'dist_rota_m'       => $distRotaM !== null ? (int)round($distRotaM) : null,
        'atraso'            => $atraso,
        'em_risco'          => $emRisco,
        'prazo_ts'          => $prazoTs,
        'gps_idade_seg'     => $idade,
        'offline_longo'     => $offlineLongo,
        'prioridade'        => $prioridade,
        'alertas'           => $alertas,
    ];
}

/** Distância do ponto ao segmento (metros), aproximação equirectangular. */
function ops_distancia_ponto_segmento_m(
    float $pLat, float $pLng,
    float $aLat, float $aLng,
    float $bLat, float $bLng
): float {
    $toXY = static function ($lat, $lng) use ($aLat, $aLng) {
        $x = deg2rad($lng - $aLng) * cos(deg2rad($aLat)) * 6371000;
        $y = deg2rad($lat - $aLat) * 6371000;
        return [$x, $y];
    };
    [$px, $py] = $toXY($pLat, $pLng);
    [$ax, $ay] = [0.0, 0.0];
    [$bx, $by] = $toXY($bLat, $bLng);
    $dx = $bx - $ax;
    $dy = $by - $ay;
    $len2 = $dx * $dx + $dy * $dy;
    if ($len2 < 1) {
        return ops_haversine_m($pLat, $pLng, $aLat, $aLng);
    }
    $t = max(0, min(1, (($px - $ax) * $dx + ($py - $ay) * $dy) / $len2));
    $cx = $ax + $t * $dx;
    $cy = $ay + $t * $dy;
    return sqrt(($px - $cx) ** 2 + ($py - $cy) ** 2);
}

/**
 * Motoristas próximos disponíveis para reatribuição.
 *
 * @return list<array{id:int,nome:string,telefone:?string,lat:float,lng:float,dist_km:float}>
 */
function ops_sugerir_reatribuicao(array $missao, array $motoristas, float $raioKm = OPS_REATRIB_RAIO_KM): array
{
    $lat = $missao['lat'] ?? $missao['origem_lat'] ?? null;
    $lng = $missao['lng'] ?? $missao['origem_lng'] ?? null;
    if (!$lat || !$lng) {
        return [];
    }
    $atualId = $missao['caminhoneiro_id'] ?? null;
    $out = [];
    foreach ($motoristas as $mot) {
        if (!$mot['lat'] || !$mot['lng']) {
            continue;
        }
        if ($atualId && (int)$mot['id'] === (int)$atualId) {
            continue;
        }
        if (($mot['estado_mapa'] ?? '') === 'offline') {
            continue;
        }
        $disp = strtolower((string)($mot['disponibilidade'] ?? ''));
        if ($disp !== '' && !in_array($disp, ['disponivel', 'disponível', 'livre', 'ativo', 'activo'], true)) {
            // ainda assim incluir se não estiver em missão crítica
        }
        $km = ops_haversine_m((float)$lat, (float)$lng, (float)$mot['lat'], (float)$mot['lng']) / 1000;
        if ($km > $raioKm) {
            continue;
        }
        $out[] = [
            'id'       => (int)$mot['id'],
            'nome'     => $mot['nome'],
            'telefone' => $mot['telefone'] ?? null,
            'lat'      => (float)$mot['lat'],
            'lng'      => (float)$mot['lng'],
            'dist_km'  => round($km, 1),
        ];
    }
    usort($out, static fn($a, $b) => $a['dist_km'] <=> $b['dist_km']);
    return array_slice($out, 0, 5);
}

/**
 * Constrói feed de eventos a partir do estado actual.
 *
 * @return list<array{tipo:string,nivel:string,titulo:string,msg:string,missao_id:?int,quando:string}>
 */
function ops_construir_eventos(array $missoes, array $emergencias): array
{
    $ev = [];
    $now = date('c');

    foreach ($emergencias as $e) {
        $ev[] = [
            'tipo'      => 'emergencia',
            'nivel'     => 'danger',
            'titulo'    => 'Emergência',
            'msg'       => ($e['titulo'] ?? 'Alerta') . ' · ' . ($e['gravidade'] ?? ''),
            'missao_id' => $e['missao_id'] ?? null,
            'quando'    => $e['quando'] ?? $now,
            'id'        => 'emg-' . ($e['id'] ?? uniqid()),
        ];
    }

    foreach ($missoes as $m) {
        $id = (int)$m['id'];
        $titulo = $m['titulo'] ?? ('Missão #' . $id);
        $mot = $m['motorista_nome'] ?? 'Motorista';
        $a = $m['automacao'] ?? [];

        if (($m['estado_mapa'] ?? '') === 'offline') {
            $ev[] = [
                'tipo' => 'offline', 'nivel' => !empty($a['offline_longo']) ? 'danger' : 'warn',
                'titulo' => 'GPS offline',
                'msg' => "$mot · $titulo",
                'missao_id' => $id, 'quando' => $m['atualizado_em'] ?? $now,
                'id' => 'off-' . $id,
            ];
        }
        if (!empty($a['atraso'])) {
            $ev[] = [
                'tipo' => 'atraso', 'nivel' => 'danger',
                'titulo' => 'Prazo ultrapassado',
                'msg' => $titulo,
                'missao_id' => $id, 'quando' => $now, 'id' => 'atr-' . $id,
            ];
        } elseif (!empty($a['em_risco'])) {
            $ev[] = [
                'tipo' => 'prazo_risco', 'nivel' => 'warn',
                'titulo' => 'Prazo em risco',
                'msg' => $titulo,
                'missao_id' => $id, 'quando' => $now, 'id' => 'risco-' . $id,
            ];
        }
        if (!empty($a['desvio_rota'])) {
            $ev[] = [
                'tipo' => 'desvio', 'nivel' => 'warn',
                'titulo' => 'Desvio de rota',
                'msg' => "$titulo · ~" . ($a['dist_rota_m'] ?? '?') . ' m',
                'missao_id' => $id, 'quando' => $now, 'id' => 'desv-' . $id,
            ];
        }
        if (!empty($a['near_destino'])) {
            $ev[] = [
                'tipo' => 'geofence_entrega', 'nivel' => 'ok',
                'titulo' => 'Na zona de entrega',
                'msg' => $titulo,
                'missao_id' => $id, 'quando' => $now, 'id' => 'geo-d-' . $id,
            ];
        } elseif (!empty($a['near_origem'])) {
            $ev[] = [
                'tipo' => 'geofence_recolha', 'nivel' => 'ok',
                'titulo' => 'Na zona de recolha',
                'msg' => $titulo,
                'missao_id' => $id, 'quando' => $now, 'id' => 'geo-o-' . $id,
            ];
        }
    }

    usort($ev, static function ($a, $b) {
        $ta = strtotime($a['quando'] ?? '') ?: 0;
        $tb = strtotime($b['quando'] ?? '') ?: 0;
        return $tb <=> $ta;
    });

    return array_slice($ev, 0, 40);
}

/**
 * Notifica stakeholders quando GPS offline prolongado (máx. 1× / 45 min por missão).
 */
function ops_escalar_offline(PDO $conn, array $missao, string $opsUrl): void
{
    if (empty($missao['automacao']['offline_longo'])) {
        return;
    }
    $mid = (int)$missao['id'];
    $chave = 'ops_off_' . $mid;
    if (!empty($_SESSION[$chave]) && (time() - (int)$_SESSION[$chave]) < 2700) {
        return;
    }

    require_once __DIR__ . '/notificacoes-helpers.php';

    $titulo = 'GPS offline prolongado';
    $msg = 'Missão #' . $mid . ' · ' . ($missao['titulo'] ?? '') . ' — '
        . ($missao['motorista_nome'] ?? 'motorista') . ' sem sinal há mais de 30 min.';
    $destinos = [];
    if (!empty($missao['empresa_id'])) {
        $destinos[] = (int)$missao['empresa_id'];
    }
    if (!empty($missao['transportador_id'])) {
        $destinos[] = (int)$missao['transportador_id'];
    }
    if (!empty($missao['caminhoneiro_id'])) {
        $destinos[] = (int)$missao['caminhoneiro_id'];
    }

    // Admins
    try {
        $admins = $conn->query("SELECT id FROM usuarios WHERE tipo_usuario = 'admin' AND status = 'ativo' LIMIT 20");
        foreach ($admins->fetchAll(PDO::FETCH_COLUMN) as $aid) {
            $destinos[] = (int)$aid;
        }
    } catch (Throwable $e) { /* ignore */ }

    $destinos = array_unique(array_filter($destinos));
    foreach ($destinos as $uid) {
        try {
            notificar_usuario($conn, $uid, 'alerta_gps', $titulo, $msg, $opsUrl);
        } catch (Throwable $e) {
            error_log('ops_escalar_offline: ' . $e->getMessage());
        }
    }
    $_SESSION[$chave] = time();
}

function ops_resumo_texto(array $stats, array $missoes): string
{
    $atraso = count(array_filter($missoes, static fn($m) => !empty($m['automacao']['atraso'])));
    $risco = count(array_filter($missoes, static fn($m) => !empty($m['automacao']['em_risco'])));
    $desvio = count(array_filter($missoes, static fn($m) => !empty($m['automacao']['desvio_rota'])));
    return sprintf(
        '%d activas · %d GPS · %d offline · %d emergências · %d atraso · %d risco · %d desvio',
        (int)($stats['total'] ?? 0),
        (int)($stats['com_gps'] ?? 0),
        (int)($stats['offline'] ?? 0),
        (int)($stats['emergencias_abertas'] ?? $stats['emergencia'] ?? 0),
        $atraso,
        $risco,
        $desvio
    );
}
