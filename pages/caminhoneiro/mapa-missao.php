<?php
/**
 * Redirecciona para detalhes da missão (mapa integrado).
 */
session_start();
include_once('../../config/app.php');

$missao_id = isset($_GET['missao_id']) ? (int)$_GET['missao_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
if ($missao_id <= 0) {
    header('Location: ' . BASE_URL . '/pages/caminhoneiro/missoes.php');
    exit;
}
header('Location: ' . BASE_URL . '/pages/caminhoneiro/detalhes-missao.php?id=' . $missao_id);
exit;
