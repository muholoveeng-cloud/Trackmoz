<?php
/**
 * API: Geocodificar endereço em Moçambique
 * GET: q=Maputo
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

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Parâmetro q obrigatório']);
    exit;
}

$coords = geocode_endereco_mz($q);
if (!$coords) {
    echo json_encode(['ok' => false, 'error' => 'Local não encontrado']);
    exit;
}

echo json_encode(['ok' => true, 'lat' => $coords['lat'], 'lng' => $coords['lng'], 'query' => $q]);
