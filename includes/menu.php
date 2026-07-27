<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$usuario = null;
$notificacoes_nao_lidas = 0;
$mensagens_nao_lidas    = 0;
$__pend_parcerias       = 0;

if (isset($_SESSION['user_id'])) {
    include_once(__DIR__ . '/../config/app.php');
    include_once(__DIR__ . '/../config/database.php');

    $stmt = $conn->prepare("SELECT * FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("SELECT COUNT(*) FROM notificacoes WHERE usuario_id = :id AND lida = 0");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $notificacoes_nao_lidas = (int)$stmt->fetchColumn();

    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM mensagens WHERE destinatario_id = :id AND lida = 0");
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $mensagens_nao_lidas = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('menu.php mensagens.lida: ' . $e->getMessage());
        $mensagens_nao_lidas = 0;
    }

    // Parcerias pendentes (transportador)
    if (($_SESSION['user_type'] ?? '') === 'transportador') {
        try {
            $s = $conn->prepare(
                "SELECT COUNT(*) FROM parcerias
                 WHERE transportador_id = :id
                   AND status IN ('pedido_enviado','em_negociacao','aguardando_aprovacao_transportador','pendente')"
            );
            $s->execute([':id' => $_SESSION['user_id']]);
            $__pend_parcerias = (int)$s->fetchColumn();
        } catch (Throwable $e) {}
    }
}

if (!isset($_SESSION['user_id'])) {
    include_once(__DIR__ . '/../config/app.php');
    header('Location: ' . BASE_URL . '/pages/login.php');
    exit;
}

$current_page = basename($_SERVER['PHP_SELF']);
$user_type    = $_SESSION['user_type'] ?? '';
$nome_usuario = $usuario['nome'] ?? 'Utilizador';
$inicial      = mb_strtoupper(mb_substr($nome_usuario, 0, 1));
$perfil_url   = match ($user_type) {
    'caminhoneiro'  => BASE_URL . '/pages/caminhoneiro/perfil.php',
    'transportador' => BASE_URL . '/pages/transportador/perfil.php',
    'empresa'       => BASE_URL . '/pages/contratante/perfil.php',
    'admin'         => BASE_URL . '/pages/admin/perfil.php',
    default         => BASE_URL . '/pages/perfil.php',
};

