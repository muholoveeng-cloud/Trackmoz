<?php
session_start();
require_once '../../config/app.php';
require_once '../../config/database.php';

// Verificar se o usuário está logado e é um administrador
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'admin') {
    header('Location: ../login.php');
    exit();
}

// Obter estatísticas gerais
$stats = [];

// Total de usuários
$stmt = $conn->query("SELECT COUNT(*) FROM usuarios");
$stats['total_usuarios'] = $stmt->fetchColumn();

// Total de motoristas
$stmt = $conn->query("SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'caminhoneiro'");
$stats['total_motoristas'] = $stmt->fetchColumn();

// Total de empresas
$stmt = $conn->query("SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'empresa'");
$stats['total_empresas'] = $stmt->fetchColumn();

// Total de missões
$stmt = $conn->query("SELECT COUNT(*) FROM missoes");
$stats['total_missoes'] = $stmt->fetchColumn();

// Missões por status
$stmt = $conn->query("SELECT status, COUNT(*) as total FROM missoes GROUP BY status");
$missoes_por_status = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Definir valores padrão para todos os status possíveis
$stats['missoes_por_status'] = [
    'aberta' => 0,
    'em_negociacao' => 0,
    'aceita' => 0,
    'em_andamento' => 0,
    'concluida' => 0,
    'cancelada' => 0
];

// Atualizar com os valores reais do banco
foreach ($missoes_por_status as $status => $total) {
    $stats['missoes_por_status'][$status] = $total;
}

// Avaliações
$stmt = $conn->query("SELECT AVG(nota) FROM avaliacoes");
$stats['media_avaliacoes'] = $stmt->fetchColumn() ?: 0;

