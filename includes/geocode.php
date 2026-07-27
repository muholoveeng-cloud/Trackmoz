<?php
/**
 * Geocodificação de endereços/cidades em Moçambique (lookup estático + cache ficheiro).
 */

/** Limites aproximados de Moçambique (lat/lng). */
function coordenadas_dentro_mocambique(float $lat, float $lng): bool
{
    return $lat >= -27.5 && $lat <= -10.0 && $lng >= 30.0 && $lng <= 41.0;
}

function mz_normalizar_texto(string $texto): string
{
    $texto = trim(mb_strtolower($texto, 'UTF-8'));
    $mapa  = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u',
        'ç' => 'c',
    ];
    return strtr($texto, $mapa);
}

/** @return array<string, array{0: float, 1: float}> */
function mz_cidades_coordenadas(): array
{
    return [
        'maputo'         => [-25.9653, 32.5892],
        'matola'         => [-25.9622, 32.4588],
        'beira'          => [-19.8436, 34.8389],
        'nampula'        => [-15.1165, 39.2666],
        'xai-xai'        => [-25.0519, 33.6442],
        'xai xai'        => [-25.0519, 33.6442],
        'inhambane'      => [-23.8650, 35.3833],
        'manica'         => [-18.9333, 32.8667],
        'chimoio'        => [-19.1167, 33.4833],
        'tete'           => [-16.1565, 33.5867],
        'quelimane'      => [-17.8764, 36.8873],
        'pemba'          => [-12.9730, 40.5175],
        'lichinga'       => [-13.3128, 35.2406],
        'maxixe'         => [-23.8597, 35.3472],
        'angoche'        => [-16.2300, 39.9100],
        'cuamba'         => [-14.3886, 36.5372],
        'chokwe'         => [-24.5333, 32.9833],
        'dondo'          => [-19.6167, 34.7500],
        'mocuba'         => [-17.0044, 36.9850],
        'gurue'          => [-15.4667, 36.9833],
        'montepuez'      => [-13.1256, 39.1600],
        'moatize'        => [-16.0986, 33.8056],
        'mandimba'       => [-13.9833, 35.9500],
        'vilankulo'      => [-22.0033, 35.3133],
        'ponta do ouro'  => [-26.8500, 32.8833],
        'gaza'           => [-25.0519, 33.6442],
        'sofala'         => [-19.8436, 34.8389],
        'zambezia'       => [-17.8764, 36.8873],
        'niassa'         => [-13.3128, 35.2406],
        'cabo delgado'   => [-12.9730, 40.5175],
    ];
}

/**
 * Resolve coordenadas a partir de texto (ex: "Maputo", "Xai-Xai, Gaza").
 * @return array{lat: float, lng: float}|null
 */
function geocode_endereco_mz(string $endereco): ?array
{
    $endereco = trim($endereco);
    if ($endereco === '') {
        return null;
    }

    $cidades = mz_cidades_coordenadas();
    $partes  = preg_split('/[,;\-–]+/', $endereco) ?: [$endereco];

    foreach ($partes as $parte) {
        $chave = mz_normalizar_texto($parte);
        if ($chave !== '' && isset($cidades[$chave])) {
            return ['lat' => $cidades[$chave][0], 'lng' => $cidades[$chave][1]];
        }
    }

    $todo = mz_normalizar_texto($endereco);
    foreach ($cidades as $nome => $coords) {
        if (str_contains($todo, $nome)) {
            return ['lat' => $coords[0], 'lng' => $coords[1]];
        }
    }

    return geocode_nominatim_cache($endereco);
}

function geocode_cache_path(): string
{
    return dirname(__DIR__) . '/storage/geocode-cache.json';
}

