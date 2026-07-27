<?php
session_start();
include_once('config/app.php');
include_once('config/database.php');
include_once('includes/auth.php');

$verSitePublico = empty($_SESSION['user_id'])
    || (isset($_GET['site']) && (string)$_GET['site'] === '1');
$lpLogado = !empty($_SESSION['user_id']);

if ($verSitePublico) {
    ?>
    <!DOCTYPE html>
    <html lang="pt">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>TrackMoz - Plataforma inteligente de gestão de transporte rodoviário de cargas</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;0,9..40,800;1,9..40,400&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
        <?php include_once __DIR__ . '/includes/pwa-head.php'; ?>
        <style>
            :root {
                --tm-bg: #c5d0db;
                --tm-bg-secondary: #b8c5d2;
                --tm-surface: #ffffff;
                --tm-surface-raised: #ffffff;
                --tm-primary: #1a647f;
                --tm-primary-soft: #dceef4;
                --tm-primary-glow: rgba(26, 100, 127, 0.12);
                --tm-secondary: #134556;
                --tm-accent: #0d6b63;
                --tm-accent-soft: #d9f0ed;
                --tm-success: #166534;
                --tm-text: #15202b;
                --tm-text-muted: #516374;
                --tm-border: #b8c7d4;
                --tm-border-soft: #cdd8e2;
                --tm-grid-line: rgba(15, 40, 60, 0.16);
                --tm-radius: 14px;
                --tm-radius-lg: 22px;
            }

            *, *::before, *::after { box-sizing: border-box; }

            body.lp {
                background: var(--tm-bg);
                color: var(--tm-text);
                font-family: 'DM Sans', system-ui, sans-serif;
                overflow-x: hidden;
                min-height: 100vh;
                position: relative;
            }

            /* Fundo em grelha — cinza-azulado um pouco mais escuro para a grelha ler bem */
            .tm-bg-layer {
                position: fixed;
                inset: 0;
                pointer-events: none;
                z-index: 0;
                overflow: hidden;
                background:
                    radial-gradient(ellipse 65% 45% at 50% 15%, rgba(232,238,244,.45), transparent 60%),
                    linear-gradient(180deg, #b8c5d2 0%, #c5d0db 45%, #b0becb 100%);
            }
            .tm-grid {
                position: absolute;
                inset: 0;
                background-image:
                    linear-gradient(var(--tm-grid-line) 1px, transparent 1px),
                    linear-gradient(90deg, var(--tm-grid-line) 1px, transparent 1px);
                background-size: 48px 48px;
                opacity: 1;
                mask-image: none;
                -webkit-mask-image: none;
            }
            .tm-orb { display: none; }

            /* ─── Navbar ─── */
            .tm-nav {
                position: fixed; top:0; left:0; right:0; z-index:1000;
                background: transparent;
                border-bottom: 1px solid transparent;
                padding: 14px 0;
                transition: background .3s, box-shadow .3s, border-color .3s;
            }
            .tm-nav.is-solid {
                background: rgba(251,252,253,.96);
                backdrop-filter: blur(16px);
                -webkit-backdrop-filter: blur(16px);
                border-bottom-color: var(--tm-border);
                box-shadow: 0 1px 8px rgba(26,35,50,.05);
            }

            .tm-logo { height:38px; transition: filter .3s; }
            .tm-nav:not(.is-solid) .tm-logo { filter: brightness(0) invert(1); }

            .tm-nav-links {
                display: none;
                align-items: center;
                gap: 4px;
                list-style: none;
                margin: 0;
                padding: 0;
            }
            @media (min-width: 992px) {
                .tm-nav-links { display: flex; }
            }
            .tm-nav-links a {
                display: inline-flex; align-items: center; gap: 6px;
                padding: 8px 12px;
                border-radius: 8px;
                font-size: .85rem;
                font-weight: 500;
                text-decoration: none;
                color: rgba(255,255,255,.85);
                transition: color .2s, background .2s;
            }
            .tm-nav-links a:hover { color: #fff; background: rgba(255,255,255,.1); }
            .tm-nav-links a.active {
                color: #fff;
                background: rgba(255,255,255,.12);
            }
            .tm-nav.is-solid .tm-nav-links a { color: var(--tm-text-muted); }
            .tm-nav.is-solid .tm-nav-links a:hover,
            .tm-nav.is-solid .tm-nav-links a.active {
                color: var(--tm-primary);
                background: rgba(29, 107, 138, .08);
            }

            .tm-btn {
                display: inline-flex; align-items: center; gap: 7px;
                border-radius: 12px;
                padding: 10px 24px;
                font-weight: 600;
                font-size: .9rem;
                transition: all .25s ease;
                position: relative;
                overflow: hidden;
                text-decoration: none;
                cursor: pointer;
            }
            .tm-btn-primary {
                background: var(--tm-primary);
                border: none; color: #fff;
                box-shadow: 0 4px 16px var(--tm-primary-glow);
            }
            .tm-btn-primary:hover { transform:translateY(-1px); background: var(--tm-secondary); box-shadow:0 4px 14px var(--tm-primary-glow); color:#fff; }

            .tm-btn-ghost {
                background: transparent;
                border: 1.5px solid rgba(255,255,255,.45);
                color: #fff;
            }
            .tm-btn-ghost:hover { background:rgba(255,255,255,.12); border-color:#fff; color:#fff; transform:translateY(-1px); }
            .tm-nav.is-solid .tm-btn-ghost {
                background: #fff;
                border-color: var(--tm-border);
                color: var(--tm-primary);
            }
            .tm-nav.is-solid .tm-btn-ghost:hover {
                background: var(--tm-primary);
                border-color: var(--tm-primary);
                color: #fff;
            }
            .tm-btn-outline {
                background: #fff;
                border: 1.5px solid var(--tm-border);
                color: var(--tm-primary);
            }
            .tm-btn-outline:hover {
                background: var(--tm-primary);
                border-color: var(--tm-primary);
                color: #fff;
                transform: translateY(-2px);
            }

            /* ─── Hero (full-bleed photo) ─── */
            .tm-hero {
                position: relative;
                z-index: 2;
                isolation: isolate;
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                justify-content: flex-end;
                padding: 120px 0 40px;
                background: linear-gradient(160deg, #0b1220 0%, #111827 45%, #1e293b 100%);
                color: #fff;
                overflow: hidden;
            }
            .tm-hero-media {
                position: absolute;
                inset: 0;
                z-index: 0;
                pointer-events: none;
            }
            .tm-hero-media img {
                width: 100%;
                height: 100%;
                object-fit: cover;
                object-position: 72% 42%;
                transform: scale(1.18);
                transform-origin: 78% 48%;
            }
            .tm-hero-media::after {
                content: '';
                position: absolute;
                inset: 0;
                background:
                    linear-gradient(90deg, #0b1220 0%, rgba(11,18,32,.92) 28%, rgba(11,18,32,.55) 48%, rgba(11,18,32,.15) 68%, transparent 82%),
                    linear-gradient(180deg, rgba(11,18,32,.75) 0%, transparent 16%),
                    linear-gradient(0deg, #c5d0db 0%, rgba(197,208,219,.55) 12%, transparent 28%);
            }
            .tm-hero-inner {
                position: relative;
                z-index: 2;
                flex: 1;
                display: flex;
                flex-direction: column;
                justify-content: center;
                padding-bottom: 32px;
            }

            .tm-pill {
                display:inline-flex; align-items:center; gap:8px;
                padding:6px 16px;
                background:rgba(255,255,255,.1);
                border:1px solid rgba(255,255,255,.2);
                border-radius:99px;
                font-size:.78rem; font-weight:600;
                color: #7dd3fc;
                letter-spacing:.06em;
                text-transform:uppercase;
                margin-bottom:22px;
                backdrop-filter: blur(8px);
            }
            .tm-pill .dot {
                width:7px;height:7px;border-radius:50%;
                background:#86efac;
            }

            .tm-h1 {
                font-size: clamp(2.4rem, 5vw, 3.8rem);
                font-weight: 800;
                line-height: 1.1;
                letter-spacing: -.02em;
                margin-bottom: 20px;
                color: #fff;
                max-width: 640px;
            }
            .tm-h1 span { color: #7dd3fc; }

            .tm-lead {
                font-size:clamp(1.02rem,1.6vw,1.2rem);
                color: rgba(255,255,255,.82);
                line-height:1.65;
                max-width:520px;
                margin-bottom:32px;
            }

            .tm-hero .tm-btn-ghost {
                background: transparent;
                border-color: rgba(255,255,255,.5);
                color: #fff;
            }
            .tm-hero .tm-btn-ghost:hover {
                background: rgba(255,255,255,.14);
                border-color: #fff;
                color: #fff;
            }

            /* ─── Faixa de confiança (ponte hero → conteúdo) ─── */
            .tm-stats-wrap {
                position: relative;
                z-index: 3;
                margin-top: -28px;
                padding: 0 0 8px;
            }
            .tm-stats-bar {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 1px;
                background: var(--tm-border);
                border: 1px solid var(--tm-border);
                border-radius: var(--tm-radius-lg);
                overflow: hidden;
                box-shadow: 0 10px 28px rgba(21, 32, 43, .12);
            }
            .tm-stat {
                display: flex;
                align-items: flex-start;
                gap: 12px;
                padding: 22px 18px;
                background: #fff;
            }
            .tm-stat-icon {
                width: 40px; height: 40px;
                border-radius: 11px;
                background: var(--tm-primary-soft);
                color: var(--tm-primary);
                display: flex; align-items: center; justify-content: center;
                font-size: 1.05rem;
                flex-shrink: 0;
            }
            .tm-stat-num {
                font-size: .98rem;
                font-weight: 750;
                color: var(--tm-text);
                line-height: 1.3;
                margin-bottom: 3px;
            }
            .tm-stat-lbl {
                font-size: .74rem;
                color: var(--tm-text-muted);
                line-height: 1.4;
                margin: 0;
            }

            /* ─── Sections sobre a grelha ─── */
            .tm-section {
                position: relative;
                z-index: 1;
                padding: 88px 0;
                background: transparent;
            }
            .tm-section.alt {
                background: rgba(160, 176, 192, .28);
                border-top: 1px solid rgba(120, 140, 160, .35);
                border-bottom: 1px solid rgba(120, 140, 160, .35);
            }
            .tm-section-head {
                max-width: 560px;
                margin-bottom: 40px;
            }
            .tm-section-head.center {
                margin-left: auto;
                margin-right: auto;
                text-align: center;
            }
            .tm-section-head.center .tm-eyebrow { justify-content: center; }
            .tm-section-head.center .tm-sub { margin-left: auto; margin-right: auto; }

            .tm-eyebrow {
                font-size:.72rem; font-weight:700; letter-spacing:.14em;
                text-transform:uppercase; color:var(--tm-primary);
                margin-bottom:14px;
                display:flex; align-items:center; gap:10px;
            }
            .tm-eyebrow::before { content:''; width:22px; height:2px; background:var(--tm-accent); border-radius:2px; }

            .tm-h2 {
                font-size:clamp(1.75rem,3.2vw,2.55rem);
                font-weight:750; letter-spacing:-.02em;
                margin-bottom:12px;
                color: var(--tm-text);
                line-height: 1.15;
            }
            .tm-sub { font-size:1.05rem; color:var(--tm-text-muted); line-height:1.65; max-width:540px; }

            /* Cards brancos sobre a grelha — contraste claro */
            .tm-card {
                background: #fff;
                border: 1px solid var(--tm-border);
                border-radius: var(--tm-radius);
                padding: 26px 24px;
                box-shadow: 0 4px 14px rgba(21, 32, 43, .06);
                transition: border-color .2s ease, box-shadow .2s ease;
                height: 100%;
            }
            .tm-card:hover {
                border-color: #9bb0c2;
                box-shadow: 0 8px 22px rgba(21, 32, 43, .1);
            }

            .tm-icon {
                width: 46px; height: 46px;
                border-radius: 12px;
                display: flex; align-items: center; justify-content: center;
                font-size: 1.2rem;
                margin-bottom: 16px;
                border: 1px solid transparent;
            }
            .ic-blue, .ic-purple, .ic-cyan {
                background: var(--tm-primary-soft);
                color: var(--tm-primary);
                border-color: rgba(26,100,127,.12);
            }
            .ic-green {
                background: var(--tm-accent-soft);
                color: var(--tm-accent);
                border-color: rgba(13,107,99,.12);
            }
            .ic-amber {
                background: #f5edd8;
                color: #9a6700;
                border-color: rgba(154,103,0,.12);
            }
            .ic-rose {
                background: #f3e4e7;
                color: #9f1239;
                border-color: rgba(159,18,57,.1);
            }

            .tm-card h3 { font-size:1.05rem; font-weight:700; color:var(--tm-text); margin-bottom:8px; }
            .tm-card p  { font-size:.9rem; color:var(--tm-text-muted); line-height:1.6; margin:0; }

            .tm-grid-3 { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; }
            .tm-grid-4 { display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:14px; }

            /* Intro “O que é” — card branco (clareza) */
            .tm-about-panel {
                background: #fff;
                color: var(--tm-text);
                border: 1px solid var(--tm-border);
                border-radius: var(--tm-radius-lg);
                padding: 36px 32px;
                box-shadow: 0 4px 16px rgba(21, 32, 43, .07);
                height: 100%;
            }
            .tm-about-panel .tm-eyebrow { color: var(--tm-primary); }
            .tm-about-panel .tm-eyebrow::before { background: var(--tm-accent); }
            .tm-about-panel .tm-h2 { color: var(--tm-text); }
            .tm-about-panel .tm-sub { color: var(--tm-text-muted); max-width: none; }

            /* Steps: cards brancos separados sobre a grelha (como a 2ª imagem, tema claro) */
            .tm-steps {
                display: grid;
                grid-template-columns: repeat(5, 1fr);
                gap: 14px;
                background: transparent;
                border: none;
                border-radius: 0;
                overflow: visible;
                box-shadow: none;
            }
            .tm-step {
                position: relative;
                padding: 26px 20px 24px;
                background: #fff;
                border: 1px solid var(--tm-border);
                border-radius: var(--tm-radius);
                margin: 0;
                box-shadow: 0 4px 14px rgba(21, 32, 43, .06);
                transition: border-color .2s, box-shadow .2s;
            }
            .tm-step:last-child { border-right: 1px solid var(--tm-border); }
            .tm-step:hover {
                background: #fff;
                border-color: #9bb0c2;
                box-shadow: 0 8px 22px rgba(21, 32, 43, .1);
                transform: none;
            }
            .tm-step-num {
                display: block;
                width: auto;
                height: auto;
                border-radius: 0;
                font-size: 2.4rem;
                font-weight: 900;
                line-height: 1;
                color: var(--tm-primary);
                background: transparent;
                margin-bottom: 10px;
                opacity: .22;
                -webkit-text-fill-color: var(--tm-primary);
                background-image: none;
            }
            .tm-step h4 { font-size: .98rem; font-weight: 700; color: var(--tm-text); margin-bottom: 8px; }
            .tm-step p  { font-size: .84rem; color: var(--tm-text-muted); line-height: 1.5; margin: 0; }

            /* ─── Perfis ─── */
            .tm-profiles { display:grid; grid-template-columns:repeat(3,1fr); gap:18px; }
            .tm-profile {
                background: #fff;
                border: 1px solid var(--tm-border);
                border-radius: var(--tm-radius-lg);
                padding: 28px 26px 28px 28px;
                box-shadow: 0 4px 14px rgba(21, 32, 43, .06);
                transition: border-color .2s, box-shadow .2s;
                position: relative;
                overflow: hidden;
            }
            .tm-profile::before {
                content:'';
                position:absolute; top:0; left:0; bottom:0; width:4px;
                background: linear-gradient(180deg, var(--tm-primary), var(--tm-accent));
                opacity: 1;
            }
            .tm-profile:hover {
                border-color: #9bb0c2;
                transform: none;
                box-shadow: 0 8px 22px rgba(21, 32, 43, .1);
            }
            .tm-profile h3 { font-size:1.2rem; font-weight:700; color:var(--tm-text); margin:14px 0 8px; }
            .tm-profile p  { font-size:.9rem; color:var(--tm-text-muted); line-height:1.55; margin:0; }
            .tm-profile ul { list-style:none; padding:0; margin:18px 0 0; display:flex; flex-direction:column; gap:9px; }
            .tm-profile li {
                font-size:.84rem; color:var(--tm-text);
                display:flex; align-items:flex-start; gap:10px;
                padding: 8px 10px;
                background: #eef4f8;
                border-radius: 8px;
            }
            .tm-profile li::before {
                content:'';
                width:6px; height:6px; border-radius:50%;
                background:var(--tm-accent);
                flex-shrink:0;
                margin-top: .45em;
            }

            /* Features — cards brancos na grelha */
            .tm-feat-list {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            .tm-feat {
                display: flex;
                gap: 14px;
                align-items: flex-start;
                padding: 16px 18px;
                background: #fff;
                border: 1px solid var(--tm-border);
                border-radius: 12px;
                box-shadow: 0 3px 12px rgba(21, 32, 43, .05);
            }
            .tm-feat .tm-icon { margin-bottom: 0; width: 40px; height: 40px; font-size: 1.05rem; flex-shrink: 0; }
            .tm-feat h3 { font-size: .95rem; font-weight: 700; margin: 0 0 4px; color: var(--tm-text); }
            .tm-feat p { font-size: .82rem; color: var(--tm-text-muted); margin: 0; line-height: 1.45; }

            /* ─── CTA ─── */
            .tm-cta {
                background: linear-gradient(135deg, #134556 0%, #1a647f 45%, #0d6b63 100%);
                border: none;
                border-radius: var(--tm-radius-lg);
                padding: 64px 40px;
                text-align: center;
                position: relative;
                overflow: hidden;
                box-shadow: 0 14px 36px rgba(19, 69, 86, .25);
                color: #fff;
            }
            .tm-cta-glow {
                position: absolute; inset: 0; border-radius: inherit;
                background:
                    radial-gradient(ellipse at 20% 0%, rgba(255,255,255,.1) 0%, transparent 45%),
                    radial-gradient(ellipse at 80% 100%, rgba(94,234,212,.12) 0%, transparent 40%);
                pointer-events: none;
            }
            .tm-cta .tm-eyebrow { color: #9fd4e4; justify-content: center; }
            .tm-cta .tm-eyebrow::before { background: #5eead4; }
            .tm-cta .tm-h2 { color: #fff; margin-bottom: 12px; }
            .tm-cta .tm-sub { color: rgba(255,255,255,.8); margin: 0 auto 32px; }
            .tm-cta .tm-btn-outline {
                background: transparent;
                border-color: rgba(255,255,255,.45);
                color: #fff;
            }
            .tm-cta .tm-btn-outline:hover {
                background: rgba(255,255,255,.12);
                border-color: #fff;
                color: #fff;
                transform: none;
            }
            .tm-cta .tm-btn-primary {
                background: #fff;
                color: var(--tm-secondary);
            }
            .tm-cta .tm-btn-primary:hover {
                background: #e8f4f7;
                color: var(--tm-secondary);
            }

            /* ─── Footer ─── */
            .tm-footer {
                background: #a8b6c4;
                border-top: 1px solid rgba(80, 100, 120, .35);
                padding: 52px 0 28px;
                position: relative;
                z-index: 1;
            }
            .tm-footer-logo { height: 40px; margin-bottom: 14px; }
            .tm-footer a { color: var(--tm-text-muted); text-decoration: none; font-size: .88rem; transition: color .2s; }
            .tm-footer a:hover { color: var(--tm-primary); }
            .tm-footer h6 {
                color: var(--tm-text);
                font-weight: 700;
                font-size: .78rem;
                letter-spacing: .08em;
                text-transform: uppercase;
                margin-bottom: 14px;
            }
            .tm-footer ul { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 9px; }
            .tm-divider { border-color: var(--tm-border); margin: 36px 0 20px; }

            /* ─── Reveal (suave, sem saltos) ─── */
            .reveal {
                opacity:0; transform:translateY(12px);
                transition:opacity .45s ease, transform .45s ease;
            }
            .reveal.visible { opacity:1; transform:translateY(0); }
            .delay-1 { transition-delay:.04s; }
            .delay-2 { transition-delay:.08s; }
            .delay-3 { transition-delay:.12s; }
            .delay-4 { transition-delay:.16s; }
            .delay-5 { transition-delay:.2s; }

            @media (prefers-reduced-motion:reduce) {
                .reveal { animation:none; transition:none; opacity:1; transform:none; }
            }
            @media (max-width:1199px) {
                .tm-steps { grid-template-columns: repeat(3, 1fr); }
            }
            @media (max-width:991px) {
                .tm-stats-bar { grid-template-columns: 1fr 1fr; }
                .tm-profiles { grid-template-columns: 1fr; }
                .tm-feat-list { grid-template-columns: 1fr; }
                .tm-about-panel { margin-bottom: 8px; }
            }
            @media (max-width:768px) {
                .tm-hero { min-height: auto; padding: 110px 0 48px; }
                .tm-hero-media img { object-position: 80% 40%; transform: scale(1.35); }
                .tm-hero-inner { padding-bottom: 16px; }
                .tm-stats-wrap { margin-top: -20px; }
                .tm-section { padding: 64px 0; }
                .tm-stats-bar { grid-template-columns: 1fr; }
                .tm-steps { grid-template-columns: 1fr; }
                .tm-cta { padding: 48px 22px; }
                .tm-grid { background-size: 40px 40px; }
            }
        </style>
    </head>
    <body class="lp">

    <!-- Fundo suave estático (após o hero) -->
    <div class="tm-bg-layer" aria-hidden="true">
        <div class="tm-grid"></div>
    </div>

    <!-- Navbar -->
    <nav class="tm-nav" id="tmPublicNav">
        <div class="container d-flex align-items-center justify-content-between gap-3">
            <a href="<?php echo BASE_URL; ?>/index.php<?php echo $lpLogado ? '?site=1' : ''; ?>" class="flex-shrink-0">
                <img class="tm-logo" src="<?php echo BASE_URL; ?>/assets/img/Logo_sem_background.png" alt="TrackMoz">
            </a>
            <ul class="tm-nav-links">
                <li><a class="active" href="#inicio"><i class="bi bi-house"></i> Início</a></li>
                <li><a href="#como-funciona"><i class="bi bi-play-circle"></i> Como Funciona</a></li>
                <li><a href="#para-quem"><i class="bi bi-grid"></i> Soluções</a></li>
                <li><a href="#funcionalidades"><i class="bi bi-stars"></i> Recursos</a></li>
                <li><a href="#o-que-e"><i class="bi bi-info-circle"></i> Sobre Nós</a></li>
            </ul>
            <div class="d-flex gap-2 flex-shrink-0">
                <?php if ($lpLogado): ?>
                    <a class="tm-btn tm-btn-primary" href="<?php echo BASE_URL; ?>/index.php">
                        <i class="bi bi-grid-1x2"></i> Voltar à app
                    </a>
                <?php else: ?>
                    <a class="tm-btn tm-btn-ghost" href="<?php echo BASE_URL; ?>/pages/login.php">Entrar</a>
                    <a class="tm-btn tm-btn-primary" href="<?php echo BASE_URL; ?>/pages/cadastro.php">
                        <i class="bi bi-person-plus"></i> Criar Conta
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Hero -->
    <section class="tm-hero" id="inicio">
        <div class="tm-hero-media" aria-hidden="true">
            <img src="<?php echo BASE_URL; ?>/assets/img/hero-background.png" alt="" width="1920" height="1080" fetchpriority="high">
        </div>
        <div class="container tm-hero-inner">
            <div class="tm-pill reveal"><span class="dot"></span>Plataforma de Logística Inteligente</div>
            <h1 class="tm-h1 reveal delay-1">Conectando o <span>Transporte.</span><br>Impulsionando <span>Moçambique.</span></h1>
            <p class="tm-lead reveal delay-2">
                Empresas, transportadoras e camionistas unidos por uma plataforma inteligente, segura e transparente.
            </p>
            <div class="d-flex flex-wrap gap-3 reveal delay-3">
                <a class="tm-btn tm-btn-primary" href="<?php echo BASE_URL; ?>/pages/cadastro.php" style="font-size:1rem;padding:14px 32px;">
                    <i class="bi bi-truck"></i> Encontrar Fretes
                </a>
                <a class="tm-btn tm-btn-ghost" href="#como-funciona" style="font-size:1rem;padding:14px 32px;">
                    <i class="bi bi-play-circle"></i> Como Funciona
                </a>
            </div>
        </div>
    </section>

    <!-- Faixa de confiança (ponte visual do hero) -->
    <div class="tm-stats-wrap">
        <div class="container reveal delay-4">
            <div class="tm-stats-bar">
                <div class="tm-stat">
                    <div class="tm-stat-icon"><i class="bi bi-people"></i></div>
                    <div>
                        <div class="tm-stat-num">1.2K+ Mercados activos</div>
                        <p class="tm-stat-lbl">Empresas a encontrar melhores soluções</p>
                    </div>
                </div>
                <div class="tm-stat">
                    <div class="tm-stat-icon"><i class="bi bi-truck"></i></div>
                    <div>
                        <div class="tm-stat-num">850+ Transportadoras</div>
                        <p class="tm-stat-lbl">Parceiros verificados em todo o país</p>
                    </div>
                </div>
                <div class="tm-stat">
                    <div class="tm-stat-icon"><i class="bi bi-shield-check"></i></div>
                    <div>
                        <div class="tm-stat-num">99.9% Entregas confirmadas</div>
                        <p class="tm-stat-lbl">Segurança e fiabilidade em cada operação</p>
                    </div>
                </div>
                <div class="tm-stat">
                    <div class="tm-stat-icon"><i class="bi bi-headset"></i></div>
                    <div>
                        <div class="tm-stat-num">24/7 Suporte online</div>
                        <p class="tm-stat-lbl">Equipa dedicada quando precisar</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- O que é -->
    <section id="o-que-e" class="tm-section">
        <div class="container">
            <div class="row align-items-stretch g-4">
                <div class="col-lg-5 reveal">
                    <div class="tm-about-panel">
                        <div class="tm-eyebrow">Sobre a plataforma</div>
                        <h2 class="tm-h2">O que é o TrackMoz?</h2>
                        <p class="tm-sub">Uma plataforma digital que moderniza a intermediação e gestão do transporte rodoviário de cargas em Moçambique — com formalidade, controlo e histórico confiável.</p>
                    </div>
                </div>
                <div class="col-lg-7 reveal delay-2">
                    <div class="tm-grid-3">
                        <div class="tm-card">
                            <div class="tm-icon ic-blue"><i class="bi bi-file-earmark-text"></i></div>
                            <h3>Contratos digitais</h3>
                            <p>Formalização automática com histórico completo e validação jurídica.</p>
                        </div>
                        <div class="tm-card">
                            <div class="tm-icon ic-purple"><i class="bi bi-truck"></i></div>
                            <h3>Gestão de missões</h3>
                            <p>Crie, atribua e acompanhe fretes pontuais e contratos de longo prazo.</p>
                        </div>
                        <div class="tm-card">
                            <div class="tm-icon ic-cyan"><i class="bi bi-geo-alt"></i></div>
                            <h3>Rastreabilidade GPS</h3>
                            <p>Acompanhamento em tempo real com histórico completo das operações.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Como Funciona -->
    <section id="como-funciona" class="tm-section alt">
        <div class="container">
            <div class="tm-section-head center reveal">
                <div class="tm-eyebrow">Fluxo de trabalho</div>
                <h2 class="tm-h2">Como funciona</h2>
                <p class="tm-sub">Um processo simples para conectar empresas, transportadoras e camionistas.</p>
            </div>
            <div class="tm-steps reveal delay-1">
                <div class="tm-step">
                    <div class="tm-step-num">01</div>
                    <h4>Publicação</h4>
                    <p>A empresa publica uma missão com carga, origem, destino e prazo.</p>
                </div>
                <div class="tm-step">
                    <div class="tm-step-num">02</div>
                    <h4>Propostas</h4>
                    <p>Transportadores propõem ou são atribuídos por contrato de parceria.</p>
                </div>
                <div class="tm-step">
                    <div class="tm-step-num">03</div>
                    <h4>Documentação</h4>
                    <p>O sistema gera contratos e documentos digitais automaticamente.</p>
                </div>
                <div class="tm-step">
                    <div class="tm-step-num">04</div>
                    <h4>Execução</h4>
                    <p>O camionista executa com modo condução GPS e rastreio em tempo real.</p>
                </div>
                <div class="tm-step">
                    <div class="tm-step-num">05</div>
                    <h4>Entrega</h4>
                    <p>OTP, prova de entrega e documentos finais gerados no fecho.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Para Quem -->
    <section id="para-quem" class="tm-section">
        <div class="container">
            <div class="tm-section-head center reveal">
                <div class="tm-eyebrow">Perfis de utilizador</div>
                <h2 class="tm-h2">Para quem é</h2>
                <p class="tm-sub">Ferramentas específicas para cada papel no ecossistema logístico.</p>
            </div>
            <div class="tm-profiles reveal delay-1">
                <div class="tm-profile">
                    <div class="tm-icon ic-blue"><i class="bi bi-buildings"></i></div>
                    <h3>Empresas</h3>
                    <p>Gira a cadeia de transporte sem sair da plataforma.</p>
                    <ul>
                        <li>Publicação e gestão de fretes</li>
                        <li>Lançamento de concursos</li>
                        <li>Acompanhamento em tempo real</li>
                        <li>Relatórios detalhados</li>
                    </ul>
                </div>
                <div class="tm-profile">
                    <div class="tm-icon ic-purple"><i class="bi bi-truck-flatbed"></i></div>
                    <h3>Transportadoras</h3>
                    <p>Mais oportunidades e controlo da frota num só sítio.</p>
                    <ul>
                        <li>Concorrência a contratos</li>
                        <li>Gestão de frota e motoristas</li>
                        <li>Histórico de desempenho</li>
                        <li>Documentação centralizada</li>
                    </ul>
                </div>
                <div class="tm-profile">
                    <div class="tm-icon ic-green"><i class="bi bi-person-badge"></i></div>
                    <h3>Camionistas</h3>
                    <p>Receba missões, navegue com GPS e construa reputação.</p>
                    <ul>
                        <li>Recepção de missões</li>
                        <li>Modo condução GPS</li>
                        <li>Alertas de prazos</li>
                        <li>Histórico e avaliações</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- Funcionalidades -->
    <section id="funcionalidades" class="tm-section alt">
        <div class="container">
            <div class="tm-section-head center reveal">
                <div class="tm-eyebrow">O que está incluído</div>
                <h2 class="tm-h2">Funcionalidades principais</h2>
                <p class="tm-sub">Tudo o que precisa para gerir transporte de cargas de forma eficiente.</p>
            </div>
            <div class="tm-feat-list reveal delay-1">
                <div class="tm-feat"><div class="tm-icon ic-blue"><i class="bi bi-clipboard-data"></i></div><div><h3>Publicação de missões</h3><p>Crie missões com carga, rotas, prazos e valores.</p></div></div>
                <div class="tm-feat"><div class="tm-icon ic-purple"><i class="bi bi-handshake"></i></div><div><h3>Parcerias</h3><p>Contratos de longo prazo com empresas parceiras.</p></div></div>
                <div class="tm-feat"><div class="tm-icon ic-cyan"><i class="bi bi-file-earmark"></i></div><div><h3>Contratos digitais</h3><p>Geração automática com validação jurídica.</p></div></div>
                <div class="tm-feat"><div class="tm-icon ic-green"><i class="bi bi-truck"></i></div><div><h3>Gestão de frota</h3><p>Veículos, manutenção e documentação.</p></div></div>
                <div class="tm-feat"><div class="tm-icon ic-amber"><i class="bi bi-person-gear"></i></div><div><h3>Atribuição de motorista</h3><p>Delegue missões com validação de carta.</p></div></div>
                <div class="tm-feat"><div class="tm-icon ic-blue"><i class="bi bi-geo-alt"></i></div><div><h3>Modo condução</h3><p>Rastreamento GPS em tempo real.</p></div></div>
                <div class="tm-feat"><div class="tm-icon ic-rose"><i class="bi bi-shield-exclamation"></i></div><div><h3>Emergências</h3><p>Alertas e resposta a emergências.</p></div></div>
                <div class="tm-feat"><div class="tm-icon ic-purple"><i class="bi bi-phone"></i></div><div><h3>Confirmação OTP</h3><p>Validação segura de entrega com código único.</p></div></div>
                <div class="tm-feat"><div class="tm-icon ic-green"><i class="bi bi-check-circle"></i></div><div><h3>Prova de entrega</h3><p>Fotos e assinatura digital em cada entrega.</p></div></div>
                <div class="tm-feat"><div class="tm-icon ic-cyan"><i class="bi bi-receipt"></i></div><div><h3>Facturas</h3><p>Geração automática de facturas e recibos.</p></div></div>
                <div class="tm-feat"><div class="tm-icon ic-amber"><i class="bi bi-folder"></i></div><div><h3>Documentos</h3><p>Gestão documental centralizada.</p></div></div>
                <div class="tm-feat"><div class="tm-icon ic-rose"><i class="bi bi-star"></i></div><div><h3>Avaliações</h3><p>Sistema de avaliação mútua entre partes.</p></div></div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section id="cta" class="tm-section">
        <div class="container reveal">
            <div class="tm-cta">
                <div class="tm-cta-glow"></div>
                <div class="tm-eyebrow">Comece já</div>
                <h2 class="tm-h2">Pronto para transformar<br>a sua logística?</h2>
                <p class="tm-sub">Junte-se a empresas e transportadoras que já modernizaram as suas operações.</p>
                <div class="d-flex flex-wrap gap-3 justify-content-center">
                    <a class="tm-btn tm-btn-primary" href="<?php echo BASE_URL; ?>/pages/cadastro.php" style="font-size:1rem;padding:14px 36px;">
                        <i class="bi bi-person-plus"></i> Criar conta gratuita
                    </a>
                    <a class="tm-btn tm-btn-outline" href="<?php echo BASE_URL; ?>/pages/login.php" style="font-size:1rem;padding:14px 36px;">
                        <i class="bi bi-box-arrow-in-right"></i> Entrar no sistema
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="tm-footer">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-4">
                    <img class="tm-footer-logo" src="<?php echo BASE_URL; ?>/assets/img/Logo_sem_background.png" alt="TrackMoz">
                    <p style="color:var(--tm-text-muted);font-size:.9rem;line-height:1.6;max-width:300px;">
                        Plataforma inteligente de gestão de transporte rodoviário de cargas. Modernizando a logística em Moçambique.
                    </p>
                    <p style="color:var(--tm-text-muted);font-size:.8rem;margin-top:16px;">
                        <strong style="color:var(--tm-text);">Projecto Académico</strong><br>
                        Desenvolvido por Engenheiro Emilton Muholove
                    </p>
                </div>
                <div class="col-6 col-lg-2">
                    <h6>Produto</h6>
                    <ul>
                        <li><a href="#o-que-e">O que é</a></li>
                        <li><a href="#como-funciona">Como funciona</a></li>
                        <li><a href="#funcionalidades">Funcionalidades</a></li>
                        <li><a href="#para-quem">Perfis</a></li>
                    </ul>
                </div>
                <div class="col-6 col-lg-2">
                    <h6>Legal</h6>
                    <ul>
                        <li><a href="#">Termos de Uso</a></li>
                        <li><a href="#">Privacidade</a></li>
                        <li><a href="#">Cookies</a></li>
                    </ul>
                </div>
                <div class="col-lg-4">
                    <h6>Contacto</h6>
                    <ul>
                        <li><a href="mailto:contacto@trackmoz.mz"><i class="bi bi-envelope me-2"></i>contacto@trackmoz.mz</a></li>
                        <li><a href="tel:+258841234567"><i class="bi bi-telephone me-2"></i>+258 84 123 4567</a></li>
                        <li><span style="color:var(--tm-text-muted)"><i class="bi bi-geo-alt me-2"></i>Maputo, Moçambique</span></li>
                    </ul>
                </div>
            </div>
            <hr class="tm-divider">
            <div class="d-flex flex-wrap justify-content-between align-items-center" style="font-size:.82rem;color:var(--tm-text-muted);">
                <span>© 2026 TrackMoz. Todos os direitos reservados.</span>
                <span>Feito com <i class="bi bi-heart-fill text-danger"></i> em Moçambique</span>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    (function () {
        var prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion:reduce)').matches;
        var els = document.querySelectorAll('.reveal');
        if (prefersReduced || !('IntersectionObserver' in window)) {
            els.forEach(function (el) { el.classList.add('visible'); });
            return;
        }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) {
                if (e.isIntersecting) { e.target.classList.add('visible'); io.unobserve(e.target); }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
        els.forEach(function (el) { io.observe(el); });

        // Navbar: transparente no hero, sólida ao scroll
        var nav = document.getElementById('tmPublicNav');
        function updateNav() {
            if (!nav) return;
            if (window.scrollY > 60) nav.classList.add('is-solid');
            else nav.classList.remove('is-solid');
        }
        updateNav();
        window.addEventListener('scroll', updateNav, { passive: true });

        // Active nav link on click
        document.querySelectorAll('.tm-nav-links a').forEach(function (a) {
            a.addEventListener('click', function () {
                document.querySelectorAll('.tm-nav-links a').forEach(function (x) { x.classList.remove('active'); });
                a.classList.add('active');
            });
        });
    })();
    </script>
    </body>
    </html>
    <?php
    exit;
}

// ═══════════════════════════════════════════════════════
//  AUTHENTICATED — DASHBOARD
// ═══════════════════════════════════════════════════════

// Painéis ricos por perfil (única fonte de verdade)
$userTypeHome = $_SESSION['user_type'] ?? '';
if ($userTypeHome === 'caminhoneiro') {
    header('Location: ' . BASE_URL . '/pages/caminhoneiro/dashboard.php');
    exit;
}
if ($userTypeHome === 'empresa') {
    header('Location: ' . BASE_URL . '/pages/contratante/dashboard.php');
    exit;
}
if ($userTypeHome === 'transportador' && is_file(__DIR__ . '/pages/transportador/dashboard.php')) {
    header('Location: ' . BASE_URL . '/pages/transportador/dashboard.php');
    exit;
}

$stats = [
    'missoes_disponiveis' => 0, 'minhas_propostas' => 0,
    'missoes_andamento'   => 0, 'missoes_ativas'   => 0,
    'propostas_recebidas' => 0, 'missoes_concluidas'=> 0,
    'avaliacao_media'     => 0, 'total_usuarios'   => 0,
    'total_caminhoneiros' => 0, 'total_empresas'   => 0,
    'usuarios_pendentes'  => 0, 'total_missoes'    => 0,
];

$adminHome = null;
$adminActividades = [];
$adminIrregulares = [];
$adminMissoesRecentes = [];

try {
    $sql = "SELECT u.*,
                pc.tipo_veiculo, pc.capacidade_carga, pc.disponibilidade,
                pe.nome_empresa
            FROM usuarios u
            LEFT JOIN perfil_caminhoneiro pc ON u.id = pc.usuario_id
            LEFT JOIN perfil_empresa      pe ON u.id = pe.usuario_id
            WHERE u.id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($_SESSION['user_type'] === 'admin') {
        require_once __DIR__ . '/includes/admin-dashboard-helpers.php';
        $adminHome = admin_dashboard_home($conn);
        $stats = array_merge($stats, $adminHome['stats']);
        $adminActividades = $adminHome['actividades'];
        $adminIrregulares = $adminHome['irregulares_preview'];
        $adminMissoesRecentes = $adminHome['missoes_recentes'];
    } elseif ($_SESSION['user_type'] === 'caminhoneiro') {
        $uid = $_SESSION['user_id'];
        $stmt=$conn->prepare("SELECT COUNT(*) FROM missoes WHERE status='aberta'"); $stmt->execute(); $stats['missoes_disponiveis']=$stmt->fetchColumn();
        $stmt=$conn->prepare("SELECT COUNT(*) FROM propostas WHERE caminhoneiro_id=:id"); $stmt->execute([':id'=>$uid]); $stats['minhas_propostas']=$stmt->fetchColumn();
        $stmt=$conn->prepare("SELECT COUNT(*) FROM missoes WHERE caminhoneiro_id=:id AND status='em_andamento'"); $stmt->execute([':id'=>$uid]); $stats['missoes_andamento']=$stmt->fetchColumn();
        $stmt=$conn->prepare("SELECT AVG(nota) FROM avaliacoes WHERE avaliado_id=:id"); $stmt->execute([':id'=>$uid]);
        $avg=$stmt->fetchColumn(); $stats['avaliacao_media']=$avg!==NULL?round($avg,1):0;
    } else {
        $uid=$_SESSION['user_id'];
        $stmt=$conn->prepare("SELECT COUNT(*) FROM missoes WHERE empresa_id=:id AND status IN('aberta','em_andamento')"); $stmt->execute([':id'=>$uid]); $stats['missoes_ativas']=$stmt->fetchColumn();
        $stmt=$conn->prepare("SELECT COUNT(*) FROM propostas p JOIN missoes m ON p.missao_id=m.id WHERE m.empresa_id=:id AND p.status='pendente'"); $stmt->execute([':id'=>$uid]); $stats['propostas_recebidas']=$stmt->fetchColumn();
        $stmt=$conn->prepare("SELECT COUNT(*) FROM missoes WHERE empresa_id=:id AND status='concluida'"); $stmt->execute([':id'=>$uid]); $stats['missoes_concluidas']=$stmt->fetchColumn();
    }
} catch (PDOException $e) { error_log($e->getMessage()); }

$usuarios_pendentes = [];
if ($_SESSION['user_type'] === 'admin') {
    try {
        $sql="SELECT u.*,
            CASE WHEN u.tipo_usuario='caminhoneiro' THEN pc.tipo_veiculo
                 WHEN u.tipo_usuario='empresa'      THEN pe.nome_empresa ELSE NULL END as detalhe_perfil
            FROM usuarios u
            LEFT JOIN perfil_caminhoneiro pc ON u.id=pc.usuario_id
            LEFT JOIN perfil_empresa      pe ON u.id=pe.usuario_id
            WHERE u.status='pendente' ORDER BY u.data_registro DESC LIMIT 5";
        $stmt=$conn->prepare($sql); $stmt->execute();
        $usuarios_pendentes=$stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { error_log($e->getMessage()); }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <?php include_once __DIR__ . '/includes/pwa-head.php'; ?>
    <style>
        :root {
            --d-bg:       #f8fafc;
            --d-surface:  #ffffff;
            --d-surf2:    #f1f5f9;
            --d-border:   #e2e8f0;
            --d-border-h: #cbd5e1;
            --d-primary:  #2563eb;
            --d-primary-g:rgba(37,99,235,.25);
            --d-secondary:#1e40af;
            --d-accent:   #0891b2;
            --d-success:  #16a34a;
            --d-warning:  #d97706;
            --d-danger:   #dc2626;
            --d-text:     #1e293b;
            --d-muted:    #64748b;
            --d-radius:   16px;
        }

        body {
            background: var(--d-bg);
            color: var(--d-text);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            min-height: 100vh;
        }

        /* ── Dashboard layout ── */
        .dash-wrap { padding: 32px 0 60px; }

        /* ── Page header ── */
        .dash-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 36px;
        }
        .dash-greeting { font-size: clamp(1.4rem, 3vw, 2rem); font-weight: 800; letter-spacing: -.015em; color: var(--d-text); margin: 0; }
        .dash-greeting span {
            background: linear-gradient(135deg, var(--d-primary), var(--d-accent));
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
        }
        .dash-sub { color: var(--d-muted); font-size: .9rem; margin: 4px 0 0; }

        .dash-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 12px; border-radius: 99px;
            font-size: .78rem; font-weight: 600; letter-spacing: .03em;
        }
        .dash-badge .dot { width: 7px; height: 7px; border-radius: 50%; animation: blink 2s ease-in-out infinite; }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }
        .badge-green  { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .badge-green .dot  { background: #16a34a; }
        .badge-yellow { background: #fef9c3; color: #a16207; border: 1px solid #fde68a; }
        .badge-yellow .dot { background: #ca8a04; }
        .badge-red    { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .badge-red .dot    { background: #dc2626; }
        .badge-gray   { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        .badge-gray .dot   { background: #64748b; }

        .dash-action-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 11px 24px; border-radius: 12px;
            font-weight: 600; font-size: .9rem;
            border: none; cursor: pointer; text-decoration: none;
            transition: all .25s;
        }
        .btn-primary-g {
            background: linear-gradient(135deg, var(--d-primary), var(--d-secondary));
            color: #fff; box-shadow: 0 4px 20px var(--d-primary-g);
        }
        .btn-primary-g:hover { transform: translateY(-2px); box-shadow: 0 8px 32px var(--d-primary-g); color: #fff; }
        .btn-danger-g {
            background: linear-gradient(135deg, #f43f5e, #e11d48);
            color: #fff; box-shadow: 0 4px 20px rgba(244,63,94,.35);
        }
        .btn-danger-g:hover { transform: translateY(-2px); color: #fff; }

        /* ── Stat cards ── */
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 32px; }

        .scard {
            background: var(--d-surface);
            border: 1px solid var(--d-border);
            border-radius: var(--d-radius);
            padding: 24px 22px;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.08), 0 2px 4px -2px rgb(0 0 0 / 0.06);
            transition: all .3s ease;
            position: relative; overflow: hidden;
            cursor: default;
        }
        .scard::before {
            content:''; position:absolute; top:0; left:0; right:0; height:2px;
            opacity:0; transition:opacity .3s;
        }
        .scard:hover { transform: translateY(-4px); border-color: var(--d-border-h); box-shadow: 0 12px 24px rgba(15,23,42,.08); }
        .scard:hover::before { opacity:1; }

        .scard-blue::before   { background: linear-gradient(90deg, var(--d-primary), #60a5fa); }
        .scard-purple::before { background: linear-gradient(90deg, var(--d-secondary), #a78bfa); }
        .scard-green::before  { background: linear-gradient(90deg, var(--d-success), #34d399); }
        .scard-amber::before  { background: linear-gradient(90deg, var(--d-warning), #fcd34d); }
        .scard-cyan::before   { background: linear-gradient(90deg, var(--d-accent), #67e8f9); }
        .scard-rose::before   { background: linear-gradient(90deg, var(--d-danger), #fb7185); }

        .scard-icon {
            width: 44px; height: 44px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; margin-bottom: 16px; flex-shrink: 0;
        }
        .si-blue   { background: rgba(59,130,246,.12);  color: var(--d-primary); }
        .si-purple { background: rgba(139,92,246,.12);  color: var(--d-secondary); }
        .si-green  { background: rgba(16,185,129,.12);  color: var(--d-success); }
        .si-amber  { background: rgba(245,158,11,.12);  color: var(--d-warning); }
        .si-cyan   { background: rgba(6,182,212,.12);   color: var(--d-accent); }
        .si-rose   { background: rgba(244,63,94,.12);   color: var(--d-danger); }

        .scard-label { font-size: .78rem; font-weight: 600; color: var(--d-muted); letter-spacing: .04em; text-transform: uppercase; margin-bottom: 8px; }
        .scard-value { font-size: 2.2rem; font-weight: 800; line-height: 1; color: var(--d-text); margin-bottom: 12px; }
        .scard-link {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: .8rem; font-weight: 600; color: var(--d-primary);
            text-decoration: none; transition: gap .2s;
        }
        .scard-link:hover { gap: 9px; color: var(--d-primary); }
        .scard-meta { font-size: .78rem; color: var(--d-muted); margin-bottom: 10px; }

        /* ── Panel card ── */
        .panel {
            background: var(--d-surface);
            border: 1px solid var(--d-border);
            border-radius: var(--d-radius);
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.06);
            overflow: hidden;
            margin-bottom: 24px;
        }
        .panel-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: 18px 24px;
            border-bottom: 1px solid var(--d-border);
            background: var(--d-surf2);
        }
        .panel-title {
            font-size: 1rem; font-weight: 700; color: var(--d-text);
            display: flex; align-items: center; gap: 10px; margin: 0;
        }
        .panel-title-icon {
            width: 32px; height: 32px; border-radius: 9px;
            display: flex; align-items: center; justify-content: center; font-size: 15px;
        }
        .panel-link {
            font-size: .8rem; font-weight: 600; color: var(--d-primary);
            text-decoration: none; display: flex; align-items: center; gap: 4px;
            padding: 6px 14px; border-radius: 8px;
            border: 1px solid #bfdbfe;
            background: #eff6ff;
            transition: all .2s;
        }
        .panel-link:hover { background: #dbeafe; color: var(--d-primary); }
        .panel-body { padding: 0; }

        /* ── Table ── */
        .dash-table { width: 100%; border-collapse: collapse; }
        .dash-table thead th {
            padding: 12px 20px; font-size: .75rem; font-weight: 700;
            color: var(--d-muted); letter-spacing: .06em; text-transform: uppercase;
            border-bottom: 1px solid var(--d-border); background: var(--d-surf2);
        }
        .dash-table tbody td {
            padding: 14px 20px; font-size: .88rem; color: var(--d-text);
            border-bottom: 1px solid var(--d-border);
        }
        .dash-table tbody tr:last-child td { border-bottom: none; }
        .dash-table tbody tr { transition: background .2s; }
        .dash-table tbody tr:hover { background: #f8fafc; }

        .type-pill {
            display: inline-flex; align-items: center;
            padding: 3px 10px; border-radius: 99px;
            font-size: .75rem; font-weight: 600;
        }
        .tp-cam  { background: rgba(59,130,246,.12);  color: #60a5fa; }
        .tp-emp  { background: rgba(6,182,212,.12);   color: var(--d-accent); }
        .tp-adm  { background: rgba(244,63,94,.12);   color: #fb7185; }

        .icon-btn {
            display: inline-flex; align-items: center; justify-content: center;
            width: 32px; height: 32px; border-radius: 8px;
            border: 1px solid var(--d-border); color: var(--d-muted);
            text-decoration: none; font-size: 14px; transition: all .2s;
        }
        .icon-btn:hover { color: var(--d-text); border-color: var(--d-border-h); background: rgba(59,130,246,.08); }
        .icon-btn-success:hover { color: #10b981; border-color: rgba(16,185,129,.4); background: rgba(16,185,129,.08); }
        .icon-btn-danger:hover  { color: #f43f5e; border-color: rgba(244,63,94,.4);  background: rgba(244,63,94,.08); }

        /* ── Activity ── */
        .activity-item {
            display: flex; align-items: flex-start; gap: 14px;
            padding: 16px 24px;
            border-bottom: 1px solid var(--d-border);
        }
        .activity-item:last-child { border-bottom: none; }
        .activity-dot {
            width: 36px; height: 36px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; flex-shrink: 0; margin-top: 2px;
        }
        .activity-content p { margin: 0; font-size: .88rem; color: var(--d-text); line-height: 1.4; }
        .activity-content small { font-size: .75rem; color: var(--d-muted); }
        .activity-item a.activity-link {
            text-decoration: none; color: inherit; display: flex; align-items: flex-start; gap: 14px; width: 100%;
        }
        .activity-item a.activity-link:hover p { color: var(--d-primary); }

        /* ── Admin ops ── */
        .ops-strip {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 28px;
        }
        .ops-chip {
            display: flex; flex-direction: column; gap: 4px;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid var(--d-border);
            background: var(--d-surface);
            text-decoration: none;
            transition: transform .2s, box-shadow .2s, border-color .2s;
            box-shadow: 0 2px 4px rgba(15,23,42,.04);
        }
        .ops-chip:hover { transform: translateY(-2px); border-color: var(--d-border-h); box-shadow: 0 8px 16px rgba(15,23,42,.06); }
        .ops-chip-label { font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--d-muted); }
        .ops-chip-value { font-size: 1.45rem; font-weight: 800; color: var(--d-text); line-height: 1; }
        .ops-chip-meta { font-size: .72rem; color: var(--d-muted); }
        .ops-chip.is-alert { border-color: #fecaca; background: linear-gradient(180deg, #fff5f5 0%, #fff 100%); }
        .ops-chip.is-alert .ops-chip-value { color: var(--d-danger); }
        .ops-chip.is-warn { border-color: #fde68a; background: linear-gradient(180deg, #fffbeb 0%, #fff 100%); }
        .ops-chip.is-warn .ops-chip-value { color: var(--d-warning); }
        .ops-chip.is-ok .ops-chip-value { color: var(--d-success); }

        .dash-grid-2 {
            display: grid;
            grid-template-columns: 1.15fr .85fr;
            gap: 24px;
            margin-bottom: 24px;
        }
        @media (max-width: 992px) {
            .dash-grid-2 { grid-template-columns: 1fr; }
        }

        .quick-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            padding: 16px;
        }
        .qa-btn {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid var(--d-border);
            background: var(--d-surf2);
            text-decoration: none;
            color: var(--d-text);
            font-size: .82rem;
            font-weight: 600;
            transition: all .2s;
        }
        .qa-btn:hover { background: #eff6ff; border-color: #bfdbfe; color: var(--d-primary); }
        .qa-btn i {
            width: 32px; height: 32px; border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            background: #fff; border: 1px solid var(--d-border); font-size: 14px;
        }

        .mission-row {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 14px 20px; border-bottom: 1px solid var(--d-border);
        }
        .mission-row:last-child { border-bottom: none; }
        .mission-row:hover { background: #f8fafc; }
        .mission-title { font-weight: 600; font-size: .88rem; margin: 0; color: var(--d-text); }
        .mission-meta { font-size: .75rem; color: var(--d-muted); margin: 2px 0 0; }
        .st-pill {
            display: inline-flex; align-items: center;
            padding: 3px 10px; border-radius: 99px;
            font-size: .72rem; font-weight: 700; white-space: nowrap;
        }
        .st-aberta { background: #dbeafe; color: #1d4ed8; }
        .st-andamento { background: #cffafe; color: #0e7490; }
        .st-concluida { background: #dcfce7; color: #15803d; }
        .st-outro { background: #f1f5f9; color: #475569; }

        .irreg-row {
            display: flex; align-items: flex-start; justify-content: space-between; gap: 10px;
            padding: 14px 20px; border-bottom: 1px solid var(--d-border);
        }
        .irreg-row:last-child { border-bottom: none; }
        .irreg-name { font-weight: 600; font-size: .88rem; margin: 0; }
        .irreg-meta { font-size: .75rem; color: var(--d-muted); margin: 2px 0 0; }

        .dash-hero-admin {
            background:
                radial-gradient(1200px 280px at 10% -40%, rgba(37,99,235,.10), transparent 60%),
                radial-gradient(900px 240px at 90% -20%, rgba(8,145,178,.08), transparent 55%);
            border-radius: 20px;
            padding: 4px 4px 0;
            margin-bottom: 8px;
        }

        .empty-soft {
            padding: 28px 24px; text-align: center; color: var(--d-muted); font-size: .9rem;
        }

        /* ── Reveal ── */
        .fade-up {
            opacity: 0; transform: translateY(20px);
            transition: opacity .6s ease, transform .6s ease;
        }
        .fade-up.show { opacity: 1; transform: translateY(0); }
        .d1{transition-delay:.05s} .d2{transition-delay:.1s} .d3{transition-delay:.15s}
        .d4{transition-delay:.2s}  .d5{transition-delay:.25s} .d6{transition-delay:.3s}

        @media (prefers-reduced-motion:reduce) { .fade-up{transition:none;opacity:1;transform:none;} }
        @media (max-width:576px) { .stat-grid { grid-template-columns: 1fr 1fr; } }
    </style>
</head>
<body>
    <?php include_once('includes/menu.php'); ?>

    <div class="container dash-wrap">

        <!-- Header -->
        <div class="dash-header fade-up">
            <div>
                <h1 class="dash-greeting">
                    Bem-vindo, <span><?php echo htmlspecialchars($usuario['nome']); ?></span>!
                </h1>
                <p class="dash-sub">
                    <?php
                    $today = date('l, d \d\e F \d\e Y');
                    $map = ['Monday'=>'Segunda','Tuesday'=>'Terça','Wednesday'=>'Quarta','Thursday'=>'Quinta','Friday'=>'Sexta','Saturday'=>'Sábado','Sunday'=>'Domingo'];
                    $months = ['January'=>'Janeiro','February'=>'Fevereiro','March'=>'Março','April'=>'Abril','May'=>'Maio','June'=>'Junho','July'=>'Julho','August'=>'Agosto','September'=>'Setembro','October'=>'Outubro','November'=>'Novembro','December'=>'Dezembro'];
                    foreach ($map    as $en=>$pt) $today = str_replace($en, $pt, $today);
                    foreach ($months as $en=>$pt) $today = str_replace($en, $pt, $today);
                    echo $today;
                    ?>
                    <?php if ($_SESSION['user_type'] === 'admin'): ?>
                        · Vista operacional da plataforma
                    <?php endif; ?>
                </p>
                <?php if ($_SESSION['user_type'] === 'caminhoneiro' && isset($usuario['disponibilidade'])): ?>
                    <?php
                    $disp = $usuario['disponibilidade'];
                    $cls  = $disp === 'disponivel' ? 'badge-green' : ($disp === 'ocupado' ? 'badge-yellow' : 'badge-red');
                    $lbl  = ucfirst($disp);
                    ?>
                    <div class="dash-badge <?php echo $cls; ?> mt-2">
                        <span class="dot"></span> <?php echo $lbl; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <?php if ($_SESSION['user_type'] === 'admin'): ?>
                    <div class="d-flex flex-wrap gap-2 justify-content-end">
                        <a href="<?php echo BASE_URL; ?>/pages/admin/contas-irregulares.php" class="dash-action-btn" style="background:#fff;border:1px solid var(--d-border);color:var(--d-text);box-shadow:0 2px 8px rgba(15,23,42,.06);">
                            <i class="bi bi-exclamation-octagon text-danger"></i> Irregulares
                            <?php if (!empty($stats['contas_irregulares'])): ?>
                                <span class="badge bg-danger"><?php echo (int)$stats['contas_irregulares']; ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="<?php echo BASE_URL; ?>/pages/admin/dashboard.php" class="dash-action-btn btn-danger-g">
                            <i class="bi bi-speedometer2"></i> Painel Admin
                        </a>
                    </div>
                <?php elseif ($_SESSION['user_type'] === 'empresa'): ?>
                    <a href="<?php echo BASE_URL; ?>/pages/contratante/nova-missao.php" class="dash-action-btn btn-primary-g">
                        <i class="bi bi-plus-circle"></i> Nova Missão
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stat Cards -->
        <div class="stat-grid">
        <?php if ($_SESSION['user_type'] === 'admin'): ?>
            <div class="scard scard-blue fade-up d1">
                <div class="scard-icon si-blue"><i class="bi bi-people"></i></div>
                <div class="scard-label">Total de Utilizadores</div>
                <div class="scard-value"><?php echo (int)$stats['total_usuarios']; ?></div>
                <div class="scard-meta">
                    Activos: <?php echo (int)($stats['usuarios_ativos'] ?? 0); ?> ·
                    Motoristas: <?php echo (int)$stats['total_caminhoneiros']; ?> ·
                    Empresas: <?php echo (int)$stats['total_empresas']; ?>
                    <?php if (!empty($stats['total_transportadores'])): ?>
                        · Transp.: <?php echo (int)$stats['total_transportadores']; ?>
                    <?php endif; ?>
                </div>
                <a href="<?php echo BASE_URL; ?>/pages/admin/usuarios.php" class="scard-link">Gerir <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="scard scard-amber fade-up d2">
                <div class="scard-icon si-amber"><i class="bi bi-person-plus"></i></div>
                <div class="scard-label">Pendentes de Aprovação</div>
                <div class="scard-value"><?php echo (int)$stats['usuarios_pendentes']; ?></div>
                <div class="scard-meta">Cadastros à espera de activação</div>
                <a href="<?php echo BASE_URL; ?>/pages/admin/usuarios.php?status=pendente" class="scard-link">Aprovar <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="scard scard-green fade-up d3">
                <div class="scard-icon si-green"><i class="bi bi-list-task"></i></div>
                <div class="scard-label">Missões na plataforma</div>
                <div class="scard-value"><?php echo (int)$stats['total_missoes']; ?></div>
                <div class="scard-meta">
                    Abertas: <?php echo (int)($stats['missoes_abertas'] ?? 0); ?> ·
                    Em curso: <?php echo (int)($stats['missoes_andamento'] ?? 0); ?>
                </div>
                <a href="<?php echo BASE_URL; ?>/pages/admin/missoes.php" class="scard-link">Ver missões <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="scard scard-cyan fade-up d4">
                <div class="scard-icon si-cyan"><i class="bi bi-check-circle"></i></div>
                <div class="scard-label">Missões Concluídas</div>
                <div class="scard-value"><?php echo (int)$stats['missoes_concluidas']; ?></div>
                <div class="scard-meta">Taxa de conclusão: <?php echo number_format((float)($stats['taxa_conclusao'] ?? 0), 1); ?>%</div>
                <a href="<?php echo BASE_URL; ?>/pages/admin/relatorios.php" class="scard-link">Relatórios <i class="bi bi-arrow-right"></i></a>
            </div>

        <?php elseif ($_SESSION['user_type'] === 'caminhoneiro'): ?>
            <div class="scard scard-blue fade-up d1">
                <div class="scard-icon si-blue"><i class="bi bi-truck"></i></div>
                <div class="scard-label">Missões Disponíveis</div>
                <div class="scard-value"><?php echo $stats['missoes_disponiveis']; ?></div>
                <a href="<?php echo BASE_URL; ?>/pages/caminhoneiro/missoes.php" class="scard-link">Ver todas <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="scard scard-green fade-up d2">
                <div class="scard-icon si-green"><i class="bi bi-send"></i></div>
                <div class="scard-label">Minhas Propostas</div>
                <div class="scard-value"><?php echo $stats['minhas_propostas']; ?></div>
                <a href="<?php echo BASE_URL; ?>/pages/caminhoneiro/propostas.php" class="scard-link">Ver todas <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="scard scard-amber fade-up d3">
                <div class="scard-icon si-amber"><i class="bi bi-clock"></i></div>
                <div class="scard-label">Em Andamento</div>
                <div class="scard-value"><?php echo $stats['missoes_andamento']; ?></div>
                <a href="<?php echo BASE_URL; ?>/pages/caminhoneiro/missoes.php?status=andamento" class="scard-link">Ver detalhes <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="scard scard-purple fade-up d4">
                <div class="scard-icon si-purple"><i class="bi bi-star"></i></div>
                <div class="scard-label">Avaliação Média</div>
                <div class="scard-value"><?php echo $stats['avaliacao_media'] ?: '—'; ?></div>
                <a href="<?php echo BASE_URL; ?>/pages/perfil.php" class="scard-link">Ver perfil <i class="bi bi-arrow-right"></i></a>
            </div>

        <?php else: ?>
            <div class="scard scard-blue fade-up d1">
                <div class="scard-icon si-blue"><i class="bi bi-list-task"></i></div>
                <div class="scard-label">Missões Activas</div>
                <div class="scard-value"><?php echo $stats['missoes_ativas']; ?></div>
                <a href="<?php echo BASE_URL; ?>/pages/contratante/missoes.php" class="scard-link">Gerir missões <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="scard scard-green fade-up d2">
                <div class="scard-icon si-green"><i class="bi bi-inbox"></i></div>
                <div class="scard-label">Propostas Recebidas</div>
                <div class="scard-value"><?php echo $stats['propostas_recebidas']; ?></div>
                <a href="<?php echo BASE_URL; ?>/pages/contratante/propostas.php" class="scard-link">Ver propostas <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="scard scard-cyan fade-up d3">
                <div class="scard-icon si-cyan"><i class="bi bi-check-circle"></i></div>
                <div class="scard-label">Missões Concluídas</div>
                <div class="scard-value"><?php echo $stats['missoes_concluidas']; ?></div>
                <a href="<?php echo BASE_URL; ?>/pages/contratante/missoes.php?status=concluida" class="scard-link">Ver histórico <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="scard scard-purple fade-up d4">
                <div class="scard-icon si-purple"><i class="bi bi-building"></i></div>
                <div class="scard-label">Perfil da Empresa</div>
                <div class="scard-value" style="font-size:1.1rem;line-height:1.3"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Empresa'); ?></div>
                <a href="<?php echo BASE_URL; ?>/pages/contratante/perfil.php" class="scard-link">Ver perfil <i class="bi bi-arrow-right"></i></a>
            </div>
        <?php endif; ?>
        </div>

        <?php if ($_SESSION['user_type'] === 'admin'): ?>
        <?php
            $opsDocs = (int)($stats['docs_pendentes'] ?? 0);
            $opsIrreg = (int)($stats['contas_irregulares'] ?? 0);
            $opsEmerg = (int)($stats['emergencias_abertas'] ?? 0);
            $opsDisp = (int)($stats['disputas_abertas'] ?? 0);
            $opsPrazo = (int)($stats['prazos_expirados'] ?? 0);
        ?>

        <!-- Operational pulse -->
        <div class="ops-strip fade-up d2">
            <a class="ops-chip <?php echo $opsDocs > 0 ? 'is-warn' : 'is-ok'; ?>"
               href="<?php echo BASE_URL; ?>/pages/admin/verificar-documentos.php?status=pendente">
                <span class="ops-chip-label">Documentos</span>
                <span class="ops-chip-value"><?php echo $opsDocs; ?></span>
                <span class="ops-chip-meta">à analisar</span>
            </a>
            <a class="ops-chip <?php echo $opsIrreg > 0 ? ($opsPrazo > 0 ? 'is-alert' : 'is-warn') : 'is-ok'; ?>"
               href="<?php echo BASE_URL; ?>/pages/admin/contas-irregulares.php">
                <span class="ops-chip-label">Irregulares</span>
                <span class="ops-chip-value"><?php echo $opsIrreg; ?></span>
                <span class="ops-chip-meta"><?php echo $opsPrazo > 0 ? $opsPrazo . ' prazo(s) expirado(s)' : 'sem docs OK'; ?></span>
            </a>
            <a class="ops-chip <?php echo $opsEmerg > 0 ? 'is-alert' : 'is-ok'; ?>"
               href="<?php echo BASE_URL; ?>/pages/admin/emergencias.php">
                <span class="ops-chip-label">Emergências</span>
                <span class="ops-chip-value"><?php echo $opsEmerg; ?></span>
                <span class="ops-chip-meta">abertas agora</span>
            </a>
            <a class="ops-chip <?php echo $opsDisp > 0 ? 'is-warn' : 'is-ok'; ?>"
               href="<?php echo BASE_URL; ?>/pages/admin/disputas.php">
                <span class="ops-chip-label">Disputas</span>
                <span class="ops-chip-value"><?php echo $opsDisp; ?></span>
                <span class="ops-chip-meta">em mediação</span>
            </a>
            <a class="ops-chip"
               href="<?php echo BASE_URL; ?>/pages/admin/missoes.php">
                <span class="ops-chip-label">Em curso</span>
                <span class="ops-chip-value"><?php echo (int)($stats['missoes_andamento'] ?? 0); ?></span>
                <span class="ops-chip-meta">missões activas</span>
            </a>
        </div>

        <div class="dash-grid-2">
            <div>
                <?php if (!empty($usuarios_pendentes)): ?>
                <div class="panel fade-up d2">
                    <div class="panel-head">
                        <h2 class="panel-title">
                            <span class="panel-title-icon si-amber" style="border-radius:9px;width:32px;height:32px;display:flex;align-items:center;justify-content:center;">
                                <i class="bi bi-person-plus" style="color:var(--d-warning)"></i>
                            </span>
                            Cadastros pendentes
                        </h2>
                        <a href="<?php echo BASE_URL; ?>/pages/admin/usuarios.php?status=pendente" class="panel-link">
                            Ver todos <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                    <div class="panel-body" style="overflow-x:auto;">
                        <table class="dash-table">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Tipo</th>
                                    <th>Email</th>
                                    <th>Registado</th>
                                    <th>Acções</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($usuarios_pendentes as $u): ?>
                                <tr>
                                    <td style="font-weight:600"><?php echo htmlspecialchars($u['nome']); ?></td>
                                    <td>
                                        <span class="type-pill <?php echo $u['tipo_usuario']==='caminhoneiro'?'tp-cam':'tp-emp'; ?>">
                                            <?php echo ucfirst($u['tipo_usuario']); ?>
                                        </span>
                                    </td>
                                    <td style="color:var(--d-muted)"><?php echo htmlspecialchars($u['email']); ?></td>
                                    <td style="color:var(--d-muted)"><?php echo date('d/m/Y', strtotime($u['data_registro'])); ?></td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <a href="<?php echo BASE_URL; ?>/pages/admin/ver-usuario.php?id=<?php echo $u['id']; ?>" class="icon-btn" title="Ver"><i class="bi bi-eye"></i></a>
                                            <a href="<?php echo BASE_URL; ?>/pages/admin/aprovar-usuario.php?id=<?php echo $u['id']; ?>" class="icon-btn icon-btn-success" title="Aprovar"><i class="bi bi-check-lg"></i></a>
                                            <a href="<?php echo BASE_URL; ?>/pages/admin/rejeitar-usuario.php?id=<?php echo $u['id']; ?>" class="icon-btn icon-btn-danger" title="Rejeitar"><i class="bi bi-x-lg"></i></a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <div class="panel fade-up d3">
                    <div class="panel-head">
                        <h2 class="panel-title">
                            <span class="panel-title-icon si-cyan" style="width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:9px;">
                                <i class="bi bi-truck" style="color:var(--d-accent)"></i>
                            </span>
                            Missões recentes
                        </h2>
                        <a href="<?php echo BASE_URL; ?>/pages/admin/missoes.php" class="panel-link">
                            Ver todas <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                    <div class="panel-body">
                        <?php if (empty($adminMissoesRecentes)): ?>
                            <div class="empty-soft">Ainda não há missões registadas.</div>
                        <?php else: ?>
                            <?php foreach ($adminMissoesRecentes as $m):
                                $st = $m['status'] ?? '';
                                $stCls = match ($st) {
                                    'aberta' => 'st-aberta',
                                    'em_andamento', 'em_transito', 'aceita' => 'st-andamento',
                                    'concluida' => 'st-concluida',
                                    default => 'st-outro',
                                };
                                $stLbl = str_replace('_', ' ', $st);
                                $rota = trim(($m['origem'] ?? '') . ' → ' . ($m['destino'] ?? ''), ' →');
                            ?>
                            <a class="mission-row text-decoration-none" href="<?php echo BASE_URL; ?>/pages/admin/ver-missao.php?id=<?php echo (int)$m['id']; ?>">
                                <div>
                                    <p class="mission-title"><?php echo htmlspecialchars($m['titulo'] ?: ('Missão #' . $m['id'])); ?></p>
                                    <p class="mission-meta">
                                        <?php echo htmlspecialchars($rota ?: 'Sem rota'); ?>
                                        · <?php echo !empty($m['data_criacao']) ? date('d/m/Y', strtotime($m['data_criacao'])) : '—'; ?>
                                    </p>
                                </div>
                                <span class="st-pill <?php echo $stCls; ?>"><?php echo htmlspecialchars(ucfirst($stLbl)); ?></span>
                            </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($adminIrregulares)): ?>
                <div class="panel fade-up d4">
                    <div class="panel-head">
                        <h2 class="panel-title">
                            <span class="panel-title-icon si-rose" style="width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:9px;">
                                <i class="bi bi-exclamation-octagon" style="color:var(--d-danger)"></i>
                            </span>
                            Contas irregulares
                        </h2>
                        <a href="<?php echo BASE_URL; ?>/pages/admin/contas-irregulares.php" class="panel-link">
                            Gerir <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                    <div class="panel-body">
                        <?php foreach ($adminIrregulares as $row):
                            $uu = $row['usuario'] ?? [];
                            $faltam = implode(', ', array_values($row['faltam_docs'] ?? [])) ?: 'docs em falta';
                        ?>
                        <div class="irreg-row">
                            <div>
                                <p class="irreg-name"><?php echo htmlspecialchars($uu['nome'] ?? 'Utilizador'); ?></p>
                                <p class="irreg-meta"><?php echo htmlspecialchars(mb_strimwidth($faltam, 0, 80, '…')); ?></p>
                            </div>
                            <div class="d-flex flex-column align-items-end gap-1">
                                <?php if (!empty($row['prazo_expirado'])): ?>
                                    <span class="st-pill" style="background:#fee2e2;color:#b91c1c;">Prazo expirado</span>
                                <?php elseif (!empty($row['prazo'])): ?>
                                    <span class="st-pill st-aberta">Até <?php echo date('d/m', strtotime($row['prazo'])); ?></span>
                                <?php else: ?>
                                    <span class="st-pill st-outro">Sem advertência</span>
                                <?php endif; ?>
                                <a class="scard-link" href="<?php echo BASE_URL; ?>/pages/admin/contas-irregulares.php">Advertir</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div>
                <div class="panel fade-up d3">
                    <div class="panel-head">
                        <h2 class="panel-title">
                            <span class="panel-title-icon si-blue" style="width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:9px;">
                                <i class="bi bi-lightning" style="color:var(--d-primary)"></i>
                            </span>
                            Ações rápidas
                        </h2>
                    </div>
                    <div class="quick-actions">
                        <a class="qa-btn" href="<?php echo BASE_URL; ?>/pages/admin/usuarios.php?status=pendente">
                            <i class="bi bi-person-check"></i> Aprovar cadastros
                        </a>
                        <a class="qa-btn" href="<?php echo BASE_URL; ?>/pages/admin/verificar-documentos.php?status=pendente">
                            <i class="bi bi-file-earmark-check"></i> Verificar docs
                        </a>
                        <a class="qa-btn" href="<?php echo BASE_URL; ?>/pages/admin/contas-irregulares.php">
                            <i class="bi bi-megaphone"></i> Advertir / remover
                        </a>
                        <a class="qa-btn" href="<?php echo BASE_URL; ?>/pages/admin/emergencias.php">
                            <i class="bi bi-exclamation-triangle"></i> Emergências
                        </a>
                        <a class="qa-btn" href="<?php echo BASE_URL; ?>/pages/shared/centro-operacoes.php" target="_blank" rel="noopener">
                            <i class="bi bi-radar"></i> Centro de ops
                        </a>
                        <a class="qa-btn" href="<?php echo BASE_URL; ?>/pages/admin/dashboard-executivo.php">
                            <i class="bi bi-graph-up"></i> Executivo
                        </a>
                        <a class="qa-btn" href="<?php echo BASE_URL; ?>/pages/admin/relatorios.php">
                            <i class="bi bi-bar-chart"></i> Relatórios
                        </a>
                        <a class="qa-btn" href="<?php echo BASE_URL; ?>/pages/admin/dashboard.php">
                            <i class="bi bi-speedometer2"></i> Painel completo
                        </a>
                    </div>
                </div>

                <div class="panel fade-up d4">
                    <div class="panel-head">
                        <h2 class="panel-title">
                            <span class="panel-title-icon si-blue" style="width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:9px;">
                                <i class="bi bi-activity" style="color:var(--d-primary)"></i>
                            </span>
                            Últimas actividades
                        </h2>
                    </div>
                    <div class="panel-body">
                        <?php if (empty($adminActividades)): ?>
                            <div class="empty-soft">Sem actividade recente para mostrar.</div>
                        <?php else: ?>
                            <?php foreach ($adminActividades as $act):
                                $cor = $act['cor'] ?? 'blue';
                                $si = 'si-' . $cor;
                                $url = $act['url'] ?? null;
                            ?>
                            <div class="activity-item">
                                <?php if ($url): ?><a class="activity-link" href="<?php echo htmlspecialchars($url); ?>"><?php endif; ?>
                                    <div class="activity-dot <?php echo $si; ?>">
                                        <i class="bi <?php echo htmlspecialchars($act['icon'] ?? 'bi-circle'); ?>"></i>
                                    </div>
                                    <div class="activity-content">
                                        <p><?php echo htmlspecialchars($act['titulo'] ?? ''); ?></p>
                                        <small><?php echo htmlspecialchars($act['quando'] ?? ''); ?></small>
                                    </div>
                                <?php if ($url): ?></a><?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php else: ?>
        <!-- Recent Activity (outros perfis) -->
        <div class="panel fade-up d3">
            <div class="panel-head">
                <h2 class="panel-title">
                    <span class="panel-title-icon si-blue" style="width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:9px;">
                        <i class="bi bi-activity" style="color:var(--d-primary)"></i>
                    </span>
                    Últimas Actividades
                </h2>
            </div>
            <div class="panel-body">
                <div class="activity-item">
                    <div class="activity-dot si-blue" style="width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;">
                        <i class="bi bi-truck" style="color:var(--d-primary)"></i>
                    </div>
                    <div class="activity-content">
                        <p>Nova missão disponível: Transporte de carga para Maputo</p>
                        <small>Há 2 horas</small>
                    </div>
                </div>
                <div class="activity-item">
                    <div class="activity-dot si-green" style="width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;">
                        <i class="bi bi-check-circle" style="color:var(--d-success)"></i>
                    </div>
                    <div class="activity-content">
                        <p>Missão concluída: Entrega em Beira</p>
                        <small>Há 5 horas</small>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /dash-wrap -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
    <script>
    (function () {
        var prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion:reduce)').matches;
        var els = document.querySelectorAll('.fade-up');
        if (prefersReduced || !('IntersectionObserver' in window)) {
            els.forEach(function (el) { el.classList.add('show'); });
            return;
        }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) {
                if (e.isIntersecting) { e.target.classList.add('show'); io.unobserve(e.target); }
            });
        }, { threshold: 0.1 });
        els.forEach(function (el) { io.observe(el); });
    })();
    </script>
</body>
</html>