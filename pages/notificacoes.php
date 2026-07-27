<?php
session_start();
include_once('../config/app.php');
include_once('../config/database.php');
include_once('../includes/helpers.php');
include_once('../includes/notificacoes-helpers.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Acções mutáveis via POST + CSRF (evita CSRF via links GET)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $acao = isset($_POST['acao']) ? (string)$_POST['acao'] : '';

    if ($acao === 'marcar_lida') {
        $notificacao_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($notificacao_id > 0) {
            try {
                $sql = "UPDATE notificacoes SET lida = 1 WHERE id = :id AND usuario_id = :usuario_id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':id' => $notificacao_id, ':usuario_id' => $user_id]);
                flash_set('success', 'Notificação marcada como lida.');
                header('Location: ' . BASE_URL . '/pages/notificacoes.php');
                exit;
            } catch (PDOException $e) {
                error_log('Erro ao marcar notificação: ' . $e->getMessage());
                $error = "Erro ao marcar notificação como lida.";
            }
        }
    } elseif ($acao === 'marcar_todas') {
        try {
            $sql = "UPDATE notificacoes SET lida = 1 WHERE usuario_id = :usuario_id AND lida = 0";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':usuario_id' => $user_id]);
            flash_set('success', 'Todas as notificações foram marcadas como lidas.');
            header('Location: ' . BASE_URL . '/pages/notificacoes.php');
            exit;
        } catch (PDOException $e) {
            error_log('Erro ao marcar todas: ' . $e->getMessage());
            $error = "Erro ao marcar notificações como lidas.";
        }
    }
}

$flashSuccess = flash_get('success') ?? '';

// Buscar notificações do usuário
try {
    // Configurar filtros
    $filtro = isset($_GET['filtro']) ? (string)$_GET['filtro'] : 'todas';
    $where = 'WHERE usuario_id = :usuario_id' . notificacao_sql_filtro($filtro);
    
    if ($filtro === 'nao_lidas') {
        // já incluído em notificacao_sql_filtro
    }
    
    // Contar total de notificações
    $sql = "SELECT COUNT(*) FROM notificacoes " . $where;
    $stmt = $conn->prepare($sql);
    $stmt->execute([':usuario_id' => $user_id]);
    $total_notificacoes = $stmt->fetchColumn();
    
    // Configurar paginação
    $por_pagina = 10;
    $pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
    $total_paginas = ceil($total_notificacoes / $por_pagina);
    $offset = ($pagina - 1) * $por_pagina;
    
    // Buscar notificações paginadas
    $sql = "SELECT * FROM notificacoes 
            " . $where . "
            ORDER BY data_criacao DESC, lida ASC
            LIMIT :offset, :limit";
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':usuario_id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $por_pagina, PDO::PARAM_INT);
    $stmt->execute();
    $notificacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Contar notificações não lidas
    $sql = "SELECT COUNT(*) FROM notificacoes WHERE usuario_id = :usuario_id AND lida = 0";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':usuario_id' => $user_id]);
    $nao_lidas = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    $error = "Erro ao carregar notificações: " . $e->getMessage();
    $notificacoes = [];
    $total_notificacoes = 0;
    $total_paginas = 1;
    $nao_lidas = 0;
}

// Função para formatar data
function formatarData($data) {
    $timestamp = strtotime($data);
    $hoje = strtotime(date('Y-m-d'));
    
    if ($timestamp >= $hoje) {
        return 'Hoje às ' . date('H:i', $timestamp);
    } elseif ($timestamp >= ($hoje - 86400)) {
        return 'Ontem às ' . date('H:i', $timestamp);
    } else {
        return date('d/m/Y H:i', $timestamp);
    }
}

