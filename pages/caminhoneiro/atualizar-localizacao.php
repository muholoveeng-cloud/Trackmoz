<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
include_once('../../config/database.php');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'caminhoneiro') {
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

if (!isset($_POST['latitude'], $_POST['longitude'])) {
    echo json_encode(['success' => false, 'message' => 'Parâmetros insuficientes']);
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$latitude  = (float)$_POST['latitude'];
$longitude = (float)$_POST['longitude'];
$missao_id = isset($_POST['missao_id']) ? (int)$_POST['missao_id'] : null;

// Validar se missão pertence ao caminhoneiro (quando fornecida)
if ($missao_id) {
    $chk = $conn->prepare("SELECT id FROM missoes WHERE id = :mid AND caminhoneiro_id = :uid");
    $chk->execute([':mid' => $missao_id, ':uid' => $user_id]);
    if (!$chk->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Missão não encontrada']);
        exit;
    }
}

try {
    // Actualizar posição actual no perfil
    $stmt = $conn->prepare(
        "UPDATE perfil_caminhoneiro
         SET ultima_localizacao_lat   = :lat,
             ultima_localizacao_lng   = :lng,
             ultima_atualizacao_local = NOW()
         WHERE usuario_id = :uid"
    );
    $stmt->execute([':lat' => $latitude, ':lng' => $longitude, ':uid' => $user_id]);

    // Registar no histórico
    $hist = $conn->prepare(
        "INSERT INTO historico_localizacao (usuario_id, latitude, longitude, data_registro)
         VALUES (:uid, :lat, :lng, NOW())"
    );
    $hist->execute([':uid' => $user_id, ':lat' => $latitude, ':lng' => $longitude]);

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    error_log('atualizar-localizacao.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro ao processar']);
}
