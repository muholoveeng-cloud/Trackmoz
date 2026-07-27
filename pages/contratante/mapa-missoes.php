<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/geocode.php');

require_role(['empresa'], '../login.php');

$empresa_id = (int)$_SESSION['user_id'];

try {
    $stmt = $conn->prepare(
        "SELECT m.id, m.titulo, m.status, m.origem, m.destino,
                m.caminhoneiro_id,
                u.nome     AS motorista_nome,
                u.telefone AS motorista_telefone,
                COALESCE(pc.ultima_localizacao_lat, pc.latitude) AS lat,
                COALESCE(pc.ultima_localizacao_lng, pc.longitude) AS lng,
                pc.ultima_atualizacao_local AS atualizado_em,
                lo.latitude  AS origem_lat,  lo.longitude AS origem_lng,
                ld.latitude  AS destino_lat, ld.longitude AS destino_lng
         FROM missoes m
         LEFT JOIN usuarios u              ON m.caminhoneiro_id = u.id
         LEFT JOIN perfil_caminhoneiro pc  ON m.caminhoneiro_id = pc.usuario_id
         LEFT JOIN locais lo ON m.local_origem_id  = lo.id
         LEFT JOIN locais ld ON m.local_destino_id = ld.id
         WHERE m.empresa_id = :eid
           AND m.status IN ('aberta','aceita','em_andamento','em_transito','em_entrega','aguardando_confirmacao','emergencia')
         ORDER BY m.data_criacao DESC"
    );
    $stmt->execute([':eid' => $empresa_id]);
    $missoes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $missoes[] = enriquecer_missao_mapa($row);
    }

    $st = $conn->prepare(
        "SELECT
            SUM(CASE WHEN status IN ('aceita','em_andamento','em_transito','em_entrega','aguardando_confirmacao') THEN 1 ELSE 0 END) AS ativas,
            SUM(CASE WHEN status = 'emergencia' THEN 1 ELSE 0 END) AS emergencias,
            SUM(CASE WHEN status = 'concluida'  THEN 1 ELSE 0 END) AS concluidas,
            SUM(CASE WHEN status = 'aberta'     THEN 1 ELSE 0 END) AS abertas
         FROM missoes WHERE empresa_id = :eid"
    );
    $st->execute([':eid' => $empresa_id]);
    $stats = $st->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('mapa-missoes: ' . $e->getMessage());
    $missoes = [];
    $motoristas = [];
    $stats   = ['ativas' => 0, 'emergencias' => 0, 'concluidas' => 0, 'abertas' => 0];
}

