<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');

include_once('../../includes/auth.php');

require_role(['empresa'], '../login.php');

// Verificar se o ID da missão foi fornecido
if (!isset($_GET['missao'])) {
    header('Location: ' . BASE_URL . '/pages/contratante/missoes.php');
    exit;
}

$missao_id = (int)$_GET['missao'];
$success = $error = '';

try {
    // Verificar se a missão pertence à empresa
    $sql = "SELECT id, titulo, status FROM missoes 
            WHERE id = :id AND empresa_id = :empresa_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':id' => $missao_id,
        ':empresa_id' => $_SESSION['user_id']
    ]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) {
        header('Location: ' . BASE_URL . '/pages/contratante/missoes.php');
        exit;
    }

    // Processar ações nas propostas
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $proposta_id = (int)$_POST['proposta_id'];
        $acao = $_POST['acao'];

        if ($acao === 'aceitar') {
            // Buscar proposta (para atribuir caminhoneiro)
            $sql = "SELECT caminhoneiro_id FROM propostas WHERE id = :id AND missao_id = :missao_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':id' => $proposta_id,
                ':missao_id' => $missao_id
            ]);
            $proposta_alvo = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$proposta_alvo) {
                $error = "Proposta não encontrada.";
            } else {
                // Verificar se já existe uma proposta aceita
                $sql = "SELECT id FROM propostas 
                        WHERE missao_id = :missao_id AND status = 'aceita'";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':missao_id' => $missao_id]);

                if ($stmt->fetch()) {
                    $error = "Já existe uma proposta aceita para esta missão.";
                } else {
                    // Aceitar a proposta
                    $sql = "UPDATE propostas SET status = 'aceita' 
                            WHERE id = :id AND missao_id = :missao_id";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':id' => $proposta_id,
                        ':missao_id' => $missao_id
                    ]);

                    // Atualizar status da missão (agendada)
                    $sql = "UPDATE missoes SET status = 'aceita', caminhoneiro_id = :caminhoneiro_id 
                            WHERE id = :id";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':id' => $missao_id,
                        ':caminhoneiro_id' => (int)$proposta_alvo['caminhoneiro_id']
                    ]);

                    $success = "Proposta aceita com sucesso!";
                }
            }
        } elseif ($acao === 'rejeitar') {
            $sql = "UPDATE propostas SET status = 'rejeitada' 
                    WHERE id = :id AND missao_id = :missao_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':id' => $proposta_id,
                ':missao_id' => $missao_id
            ]);
            $success = "Proposta rejeitada com sucesso!";
        }
    }

    // Buscar todas as propostas da missão
    try {
        // Primeiro, verificar se existem propostas
        $check_sql = "SELECT COUNT(*) FROM propostas WHERE missao_id = :missao_id";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->execute([':missao_id' => $missao_id]);
        $proposta_count = $check_stmt->fetchColumn();

        if ($proposta_count > 0) {
            // Se existem propostas, buscar os detalhes
            $sql = "SELECT p.*, u.nome as nome_caminhoneiro, u.telefone as telefone_caminhoneiro,
                    u.email as email_caminhoneiro, c.avaliacao_media, c.total_entregas
                    FROM propostas p
                    JOIN usuarios u ON p.caminhoneiro_id = u.id
                    LEFT JOIN perfil_caminhoneiro c ON p.caminhoneiro_id = c.usuario_id
                    WHERE p.missao_id = :missao_id
                    GROUP BY p.id
                    ORDER BY p.data_criacao DESC";

            $stmt = $conn->prepare($sql);
            $stmt->execute([':missao_id' => $missao_id]);
            $propostas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $propostas = [];
        }
    } catch (PDOException $e) {
        error_log("Erro específico ao buscar propostas: " . $e->getMessage());
        error_log("Query que falhou: " . $sql);
        $error = "Erro ao processar propostas: " . $e->getMessage();
        $propostas = [];
    }

} catch (PDOException $e) {
    error_log("Erro geral: " . $e->getMessage());
    $error = "Erro ao processar propostas. Por favor, tente novamente.";
    $propostas = [];
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Propostas Recebidas - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container mt-4">
        <div class="row mb-4">
            <div class="col-md-8">
                <h2>Propostas Recebidas</h2>
                <p class="text-muted">
                    Missão: <?php echo htmlspecialchars($missao['titulo']); ?>
                </p>
            </div>
            <div class="col-md-4 text-end">
                <a href="detalhes-missao.php?id=<?php echo $missao_id; ?>" class="btn btn-outline-primary">
                    <i class="bi bi-arrow-left"></i> Voltar para Missão
                </a>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success" role="alert">
                <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($propostas)): ?>
            <div class="alert alert-info" role="alert">
                Nenhuma proposta recebida para esta missão.
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($propostas as $proposta): ?>
                    <div class="col-md-6 mb-4">
                        <div class="card h-100 fade-in">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div>
                                        <h5 class="card-title mb-1">
                                            <?php echo htmlspecialchars($proposta['nome_caminhoneiro']); ?>
                                        </h5>
                                        <p class="text-muted mb-0">
                                            <i class="bi bi-star-fill text-warning"></i>
                                            <?php echo number_format($proposta['avaliacao_media'], 1); ?> 
                                            (<?php echo $proposta['total_entregas']; ?> entregas)
                                        </p>
                                    </div>
                                    <span class="badge bg-<?php 
                                        echo $proposta['status'] === 'aceita' ? 'success' : 
                                            ($proposta['status'] === 'rejeitada' ? 'danger' : 'warning'); 
                                    ?>">
                                        <?php echo ucfirst($proposta['status']); ?>
                                    </span>
                                </div>

                                <div class="mb-3">
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="bi bi-currency-dollar text-primary me-2"></i>
                                        <div>
                                            <small class="text-muted d-block">Valor Proposto</small>
                                            <?php echo number_format($proposta['valor'], 2, ',', '.'); ?> MT
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="bi bi-calendar text-primary me-2"></i>
                                        <div>
                                            <small class="text-muted d-block">Data da Proposta</small>
                                            <?php echo date('d/m/Y H:i', strtotime($proposta['data_criacao'])); ?>
                                        </div>
                                    </div>
                                    <?php if ($proposta['observacoes']): ?>
                                        <div class="d-flex align-items-start">
                                            <i class="bi bi-chat text-primary me-2"></i>
                                            <div>
                                                <small class="text-muted d-block">Observações</small>
                                                <?php echo nl2br(htmlspecialchars($proposta['observacoes'])); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <div class="d-flex gap-2 flex-wrap">
                                        <a href="<?php echo BASE_URL; ?>/pages/shared/perfil-motorista.php?id=<?php echo (int)$proposta['caminhoneiro_id']; ?>&missao=<?php echo $missao_id; ?>"
                                           class="btn btn-outline-secondary btn-sm">
                                            <i class="bi bi-person-badge"></i> Ver perfil
                                        </a>
                                        <a href="<?php echo BASE_URL; ?>/pages/chat.php?user=<?php echo $proposta['caminhoneiro_id']; ?>&missao=<?php echo $missao_id; ?>" 
                                           class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-chat"></i> Chat
                                        </a>
                                    </div>
                                    <?php if ($proposta['status'] === 'pendente'): ?>
                                        <div class="btn-group">
                                            <form method="POST" action="" class="d-inline">
                                                <input type="hidden" name="proposta_id" value="<?php echo $proposta['id']; ?>">
                                                <input type="hidden" name="acao" value="aceitar">
                                                <button type="submit" class="btn btn-success btn-sm" 
                                                        onclick="return confirm('Tem certeza que deseja aceitar esta proposta?')">
                                                    <i class="bi bi-check"></i> Aceitar
                                                </button>
                                            </form>
                                            <form method="POST" action="" class="d-inline">
                                                <input type="hidden" name="proposta_id" value="<?php echo $proposta['id']; ?>">
                                                <input type="hidden" name="acao" value="rejeitar">
                                                <button type="submit" class="btn btn-danger btn-sm"
                                                        onclick="return confirm('Tem certeza que deseja rejeitar esta proposta?')">
                                                    <i class="bi bi-x"></i> Rejeitar
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 