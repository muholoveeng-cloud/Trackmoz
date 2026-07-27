<?php
session_start();
include_once('../config/database.php');

// Verificar se o usuário está autenticado
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Não autorizado'
    ]);
    exit;
}

// Verificar se foi fornecido o ID da proposta
if (!isset($_GET['id'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'ID da proposta não fornecido'
    ]);
    exit;
}

$proposta_id = intval($_GET['id']);
$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

try {
    // Consulta diferente dependendo do tipo de usuário
    if ($user_type == 'caminhoneiro') {
        $sql = "SELECT p.*, m.empresa_id, m.id AS missao_id 
                FROM propostas p
                JOIN missoes m ON p.missao_id = m.id
                WHERE p.id = ? AND p.caminhoneiro_id = ?";
        $params = [$proposta_id, $user_id];
    } else if ($user_type == 'empresa') {
        $sql = "SELECT p.*, m.caminhoneiro_id, m.id AS missao_id 
                FROM propostas p
                JOIN missoes m ON p.missao_id = m.id
                WHERE p.id = ? AND m.empresa_id = ?";
        $params = [$proposta_id, $user_id];
    } else {
        // Para administradores ou outros tipos
        $sql = "SELECT p.*, m.empresa_id, m.caminhoneiro_id, m.id AS missao_id 
                FROM propostas p
                JOIN missoes m ON p.missao_id = m.id
                WHERE p.id = ?";
        $params = [$proposta_id];
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $proposta = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$proposta) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Proposta não encontrada ou você não tem permissão para acessá-la'
        ]);
        exit;
    }
    
    // Retornar dados da proposta
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'proposta' => $proposta,
        'empresa_id' => $proposta['empresa_id'] ?? null,
        'caminhoneiro_id' => $proposta['caminhoneiro_id'] ?? null,
        'missao_id' => $proposta['missao_id'] ?? null
    ]);
    
} catch (PDOException $e) {
    // Registrar erro no log
    error_log('Erro ao obter dados da proposta: ' . $e->getMessage());
    
    // Retornar erro
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao obter dados da proposta'
    ]);
}
?> 