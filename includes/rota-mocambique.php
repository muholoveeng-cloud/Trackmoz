<?php
/**
 * Rotas preferencialmente nacionais (Moçambique).
 * Evita corredores internacionais (Zimbabwe/Zâmbia) quando ambos os pontos estão em MZ.
 */
require_once __DIR__ . '/geocode.php';

/** Hubs em estradas nacionais (EN1, EN6, EN7, etc.) */
function mz_pontos_corredor(): array
{
    return [
        ['nome' => 'Pemba',      'lat' => -12.9730, 'lng' => 40.5175],
        ['nome' => 'Montepuez',  'lat' => -13.1256, 'lng' => 39.1600],
        ['nome' => 'Nampula',    'lat' => -15.1165, 'lng' => 39.2666],
        ['nome' => 'Cuamba',     'lat' => -14.3886, 'lng' => 36.5372],
        ['nome' => 'Lichinga',   'lat' => -13.3128, 'lng' => 35.2406],
        ['nome' => 'Tete',       'lat' => -16.1565, 'lng' => 33.5867],
        ['nome' => 'Moatize',    'lat' => -16.0986, 'lng' => 33.8056],
        ['nome' => 'Chimoio',    'lat' => -19.1167, 'lng' => 33.4833],
        ['nome' => 'Manica',     'lat' => -18.9333, 'lng' => 32.8667],
        ['nome' => 'Inchope',    'lat' => -19.6730, 'lng' => 34.8520],
        ['nome' => 'Dondo',      'lat' => -19.6167, 'lng' => 34.7500],
        ['nome' => 'Beira',      'lat' => -19.8436, 'lng' => 34.8389],
        ['nome' => 'Mocuba',     'lat' => -17.0044, 'lng' => 36.9850],
        ['nome' => 'Quelimane',  'lat' => -17.8764, 'lng' => 36.8873],
        ['nome' => 'Vilanculos', 'lat' => -22.0033, 'lng' => 35.3133],
        ['nome' => 'Maxixe',     'lat' => -23.8597, 'lng' => 35.3472],
        ['nome' => 'Inhambane',  'lat' => -23.8650, 'lng' => 35.3833],
        ['nome' => 'Xai-Xai',    'lat' => -25.0519, 'lng' => 33.6442],
        ['nome' => 'Chokwe',     'lat' => -24.5333, 'lng' => 32.9833],
        ['nome' => 'Matola',     'lat' => -25.9622, 'lng' => 32.4588],
        ['nome' => 'Maputo',     'lat' => -25.9653, 'lng' => 32.5892],
    ];
}

/** Longitude aproximada da fronteira oeste de Moçambique (lat). */
function mz_longitude_fronteira_oeste(float $lat): float
{
    if ($lat >= -11.5) {
        return 40.2;
    }
    if ($lat >= -14.0) {
        return 36.8;
    }
    if ($lat >= -16.5) {
        return 33.0;
    }
    if ($lat >= -19.0) {
        return 32.4;
    }
    if ($lat >= -22.5) {
        return 32.0;
    }
    if ($lat >= -25.5) {
        return 31.9;
    }
    return 32.5;
}

/** Detecta pontos provavelmente fora de Moçambique (Zimbabwe, Zâmbia, África do Sul). */
function mz_ponto_provavel_estrangeiro(float $lat, float $lng): bool
{
    if (!coordenadas_dentro_mocambique($lat, $lng)) {
        return true;
    }

    $fronteira = mz_longitude_fronteira_oeste($lat);
    if ($lng < ($fronteira - 0.12)) {
        return true;
    }

    // eSwatini / África do Sul a oeste de Maputo
    if ($lat < -25.2 && $lng < 31.75) {
        return true;
    }

    // Corredor Zimbabwe / Zâmbia (fronteira oeste)
    if ($lat >= -20.5 && $lat <= -15.5 && $lng >= 29.5 && $lng < 32.35) {
        return true;
    }

    // Zâmbia noroeste
    if ($lat >= -16.5 && $lat <= -12.5 && $lng >= 30.0 && $lng < 32.35) {
        return true;
    }

    return false;
}

