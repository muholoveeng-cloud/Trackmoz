<?php
/**
 * API: Pesquisa de locais via Nominatim (sugestões).
 * GET: q=texto&limit=6
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
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 6;

if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'sugestoes' => []]);
    exit;
}

$sugestoes = nominatim_pesquisar($q, $limit);
echo json_encode(['ok' => true, 'sugestoes' => $sugestoes]);
