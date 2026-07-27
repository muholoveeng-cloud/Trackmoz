<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');
include_once('../../includes/kpi-helpers.php');
include_once('../../includes/reputacao-helpers.php');

require_role(['caminhoneiro'], '../login.php');

$userId = (int)$_SESSION['user_id'];

if (isset($_POST['toggle_disponibilidade'])) {
    $disponibilidade = $_POST['disponibilidade'] === 'disponivel' ? 'disponivel' : 'indisponivel';
    $stmt = $conn->prepare('UPDATE perfil_caminhoneiro SET disponibilidade = :d WHERE usuario_id = :uid');
    $stmt->execute([':d' => $disponibilidade, ':uid' => $userId]);
}

$kpi = kpi_caminhoneiro($conn, $userId);
$reputacao = reputacao_utilizador($conn, $userId);
$serie = kpi_serie_mensal($conn, ['caminhoneiro_id' => $userId], 6);
$dist = kpi_distribuicao_status($conn, ['caminhoneiro_id' => $userId]);
$periodo = kpi_resumo_periodo($conn, ['caminhoneiro_id' => $userId], 30);

$stmt = $conn->prepare(
    "SELECT m.*, u.nome AS empresa_nome
     FROM missoes m JOIN usuarios u ON m.empresa_id = u.id
     WHERE m.status = 'aberta' ORDER BY m.data_criacao DESC LIMIT 6"
);
$stmt->execute();
$missoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $conn->prepare(
    "SELECT m.id, m.titulo, m.status, m.origem, m.destino, m.prazo_entrega, m.valor
     FROM missoes m
     WHERE m.caminhoneiro_id = :uid
       AND m.status IN ('aceita','em_andamento','em_transito','em_entrega','aguardando_confirmacao')
     ORDER BY m.data_criacao DESC LIMIT 6"
);
$stmt->execute([':uid' => $userId]);
$missoesAtivas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $conn->prepare(
    "SELECT p.id, p.valor AS valor_proposta, p.status, p.data_criacao AS data_proposta, m.titulo, m.origem, m.destino
     FROM propostas p
     JOIN missoes m ON m.id = p.missao_id
     WHERE p.caminhoneiro_id = :uid
     ORDER BY p.data_criacao DESC LIMIT 5"
);
$stmt->execute([':uid' => $userId]);
$propostasRecentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $conn->prepare('SELECT disponibilidade FROM perfil_caminhoneiro WHERE usuario_id = :uid');
$stmt->execute([':uid' => $userId]);
$disponibilidade = $stmt->fetchColumn() ?: 'disponivel';

$nome = $_SESSION['user_name'] ?? 'Motorista';
$hora = (int)date('G');
$saudacao = $hora < 12 ? 'Bom dia' : ($hora < 18 ? 'Boa tarde' : 'Boa noite');
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel do Motorista — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/profissional.css">
</head>
<body>
<?php include_once('../../includes/menu.php'); ?>

