<?php
session_start();
require_once '../../config/app.php';
require_once '../../config/database.php';

// Verificar se o usuário está logado e é um administrador
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'admin') {
    header('Location: ../login.php');
    exit();
}

// Processar formulário de configurações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_profile':
                // Atualizar perfil do administrador
                $stmt = $conn->prepare("
                    UPDATE usuarios 
                    SET nome = :nome, 
                        email = :email, 
                        telefone = :telefone
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':nome' => $_POST['nome'],
                    ':email' => $_POST['email'],
                    ':telefone' => $_POST['telefone'],
                    ':id' => $_SESSION['user_id']
                ]);
                $success_message = "Perfil atualizado com sucesso!";
                break;

            case 'update_password':
                // Atualizar senha
                if ($_POST['nova_senha'] === $_POST['confirmar_senha']) {
                    $stmt = $conn->prepare("
                        UPDATE usuarios 
                        SET senha = :senha
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':senha' => password_hash($_POST['nova_senha'], PASSWORD_DEFAULT),
                        ':id' => $_SESSION['user_id']
                    ]);
                    $success_message = "Senha atualizada com sucesso!";
                } else {
                    $error_message = "As senhas não coincidem!";
                }
                break;

            case 'update_system':
                // Atualizar configurações do sistema
                $stmt = $conn->prepare("
                    UPDATE configuracoes 
                    SET valor = :valor
                    WHERE chave = :chave
                ");
                
                foreach ($_POST['config'] as $chave => $valor) {
                    $stmt->execute([
                        ':valor' => $valor,
                        ':chave' => $chave
                    ]);
                }
                $success_message = "Configurações do sistema atualizadas com sucesso!";
                break;
        }
    }
}

