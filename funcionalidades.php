<?php
$pageTitle = 'Funcionalidades | TrackMoz — Gestão de Fretes e Logística';
$pageDescription = 'Gestão de fretes, contratos digitais, rastreamento GPS, OTP de entrega, parcerias e modo condução. Conheça as funcionalidades do TrackMoz.';
$activeNav = 'funcionalidades';
require __DIR__ . '/includes/public-header.php';
?>
<article class="tm-pub-card">
    <h1>Funcionalidades</h1>
    <p class="lead">Tudo o que precisa para gerir transporte de cargas de forma eficiente — numa aplicação web e PWA.</p>

    <h2>Gestão de missões e fretes</h2>
    <p>Publique cargas com origem, destino, prazos e valores. Acompanhe o ciclo completo desde a atribuição até à entrega confirmada.</p>

    <h2>Contratos digitais e parcerias</h2>
    <p>Formalize relações entre empresas e transportadoras com histórico e documentação centralizada.</p>

    <h2>Rastreamento GPS e modo condução</h2>
    <p>O camionista usa o modo condução com localização em tempo real, estados de viagem e suporte offline para continuar a operar sem rede.</p>

    <h2>Confirmação segura de entrega</h2>
    <p>OTP, prova fotográfica e registo de quem recebeu — para reduzir litígios e aumentar confiança.</p>

    <h2>Frota, documentos e emergências</h2>
    <ul>
        <li>Gestão de veículos e documentação</li>
        <li>Alertas e emergências em viagem</li>
        <li>Painéis para empresa, transportadora, motorista e administração</li>
    </ul>

    <h2>Perguntas frequentes</h2>
    <div class="tm-faq">
        <details>
            <summary>O TrackMoz é uma aplicação para fretes?</summary>
            <p>Sim. É um sistema de transporte rodoviário e gestão de fretes feito para Moçambique, acessível no browser e instalável como app (PWA).</p>
        </details>
        <details>
            <summary>Como encontrar cargas em Moçambique?</summary>
            <p>Crie conta como transportadora ou camionista, complete o perfil e aceda às missões publicadas por empresas na plataforma.</p>
        </details>
        <details>
            <summary>Preciso de instalar algo?</summary>
            <p>Pode usar no browser. Em dispositivos compatíveis, pode instalar o TrackMoz como aplicação a partir do próprio site.</p>
        </details>
    </div>

    <p class="mt-4 mb-0">
        <a class="tm-pub-btn primary" href="<?php echo BASE_URL; ?>/pages/cadastro.php">Começar agora</a>
    </p>
</article>
<?php require __DIR__ . '/includes/public-footer.php'; ?>
