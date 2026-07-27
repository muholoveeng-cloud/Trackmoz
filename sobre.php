<?php
session_start();
require_once __DIR__ . '/config/app.php';

$pageTitle = 'Sobre o TrackMoz | Plataforma de Fretes em Moçambique';
$pageDescription = 'Conheça o TrackMoz: plataforma digital que moderniza o transporte rodoviário de cargas em Moçambique, ligando empresas, transportadoras e camionistas.';
$pageCanonical = null;
$activeNav = 'sobre';
require __DIR__ . '/includes/public-header.php';
?>
<article class="tm-pub-card">
    <h1>Sobre o TrackMoz</h1>
    <p class="lead">Somos uma plataforma inteligente de gestão de fretes e logística que formaliza e digitaliza o transporte rodoviário em Moçambique.</p>

    <h2>A nossa missão</h2>
    <p>Reduzir viagens vazias, aumentar a transparência entre empresas e transportadores, e dar aos camionistas ferramentas práticas de condução, GPS e confirmação de entrega.</p>

    <h2>Para quem é</h2>
    <ul>
        <li><strong>Empresas</strong> — publicam fretes, gerem contratos e acompanham entregas.</li>
        <li><strong>Transportadoras</strong> — gerem frota, motoristas e propostas a missões.</li>
        <li><strong>Camionistas</strong> — recebem missões, usam o modo condução com GPS e confirmam entregas.</li>
    </ul>

    <h2>Como encontrar cargas em Moçambique?</h2>
    <p>Com o TrackMoz, empresas publicam missões de frete e transportadoras ou camionistas acedem a oportunidades verificadas — com documentação, rastreio e histórico confiável.</p>

    <h2>Projecto</h2>
    <p>TrackMoz é um projecto desenvolvido por Engenheiro Emilton Muholove, com foco em soluções reais para o transporte rodoviário nacional.</p>

    <p class="mt-4 mb-0">
        <a class="tm-pub-btn primary" href="<?php echo BASE_URL; ?>/pages/cadastro.php">Criar conta gratuita</a>
        <a class="tm-pub-btn ghost ms-2" href="<?php echo BASE_URL; ?>/funcionalidades.php">Ver funcionalidades</a>
    </p>
</article>
<?php require __DIR__ . '/includes/public-footer.php'; ?>