// Obter dados do administrador
$stmt = $conn->prepare("SELECT * FROM usuarios WHERE id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

// Obter configurações do sistema
$stmt = $conn->query("SELECT chave, valor FROM configuracoes");
$configuracoes = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações - TrackMoz Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css">
    <style>
        .settings-card {
            border: 2px solid var(--admin-border);
            border-radius: var(--admin-radius);
            box-shadow: var(--admin-shadow);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .settings-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--admin-shadow-lg);
        }
        .settings-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }
        .settings-profile::before { background: var(--admin-primary); }
        .settings-profile { border-color: var(--admin-primary); }
        .settings-security::before { background: var(--admin-danger); }
        .settings-security { border-color: var(--admin-danger); }
        .settings-system::before { background: var(--admin-success); }
        .settings-system { border-color: var(--admin-success); }
        .settings-notifications::before { background: var(--admin-warning); }
        .settings-notifications { border-color: var(--admin-warning); }
        .settings-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-right: 1rem;
        }
        .settings-icon.primary {
            background: linear-gradient(135deg, var(--admin-primary) 0%, var(--admin-primary-dark) 100%);
            color: white;
        }
        .settings-icon.danger {
            background: linear-gradient(135deg, var(--admin-danger) 0%, #dc2626 100%);
            color: white;
        }
        .settings-icon.success {
            background: linear-gradient(135deg, var(--admin-success) 0%, #059669 100%);
            color: white;
        }
        .settings-icon.warning {
            background: linear-gradient(135deg, var(--admin-warning) 0%, #d97706 100%);
            color: white;
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
                            <a class="nav-link" href="mensagens.php">
                                <i class="bi bi-chat-left"></i> Mensagens
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="avaliacoes.php">
                                <i class="bi bi-star"></i> Avaliações
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active" href="configuracoes.php">
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
                    <h1 class="h2">Configurações</h1>
                </div>

                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $success_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $error_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Perfil -->
                <div class="card settings-card settings-profile mb-4">
                    <div class="card-header d-flex align-items-center">
                        <div class="settings-icon primary">
                            <i class="bi bi-person-circle"></i>
                        </div>
                        <h5 class="card-title mb-0">Perfil do Administrador</h5>
                    </div>
                    <div class="card-body">
                        <form action="" method="POST">
                            <input type="hidden" name="action" value="update_profile">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="nome" class="form-label">Nome</label>
                                    <input type="text" class="form-control" id="nome" name="nome" value="<?php echo htmlspecialchars($admin['nome'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="email" class="form-label">E-mail</label>
                                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($admin['email'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="telefone" class="form-label">Telefone</label>
                                    <input type="tel" class="form-control" id="telefone" name="telefone" value="<?php echo htmlspecialchars($admin['telefone'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary">Salvar Alterações</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Segurança -->
                <div class="card settings-card settings-security mb-4">
                    <div class="card-header d-flex align-items-center">
                        <div class="settings-icon danger">
                            <i class="bi bi-shield-lock"></i>
                        </div>
                        <h5 class="card-title mb-0">Segurança</h5>
                    </div>
                    <div class="card-body">
                        <form action="" method="POST">
                            <input type="hidden" name="action" value="update_password">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="nova_senha" class="form-label">Nova Senha</label>
                                    <input type="password" class="form-control" id="nova_senha" name="nova_senha" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="confirmar_senha" class="form-label">Confirmar Nova Senha</label>
                                    <input type="password" class="form-control" id="confirmar_senha" name="confirmar_senha" required>
                                </div>
                            </div>
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary">Alterar Senha</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Configurações do Sistema -->
                <div class="card settings-card settings-system mb-4">
                    <div class="card-header d-flex align-items-center">
                        <div class="settings-icon success">
                            <i class="bi bi-gear"></i>
                        </div>
                        <h5 class="card-title mb-0">Configurações do Sistema</h5>
                    </div>
                    <div class="card-body">
                        <form action="" method="POST">
                            <input type="hidden" name="action" value="update_system">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="config[site_name]" class="form-label">Nome do Site</label>
                                    <input type="text" class="form-control" id="config[site_name]" name="config[site_name]" value="<?php echo htmlspecialchars($configuracoes['site_name'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="config[site_email]" class="form-label">E-mail do Site</label>
                                    <input type="email" class="form-control" id="config[site_email]" name="config[site_email]" value="<?php echo htmlspecialchars($configuracoes['site_email'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="config[max_upload_size]" class="form-label">Tamanho Máximo de Upload (MB)</label>
                                    <input type="number" class="form-control" id="config[max_upload_size]" name="config[max_upload_size]" value="<?php echo htmlspecialchars($configuracoes['max_upload_size'] ?? '5'); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="config[maintenance_mode]" class="form-label">Modo de Manutenção</label>
                                    <select class="form-select" id="config[maintenance_mode]" name="config[maintenance_mode]">
                                        <option value="0" <?php echo ($configuracoes['maintenance_mode'] ?? '0') == '0' ? 'selected' : ''; ?>>Desativado</option>
                                        <option value="1" <?php echo ($configuracoes['maintenance_mode'] ?? '0') == '1' ? 'selected' : ''; ?>>Ativado</option>
                                    </select>
                                </div>
                            </div>
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary">Salvar Configurações</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Notificações -->
                <div class="card settings-card settings-notifications mb-4">
                    <div class="card-header d-flex align-items-center">
                        <div class="settings-icon warning">
                            <i class="bi bi-bell"></i>
                        </div>
                        <h5 class="card-title mb-0">Notificações</h5>
                    </div>
                    <div class="card-body">
                        <form action="" method="POST">
                            <input type="hidden" name="action" value="update_notifications">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="notify_new_users" name="config[notify_new_users]" <?php echo ($configuracoes['notify_new_users'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="notify_new_users">Notificar sobre novos usuários</label>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="notify_new_missions" name="config[notify_new_missions]" <?php echo ($configuracoes['notify_new_missions'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="notify_new_missions">Notificar sobre novas missões</label>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="notify_new_ratings" name="config[notify_new_ratings]" <?php echo ($configuracoes['notify_new_ratings'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="notify_new_ratings">Notificar sobre novas avaliações</label>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="notify_reported_content" name="config[notify_reported_content]" <?php echo ($configuracoes['notify_reported_content'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="notify_reported_content">Notificar sobre conteúdo reportado</label>
                                    </div>
                                </div>
                            </div>
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary">Salvar Preferências</button>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 