<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/regras-negocio.php');

// Verificar se o usuário está logado e é um caminhoneiro
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'caminhoneiro') {
    header('Location: ' . BASE_URL . '/pages/login.php');
    exit;
}

// Verificar se o ID da proposta foi fornecido
if (!isset($_GET['id'])) {
    header('Location: ' . BASE_URL . '/pages/caminhoneiro/propostas.php?error=ID da proposta não fornecido');
    exit;
}

$proposta_id = intval($_GET['id']);
$caminhoneiro_id = (int)$_SESSION['user_id'];

try {
    // Buscar informações da proposta
    $sql = "SELECT p.*, m.id as missao_id, m.empresa_id, m.status as missao_status, 
            m.local_origem_id, m.local_destino_id, m.descricao, m.tipo_carga,
            m.peso_carga as peso, m.prazo_entrega, m.valor
            FROM propostas p
            JOIN missoes m ON p.missao_id = m.id
            WHERE p.id = ? AND p.caminhoneiro_id = ? AND p.status = 'pendente'";
            
    $stmt = $conn->prepare($sql);
    $stmt->execute([$proposta_id, $caminhoneiro_id]);
    $proposta = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$proposta) {
        header('Location: ' . BASE_URL . '/pages/caminhoneiro/propostas.php?error=Proposta não encontrada ou já processada');
        exit;
    }
    
    // Bloquear apenas se já tiver missão em execução (agendadas são permitidas)
    if (motorista_tem_missao_ativa($conn, $caminhoneiro_id)) {
        header('Location: ' . BASE_URL . '/pages/caminhoneiro/propostas.php?error=' . urlencode('Você já possui uma missão em andamento. Finalize a missão actual antes de aceitar outra.'));
        exit;
    }
    
    // Iniciar transação
    $conn->beginTransaction();
    
    // Atualizar status da proposta
    $sql = "UPDATE propostas SET status = 'aceita', data_atualizacao = NOW() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$proposta_id]);
    
    // Missão fica agendada — execução só após concluir missão actual
    $sql = "UPDATE missoes SET 
            status = 'aceita', 
            caminhoneiro_id = ?, 
            ultima_atualizacao = NOW()
            WHERE id = ?";
            
    $stmt = $conn->prepare($sql);
    $stmt->execute([$caminhoneiro_id, $proposta['missao_id']]);
    
    // Disponibilidade só muda quando iniciar execução, não ao aceitar/agendar
    // Rejeitar automaticamente outras propostas para esta missão
    $sql = "UPDATE propostas 
            SET status = 'rejeitada', data_atualizacao = NOW(), 
            observacoes = 'Rejeitada automaticamente: outra proposta foi aceita'
            WHERE missao_id = ? AND id != ? AND status = 'pendente'";
            
    $stmt = $conn->prepare($sql);
    $stmt->execute([$proposta['missao_id'], $proposta_id]);
    
    // Registrar no log
    $sql = "INSERT INTO registros_viagem (missao_id, tipo, descricao, data_registro) 
            VALUES (?, 'aceitacao', 'Proposta aceita pelo caminhoneiro', NOW())";
            
    $stmt = $conn->prepare($sql);
    $stmt->execute([$proposta['missao_id']]);
    
    // Notificar o contratante
    $sql = "INSERT INTO notificacoes (
            usuario_id, tipo, titulo, mensagem, link, data_criacao, lida
            ) VALUES (
            ?, 'proposta_aceita', 'Proposta Aceita', 
            ?, 
            ?, 
            NOW(), 0
            )";
            
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        $proposta['empresa_id'],
        'Sua proposta para a missão #' . $proposta['missao_id'] . ' foi aceita pelo caminhoneiro.',
        BASE_URL . '/pages/contratante/visualizar-missao.php?id=' . $proposta['missao_id']
    ]);
    
    // Confirmar transação
    $conn->commit();
    
    // Redirecionar com mensagem de sucesso
    header('Location: ' . BASE_URL . '/pages/caminhoneiro/missoes.php?status=agendada&success=' . urlencode('Proposta aceita! A missão ficou agendada — inicie quando estiver disponível.'));
    exit;
    
} catch (PDOException $e) {
    // Reverter transação em caso de erro
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    // Registrar erro no log
    error_log('Erro ao aceitar proposta: ' . $e->getMessage());
    
    // Redirecionar com mensagem de erro
    header('Location: ' . BASE_URL . '/pages/caminhoneiro/propostas.php?error=Erro ao aceitar proposta');
    exit;
}
?> 