<?php
session_start();
require_once '../../config/app.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../includes/notificacoes.php';
require_once '../../includes/kpi-helpers.php';
require_once '../../includes/analytics-helpers.php';

require_role(['admin'], '../login.php');

$kpiAdmin = kpi_admin($conn);
$axHoje = tmz_analytics_resumo_hoje($conn);

$contasIrregulares = 0;
try {
    require_once '../../includes/kyc-advertencias-helpers.php';
    $contasIrregulares = kyc_contar_contas_irregulares($conn);
} catch (Throwable $e) {
    error_log('dashboard irregulares: ' . $e->getMessage());
}

// Buscar estatísticas gerais
$sql = "SELECT 
            (SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'caminhoneiro') as total_caminhoneiros,
            (SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'empresa') as total_empresas,
            (SELECT COUNT(*) FROM missoes) as total_missoes,
            (SELECT COUNT(*) FROM missoes WHERE status = 'concluida') as missoes_concluidas,
            (SELECT COUNT(*) FROM usuarios WHERE status = 'pendente') as usuarios_pendentes";
$stmt = $conn->prepare($sql);
$stmt->execute();
$estatisticas = $stmt->fetch(PDO::FETCH_ASSOC);

// Buscar usuários pendentes de aprovação
$sql = "SELECT u.*, 
            CASE 
                WHEN u.tipo_usuario = 'caminhoneiro' THEN pc.tipo_veiculo
                WHEN u.tipo_usuario = 'empresa' THEN pe.nome_empresa
                ELSE NULL
            END as detalhe_perfil
        FROM usuarios u
        LEFT JOIN perfil_caminhoneiro pc ON u.id = pc.usuario_id
        LEFT JOIN perfil_empresa pe ON u.id = pe.usuario_id
        WHERE u.status = 'pendente'
        ORDER BY u.data_registro DESC
        LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->execute();
$usuarios_pendentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Adicionar estatísticas de documentos pendentes
try {
    $sql = "SELECT COUNT(*) FROM documentos WHERE status = 'pendente'";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $estatisticas['documentos_pendentes'] = $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Erro ao contar documentos pendentes: " . $e->getMessage());
    $estatisticas['documentos_pendentes'] = 0;
}

