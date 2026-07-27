<?php
session_start();
require_once __DIR__ . '/config/app.php';

$pageTitle = 'Termos de Utilização | TrackMoz';
$pageDescription = 'Termos de utilização da plataforma TrackMoz — regras de uso para empresas, transportadoras e camionistas.';
$activeNav = '';
require __DIR__ . '/includes/public-header.php';
?>
<article class="tm-pub-card">
    <h1>Termos de Utilização</h1>
    <p class="lead">Última actualização: <?php echo date('d/m/Y'); ?>. Ao usar o TrackMoz, aceita estes termos.</p>

    <h2>1. Objecto</h2>
    <p>O TrackMoz é uma plataforma digital de gestão de fretes, contratos, rastreamento e operações logísticas. O serviço é prestado «tal como está», sujeito a melhorias contínuas.</p>

    <h2>2. Contas e responsabilidade</h2>
    <ul>
        <li>O utilizador é responsável pela exactidão dos dados e documentos submetidos.</li>
        <li>Credenciais de acesso são pessoais e intransmissíveis.</li>
        <li>Contas podem ser suspensas em caso de fraude, abuso ou documentação irregular.</li>
    </ul>

    <h2>3. Uso aceitável</h2>
    <p>É proibido utilizar a plataforma para actividades ilícitas, interferir na segurança do sistema, ou publicar informação falsa sobre cargas, veículos ou motoristas.</p>

    <h2>4. Missões e contratos</h2>
    <p>As relações comerciais entre empresas, transportadoras e camionistas são da responsabilidade das partes. O TrackMoz facilita a gestão digital, mas não substitui obrigações legais do transporte rodoviário em Moçambique.</p>

    <h2>5. Propriedade intelectual</h2>
    <p>Marca, interface, código e conteúdos do TrackMoz são protegidos. Não é permitido copiar ou redistribuir sem autorização.</p>

    <h2>6. Limitação de responsabilidade</h2>
    <p>O TrackMoz não se responsabiliza por perdas decorrentes de falhas de rede, GPS, decisões comerciais entre utilizadores, ou indisponibilidade temporária do serviço.</p>

    <h2>7. Alterações</h2>
    <p>Podemos actualizar estes termos. A versão publicada nesta página é a vigente.</p>

    <h2>8. Contacto</h2>
    <p>Dúvidas: <a href="mailto:contacto@trackmoz.mz">contacto@trackmoz.mz</a>.</p>
</article>
<?php require __DIR__ . '/includes/public-footer.php'; ?>
