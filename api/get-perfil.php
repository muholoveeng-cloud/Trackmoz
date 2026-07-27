<?php
/**
 * API: Retorna dados do perfil do usuário autenticado
 * Suporta: caminhoneiro, empresa, transportador, admin
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
include_once('../config/database.php');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Não autenticado']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

try {
    // Buscar dados básicos do usuário
    $sql = "SELECT id, nome, email, telefone, tipo_usuario, foto_perfil, data_registro, status
            FROM usuarios 
            WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $user_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Usuário não encontrado']);
        exit;
    }

    // Buscar dados específicos do perfil baseado no tipo
    if ($user_type === 'caminhoneiro') {
        $sql = "SELECT * FROM perfil_caminhoneiro WHERE usuario_id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $user_id]);
        $perfil = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Buscar fotos do veículo
        $sql = "SELECT * FROM fotos_veiculo WHERE usuario_id = :usuario_id ORDER BY data_upload DESC";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':usuario_id' => $user_id]);
        $fotos_veiculo = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $usuario['perfil'] = $perfil;
        $usuario['fotos_veiculo'] = $fotos_veiculo;
        
    } elseif ($user_type === 'empresa') {
        $sql = "SELECT * FROM perfil_empresa WHERE usuario_id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $user_id]);
        $perfil = $stmt->fetch(PDO::FETCH_ASSOC);
        $usuario['perfil'] = $perfil;
        
    } elseif ($user_type === 'transportador') {
        $sql = "SELECT * FROM perfil_transportador WHERE usuario_id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $user_id]);
        $perfil = $stmt->fetch(PDO::FETCH_ASSOC);
        $usuario['perfil'] = $perfil;
    }

    echo json_encode(['ok' => true, 'usuario' => $usuario]);

} catch (PDOException $e) {
    error_log('get-perfil: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erro interno']);
}
