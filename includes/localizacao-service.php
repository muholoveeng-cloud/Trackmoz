<?php
/**
 * Serviço central de localização GPS — TrackMoz TMS
 */
require_once __DIR__ . '/geocode.php';
require_once __DIR__ . '/missao-helpers.php';
require_once __DIR__ . '/tms-geo.php';
require_once __DIR__ . '/checkpoint-service.php';

/**
 * Verifica se o motorista é responsável pela missão.
 */
function tms_motorista_pode_transmitir(PDO $conn, int $driverId, ?int $missionId): bool
{
    if ($missionId === null || $missionId <= 0) {
        return true;
    }
    $stmt = $conn->prepare('SELECT caminhoneiro_id FROM missoes WHERE id = :id');
    $stmt->execute([':id' => $missionId]);
    $cid = $stmt->fetchColumn();
    return $cid && (int)$cid === $driverId;
}

/**
 * Regista posição GPS do motorista.
 *
 * @return array{ok: bool, checkpoint?: array|null, error?: string}
 */
function tms_registrar_posicao(
    PDO $conn,
    int $driverId,
    float $lat,
    float $lng,
    ?int $missionId = null,
    ?float $speed = null,
    ?float $heading = null,
    ?float $accuracy = null,
    ?int $vehicleId = null
): array {
    if (!coordenadas_dentro_mocambique($lat, $lng)) {
        return ['ok' => false, 'error' => 'Coordenadas fora de Moçambique'];
    }

    if ($missionId && !tms_motorista_pode_transmitir($conn, $driverId, $missionId)) {
        return ['ok' => false, 'error' => 'Apenas o motorista responsável pode transmitir localização'];
    }

    $conn->prepare(
        'UPDATE perfil_caminhoneiro
         SET ultima_localizacao_lat = :lat, ultima_localizacao_lng = :lng,
             ultima_atualizacao_local = NOW(),
             latitude = :lat2, longitude = :lng2
         WHERE usuario_id = :uid'
    )->execute([
        ':lat' => $lat, ':lng' => $lng,
        ':lat2' => $lat, ':lng2' => $lng,
        ':uid' => $driverId,
    ]);

    $histSql = 'INSERT INTO historico_localizacao (usuario_id, latitude, longitude, data_registro';
    $histVals = 'VALUES (:uid, :lat, :lng, NOW()';
    $params = [':uid' => $driverId, ':lat' => $lat, ':lng' => $lng];

    if (coluna_existe($conn, 'historico_localizacao', 'missao_id')) {
        $histSql .= ', missao_id';
        $histVals .= ', :mid';
        $params[':mid'] = $missionId;
    }
    if (coluna_existe($conn, 'historico_localizacao', 'speed') && $speed !== null) {
        $histSql .= ', speed';
        $histVals .= ', :spd';
        $params[':spd'] = $speed;
    }
    if (coluna_existe($conn, 'historico_localizacao', 'heading') && $heading !== null) {
        $histSql .= ', heading';
        $histVals .= ', :hdg';
        $params[':hdg'] = $heading;
    }
    $conn->prepare($histSql . ') ' . $histVals . ')')->execute($params);

    if (tms_gps_tabelas_prontas($conn)) {
        $conn->prepare(
            'INSERT INTO gps_locations
             (mission_id, driver_id, vehicle_id, latitude, longitude, speed, heading, accuracy)
             VALUES (:mid, :did, :vid, :lat, :lng, :spd, :hdg, :acc)'
        )->execute([
            ':mid' => $missionId, ':did' => $driverId, ':vid' => $vehicleId,
            ':lat' => $lat, ':lng' => $lng,
            ':spd' => $speed, ':hdg' => $heading, ':acc' => $accuracy,
        ]);

        if ($vehicleId) {
            $estado = tms_estado_veiculo_por_missao($conn, $missionId);
            $conn->prepare(
                'INSERT INTO vehicle_positions
                 (vehicle_id, mission_id, driver_id, latitude, longitude, speed, heading, estado)
                 VALUES (:vid, :mid, :did, :lat, :lng, :spd, :hdg, :est)'
            )->execute([
                ':vid' => $vehicleId, ':mid' => $missionId, ':did' => $driverId,
                ':lat' => $lat, ':lng' => $lng, ':spd' => $speed, ':hdg' => $heading,
                ':est' => $estado,
            ]);
        }
    }

    if ($missionId && coluna_existe($conn, 'missoes', 'gps_offline_desde')) {
        $conn->prepare('UPDATE missoes SET gps_offline_desde = NULL WHERE id = :id')
            ->execute([':id' => $missionId]);
    }

    $checkpoint = null;
    if ($missionId) {
        $checkpoint = tms_processar_checkpoints($conn, $missionId, $driverId, $lat, $lng);
        tms_emitir_evento_realtime($conn, 'gps_update', $missionId, $driverId, [
            'lat' => $lat, 'lng' => $lng,
            'speed' => $speed, 'heading' => $heading,
            'checkpoint' => $checkpoint,
        ]);
    }

    return ['ok' => true, 'checkpoint' => $checkpoint];
}

