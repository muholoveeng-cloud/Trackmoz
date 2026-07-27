<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');

require_role(['transportador'], '../login.php');

if (!isset($_GET['id'])) {
    header('Location: ' . BASE_URL . '/pages/caminhoneiro/propostas.php?error=ID da proposta não fornecido');
    exit;
}

$proposta_id = (int)$_GET['id'];
$transportador_id = (int)($_SESSION['user_id'] ?? 0);

try {
    // Regra: transportador precisa ter pelo menos 1 veículo ativo
    $stmt = $conn->prepare("SELECT COUNT(*) FROM transportador_veiculos WHERE transportador_id = :id AND status = 'ativo'");
    $stmt->execute([':id' => $transportador_id]);
    $ativos = (int)$stmt->fetchColumn();

    if ($ativos <= 0) {
        header('Location: ' . BASE_URL . '/pages/transportador/frota.php');
        exit;
    }

    // Buscar proposta (precisa ser do próprio usuário) e missão aberta/negociável
    $sql = "SELECT p.*, m.id as missao_id, m.empresa_id, m.status as missao_status
            FROM propostas p
            JOIN missoes m ON p.missao_id = m.id
            WHERE p.id = ? AND p.caminhoneiro_id = ? AND p.status = 'pendente'";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$proposta_id, $transportador_id]);
    $proposta = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$proposta) {
        header('Location: ' . BASE_URL . '/pages/caminhoneiro/propostas.php?error=Proposta não encontrada ou já processada');
        exit;
    }

    $conn->beginTransaction();

    // Atualizar status da proposta
    $stmt = $conn->prepare("UPDATE propostas SET status = 'aceita', data_atualizacao = NOW() WHERE id = ?");
    $stmt->execute([$proposta_id]);

    // Atualizar missão: vincular ao transportador e marcar aceita
    $stmt = $conn->prepare("UPDATE missoes SET status = 'aceita', transportador_id = ?, ultima_atualizacao = NOW() WHERE id = ?");
    $stmt->execute([$transportador_id, $proposta['missao_id']]);

    // Rejeitar automaticamente outras propostas para esta missão
    $stmt = $conn->prepare("UPDATE propostas 
            SET status = 'rejeitada', data_atualizacao = NOW(), 
            observacoes = 'Rejeitada automaticamente: outra proposta foi aceita'
            WHERE missao_id = ? AND id != ? AND status = 'pendente'");
    $stmt->execute([$proposta['missao_id'], $proposta_id]);

    // Registrar no log
    $stmt = $conn->prepare("INSERT INTO registros_viagem (missao_id, tipo, descricao, data_registro) 
            VALUES (?, 'aceitacao', 'Proposta aceita pelo transportador', NOW())");
    $stmt->execute([$proposta['missao_id']]);

    // Notificar a empresa
    $stmt = $conn->prepare("INSERT INTO notificacoes (
            usuario_id, tipo, titulo, mensagem, link, data_criacao, lida
            ) VALUES (
            ?, 'proposta_aceita', 'Proposta Aceita',
            ?,
            ?,
            NOW(), 0
            )");
    $stmt->execute([
        $proposta['empresa_id'],
        'Sua missão #' . $proposta['missao_id'] . ' foi assumida por um transportador.',
        BASE_URL . '/pages/contratante/visualizar-missao.php?id=' . $proposta['missao_id']
    ]);

    $conn->commit();

    header('Location: ' . BASE_URL . '/pages/transportador/dashboard.php?success=Proposta aceita com sucesso!');
    exit;

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Erro ao aceitar proposta (transportador): ' . $e->getMessage());
    header('Location: ' . BASE_URL . '/pages/caminhoneiro/propostas.php?error=Erro ao aceitar proposta: ' . urlencode($e->getMessage()));
    exit;
}
