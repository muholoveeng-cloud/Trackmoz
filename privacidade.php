<?php
session_start();
require_once __DIR__ . '/config/app.php';

$pageTitle = 'Política de Privacidade | TrackMoz';
$pageDescription = 'Como o TrackMoz trata dados pessoais, localização GPS e documentos de utilizadores em Moçambique.';
$activeNav = '';
require __DIR__ . '/includes/public-header.php';
?>
<article class="tm-pub-card">
    <h1>Política de Privacidade</h1>
    <p class="lead">Última actualização: <?php echo date('d/m/Y'); ?>. Explicamos que dados recolhemos e para que os usamos.</p>

    <h2>1. Responsável</h2>
    <p>TrackMoz — contacto: <a href="mailto:contacto@trackmoz.mz">contacto@trackmoz.mz</a>.</p>

    <h2>2. Dados que recolhemos</h2>
    <ul>
        <li>Dados de conta: nome, email, telefone, tipo de perfil.</li>
        <li>Documentos operacionais necessários à verificação (ex.: carta, documentos de empresa/veículo).</li>
        <li>Dados de utilização: páginas visitadas, eventos de sessão (analytics interno).</li>
        <li>Localização GPS durante missões activas no modo condução (para rastreabilidade da carga).</li>
    </ul>

    <h2>3. Finalidades</h2>
    <p>Prestação do serviço, segurança, cumprimento de obrigações operacionais entre as partes, melhoria da plataforma e, quando configurado, estatísticas agregadas (ex.: Google Analytics).</p>

    <h2>4. Partilha</h2>
    <p>Dados relevantes de uma missão podem ser visíveis às partes envolvidas (empresa, transportadora, motorista). Não vendemos dados pessoais a terceiros.</p>

    <h2>5. Conservação e segurança</h2>
    <p>Aplicamos medidas razoáveis de segurança técnica. Os dados são conservados enquanto a conta estiver activa e pelo período necessário a obrigações legais ou disputas.</p>

    <h2>6. Os seus direitos</h2>
    <p>Pode solicitar acesso, correcção ou eliminação de dados de conta através do email de contacto, sujeito a obrigações legais de conservação.</p>

    <h2>7. Cookies</h2>
    <p>Usamos cookies/sessão essenciais ao login e funcionamento da app. Cookies analíticos só são activados se configurar Google Analytics.</p>

    <h2>8. Contacto</h2>
    <p>Para questões de privacidade: <a href="mailto:contacto@trackmoz.mz">contacto@trackmoz.mz</a>.</p>
</article>
<?php require __DIR__ . '/includes/public-footer.php'; ?>