/** @param array<int, array{0: float, 1: float}> $coords */
function rota_geometria_nacional(array $coords): bool
{
    if (count($coords) < 2) {
        return true;
    }

    $amostra = $coords;
    if (count($coords) > 40) {
        $amostra = [];
        $step = (int)floor(count($coords) / 40);
        $step = max(1, $step);
        for ($i = 0; $i < count($coords); $i += $step) {
            $amostra[] = $coords[$i];
        }
        $amostra[] = $coords[count($coords) - 1];
    }

    foreach ($amostra as $c) {
        if (mz_ponto_provavel_estrangeiro((float)$c[0], (float)$c[1])) {
            return false;
        }
    }

    return true;
}

function mz_distancia_haversine_m(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $R   = 6371000;
    $toR = static fn(float $d) => $d * M_PI / 180;
    $dLat = $toR($lat2 - $lat1);
    $dLon = $toR($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
        + cos($toR($lat1)) * cos($toR($lat2)) * sin($dLon / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/** Projeção escalar do ponto P na recta A→B (0 = A, 1 = B). */
function mz_projecao_parametro(
    float $latA,
    float $lngA,
    float $latB,
    float $lngB,
    float $latP,
    float $lngP
): float {
    $ax = $lngB - $lngA;
    $ay = $latB - $latA;
    $len2 = $ax * $ax + $ay * $ay;
    if ($len2 < 1e-12) {
        return 0.0;
    }
    $px = $lngP - $lngA;
    $py = $latP - $latA;
    return ($px * $ax + $py * $ay) / $len2;
}

/** Distância ortogonal aproximada (m) do ponto à linha A→B. */
function mz_distancia_a_linha_m(
    float $latA,
    float $lngA,
    float $latB,
    float $lngB,
    float $latP,
    float $lngP
): float {
    $t = max(0.0, min(1.0, mz_projecao_parametro($latA, $lngA, $latB, $lngB, $latP, $lngP)));
    $latC = $latA + $t * ($latB - $latA);
    $lngC = $lngA + $t * ($lngB - $lngA);
    return mz_distancia_haversine_m($latP, $lngP, $latC, $lngC);
}

/**
 * Escolhe cidades-corredor entre origem e destino (ordem geográfica).
 * @return array<int, array{0: float, 1: float}>
 */
function mz_escolher_vias_corredor(float $lat1, float $lng1, float $lat2, float $lng2): array
{
    $distTotal = mz_distancia_haversine_m($lat1, $lng1, $lat2, $lng2);
    $limiteKm  = max(80000, min(250000, $distTotal * 0.45));
    $candidatos = [];

    foreach (mz_pontos_corredor() as $p) {
        if (mz_ponto_provavel_estrangeiro($p['lat'], $p['lng'])) {
            continue;
        }

        $t = mz_projecao_parametro($lat1, $lng1, $lat2, $lng2, $p['lat'], $p['lng']);
        if ($t <= 0.03 || $t >= 0.97) {
            continue;
        }

        $distLinha = mz_distancia_a_linha_m($lat1, $lng1, $lat2, $lng2, $p['lat'], $p['lng']);
        if ($distLinha > $limiteKm) {
            continue;
        }

        $distOrigem = mz_distancia_haversine_m($lat1, $lng1, $p['lat'], $p['lng']);
        if ($distOrigem < 8000) {
            continue;
        }

        $candidatos[] = [
            't'    => $t,
            'lat'  => $p['lat'],
            'lng'  => $p['lng'],
            'nome' => $p['nome'],
            'dlin' => $distLinha,
        ];
    }

    usort($candidatos, static fn($a, $b) => $a['t'] <=> $b['t']);

    $vias = [];
    $ultimoT = -1.0;
    foreach ($candidatos as $c) {
        if ($c['t'] - $ultimoT < 0.08) {
            continue;
        }
        $vias[] = [$c['lat'], $c['lng']];
        $ultimoT = $c['t'];
        if (count($vias) >= 5) {
            break;
        }
    }

    return $vias;
}

/**
 * OSRM com múltiplos waypoints. $pontos = [[lat,lng], ...] origem → … → destino.
 * @param array<int, array{0: float, 1: float}> $pontos
 */
function calcular_rota_osrm_waypoints(array $pontos): ?array
{
    if (count($pontos) < 2) {
        return null;
    }

    $partes = [];
    foreach ($pontos as $p) {
        $partes[] = sprintf('%s,%s', (float)$p[1], (float)$p[0]);
    }

    $url = 'https://router.project-osrm.org/route/v1/driving/'
        . implode(';', $partes)
        . '?overview=full&geometries=geojson';

    if (!function_exists('http_get_string')) {
        require_once __DIR__ . '/missao-helpers.php';
    }

    $raw = http_get_string($url, 18);
    if ($raw === null) {
        return null;
    }

    $data = json_decode($raw, true);
    if (empty($data['routes'][0])) {
        return null;
    }

    $route  = $data['routes'][0];
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
        'nacional'     => true,
    ];
}

/**
 * Rota preferencialmente nacional. Usa corredores MZ se a rota directa sai do país.
 */
function calcular_rota_mocambique(
    float $lat1,
    float $lng1,
    float $lat2,
    float $lng2,
    ?callable $rotaDirecta = null
): ?array {
    if (!function_exists('calcular_rota_on_osrm_geojson')) {
        require_once __DIR__ . '/missao-helpers.php';
    }

    $origemMz = coordenadas_dentro_mocambique($lat1, $lng1) && !mz_ponto_provavel_estrangeiro($lat1, $lng1);
    $destinoMz = coordenadas_dentro_mocambique($lat2, $lng2) && !mz_ponto_provavel_estrangeiro($lat2, $lng2);

    $calcular = $rotaDirecta ?? static fn($a, $b, $c, $d) => calcular_rota_on_osrm_geojson($a, $b, $c, $d);

    // Missão internacional: rota directa
    if (!$origemMz || !$destinoMz) {
        $rota = $calcular($lat1, $lng1, $lat2, $lng2);
        if ($rota) {
            $rota['nacional'] = false;
            $rota['internacional'] = true;
        }
        return $rota;
    }

    $dist = mz_distancia_haversine_m($lat1, $lng1, $lat2, $lng2);

    $rotaDirectaResult = $calcular($lat1, $lng1, $lat2, $lng2);
    $directaNacional = $rotaDirectaResult
        && rota_geometria_nacional($rotaDirectaResult['coordinates'] ?? []);

    if ($rotaDirectaResult && $directaNacional) {
        $rotaDirectaResult['nacional'] = true;
        return $rotaDirectaResult;
    }

    // Distâncias curtas sem rota internacional detectada
    if ($dist < 25000 && $rotaDirectaResult) {
        $rotaDirectaResult['nacional'] = true;
        return $rotaDirectaResult;
    }

    $vias = mz_escolher_vias_corredor($lat1, $lng1, $lat2, $lng2);
    if (empty($vias)) {
        $vias = [[-19.6730, 34.8520]]; // Inchope — cruzamento EN1
    }

    $pontos = array_merge([[ $lat1, $lng1 ]], $vias, [[ $lat2, $lng2 ]]);
    $rotaNacional = calcular_rota_osrm_waypoints($pontos);

    if ($rotaNacional && rota_geometria_nacional($rotaNacional['coordinates'] ?? [])) {
        $rotaNacional['nacional'] = true;
        $rotaNacional['via_corredor'] = true;
        return $rotaNacional;
    }

    // Segunda tentativa: corredor mínimo Tete → Inchope → destino (eixo EN1)
    $corredorEn1 = [-19.6730, 34.8520];
    $rotaEn1 = calcular_rota_osrm_waypoints([
        [$lat1, $lng1],
        $corredorEn1,
        [$lat2, $lng2],
    ]);
    if ($rotaEn1 && rota_geometria_nacional($rotaEn1['coordinates'] ?? [])) {
        $rotaEn1['nacional'] = true;
        $rotaEn1['via_corredor'] = true;
        return $rotaEn1;
    }

    if ($rotaNacional) {
        $rotaNacional['nacional'] = true;
        $rotaNacional['via_corredor'] = true;
        $rotaNacional['aviso'] = 'Rota nacional aproximada — verifique no terreno.';
        return $rotaNacional;
    }

    if ($rotaDirectaResult) {
        $rotaDirectaResult['nacional'] = false;
        $rotaDirectaResult['aviso'] = 'Rota directa pode usar estradas internacionais.';
        return $rotaDirectaResult;
    }

    return null;
}
