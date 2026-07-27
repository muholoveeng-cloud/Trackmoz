<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');

require_role(['admin', 'transportador', 'empresa'], '../login.php');

$userType = $_SESSION['user_type'] ?? '';
$scope = match ($userType) {
    'admin' => 'operacoes',
    'transportador' => 'transportador',
    'empresa' => 'empresa',
    default => 'operacoes',
};

$titulo = match ($scope) {
    'operacoes' => 'Centro de Operações',
    'transportador' => 'Mapa da Frota',
    default => 'Mapa das Encomendas',
};

$homeUrl = match ($userType) {
    'admin' => BASE_URL . '/index.php',
    'transportador' => BASE_URL . '/pages/transportador/dashboard.php',
    'empresa' => BASE_URL . '/pages/contratante/dashboard.php',
    default => BASE_URL . '/index.php',
};

$detalheMissaoBase = match ($userType) {
    'admin' => BASE_URL . '/pages/admin/ver-missao.php?id=',
    'empresa' => BASE_URL . '/pages/contratante/detalhes-missao.php?id=',
    'transportador' => BASE_URL . '/pages/transportador/detalhes-missao.php?id=',
    default => BASE_URL . '/pages/admin/ver-missao.php?id=',
};
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($titulo); ?> — TrackMoz</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --ops-bg: #070b14;
            --ops-panel: rgba(10, 18, 32, 0.92);
            --ops-panel-solid: #0c1524;
            --ops-border: rgba(56, 189, 248, 0.22);
            --ops-cyan: #22d3ee;
            --ops-blue: #3b82f6;
            --ops-green: #34d399;
            --ops-amber: #fbbf24;
            --ops-orange: #fb923c;
            --ops-red: #f87171;
            --ops-muted: #94a3b8;
            --ops-text: #e2e8f0;
            --ops-glow: 0 0 24px rgba(34, 211, 238, 0.25);
            --ops-sidebar: 380px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            height: 100%; overflow: hidden;
            background: var(--ops-bg);
            color: var(--ops-text);
            font-family: 'IBM Plex Sans', system-ui, sans-serif;
        }

        .ops-shell {
            display: grid;
            grid-template-columns: 1fr var(--ops-sidebar);
            height: 100vh;
        }
        @media (max-width: 900px) {
            .ops-shell { grid-template-rows: 1fr 42vh; grid-template-columns: 1fr; }
            :root { --ops-sidebar: 100%; }
        }

        .ops-map-wrap { position: relative; min-height: 0; }
        #map-ops {
            width: 100%; height: 100%;
            background: #0a1220;
        }

        /* Dark map tiles overlay */
        .leaflet-container { background: #0a1220; font-family: inherit; }
        .leaflet-control-zoom a {
            background: var(--ops-panel-solid) !important;
            color: var(--ops-cyan) !important;
            border-color: var(--ops-border) !important;
        }
        .leaflet-popup-content-wrapper {
            background: var(--ops-panel-solid);
            color: var(--ops-text);
            border: 1px solid var(--ops-border);
            border-radius: 12px;
            box-shadow: var(--ops-glow);
        }
        .leaflet-popup-tip { background: var(--ops-panel-solid); }
        .leaflet-popup-content { margin: 12px 14px; font-size: .85rem; line-height: 1.45; }
        .leaflet-popup-content strong { color: #fff; }
        .ops-pop-badge {
            display: inline-block; padding: 2px 8px; border-radius: 99px;
            font-size: .68rem; font-weight: 700; margin: 4px 0; letter-spacing: .04em;
        }

        .ops-hud {
            position: absolute; inset: 0; pointer-events: none; z-index: 500;
        }
        .ops-hud > * { pointer-events: auto; }

        .ops-topbar {
            position: absolute; top: 14px; left: 14px; right: 14px;
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
        }
        .ops-brand {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 16px;
            background: var(--ops-panel);
            border: 1px solid var(--ops-border);
            border-radius: 14px;
            backdrop-filter: blur(14px);
            box-shadow: var(--ops-glow);
        }
        .ops-brand h1 {
            font-family: 'Orbitron', sans-serif;
            font-size: .92rem; font-weight: 700; letter-spacing: .06em;
            color: #fff; margin: 0;
        }
        .ops-brand small { display: block; color: var(--ops-muted); font-size: .68rem; margin-top: 2px; font-family: 'IBM Plex Sans', sans-serif; letter-spacing: 0; }
        .ops-live {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: .68rem; font-weight: 700; color: var(--ops-green);
            text-transform: uppercase; letter-spacing: .08em;
        }
        .ops-live .pulse {
            width: 8px; height: 8px; border-radius: 50%; background: var(--ops-green);
            box-shadow: 0 0 0 0 rgba(52, 211, 153, .7);
            animation: opsPulse 1.6s infinite;
        }
        @keyframes opsPulse {
            0% { box-shadow: 0 0 0 0 rgba(52,211,153,.55); }
            70% { box-shadow: 0 0 0 10px rgba(52,211,153,0); }
            100% { box-shadow: 0 0 0 0 rgba(52,211,153,0); }
        }

        .ops-kpis {
            display: flex; gap: 8px; flex-wrap: wrap; margin-left: auto;
        }
        .ops-kpi {
            min-width: 78px;
            padding: 8px 12px;
            background: var(--ops-panel);
            border: 1px solid var(--ops-border);
            border-radius: 12px;
            backdrop-filter: blur(12px);
            text-align: center;
        }
        .ops-kpi b {
            display: block; font-family: 'Orbitron', sans-serif;
            font-size: 1.15rem; color: #fff; line-height: 1.1;
        }
        .ops-kpi span { font-size: .62rem; color: var(--ops-muted); text-transform: uppercase; letter-spacing: .05em; }
        .ops-kpi.warn b { color: var(--ops-amber); }
        .ops-kpi.danger b { color: var(--ops-red); }
        .ops-kpi.ok b { color: var(--ops-green); }

        .ops-banner {
            display: none; position: absolute; top: 86px; left: 50%; transform: translateX(-50%);
            max-width: min(560px, 92vw); z-index: 600;
            padding: 12px 40px 12px 16px; border-radius: 12px;
            font-size: .85rem; font-weight: 600; cursor: pointer;
            border: 1px solid var(--ops-border); backdrop-filter: blur(12px);
            box-shadow: var(--ops-glow);
        }
        .ops-banner.show { display: block; animation: opsSlide .35s ease; }
        .ops-banner.nivel-danger { background: rgba(185,28,28,.92); color: #fff; border-color: #f87171; }
        .ops-banner.nivel-warn { background: rgba(146,64,14,.92); color: #fff; border-color: #fbbf24; }
        .ops-banner-x {
            position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
            background: transparent; border: none; color: inherit; font-size: 1.3rem; cursor: pointer;
        }
        @keyframes opsSlide {
            from { opacity: 0; transform: translate(-50%, -8px); }
            to { opacity: 1; transform: translate(-50%, 0); }
        }

        .ops-feed-hud {
            position: absolute; top: 86px; left: 14px; max-width: 280px;
            display: flex; flex-direction: column; gap: 6px; z-index: 520;
        }
        .ops-feed-chip {
            text-align: left; padding: 8px 10px; border-radius: 10px;
            border: 1px solid var(--ops-border); background: var(--ops-panel);
            color: var(--ops-text); font-size: .72rem; cursor: pointer;
            backdrop-filter: blur(10px); line-height: 1.35;
        }
        .ops-feed-chip.nivel-danger { border-color: rgba(248,113,113,.5); }
        .ops-feed-chip.nivel-warn { border-color: rgba(251,191,36,.45); }
        .ops-feed-chip strong { display: block; color: #fff; margin-bottom: 2px; }

        .ops-toast {
            position: absolute; bottom: 70px; left: 50%; transform: translateX(-50%);
            padding: 10px 16px; border-radius: 10px; z-index: 700;
            background: rgba(15,23,42,.95); border: 1px solid var(--ops-cyan);
            color: #fff; font-size: .8rem; opacity: 0; pointer-events: none;
            transition: opacity .25s; max-width: 90vw; text-align: center;
        }
        .ops-toast.show { opacity: 1; }

        .ops-resumo {
            position: absolute; bottom: 18px; right: 14px; left: auto;
            max-width: min(420px, calc(100% - 200px));
            padding: 8px 12px; border-radius: 10px;
            background: var(--ops-panel); border: 1px solid var(--ops-border);
            font-size: .68rem; color: var(--ops-muted); backdrop-filter: blur(10px);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        @media (max-width: 900px) {
            .ops-resumo { display: none; }
            .ops-feed-hud { display: none; }
        }

        body.ops-wall .ops-side { display: none; }
        body.ops-wall .ops-shell { grid-template-columns: 1fr; }
        body.ops-wall .ops-actions .ops-btn:not(.wall-keep) { opacity: .85; }
        body.ops-wall .ops-resumo { max-width: 60vw; left: 14px; right: auto; bottom: 18px; }

        .ops-empty-list { padding: 20px; color: #94a3b8; font-size: .85rem; text-align: center; }
        .ops-mini {
            display: inline-block; font-size: .58rem; font-weight: 800; letter-spacing: .04em;
            padding: 1px 5px; border-radius: 4px; margin-right: 3px;
            background: rgba(148,163,184,.2); color: #cbd5e1;
        }
        .ops-mini.danger { background: rgba(248,113,113,.25); color: #fca5a5; }
        .ops-mini.warn { background: rgba(251,191,36,.2); color: #fcd34d; }
        .ops-mini.ok { background: rgba(52,211,153,.2); color: #6ee7b7; }
        .ops-cand {
            margin-bottom: 10px; padding: 8px 10px; border-radius: 8px;
            border: 1px solid var(--ops-border); background: rgba(34,211,238,.06);
        }
        .ops-cand-title { font-size: .7rem; font-weight: 700; color: var(--ops-cyan); margin-bottom: 6px; text-transform: uppercase; }
        .ops-cand-row {
            display: flex; justify-content: space-between; gap: 8px;
            font-size: .75rem; padding: 4px 0; border-top: 1px solid rgba(56,189,248,.1);
        }
        .ops-link { color: var(--ops-cyan); text-decoration: none; margin-left: 8px; font-size: .72rem; }
        .text-danger { color: var(--ops-red) !important; }
        .text-warn { color: var(--ops-amber) !important; }
        .ops-feed-item.nivel-danger { box-shadow: inset 3px 0 0 var(--ops-red); }
        .ops-feed-item.nivel-warn { box-shadow: inset 3px 0 0 var(--ops-amber); }
        .ops-marker.blink { animation: opsBlink 1s infinite; }
        @keyframes opsBlink {
            0%, 100% { box-shadow: 0 0 16px rgba(248,113,113,.6); }
            50% { box-shadow: 0 0 28px rgba(248,113,113,.95); }
        }

        .ops-actions {
            position: absolute; bottom: 18px; left: 14px;
            display: flex; gap: 8px; flex-wrap: wrap;
        }
        .ops-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 14px;
            border-radius: 12px;
            border: 1px solid var(--ops-border);
            background: var(--ops-panel);
            color: var(--ops-text);
            font-size: .8rem; font-weight: 600;
            cursor: pointer; text-decoration: none;
            backdrop-filter: blur(12px);
            transition: border-color .2s, transform .15s, box-shadow .2s;
        }
        .ops-btn:hover {
            border-color: var(--ops-cyan); color: #fff;
            box-shadow: var(--ops-glow); transform: translateY(-1px);
        }
        .ops-btn.primary {
            background: linear-gradient(135deg, rgba(34,211,238,.25), rgba(59,130,246,.2));
            border-color: rgba(34,211,238,.45); color: #fff;
        }

        .ops-empty {
            position: absolute; inset: 0; display: none;
            align-items: center; justify-content: center;
            background: rgba(7,11,20,.55); z-index: 400;
            text-align: center; padding: 24px;
        }
        .ops-empty.show { display: flex; }
        .ops-empty-card {
            max-width: 360px;
            padding: 28px 24px;
            background: var(--ops-panel);
            border: 1px solid var(--ops-border);
            border-radius: 18px;
            box-shadow: var(--ops-glow);
        }
        .ops-empty-card i { font-size: 2rem; color: var(--ops-cyan); }
        .ops-empty-card h3 { margin: 12px 0 8px; font-family: 'Orbitron', sans-serif; font-size: .95rem; }
        .ops-empty-card p { color: var(--ops-muted); font-size: .85rem; }

        /* Sidebar */
        .ops-side {
            background: var(--ops-panel-solid);
            border-left: 1px solid var(--ops-border);
            display: flex; flex-direction: column;
            min-height: 0;
            box-shadow: -8px 0 40px rgba(0,0,0,.35);
        }
        @media (max-width: 900px) {
            .ops-side { border-left: none; border-top: 1px solid var(--ops-border); }
        }

        .ops-side-head {
            padding: 14px 16px 10px;
            border-bottom: 1px solid var(--ops-border);
        }
        .ops-side-head .row1 {
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            margin-bottom: 10px;
        }
        .ops-side-head h2 {
            font-family: 'Orbitron', sans-serif;
            font-size: .78rem; letter-spacing: .08em; color: var(--ops-cyan);
        }
        #ops-clock { font-size: .72rem; color: var(--ops-muted); font-variant-numeric: tabular-nums; }

        .ops-search {
            width: 100%;
            padding: 10px 12px 10px 36px;
            border-radius: 10px;
            border: 1px solid var(--ops-border);
            background: rgba(15, 23, 42, .8) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2394a3b8' viewBox='0 0 16 16'%3E%3Cpath d='M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85zm-5.242.656a5 5 0 1 1 0-10 5 5 0 0 1 0 10z'/%3E%3C/svg%3E") 12px center no-repeat;
            color: var(--ops-text);
            font-size: .85rem; outline: none;
        }
        .ops-search:focus { border-color: var(--ops-cyan); box-shadow: 0 0 0 2px rgba(34,211,238,.15); }

        .ops-filters {
            display: flex; gap: 6px; flex-wrap: wrap;
            padding: 10px 16px;
            border-bottom: 1px solid var(--ops-border);
        }
        .ops-filter {
            padding: 5px 10px; border-radius: 99px;
            border: 1px solid var(--ops-border);
            background: transparent; color: var(--ops-muted);
            font-size: .7rem; font-weight: 600; cursor: pointer;
            text-transform: uppercase; letter-spacing: .04em;
        }
        .ops-filter.active {
            color: #041016; background: var(--ops-cyan); border-color: var(--ops-cyan);
            box-shadow: 0 0 12px rgba(34,211,238,.35);
        }

        .ops-tabs {
            display: flex; border-bottom: 1px solid var(--ops-border);
        }
        .ops-tab {
            flex: 1; padding: 10px; background: transparent; border: none;
            color: var(--ops-muted); font-size: .75rem; font-weight: 700;
            cursor: pointer; border-bottom: 2px solid transparent;
            text-transform: uppercase; letter-spacing: .05em;
        }
        .ops-tab.active { color: var(--ops-cyan); border-bottom-color: var(--ops-cyan); }

        .ops-scroll {
            flex: 1; overflow-y: auto; min-height: 0;
            scrollbar-width: thin; scrollbar-color: var(--ops-border) transparent;
        }

        .ops-item {
            display: block; width: 100%; text-align: left;
            padding: 12px 16px;
            border: none; border-bottom: 1px solid rgba(56,189,248,.08);
            background: transparent; color: inherit; cursor: pointer;
            transition: background .15s;
        }
        .ops-item:hover, .ops-item.active {
            background: rgba(34, 211, 238, .08);
        }
        .ops-item.active { box-shadow: inset 3px 0 0 var(--ops-cyan); }
        .ops-item-title {
            display: flex; justify-content: space-between; gap: 8px; align-items: flex-start;
            font-weight: 600; font-size: .86rem; color: #fff; margin-bottom: 4px;
        }
        .ops-item-meta { font-size: .75rem; color: var(--ops-muted); line-height: 1.35; }
        .ops-pill {
            flex-shrink: 0; padding: 2px 8px; border-radius: 99px;
            font-size: .65rem; font-weight: 700; text-transform: uppercase;
        }
        .pill-em_transito { background: rgba(52,211,153,.15); color: var(--ops-green); }
        .pill-em_recolha { background: rgba(59,130,246,.15); color: #60a5fa; }
        .pill-parado { background: rgba(251,146,60,.15); color: var(--ops-orange); }
        .pill-emergencia { background: rgba(248,113,113,.2); color: var(--ops-red); }
        .pill-offline { background: rgba(148,163,184,.15); color: var(--ops-muted); }

        .ops-alert {
            margin: 10px 12px; padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid rgba(248,113,113,.4);
            background: rgba(248,113,113,.1);
            font-size: .78rem;
        }
        .ops-alert strong { color: var(--ops-red); }

        .ops-detail {
            display: none;
            border-top: 1px solid var(--ops-border);
            padding: 14px 16px;
            background: rgba(0,0,0,.25);
        }
        .ops-detail.show { display: block; }
        .ops-detail h3 { font-size: .9rem; color: #fff; margin-bottom: 8px; }
        .ops-detail dl {
            display: grid; grid-template-columns: 90px 1fr; gap: 6px 8px;
            font-size: .78rem; margin-bottom: 12px;
        }
        .ops-detail dt { color: var(--ops-muted); }
        .ops-detail dd { color: var(--ops-text); margin: 0; }
        .ops-detail-actions { display: flex; gap: 8px; flex-wrap: wrap; }

        .ops-marker {
            width: 40px; height: 40px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            border: 2px solid #fff; box-shadow: 0 0 16px rgba(0,0,0,.45);
            font-size: 18px; position: relative;
        }
        .ops-marker::after {
            content: ''; position: absolute; inset: -4px; border-radius: 50%;
            border: 1px solid currentColor; opacity: .45;
            animation: opsRing 2s infinite;
        }
        @keyframes opsRing {
            0% { transform: scale(.85); opacity: .5; }
            100% { transform: scale(1.35); opacity: 0; }
        }
        .ops-marker.em_transito { background: #059669; color: #34d399; }
        .ops-marker.em_recolha { background: #1d4ed8; color: #60a5fa; }
        .ops-marker.parado { background: #c2410c; color: #fb923c; }
        .ops-marker.emergencia { background: #b91c1c; color: #f87171; }
        .ops-marker.offline { background: #475569; color: #94a3b8; }
        .ops-marker.origem { background: #047857; width: 18px; height: 18px; font-size: 0; }
        .ops-marker.destino { background: #be123c; width: 18px; height: 18px; font-size: 0; }
        .ops-marker.origem::after, .ops-marker.destino::after { display: none; }

        .ops-legend {
            display: flex; flex-wrap: wrap; gap: 8px 12px;
            padding: 10px 16px; font-size: .68rem; color: var(--ops-muted);
            border-top: 1px solid var(--ops-border);
        }
        .ops-legend i { font-size: .55rem; margin-right: 4px; vertical-align: middle; }
    </style>
</head>
<body>
<div class="ops-shell">
    <div class="ops-map-wrap">
        <div id="map-ops"></div>
        <div class="ops-hud">
            <div class="ops-topbar">
                <div class="ops-brand">
                    <div>
                        <h1><i class="bi bi-radar"></i> <?php echo htmlspecialchars($titulo); ?></h1>
                        <small>TrackMoz · Moçambique · tempo real</small>
                    </div>
                    <div class="ops-live"><span class="pulse"></span> LIVE</div>
                </div>
                <div class="ops-kpis" id="ops-kpis">
                    <div class="ops-kpi"><b id="kpi-total">—</b><span>Activas</span></div>
                    <div class="ops-kpi ok"><b id="kpi-gps">—</b><span>Com GPS</span></div>
                    <div class="ops-kpi warn"><b id="kpi-offline">—</b><span>Offline</span></div>
                    <div class="ops-kpi warn"><b id="kpi-risco">—</b><span>Risco</span></div>
                    <div class="ops-kpi danger"><b id="kpi-emerg">—</b><span>Alertas</span></div>
                </div>
            </div>
            <div class="ops-banner" id="ops-banner" role="alert"></div>
            <div class="ops-feed-hud" id="ops-feed"></div>
            <div class="ops-toast" id="ops-toast"></div>
            <div class="ops-actions">
                <button type="button" class="ops-btn wall-keep" id="btn-voltar" title="Fechar ou voltar ao painel"><i class="bi bi-arrow-left"></i> Voltar</button>
                <button type="button" class="ops-btn wall-keep" id="btn-fit"><i class="bi bi-fullscreen"></i> Enquadrar</button>
                <button type="button" class="ops-btn primary wall-keep" id="btn-refresh"><i class="bi bi-arrow-clockwise"></i> Actualizar</button>
                <button type="button" class="ops-btn wall-keep" id="btn-sound" title="Som de alertas"><i class="bi bi-volume-up"></i> Som</button>
                <button type="button" class="ops-btn wall-keep" id="btn-wall" title="Modo ecrã / parede"><i class="bi bi-tv"></i> Parede</button>
            </div>
            <div class="ops-resumo" id="ops-resumo" title="Resumo operacional"></div>
            <div class="ops-empty" id="ops-empty">
                <div class="ops-empty-card">
                    <i class="bi bi-geo-alt"></i>
                    <h3>Sem posições no mapa</h3>
                    <p>Há missões activas, mas ainda sem coordenadas GPS. Assim que os motoristas enviarem localização, aparecem aqui.</p>
                </div>
            </div>
        </div>
    </div>

    <aside class="ops-side">
        <div class="ops-side-head">
            <div class="row1">
                <h2>Comando</h2>
                <span id="ops-clock">--:--:--</span>
            </div>
            <input type="search" class="ops-search" id="ops-search" placeholder="Pesquisar missão, motorista, rota…" autocomplete="off">
        </div>
        <div class="ops-filters" id="ops-filters">
            <button type="button" class="ops-filter active" data-filter="todos">Todos</button>
            <button type="button" class="ops-filter" data-filter="em_transito">Trânsito</button>
            <button type="button" class="ops-filter" data-filter="em_recolha">Recolha</button>
            <button type="button" class="ops-filter" data-filter="risco">Risco</button>
            <button type="button" class="ops-filter" data-filter="emergencia">Emerg.</button>
            <button type="button" class="ops-filter" data-filter="offline">Offline</button>
            <button type="button" class="ops-filter" data-filter="watch">👁</button>
        </div>
        <div class="ops-tabs">
            <button type="button" class="ops-tab active" data-tab="missoes">Missões</button>
            <button type="button" class="ops-tab" data-tab="motoristas">Motoristas</button>
            <button type="button" class="ops-tab" data-tab="alertas">Alertas</button>
            <button type="button" class="ops-tab" data-tab="feed">Feed</button>
        </div>
        <div class="ops-scroll" id="ops-list"></div>
        <div class="ops-detail" id="ops-detail"></div>
        <div class="ops-legend">
            <span><i class="bi bi-circle-fill" style="color:#34d399"></i>Trânsito</span>
            <span><i class="bi bi-circle-fill" style="color:#60a5fa"></i>Recolha</span>
            <span><i class="bi bi-circle-fill" style="color:#fb923c"></i>Parado</span>
            <span><i class="bi bi-circle-fill" style="color:#f87171"></i>Emergência</span>
            <span><i class="bi bi-circle-fill" style="color:#94a3b8"></i>Offline</span>
        </div>
    </aside>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/mapa-core.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/realtime-client.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/centro-operacoes.js"></script>
<script>
const BASE_URL = <?php echo json_encode(BASE_URL); ?>;
const HOME_URL = <?php echo json_encode($homeUrl); ?>;
const SCOPE = <?php echo json_encode($scope); ?>;
const DETALHE_BASE = <?php echo json_encode($detalheMissaoBase); ?>;
const USER_TYPE = <?php echo json_encode($userType); ?>;

const centro = new TrackMozCentroOperacoes('map-ops', {
    baseUrl: BASE_URL,
    scope: SCOPE,
    detalheBase: DETALHE_BASE,
    userType: USER_TYPE,
    darkTiles: true,
});

document.getElementById('btn-voltar').addEventListener('click', () => centro.sair(HOME_URL));
document.getElementById('btn-refresh').addEventListener('click', () => centro.carregar(true));
document.getElementById('btn-fit').addEventListener('click', () => centro.fitAll());
document.getElementById('btn-sound').addEventListener('click', () => {
    const on = centro.toggleSound();
    document.getElementById('btn-sound').innerHTML = on
        ? '<i class="bi bi-volume-up"></i> Som'
        : '<i class="bi bi-volume-mute"></i> Mudo';
});
document.getElementById('btn-wall').addEventListener('click', () => {
    const on = centro.toggleWallMode();
    document.getElementById('btn-wall').classList.toggle('primary', on);
});

document.querySelectorAll('.ops-filter').forEach((btn) => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.ops-filter').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        centro.setFilter(btn.dataset.filter);
    });
});

document.querySelectorAll('.ops-tab').forEach((btn) => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.ops-tab').forEach((b) => b.classList.remove('active'));
        btn.classList.add('active');
        centro.setTab(btn.dataset.tab);
    });
});

document.getElementById('ops-search').addEventListener('input', (e) => {
    centro.setSearch(e.target.value);
});

function tickClock() {
    document.getElementById('ops-clock').textContent =
        new Date().toLocaleTimeString('pt-MZ', { hour12: false });
}
tickClock();
setInterval(tickClock, 1000);

centro.carregar().then(() => centro.iniciarAtualizacao(8000));
</script>
</body>
</html>