/** @return array{lat: float, lng: float}|null */
function geocode_nominatim_cache(string $endereco): ?array
{
    $cacheFile = geocode_cache_path();
    $cache     = [];
    if (is_file($cacheFile)) {
        $cache = json_decode((string)file_get_contents($cacheFile), true) ?: [];
    }

    $key = mz_normalizar_texto($endereco);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $query = urlencode($endereco . ', Moçambique');
    $url   = 'https://nominatim.openstreetmap.org/search?q=' . $query
           . '&format=json&limit=1&countrycodes=mz'
           . '&viewbox=30.0,-27.5,41.0,-10.0&bounded=1';

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 5,
            'header'  => "User-Agent: TrackMoz/1.0 (freight tracking)\r\n",
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return null;
    }

    $data = json_decode($raw, true);
    if (empty($data[0]['lat']) || empty($data[0]['lon'])) {
        return null;
    }

    $result = ['lat' => (float)$data[0]['lat'], 'lng' => (float)$data[0]['lon']];
    if (!coordenadas_dentro_mocambique($result['lat'], $result['lng'])) {
        return null;
    }
    $cache[$key] = $result;

    $dir = dirname($cacheFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT));

    return $result;
}

/**
 * Pesquisa Nominatim com múltiplas sugestões.
 * @return list<array{nome: string, endereco: string, lat: float, lng: float}>
 */
function nominatim_pesquisar(string $query, int $limit = 6): array
{
    $query = trim($query);
    if (mb_strlen($query) < 2) {
        return [];
    }

    $q = urlencode($query . ', Moçambique');
    $url = 'https://nominatim.openstreetmap.org/search?q=' . $q
         . '&format=json&limit=' . max(1, min(10, $limit))
         . '&countrycodes=mz&addressdetails=1'
         . '&viewbox=30.0,-27.5,41.0,-10.0&bounded=1';

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 6,
            'header'  => "User-Agent: TrackMoz-TMS/1.0 (freight logistics)\r\n",
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }

    $resultados = [];
    foreach ($data as $item) {
        if (empty($item['lat']) || empty($item['lon'])) {
            continue;
        }
        $lat = (float)$item['lat'];
        $lng = (float)$item['lon'];
        if (!coordenadas_dentro_mocambique($lat, $lng)) {
            continue;
        }
        $nome = $item['name'] ?? ($item['display_name'] ?? $query);
        $resultados[] = [
            'nome'     => (string)$nome,
            'endereco' => (string)($item['display_name'] ?? $nome),
            'lat'      => $lat,
            'lng'      => $lng,
        ];
    }
    return $resultados;
}

/**
 * Geocodificação inversa (coordenadas → endereço aproximado).
 * @return array{nome: string, endereco: string, lat: float, lng: float}|null
 */
function reverse_geocode_mz(float $lat, float $lng): ?array
{
    if (!coordenadas_dentro_mocambique($lat, $lng)) {
        return null;
    }

    $url = sprintf(
        'https://nominatim.openstreetmap.org/reverse?lat=%s&lon=%s&format=json&addressdetails=1',
        $lat,
        $lng
    );

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 5,
            'header'  => "User-Agent: TrackMoz-TMS/1.0 (freight logistics)\r\n",
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return [
            'nome'     => 'Local no mapa',
            'endereco' => sprintf('%.5f, %.5f', $lat, $lng),
            'lat'      => $lat,
            'lng'      => $lng,
        ];
    }

    $data = json_decode($raw, true);
    if (empty($data['display_name'])) {
        return [
            'nome'     => 'Local no mapa',
            'endereco' => sprintf('%.5f, %.5f', $lat, $lng),
            'lat'      => $lat,
            'lng'      => $lng,
        ];
    }

    return [
        'nome'     => (string)($data['name'] ?? 'Local no mapa'),
        'endereco' => (string)$data['display_name'],
        'lat'      => $lat,
        'lng'      => $lng,
    ];
}

/**
 * Cria registo em `locais` e devolve o ID.
 */
function criar_local(PDO $conn, string $endereco, float $lat, float $lng, ?string $nome = null): int
{
    try {
        $stmt = $conn->prepare(
            'INSERT INTO locais (nome, endereco, latitude, longitude) VALUES (:nome, :endereco, :lat, :lng)'
        );
        $stmt->execute([':nome' => $nome, ':endereco' => $endereco, ':lat' => $lat, ':lng' => $lng]);
    } catch (PDOException $e) {
        $stmt = $conn->prepare(
            'INSERT INTO locais (endereco, latitude, longitude) VALUES (:endereco, :lat, :lng)'
        );
        $stmt->execute([':endereco' => $endereco, ':lat' => $lat, ':lng' => $lng]);
    }
    return (int)$conn->lastInsertId();
}