function tms_estado_veiculo_por_missao(PDO $conn, ?int $missionId): string
{
    if (!$missionId) {
        return 'parado';
    }
    $stmt = $conn->prepare('SELECT status, status_viagem FROM missoes WHERE id = :id');
    $stmt->execute([':id' => $missionId]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) {
        return 'parado';
    }
    if ($m['status'] === 'emergencia') {
        return 'emergencia';
    }
    $sv = $m['status_viagem'] ?? '';
    if (in_array($sv, ['aguardando_recolha', 'a_caminho_recolha'], true)) {
        return 'em_recolha';
    }
    if (in_array($m['status'], ['em_transito', 'em_andamento', 'em_entrega'], true)) {
        return 'em_transito';
    }
    return 'parado';
}

/**
 * Histórico GPS de uma missão.
 * @return list<array{lat: float, lng: float, speed: ?float, heading: ?float, ts: string}>
 */
function tms_historico_missao(PDO $conn, int $missionId, int $limit = 2000): array
{
    if (tms_gps_tabelas_prontas($conn)) {
        $stmt = $conn->prepare(
            'SELECT latitude, longitude, speed, heading, created_at
             FROM gps_locations WHERE mission_id = :mid
             ORDER BY created_at ASC LIMIT ' . (int)$limit
        );
        $stmt->execute([':mid' => $missionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            return array_map(static fn($r) => [
                'lat' => (float)$r['latitude'],
                'lng' => (float)$r['longitude'],
                'speed' => $r['speed'] !== null ? (float)$r['speed'] : null,
                'heading' => $r['heading'] !== null ? (float)$r['heading'] : null,
                'ts' => $r['created_at'],
            ], $rows);
        }
    }

    $stmt = $conn->prepare('SELECT caminhoneiro_id FROM missoes WHERE id = :id');
    $stmt->execute([':id' => $missionId]);
    $driverId = (int)$stmt->fetchColumn();
    if (!$driverId) {
        return [];
    }

    $sql = 'SELECT latitude, longitude, data_registro';
    if (coluna_existe($conn, 'historico_localizacao', 'speed')) {
        $sql .= ', speed, heading';
    }
    $sql .= ' FROM historico_localizacao WHERE usuario_id = :uid';
    if (coluna_existe($conn, 'historico_localizacao', 'missao_id')) {
        $sql .= ' AND (missao_id = :mid OR missao_id IS NULL)';
    }
    $sql .= ' ORDER BY data_registro ASC LIMIT ' . (int)$limit;

    $stmt = $conn->prepare($sql);
    $params = [':uid' => $driverId];
    if (coluna_existe($conn, 'historico_localizacao', 'missao_id')) {
        $params[':mid'] = $missionId;
    }
    $stmt->execute($params);

    return array_map(static fn($r) => [
        'lat' => (float)$r['latitude'],
        'lng' => (float)$r['longitude'],
        'speed' => isset($r['speed']) ? (float)$r['speed'] : null,
        'heading' => isset($r['heading']) ? (float)$r['heading'] : null,
        'ts' => $r['data_registro'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Guarda rota OSRM calculada para a missão.
 */
function tms_guardar_rota_missao(
    PDO $conn,
    int $missionId,
    float $oLat, float $oLng,
    float $dLat, float $dLng,
    ?float $distKm, ?int $durMin,
    ?string $geojson = null
): void {
    if (!tms_gps_tabelas_prontas($conn)) {
        return;
    }
    $conn->prepare(
        'INSERT INTO mission_routes
         (mission_id, origin_lat, origin_lng, dest_lat, dest_lng, distance_km, duration_min, route_geojson)
         VALUES (:mid, :olat, :olng, :dlat, :dlng, :dkm, :dmin, :geo)
         ON DUPLICATE KEY UPDATE
           origin_lat = VALUES(origin_lat), origin_lng = VALUES(origin_lng),
           dest_lat = VALUES(dest_lat), dest_lng = VALUES(dest_lng),
           distance_km = VALUES(distance_km), duration_min = VALUES(duration_min),
           route_geojson = VALUES(route_geojson), updated_at = NOW()'
    )->execute([
        ':mid' => $missionId,
        ':olat' => $oLat, ':olng' => $oLng,
        ':dlat' => $dLat, ':dlng' => $dLng,
        ':dkm' => $distKm, ':dmin' => $durMin,
        ':geo' => $geojson,
    ]);
}

/**
 * Calcula ETA e distância restante com base na posição actual.
 * @return array{distancia_restante_km: float, eta_min: int}|null
 */
function tms_calcular_eta(PDO $conn, int $missionId, float $lat, float $lng): ?array
{
    $stmt = $conn->prepare(
        'SELECT m.status, m.status_viagem,
                lo.latitude AS olat, lo.longitude AS olng,
                ld.latitude AS dlat, ld.longitude AS dlng,
                mr.distance_km, mr.duration_min
         FROM missoes m
         LEFT JOIN locais lo ON m.local_origem_id = lo.id
         LEFT JOIN locais ld ON m.local_destino_id = ld.id
         LEFT JOIN mission_routes mr ON mr.mission_id = m.id
         WHERE m.id = :id'
    );
    $stmt->execute([':id' => $missionId]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) {
        return null;
    }

    $sv = $m['status_viagem'] ?? '';
    $alvoLat = $m['dlat'];
    $alvoLng = $m['dlng'];
    if (in_array($sv, ['nao_iniciada', 'a_caminho_recolha', 'aguardando_recolha'], true)) {
        $alvoLat = $m['olat'];
        $alvoLng = $m['olng'];
    }

    if (!$alvoLat || !$alvoLng) {
        return null;
    }

    $distM = tms_distancia_metros($lat, $lng, (float)$alvoLat, (float)$alvoLng);
    $distKm = round($distM / 1000, 1);

    $avgKmh = 50;
    if ($m['distance_km'] && $m['duration_min'] && $m['duration_min'] > 0) {
        $avgKmh = max(20, ($m['distance_km'] / $m['duration_min']) * 60);
    }
    $etaMin = max(1, (int)round(($distKm / $avgKmh) * 60));

    return ['distancia_restante_km' => $distKm, 'eta_min' => $etaMin];
}

/**
 * Detecta motoristas offline (>60s sem GPS).
 */
function tms_detectar_gps_offline(PDO $conn, int $missionId): ?array
{
    $stmt = $conn->prepare(
        'SELECT m.gps_offline_desde, m.caminhoneiro_id, pc.ultima_atualizacao_local
         FROM missoes m
         LEFT JOIN perfil_caminhoneiro pc ON m.caminhoneiro_id = pc.usuario_id
         WHERE m.id = :id'
    );
    $stmt->execute([':id' => $missionId]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m || !$m['caminhoneiro_id']) {
        return null;
    }

    $ultima = $m['ultima_atualizacao_local'] ?? null;
    if (!$ultima) {
        return null;
    }

    $diff = time() - strtotime($ultima);
    if ($diff > 60) {
        if (coluna_existe($conn, 'missoes', 'gps_offline_desde') && !$m['gps_offline_desde']) {
            $conn->prepare('UPDATE missoes SET gps_offline_desde = NOW() WHERE id = :id')
                ->execute([':id' => $missionId]);
            tms_emitir_evento_realtime($conn, 'gps_offline', $missionId, (int)$m['caminhoneiro_id'], [
                'segundos' => $diff,
            ]);
        }
        return ['offline' => true, 'segundos' => $diff];
    }
    return ['offline' => false, 'segundos' => $diff];
}

/**
 * Verifica acesso à missão para visualização de mapa/GPS.
 */
function tms_pode_ver_missao(PDO $conn, int $userId, string $userType, array $missao): bool
{
    if ($userType === 'admin') {
        return true;
    }
    if ($userType === 'empresa' && (int)($missao['empresa_id'] ?? 0) === $userId) {
        return true;
    }
    if ($userType === 'transportador' && (int)($missao['transportador_id'] ?? 0) === $userId) {
        return true;
    }
    if ($userType === 'caminhoneiro' && (int)($missao['caminhoneiro_id'] ?? 0) === $userId) {
        return true;
    }
    return false;
}
