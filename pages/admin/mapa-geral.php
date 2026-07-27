<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/geocode.php');

require_role(['admin'], '../login.php');

try {
    $stmt = $conn->prepare(
        "SELECT m.id, m.titulo, m.status, m.origem, m.destino,
                m.caminhoneiro_id, m.empresa_id,
                u_mot.nome AS motorista_nome,
                pe.nome_empresa,
                COALESCE(pc.ultima_localizacao_lat, pc.latitude) AS lat,
                COALESCE(pc.ultima_localizacao_lng, pc.longitude) AS lng,
                pc.ultima_atualizacao_local AS atualizado_em,
                lo.latitude  AS origem_lat,  lo.longitude AS origem_lng,
                ld.latitude  AS destino_lat, ld.longitude AS destino_lng
         FROM missoes m
         LEFT JOIN usuarios u_mot        ON m.caminhoneiro_id = u_mot.id
         LEFT JOIN perfil_empresa pe      ON m.empresa_id = pe.usuario_id
         LEFT JOIN perfil_caminhoneiro pc ON m.caminhoneiro_id = pc.usuario_id
         LEFT JOIN locais lo ON m.local_origem_id  = lo.id
         LEFT JOIN locais ld ON m.local_destino_id = ld.id
         WHERE m.status IN ('aberta','aceita','em_andamento','em_transito','em_entrega','aguardando_confirmacao','emergencia')
         ORDER BY m.data_criacao DESC"
    );
    $stmt->execute();
    $missoes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $missoes[] = enriquecer_missao_mapa($row);
    }

    $stmtMot = $conn->query(
        "SELECT u.id, u.nome, u.telefone,
                pc.ultima_localizacao_lat AS lat,
                pc.ultima_localizacao_lng AS lng,
                pc.ultima_atualizacao_local AS atualizado_em,
                pc.disponibilidade
         FROM usuarios u
         INNER JOIN perfil_caminhoneiro pc ON u.id = pc.usuario_id
         WHERE u.tipo_usuario = 'caminhoneiro'
           AND u.status = 'ativo'
           AND pc.ultima_localizacao_lat IS NOT NULL
           AND pc.ultima_localizacao_lng IS NOT NULL"
    );
    $motoristas = $stmtMot->fetchAll(PDO::FETCH_ASSOC);

    $stats_stmt = $conn->prepare(
        "SELECT
            SUM(CASE WHEN status IN ('aceita','em_andamento','em_transito','em_entrega','aguardando_confirmacao') THEN 1 ELSE 0 END) AS ativas,
            SUM(CASE WHEN status = 'emergencia' THEN 1 ELSE 0 END) AS emergencias,
            SUM(CASE WHEN status = 'aberta'     THEN 1 ELSE 0 END) AS abertas,
            COUNT(*) AS total
         FROM missoes"
    );
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('mapa-geral: ' . $e->getMessage());
    $missoes = [];
    $motoristas = [];
    $stats   = ['ativas' => 0, 'emergencias' => 0, 'abertas' => 0, 'total' => 0];
}