/**
 * Garante local_origem_id / local_destino_id na missão quando possível.
 */
function garantir_locais_missao(PDO $conn, int $missaoId): void
{
    $stmt = $conn->prepare(
        'SELECT m.id, m.origem, m.destino, m.local_origem_id, m.local_destino_id,
                lo.endereco AS origem_endereco, lo.latitude AS origem_lat, lo.longitude AS origem_lng,
                ld.endereco AS destino_endereco, ld.latitude AS destino_lat, ld.longitude AS destino_lng
         FROM missoes m
         LEFT JOIN locais lo ON m.local_origem_id = lo.id
         LEFT JOIN locais ld ON m.local_destino_id = ld.id
         WHERE m.id = :id'
    );
    $stmt->execute([':id' => $missaoId]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) {
        return;
    }

    $updates = [];
    $params  = [':id' => $missaoId];

    $origemCoordsOk = !empty($m['origem_lat']) && !empty($m['origem_lng'])
        && coordenadas_dentro_mocambique((float)$m['origem_lat'], (float)$m['origem_lng']);

    if (!$origemCoordsOk && !empty($m['origem'])) {
        $coords = geocode_endereco_mz($m['origem']);
        if ($coords) {
            if (!empty($m['local_origem_id'])) {
                $conn->prepare(
                    'UPDATE locais SET endereco = :endereco, latitude = :lat, longitude = :lng WHERE id = :id'
                )->execute([
                    ':endereco' => $m['origem'],
                    ':lat'      => $coords['lat'],
                    ':lng'      => $coords['lng'],
                    ':id'       => $m['local_origem_id'],
                ]);
            } else {
                $localId = criar_local($conn, $m['origem'], $coords['lat'], $coords['lng']);
                $updates[] = 'local_origem_id = :loid';
                $params[':loid'] = $localId;
            }
        }
    }

    $destinoCoordsOk = !empty($m['destino_lat']) && !empty($m['destino_lng'])
        && coordenadas_dentro_mocambique((float)$m['destino_lat'], (float)$m['destino_lng']);

    if (!$destinoCoordsOk && !empty($m['destino'])) {
        $coords = geocode_endereco_mz($m['destino']);
        if ($coords) {
            if (!empty($m['local_destino_id'])) {
                $conn->prepare(
                    'UPDATE locais SET endereco = :endereco, latitude = :lat, longitude = :lng WHERE id = :id'
                )->execute([
                    ':endereco' => $m['destino'],
                    ':lat'      => $coords['lat'],
                    ':lng'      => $coords['lng'],
                    ':id'       => $m['local_destino_id'],
                ]);
            } else {
                $localId = criar_local($conn, $m['destino'], $coords['lat'], $coords['lng']);
                $updates[] = 'local_destino_id = :ldid';
                $params[':ldid'] = $localId;
            }
        }
    }

    if ($updates) {
        $sql = 'UPDATE missoes SET ' . implode(', ', $updates) . ' WHERE id = :id';
        $conn->prepare($sql)->execute($params);
    }
}

/**
 * Enriquece linha de missão com coordenadas resolvidas.
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function enriquecer_missao_mapa(array $row): array
{
    if ((empty($row['origem_lat']) || empty($row['origem_lng'])
            || !coordenadas_dentro_mocambique((float)$row['origem_lat'], (float)$row['origem_lng']))
        && !empty($row['origem'])) {
        $c = geocode_endereco_mz((string)$row['origem']);
        if ($c) {
            $row['origem_lat'] = $c['lat'];
            $row['origem_lng'] = $c['lng'];
            $row['origem_geocoded'] = true;
        }
    }
    if ((empty($row['destino_lat']) || empty($row['destino_lng'])
            || !coordenadas_dentro_mocambique((float)$row['destino_lat'], (float)$row['destino_lng']))
        && !empty($row['destino'])) {
        $c = geocode_endereco_mz((string)$row['destino']);
        if ($c) {
            $row['destino_lat'] = $c['lat'];
            $row['destino_lng'] = $c['lng'];
            $row['destino_geocoded'] = true;
        }
    }

    return $row;
}
