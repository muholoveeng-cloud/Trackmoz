<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/geocode.php');

require_role(['empresa'], '../login.php');

$missao_id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$empresa_id = (int)$_SESSION['user_id'];

if ($missao_id <= 0) {
    header('Location: ' . BASE_URL . '/pages/contratante/missoes.php');
    exit;
}

try {
    $stmt = $conn->prepare(
        "SELECT m.*,
                u.nome AS motorista_nome, u.telefone AS motorista_telefone,
                COALESCE(pc.ultima_localizacao_lat, pc.latitude) AS lat,
                COALESCE(pc.ultima_localizacao_lng, pc.longitude) AS lng,
                pc.ultima_atualizacao_local AS atualizado_em,
                pc.avaliacao_media, pc.total_entregas,
                lo.latitude  AS origem_lat,  lo.longitude  AS origem_lng,
                ld.latitude  AS destino_lat, ld.longitude  AS destino_lng
         FROM missoes m
         LEFT JOIN usuarios u              ON m.caminhoneiro_id = u.id
         LEFT JOIN perfil_caminhoneiro pc  ON m.caminhoneiro_id = pc.usuario_id
         LEFT JOIN locais lo ON m.local_origem_id  = lo.id
         LEFT JOIN locais ld ON m.local_destino_id = ld.id
         WHERE m.id = :mid AND m.empresa_id = :eid"
    );
    $stmt->execute([':mid' => $missao_id, ':eid' => $empresa_id]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) {
        header('Location: ' . BASE_URL . '/pages/contratante/missoes.php');
        exit;
    }

    garantir_locais_missao($conn, $missao_id);
    $stmt->execute([':mid' => $missao_id, ':eid' => $empresa_id]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);
    $missao = enriquecer_missao_mapa($missao);
} catch (PDOException $e) {
    error_log('Erro rastrear-missao: ' . $e->getMessage());
    header('Location: ' . BASE_URL . '/pages/contratante/missoes.php');
    exit;
}

