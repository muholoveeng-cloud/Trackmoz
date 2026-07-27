<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');
include_once('../../includes/kpi-helpers.php');

require_role(['empresa'], '../login.php');

$userId = (int)$_SESSION['user_id'];

try {
    if (!table_has_column($conn, 'perfil_empresa', 'logo_empresa')) {
        $conn->exec("ALTER TABLE perfil_empresa ADD COLUMN logo_empresa VARCHAR(255) DEFAULT NULL");
    }
} catch (Throwable $e) {
    error_log('dashboard logo column check: ' . $e->getMessage());
}

$stmt = $conn->prepare(
    "SELECT pe.nome_empresa, pe.logo_empresa, u.foto_perfil, u.nome
     FROM usuarios u
     LEFT JOIN perfil_empresa pe ON pe.usuario_id = u.id
     WHERE u.id = :id"
);
$stmt->execute([':id' => $userId]);
$empresaInfo = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$kpi = kpi_empresa($conn, $userId);
$serie = kpi_serie_mensal($conn, ['empresa_id' => $userId], 6);
$dist = kpi_distribuicao_status($conn, ['empresa_id' => $userId]);
$periodo = kpi_resumo_periodo($conn, ['empresa_id' => $userId], 30);

$stmt = $conn->prepare(
    "SELECT m.*, u.nome AS caminhoneiro_nome
     FROM missoes m
     LEFT JOIN usuarios u ON m.caminhoneiro_id = u.id
     WHERE m.empresa_id = :eid
       AND m.status IN ('aceita','em_andamento','em_transito','em_entrega','aguardando_confirmacao')
     ORDER BY m.data_criacao DESC
     LIMIT 6"
);
$stmt->execute([':eid' => $userId]);
$missoesAndamento = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $conn->prepare(
    "SELECT p.id, p.valor AS valor_proposta, p.status, p.data_criacao AS data_proposta, m.titulo, m.id AS missao_id, u.nome AS motorista
     FROM propostas p
     JOIN missoes m ON m.id = p.missao_id
     JOIN usuarios u ON u.id = p.caminhoneiro_id
     WHERE m.empresa_id = :eid AND p.status = 'pendente'
     ORDER BY p.data_criacao DESC
     LIMIT 5"
);
$stmt->execute([':eid' => $userId]);
$propostasPendentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $conn->prepare(
    "SELECT m.id, m.titulo, m.origem, m.destino, m.prazo_entrega, m.status
     FROM missoes m
     WHERE m.empresa_id = :eid
       AND m.status NOT IN ('concluida','entrega_confirmada','cancelada')
       AND m.prazo_entrega IS NOT NULL AND m.prazo_entrega < CURDATE()
     ORDER BY m.prazo_entrega ASC
     LIMIT 5"
);
$stmt->execute([':eid' => $userId]);
$atrasadas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$emergencias = [];
try {
    $stmt = $conn->prepare(
        "SELECT e.id, e.tipo, e.gravidade, e.data_criacao AS criado_em, m.titulo
         FROM emergencias e
         LEFT JOIN missoes m ON m.id = e.missao_id
         WHERE m.empresa_id = :eid AND e.status IN ('aberta','em_atendimento')
         ORDER BY e.data_criacao DESC LIMIT 3"
    );
    $stmt->execute([':eid' => $userId]);
    $emergencias = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $emergencias = [];
}