// Buscar notificações de prazos próximos
$notificacoes = verificar_notificacoes_prazos($conn);
$resumo_notificacoes = obter_resumo_notificacoes($conn);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Administrativo - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/profissional.css">
    <style>
        .card-header {
            background-color: var(--admin-surface) !important;
            border-bottom: 1px solid var(--admin-border);
        }
        .table-light {
            background-color: var(--admin-bg) !important;
        }
        .list-group-item {
            background-color: var(--admin-surface);
            border-color: var(--admin-border);
        }
        .tm-kpi-meta {
            display: flex;
            flex-wrap: wrap;
            gap: .35rem;
            margin-top: .55rem;
        }
        .tm-kpi-chip {
            display: inline-flex;
            align-items: center;
            padding: .2rem .55rem;
            border-radius: 999px;
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .02em;
            line-height: 1.2;
        }
        .tm-kpi-chip.blue   { background: #dbeafe; color: #1d4ed8; }
        .tm-kpi-chip.slate  { background: #f1f5f9; color: #475569; }
        .tm-kpi-chip.indigo { background: #e0e7ff; color: #4338ca; }
        .tm-kpi-chip.rose   { background: #fee2e2; color: #b91c1c; }
        .tm-kpi-chip.amber  { background: #fef3c7; color: #b45309; }
        .tm-kpi-chip.green  { background: #dcfce7; color: #15803d; }
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
                            <a class="nav-link active" href="dashboard.php">
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
                    <h1 class="h2">Dashboard Administrativo</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary">Exportar</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary">Imprimir</button>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle">
                            <i class="bi bi-calendar3"></i> Esta semana
                        </button>
                    </div>
                </div>

                <div class="mb-4">
                    <?php
                    echo kpi_render_cards($kpiAdmin, [
                        'missoes_andamento'   => ['label' => 'Missões em execução'],
                        'missoes_concluidas'  => ['label' => 'Missões concluídas'],
                        'emergencias'         => ['label' => 'Emergências activas'],
                        'usuarios_pendentes'  => ['label' => 'Utilizadores pendentes'],
                        'documentos_pendentes'=> ['label' => 'Documentos pendentes'],
                    ]);
                    ?>
                </div>

                <div class="d-flex align-items-center justify-content-between mb-2">
                    <h2 class="h6 text-uppercase fw-bold mb-0" style="letter-spacing:.06em;color:#64748b">Acessos hoje</h2>
                    <a href="acessos.php" class="small text-decoration-none">Ver detalhe →</a>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body py-3">
                                <div class="small text-muted">Views site</div>
                                <div class="fs-3 fw-bold"><?php echo (int)$axHoje['pageviews_hoje']; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body py-3">
                                <div class="small text-muted">Visitantes únicos</div>
                                <div class="fs-3 fw-bold"><?php echo (int)$axHoje['visitantes_unicos_hoje']; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body py-3">
                                <div class="small text-muted">Logins</div>
                                <div class="fs-3 fw-bold text-primary"><?php echo (int)$axHoje['logins_hoje']; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body py-3">
                                <div class="small text-muted">Online agora</div>
                                <div class="fs-3 fw-bold text-success"><?php echo (int)$axHoje['online_agora']; ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Resumo da plataforma -->
                <div class="d-flex align-items-center justify-content-between mb-2 mt-1">
                    <h2 class="h6 text-uppercase fw-bold mb-0" style="letter-spacing:.06em;color:#64748b">Resumo da plataforma</h2>
                </div>
                <div class="row g-3 tm-kpi-grid mb-4">
                    <?php
                    $totalUsers = (int)$estatisticas['total_caminhoneiros'] + (int)$estatisticas['total_empresas'];
                    try {
                        $totalTransp = (int)$conn->query("SELECT COUNT(*) FROM usuarios WHERE tipo_usuario='transportador'")->fetchColumn();
                        $totalUsers += $totalTransp;
                    } catch (Throwable $e) {
                        $totalTransp = 0;
                    }
                    $alertCrit = (int)($resumo_notificacoes['critica'] ?? 0);
                    $alertTotal = (int)($resumo_notificacoes['total'] ?? 0);
                    $alertColor = $alertCrit > 0 ? 'rose' : 'indigo';
                    ?>
                    <div class="col-md-6 col-xl-4">
                        <a href="usuarios.php" class="text-decoration-none text-reset d-block h-100">
                            <div class="card border-0 h-100 tm-kpi-card tm-kpi-blue">
                                <div class="card-body">
                                    <div class="d-flex align-items-start justify-content-between mb-2">
                                        <div class="tm-kpi-label">Utilizadores</div>
                                        <span class="tm-kpi-icon"><i class="bi bi-people"></i></span>
                                    </div>
                                    <div class="tm-kpi-value"><?php echo $totalUsers; ?></div>
                                    <div class="tm-kpi-meta">
                                        <span class="tm-kpi-chip blue">Motoristas <?php echo (int)$estatisticas['total_caminhoneiros']; ?></span>
                                        <span class="tm-kpi-chip slate">Empresas <?php echo (int)$estatisticas['total_empresas']; ?></span>
                                        <?php if ($totalTransp > 0): ?>
                                            <span class="tm-kpi-chip indigo">Transp. <?php echo $totalTransp; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-4">
                        <a href="usuarios.php?status=pendente" class="text-decoration-none text-reset d-block h-100">
                            <div class="card border-0 h-100 tm-kpi-card tm-kpi-amber">
                                <div class="card-body">
                                    <div class="d-flex align-items-start justify-content-between mb-2">
                                        <div class="tm-kpi-label">Pendentes</div>
                                        <span class="tm-kpi-icon"><i class="bi bi-person-plus"></i></span>
                                    </div>
                                    <div class="tm-kpi-value"><?php echo (int)$estatisticas['usuarios_pendentes']; ?></div>
                                    <div class="tm-kpi-hint">Cadastros à espera de aprovação</div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-4">
                        <a href="missoes.php" class="text-decoration-none text-reset d-block h-100">
                            <div class="card border-0 h-100 tm-kpi-card tm-kpi-green">
                                <div class="card-body">
                                    <div class="d-flex align-items-start justify-content-between mb-2">
                                        <div class="tm-kpi-label">Missões</div>
                                        <span class="tm-kpi-icon"><i class="bi bi-list-task"></i></span>
                                    </div>
                                    <div class="tm-kpi-value"><?php echo (int)$estatisticas['total_missoes']; ?></div>
                                    <div class="tm-kpi-hint">
                                        <?php echo (int)$estatisticas['missoes_concluidas']; ?> concluídas
                                        · <?php echo (int)($kpiAdmin['missoes_andamento'] ?? 0); ?> em execução
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-4">
                        <a href="verificar-documentos.php?status=pendente" class="text-decoration-none text-reset d-block h-100">
                            <div class="card border-0 h-100 tm-kpi-card tm-kpi-cyan">
                                <div class="card-body">
                                    <div class="d-flex align-items-start justify-content-between mb-2">
                                        <div class="tm-kpi-label">Documentos</div>
                                        <span class="tm-kpi-icon"><i class="bi bi-file-earmark-check"></i></span>
                                    </div>
                                    <div class="tm-kpi-value"><?php echo (int)$estatisticas['documentos_pendentes']; ?></div>
                                    <div class="tm-kpi-hint">Aguardando análise KYC</div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-4">
                        <a href="contas-irregulares.php" class="text-decoration-none text-reset d-block h-100">
                            <div class="card border-0 h-100 tm-kpi-card tm-kpi-rose">
                                <div class="card-body">
                                    <div class="d-flex align-items-start justify-content-between mb-2">
                                        <div class="tm-kpi-label">Irregulares</div>
                                        <span class="tm-kpi-icon"><i class="bi bi-exclamation-octagon"></i></span>
                                    </div>
                                    <div class="tm-kpi-value"><?php echo (int)$contasIrregulares; ?></div>
                                    <div class="tm-kpi-hint">Sem documentação regularizada</div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-6 col-xl-4">
                        <div class="card border-0 h-100 tm-kpi-card tm-kpi-<?php echo e($alertColor); ?>">
                            <div class="card-body">
                                <div class="d-flex align-items-start justify-content-between mb-2">
                                    <div class="tm-kpi-label">Alertas de prazo</div>
                                    <span class="tm-kpi-icon">
                                        <i class="bi <?php echo $alertCrit > 0 ? 'bi-exclamation-triangle' : 'bi-bell'; ?>"></i>
                                    </span>
                                </div>
                                <div class="tm-kpi-value"><?php echo $alertTotal; ?></div>
                                <div class="tm-kpi-meta">
                                    <?php if ($alertCrit > 0): ?>
                                        <span class="tm-kpi-chip rose"><?php echo $alertCrit; ?> críticos</span>
                                    <?php endif; ?>
                                    <?php if (!empty($resumo_notificacoes['alta'])): ?>
                                        <span class="tm-kpi-chip amber"><?php echo (int)$resumo_notificacoes['alta']; ?> altos</span>
                                    <?php endif; ?>
                                    <?php if ($alertTotal === 0): ?>
                                        <span class="tm-kpi-hint mb-0">Nenhum alerta activo</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Notificações de Prazos Próximos -->
                <?php if (!empty($notificacoes)): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="bi bi-bell me-2"></i>Alertas de Prazos Próximos
                                </h5>
                                <span class="badge bg-<?php echo $resumo_notificacoes['critica'] > 0 ? 'danger' : 'warning'; ?>">
                                    <?php echo $resumo_notificacoes['total']; ?> alertas
                                </span>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Tipo</th>
                                                <th>Descrição</th>
                                                <th>Detalhes</th>
                                                <th>Gravidade</th>
                                                <th>Ação</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($notificacoes as $notif): ?>
                                            <tr>
                                                <td>
                                                    <?php
                                                    switch($notif['tipo']) {
                                                        case 'missao': $icon = 'bi-truck'; break;
                                                        case 'documento': $icon = 'bi-file-earmark'; break;
                                                        case 'cnh': $icon = 'bi-person-badge'; break;
                                                        case 'contrato': $icon = 'bi-file-earmark-text'; break;
                                                        case 'parceria': $icon = 'bi-handshake'; break;
                                                        default: $icon = 'bi-bell';
                                                    }
                                                    ?>
                                                    <i class="bi <?php echo $icon; ?> me-1"></i>
                                                    <?php echo ucfirst($notif['tipo']); ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($notif['descricao']); ?></td>
                                                <td><?php echo htmlspecialchars($notif['detalhe']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo $notif['gravidade'] === 'critica' ? 'danger' : 
                                                            ($notif['gravidade'] === 'alta' ? 'warning' : 'info'); 
                                                    ?>">
                                                        <?php echo ucfirst($notif['gravidade']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="<?php echo $notif['link']; ?>" class="btn btn-sm btn-primary">
                                                        <?php echo $notif['acao']; ?>
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Usuários Pendentes -->
                    <div class="col-md-6 mb-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Usuários Pendentes de Aprovação</h5>
                                <a href="usuarios.php?status=pendente" class="btn btn-sm btn-outline-primary">Ver Todos</a>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($usuarios_pendentes)): ?>
                                    <div class="text-center py-4">
                                        <i class="bi bi-person-check text-muted fs-1"></i>
                                        <p class="mt-2">Nenhum usuário pendente de aprovação.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Usuário</th>
                                                    <th>Tipo</th>
                                                    <th>Registro</th>
                                                    <th>Ações</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($usuarios_pendentes as $usuario): ?>
                                                <tr>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="avatar me-2 bg-light rounded-circle p-2">
                                                                <i class="bi bi-person text-secondary"></i>
                                                            </div>
                                                            <div>
                                                                <h6 class="mb-0"><?php echo htmlspecialchars($usuario['nome']); ?></h6>
                                                                <small class="text-muted"><?php echo htmlspecialchars($usuario['email']); ?></small>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $usuario['tipo_usuario'] == 'caminhoneiro' ? 'primary' : 'info'; ?> rounded-pill">
                                                            <?php echo ucfirst($usuario['tipo_usuario']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <small>
                                                            <?php echo isset($usuario['data_registro']) ? date('d/m/Y', strtotime($usuario['data_registro'])) : ''; ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm">
                                                            <a href="ver-usuario.php?id=<?php echo $usuario['id']; ?>" class="btn btn-outline-secondary">
                                                                <i class="bi bi-eye"></i>
                                                            </a>
                                                            <a href="aprovar-usuario.php?id=<?php echo $usuario['id']; ?>" class="btn btn-outline-success">
                                                                <i class="bi bi-check-lg"></i>
                                                            </a>
                                                            <a href="rejeitar-usuario.php?id=<?php echo $usuario['id']; ?>" class="btn btn-outline-danger">
                                                                <i class="bi bi-x-lg"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Status e atividades recentes -->
                    <div class="col-md-6 mb-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-header bg-white">
                                <h5 class="mb-0">Atividades Recentes do Sistema</h5>
                            </div>
                            <div class="card-body">
                                <div class="timeline-activity">
                                    <div class="activity-item pb-3 border-bottom mb-3">
                                        <div class="d-flex">
                                            <div class="flex-shrink-0">
                                                <div class="activity-icon bg-success bg-opacity-25 p-2 rounded">
                                                    <i class="bi bi-person-plus-fill text-success"></i>
                                                </div>
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <p class="mb-1">Novo usuário registrado</p>
                                                <p class="mb-0 small text-muted">Há 15 minutos</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="activity-item pb-3 border-bottom mb-3">
                                        <div class="d-flex">
                                            <div class="flex-shrink-0">
                                                <div class="activity-icon bg-primary bg-opacity-25 p-2 rounded">
                                                    <i class="bi bi-truck text-primary"></i>
                                                </div>
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <p class="mb-1">Nova missão criada: Transporte para Beira</p>
                                                <p class="mb-0 small text-muted">Há 1 hora</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="activity-item pb-3 border-bottom mb-3">
                                        <div class="d-flex">
                                            <div class="flex-shrink-0">
                                                <div class="activity-icon bg-warning bg-opacity-25 p-2 rounded">
                                                    <i class="bi bi-check-circle text-warning"></i>
                                                </div>
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <p class="mb-1">Missão concluída: Entrega em Maputo</p>
                                                <p class="mb-0 small text-muted">Há 3 horas</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="activity-item">
                                        <div class="d-flex">
                                            <div class="flex-shrink-0">
                                                <div class="activity-icon bg-info bg-opacity-25 p-2 rounded">
                                                    <i class="bi bi-star-fill text-info"></i>
                                                </div>
                                            </div>
                                            <div class="flex-grow-1 ms-3">
                                                <p class="mb-1">Nova avaliação: 5 estrelas para caminhoneiro</p>
                                                <p class="mb-0 small text-muted">Há 5 horas</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                        </div>
                        </div>
                    </div>
                </div>

                <!-- Gráficos e informações adicionais -->
                <div class="row">
                    <!-- Distribuição de usuários -->
                    <div class="col-md-4 mb-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-header bg-white">
                                <h5 class="mb-0">Distribuição de Usuários</h5>
                            </div>
                            <div class="card-body">
                                <div class="d-flex justify-content-center">
                                    <div style="height: 180px; width: 180px;">
                                        <!-- Aqui iria um canvas para gráfico de pizza -->
                                        <div class="text-center py-5">
                                            <div class="row">
                                                <div class="col-6">
                                                    <h3 class="text-primary mb-0"><?php echo $estatisticas['total_caminhoneiros']; ?></h3>
                                                    <p class="small mb-0">Caminhoneiros</p>
                                                </div>
                                                <div class="col-6">
                                                    <h3 class="text-info mb-0"><?php echo $estatisticas['total_empresas']; ?></h3>
                                                    <p class="small mb-0">Empresas</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Status de usuários online -->
                    <div class="col-md-4 mb-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-header bg-white">
                                <h5 class="mb-0">Status do Sistema</h5>
                            </div>
                            <div class="card-body">
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        Usuários Online
                                        <span class="badge bg-success rounded-pill"><?php echo (int)$axHoje['online_agora']; ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        Views site (hoje)
                                        <span class="badge bg-info rounded-pill"><?php echo (int)$axHoje['pageviews_hoje']; ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        Logins (hoje)
                                        <span class="badge bg-primary rounded-pill"><?php echo (int)$axHoje['logins_hoje']; ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        Missões Ativas
                                        <span class="badge bg-primary rounded-pill"><?php echo $estatisticas['total_missoes'] - $estatisticas['missoes_concluidas']; ?></span>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        Uso da CPU
                                        <div class="progress" style="width: 50%;">
                                            <div class="progress-bar progress-bar-striped" role="progressbar" style="width: 25%"></div>
                                        </div>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        Uso da Memória
                                        <div class="progress" style="width: 50%;">
                                            <div class="progress-bar progress-bar-striped bg-success" role="progressbar" style="width: 40%"></div>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Ações rápidas -->
                    <div class="col-md-4 mb-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-header bg-white">
                        <h5 class="mb-0">Ações Rápidas</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                                    <a href="usuarios.php" class="btn btn-outline-primary">
                                        <i class="bi bi-people fs-3 mb-2"></i>
                                        Gerenciar Usuários
                            </a>
                                    <a href="missoes.php" class="btn btn-outline-success">
                                        <i class="bi bi-list-task fs-3 mb-2"></i>
                                        Gerenciar Missões
                            </a>
                                    <a href="relatorios.php" class="btn btn-outline-info">
                                        <i class="bi bi-bar-chart fs-3 mb-2"></i>
                                        Ver Relatórios
                                    </a>
                                    <a href="verificar-documentos.php" class="btn btn-outline-warning">
                                        <i class="bi bi-file-earmark-check fs-3 mb-2"></i>
                                        Verificar Documentos
                            </a>
                                    <a href="contas-irregulares.php" class="btn btn-outline-danger">
                                        <i class="bi bi-exclamation-octagon fs-3 mb-2"></i>
                                        Contas Irregulares
                            </a>
                        </div>
                    </div>
                </div>
            </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="../../assets/js/main.js"></script>
</body>
</html> 