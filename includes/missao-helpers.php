<?php
/**
 * Automatizações de missões: código, notificações, registo de viagem.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/geocode.php';
require_once __DIR__ . '/notificacoes-helpers.php';

function gerar_codigo_missao(PDO $conn): string
{
    $ano = date('Y');
    try {
        $stmt = $conn->query(
            "SELECT codigo_missao FROM missoes
             WHERE codigo_missao LIKE " . $conn->quote("TMZ-$ano-%") . "
             ORDER BY id DESC LIMIT 1"
        );
        $ultimo = $stmt->fetchColumn();
        $seq    = 1;
        if ($ultimo && preg_match('/TMZ-\d{4}-(\d+)/', $ultimo, $m)) {
            $seq = (int)$m[1] + 1;
        }
    } catch (PDOException $e) {
        $seq = random_int(1000, 9999);
    }
    return sprintf('TMZ-%s-%05d', $ano, $seq);
}

function coluna_existe(PDO $conn, string $tabela, string $coluna): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute([':t' => $tabela, ':c' => $coluna]);
    return (bool)$stmt->fetchColumn();
}

function registar_evento_viagem(PDO $conn, int $missaoId, string $tipo, string $descricao): void
{
    try {
        $stmt = $conn->prepare(
            'INSERT INTO registros_viagem (missao_id, tipo, descricao, data_registro) VALUES (:mid, :tipo, :desc, NOW())'
        );
        $stmt->execute([':mid' => $missaoId, ':tipo' => $tipo, ':desc' => $descricao]);
    } catch (PDOException $e) {
        error_log('registar_evento_viagem: ' . $e->getMessage());
    }
}

/**
 * Passos opcionais após INSERT da missão (não devem impedir criação).
 */
function pos_criacao_missao(PDO $conn, int $missaoId, ?float $origemLat, ?float $origemLng, ?float $destinoLat, ?float $destinoLng, string $origem, string $destino, string $status): void
{
    try {
        if ($origemLat !== null && $origemLng !== null) {
            $localOrigemId = criar_local($conn, $origem, $origemLat, $origemLng);
            $conn->prepare('UPDATE missoes SET local_origem_id = :lid WHERE id = :id')
                ->execute([':lid' => $localOrigemId, ':id' => $missaoId]);
        }
        if ($destinoLat !== null && $destinoLng !== null) {
            $localDestinoId = criar_local($conn, $destino, $destinoLat, $destinoLng);
            $conn->prepare('UPDATE missoes SET local_destino_id = :lid WHERE id = :id')
                ->execute([':lid' => $localDestinoId, ':id' => $missaoId]);
        }
        garantir_locais_missao($conn, $missaoId);

        if ($origemLat !== null && $destinoLat !== null) {
            guardar_metricas_rota_missao($conn, $missaoId, $origemLat, $origemLng, $destinoLat, $destinoLng);
        }

        if (coluna_existe($conn, 'missoes', 'codigo_missao')) {
            $codigo = sprintf('TMZ-%s-%05d', date('Y'), $missaoId);
            $conn->prepare('UPDATE missoes SET codigo_missao = :c WHERE id = :id')
                ->execute([':c' => $codigo, ':id' => $missaoId]);
        }

        registar_evento_viagem($conn, $missaoId, 'criacao', 'Missão criada pela empresa');
        notificar_mudanca_status_missao($conn, $missaoId, $status);
    } catch (Throwable $e) {
        error_log('pos_criacao_missao: ' . $e->getMessage());
    }
}

/**
 * Calcula rota via OSRM com geometria GeoJSON. Devolve null se falhar.
 * @return array{distancia_km: float, tempo_min: int, coordinates: array, fallback: bool}|null
 */
function http_get_string(string $url, int $timeout = 10): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => ['User-Agent: TrackMoz/1.0'],
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw !== false && $code >= 200 && $code < 300) {
            return $raw;
        }
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'header'  => "User-Agent: TrackMoz/1.0\r\n",
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw === false ? null : $raw;
}

function calcular_rota_on_osrm_geojson(float $lat1, float $lng1, float $lat2, float $lng2): ?array
{
    require_once __DIR__ . '/geocode.php';
    if (!coordenadas_dentro_mocambique($lat1, $lng1) || !coordenadas_dentro_mocambique($lat2, $lng2)) {
        error_log(sprintf('OSRM skip: coords outside MZ (%s,%s)->(%s,%s)', $lat1, $lng1, $lat2, $lng2));
        return null;
    }

    $url = sprintf(
        'https://router.project-osrm.org/route/v1/driving/%s,%s;%s,%s?overview=full&geometries=geojson',
        $lng1, $lat1, $lng2, $lat2
    );
    $raw = http_get_string($url, 12);
    if ($raw === null) {
        error_log('OSRM request failed: ' . $url);
        return null;
    }
    $data = json_decode($raw, true);
    if (empty($data['routes'][0])) {
        error_log('OSRM empty route response');
        return null;
    }
    $route = $data['routes'][0];
    $coords = [];
    if (!empty($route['geometry']['coordinates'])) {
        foreach ($route['geometry']['coordinates'] as $c) {
            $coords[] = [(float)$c[1], (float)$c[0]];
        }
    }
    return [
        'distancia_km' => round($route['distance'] / 1000, 1),
        'tempo_min'    => (int)round($route['duration'] / 60),
        'distancia_m'  => (float)$route['distance'],
        'duracao_s'    => (float)$route['duration'],
        'coordinates'  => $coords,
        'fallback'     => false,
    ];
}