$status_label = match($missao['status']) {
    'aceita'                 => ['Aceita',                 'success'],
    'em_andamento'           => ['Em Andamento',           'warning'],
    'em_transito'            => ['Em Trânsito',            'primary'],
    'em_entrega'             => ['Em Entrega',             'info'],
    'aguardando_confirmacao' => ['Ag. Confirmação',        'secondary'],
    'concluida'              => ['Concluída',              'success'],
    default                  => [ucfirst($missao['status']),'secondary'],
};
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rastrear Missão — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <style>
        #map { height: calc(100vh - 220px); min-height: 350px; border-radius: 10px; z-index: 0; }
        @media (max-width: 991.98px) { #map { height: 52vh; min-height: 280px; } }
        .info-bar { background:#fff; border-bottom:1px solid #dee2e6; padding:10px 0; }
        .pulse { display:inline-block; width:10px; height:10px; border-radius:50%;
                 background:#28a745; animation:pulse 1.4s infinite; }
        @keyframes pulse {
            0%   { box-shadow: 0 0 0 0 rgba(40,167,69,.6); }
            70%  { box-shadow: 0 0 0 8px rgba(40,167,69,0); }
            100% { box-shadow: 0 0 0 0 rgba(40,167,69,0); }
        }
        #ultimaAtualizacao { font-size:.8rem; color:#888; }
        .truck-icon { font-size:22px; line-height:1; }
        /* Botão flutuante abrir mapa nativo (só aparece no mobile) */
        #btnAbrirMapaNativo {
            position: fixed; bottom: 20px; right: 20px; z-index: 9999;
            border-radius: 50px; padding: 10px 18px;
            box-shadow: 0 4px 16px rgba(0,0,0,.25);
            display: none;
        }
        @media (max-width: 991.98px) { #btnAbrirMapaNativo { display: flex; align-items: center; gap: 6px; } }
    </style>
</head>
<body>
<?php include_once('../../includes/menu.php'); ?>

<div class="info-bar shadow-sm">
    <div class="container">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div>
                <span class="text-muted small">Missão:</span>
                <strong class="ms-1"><?php echo htmlspecialchars($missao['titulo']); ?></strong>
                <span class="badge bg-<?php echo $status_label[1]; ?> ms-2"><?php echo $status_label[0]; ?></span>
            </div>
            <div class="ms-auto d-flex align-items-center gap-3">
                <span id="conexaoStatus">
                    <span class="pulse"></span>
                    <span class="ms-1 small text-success">A rastrear</span>
                </span>
                <span id="ultimaAtualizacao"></span>
                <a href="<?php echo BASE_URL; ?>/pages/shared/historico-trajeto.php?id=<?php echo $missao_id; ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-clock-history"></i> Histórico
                </a>
                <a href="detalhes-missao.php?id=<?php echo $missao_id; ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Detalhes
                </a>
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
            <!-- Posição actual -->
            <div class="card mb-3">
                <div class="card-header fw-semibold">
                    <i class="bi bi-geo-alt-fill text-danger me-1"></i>Posição Actual
                </div>
                <div class="card-body">
                    <div id="posicaoInfo" class="text-muted small">
                        <?php if ($missao['lat'] && $missao['lng']): ?>
                            <div class="fw-bold" id="coordDisplay">
                                <?php echo number_format((float)$missao['lat'],6); ?>,
                                <?php echo number_format((float)$missao['lng'],6); ?>
                            </div>
                            <a id="linkMapaNativo"
                               href="geo:<?php echo (float)$missao['lat']; ?>,<?php echo (float)$missao['lng']; ?>?q=<?php echo (float)$missao['lat']; ?>,<?php echo (float)$missao['lng']; ?>(Motorista)"
                               class="btn btn-sm btn-outline-success mt-2 w-100 d-md-none">
                                <i class="bi bi-map me-1"></i>Abrir no Google Maps
                            </a>
                        <?php elseif (!$missao['caminhoneiro_id']): ?>
                            <i class="bi bi-info-circle me-1"></i>Nenhum motorista atribuído.
                        <?php else: ?>
                            <i class="bi bi-info-circle me-1"></i>Localização não disponível ainda.
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Motorista -->
            <?php if ($missao['motorista_nome']): ?>
            <div class="card mb-3">
                <div class="card-header fw-semibold">
                    <i class="bi bi-person-fill me-1 text-primary"></i>Motorista
                </div>
                <div class="card-body">
                    <div class="fw-semibold"><?php echo htmlspecialchars($missao['motorista_nome']); ?></div>
                    <?php if ($missao['motorista_telefone']): ?>
                        <div class="small mt-1">
                            <i class="bi bi-telephone me-1 text-muted"></i>
                            <a href="tel:<?php echo htmlspecialchars($missao['motorista_telefone']); ?>">
                                <?php echo htmlspecialchars($missao['motorista_telefone']); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    <div class="d-flex gap-3 mt-2 small text-muted">
                        <span><i class="bi bi-star-fill text-warning me-1"></i><?php echo number_format((float)($missao['avaliacao_media']??0),1); ?></span>
                        <span><i class="bi bi-truck me-1"></i><?php echo (int)($missao['total_entregas']??0); ?> entregas</span>
                    </div>
                    <a href="<?php echo BASE_URL; ?>/pages/chat.php?user=<?php echo (int)$missao['caminhoneiro_id']; ?>&missao=<?php echo $missao_id; ?>"
                       class="btn btn-outline-primary btn-sm mt-2 w-100">
                        <i class="bi bi-chat me-1"></i>Enviar Mensagem
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Rota -->
            <div class="card mb-3">
                <div class="card-header fw-semibold">
                    <i class="bi bi-map me-1 text-success"></i>Rota
                </div>
                <div class="card-body small">
                    <div class="d-flex align-items-start gap-2 mb-2">
                        <i class="bi bi-circle text-success mt-1"></i>
                        <div><div class="text-muted">Origem</div><div class="fw-semibold"><?php echo htmlspecialchars($missao['origem']); ?></div></div>
                    </div>
                    <div class="border-start ms-2 ps-2" style="border-style:dashed!important;height:14px"></div>
                    <div class="d-flex align-items-start gap-2">
                        <i class="bi bi-geo-alt-fill text-danger mt-1"></i>
                        <div><div class="text-muted">Destino</div><div class="fw-semibold"><?php echo htmlspecialchars($missao['destino']); ?></div></div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body text-center">
                    <div class="small text-muted mb-1">Distância estimada</div>
                    <div id="distanciaInfo" class="fw-bold text-primary">—</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const MISSAO_ID  = <?php echo json_encode($missao_id); ?>;
const BASE_URL   = <?php echo json_encode(BASE_URL); ?>;
let origemLat = <?php echo json_encode($missao['origem_lat']  ? (float)$missao['origem_lat']  : null); ?>;
let origemLng = <?php echo json_encode($missao['origem_lng']  ? (float)$missao['origem_lng']  : null); ?>;
let destLat   = <?php echo json_encode($missao['destino_lat'] ? (float)$missao['destino_lat'] : null); ?>;
let destLng   = <?php echo json_encode($missao['destino_lng'] ? (float)$missao['destino_lng'] : null); ?>;
const textoOrigem  = <?php echo json_encode($missao['origem'] ?? ''); ?>;
const textoDestino = <?php echo json_encode($missao['destino'] ?? ''); ?>;

const centroInicial = (origemLat && origemLng) ? [origemLat, origemLng] : [-18.0, 35.0];
const map = L.map('map').setView(centroInicial, origemLat ? 8 : 6);
setTimeout(() => map.invalidateSize(), 200);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    maxZoom: 19,
}).addTo(map);

// Marcadores de origem e destino
const iconeOrigem = L.divIcon({
    html: '<div style="background:#28a745;width:14px;height:14px;border-radius:50%;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4)"></div>',
    className: '', iconAnchor: [7, 7],
});
const iconeDestino = L.divIcon({
    html: '<div style="background:#dc3545;width:14px;height:14px;border-radius:50%;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4)"></div>',
    className: '', iconAnchor: [7, 7],
});
const iconeTruck = L.divIcon({
    html: '<div style="font-size:26px;line-height:1;filter:drop-shadow(0 1px 3px rgba(0,0,0,.5))">🚛</div>',
    className: '', iconAnchor: [13, 20],
});

let marcadorOrigem, marcadorDestino, marcadorMotorista;
let polylineRota = null, polylinePercorrida = null;
const bounds = [];

function desenharMarcadoresRota() {
    if (origemLat && origemLng && !marcadorOrigem) {
        marcadorOrigem = L.marker([origemLat, origemLng], { icon: iconeOrigem })
            .addTo(map).bindPopup('<strong>Origem</strong><br>' + textoOrigem);
        bounds.push([origemLat, origemLng]);
    }
    if (destLat && destLng && !marcadorDestino) {
        marcadorDestino = L.marker([destLat, destLng], { icon: iconeDestino })
            .addTo(map).bindPopup('<strong>Destino</strong><br>' + textoDestino);
        bounds.push([destLat, destLng]);
    }
}

function desenharRotaOsrm() {
    if (!origemLat || !destLat) return;
    const osrmUrl = `https://router.project-osrm.org/route/v1/driving/${origemLng},${origemLat};${destLng},${destLat}?overview=full&geometries=geojson`;
    fetch(osrmUrl)
        .then(r => r.json())
        .then(data => {
            if (data.routes && data.routes[0]) {
                const coords = data.routes[0].geometry.coordinates.map(c => [c[1], c[0]]);
                if (polylineRota) map.removeLayer(polylineRota);
                polylineRota = L.polyline(coords, {
                    color: '#0d6efd', weight: 4, opacity: 0.6, dashArray: '8,6'
                }).addTo(map);
                const dist = (data.routes[0].distance / 1000).toFixed(0);
                const mins = Math.round(data.routes[0].duration / 60);
                const h = Math.floor(mins / 60), m = mins % 60;
                const info = document.getElementById('distanciaInfo');
                if (info) info.textContent = dist + ' km · ' + (h > 0 ? h + 'h ' : '') + m + 'min';
            }
        })
        .catch(() => {
            L.polyline([[origemLat, origemLng], [destLat, destLng]], {
                color: '#0d6efd', weight: 3, opacity: 0.5, dashArray: '6,5'
            }).addTo(map);
        });
}

desenharMarcadoresRota();
desenharRotaOsrm();
if (bounds.length) map.fitBounds(bounds, { padding: [40, 40] });

async function geocodificarSeNecessario() {
    if (!origemLat && textoOrigem) {
        const d = await fetch(BASE_URL + '/api/geocode.php?q=' + encodeURIComponent(textoOrigem)).then(r => r.json());
        if (d.ok) { origemLat = d.lat; origemLng = d.lng; }
    }
    if (!destLat && textoDestino) {
        const d = await fetch(BASE_URL + '/api/geocode.php?q=' + encodeURIComponent(textoDestino)).then(r => r.json());
        if (d.ok) { destLat = d.lat; destLng = d.lng; }
    }
    desenharMarcadoresRota();
    desenharRotaOsrm();
    if (bounds.length) map.fitBounds(bounds, { padding: [40, 40] });
}
geocodificarSeNecessario();

// ── Polling de localização ──────────────────────────────────────────
async function buscarLocalizacao(comHistorico) {
    try {
        const url = BASE_URL + '/api/get-localizacao.php?missao_id=' + MISSAO_ID
            + (comHistorico ? '&historico=1' : '');
        const res  = await fetch(url);
        const data = await res.json();
        if (!data.ok) return;

        if (data.localizacao) {
            const { lat, lng, atualizado_em } = data.localizacao;
            if (!marcadorMotorista) {
                marcadorMotorista = L.marker([lat, lng], { icon: iconeTruck, zIndexOffset: 500 })
                    .addTo(map)
                    .bindPopup(`<strong>${data.motorista?.nome ?? 'Motorista'}</strong><br>Em movimento`);
            } else {
                marcadorMotorista.setLatLng([lat, lng]);
            }
            const cd = document.getElementById('coordDisplay');
            if (cd) cd.textContent = lat.toFixed(6) + ', ' + lng.toFixed(6);
            if (atualizado_em) {
                const d = new Date(atualizado_em);
                document.getElementById('ultimaAtualizacao').textContent =
                    'Últ. actualização: ' + d.toLocaleTimeString('pt');
            }
            document.getElementById('conexaoStatus').innerHTML =
                '<span class="pulse"></span><span class="ms-1 small text-success">A rastrear</span>';
        }

        if (comHistorico && data.pontos_rota && data.pontos_rota.length > 1) {
            if (polylinePercorrida) map.removeLayer(polylinePercorrida);
            polylinePercorrida = L.polyline(
                data.pontos_rota.map(p => [p.lat, p.lng]),
                { color: '#28a745', weight: 4, opacity: 0.85 }
            ).addTo(map);
        }

    } catch (e) {
        document.getElementById('conexaoStatus').innerHTML =
            '<i class="bi bi-circle-fill text-warning" style="font-size:.5rem"></i>' +
            '<span class="ms-1 small text-warning">Reconectando...</span>';
    }
}

// ── Botão flutuante: abrir no mapa nativo (mobile) ──────────────────
function atualizarLinkMapaNativo(lat, lng) {
    const btnFloat = document.getElementById('btnAbrirMapaNativo');
    const linkSide = document.getElementById('linkMapaNativo');
    const label    = encodeURIComponent('Motorista');
    const url      = `https://maps.google.com/maps?q=${lat},${lng}&z=16`;
    const geoUrl   = `geo:${lat},${lng}?q=${lat},${lng}(${label})`;

    if (btnFloat) { btnFloat.href = geoUrl; btnFloat.style.display = ''; }
    if (linkSide) { linkSide.href = geoUrl; }
}

// Atualizar o link após cada polling
const _origBuscar = buscarLocalizacao;
buscarLocalizacao = async function(comHistorico) {
    await _origBuscar(comHistorico);
    // Tenta ler coordenadas actuais do marcador
    if (marcadorMotorista) {
        const ll = marcadorMotorista.getLatLng();
        atualizarLinkMapaNativo(ll.lat.toFixed(6), ll.lng.toFixed(6));
    }
};

buscarLocalizacao(true);
setInterval(() => buscarLocalizacao(false), 6000);
</script>

<!-- Botão flutuante (mobile) para abrir GPS nativo -->
<a id="btnAbrirMapaNativo" href="#" class="btn btn-success" style="display:none">
    <i class="bi bi-map-fill"></i> Abrir Mapa
</a>

</body>
</html>
