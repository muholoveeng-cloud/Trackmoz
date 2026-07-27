<?php
session_start();
require_once '../../config/app.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/analytics-helpers.php';

require_role(['admin'], '../login.php');

$payload = tmz_analytics_dashboard_payload($conn);
$apiUrl = BASE_URL . '/api/admin-analytics.php';
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acessos / Visitas - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css">
    <style>
        .tm-ax-chart-wrap { position: relative; height: 280px; }
        @media (max-width: 767.98px) { .tm-ax-chart-wrap { height: 240px; } }
    </style>
</head>
<body>
<?php include_once '../../includes/menu.php'; ?>

<main class="container-fluid py-4" style="max-width:1200px">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">Acessos e visitas</h1>
            <p class="text-muted mb-0">Números + gráficos · actualiza a cada 20s</p>
        </div>
        <div class="text-muted small">
            Última actualização: <span id="tm-ax-agora"><?php echo date('H:i:s'); ?></span>
            <span class="badge bg-success ms-2" id="tm-ax-live">ao vivo</span>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-xl">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small mb-1"><i class="bi bi-eye"></i> Views site público</div>
                    <div class="display-6 fw-bold" id="tm-ax-pageviews"><?php echo (int)$payload['pageviews_hoje']; ?></div>
                    <div class="small text-muted">hoje</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small mb-1"><i class="bi bi-people"></i> Visitantes únicos</div>
                    <div class="display-6 fw-bold" id="tm-ax-unicos"><?php echo (int)$payload['visitantes_unicos_hoje']; ?></div>
                    <div class="small text-muted">hoje</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small mb-1"><i class="bi bi-box-arrow-in-right"></i> Logins</div>
                    <div class="display-6 fw-bold text-primary" id="tm-ax-logins"><?php echo (int)$payload['logins_hoje']; ?></div>
                    <div class="small text-muted">hoje</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small mb-1"><i class="bi bi-box-arrow-right"></i> Logouts</div>
                    <div class="display-6 fw-bold" id="tm-ax-logouts"><?php echo (int)$payload['logouts_hoje']; ?></div>
                    <div class="small text-muted">hoje</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="card border-0 shadow-sm h-100 border-success">
                <div class="card-body">
                    <div class="text-muted small mb-1"><i class="bi bi-broadcast"></i> Online agora</div>
                    <div class="display-6 fw-bold text-success" id="tm-ax-online"><?php echo (int)$payload['online_agora']; ?></div>
                    <div class="small text-muted">activos ~15 min</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">Hoje por hora</h2>
                    <div class="small text-muted">Views do site · logins · logouts</div>
                </div>
                <div class="card-body">
                    <div class="tm-ax-chart-wrap">
                        <canvas id="tmChartHoras"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">Distribuição de hoje</h2>
                    <div class="small text-muted">Views vs logins vs logouts</div>
                </div>
                <div class="card-body">
                    <div class="tm-ax-chart-wrap">
                        <canvas id="tmChartPizza"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">Últimos 7 dias</h2>
                    <div class="small text-muted">Evolução de visitas e acessos</div>
                </div>
                <div class="card-body">
                    <div class="tm-ax-chart-wrap" style="height:300px">
                        <canvas id="tmChartDias"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">Utilizadores online</h2>
            <span class="badge bg-success-subtle text-success" id="tm-ax-online-badge"><?php echo (int)$payload['online_agora']; ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Nome</th>
                            <th>Tipo</th>
                            <th>Última actividade</th>
                        </tr>
                    </thead>
                    <tbody id="tm-ax-online-body">
                        <?php if (empty($payload['online_lista'])): ?>
                            <tr><td colspan="3" class="text-muted text-center py-4">Ninguém online neste momento.</td></tr>
                        <?php else: ?>
                            <?php foreach ($payload['online_lista'] as $u): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($u['nome']); ?></td>
                                    <td><span class="badge text-bg-light"><?php echo htmlspecialchars($u['tipo']); ?></span></td>
                                    <td class="text-muted small"><?php echo htmlspecialchars($u['ultimo']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    var api = <?php echo json_encode($apiUrl); ?>;
    var initial = <?php echo json_encode($payload, JSON_UNESCAPED_UNICODE); ?>;

    var chartHoras = null;
    var chartDias = null;
    var chartPizza = null;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function makeCharts(series) {
        series = series || {};
        var horas = series.horas || { labels: [], pageviews: [], logins: [], logouts: [] };
        var dias = series.dias || { labels: [], pageviews: [], unicos: [], logins: [] };

        var commonOpts = {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom' } }
        };

        if (chartHoras) chartHoras.destroy();
        chartHoras = new Chart(document.getElementById('tmChartHoras'), {
            type: 'line',
            data: {
                labels: horas.labels || [],
                datasets: [
                    {
                        label: 'Views site',
                        data: horas.pageviews || [],
                        borderColor: '#0ea5e9',
                        backgroundColor: 'rgba(14,165,233,0.12)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 0
                    },
                    {
                        label: 'Logins',
                        data: horas.logins || [],
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37,99,235,0.08)',
                        fill: false,
                        tension: 0.3,
                        pointRadius: 0
                    },
                    {
                        label: 'Logouts',
                        data: horas.logouts || [],
                        borderColor: '#64748b',
                        backgroundColor: 'transparent',
                        fill: false,
                        tension: 0.3,
                        pointRadius: 0,
                        borderDash: [4, 4]
                    }
                ]
            },
            options: Object.assign({}, commonOpts, {
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                    x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } }
                }
            })
        });

        if (chartDias) chartDias.destroy();
        chartDias = new Chart(document.getElementById('tmChartDias'), {
            type: 'bar',
            data: {
                labels: dias.labels || [],
                datasets: [
                    {
                        label: 'Views',
                        data: dias.pageviews || [],
                        backgroundColor: 'rgba(14,165,233,0.75)',
                        borderRadius: 6
                    },
                    {
                        label: 'Visitantes únicos',
                        data: dias.unicos || [],
                        backgroundColor: 'rgba(16,185,129,0.75)',
                        borderRadius: 6
                    },
                    {
                        label: 'Logins',
                        data: dias.logins || [],
                        backgroundColor: 'rgba(37,99,235,0.75)',
                        borderRadius: 6
                    }
                ]
            },
            options: Object.assign({}, commonOpts, {
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                }
            })
        });
    }

    function updatePizza(data) {
        var vals = [
            data.pageviews_hoje || 0,
            data.logins_hoje || 0,
            data.logouts_hoje || 0
        ];
        if (chartPizza) {
            chartPizza.data.datasets[0].data = vals;
            chartPizza.update('none');
            return;
        }
        chartPizza = new Chart(document.getElementById('tmChartPizza'), {
            type: 'doughnut',
            data: {
                labels: ['Views site', 'Logins', 'Logouts'],
                datasets: [{
                    data: vals,
                    backgroundColor: ['#0ea5e9', '#2563eb', '#94a3b8'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }

    function renderTable(data) {
        document.getElementById('tm-ax-pageviews').textContent = data.pageviews_hoje || 0;
        document.getElementById('tm-ax-unicos').textContent = data.visitantes_unicos_hoje || 0;
        document.getElementById('tm-ax-logins').textContent = data.logins_hoje || 0;
        document.getElementById('tm-ax-logouts').textContent = data.logouts_hoje || 0;
        document.getElementById('tm-ax-online').textContent = data.online_agora || 0;
        document.getElementById('tm-ax-online-badge').textContent = data.online_agora || 0;

        var body = document.getElementById('tm-ax-online-body');
        var list = data.online_lista || [];
        if (!list.length) {
            body.innerHTML = '<tr><td colspan="3" class="text-muted text-center py-4">Ninguém online neste momento.</td></tr>';
        } else {
            body.innerHTML = list.map(function (u) {
                return '<tr><td>' + esc(u.nome) + '</td><td><span class="badge text-bg-light">' +
                    esc(u.tipo) + '</span></td><td class="text-muted small">' + esc(u.ultimo) + '</td></tr>';
            }).join('');
        }
    }

    function apply(data) {
        if (!data) return;
        renderTable(data);
        updatePizza(data);
        if (data.series) {
            if (!chartHoras) {
                makeCharts(data.series);
            } else {
                var h = data.series.horas || {};
                var d = data.series.dias || {};
                chartHoras.data.datasets[0].data = h.pageviews || [];
                chartHoras.data.datasets[1].data = h.logins || [];
                chartHoras.data.datasets[2].data = h.logouts || [];
                chartHoras.update('none');
                chartDias.data.datasets[0].data = d.pageviews || [];
                chartDias.data.datasets[1].data = d.unicos || [];
                chartDias.data.datasets[2].data = d.logins || [];
                chartDias.update('none');
            }
        }
    }

    function tick() {
        fetch(api, { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.ok) return;
                apply(j.data);
                var agora = document.getElementById('tm-ax-agora');
                if (agora && j.agora) agora.textContent = String(j.agora).slice(11);
                var live = document.getElementById('tm-ax-live');
                if (live) {
                    live.className = 'badge bg-success ms-2';
                    live.textContent = 'ao vivo';
                }
            })
            .catch(function () {
                var live = document.getElementById('tm-ax-live');
                if (live) {
                    live.className = 'badge bg-secondary ms-2';
                    live.textContent = 'offline';
                }
            });
    }

    apply(initial);
    setInterval(tick, 20000);
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
