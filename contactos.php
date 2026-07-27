<?php
session_start();
require_once __DIR__ . '/config/app.php';

$pageTitle = 'Contactos | TrackMoz Moçambique';
$pageDescription = 'Contacte a equipa TrackMoz. Suporte para empresas, transportadoras e camionistas em Moçambique.';
$activeNav = 'contactos';
require __DIR__ . '/includes/public-header.php';
?>
<article class="tm-pub-card">
    <h1>Contactos</h1>
    <p class="lead">Fale connosco para demos, parcerias ou suporte à plataforma.</p>

    <div class="tm-contact-grid">
        <div class="tm-contact-item">
            <strong><i class="bi bi-envelope"></i> Email</strong>
            <a href="mailto:contacto@trackmoz.mz">contacto@trackmoz.mz</a>
        </div>
        <div class="tm-contact-item">
            <strong><i class="bi bi-geo-alt"></i> Localização</strong>
            <span>Maputo e Tete, Moçambique</span>
        </div>
        <div class="tm-contact-item">
            <strong><i class="bi bi-clock"></i> Disponibilidade</strong>
            <span>Suporte online — dias úteis</span>
        </div>
    </div>

    <h2>Quer experimentar o sistema?</h2>
    <p>Crie uma conta gratuita e explore o fluxo de fretes, missões e modo condução.</p>
    <p class="mb-0">
        <a class="tm-pub-btn primary" href="<?php echo BASE_URL; ?>/pages/cadastro.php">Criar conta</a>
        <a class="tm-pub-btn ghost ms-2" href="<?php echo BASE_URL; ?>/pages/login.php">Já tenho conta</a>
    </p>
</article>
<?php require __DIR__ . '/includes/public-footer.php'; ?>
