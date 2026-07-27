<?php
session_start();
include_once('../../config/database.php');

// Verificar se o usuário está logado e é um administrador
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'admin') {
    header('Location: ../login.php');
    exit();
}

// Definir a aba padrão
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'inbox';

// Obter mensagens recebidas
$stmt = $conn->prepare("
    SELECT m.*, 
           u_remetente.nome AS nome_remetente,
           u_remetente.tipo_usuario AS tipo_remetente
    FROM mensagens m
    JOIN usuarios u_remetente ON m.remetente_id = u_remetente.id
    WHERE m.destinatario_id = :admin_id
    ORDER BY m.data_envio DESC
");
$stmt->execute([':admin_id' => $_SESSION['user_id']]);
$mensagens_recebidas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obter mensagens enviadas
$stmt = $conn->prepare("
    SELECT m.*, 
           u_destinatario.nome AS nome_destinatario,
           u_destinatario.tipo_usuario AS tipo_destinatario
    FROM mensagens m
    JOIN usuarios u_destinatario ON m.destinatario_id = u_destinatario.id
    WHERE m.remetente_id = :admin_id
    ORDER BY m.data_envio DESC
");
$stmt->execute([':admin_id' => $_SESSION['user_id']]);
$mensagens_enviadas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obter usuários para o formulário de nova mensagem
$stmt = $conn->query("SELECT id, nome, tipo_usuario FROM usuarios WHERE id != " . $_SESSION['user_id'] . " ORDER BY nome");
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contar mensagens não lidas
$stmt = $conn->prepare("SELECT COUNT(*) FROM mensagens WHERE destinatario_id = :admin_id AND lida = 0");
$stmt->execute([':admin_id' => $_SESSION['user_id']]);
$mensagens_nao_lidas = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mensagens - TrackMoz Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/admin.css">
    <style>
        .message-card {
            border-left: 4px solid;
            transition: all 0.2s;
        }
        .message-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .message-unread { border-left-color: #0d6efd; }
        .message-read { border-left-color: #6c757d; }
        .message-sent { border-left-color: #198754; }
        .message-trash { border-left-color: #dc3545; }
        .message-content {
            max-height: 200px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2 admin-sidebar d-none d-md-block p-0">
                <div class="d-flex flex-column p-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="dashboard.php">
                                <i class="bi bi-speedometer2"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="usuarios.php">
                                <i class="bi bi-people"></i> Usuários
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="missoes.php">
                                <i class="bi bi-list-task"></i> Missões
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="relatorios.php">
                                <i class="bi bi-graph-up"></i> Relatórios
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="mensagens.php">
                                <i class="bi bi-chat-left"></i> Mensagens
                                <?php if ($mensagens_nao_lidas > 0): ?>
                                <span class="badge bg-danger rounded-pill"><?php echo $mensagens_nao_lidas; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="avaliacoes.php">
                                <i class="bi bi-star"></i> Avaliações
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="configuracoes.php">
                                <i class="bi bi-gear"></i> Configurações
                            </a>
                        </li>
                    </ul>
                    
                    <hr class="text-white-50">
                    
                    <h6 class="text-white-50 px-3 mt-3 mb-2 text-uppercase">Sistema</h6>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link" href="registros.php">
                                <i class="bi bi-journals"></i> Logs do Sistema
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="backup.php">
                                <i class="bi bi-cloud-arrow-down"></i> Backup
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Content -->
            <main class="col-md-10 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Mensagens</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#novaMensagemModal">
                            <i class="bi bi-pencil-square"></i> Nova Mensagem
                        </button>
                    </div>
                </div>

                <!-- Tabs -->
                <ul class="nav nav-tabs mb-4">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $tab === 'inbox' ? 'active' : ''; ?>" href="?tab=inbox">
                            <i class="bi bi-inbox"></i> Caixa de Entrada
                            <?php if ($mensagens_nao_lidas > 0): ?>
                            <span class="badge bg-danger rounded-pill"><?php echo $mensagens_nao_lidas; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $tab === 'sent' ? 'active' : ''; ?>" href="?tab=sent">
                            <i class="bi bi-send"></i> Enviadas
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $tab === 'trash' ? 'active' : ''; ?>" href="?tab=trash">
                            <i class="bi bi-trash"></i> Lixeira
                        </a>
                    </li>
                </ul>

                <!-- Mensagens -->
                <?php if ($tab === 'inbox'): ?>
                    <?php if (empty($mensagens_recebidas)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Nenhuma mensagem recebida.
                        </div>
                    <?php else: ?>
                        <?php foreach ($mensagens_recebidas as $mensagem): ?>
                            <div class="card message-card <?php echo $mensagem['lida'] ? 'message-read' : 'message-unread'; ?> mb-3">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div class="d-flex align-items-center">
                                            <div class="avatar me-2 rounded-circle bg-light p-2">
                                                <i class="bi <?php echo $mensagem['tipo_remetente'] === 'caminhoneiro' ? 'bi-truck' : 'bi-building'; ?> text-secondary"></i>
                                            </div>
                                            <div>
                                                <h6 class="mb-0"><?php echo htmlspecialchars($mensagem['nome_remetente']); ?></h6>
                                                <small class="text-muted">
                                                    <?php echo $mensagem['tipo_remetente'] === 'caminhoneiro' ? 'Motorista' : 'Empresa'; ?>
                                                </small>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <small class="text-muted">
                                                <?php echo date('d/m/Y H:i', strtotime($mensagem['data_envio'])); ?>
                                            </small>
                                            <div class="btn-group btn-group-sm ms-2">
                                                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $mensagem['id']; ?>">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?php echo $mensagem['id']; ?>">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <h5 class="card-title"><?php echo htmlspecialchars($mensagem['assunto']); ?></h5>
                                    <p class="card-text message-content">
                                        <?php echo nl2br(htmlspecialchars($mensagem['conteudo'])); ?>
                                    </p>
                                </div>
                            </div>

                            <!-- Modal Visualizar -->
                            <div class="modal fade" id="viewModal<?php echo $mensagem['id']; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title"><?php echo htmlspecialchars($mensagem['assunto']); ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="d-flex align-items-center mb-3">
                                                <div class="avatar me-2 rounded-circle bg-light p-2">
                                                    <i class="bi <?php echo $mensagem['tipo_remetente'] === 'caminhoneiro' ? 'bi-truck' : 'bi-building'; ?> text-secondary"></i>
                                                </div>
                                                <div>
                                                    <h6 class="mb-0"><?php echo htmlspecialchars($mensagem['nome_remetente']); ?></h6>
                                                    <small class="text-muted">
                                                        <?php echo $mensagem['tipo_remetente'] === 'caminhoneiro' ? 'Motorista' : 'Empresa'; ?>
                                                    </small>
                                                </div>
                                                <small class="text-muted ms-auto">
                                                    <?php echo date('d/m/Y H:i', strtotime($mensagem['data_envio'])); ?>
                                                </small>
                                            </div>
                                            <div class="message-content">
                                                <?php echo nl2br(htmlspecialchars($mensagem['conteudo'])); ?>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#replyModal<?php echo $mensagem['id']; ?>">
                                                <i class="bi bi-reply"></i> Responder
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Modal Responder -->
                            <div class="modal fade" id="replyModal<?php echo $mensagem['id']; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Responder Mensagem</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <form action="enviar-mensagem.php" method="POST">
                                                <input type="hidden" name="destinatario_id" value="<?php echo $mensagem['remetente_id']; ?>">
                                                <div class="mb-3">
                                                    <label for="assunto" class="form-label">Assunto</label>
                                                    <input type="text" class="form-control" id="assunto" name="assunto" value="Re: <?php echo htmlspecialchars($mensagem['assunto']); ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="conteudo" class="form-label">Mensagem</label>
                                                    <textarea class="form-control" id="conteudo" name="conteudo" rows="5" required></textarea>
                                                </div>
                                                <div class="text-end">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                    <button type="submit" class="btn btn-primary">Enviar</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Modal Excluir -->
                            <div class="modal fade" id="deleteModal<?php echo $mensagem['id']; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Confirmar Exclusão</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p>Tem certeza que deseja excluir esta mensagem?</p>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                            <form action="excluir-mensagem.php" method="POST" class="d-inline">
                                                <input type="hidden" name="mensagem_id" value="<?php echo $mensagem['id']; ?>">
                                                <button type="submit" class="btn btn-danger">Excluir</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php elseif ($tab === 'sent'): ?>
                    <?php if (empty($mensagens_enviadas)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Nenhuma mensagem enviada.
                        </div>
                    <?php else: ?>
                        <?php foreach ($mensagens_enviadas as $mensagem): ?>
                            <div class="card message-card message-sent mb-3">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div class="d-flex align-items-center">
                                            <div class="avatar me-2 rounded-circle bg-light p-2">
                                                <i class="bi <?php echo $mensagem['tipo_destinatario'] === 'caminhoneiro' ? 'bi-truck' : 'bi-building'; ?> text-secondary"></i>
                                            </div>
                                            <div>
                                                <h6 class="mb-0">Para: <?php echo htmlspecialchars($mensagem['nome_destinatario']); ?></h6>
                                                <small class="text-muted">
                                                    <?php echo $mensagem['tipo_destinatario'] === 'caminhoneiro' ? 'Motorista' : 'Empresa'; ?>
                                                </small>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <small class="text-muted">
                                                <?php echo date('d/m/Y H:i', strtotime($mensagem['data_envio'])); ?>
                                            </small>
                                            <div class="btn-group btn-group-sm ms-2">
                                                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#viewSentModal<?php echo $mensagem['id']; ?>">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteSentModal<?php echo $mensagem['id']; ?>">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <h5 class="card-title"><?php echo htmlspecialchars($mensagem['assunto']); ?></h5>
                                    <p class="card-text message-content">
                                        <?php echo nl2br(htmlspecialchars($mensagem['conteudo'])); ?>
                                    </p>
                                </div>
                            </div>

                            <!-- Modal Visualizar Enviada -->
                            <div class="modal fade" id="viewSentModal<?php echo $mensagem['id']; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title"><?php echo htmlspecialchars($mensagem['assunto']); ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="d-flex align-items-center mb-3">
                                                <div class="avatar me-2 rounded-circle bg-light p-2">
                                                    <i class="bi <?php echo $mensagem['tipo_destinatario'] === 'caminhoneiro' ? 'bi-truck' : 'bi-building'; ?> text-secondary"></i>
                                                </div>
                                                <div>
                                                    <h6 class="mb-0">Para: <?php echo htmlspecialchars($mensagem['nome_destinatario']); ?></h6>
                                                    <small class="text-muted">
                                                        <?php echo $mensagem['tipo_destinatario'] === 'caminhoneiro' ? 'Motorista' : 'Empresa'; ?>
                                                    </small>
                                                </div>
                                                <small class="text-muted ms-auto">
                                                    <?php echo date('d/m/Y H:i', strtotime($mensagem['data_envio'])); ?>
                                                </small>
                                            </div>
                                            <div class="message-content">
                                                <?php echo nl2br(htmlspecialchars($mensagem['conteudo'])); ?>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Modal Excluir Enviada -->
                            <div class="modal fade" id="deleteSentModal<?php echo $mensagem['id']; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Confirmar Exclusão</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p>Tem certeza que deseja excluir esta mensagem?</p>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                            <form action="excluir-mensagem.php" method="POST" class="d-inline">
                                                <input type="hidden" name="mensagem_id" value="<?php echo $mensagem['id']; ?>">
                                                <button type="submit" class="btn btn-danger">Excluir</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php elseif ($tab === 'trash'): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> Nenhuma mensagem na lixeira.
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Modal Nova Mensagem -->
    <div class="modal fade" id="novaMensagemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Nova Mensagem</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form action="enviar-mensagem.php" method="POST">
                        <div class="mb-3">
                            <label for="destinatario_id" class="form-label">Destinatário</label>
                            <select class="form-select" id="destinatario_id" name="destinatario_id" required>
                                <option value="" selected disabled>Selecione o destinatário</option>
                                <?php foreach ($usuarios as $usuario): ?>
                                    <option value="<?php echo $usuario['id']; ?>">
                                        <?php echo htmlspecialchars($usuario['nome']); ?> 
                                        (<?php echo $usuario['tipo_usuario'] === 'caminhoneiro' ? 'Motorista' : 'Empresa'; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="assunto" class="form-label">Assunto</label>
                            <input type="text" class="form-control" id="assunto" name="assunto" required>
                        </div>
                        <div class="mb-3">
                            <label for="conteudo" class="form-label">Mensagem</label>
                            <textarea class="form-control" id="conteudo" name="conteudo" rows="5" required></textarea>
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Enviar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 