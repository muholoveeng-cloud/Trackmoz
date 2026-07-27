<?php
session_start();
include_once('../../config/database.php');
include_once('../../includes/motorista-regras.php');

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'caminhoneiro') {
    echo json_encode(['success' => false, 'message' => 'Utilizador não autorizado']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método de requisição inválido']);
    exit;
}

$disponibilidade = isset($_POST['disponibilidade']) ? trim((string)$_POST['disponibilidade']) : '';

if (!in_array($disponibilidade, ['disponivel', 'indisponivel', 'ocupado', 'manutencao'], true)) {
    echo json_encode(['success' => false, 'message' => 'Valor de disponibilidade inválido']);
    exit;
}

try {
    // Em missão activa: não pode marcar-se indisponível/manutenção
    // (afecta aceitação de fretes e lista de independentes disponíveis)
    if (in_array($disponibilidade, ['indisponivel', 'manutencao'], true)
        && motorista_tem_missao_ativa($conn, $user_id)) {
        $activa = motorista_missao_ativa($conn, $user_id);
        $titulo = $activa['titulo'] ?? ('#' . ($activa['id'] ?? ''));
        echo json_encode([
            'success' => false,
            'message' => 'Não pode ficar indisponível enquanto tem a missão activa "' . $titulo . '". Conclua a entrega ou mantenha o estado Ocupado.',
            'code' => 'missao_activa',
            'forced' => 'ocupado',
        ]);
        exit;
    }

    $sql = "UPDATE perfil_caminhoneiro
            SET disponibilidade = :disponibilidade
            WHERE usuario_id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':disponibilidade' => $disponibilidade,
        ':id' => $user_id,
    ]);

    if ($stmt->rowCount() > 0) {
        $response['success'] = true;
        $response['message'] = 'Disponibilidade actualizada com sucesso';
        $response['disponibilidade'] = $disponibilidade;
    } else {
        $check_stmt = $conn->prepare('SELECT COUNT(*) FROM perfil_caminhoneiro WHERE usuario_id = :id');
        $check_stmt->execute([':id' => $user_id]);
        $profile_exists = (int)$check_stmt->fetchColumn();

        if (!$profile_exists) {
            $init_stmt = $conn->prepare(
                "INSERT INTO perfil_caminhoneiro
                 (usuario_id, tipo_veiculo, placa_veiculo, capacidade_carga, descricao_veiculo, disponibilidade)
                 VALUES (:id, 'Não informado', 'Não informado', 0, 'Não informado', :disponibilidade)"
            );
            $init_stmt->execute([
                ':id' => $user_id,
                ':disponibilidade' => $disponibilidade,
            ]);
            $response['success'] = true;
            $response['message'] = 'Perfil criado e disponibilidade actualizada com sucesso';
            $response['disponibilidade'] = $disponibilidade;
        } else {
            // Mesmo valor já gravado
            $response['success'] = true;
            $response['message'] = 'Disponibilidade mantida';
            $response['disponibilidade'] = $disponibilidade;
        }
    }
} catch (PDOException $e) {
    error_log('atualizar-disponibilidade: ' . $e->getMessage());
    $response['message'] = 'Erro ao actualizar disponibilidade.';
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