function calcular_rota_osrm_geojson(float $lat1, float $lng1, float $lat2, float $lng2): ?array
{
    require_once __DIR__ . '/rota-mocambique.php';
    return calcular_rota_mocambique($lat1, $lng1, $lat2, $lng2);
}

/**
 * Fallback: linha recta entre dois pontos com estimativa de tempo.
 */
function calcular_rota_fallback(float $lat1, float $lng1, float $lat2, float $lng2): array
{
    $R = 6371000;
    $toR = static fn(float $d) => $d * M_PI / 180;
    $dLat = $toR($lat2 - $lat1);
    $dLon = $toR($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos($toR($lat1)) * cos($toR($lat2)) * sin($dLon / 2) ** 2;
    $distM = $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    $tempoMin = max(1, (int)round(($distM / 1000) / 50 * 60));

    return [
        'distancia_km' => round($distM / 1000, 1),
        'tempo_min'    => $tempoMin,
        'distancia_m'  => $distM,
        'duracao_s'    => $tempoMin * 60,
        'coordinates'  => [[$lat1, $lng1], [$lat2, $lng2]],
        'fallback'     => true,
    ];
}

/**
 * Calcula distância/tempo via OSRM (estrada). Devolve null se falhar.
 * @return array{distancia_km: float, tempo_min: int}|null
 */
function calcular_rota_osrm(float $lat1, float $lng1, float $lat2, float $lng2): ?array
{
    $url = sprintf(
        'https://router.project-osrm.org/route/v1/driving/%s,%s;%s,%s?overview=false',
        $lng1, $lat1, $lng2, $lat2
    );
    $raw = http_get_string($url, 8);
    if ($raw === null) {
        return null;
    }
    $data = json_decode($raw, true);
    if (empty($data['routes'][0])) {
        return null;
    }
    $route = $data['routes'][0];
    return [
        'distancia_km' => round($route['distance'] / 1000, 1),
        'tempo_min'    => (int)round($route['duration'] / 60),
    ];
}

function guardar_metricas_rota_missao(PDO $conn, int $missaoId, float $lat1, float $lng1, float $lat2, float $lng2): void
{
    require_once __DIR__ . '/rota-mocambique.php';

    $rota = calcular_rota_mocambique($lat1, $lng1, $lat2, $lng2);
    if (!$rota) {
        $rota = calcular_rota_fallback($lat1, $lng1, $lat2, $lng2);
    }
    if (!$rota) {
        return;
    }

    if (coluna_existe($conn, 'missoes', 'distancia_km')) {
        $sql = 'UPDATE missoes SET distancia_km = :d';
        $params = [':d' => $rota['distancia_km'], ':id' => $missaoId];
        if (coluna_existe($conn, 'missoes', 'tempo_estimado_min')) {
            $sql .= ', tempo_estimado_min = :t';
            $params[':t'] = $rota['tempo_min'];
        }
        $sql .= ' WHERE id = :id';
        $conn->prepare($sql)->execute($params);
    }

    if (file_exists(__DIR__ . '/localizacao-service.php')) {
        require_once __DIR__ . '/localizacao-service.php';
        $geojson = !empty($rota['coordinates'])
            ? json_encode(['type' => 'LineString', 'coordinates' => array_map(
                static fn($c) => [$c[1] ?? $c[0], $c[0] ?? $c[1]],
                $rota['coordinates']
            )])
            : null;
        tms_guardar_rota_missao(
            $conn, $missaoId, $lat1, $lng1, $lat2, $lng2,
            $rota['distancia_km'], $rota['tempo_min'], $geojson
        );
    }
}

/** Garante colunas operacionais de missão (entrega, condução) em ambientes sem migration completa. */
function missao_garantir_colunas_operacionais(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $adds = [
        'status_entrega'           => "VARCHAR(40) DEFAULT NULL",
        'modo_confirmacao_entrega' => "VARCHAR(30) DEFAULT 'otp'",
        'otp_expiracao_horas'      => 'INT DEFAULT 48',
    ];

    foreach ($adds as $col => $def) {
        if (!coluna_existe($conn, 'missoes', $col)) {
            try {
                $conn->exec("ALTER TABLE missoes ADD COLUMN {$col} {$def}");
            } catch (Throwable $e) {
                error_log('missao_garantir_colunas_operacionais: ' . $e->getMessage());
            }
        }
    }
}
