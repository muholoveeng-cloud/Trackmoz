<?php
/**
 * API: Actualizar localização GPS do motorista (TMS)
 * POST: latitude, longitude, missao_id?, speed?, heading?, accuracy?, vehicle_id?
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
include_once('../config/database.php');
include_once('../includes/localizacao-service.php');
include_once('../includes/offline-sync-helpers.php');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Não autenticado']);
    exit;
}

if (($_SESSION['user_type'] ?? '') !== 'caminhoneiro') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acesso negado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método inválido']);
    exit;
}

$input = $_POST;
if (empty($input) && ($raw = file_get_contents('php://input'))) {
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $input = $json;
    }
}

$user_id   = (int)$_SESSION['user_id'];
$latitude  = isset($input['latitude'])  ? (float)$input['latitude']  : null;
$longitude = isset($input['longitude']) ? (float)$input['longitude'] : null;
$missao_id = isset($input['missao_id']) ? (int)$input['missao_id']   : null;
$speed     = isset($input['speed'])     ? (float)$input['speed']     : null;
$heading   = isset($input['heading'])   ? (float)$input['heading']   : null;
$accuracy  = isset($input['accuracy'])  ? (float)$input['accuracy']  : null;
$vehicle_id = isset($input['vehicle_id']) ? (int)$input['vehicle_id'] : null;
$clientOpId = trim((string)($input['client_op_id'] ?? ''));

if ($latitude === null || $longitude === null
    || $latitude < -90 || $latitude > 90
    || $longitude < -180 || $longitude > 180) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Coordenadas inválidas']);
    exit;
}

if ($clientOpId !== '') {
    $prev = tmz_sync_find($conn, $clientOpId);
    if (is_array($prev)) {
        $prev['duplicate'] = true;
        $prev['ok'] = $prev['ok'] ?? true;
        echo json_encode($prev);
        exit;
    }
}

try {
    $result = tms_registrar_posicao(
        $conn, $user_id, $latitude, $longitude,
        $missao_id ?: null, $speed, $heading, $accuracy, $vehicle_id
    );

    if (!$result['ok']) {
        http_response_code(403);
        echo json_encode($result);
        exit;
    }

    $payload = [
        'ok'         => true,
        'checkpoint' => $result['checkpoint'] ?? null,
        'timestamp'  => date('c'),
    ];

    if ($clientOpId !== '') {
        tmz_sync_store($conn, $clientOpId, $user_id, 'gps', $missao_id ?: null, $payload);
    }

    echo json_encode($payload);
} catch (Throwable $e) {
    error_log('update-localizacao: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erro interno']);
}
