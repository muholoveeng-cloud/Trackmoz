<?php
/**
 * API: Geocodificação inversa (clique no mapa → endereço).
 * GET: lat=&lng=
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

include_once('../config/database.php');
include_once('../includes/geocode.php');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Não autenticado']);
    exit;
}

$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : 0;
$lng = isset($_GET['lng']) ? (float)$_GET['lng'] : 0;

if (!$lat || !$lng) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Coordenadas inválidas']);
    exit;
}

$result = reverse_geocode_mz($lat, $lng);
if (!$result) {
    echo json_encode(['ok' => false, 'error' => 'Fora de Moçambique']);
    exit;
}

echo json_encode(['ok' => true, ...$result]);
