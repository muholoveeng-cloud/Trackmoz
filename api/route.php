<?php
/**
 * API de rota — proxy server-side OSRM com fallback.
 * GET: from_lat, from_lng, to_lat, to_lng
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/missao-helpers.php';
require_once __DIR__ . '/../includes/rota-mocambique.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Não autenticado']);
    exit;
}

$fromLat = isset($_GET['from_lat']) ? (float)$_GET['from_lat'] : 0;
$fromLng = isset($_GET['from_lng']) ? (float)$_GET['from_lng'] : 0;
$toLat   = isset($_GET['to_lat'])   ? (float)$_GET['to_lat']   : 0;
$toLng   = isset($_GET['to_lng'])   ? (float)$_GET['to_lng']   : 0;

if (!$fromLat || !$fromLng || !$toLat || !$toLng) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Coordenadas inválidas']);
    exit;
}

try {
    $rota = calcular_rota_mocambique($fromLat, $fromLng, $toLat, $toLng);
    if (!$rota) {
        $rota = calcular_rota_fallback($fromLat, $fromLng, $toLat, $toLng);
        error_log(sprintf(
            'route.php: fallback recto (%s,%s)->(%s,%s)',
            $fromLat, $fromLng, $toLat, $toLng
        ));
    }

    echo json_encode([
        'ok'           => true,
        'distancia_km' => $rota['distancia_km'],
        'tempo_min'    => $rota['tempo_min'],
        'distancia_m'  => $rota['distancia_m'],
        'duracao_s'    => $rota['duracao_s'],
        'coordinates'  => $rota['coordinates'],
        'fallback'     => (bool)($rota['fallback'] ?? false),
        'nacional'     => (bool)($rota['nacional'] ?? true),
        'via_corredor' => (bool)($rota['via_corredor'] ?? false),
        'aviso'        => $rota['aviso'] ?? null,
    ]);
} catch (Throwable $e) {
    error_log('route.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erro ao calcular rota']);
}