$nomeEmpresa = $empresaInfo['nome_empresa'] ?? ($empresaInfo['nome'] ?? 'Empresa');
$hora = (int)date('G');
$saudacao = $hora < 12 ? 'Bom dia' : ($hora < 18 ? 'Boa tarde' : 'Boa noite');
$logoUrl = null;
if (!empty($empresaInfo['logo_empresa'])) {
    $logoUrl = BASE_URL . '/uploads/logos/' . rawurlencode($empresaInfo['logo_empresa']);
} elseif (!empty($empresaInfo['foto_perfil'])) {
    $logoUrl = BASE_URL . '/uploads/perfil/' . rawurlencode($empresaInfo['foto_perfil']);
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel da Empresa — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/profissional.css">
</head>
<body>
<?php include_once('../../includes/menu.php'); ?>

<div class="container py-4">
    <div class="tm-dash-header">
        <div class="d-flex align-items-center gap-3">
            <?php if ($logoUrl): ?>
                <img src="<?php echo e($logoUrl); ?>" alt="Logo" width="56" height="56"
                     style="object-fit:contain;border:1px solid #e2e8f0;border-radius:12px;padding:4px;background:#fff;">
            <?php else: ?>
                <div class="d-flex align-items-center justify-content-center"
                     style="width:56px;height:56px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;">
                    <i class="bi bi-building fs-4 text-secondary"></i>
                </div>
            <?php endif; ?>
            <div>
                <h1><?php echo e($saudacao); ?> — <?php echo e($nomeEmpresa); ?></h1>
                <p class="sub">
                    Painel operacional · Últimos 30 dias:
                    <strong><?php echo e((string)$periodo['taxa']); ?>%</strong> de conclusão
                    · <?php echo number_format($periodo['receita'], 0, ',', '.'); ?> MT
                </p>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="publicar-missao.php" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Publicar missão</a>
            <a href="<?php echo BASE_URL; ?>/pages/shared/centro-operacoes.php" class="btn btn-outline-primary" target="_blank" rel="noopener"><i class="bi bi-radar me-1"></i>Operações</a>
        </div>
    </div>

    <?php if ($kpi['emergencias'] > 0 || !empty($emergencias)): ?>
        <div class="tm-alert-strip">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div class="flex-grow-1">
                <strong><?php echo max($kpi['emergencias'], count($emergencias)); ?></strong> emergência(s) a requerer atenção.
            </div>
            <a href="missoes.php?status=emergencia" class="btn btn-sm btn-danger">Ver</a>
        </div>
    <?php elseif ($kpi['missoes_atrasadas'] > 0): ?>
        <div class="tm-alert-strip warn">
            <i class="bi bi-clock-history fs-5"></i>
            <div class="flex-grow-1">
                <strong><?php echo (int)$kpi['missoes_atrasadas']; ?></strong> missão(ões) com prazo ultrapassado.
            </div>
            <a href="missoes.php" class="btn btn-sm btn-warning">Rever</a>
        </div>
    <?php elseif (count($propostasPendentes) > 0): ?>
        <div class="tm-alert-strip info">
            <i class="bi bi-inbox fs-5"></i>
            <div class="flex-grow-1">
                Tem <strong><?php echo count($propostasPendentes); ?></strong> proposta(s) pendente(s) de avaliação.
            </div>
            <a href="propostas.php" class="btn btn-sm btn-primary">Avaliar</a>
        </div>
    <?php endif; ?>

    <?php
    echo kpi_render_cards($kpi, [
        'missoes_andamento'  => ['label' => 'Em execução', 'icon' => 'bi-truck', 'color' => 'blue', 'hint' => 'Operacionais'],
        'missoes_abertas'    => ['label' => 'Abertas', 'icon' => 'bi-megaphone', 'color' => 'cyan', 'hint' => 'A receber propostas'],
        'missoes_concluidas' => ['label' => 'Concluídas', 'icon' => 'bi-check2-all', 'color' => 'green', 'hint' => 'Histórico'],
        'missoes_atrasadas'  => ['label' => 'Atrasadas', 'icon' => 'bi-exclamation-circle', 'color' => 'amber', 'hint' => 'Prazo ultrapassado'],
        'emergencias'        => ['label' => 'Emergências', 'icon' => 'bi-shield-exclamation', 'color' => 'rose', 'hint' => 'Requer acção'],
        'receita_total'      => ['label' => 'Receita', 'icon' => 'bi-cash-coin', 'color' => 'indigo', 'format' => 'money', 'hint' => 'Missões concluídas'],
    ]);
    ?>

    <div class="row g-4 mt-1">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm tm-panel h-100">
                <div class="tm-panel-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-graph-up text-primary me-2"></i>Volume operacional — 6 meses</span>
                    <span class="badge bg-light text-secondary border">Publicadas vs concluídas</span>
                </div>
                <div class="tm-panel-body">
                    <div class="tm-chart-wrap">
                        <canvas id="chartMensal"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm tm-panel h-100">
                <div class="tm-panel-header">
                    <i class="bi bi-pie-chart text-primary me-2"></i>Estado das missões
                </div>
                <div class="tm-panel-body">
                    <?php if (empty($dist['values'])): ?>
                        <div class="tm-empty-state py-4">Publique a primeira missão para ver o relatório.</div>
                    <?php else: ?>
                        <div class="tm-chart-wrap sm">
                            <canvas id="chartStatus"></canvas>
                        </div>
                    <?php endif; ?>
                    <div class="tm-stat-pill mt-2"><span>Parcerias activas</span><strong><?php echo (int)$kpi['parcerias_ativas']; ?></strong></div>
                    <div class="tm-stat-pill"><span>Total de missões</span><strong><?php echo (int)$kpi['total_missoes']; ?></strong></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mt-1">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm tm-panel h-100">
                <div class="tm-panel-header d-flex justify-content-between align-items-center">
                    <span>Missões em execução</span>
                    <a href="missoes.php" class="btn btn-sm btn-outline-primary">Ver todas</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($missoesAndamento)): ?>
                        <div class="tm-empty-state">Nenhuma missão em andamento.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 tm-table-compact align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Missão</th>
                                        <th>Motorista</th>
                                        <th>Estado</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($missoesAndamento as $missao): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo e($missao['titulo']); ?></div>
                                            <small class="text-muted"><?php echo e($missao['origem']); ?> → <?php echo e($missao['destino']); ?></small>
                                        </td>
                                        <td><?php echo e($missao['caminhoneiro_nome'] ?: '—'); ?></td>
                                        <td><?php echo status_missao_badge_html($missao['status']); ?></td>
                                        <td class="text-end">
                                            <a href="detalhes-missao.php?id=<?php echo (int)$missao['id']; ?>" class="btn btn-sm btn-outline-primary">Acompanhar</a>
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
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm tm-panel mb-4">
                <div class="tm-panel-header">Resumo 30 dias</div>
                <div class="tm-panel-body">
                    <div class="tm-stat-pill"><span>Missões no período</span><strong><?php echo (int)$periodo['total']; ?></strong></div>
                    <div class="tm-stat-pill"><span>Concluídas</span><strong class="text-success"><?php echo (int)$periodo['concluidas']; ?></strong></div>
                    <div class="tm-stat-pill"><span>Taxa de conclusão</span><strong><?php echo e((string)$periodo['taxa']); ?>%</strong></div>
                    <div class="tm-stat-pill"><span>Receita (30d)</span><strong><?php echo number_format($periodo['receita'], 0, ',', '.'); ?> MT</strong></div>
                    <div class="tm-stat-pill"><span>Atrasadas</span><strong class="<?php echo $periodo['atrasadas'] ? 'text-danger' : ''; ?>"><?php echo (int)$periodo['atrasadas']; ?></strong></div>
                </div>
            </div>
            <div class="card border-0 shadow-sm tm-panel">
                <div class="tm-panel-header">Ações rápidas</div>
                <div class="tm-panel-body">
                    <div class="tm-quick-grid">
                        <a href="publicar-missao.php"><i class="bi bi-plus-circle"></i>Nova missão</a>
                        <a href="propostas.php"><i class="bi bi-inbox"></i>Propostas</a>
                        <a href="parcerias.php"><i class="bi bi-handshake"></i>Parcerias</a>
                        <a href="mapa-missoes.php"><i class="bi bi-map"></i>Mapa</a>
                        <a href="<?php echo BASE_URL; ?>/pages/chat.php"><i class="bi bi-chat-dots"></i>Mensagens</a>
                        <a href="documentos/relatorio.php"><i class="bi bi-file-earmark-bar-graph"></i>Relatório</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mt-1">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm tm-panel">
                <div class="tm-panel-header d-flex justify-content-between align-items-center">
                    <span>Propostas pendentes</span>
                    <a href="propostas.php" class="small text-decoration-none">Gerir</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($propostasPendentes)): ?>
                        <div class="tm-empty-state">Sem propostas a aguardar.</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($propostasPendentes as $p): ?>
                                <a href="detalhes-missao.php?id=<?php echo (int)$p['missao_id']; ?>" class="list-group-item list-group-item-action">
                                    <div class="d-flex justify-content-between gap-2">
                                        <span class="fw-semibold"><?php echo e($p['titulo']); ?></span>
                                        <span class="badge bg-warning text-dark"><?php echo number_format((float)$p['valor_proposta'], 0, ',', '.'); ?> MT</span>
                                    </div>
                                    <small class="text-muted"><?php echo e($p['motorista']); ?> · <?php echo e(date('d/m/Y H:i', strtotime($p['data_proposta']))); ?></small>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm tm-panel">
                <div class="tm-panel-header d-flex justify-content-between align-items-center">
                    <span>Prazos em atraso</span>
                    <span class="badge <?php echo $atrasadas ? 'bg-danger' : 'bg-success'; ?>"><?php echo count($atrasadas); ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($atrasadas)): ?>
                        <div class="tm-empty-state">Nenhum atraso activo — bom trabalho.</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($atrasadas as $m): ?>
                                <a href="detalhes-missao.php?id=<?php echo (int)$m['id']; ?>" class="list-group-item list-group-item-action">
                                    <div class="d-flex justify-content-between gap-2">
                                        <span class="fw-semibold"><?php echo e($m['titulo']); ?></span>
                                        <span class="text-danger small fw-semibold"><?php echo e(date('d/m/Y', strtotime($m['prazo_entrega']))); ?></span>
                                    </div>
                                    <small class="text-muted"><?php echo e($m['origem']); ?> → <?php echo e($m['destino']); ?></small>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    const serie = <?php echo json_encode($serie, JSON_UNESCAPED_UNICODE); ?>;
    const dist = <?php echo json_encode($dist, JSON_UNESCAPED_UNICODE); ?>;

    const ctx = document.getElementById('chartMensal');
    if (ctx) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: serie.labels,
                datasets: [
                    {
                        label: 'Publicadas',
                        data: serie.criadas,
                        backgroundColor: 'rgba(37,99,235,.75)',
                        borderRadius: 6,
                        maxBarThickness: 28
                    },
                    {
                        label: 'Concluídas',
                        data: serie.concluidas,
                        backgroundColor: 'rgba(22,163,74,.75)',
                        borderRadius: 6,
                        maxBarThickness: 28
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, usePointStyle: true } } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f5f9' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    const ctx2 = document.getElementById('chartStatus');
    if (ctx2 && dist.values && dist.values.length) {
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: dist.labels,
                datasets: [{
                    data: dist.values,
                    backgroundColor: ['#2563eb','#16a34a','#d97706','#0891b2','#4f46e5','#dc2626','#94a3b8','#0ea5e9'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } },
                cutout: '62%'
            }
        });
    }
})();
</script>
</body>
</html>
