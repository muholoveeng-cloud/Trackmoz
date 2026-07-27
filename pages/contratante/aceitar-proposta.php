<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');
include_once('../../includes/regras-negocio.php');
include_once('../../includes/missao-helpers.php');
include_once('../../includes/notificacoes-helpers.php');

require_role(['empresa'], '../login.php');

$empresa_id = $_SESSION['user_id'];

// Validar parâmetros
if (!isset($_GET['proposta']) || !isset($_GET['missao'])) {
    header('Location: ' . BASE_URL . '/pages/contratante/missoes.php?error=Parâmetros inválidos');
    exit;
}

$proposta_id = (int)$_GET['proposta'];
$missao_id   = (int)$_GET['missao'];

try {
    // Verificar se a missão pertence à empresa
    $sql = "SELECT id, status, caminhoneiro_id FROM missoes 
            WHERE id = :missao_id AND empresa_id = :empresa_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':missao_id'  => $missao_id,
        ':empresa_id' => $empresa_id
    ]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) {
        header('Location: ' . BASE_URL . '/pages/contratante/missoes.php?error=Missão não encontrada');
        exit;
    }

    if ($missao['status'] !== 'aberta') {
        header('Location: ' . BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id . '&error=Missão não está mais aberta');
        exit;
    }

    // Buscar proposta válida
    $sql = "SELECT p.id, p.caminhoneiro_id, p.status, p.valor,
                   u.nome as nome_caminhoneiro
            FROM propostas p
            JOIN usuarios u ON p.caminhoneiro_id = u.id
            WHERE p.id = :proposta_id AND p.missao_id = :missao_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':proposta_id' => $proposta_id,
        ':missao_id'   => $missao_id
    ]);
    $proposta = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$proposta) {
        header('Location: ' . BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id . '&error=Proposta não encontrada');
        exit;
    }

    if ($proposta['status'] !== 'pendente') {
        header('Location: ' . BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id . '&error=Proposta já foi processada');
        exit;
    }

    $pesoCheck = validar_peso_capacidade_missao($conn, $missao_id, (int)$proposta['caminhoneiro_id']);
    if (!$pesoCheck['ok']) {
        header('Location: ' . BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id . '&error=' . urlencode(implode(' ', $pesoCheck['erros'])));
        exit;
    }

    $motoristaCheck = validar_motorista_nova_missao($conn, (int)$proposta['caminhoneiro_id']);
    if (!$motoristaCheck['ok']) {
        header('Location: ' . BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id . '&error=' . urlencode(implode(' ', $motoristaCheck['erros'])));
        exit;
    }

    // Verificar se já existe uma proposta aceita para esta missão
    $sql = "SELECT id FROM propostas WHERE missao_id = :missao_id AND status = 'aceita'";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':missao_id' => $missao_id]);
    if ($stmt->fetch()) {
        header('Location: ' . BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id . '&error=Já existe uma proposta aceita para esta missão');
        exit;
    }

    $conn->beginTransaction();

    // Aceitar a proposta
    $sql = "UPDATE propostas SET status = 'aceita', data_atualizacao = NOW() 
            WHERE id = :id AND missao_id = :missao_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':id'         => $proposta_id,
        ':missao_id'  => $missao_id
    ]);

    // Atualizar missão — agendada, não em execução
    $sql = "UPDATE missoes SET 
            status = 'aceita',
            caminhoneiro_id = :caminhoneiro_id,
            ultima_atualizacao = NOW()
            WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':caminhoneiro_id' => (int)$proposta['caminhoneiro_id'],
        ':id'              => $missao_id
    ]);

    // Rejeitar outras propostas automaticamente
    $sql = "UPDATE propostas 
            SET status = 'rejeitada', data_atualizacao = NOW(),
                observacoes = 'Rejeitada automaticamente: outra proposta foi aceita'
            WHERE missao_id = :missao_id AND id != :proposta_id AND status = 'pendente'";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':missao_id'   => $missao_id,
        ':proposta_id' => $proposta_id
    ]);

    // Registrar log
    $sql = "INSERT INTO registros_viagem (missao_id, tipo, descricao, data_registro) 
            VALUES (:missao_id, 'aceitacao', 'Proposta aceita pelo contratante', NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':missao_id' => $missao_id]);

    registrar_log(
        $conn,
        (int)$empresa_id,
        'aceitar',
        'missao',
        $missao_id,
        'Proposta #' . $proposta_id . ' aceite — motorista ' . ($proposta['nome_caminhoneiro'] ?? '')
    );

    // Notificar caminhoneiro
    notificacao_enviar(
        $conn,
        (int)$proposta['caminhoneiro_id'],
        'proposta_aceita',
        'Proposta Aceita',
        'Sua proposta para a missão #' . $missao_id . ' foi aceita.',
        BASE_URL . '/pages/caminhoneiro/detalhes-missao.php?id=' . $missao_id
    );

    $conn->commit();

    header('Location: ' . BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id . '&success=Proposta aceita com sucesso!');
    exit;

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Erro ao aceitar proposta (contratante): ' . $e->getMessage());
    header('Location: ' . BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id . '&error=Erro ao aceitar proposta');
    exit;
}
?>
