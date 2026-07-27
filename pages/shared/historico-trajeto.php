<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/geocode.php');
include_once('../../includes/localizacao-service.php');

$user_type = $_SESSION['user_type'] ?? '';
$allowed = ['admin', 'empresa', 'transportador', 'caminhoneiro'];
require_role($allowed, '../login.php');

$missao_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($missao_id <= 0) {
    $pasta = match ($user_type) {
        'admin' => 'admin',
        'empresa' => 'contratante',
        'transportador' => 'transportador',
        'caminhoneiro' => 'caminhoneiro',
        default => 'caminhoneiro',
    };
    header('Location: ' . BASE_URL . '/pages/' . $pasta . '/missoes.php');
    exit;
}

$stmt = $conn->prepare(
    "SELECT m.*, u.nome AS motorista_nome
     FROM missoes m LEFT JOIN usuarios u ON m.caminhoneiro_id = u.id
     WHERE m.id = :id"
);
$stmt->execute([':id' => $missao_id]);
$missao = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$missao || !tms_pode_ver_missao($conn, (int)$_SESSION['user_id'], $user_type, $missao)) {
    http_response_code(403);
    echo 'Acesso negado';
    exit;
}

garantir_locais_missao($conn, $missao_id);
$historico = tms_historico_missao($conn, $missao_id);
$checkpoints = tms_listar_checkpoints($conn, $missao_id);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Histórico do Trajeto — Missão #<?php echo $missao_id; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/tms-mapa.css">
</head>
<body class="bg-light">
<?php include_once('../../includes/menu.php'); ?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1">Histórico do Trajeto</h4>
            <p class="text-muted mb-0"><?php echo htmlspecialchars($missao['titulo']); ?> — <?php echo htmlspecialchars($missao['motorista_nome'] ?? '—'); ?></p>
        </div>
        <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm">Voltar</a>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="tm-map-container tm-historico-map">
                <div id="map-historico" style="height:100%;min-height:400px"></div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header fw-semibold">Checkpoints automáticos</div>
                <ul class="list-group list-group-flush">
                    <?php if (empty($checkpoints)): ?>
                    <li class="list-group-item text-muted small">Nenhum checkpoint registado.</li>
                    <?php else: foreach ($checkpoints as $cp): ?>
                    <li class="list-group-item small">
                        <strong><?php echo htmlspecialchars(str_replace('_', ' ', $cp['tipo'])); ?></strong><br>
                        <?php echo date('d/m/Y H:i', strtotime($cp['created_at'])); ?>
                        <?php if ($cp['distancia_m']): ?> — <?php echo round($cp['distancia_m']); ?>m<?php endif; ?>
                    </li>
                    <?php endforeach; endif; ?>
                </ul>
            </div>
            <div class="card shadow-sm mt-3">
                <div class="card-header fw-semibold">Pontos GPS (<?php echo count($historico); ?>)</div>
                <div class="card-body p-0" style="max-height:300px;overflow-y:auto">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Hora</th><th>Vel.</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice(array_reverse($historico), 0, 50) as $p): ?>
                        <tr>
                            <td class="small"><?php echo date('H:i:s', strtotime($p['ts'])); ?></td>
                            <td class="small"><?php echo $p['speed'] !== null ? round($p['speed']) . ' km/h' : '—'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/mapa-core.js"></script>
<script>
const HISTORICO = <?php echo json_encode($historico); ?>;
const MISSAO = <?php echo json_encode(enriquecer_missao_mapa($missao)); ?>;

const provider = new TrackMozMapCore.LeafletProvider('map-historico', { zoom: 10 });
const map = provider.map;

if (MISSAO.origem_lat) {
    provider.addMarker(MISSAO.origem_lat, MISSAO.origem_lng, 'origem', '<strong>Recolha</strong>');
}
if (MISSAO.destino_lat) {
    provider.addMarker(MISSAO.destino_lat, MISSAO.destino_lng, 'destino', '<strong>Entrega</strong>');
}

if (HISTORICO.length > 1) {
    const coords = HISTORICO.map(p => [p.lat, p.lng]);
    L.polyline(coords, { color: '#22c55e', weight: 4, opacity: 0.85 }).addTo(map);
    HISTORICO.forEach((p, i) => {
        if (i % Math.max(1, Math.floor(HISTORICO.length / 20)) === 0) {
            L.circleMarker([p.lat, p.lng], { radius: 4, color: '#16a34a', fillOpacity: 0.8 })
                .addTo(map).bindPopup(new Date(p.ts).toLocaleString('pt'));
        }
    });
    provider.fitBounds(coords);
}

if (MISSAO.origem_lat && MISSAO.destino_lat) {
    TrackMozMapCore.calcularRota(
        <?php echo json_encode(BASE_URL); ?>,
        MISSAO.origem_lat, MISSAO.origem_lng,
        MISSAO.destino_lat, MISSAO.destino_lng
    ).then(rota => {
        if (rota?.coordinates?.length) {
            L.polyline(rota.coordinates, { color: '#2563eb', weight: 3, opacity: 0.4, dashArray: '8,6' }).addTo(map);
        }
    });
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
