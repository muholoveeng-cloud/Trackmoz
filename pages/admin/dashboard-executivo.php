<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');

require_role(['admin'], '../login.php');

$conn = getConnection();

// KPIs
$kpis = [];

// Missões
$stmt = $conn->query("SELECT COUNT(*) FROM missoes");
$kpis['total_missoes'] = (int)$stmt->fetchColumn();

$stmt = $conn->query("SELECT COUNT(*) FROM missoes WHERE status = 'concluida'");
$kpis['missoes_concluidas'] = (int)$stmt->fetchColumn();

$stmt = $conn->query("SELECT COUNT(*) FROM missoes WHERE status IN ('em_andamento','em_transito','em_entrega')");
$kpis['missoes_ativas'] = (int)$stmt->fetchColumn();

$stmt = $conn->query("SELECT COUNT(*) FROM missoes WHERE status = 'emergencia_reportada'");
$kpis['emergencias'] = (int)$stmt->fetchColumn();

$stmt = $conn->query("SELECT COUNT(*) FROM missoes WHERE status = 'aberta'");
$kpis['missoes_abertas'] = (int)$stmt->fetchColumn();

// Usuários
$stmt = $conn->query("SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'caminhoneiro' AND status = 'ativo'");
$kpis['motoristas_ativos'] = (int)$stmt->fetchColumn();

$stmt = $conn->query("SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'empresa' AND status = 'ativo'");
$kpis['empresas_ativas'] = (int)$stmt->fetchColumn();

$stmt = $conn->query("SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'transportador' AND status = 'ativo'");
$kpis['transportadores_ativos'] = (int)$stmt->fetchColumn();

// Frota
$stmt = $conn->query("SELECT COUNT(*) FROM veiculos WHERE estado_operacional = 'ativo'");
$kpis['veiculos_ativos'] = (int)$stmt->fetchColumn();

$stmt = $conn->query("SELECT COUNT(*) FROM veiculos WHERE estado_operacional = 'manutencao'");
$kpis['veiculos_manutencao'] = (int)$stmt->fetchColumn();

// Financeiro
$stmt = $conn->query("SELECT SUM(valor) FROM missoes WHERE status = 'concluida' AND valor IS NOT NULL");
$kpis['receita_total'] = (float)($stmt->fetchColumn() ?? 0);

$stmt = $conn->query("SELECT SUM(valor) FROM custos_operacionais");
$kpis['custos_total'] = (float)($stmt->fetchColumn() ?? 0);

$stmt = $conn->query("SELECT SUM(valor) FROM manutencoes");
$kpis['custos_manutencao'] = (float)($stmt->fetchColumn() ?? 0);

$stmt = $conn->query("SELECT SUM(valor_total) FROM abastecimentos");
$kpis['custos_combustivel'] = (float)($stmt->fetchColumn() ?? 0);

$kpis['lucro'] = $kpis['receita_total'] - $kpis['custos_total'] - $kpis['custos_manutencao'] - $kpis['custos_combustivel'];

