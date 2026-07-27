<?php
/**
 * Layout parcial — páginas públicas institucionais.
 * Expecta: $pageTitle, $pageDescription, $page Canonical opcional, $activeNav
 */
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../config/app.php';
}
require_once __DIR__ . '/seo.php';

$seoTitle = $pageTitle ?? 'TrackMoz';
$seoDesc = $pageDescription ?? tmz_seo_defaults()['description'];
$seoCanon = $pageCanonical ?? null;
$activeNav = $activeNav ?? '';
$homeHref = rtrim((string)BASE_URL, '/') . '/index.php';
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    tmz_seo_render_head([
        'title' => $seoTitle,
        'description' => $seoDesc,
        'canonical' => $seoCanon,
        'type' => 'website',
    ], true);
    include_once __DIR__ . '/pwa-head.php';
    ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/public-pages.css?v=1">
</head>
<body class="tm-public">
<nav class="tm-pub-nav">
    <div class="container d-flex align-items-center justify-content-between gap-3">
        <a href="<?php echo htmlspecialchars($homeHref); ?>" class="flex-shrink-0">
            <img class="tm-pub-logo" src="<?php echo BASE_URL; ?>/assets/img/Logo_sem_background.png" alt="TrackMoz">
        </a>
        <ul class="tm-pub-links d-none d-md-flex">
            <li><a class="<?php echo $activeNav === 'inicio' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($homeHref); ?>">Início</a></li>
            <li><a class="<?php echo $activeNav === 'sobre' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/sobre.php">Sobre</a></li>
            <li><a class="<?php echo $activeNav === 'funcionalidades' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/funcionalidades.php">Funcionalidades</a></li>
            <li><a class="<?php echo $activeNav === 'contactos' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/contactos.php">Contactos</a></li>
        </ul>
        <div class="d-flex gap-2 flex-shrink-0">
            <a class="tm-pub-btn ghost" href="<?php echo BASE_URL; ?>/pages/login.php">Entrar</a>
            <a class="tm-pub-btn primary" href="<?php echo BASE_URL; ?>/pages/cadastro.php">Criar conta</a>
        </div>
    </div>
</nav>
<main class="tm-pub-main">
    <div class="container">