// Avaliações por estrela
$stmt = $conn->query("SELECT nota, COUNT(*) as total FROM avaliacoes GROUP BY nota ORDER BY nota DESC");
$stats['avaliacoes_por_estrela'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Usuários por mês (últimos 6 meses)
$stmt = $conn->query("
    SELECT DATE_FORMAT(data_registro, '%Y-%m') as mes, COUNT(*) as total 
    FROM usuarios 
    WHERE data_registro >= DATE_SUB(CURRENT_DATE, INTERVAL 6 MONTH)
    GROUP BY mes 
    ORDER BY mes ASC
");
$stats['usuarios_por_mes'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Missões por mês (últimos 6 meses)
$stmt = $conn->query("
    SELECT DATE_FORMAT(data_criacao, '%Y-%m') as mes, COUNT(*) as total 
    FROM missoes 
    WHERE data_criacao >= DATE_SUB(CURRENT_DATE, INTERVAL 6 MONTH)
    GROUP BY mes 
    ORDER BY mes ASC
");
$stats['missoes_por_mes'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios - TrackMoz Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .stat-card {
            border-left: 4px solid;
            transition: all 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .stat-users { border-left-color: #0d6efd; }
        .stat-missions { border-left-color: #198754; }
        .stat-ratings { border-left-color: #ffc107; }
        .stat-companies { border-left-color: #6f42c1; }
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
                            <a class="nav-link active" href="relatorios.php">
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
                    <h1 class="h2">Relatórios e Estatísticas</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-file-earmark-excel"></i> Exportar
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-printer"></i> Imprimir
                            </button>
                        </div>
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                <i class="bi bi-calendar"></i> Período
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="#">Hoje</a></li>
                                <li><a class="dropdown-item" href="#">Última semana</a></li>
                                <li><a class="dropdown-item" href="#">Último mês</a></li>
                                <li><a class="dropdown-item" href="#">Último trimestre</a></li>
                                <li><a class="dropdown-item" href="#">Último ano</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="#">Personalizado</a></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Estatísticas Gerais -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="admin-stat-card primary">
                            <div class="admin-stat-icon primary">
                                <i class="bi bi-people"></i>
                            </div>
                            <div class="admin-stat-value"><?php echo $stats['total_usuarios']; ?></div>
                            <div class="admin-stat-label">Total de Usuários</div>
                            <div class="mt-3">
                                <small class="text-muted">
                                    <?php echo $stats['total_motoristas']; ?> motoristas, 
                                    <?php echo $stats['total_empresas']; ?> empresas
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="admin-stat-card success">
                            <div class="admin-stat-icon success">
                                <i class="bi bi-list-task"></i>
                            </div>
                            <div class="admin-stat-value"><?php echo $stats['total_missoes']; ?></div>
                            <div class="admin-stat-label">Total de Missões</div>
                            <div class="mt-3">
                                <small class="text-muted">
                                    <?php 
                                    $status_labels = [
                                        'aberta' => 'Abertas',
                                        'em_negociacao' => 'Em negociação',
                                        'aceita' => 'Aceitas',
                                        'em_andamento' => 'Em andamento',
                                        'em_transito' => 'Em trânsito',
                                        'em_entrega' => 'Em entrega',
                                        'emergencia_reportada' => 'Emergência reportada',
                                        'entrega_confirmada' => 'Entrega confirmada',
                                        'aguardando_confirmacao' => 'Aguardando confirmação',
                                        'concluida' => 'Concluídas',
                                        'cancelada' => 'Canceladas',
                                        'emergencia' => 'Emergência'
                                    ];
                                    
                                    $status_display = [];
                                    foreach ($stats['missoes_por_status'] as $status => $total) {
                                        if ($total > 0) {
                                            $label = $status_labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
                                            $status_display[] = $total . ' ' . $label;
                                        }
                                    }
                                    echo implode(', ', $status_display);
                                    ?>
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="admin-stat-card warning">
                            <div class="admin-stat-icon warning">
                                <i class="bi bi-star"></i>
                            </div>
                            <div class="admin-stat-value"><?php echo number_format($stats['media_avaliacoes'], 1); ?></div>
                            <div class="admin-stat-label">Média de Avaliações</div>
                            <div class="mt-3">
                                <small class="text-muted">
                                    <?php 
                                    $total_avaliacoes = array_sum($stats['avaliacoes_por_estrela']);
                                    echo $total_avaliacoes . ' avaliações no total';
                                    ?>
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="admin-stat-card info">
                            <div class="admin-stat-icon info">
                                <i class="bi bi-building"></i>
                            </div>
                            <div class="admin-stat-value"><?php echo $stats['total_empresas']; ?></div>
                            <div class="admin-stat-label">Empresas Ativas</div>
                            <div class="mt-3">
                                <small class="text-muted">
                                    <?php 
                                    $stmt = $conn->query("SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'empresa' AND status = 'ativo'");
                                    echo $stmt->fetchColumn() . ' empresas ativas';
                                    ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Gráficos -->
                <div class="row mb-4">
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <h5 class="card-title">Crescimento de Usuários</h5>
                                <canvas id="usersChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <h5 class="card-title">Missões por Mês</h5>
                                <canvas id="missionsChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <h5 class="card-title">Distribuição de Avaliações</h5>
                                <canvas id="ratingsChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <h5 class="card-title">Status das Missões</h5>
                                <canvas id="missionsStatusChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Gráfico de Usuários
        const usersCtx = document.getElementById('usersChart').getContext('2d');
        new Chart(usersCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_keys($stats['usuarios_por_mes'])); ?>,
                datasets: [{
                    label: 'Novos Usuários',
                    data: <?php echo json_encode(array_values($stats['usuarios_por_mes'])); ?>,
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // Gráfico de Missões
        const missionsCtx = document.getElementById('missionsChart').getContext('2d');
        new Chart(missionsCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_keys($stats['missoes_por_mes'])); ?>,
                datasets: [{
                    label: 'Novas Missões',
                    data: <?php echo json_encode(array_values($stats['missoes_por_mes'])); ?>,
                    backgroundColor: '#198754'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // Gráfico de Avaliações
        const ratingsCtx = document.getElementById('ratingsChart').getContext('2d');
        new Chart(ratingsCtx, {
            type: 'bar',
            data: {
                labels: ['5 estrelas', '4 estrelas', '3 estrelas', '2 estrelas', '1 estrela'],
                datasets: [{
                    label: 'Quantidade de Avaliações',
                    data: [
                        <?php echo $stats['avaliacoes_por_estrela'][5] ?? 0; ?>,
                        <?php echo $stats['avaliacoes_por_estrela'][4] ?? 0; ?>,
                        <?php echo $stats['avaliacoes_por_estrela'][3] ?? 0; ?>,
                        <?php echo $stats['avaliacoes_por_estrela'][2] ?? 0; ?>,
                        <?php echo $stats['avaliacoes_por_estrela'][1] ?? 0; ?>
                    ],
                    backgroundColor: [
                        '#198754',
                        '#20c997',
                        '#ffc107',
                        '#fd7e14',
                        '#dc3545'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // Gráfico de Status das Missões
        const missionsStatusCtx = document.getElementById('missionsStatusChart').getContext('2d');
        new Chart(missionsStatusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Abertas', 'Em negociação', 'Aceitas', 'Em andamento', 'Concluídas', 'Canceladas'],
                datasets: [{
                    data: [
                        <?php echo $stats['missoes_por_status']['aberta'] ?? 0; ?>,
                        <?php echo $stats['missoes_por_status']['em_negociacao'] ?? 0; ?>,
                        <?php echo $stats['missoes_por_status']['aceita'] ?? 0; ?>,
                        <?php echo $stats['missoes_por_status']['em_andamento'] ?? 0; ?>,
                        <?php echo $stats['missoes_por_status']['concluida'] ?? 0; ?>,
                        <?php echo $stats['missoes_por_status']['cancelada'] ?? 0; ?>
                    ],
                    backgroundColor: [
                        '#ffc107',
                        '#0d6efd',
                        '#198754',
                        '#20c997',
                        '#20c997',
                        '#dc3545'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                }
            }
        });
    </script>
</body>
</html> 