function status_info(string $s): array {
    return match($s) {
        'aceita'                 => ['Aceita',          'success'],
        'em_andamento'           => ['Em Andamento',    'warning'],
        'em_transito'            => ['Em Trânsito',     'primary'],
        'em_entrega'             => ['Em Entrega',      'info'],
        'aguardando_confirmacao' => ['Ag. Confirmação', 'secondary'],
        'emergencia'             => ['EMERGÊNCIA',      'danger'],
        default                  => [ucfirst($s),       'secondary'],
    };
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mapa Geral — TrackMoz Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <style>
        #map { height: calc(100vh - 190px); min-height: 450px; border-radius: 10px; z-index: 0; }
        .sidebar-list { max-height: calc(100vh - 260px); overflow-y: auto; }
        .missao-item { border-left: 3px solid transparent; cursor: pointer; transition: all .15s; }
        .missao-item:hover { background: #f0f4ff; }
        .missao-item.selected { background: #e8f0fe; border-left-color: #0d6efd; }
        .missao-item.emergencia { border-left-color: #dc3545; background: #fff5f5; }
        .pulse-red { animation: pulseRed 1s infinite; }
        @keyframes pulseRed { 0%,100%{opacity:1} 50%{opacity:.4} }
    </style>
</head>
<body>
<?php include_once('../../includes/menu.php'); ?>

<div class="bg-white border-bottom py-2">
    <div class="container-fluid">
        <div class="row g-2 text-center">
            <div class="col-6 col-md-3">
                <div class="d-flex align-items-center justify-content-center gap-2">
                    <i class="bi bi-truck text-primary fs-5"></i>
                    <div class="text-start">
                        <div class="fw-bold"><?php echo (int)$stats['ativas']; ?></div>
                        <div class="small text-muted">Missões activas</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="d-flex align-items-center justify-content-center gap-2">
                    <i class="bi bi-geo-alt-fill text-success fs-5"></i>
                    <div class="text-start">
                        <div class="fw-bold" id="statGps"><?php echo count(array_filter($missoes, fn($m) => $m['lat'] && $m['lng'])); ?></div>
                        <div class="small text-muted">Com GPS activo</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="d-flex align-items-center justify-content-center gap-2">
                    <i class="bi bi-list-task text-warning fs-5"></i>
                    <div class="text-start">
                        <div class="fw-bold"><?php echo (int)$stats['abertas']; ?></div>
                        <div class="small text-muted">Abertas</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <?php if ($stats['emergencias'] > 0): ?>
                <div class="d-flex align-items-center justify-content-center gap-2">
                    <i class="bi bi-exclamation-triangle-fill text-danger fs-5 pulse-red"></i>
                    <div class="text-start">
                        <div class="fw-bold text-danger"><?php echo (int)$stats['emergencias']; ?></div>
                        <div class="small text-danger">Emergências!</div>
                    </div>
                </div>
                <?php else: ?>
                <div class="d-flex align-items-center justify-content-center gap-2">
                    <i class="bi bi-check-circle-fill text-success fs-5"></i>
                    <div class="text-start">
                        <div class="fw-bold"><?php echo (int)$stats['total']; ?></div>
                        <div class="small text-muted">Total missões</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid px-3 py-3">
    <div class="row g-3">
        <div class="col-lg-9">
            <div id="map"></div>
        </div>
        <div class="col-lg-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0 fw-semibold">Missões no mapa (<?php echo count($missoes); ?>)</h6>
                <button class="btn btn-sm btn-outline-secondary" id="btnRefreshMapa" type="button" title="Actualizar">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </div>
            <div class="small text-muted mb-2">
                <span class="badge bg-success me-1">●</span> Origem
                <span class="badge bg-danger me-1">●</span> Destino
                <span class="badge bg-primary me-1">🚛</span> GPS missão
                <span class="badge bg-secondary">👤</span> Motorista livre
            </div>

            <!-- Emergências -->
            <?php foreach ($missoes as $m):
                if ($m['status'] !== 'emergencia') continue;
                [$lbl,$cls] = status_info($m['status']);
            ?>
            <div class="card missao-item emergencia mb-2 p-2"
                 onclick="focarMissao(<?php echo (int)$m['id']; ?>)"
                 id="item-<?php echo (int)$m['id']; ?>">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <div class="fw-semibold small text-danger">
                        <i class="bi bi-exclamation-triangle-fill pulse-red me-1"></i>
                        <?php echo htmlspecialchars($m['titulo']); ?>
                    </div>
                    <span class="badge bg-<?php echo $cls; ?>"><?php echo $lbl; ?></span>
                </div>
                <div class="small text-muted"><?php echo htmlspecialchars($m['origem']); ?> → <?php echo htmlspecialchars($m['destino']); ?></div>
            </div>
            <?php endforeach; ?>

            <div class="sidebar-list">
                <?php foreach ($missoes as $m):
                    if ($m['status'] === 'emergencia') continue;
                    [$lbl,$cls] = status_info($m['status']);
                ?>
                <div class="card missao-item mb-2 p-2"
                     onclick="focarMissao(<?php echo (int)$m['id']; ?>)"
                     id="item-<?php echo (int)$m['id']; ?>">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <div class="fw-semibold small text-truncate" style="max-width:150px">
                            <?php echo htmlspecialchars($m['titulo']); ?>
                        </div>
                        <span class="badge bg-<?php echo $cls; ?> flex-shrink-0"><?php echo $lbl; ?></span>
                    </div>
                    <div class="small text-muted"><?php echo htmlspecialchars($m['nome_empresa'] ?? ''); ?></div>
                    <div class="small text-truncate text-muted"><?php echo htmlspecialchars($m['origem']); ?> → <?php echo htmlspecialchars($m['destino']); ?></div>
                    <?php if ($m['lat']): ?>
                        <div class="small text-success mt-1"><i class="bi bi-broadcast me-1"></i>GPS activo</div>
                    <?php elseif ($m['origem_lat']): ?>
                        <div class="small text-info mt-1"><i class="bi bi-signpost-split me-1"></i>Rota no mapa</div>
                    <?php else: ?>
                        <div class="small text-muted mt-1"><i class="bi bi-broadcast-pin me-1"></i>Sem localização</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <?php if (empty($missoes)): ?>
                    <div class="text-center text-muted py-4 small">
                        <i class="bi bi-truck fs-3 d-block mb-2 opacity-25"></i>
                        Nenhuma missão activa.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/mapa-trackmoz.js"></script>
<script>
const BASE_URL    = <?php echo json_encode(BASE_URL); ?>;
const MISSOES_INI = <?php echo json_encode(array_values($missoes)); ?>;
const MOTORISTAS  = <?php echo json_encode(array_values($motoristas ?? [])); ?>;

const trackMap = new TrackMozMap('map', {
    baseUrl: BASE_URL,
    onSelect: (id, selected) => {
        const el = document.getElementById('item-' + id);
        if (!el) return;
        if (selected) {
            document.querySelectorAll('.missao-item.selected').forEach((n) => n.classList.remove('selected'));
            el.classList.add('selected');
            el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else {
            el.classList.remove('selected');
        }
    },
});

trackMap.renderMissoes(MISSOES_INI, MOTORISTAS);

window.focarMissao = (id) => trackMap.selecionar(id);

async function refreshMapaCompleto() {
    const btn = document.getElementById('btnRefreshMapa');
    if (btn) btn.disabled = true;
    try {
        const data = await trackMap.reloadAll('admin');
        if (data && data.ok) {
            const comGps = data.missoes.filter((m) => m.lat && m.lng).length;
            const el = document.getElementById('statGps');
            if (el) el.textContent = comGps;
        }
    } catch (e) { /* silencioso */ }
    finally { if (btn) btn.disabled = false; }
}

document.getElementById('btnRefreshMapa')?.addEventListener('click', refreshMapaCompleto);
trackMap.iniciarPolling('admin', 8000);
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