<div class="container py-4 tm-dash-page">
    <div class="tm-dash-header">
        <div>
            <h1><?php echo e($saudacao); ?>, <?php echo e(explode(' ', $nome)[0]); ?></h1>
            <p class="sub">
                Reputação: <?php echo reputacao_badge_html($reputacao); ?>
                · Últimos 30 dias: <strong><?php echo e((string)$periodo['taxa']); ?>%</strong> concluídas
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <form method="POST" class="m-0">
                <button type="submit" name="toggle_disponibilidade"
                        class="btn <?php echo $disponibilidade === 'disponivel' ? 'btn-success' : 'btn-outline-danger'; ?> rounded-pill px-3">
                    <i class="bi bi-<?php echo $disponibilidade === 'disponivel' ? 'check-circle' : 'x-circle'; ?>"></i>
                    <?php echo $disponibilidade === 'disponivel' ? 'Disponível' : 'Indisponível'; ?>
                </button>
                <input type="hidden" name="disponibilidade" value="<?php echo $disponibilidade === 'disponivel' ? 'indisponivel' : 'disponivel'; ?>">
            </form>
            <a href="missoes.php" class="btn btn-primary rounded-pill px-3"><i class="bi bi-search me-1"></i>Explorar fretes</a>
        </div>
    </div>

    <?php if (!empty($missoesAtivas)): ?>
        <div class="tm-alert-strip info">
            <i class="bi bi-truck fs-5"></i>
            <div class="flex-grow-1">
                Tem <strong><?php echo count($missoesAtivas); ?></strong> missão(ões) activa(s).
                Continúe a viagem no modo condução quando estiver pronto.
            </div>
            <a href="detalhes-missao.php?id=<?php echo (int)$missoesAtivas[0]['id']; ?>" class="btn btn-sm btn-primary">Abrir</a>
        </div>
    <?php elseif ($disponibilidade !== 'disponivel'): ?>
        <div class="tm-alert-strip warn">
            <i class="bi bi-pause-circle fs-5"></i>
            Está marcado como indisponível — novas missões não o encontrarão facilmente.
        </div>
    <?php endif; ?>

    <?php
    echo kpi_render_cards($kpi, [
        'missoes_ativas'      => ['label' => 'Activas', 'icon' => 'bi-play-circle', 'color' => 'blue', 'hint' => 'Em execução'],
        'missoes_concluidas'  => ['label' => 'Concluídas', 'icon' => 'bi-check2-circle', 'color' => 'green', 'hint' => 'Histórico total'],
        'propostas_pendentes' => ['label' => 'Propostas', 'icon' => 'bi-send', 'color' => 'amber', 'hint' => 'A aguardar resposta'],
        'total_entregas'      => ['label' => 'Entregas', 'icon' => 'bi-box-seam', 'color' => 'cyan', 'hint' => 'Registo no perfil'],
        'avaliacao_media'     => ['label' => 'Avaliação', 'icon' => 'bi-star', 'color' => 'indigo', 'format' => 'decimal', 'hint' => 'Média de notas'],
        'receita_estimada'    => ['label' => 'Receita', 'icon' => 'bi-cash-stack', 'color' => 'green', 'format' => 'money', 'hint' => 'Missões concluídas'],
    ]);
    ?>

    <div class="row g-4 mt-1">
        <div class="col-lg-8">
            <div class="tm-panel h-100 tm-reveal tm-reveal-1">
                <div class="tm-panel-header justify-content-between">
                    <span><i class="bi bi-graph-up"></i> Desempenho — últimos 6 meses</span>
                    <span class="tm-panel-chip">Criadas vs concluídas</span>
                </div>
                <div class="tm-panel-body">
                    <div class="tm-chart-wrap">
                        <canvas id="chartMensal"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="tm-panel h-100 tm-reveal tm-reveal-2">
                <div class="tm-panel-header">
                    <i class="bi bi-pie-chart"></i> Distribuição por estado
                </div>
                <div class="tm-panel-body">
                    <?php if (empty($dist['values'])): ?>
                        <div class="tm-empty-state py-4">Sem dados de missões ainda.</div>
                    <?php else: ?>
                        <div class="tm-chart-wrap sm">
                            <canvas id="chartStatus"></canvas>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mt-1">
        <div class="col-lg-4">
            <div class="tm-panel h-100 tm-reveal tm-reveal-3">
                <div class="tm-panel-header justify-content-between">
                    <span><i class="bi bi-stopwatch"></i> Timers de missões</span>
                    <span class="tm-panel-chip">Prazo + condução</span>
                </div>
                <div class="tm-panel-body" id="tmzDashTimers">
                    <div class="tm-empty-state py-3 mb-0">A carregar timers…</div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="tm-panel h-100 tm-reveal tm-reveal-4">
                <div class="tm-panel-header"><i class="bi bi-bar-chart"></i> Resumo 30 dias</div>
                <div class="tm-panel-body">
                    <div class="tm-stat-pill"><span>Missões no período</span><strong><?php echo (int)$periodo['total']; ?></strong></div>
                    <div class="tm-stat-pill"><span>Concluídas</span><strong class="text-success"><?php echo (int)$periodo['concluidas']; ?></strong></div>
                    <div class="tm-stat-pill"><span>Taxa de conclusão</span><strong><?php echo e((string)$periodo['taxa']); ?>%</strong></div>
                    <div class="tm-stat-pill"><span>Receita (30d)</span><strong><?php echo number_format($periodo['receita'], 0, ',', '.'); ?> MT</strong></div>
                    <div class="tm-stat-pill"><span>Com atraso</span><strong class="<?php echo $periodo['atrasadas'] ? 'text-danger' : ''; ?>"><?php echo (int)$periodo['atrasadas']; ?></strong></div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="tm-panel h-100 tm-reveal tm-reveal-5">
                <div class="tm-panel-header justify-content-between">
                    <span><i class="bi bi-truck"></i> Missões activas</span>
                    <a href="missoes.php" class="small text-decoration-none fw-semibold">Ver todas</a>
                </div>
                <div class="p-0">
                    <?php if (empty($missoesAtivas)): ?>
                        <div class="tm-empty-state">Nenhuma missão activa.</div>
                    <?php else: ?>
                        <?php foreach ($missoesAtivas as $m): ?>
                            <a href="detalhes-missao.php?id=<?php echo (int)$m['id']; ?>" class="tm-feed-item">
                                <div class="d-flex justify-content-between gap-2 align-items-start">
                                    <div class="title text-truncate"><?php echo e($m['titulo']); ?></div>
                                    <?php echo status_missao_badge_html($m['status']); ?>
                                </div>
                                <div class="meta"><?php echo e($m['origem']); ?> → <?php echo e($m['destino']); ?></div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mt-1">
        <div class="col-lg-4">
            <div class="tm-panel h-100 tm-reveal tm-reveal-3">
                <div class="tm-panel-header"><i class="bi bi-lightning"></i> Ações rápidas</div>
                <div class="tm-panel-body">
                    <div class="tm-quick-grid">
                        <a href="missoes.php"><i class="bi bi-search"></i>Explorar fretes</a>
                        <a href="propostas.php"><i class="bi bi-send"></i>Minhas propostas</a>
                        <a href="<?php echo BASE_URL; ?>/pages/chat.php"><i class="bi bi-chat-dots"></i>Mensagens</a>
                        <a href="perfil.php"><i class="bi bi-person"></i>Meu perfil</a>
                        <a href="upload-documentos.php"><i class="bi bi-folder"></i>Documentos</a>
                        <?php if (!empty($missoesAtivas)): ?>
                            <a href="modo-direcao.php?missao_id=<?php echo (int)$missoesAtivas[0]['id']; ?>"><i class="bi bi-compass"></i>Modo condução</a>
                        <?php endif; ?>
                    </div>
                    <p class="small text-muted mt-3 mb-0">
                        <i class="bi bi-broadcast me-1"></i>
                        Alertas sonoros · fretes a 20 km · silenciar no canto inferior direito.
                    </p>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="tm-panel h-100 tm-reveal tm-reveal-4">
                <div class="tm-panel-header justify-content-between">
                    <span><i class="bi bi-geo-alt"></i> Fretes disponíveis</span>
                    <a href="missoes.php" class="btn btn-sm btn-primary rounded-pill px-3">Explorar</a>
                </div>
                <div class="p-0">
                    <?php if (empty($missoes)): ?>
                        <div class="tm-empty-state">Nenhuma missão aberta no momento.</div>
                    <?php else: ?>
                        <?php foreach ($missoes as $missao): ?>
                            <a href="missao.php?id=<?php echo (int)$missao['id']; ?>" class="tm-feed-item">
                                <div class="d-flex justify-content-between gap-2 align-items-start">
                                    <div class="title"><?php echo e($missao['titulo']); ?></div>
                                    <span class="tm-price-pill"><?php echo number_format((float)($missao['valor'] ?? 0), 0, ',', '.'); ?> MT</span>
                                </div>
                                <div class="meta"><?php echo e($missao['origem']); ?> → <?php echo e($missao['destino']); ?></div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="tm-panel h-100 tm-reveal tm-reveal-5">
                <div class="tm-panel-header justify-content-between">
                    <span><i class="bi bi-send"></i> Propostas recentes</span>
                    <a href="propostas.php" class="small text-decoration-none fw-semibold">Ver todas</a>
                </div>
                <div class="p-0">
                    <?php if (empty($propostasRecentes)): ?>
                        <div class="tm-empty-state">Ainda não enviou propostas.</div>
                    <?php else: ?>
                        <?php foreach ($propostasRecentes as $p): ?>
                            <?php
                            $pCls = match ($p['status']) {
                                'pendente' => 'warning',
                                'aceita' => 'success',
                                'rejeitada' => 'danger',
                                default => 'secondary',
                            };
                            ?>
                            <div class="tm-feed-item">
                                <div class="d-flex justify-content-between gap-2 align-items-start">
                                    <div class="title"><?php echo e($p['titulo']); ?></div>
                                    <span class="tm-soft-badge tm-soft-<?php echo e($pCls); ?>"><?php echo e(ucfirst($p['status'])); ?></span>
                                </div>
                                <div class="meta">
                                    <?php echo number_format((float)$p['valor_proposta'], 0, ',', '.'); ?> MT
                                    · <?php echo e(date('d/m/Y', strtotime($p['data_proposta']))); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
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
            type: 'line',
            data: {
                labels: serie.labels,
                datasets: [
                    {
                        label: 'Missões',
                        data: serie.criadas,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37,99,235,.12)',
                        fill: true,
                        tension: .35,
                        borderWidth: 2,
                        pointRadius: 3
                    },
                    {
                        label: 'Concluídas',
                        data: serie.concluidas,
                        borderColor: '#16a34a',
                        backgroundColor: 'rgba(22,163,74,.1)',
                        fill: true,
                        tension: .35,
                        borderWidth: 2,
                        pointRadius: 3
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