// Alertas
$alertas = [];
$stmt = $conn->query(
    "SELECT vd.*, v.matricula FROM veiculo_documentos vd
     JOIN veiculos v ON vd.veiculo_id = v.id
     WHERE vd.data_validade < DATE_ADD(CURDATE(), INTERVAL 30 DAY)
     ORDER BY vd.data_validade ASC LIMIT 10"
);
$alertas['documentos'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $conn->query(
    "SELECT m.titulo, m.status, m.prazo_entrega, u.nome AS nome_caminhoneiro
     FROM missoes m
     LEFT JOIN usuarios u ON m.caminhoneiro_id = u.id
     WHERE m.prazo_entrega < DATE_ADD(CURDATE(), INTERVAL 3 DAY)
     AND m.status NOT IN ('concluida','cancelada')
     ORDER BY m.prazo_entrega ASC LIMIT 10"
);
$alertas['prazos'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $conn->query(
    "SELECT e.*, m.titulo, u.nome AS nome_caminhoneiro
     FROM emergencias e
     JOIN missoes m ON e.missao_id = m.id
     JOIN usuarios u ON e.caminhoneiro_id = u.id
     WHERE e.status IN ('aberta','em_andamento')
     ORDER BY e.data_criacao DESC LIMIT 10"
);
$alertas['emergencias'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Missões por status para gráfico
$missoesStatus = [];
$stmt = $conn->query("SELECT status, COUNT(*) AS total FROM missoes GROUP BY status");
while ($row = $stmt->fetch()) $missoesStatus[$row['status']] = (int)$row['total'];

// Missões por mês
$missoesMes = [];
$stmt = $conn->query("SELECT DATE_FORMAT(data_criacao, '%Y-%m') AS mes, COUNT(*) AS total FROM missoes GROUP BY mes ORDER BY mes DESC LIMIT 6");
while ($row = $stmt->fetch()) $missoesMes[$row['mes']] = (int)$row['total'];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Executivo — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="mb-0"><i class="bi bi-speedometer2 me-2 text-primary"></i>Dashboard Executivo</h3>
            <span class="text-muted small"><?php echo date('d/m/Y H:i'); ?></span>
        </div>

        <!-- KPIs -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3 col-lg-2">
                <div class="card border-0 shadow-sm text-center h-100">
                    <div class="card-body">
                        <div class="text-muted small mb-1">Missões Totais</div>
                        <div class="fs-3 fw-bold text-primary"><?php echo number_format($kpis['total_missoes']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <div class="card border-0 shadow-sm text-center h-100">
                    <div class="card-body">
                        <div class="text-muted small mb-1">Concluídas</div>
                        <div class="fs-3 fw-bold text-success"><?php echo number_format($kpis['missoes_concluidas']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <div class="card border-0 shadow-sm text-center h-100">
                    <div class="card-body">
                        <div class="text-muted small mb-1">Activas</div>
                        <div class="fs-3 fw-bold text-warning"><?php echo number_format($kpis['missoes_ativas']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <div class="card border-0 shadow-sm text-center h-100">
                    <div class="card-body">
                        <div class="text-muted small mb-1">Emergências</div>
                        <div class="fs-3 fw-bold text-danger"><?php echo number_format($kpis['emergencias']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <div class="card border-0 shadow-sm text-center h-100">
                    <div class="card-body">
                        <div class="text-muted small mb-1">Motoristas</div>
                        <div class="fs-3 fw-bold text-info"><?php echo number_format($kpis['motoristas_ativos']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <div class="card border-0 shadow-sm text-center h-100">
                    <div class="card-body">
                        <div class="text-muted small mb-1">Veículos</div>
                        <div class="fs-3 fw-bold text-secondary"><?php echo number_format($kpis['veiculos_ativos']); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Financeiro -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small mb-2"><i class="bi bi-cash-stack me-1"></i>Receita Total</div>
                        <div class="fs-4 fw-bold text-success"><?php echo number_format($kpis['receita_total'], 2, ',', '.'); ?> MT</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small mb-2"><i class="bi bi-receipt me-1"></i>Custos Totais</div>
                        <div class="fs-4 fw-bold text-danger"><?php echo number_format($kpis['custos_total'] + $kpis['custos_manutencao'] + $kpis['custos_combustivel'], 2, ',', '.'); ?> MT</div>
                        <div class="small text-muted mt-1">
                            Manut.: <?php echo number_format($kpis['custos_manutencao'], 2, ',', '.'); ?> ·
                            Comb.: <?php echo number_format($kpis['custos_combustivel'], 2, ',', '.'); ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small mb-2"><i class="bi bi-graph-up me-1"></i>Margem / Lucro</div>
                        <div class="fs-4 fw-bold <?php echo $kpis['lucro'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo number_format($kpis['lucro'], 2, ',', '.'); ?> MT</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gráficos -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-transparent fw-semibold"><i class="bi bi-pie-chart me-2"></i>Missões por Estado</div>
                    <div class="card-body"><canvas id="chartStatus"></canvas></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-transparent fw-semibold"><i class="bi bi-bar-chart me-2"></i>Missões por Mês</div>
                    <div class="card-body"><canvas id="chartMes"></canvas></div>
                </div>
            </div>
        </div>

        <!-- Alertas -->
        <div class="row g-3">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-transparent fw-semibold text-warning"><i class="bi bi-exclamation-triangle me-2"></i>Documentos a Vencer</div>
                    <div class="card-body p-0">
                        <?php if (empty($alertas['documentos'])): ?>
                            <div class="text-center py-3 text-muted small">Nenhum alerta</div>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($alertas['documentos'] as $a):
                                    $dias = (int)ceil((strtotime($a['data_validade']) - time()) / 86400);
                                ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center small">
                                        <span><?php echo e($a['matricula']); ?> · <?php echo e($a['tipo']); ?></span>
                                        <span class="badge <?php echo $dias < 0 ? 'bg-danger' : 'bg-warning text-dark'; ?>"><?php echo $dias < 0 ? 'Expirado' : $dias . 'd'; ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-transparent fw-semibold text-danger"><i class="bi bi-clock me-2"></i>Prazos Próximos</div>
                    <div class="card-body p-0">
                        <?php if (empty($alertas['prazos'])): ?>
                            <div class="text-center py-3 text-muted small">Nenhum alerta</div>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($alertas['prazos'] as $a):
                                    $dias = (int)ceil((strtotime($a['prazo_entrega']) - time()) / 86400);
                                ?>
                                    <li class="list-group-item small">
                                        <div class="d-flex justify-content-between">
                                            <span class="text-truncate" style="max-width:70%"><?php echo e($a['titulo']); ?></span>
                                            <span class="badge <?php echo $dias < 0 ? 'bg-danger' : 'bg-warning text-dark'; ?>"><?php echo $dias < 0 ? 'Atrasada' : $dias . 'd'; ?></span>
                                        </div>
                                        <div class="text-muted small"><?php echo e($a['nome_caminhoneiro'] ?: 'Sem motorista'); ?></div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-transparent fw-semibold text-danger"><i class="bi bi-exclamation-octagon me-2"></i>Emergências Abertas</div>
                    <div class="card-body p-0">
                        <?php if (empty($alertas['emergencias'])): ?>
                            <div class="text-center py-3 text-muted small">Nenhuma emergência aberta</div>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($alertas['emergencias'] as $a): ?>
                                    <li class="list-group-item small">
                                        <div class="d-flex justify-content-between">
                                            <span class="text-truncate" style="max-width:70%"><?php echo e($a['titulo']); ?></span>
                                            <span class="badge bg-<?php echo $a['gravidade'] === 'critica' ? 'dark' : ($a['gravidade'] === 'alta' ? 'danger' : 'warning'); ?>"><?php echo e($a['gravidade']); ?></span>
                                        </div>
                                        <div class="text-muted small"><?php echo e($a['nome_caminhoneiro']); ?> · <?php echo date('d/m H:i', strtotime($a['data_criacao'])); ?></div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    new Chart(document.getElementById('chartStatus'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_map('status_missao_label', array_keys($missoesStatus))); ?>,
            datasets: [{ data: <?php echo json_encode(array_values($missoesStatus)); ?>, borderWidth: 2 }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });
    new Chart(document.getElementById('chartMes'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_keys($missoesMes)); ?>,
            datasets: [{ label: 'Missões', data: <?php echo json_encode(array_values($missoesMes)); ?>, backgroundColor: '#0d6efd', borderRadius: 4 }]
        },
        options: { responsive: true, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
    });
    </script>
</body>
</html>