function status_info(string $s): array {
    return match($s) {
        'aceita'                 => ['Aceita',           'success'],
        'em_andamento'           => ['Em Andamento',     'warning'],
        'em_transito'            => ['Em Trânsito',      'primary'],
        'em_entrega'             => ['Em Entrega',       'info'],
        'aguardando_confirmacao' => ['Ag. Confirmação',  'secondary'],
        'emergencia'             => ['EMERGÊNCIA',       'danger'],
        default                  => [ucfirst($s),        'secondary'],
    };
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mapa das Minhas Missões — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <style>
        #map { height: calc(100vh - 200px); min-height: 350px; border-radius: 10px; z-index: 0; }
        @media (max-width: 991.98px) { #map { height: 50vh; min-height: 260px; } }
        .sidebar-list { max-height: calc(100vh - 280px); overflow-y: auto; }
        .missao-item { border-left: 3px solid transparent; cursor: pointer; transition: all .15s; }
        .missao-item:hover { background: #f0f4ff; border-left-color: #0d6efd; }
        .missao-item.selected { background: #e8f0fe; border-left-color: #0d6efd; }
        .missao-item.emergencia { border-left-color: #dc3545; background: #fff5f5; }
        .stat-num { font-size: 1.5rem; font-weight: 700; line-height: 1; }
        /* Botão flutuante mobile */
        #btnAbrirMapaNativo {
            position: fixed; bottom: 20px; right: 20px; z-index: 9999;
            border-radius: 50px; padding: 10px 18px;
            box-shadow: 0 4px 16px rgba(0,0,0,.25);
            display: none;
        }
        @media (max-width: 991.98px) { #btnAbrirMapaNativo { display: flex; align-items: center; gap: 6px; } }
        .pulse-red { animation: pulseRed 1s infinite; }
        @keyframes pulseRed { 0%,100%{opacity:1} 50%{opacity:.35} }
    </style>
</head>
<body>
<?php include_once('../../includes/menu.php'); ?>

<div class="bg-white border-bottom py-2 shadow-sm">
    <div class="container-fluid px-4">
        <div class="row g-0 text-center">
            <div class="col-3">
                <div class="stat-num text-primary"><?php echo (int)$stats['ativas']; ?></div>
                <div class="small text-muted">Em curso</div>
            </div>
            <div class="col-3">
                <div class="stat-num text-warning"><?php echo (int)$stats['abertas']; ?></div>
                <div class="small text-muted">Abertas</div>
            </div>
            <div class="col-3">
                <div class="stat-num text-success"><?php echo (int)$stats['concluidas']; ?></div>
                <div class="small text-muted">Concluídas</div>
            </div>
            <div class="col-3">
                <?php if ($stats['emergencias'] > 0): ?>
                    <div class="stat-num text-danger pulse-red"><?php echo (int)$stats['emergencias']; ?></div>
                    <div class="small text-danger">Emergência!</div>
                <?php else: ?>
                    <div class="stat-num text-success"><i class="bi bi-check-circle"></i></div>
                    <div class="small text-muted">Sem alertas</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="container-fluid px-3 py-3">
    <div class="row g-3">
        <div class="col-lg-9">
            <?php if (empty($missoes)): ?>
                <div class="card text-center py-5">
                    <div class="card-body">
                        <i class="bi bi-map" style="font-size:3rem;color:#ccc"></i>
                        <h5 class="mt-3 text-muted">Nenhuma missão activa</h5>
                        <p class="text-muted">Quando as suas missões estiverem em curso, aparecerão aqui.</p>
                        <a href="nova-missao.php" class="btn btn-primary mt-2">
                            <i class="bi bi-plus-circle me-1"></i>Publicar Missão
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div id="map"></div>
            <?php endif; ?>
        </div>

        <div class="col-lg-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-truck me-1 text-primary"></i>
                    Missões Activas (<?php echo count($missoes); ?>)
                </h6>
                <button class="btn btn-sm btn-outline-secondary" onclick="location.reload()">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </div>

            <?php if (empty($missoes)): ?>
                <div class="text-center text-muted py-3 small">Nenhuma missão em andamento.</div>
            <?php else: ?>
                <!-- Emergências primeiro -->
                <?php foreach ($missoes as $m):
                    if ($m['status'] !== 'emergencia') continue;
                    [$lbl, $cls] = status_info($m['status']);
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
                    <div class="small text-muted mb-2"><?php echo htmlspecialchars($m['origem']); ?> → <?php echo htmlspecialchars($m['destino']); ?></div>
                    <a href="rastrear-missao.php?id=<?php echo (int)$m['id']; ?>"
                       class="btn btn-danger btn-sm w-100" onclick="event.stopPropagation()">
                        <i class="bi bi-geo-alt-fill me-1"></i>Rastrear
                    </a>
                </div>
                <?php endforeach; ?>

                <div class="sidebar-list">
                    <?php foreach ($missoes as $m):
                        if ($m['status'] === 'emergencia') continue;
                        [$lbl, $cls] = status_info($m['status']);
                        $diff = $m['atualizado_em'] ? time() - strtotime($m['atualizado_em']) : null;
                        $tempo = $diff === null ? null : ($diff < 60 ? 'agora' : round($diff/60) . 'min atrás');
                    ?>
                    <div class="card missao-item mb-2 p-2"
                         onclick="focarMissao(<?php echo (int)$m['id']; ?>)"
                         id="item-<?php echo (int)$m['id']; ?>">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <div class="fw-semibold small text-truncate" style="max-width:155px">
                                <?php echo htmlspecialchars($m['titulo']); ?>
                            </div>
                            <span class="badge bg-<?php echo $cls; ?> flex-shrink-0"><?php echo $lbl; ?></span>
                        </div>
                        <div class="small text-truncate text-muted mb-1">
                            <?php echo htmlspecialchars($m['origem']); ?> → <?php echo htmlspecialchars($m['destino']); ?>
                        </div>
                        <?php if ($m['motorista_nome']): ?>
                            <div class="small text-muted"><i class="bi bi-person me-1"></i><?php echo htmlspecialchars($m['motorista_nome']); ?></div>
                        <?php endif; ?>
                        <?php if ($m['lat']): ?>
                            <div class="small text-success mt-1">
                                <i class="bi bi-broadcast me-1"></i>GPS activo
                                <?php if ($tempo): ?><span class="text-muted">· <?php echo $tempo; ?></span><?php endif; ?>
                            </div>
                        <?php elseif ($m['origem_lat']): ?>
                            <div class="small text-info mt-1"><i class="bi bi-signpost-split me-1"></i>Rota no mapa</div>
                        <?php else: ?>
                            <div class="small text-muted mt-1"><i class="bi bi-broadcast-pin me-1"></i>Sem localização</div>
                        <?php endif; ?>
                        <a href="rastrear-missao.php?id=<?php echo (int)$m['id']; ?>"
                           class="btn btn-outline-primary btn-sm mt-2 w-100" onclick="event.stopPropagation()">
                            <i class="bi bi-geo-alt-fill me-1"></i>Rastrear
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($missoes)): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/mapa-core.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/mapa-trackmoz.js"></script>
<script>
const BASE_URL = <?php echo json_encode(BASE_URL); ?>;
const MISSOES  = <?php echo json_encode(array_values($missoes)); ?>;

const trackMap = new TrackMozMap('map', {
    baseUrl: BASE_URL,
    rastrearUrl: BASE_URL + '/pages/contratante/rastrear-missao.php',
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

trackMap.renderMissoes(MISSOES, []);
window.focarMissao = (id) => {
    trackMap.selecionar(id);
    const m = trackMap.missoes.find((x) => x.id == id);
    if (m && m.lat && m.lng) {
        atualizarBotaoMapaMobile(m.lat, m.lng, m.titulo);
    }
};

function atualizarBotaoMapaMobile(lat, lng, titulo) {
    const btn = document.getElementById('btnAbrirMapaNativo');
    if (!btn) return;
    const label = encodeURIComponent(titulo || 'Motorista');
    btn.href = `geo:${lat},${lng}?q=${lat},${lng}(${label})`;
    btn.style.display = '';
}

trackMap.iniciarPolling('empresa', 8000);
</script>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Botão flutuante (mobile) -->
<a id="btnAbrirMapaNativo" href="#" class="btn btn-success" style="display:none">
    <i class="bi bi-map-fill"></i> Abrir Mapa
</a>

</body>
</html>