// Função para obter a classe de ícone baseada no tipo de notificação
function getIconClass($tipo) {
    return notificacao_icone((string)$tipo);
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notificações - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <style>
        .notification-item {
            transition: all 0.2s;
            border-left: 4px solid transparent;
        }
        .notification-item:hover {
            background-color: #f8f9fa;
        }
        .notification-item.unread {
            border-left-color: #0d6efd;
            background-color: #f0f7ff;
        }
        .notification-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background-color: #f8f9fa;
        }
        .notification-time {
            color: #6c757d;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <?php include_once('../includes/menu.php'); ?>

    <div class="container mt-4">
        <div class="row">
            <div class="col-lg-12">
                <div class="card fade-in">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h2 class="card-title mb-0">
                                <i class="bi bi-bell"></i> Notificações
                                <?php if ($nao_lidas > 0): ?>
                                    <span class="badge bg-danger"><?php echo $nao_lidas; ?> não lidas</span>
                                <?php endif; ?>
                            </h2>
                            
                            <?php if ($nao_lidas > 0): ?>
                                <form method="POST" class="m-0">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="acao" value="marcar_todas">
                                    <button type="submit" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-check-all"></i> Marcar todas como lidas
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($flashSuccess) || !empty($success)): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <?php echo e($flashSuccess ?: $success); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <?php echo $error; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>
                        
                        <div class="row mb-3">
                            <div class="col-md-8">
                                <div class="btn-group flex-wrap" role="group">
                                    <?php foreach (notificacao_filtros_disponiveis() as $key => $label): ?>
                                    <a href="?filtro=<?php echo urlencode($key); ?>" class="btn btn-outline-secondary btn-sm <?php echo $filtro === $key ? 'active' : ''; ?>">
                                        <?php echo htmlspecialchars($label); ?>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (empty($notificacoes)): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i>
                                Você não possui notificações<?php echo $filtro !== 'todas' ? ' neste filtro' : ''; ?>.
                            </div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($notificacoes as $notificacao): ?>
                                    <div class="list-group-item notification-item <?php echo $notificacao['lida'] ? '' : 'unread'; ?>">
                                        <div class="d-flex align-items-center">
                                            <div class="notification-icon me-3">
                                                <i class="bi <?php echo getIconClass($notificacao['tipo']); ?>"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <h5 class="mb-1"><?php echo htmlspecialchars($notificacao['titulo']); ?></h5>
                                                    <span class="notification-time">
                                                        <?php echo formatarData($notificacao['data_criacao']); ?>
                                                    </span>
                                                </div>
                                                <p class="mb-1"><?php echo htmlspecialchars($notificacao['mensagem']); ?></p>
                                                <div class="d-flex justify-content-end mt-2 gap-2">
                                                    <?php if (!empty($notificacao['link'])): ?>
                                                        <a href="<?php echo htmlspecialchars($notificacao['link']); ?>" class="btn btn-sm btn-outline-secondary">
                                                            <i class="bi bi-box-arrow-up-right"></i> Ver detalhes
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if (!$notificacao['lida']): ?>
                                                        <form method="POST" class="m-0">
                                                            <?php echo csrf_field(); ?>
                                                            <input type="hidden" name="acao" value="marcar_lida">
                                                            <input type="hidden" name="id" value="<?php echo (int)$notificacao['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-primary me-2">
                                                                <i class="bi bi-check"></i> Marcar como lida
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <?php if ($total_paginas > 1): ?>
                                <nav class="mt-4">
                                    <ul class="pagination justify-content-center">
                                        <li class="page-item <?php echo $pagina <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?filtro=<?php echo $filtro; ?>&pagina=<?php echo $pagina-1; ?>">
                                                <i class="bi bi-arrow-left"></i> Anterior
                                            </a>
                                        </li>
                                        
                                        <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                                            <li class="page-item <?php echo $i === $pagina ? 'active' : ''; ?>">
                                                <a class="page-link" href="?filtro=<?php echo $filtro; ?>&pagina=<?php echo $i; ?>">
                                                    <?php echo $i; ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <li class="page-item <?php echo $pagina >= $total_paginas ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?filtro=<?php echo $filtro; ?>&pagina=<?php echo $pagina+1; ?>">
                                                Próxima <i class="bi bi-arrow-right"></i>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 