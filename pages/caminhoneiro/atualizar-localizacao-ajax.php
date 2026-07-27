<?php
session_start();
include_once('../../config/database.php');

// Definir cabeçalho para retornar JSON
header('Content-Type: application/json');

// Verificar autenticação
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'caminhoneiro') {
    echo json_encode(['success' => false, 'message' => 'Usuário não autorizado']);
    exit;
}

$user_id = $_SESSION['user_id'];
$response = ['success' => false, 'message' => ''];

// Verificar se os dados foram enviados
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $latitude = isset($_POST['latitude']) ? floatval($_POST['latitude']) : null;
    $longitude = isset($_POST['longitude']) ? floatval($_POST['longitude']) : null;
    
    if ($latitude !== null && $longitude !== null) {
        try {
            // Atualizar a localização no perfil do caminhoneiro
            $sql = "UPDATE perfil_caminhoneiro 
                    SET ultima_localizacao_lat = :latitude,
                        ultima_localizacao_lng = :longitude,
                        ultima_atualizacao_local = NOW() 
                    WHERE usuario_id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':latitude' => $latitude,
                ':longitude' => $longitude,
                ':id' => $user_id
            ]);
            
            // Verificar se a atualização foi bem-sucedida
            if ($stmt->rowCount() > 0) {
                $response['success'] = true;
                $response['message'] = 'Localização atualizada com sucesso';
            } else {
                // Verificar se o perfil existe
                $check_sql = "SELECT COUNT(*) FROM perfil_caminhoneiro WHERE usuario_id = :id";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->execute([':id' => $user_id]);
                $profile_exists = (int)$check_stmt->fetchColumn();
                
                if (!$profile_exists) {
                    // Criar um perfil se não existir
                    $init_sql = "INSERT INTO perfil_caminhoneiro 
                                (usuario_id, tipo_veiculo, placa_veiculo, capacidade_carga, 
                                 descricao_veiculo, disponibilidade, ultima_localizacao_lat,
                                 ultima_localizacao_lng, ultima_atualizacao_local) 
                                VALUES 
                                (:id, 'Não informado', 'Não informado', 0, 
                                'Não informado', 'indisponivel', :latitude, :longitude, NOW())";
                    $init_stmt = $conn->prepare($init_sql);
                    $init_stmt->execute([
                        ':id' => $user_id,
                        ':latitude' => $latitude,
                        ':longitude' => $longitude
                    ]);
                    
                    $response['success'] = true;
                    $response['message'] = 'Perfil criado e localização atualizada com sucesso';
                } else {
                    $response['success'] = true;
                    $response['message'] = 'Localização já estava atualizada';
                }
            }
        } catch (PDOException $e) {
            $response['message'] = 'Erro ao atualizar localização: ' . $e->getMessage();
        }
    } else {
        $response['message'] = 'Coordenadas inválidas';
    }
} else {
    $response['message'] = 'Método de requisição inválido';
}

echo json_encode($response); 