/** URL da foto/logo do utilizador autenticado (ou null). */
$fotoPerfilUrl = null;
$fpRaw = trim((string)($usuario['foto_perfil'] ?? ''));
if ($fpRaw !== '') {
    if (preg_match('#^https?://#i', $fpRaw)) {
        $fotoPerfilUrl = $fpRaw;
    } elseif (str_contains(str_replace('\\', '/', $fpRaw), '/')) {
        $fotoPerfilUrl = BASE_URL . '/' . ltrim(str_replace('\\', '/', $fpRaw), '/');
    } else {
        $subdir = is_file(upload_path('perfil', $fpRaw)) ? 'perfil'
            : (is_file(upload_path('logos', $fpRaw)) ? 'logos' : 'perfil');
        $fotoPerfilUrl = upload_url($subdir, $fpRaw);
    }
}
// Fallback: logo da empresa/transportadora se não houver foto_perfil
if ($fotoPerfilUrl === null && in_array($user_type, ['empresa', 'transportador'], true) && isset($conn)) {
    try {
        $tabelaLogo = $user_type === 'empresa' ? 'perfil_empresa' : 'perfil_transportador';
        $colLogo = 'logo_empresa';
        if (table_has_column($conn, $tabelaLogo, $colLogo)) {
            $stLogo = $conn->prepare("SELECT {$colLogo} FROM {$tabelaLogo} WHERE usuario_id = :id LIMIT 1");
            $stLogo->execute([':id' => (int)$_SESSION['user_id']]);
            $logoFile = trim((string)($stLogo->fetchColumn() ?: ''));
            if ($logoFile !== '') {
                $fotoPerfilUrl = str_contains($logoFile, '/')
                    ? (BASE_URL . '/' . ltrim($logoFile, '/'))
                    : upload_url('logos', $logoFile);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
}

if (!function_exists('tmz_menu_avatar_html')) {
    function tmz_menu_avatar_html(string $inicial, ?string $fotoUrl, string $extraStyle = ''): string
    {
        $styleAttr = $extraStyle !== '' ? ' style="' . htmlspecialchars($extraStyle, ENT_QUOTES, 'UTF-8') . '"' : '';
        if ($fotoUrl) {
            $iniJs = htmlspecialchars($inicial, ENT_QUOTES, 'UTF-8');
            return '<div class="tm-avatar tm-avatar-photo"' . $styleAttr . '>'
                . '<img src="' . htmlspecialchars($fotoUrl, ENT_QUOTES, 'UTF-8') . '" alt=""'
                . ' onerror="var p=this.parentElement;this.remove();if(p){p.classList.remove(\'tm-avatar-photo\');p.textContent=\'' . $iniJs . '\';}">'
                . '</div>';
        }
        return '<div class="tm-avatar"' . $styleAttr . '>'
            . htmlspecialchars($inicial, ENT_QUOTES, 'UTF-8')
            . '</div>';
    }
}

if (!function_exists('nav_active')) {
    function nav_active(array $pages): string {
        global $current_page;
        return in_array($current_page, $pages, true) ? 'active' : '';
    }
}

$__adminDocs = 0;
$__adminUsersPend = 0;
$__adminIrregulares = 0;
$__adminAtencaoTotal = 0;
if ($user_type === 'admin' && isset($conn)) {
    try {
        require_once __DIR__ . '/admin-atencao-helpers.php';
        $__atencao = admin_atencao_resumo($conn);
        $__adminDocs = (int)($__atencao['contagens']['docs_pendentes'] ?? 0);
        $__adminUsersPend = (int)($__atencao['contagens']['usuarios_pendentes'] ?? 0);
        $__adminIrregulares = (int)($__atencao['contagens']['contas_irregulares'] ?? 0);
        $__adminAtencaoTotal = (int)($__atencao['total'] ?? 0);
        if (empty($_SESSION['_admin_lembretes_sync']) || (time() - (int)$_SESSION['_admin_lembretes_sync']) > 120) {
            admin_sincronizar_lembretes($conn, (int)$_SESSION['user_id']);
            $_SESSION['_admin_lembretes_sync'] = time();
            $stmt = $conn->prepare('SELECT COUNT(*) FROM notificacoes WHERE usuario_id = :id AND lida = 0');
            $stmt->execute([':id' => $_SESSION['user_id']]);
            $notificacoes_nao_lidas = (int)$stmt->fetchColumn();
        }
    } catch (Throwable $e) {
        error_log('menu admin atencao: ' . $e->getMessage());
    }
}

$roleLabel = match ($user_type) {
    'admin' => 'Admin',
    'transportador' => 'Transportador',
    'caminhoneiro' => 'Motorista',
    default => 'Empresa',
};
$roleChipClass = match ($user_type) {
    'admin' => 'bg-danger text-white',
    'transportador' => 'bg-success text-white',
    'caminhoneiro' => 'bg-primary text-white',
    default => 'bg-info text-white',
};
?>
<style>
.tm-nav {
    background: #fff; border-bottom: 1px solid #e2e8f0; min-height: 56px;
    position: sticky; top: 0; z-index: 1030 !important;
    box-shadow: 0 1px 8px rgba(15,23,42,.06);
    overflow: visible !important;
}
.navbar.tm-nav { z-index: 1030 !important; overflow: visible !important; }
.tm-nav > .container-fluid {
    display: flex; align-items: center; gap: .4rem;
    min-height: 56px; flex-wrap: nowrap;
    overflow: visible !important;
}
.tm-brand img { height: 28px; width: auto; }
.tm-nav .navbar-nav .nav-link,
.tm-nav .nav-link {
    color: #64748b; font-size: .8rem; font-weight: 500;
    padding: 6px 10px; border-radius: 8px;
    display: inline-flex; align-items: center; gap: 5px;
    white-space: nowrap; transition: color .15s, background .15s;
}
.tm-nav .navbar-nav .nav-link:hover,
.tm-nav .nav-link:hover { color: #2563eb; background: #f1f5f9; }
.tm-nav .navbar-nav .nav-link.active,
.tm-nav .nav-link.active { color: #2563eb; background: #eff6ff; font-weight: 600; }
.tm-nav .nav-link .bi { font-size: .9rem; }
.role-chip {
    font-size: .62rem; padding: 2px 7px; border-radius: 20px;
    font-weight: 600; letter-spacing: .03em; text-transform: uppercase;
}
.tm-icon-btn {
    position: relative; width: 36px; height: 36px; border-radius: 9px; border: none;
    background: #f1f5f9; color: #64748b;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 1.05rem; text-decoration: none; flex-shrink: 0;
}
.tm-icon-btn:hover { background: #e2e8f0; color: #2563eb; }
.tm-icon-btn .dot {
    position: absolute; top: 4px; right: 4px; width: 8px; height: 8px;
    border-radius: 50%; background: #f43f5e; border: 2px solid #fff;
}
.tm-icon-btn .cnt {
    position: absolute; top: -4px; right: -4px;
    font-size: .6rem; min-width: 16px; height: 16px; border-radius: 8px;
    background: #ef4444; color: #fff; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    padding: 0 3px; border: 2px solid #fff;
}
.tm-avatar {
    width: 32px; height: 32px; border-radius: 50%;
    background: linear-gradient(135deg, #2563eb, #1e40af);
    color: #fff; font-size: .8rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    overflow: hidden;
}
.tm-avatar.tm-avatar-photo {
    background: #e2e8f0;
    border: 1px solid #e2e8f0;
    padding: 0;
}
.tm-avatar.tm-avatar-photo img {
    width: 100%; height: 100%;
    object-fit: cover;
    display: block;
}
.tm-user-btn {
    background: #f1f5f9; border: none; border-radius: 9px;
    padding: 4px 8px 4px 4px; color: #334155;
    font-size: .78rem; font-weight: 500;
    display: inline-flex; align-items: center; gap: 6px; max-width: 100%;
}
.tm-user-btn:hover { background: #e2e8f0; color: #0f172a; }
.tm-user-btn .bi-chevron-down { font-size: .65rem; opacity: .6; }
.tm-right-actions {
    display: flex; align-items: center; gap: .35rem;
    flex-shrink: 0; margin-left: auto;
}
@media (max-width: 575.98px) {
    .tm-brand img { height: 24px; }
    .tm-icon-btn { width: 40px; height: 40px; }
    .tm-user-btn { padding: 4px; }
    .tm-user-btn .bi-chevron-down { display: none; }
}
.tm-nav .dropdown-menu {
    border: 1px solid #e8ecf0; border-radius: 12px;
    box-shadow: 0 8px 30px rgba(0,0,0,.12); padding: 6px;
    min-width: 220px; margin-top: 6px !important;
    max-height: min(70vh, 440px); overflow-y: auto;
    z-index: 2000;
}
.tm-nav .dropdown-menu.show {
    display: block !important;
}
.tm-nav .dropdown-menu.tm-drop-fixed {
    position: fixed !important;
    inset: auto auto auto auto;
    margin: 0 !important;
    transform: none !important;
}
.tm-nav .dropdown-item {
    border-radius: 8px; font-size: .83rem; padding: 8px 12px;
    display: flex; align-items: center; gap: 8px; color: #374151;
}
.tm-nav .dropdown-item:hover { background: #f0f4ff; color: #0d6efd; }
.tm-nav .dropdown-item.text-danger:hover { background: #fff5f5; color: #dc2626; }
.tm-nav .dropdown-item.active { background: #eff6ff; color: #2563eb; font-weight: 600; }
.tm-toggler {
    background: #f1f5f9; border: none; border-radius: 8px;
    width: 40px; height: 36px; color: #64748b; font-size: 1.2rem;
    display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.tm-toggler:hover { background: #e2e8f0; color: #0f172a; }
.tm-toggler:focus { box-shadow: none; outline: none; }
.tm-offcanvas { width: min(88vw, 320px) !important; }
.tm-offcanvas .offcanvas-body { padding: 0; display: flex; flex-direction: column; height: 100%; }
.tm-off-scroll { flex: 1; overflow-y: auto; -webkit-overflow-scrolling: touch; padding: .75rem; }
.tm-off-link {
    display: flex; align-items: center; gap: .65rem;
    padding: .85rem 1rem; border-radius: 10px; margin-bottom: .15rem;
    color: #334155; text-decoration: none; font-weight: 500; font-size: .95rem;
}
.tm-off-link:hover, .tm-off-link.active { background: #eff6ff; color: #2563eb; }
.tm-off-link .bi { font-size: 1.1rem; width: 1.4rem; text-align: center; }
.tm-off-section {
    font-size: .68rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .06em; color: #94a3b8; padding: .75rem 1rem .35rem;
}
.tm-off-footer {
    border-top: 1px solid #e2e8f0; padding: .85rem 1rem;
    background: #f8fafc; flex-shrink: 0;
}
.tm-off-footer .btn { border-radius: 10px; }
.tm-bottom-nav {
    position: fixed; left: 0; right: 0; bottom: 0; z-index: 1040;
    background: #fff; border-top: 1px solid #e2e8f0;
    display: flex; justify-content: space-around;
    padding: .35rem .25rem calc(.35rem + env(safe-area-inset-bottom));
    box-shadow: 0 -4px 20px rgba(15,23,42,.08);
}
.tm-bottom-nav a {
    flex: 1; text-align: center; text-decoration: none; color: #64748b;
    font-size: .62rem; font-weight: 600; padding: .35rem .1rem; border-radius: 10px;
    display: flex; flex-direction: column; align-items: center; gap: 2px;
    position: relative; min-width: 0;
}
.tm-bottom-nav a .bi { font-size: 1.25rem; line-height: 1; }
.tm-bottom-nav a.active { color: #2563eb; background: #eff6ff; }
.tm-bottom-nav a .badge-dot {
    position: absolute; top: 2px; right: calc(50% - 14px);
    width: 8px; height: 8px; border-radius: 50%; background: #ef4444;
}
body.tm-has-bottom-nav { padding-bottom: calc(64px + env(safe-area-inset-bottom)); }
.tm-nav-desktop {
    display: none; flex: 1 1 auto; min-width: 0;
    align-items: center; gap: .15rem;
    overflow: visible;
}
.tm-nav-desktop .dropdown { position: relative; }
.tm-nav .dropdown-menu { z-index: 1080; }
.tm-nav-desktop .nav-link.dropdown-toggle {
    border: none; background: transparent; cursor: pointer;
}
@media (min-width: 992px) {
    .tm-toggler { display: none !important; }
    .tm-nav-desktop { display: flex !important; }
}
@media (max-width: 991.98px) {
    .tm-nav-desktop { display: none !important; }
}
</style>

<nav class="navbar tm-nav">
<div class="container-fluid px-2 px-sm-3">

    <button class="tm-toggler" type="button"
            data-bs-toggle="offcanvas" data-bs-target="#tmOffcanvas"
            aria-controls="tmOffcanvas" aria-label="Abrir menu">
        <i class="bi bi-list"></i>
    </button>

    <a class="navbar-brand tm-brand d-flex align-items-center gap-2 mb-0 me-1" href="<?php echo BASE_URL; ?>/index.php">
        <img src="<?php echo BASE_URL; ?>/assets/img/Logo_sem_background.png" alt="TrackMoz">
    </a>

    <!-- Desktop links (não empurram o perfil) -->
    <div class="tm-nav-desktop">
        <a class="nav-link <?php echo nav_active(['index.php','dashboard.php']); ?>"
           href="<?php echo BASE_URL; ?>/index.php">
            <i class="bi bi-house"></i> Dashboard
        </a>
        <a class="nav-link" href="<?php echo BASE_URL; ?>/index.php?site=1" title="Ver o site público">
            <i class="bi bi-globe2"></i> Ver o site
        </a>

        <?php if (in_array($user_type, ['caminhoneiro','empresa','transportador'], true)): ?>
            <a class="nav-link <?php echo nav_active(['verificacao-conta.php']); ?>"
               href="<?php echo BASE_URL; ?>/pages/shared/verificacao-conta.php">
                <i class="bi bi-shield-check"></i> Verificação
            </a>
        <?php endif; ?>

        <?php if ($user_type === 'admin'): ?>
            <a class="nav-link <?php echo nav_active(['usuarios.php']); ?>"
               href="<?php echo BASE_URL; ?>/pages/admin/usuarios.php?status=pendente">
                <i class="bi bi-people"></i> Utilizadores
                <?php if ($__adminUsersPend > 0): ?>
                    <span class="badge bg-warning text-dark rounded-pill" style="font-size:.58rem"><?php echo $__adminUsersPend; ?></span>
                <?php endif; ?>
            </a>
            <a class="nav-link <?php echo nav_active(['verificar-documentos.php','analise-verificacoes.php']); ?>"
               href="<?php echo BASE_URL; ?>/pages/admin/verificar-documentos.php?status=pendente">
                <i class="bi bi-file-earmark-check"></i> Docs
                <?php if ($__adminDocs > 0): ?>
                    <span class="badge bg-danger rounded-pill" style="font-size:.58rem"><?php echo $__adminDocs; ?></span>
                <?php endif; ?>
            </a>
            <a class="nav-link <?php echo nav_active(['centro-operacoes.php']); ?>"
               href="<?php echo BASE_URL; ?>/pages/shared/centro-operacoes.php"
               target="_blank" rel="noopener">
                <i class="bi bi-radar"></i> Operações
            </a>
            <div class="dropdown">
                <button type="button" class="nav-link dropdown-toggle"
                        aria-expanded="false" id="tmMaisAdmin" aria-haspopup="true">
                    <i class="bi bi-grid"></i> Mais
                    <?php if ($__adminIrregulares > 0): ?>
                        <span class="badge bg-warning text-dark rounded-pill" style="font-size:.58rem"><?php echo $__adminIrregulares; ?></span>
                    <?php endif; ?>
                </button>
                <ul class="dropdown-menu" aria-labelledby="tmMaisAdmin">
                    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/pages/admin/contas-irregulares.php">
                        <i class="bi bi-exclamation-octagon text-warning"></i> Irregulares
                        <?php if ($__adminIrregulares > 0): ?><span class="badge bg-warning text-dark ms-auto"><?php echo $__adminIrregulares; ?></span><?php endif; ?>
                    </a></li>
                    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/pages/admin/mapa-geral.php"><i class="bi bi-map text-primary"></i> Mapa Geral</a></li>
                    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/pages/admin/emergencias.php"><i class="bi bi-exclamation-triangle text-danger"></i> Emergências</a></li>
                    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/pages/admin/disputas.php"><i class="bi bi-shield-exclamation text-warning"></i> Disputas</a></li>
                    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/pages/admin/missoes.php"><i class="bi bi-list-task text-success"></i> Missões</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/pages/admin/dashboard.php"><i class="bi bi-speedometer2"></i> Painel Admin</a></li>
                    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/pages/admin/dashboard-executivo.php"><i class="bi bi-graph-up"></i> Executivo</a></li>
                    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/pages/admin/relatorios.php"><i class="bi bi-bar-chart"></i> Relatórios</a></li>
                    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/pages/admin/configuracoes.php"><i class="bi bi-gear"></i> Configurações</a></li>
                </ul>
            </div>

        <?php elseif ($user_type === 'transportador'): ?>
            <a class="nav-link <?php echo nav_active(['missoes.php']); ?>" href="<?php echo BASE_URL; ?>/pages/transportador/missoes.php"><i class="bi bi-list-task"></i> Missões</a>
            <a class="nav-link <?php echo nav_active(['frota.php']); ?>" href="<?php echo BASE_URL; ?>/pages/transportador/frota.php"><i class="bi bi-truck-flatbed"></i> Frota</a>
            <a class="nav-link <?php echo nav_active(['motoristas.php']); ?>" href="<?php echo BASE_URL; ?>/pages/transportador/motoristas.php"><i class="bi bi-person-badge"></i> Motoristas</a>
            <a class="nav-link <?php echo nav_active(['explorador.php']); ?>" href="<?php echo BASE_URL; ?>/pages/contratante/documentos/explorador.php"><i class="bi bi-folder2-open"></i> Documentos</a>
            <div class="dropdown">
                <button type="button" class="nav-link dropdown-toggle" aria-expanded="false" id="tmMaisTransp" aria-haspopup="true">
                    <i class="bi bi-grid"></i> Mais
                </button>
                <ul class="dropdown-menu" aria-labelledby="tmMaisTransp">
                    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/pages/transportador/parcerias.php"><i class="bi bi-handshake"></i> Parcerias
                        <?php if ($__pend_parcerias > 0): ?><span class="badge bg-warning text-dark ms-auto"><?php echo $__pend_parcerias; ?></span><?php endif; ?>
                    </a></li>
                    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/pages/shared/centro-operacoes.php" target="_blank" rel="noopener"><i class="bi bi-radar"></i> Operações</a></li>
                    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/pages/transportador/missoes-delegadas.php"><i class="bi bi-inbox"></i> Delegadas</a></li>
                </ul>
            </div>

        <?php elseif ($user_type === 'caminhoneiro'): ?>
            <a class="nav-link <?php echo nav_active(['missoes.php']); ?>" href="<?php echo BASE_URL; ?>/pages/caminhoneiro/missoes.php"><i class="bi bi-list-task"></i> Missões</a>
            <a class="nav-link <?php echo nav_active(['propostas.php']); ?>" href="<?php echo BASE_URL; ?>/pages/caminhoneiro/propostas.php"><i class="bi bi-send"></i> Propostas</a>
            <a class="nav-link <?php echo nav_active(['upload-documentos.php']); ?>" href="<?php echo BASE_URL; ?>/pages/caminhoneiro/upload-documentos.php"><i class="bi bi-file-earmark-text"></i> Documentos</a>

        <?php else: ?>
            <a class="nav-link <?php echo nav_active(['missoes.php']); ?>" href="<?php echo BASE_URL; ?>/pages/contratante/missoes.php"><i class="bi bi-list-task"></i> Missões</a>
            <a class="nav-link <?php echo nav_active(['propostas.php']); ?>" href="<?php echo BASE_URL; ?>/pages/contratante/propostas.php"><i class="bi bi-inbox"></i> Propostas</a>
            <a class="nav-link <?php echo nav_active(['centro-operacoes.php']); ?>" href="<?php echo BASE_URL; ?>/pages/shared/centro-operacoes.php" target="_blank" rel="noopener"><i class="bi bi-radar"></i> Operações</a>
            <div class="dropdown">
                <button type="button" class="nav-link dropdown-toggle" aria-expanded="false" id="tmMaisEmpresa" aria-haspopup="true">
                    <i class="bi bi-grid"></i> Mais
                </button>
                <ul class="dropdown-menu" aria-labelledby="tmMaisEmpresa">
                    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/pages/contratante/mapa-missoes.php"><i class="bi bi-map"></i> Mapa</a></li>
                    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/pages/contratante/parcerias.php"><i class="bi bi-handshake"></i> Parcerias</a></li>
                    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/pages/contratante/documentos/explorador.php"><i class="bi bi-folder2-open"></i> Documentos</a></li>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <!-- Perfil / logout / notifs — SEMPRE visíveis -->
    <div class="tm-right-actions">
        <a href="<?php echo BASE_URL; ?>/pages/chat.php" class="tm-icon-btn" title="Mensagens" aria-label="Mensagens">
            <i class="bi bi-chat-dots"></i>
            <?php if ($mensagens_nao_lidas > 0): ?>
                <span class="cnt"><?php echo $mensagens_nao_lidas > 9 ? '9+' : $mensagens_nao_lidas; ?></span>
            <?php endif; ?>
        </a>
        <a href="<?php echo BASE_URL; ?>/pages/notificacoes.php" class="tm-icon-btn" title="Notificações" aria-label="Notificações">
            <i class="bi bi-bell"></i>
            <?php if ($notificacoes_nao_lidas > 0): ?><span class="dot"></span><?php endif; ?>
        </a>
        <div class="dropdown">
            <button class="tm-user-btn" type="button" id="tmUserDrop"
                    aria-expanded="false" aria-label="Conta" aria-haspopup="true">
                <?php echo tmz_menu_avatar_html($inicial, $fotoPerfilUrl); ?>
                <span class="d-none d-md-inline text-truncate" style="max-width:100px"><?php echo htmlspecialchars($nome_usuario); ?></span>
                <span class="role-chip <?php echo $roleChipClass; ?> d-none d-sm-inline"><?php echo $roleLabel; ?></span>
                <i class="bi bi-chevron-down"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="tmUserDrop">
                <li class="px-3 py-2 mb-1">
                    <div class="d-flex align-items-center gap-2">
                        <?php echo tmz_menu_avatar_html($inicial, $fotoPerfilUrl, 'width:38px;height:38px;font-size:.95rem'); ?>
                        <div class="min-w-0">
                            <div class="fw-semibold small text-truncate" style="color:#111;max-width:160px"><?php echo htmlspecialchars($nome_usuario); ?></div>
                            <div class="text-muted text-truncate" style="font-size:.7rem;max-width:160px"><?php echo htmlspecialchars($usuario['email'] ?? ''); ?></div>
                        </div>
                    </div>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item" href="<?php echo htmlspecialchars($perfil_url); ?>">
                        <i class="bi bi-person-circle text-primary"></i> Meu Perfil
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="<?php echo BASE_URL; ?>/index.php?site=1">
                        <i class="bi bi-globe2 text-info"></i> Ver o site
                    </a>
                </li>
                <li data-tm-pwa-menu-install>
                    <a class="dropdown-item" href="#" role="button"
                       onclick="event.preventDefault(); if (window.TrackMozPwa) TrackMozPwa.promptInstall();">
                        <i class="bi bi-download text-success"></i> Instalar app
                    </a>
                </li>
                <?php if ($user_type === 'admin'): ?>
                <li>
                    <a class="dropdown-item" href="<?php echo BASE_URL; ?>/pages/admin/dashboard.php">
                        <i class="bi bi-speedometer2 text-warning"></i> Painel Admin
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="<?php echo BASE_URL; ?>/pages/admin/configuracoes.php">
                        <i class="bi bi-gear text-secondary"></i> Configurações
                    </a>
                </li>
                <?php endif; ?>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item text-danger" href="<?php echo BASE_URL; ?>/pages/logout.php">
                        <i class="bi bi-box-arrow-right"></i> Terminar Sessão
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>
</nav>

<!-- Offcanvas mobile -->
<div class="offcanvas offcanvas-start tm-offcanvas" tabindex="-1" id="tmOffcanvas" aria-labelledby="tmOffLabel">
    <div class="offcanvas-header border-bottom py-3">
        <div class="d-flex align-items-center gap-2" id="tmOffLabel">
            <?php echo tmz_menu_avatar_html($inicial, $fotoPerfilUrl, 'width:40px;height:40px;font-size:1rem'); ?>
            <div>
                <div class="fw-semibold small mb-0"><?php echo htmlspecialchars($nome_usuario); ?></div>
                <span class="role-chip <?php echo $roleChipClass; ?>"><?php echo $roleLabel; ?></span>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
    </div>
    <div class="offcanvas-body">
        <div class="tm-off-scroll">
            <div class="tm-off-section">Principal</div>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/index.php"><i class="bi bi-house"></i> Dashboard</a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/index.php?site=1"><i class="bi bi-globe2"></i> Ver o site</a>
            <a class="tm-off-link" href="#" data-tm-pwa-menu-install role="button"
               onclick="event.preventDefault(); if (window.TrackMozPwa) TrackMozPwa.promptInstall();">
                <i class="bi bi-download"></i> Instalar app
            </a>
            <?php if (in_array($user_type, ['caminhoneiro','empresa','transportador'], true)): ?>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/shared/verificacao-conta.php"><i class="bi bi-shield-check"></i> Verificação</a>
            <?php endif; ?>

            <?php if ($user_type === 'admin'): ?>
            <div class="tm-off-section">Gestão</div>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/admin/usuarios.php?status=pendente"><i class="bi bi-people"></i> Utilizadores <?php if ($__adminUsersPend > 0): ?><span class="badge bg-warning text-dark ms-auto"><?php echo $__adminUsersPend; ?></span><?php endif; ?></a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/admin/verificar-documentos.php?status=pendente"><i class="bi bi-file-earmark-check"></i> Documentos <?php if ($__adminDocs > 0): ?><span class="badge bg-danger ms-auto"><?php echo $__adminDocs; ?></span><?php endif; ?></a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/admin/contas-irregulares.php"><i class="bi bi-exclamation-octagon"></i> Irregulares <?php if ($__adminIrregulares > 0): ?><span class="badge bg-warning text-dark ms-auto"><?php echo $__adminIrregulares; ?></span><?php endif; ?></a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/admin/missoes.php"><i class="bi bi-list-task"></i> Missões</a>
            <div class="tm-off-section">Operações</div>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/shared/centro-operacoes.php" target="_blank" rel="noopener"><i class="bi bi-radar"></i> Centro de Operações</a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/admin/mapa-geral.php"><i class="bi bi-map"></i> Mapa Geral</a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/admin/emergencias.php"><i class="bi bi-exclamation-triangle"></i> Emergências</a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/admin/disputas.php"><i class="bi bi-shield-exclamation"></i> Disputas</a>
            <div class="tm-off-section">Sistema</div>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/admin/dashboard.php"><i class="bi bi-speedometer2"></i> Painel Admin</a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/admin/dashboard-executivo.php"><i class="bi bi-graph-up"></i> Executivo</a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/admin/relatorios.php"><i class="bi bi-bar-chart"></i> Relatórios</a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/admin/configuracoes.php"><i class="bi bi-gear"></i> Configurações</a>
            <?php elseif ($user_type === 'transportador'): ?>
            <div class="tm-off-section">Frota</div>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/transportador/missoes.php"><i class="bi bi-list-task"></i> Missões</a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/transportador/frota.php"><i class="bi bi-truck-flatbed"></i> Frota</a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/transportador/motoristas.php"><i class="bi bi-person-badge"></i> Motoristas</a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/transportador/parcerias.php"><i class="bi bi-handshake"></i> Parcerias</a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/shared/centro-operacoes.php" target="_blank" rel="noopener"><i class="bi bi-radar"></i> Operações</a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/transportador/missoes-delegadas.php"><i class="bi bi-inbox"></i> Delegadas</a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/contratante/documentos/explorador.php"><i class="bi bi-folder2-open"></i> Documentos</a>
            <?php elseif ($user_type === 'caminhoneiro'): ?>
            <div class="tm-off-section">Trabalho</div>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/caminhoneiro/missoes.php"><i class="bi bi-list-task"></i> Missões</a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/caminhoneiro/propostas.php"><i class="bi bi-send"></i> Propostas</a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/caminhoneiro/upload-documentos.php"><i class="bi bi-file-earmark-text"></i> Documentos</a>
            <?php else: ?>
            <div class="tm-off-section">Negócio</div>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/contratante/missoes.php"><i class="bi bi-list-task"></i> Missões</a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/contratante/propostas.php"><i class="bi bi-inbox"></i> Propostas</a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/shared/centro-operacoes.php" target="_blank" rel="noopener"><i class="bi bi-radar"></i> Operações</a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/contratante/mapa-missoes.php"><i class="bi bi-map"></i> Mapa</a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/contratante/parcerias.php"><i class="bi bi-handshake"></i> Parcerias</a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/contratante/documentos/explorador.php"><i class="bi bi-folder2-open"></i> Documentos</a>
            <?php endif; ?>

            <div class="tm-off-section">Conta</div>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/chat.php"><i class="bi bi-chat-dots"></i> Mensagens <?php if ($mensagens_nao_lidas > 0): ?><span class="badge bg-danger ms-auto"><?php echo $mensagens_nao_lidas; ?></span><?php endif; ?></a>
            <a class="tm-off-link" href="<?php echo BASE_URL; ?>/pages/notificacoes.php"><i class="bi bi-bell"></i> Notificações <?php if ($notificacoes_nao_lidas > 0): ?><span class="badge bg-danger ms-auto"><?php echo $notificacoes_nao_lidas; ?></span><?php endif; ?></a>
            <a class="tm-off-link" href="<?php echo htmlspecialchars($perfil_url); ?>"><i class="bi bi-person-circle"></i> Meu Perfil</a>
        </div>
        <div class="tm-off-footer">
            <a href="<?php echo BASE_URL; ?>/index.php?site=1" class="btn btn-outline-secondary w-100 mb-2"><i class="bi bi-globe2"></i> Ver o site</a>
            <a href="<?php echo htmlspecialchars($perfil_url); ?>" class="btn btn-outline-primary w-100 mb-2"><i class="bi bi-person"></i> Perfil</a>
            <a href="<?php echo BASE_URL; ?>/pages/logout.php" class="btn btn-danger w-100"><i class="bi bi-box-arrow-right"></i> Terminar Sessão</a>
        </div>
    </div>
</div>

<?php
$__hideBottomNav = in_array($current_page, ['modo-direcao.php', 'entrega-confirmar.php'], true);
?>
<?php if (!$__hideBottomNav): ?>
<nav class="tm-bottom-nav d-lg-none" aria-label="Navegação rápida">
    <a href="<?php echo BASE_URL; ?>/index.php" class="<?php echo nav_active(['index.php','dashboard.php']); ?>"><i class="bi bi-house"></i><span>Início</span></a>
    <?php if ($user_type === 'caminhoneiro'): ?>
        <a href="<?php echo BASE_URL; ?>/pages/caminhoneiro/missoes.php" class="<?php echo nav_active(['missoes.php','detalhes-missao.php','missao.php']); ?>"><i class="bi bi-truck"></i><span>Missões</span></a>
        <a href="<?php echo BASE_URL; ?>/pages/caminhoneiro/propostas.php" class="<?php echo nav_active(['propostas.php','enviar-proposta.php']); ?>"><i class="bi bi-send"></i><span>Propostas</span></a>
        <a href="<?php echo BASE_URL; ?>/pages/chat.php" class="<?php echo nav_active(['chat.php']); ?>"><i class="bi bi-chat-dots"></i><span>Chat</span><?php if ($mensagens_nao_lidas > 0): ?><span class="badge-dot"></span><?php endif; ?></a>
    <?php elseif ($user_type === 'admin'): ?>
        <a href="<?php echo BASE_URL; ?>/pages/admin/usuarios.php?status=pendente" class="<?php echo nav_active(['usuarios.php']); ?>"><i class="bi bi-people"></i><span>Contas</span></a>
        <a href="<?php echo BASE_URL; ?>/pages/shared/centro-operacoes.php" class="<?php echo nav_active(['centro-operacoes.php']); ?>" target="_blank" rel="noopener"><i class="bi bi-radar"></i><span>Ops</span></a>
        <a href="<?php echo BASE_URL; ?>/pages/notificacoes.php" class="<?php echo nav_active(['notificacoes.php']); ?>"><i class="bi bi-bell"></i><span>Alertas</span><?php if ($notificacoes_nao_lidas > 0): ?><span class="badge-dot"></span><?php endif; ?></a>
    <?php elseif ($user_type === 'transportador'): ?>
        <a href="<?php echo BASE_URL; ?>/pages/transportador/missoes.php" class="<?php echo nav_active(['missoes.php','detalhes-missao.php']); ?>"><i class="bi bi-list-task"></i><span>Missões</span></a>
        <a href="<?php echo BASE_URL; ?>/pages/transportador/frota.php" class="<?php echo nav_active(['frota.php']); ?>"><i class="bi bi-truck"></i><span>Frota</span></a>
        <a href="<?php echo BASE_URL; ?>/pages/chat.php" class="<?php echo nav_active(['chat.php']); ?>"><i class="bi bi-chat-dots"></i><span>Chat</span><?php if ($mensagens_nao_lidas > 0): ?><span class="badge-dot"></span><?php endif; ?></a>
    <?php else: ?>
        <a href="<?php echo BASE_URL; ?>/pages/contratante/missoes.php" class="<?php echo nav_active(['missoes.php','detalhes-missao.php']); ?>"><i class="bi bi-list-task"></i><span>Missões</span></a>
        <a href="<?php echo BASE_URL; ?>/pages/contratante/nova-missao.php" class="<?php echo nav_active(['nova-missao.php']); ?>"><i class="bi bi-plus-circle"></i><span>Nova</span></a>
        <a href="<?php echo BASE_URL; ?>/pages/chat.php" class="<?php echo nav_active(['chat.php']); ?>"><i class="bi bi-chat-dots"></i><span>Chat</span><?php if ($mensagens_nao_lidas > 0): ?><span class="badge-dot"></span><?php endif; ?></a>
    <?php endif; ?>
    <a href="<?php echo htmlspecialchars($perfil_url); ?>" class="<?php echo nav_active(['perfil.php']); ?>"><i class="bi bi-person"></i><span>Perfil</span></a>
</nav>
<script>document.body.classList.add('tm-has-bottom-nav');</script>
<?php endif; ?>
<?php
if ($user_type === 'admin' && isset($conn)) {
    try {
        require_once __DIR__ . '/admin-atencao-helpers.php';
        if (!isset($__atencao)) {
            $__atencao = admin_atencao_resumo($conn);
        }
        echo admin_atencao_banner_html($__atencao);
    } catch (Throwable $e) {
        // silencioso
    }
}
?>
<style>
.tm-admin-atencao {
    background: linear-gradient(90deg, #fff7ed, #fef2f2);
    border-bottom: 1px solid #fecaca;
    padding: .55rem 1rem;
}
.tm-admin-atencao-inner {
    max-width: 1200px; margin: 0 auto;
    display: flex; flex-wrap: wrap; gap: .5rem 1rem; align-items: center;
}
.tm-admin-atencao-title {
    font-weight: 800; font-size: .85rem; color: #9a3412;
    display: flex; align-items: center; gap: .35rem; white-space: nowrap;
}
.tm-admin-atencao-items { display: flex; flex-wrap: wrap; gap: .4rem; }
.tm-admin-chip {
    display: inline-flex; align-items: center; gap: .35rem;
    text-decoration: none; font-size: .78rem; font-weight: 600;
    padding: .28rem .65rem; border-radius: 999px;
    border: 1px solid transparent;
}
.tm-admin-chip .badge {
    background: #fff; color: #9a3412; border-radius: 999px;
    font-size: .68rem; padding: .1rem .4rem;
}
.tm-admin-chip-warning { background: #fef3c7; color: #92400e; border-color: #fde68a; }
.tm-admin-chip-danger { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
.tm-admin-chip:hover { filter: brightness(.97); color: inherit; }
</style>
<?php
if (in_array($user_type, ['caminhoneiro', 'empresa', 'transportador'], true)
    && isset($conn)) {
    try {
        require_once __DIR__ . '/kyc-helpers.php';
        $__uid = (int)$_SESSION['user_id'];
        $__kyc = kyc_obter_estado($conn, $__uid);

        // Lembrete no sino (máx. 1 sync / 5 min por sessão)
        if (empty($_SESSION['_kyc_lembrete_sync']) || (time() - (int)$_SESSION['_kyc_lembrete_sync']) > 300) {
            kyc_sincronizar_lembrete_utilizador($conn, $__uid);
            $_SESSION['_kyc_lembrete_sync'] = time();
            $stmt = $conn->prepare('SELECT COUNT(*) FROM notificacoes WHERE usuario_id = :id AND lida = 0');
            $stmt->execute([':id' => $__uid]);
            $notificacoes_nao_lidas = (int)$stmt->fetchColumn();
        }

        if (empty($__kyc['pode_operar']) && $current_page !== 'verificacao-conta.php') {
            $faltam = $__kyc['faltam_docs'] ?? [];
            if (($__kyc['estado'] ?? '') === 'em_analise' && empty($faltam)) {
                $__msg = 'Documentos em análise pela administração. Ainda não pode operar.';
            } elseif ($faltam) {
                $__msg = 'Documentos em falta: ' . implode(', ', array_map(static function ($v) {
                    return preg_replace('/\s*\(.*\)$/', '', $v);
                }, array_values($faltam))) . '. Sem eles não pode negociar nem criar missões.';
            } else {
                $__msg = 'Complete a verificação da conta para poder operar.';
            }
            echo '<div class="alert alert-warning border-0 rounded-0 mb-0 text-center small py-2">'
                . '<i class="bi bi-shield-exclamation me-1"></i>' . htmlspecialchars($__msg)
                . ' <a href="' . htmlspecialchars(BASE_URL . '/pages/shared/verificacao-conta.php') . '" class="alert-link fw-semibold">Anexar documentos</a>'
                . '</div>';
        }
    } catch (Throwable $e) {
        // silencioso
    }
}
?>
<?php if ($user_type === 'caminhoneiro'): ?>
<script>
window.TRACKMOZ_DRIVER_ALERTS = {
    apiUrl: <?php echo json_encode(BASE_URL . '/api/driver-alerts.php'); ?>
};
</script>
<script src="<?php echo BASE_URL; ?>/assets/js/driver-alerts.js" defer></script>
<?php endif; ?>
<script>
(function () {
    if (window.__tmNavReady) return;
    window.__tmNavReady = true;

    function ensureBootstrap(cb) {
        if (window.bootstrap && window.bootstrap.Offcanvas) {
            cb();
            return;
        }
        var existing = document.querySelector('script[data-tm-bootstrap]');
        if (existing) {
            existing.addEventListener('load', cb);
            return;
        }
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js';
        s.async = true;
        s.setAttribute('data-tm-bootstrap', '1');
        s.onload = cb;
        document.head.appendChild(s);
    }

    function closeAll(except) {
        document.querySelectorAll('.tm-nav .dropdown-menu.show').forEach(function (m) {
            if (except && m === except) return;
            m.classList.remove('show', 'tm-drop-fixed');
            m.style.cssText = '';
            var t = m.closest('.dropdown') && m.closest('.dropdown').querySelector('[data-bs-toggle="dropdown"],.dropdown-toggle');
            if (t) t.setAttribute('aria-expanded', 'false');
        });
    }

    function placeMenu(btn, menu) {
        menu.classList.add('show', 'tm-drop-fixed');
        btn.setAttribute('aria-expanded', 'true');
        var r = btn.getBoundingClientRect();
        var pad = 8;
        menu.style.top = (r.bottom + 4) + 'px';
        menu.style.left = r.left + 'px';
        menu.style.right = 'auto';
        requestAnimationFrame(function () {
            var mr = menu.getBoundingClientRect();
            if (mr.right > window.innerWidth - pad) {
                menu.style.left = Math.max(pad, window.innerWidth - mr.width - pad) + 'px';
            }
            if (mr.bottom > window.innerHeight - pad) {
                var above = r.top - mr.height - 4;
                if (above > pad) menu.style.top = above + 'px';
            }
        });
    }

    function bindDropdowns() {
        var toggles = document.querySelectorAll('.tm-nav .dropdown-toggle, .tm-nav #tmUserDrop');
        toggles.forEach(function (btn) {
            if (btn.dataset.tmBound) return;
            btn.dataset.tmBound = '1';
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var dd = btn.closest('.dropdown');
                if (!dd) return;
                var menu = dd.querySelector('.dropdown-menu');
                if (!menu) return;
                var open = menu.classList.contains('show');
                closeAll();
                if (!open) placeMenu(btn, menu);
            });
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('.tm-nav .dropdown')) closeAll();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeAll();
        });
        window.addEventListener('resize', function () { closeAll(); });
        window.addEventListener('scroll', function () { closeAll(); }, true);
    }

    bindDropdowns();
    ensureBootstrap(function () {});
})();
</script>
<?php include_once __DIR__ . '/pwa-boot.php'; ?>
