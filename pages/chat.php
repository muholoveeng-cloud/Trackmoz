<?php
session_start();
include_once('../config/app.php');
include_once('../config/database.php');
include_once('../includes/helpers.php');
include_once('../includes/chat-helpers.php');

function tableHasColumn(PDO $conn, string $table, string $column): bool {
    return chat_coluna_existe($conn, $table, $column);
}

// Garantir colunas de anexo (migration automática)
chat_garantir_colunas_anexo($conn);
chat_garantir_schema_conversas($conn);

try {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/index.php');
        exit();
    }

    $user_id    = $_SESSION['user_id'];
    $contato_id = isset($_GET['user']) ? (int)$_GET['user'] : 0;
    $missao_id  = chat_normalizar_missao_id($_GET['missao'] ?? null);
    $chat_bloqueado = null;

    $chat_u1 = ($contato_id > 0) ? min($user_id, $contato_id) : null;
    $chat_u2 = ($contato_id > 0) ? max($user_id, $contato_id) : null;

    $hasMsgLida          = tableHasColumn($conn, 'mensagens', 'lida');
    $hasConvUltAtual     = tableHasColumn($conn, 'conversas', 'ultima_atualizacao');
    $hasConvNaoLidas     = tableHasColumn($conn, 'conversas', 'nao_lidas');

    // Buscar utilizador atual
    $stmt = $conn->prepare("SELECT nome, tipo_usuario FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $usuario_atual = $stmt->fetch(PDO::FETCH_ASSOC);

    // Buscar contato selecionado e criar/obter conversa
    $contato     = null;
    $conversa_id = 0;
    $missao_info = null;

    if ($contato_id > 0) {
        $stmt = $conn->prepare("SELECT id, nome, tipo_usuario FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $contato_id]);
        $contato = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($contato) {
            $acesso = chat_validar_acesso($conn, (int)$user_id, $contato_id, $missao_id);
            if (!$acesso['ok']) {
                $chat_bloqueado = $acesso['error'] ?? 'Sem permissão para esta conversa.';
            } else {
                $stmt = $conn->prepare(
                    "SELECT id FROM conversas
                     WHERE usuario1_id = :u1 AND usuario2_id = :u2 AND missao_id <=> :missao_id
                     ORDER BY id DESC LIMIT 1"
                );
                $stmt->execute([':u1' => $chat_u1, ':u2' => $chat_u2, ':missao_id' => $missao_id]);
                $conversa_id = (int)($stmt->fetchColumn() ?: 0);

                if ($conversa_id === 0) {
                    $cols = $hasConvUltAtual
                        ? "usuario1_id, usuario2_id, missao_id, ultima_atualizacao"
                        : "usuario1_id, usuario2_id, missao_id";
                    $vals = $hasConvUltAtual
                        ? ":u1, :u2, :missao_id, NOW()"
                        : ":u1, :u2, :missao_id";
                    $stmt = $conn->prepare("INSERT INTO conversas ($cols) VALUES ($vals)");
                    $stmt->execute([':u1' => $chat_u1, ':u2' => $chat_u2, ':missao_id' => $missao_id]);
                    $conversa_id = (int)$conn->lastInsertId();
                }

                if ($hasMsgLida) {
                    $stmt = $conn->prepare(
                        "UPDATE mensagens SET lida = 1
                         WHERE remetente_id = :rem AND destinatario_id = :dest
                         AND (missao_id <=> :missao_id)"
                    );
                    $stmt->execute([':rem' => $contato_id, ':dest' => $user_id, ':missao_id' => $missao_id]);
                }
                if ($hasConvNaoLidas) {
                    $conn->prepare("UPDATE conversas SET nao_lidas = 0 WHERE id = :id")
                         ->execute([':id' => $conversa_id]);
                }

                if ($missao_id) {
                    $stmt = $conn->prepare("SELECT id, titulo, status FROM missoes WHERE id = :id");
                    $stmt->execute([':id' => $missao_id]);
                    $missao_info = $stmt->fetch(PDO::FETCH_ASSOC);
                }
            }
        }
    }

    // Buscar todas as conversas do utilizador (para a lista lateral)
    $selUlt  = $hasConvUltAtual  ? 'c.ultima_atualizacao' : 'NULL AS ultima_atualizacao';
    $selNL   = $hasConvNaoLidas  ? 'c.nao_lidas'          : '0 AS nao_lidas';
    // Whitelist para ORDER BY - previne SQL injection
    $orderBy = $hasConvUltAtual  ? 'c.ultima_atualizacao DESC' : 'c.id DESC';
    $allowedOrderBy = ['c.ultima_atualizacao DESC', 'c.id DESC'];
    if (!in_array($orderBy, $allowedOrderBy, true)) {
        $orderBy = 'c.id DESC';
    }

    $stmt = $conn->prepare(
        "SELECT c.id, c.usuario1_id, c.usuario2_id, c.missao_id,
                $selUlt, $selNL, mt.titulo AS missao_titulo,
                CASE WHEN c.usuario1_id = :uid1 THEN u2.nome ELSE u1.nome END AS contato_nome,
                CASE WHEN c.usuario1_id = :uid2 THEN u2.id   ELSE u1.id   END AS contato_id
         FROM conversas c
         JOIN usuarios u1 ON c.usuario1_id = u1.id
         JOIN usuarios u2 ON c.usuario2_id = u2.id
         LEFT JOIN missoes mt ON c.missao_id = mt.id
         WHERE c.usuario1_id = :uid3 OR c.usuario2_id = :uid4
         ORDER BY $orderBy"
    );
    $stmt->execute([':uid1' => $user_id, ':uid2' => $user_id, ':uid3' => $user_id, ':uid4' => $user_id]);
    $conversas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Buscar mensagens iniciais (histórico completo)
    $mensagens = [];
    $last_msg_id = 0;
    if ($contato && !$chat_bloqueado) {
        $camposMsg = chat_campos_mensagem($conn);
        $stmt = $conn->prepare(
            "SELECT {$camposMsg['select']}, u.nome AS remetente_nome
             FROM mensagens m
             JOIN usuarios u ON m.remetente_id = u.id
             WHERE ((m.remetente_id = :uid1 AND m.destinatario_id = :cid1)
                 OR (m.remetente_id = :cid2 AND m.destinatario_id = :uid2))
             AND (m.missao_id <=> :missao_id)
             ORDER BY m.data_envio ASC"
        );
        $stmt->execute([
            ':uid1'     => $user_id,
            ':cid1'     => $contato_id,
            ':cid2'     => $contato_id,
            ':uid2'     => $user_id,
            ':missao_id'=> $missao_id,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $mensagens[] = chat_formatar_mensagem(
                $row,
                (int)$user_id,
                $camposMsg['has_anexo'],
                $camposMsg['has_lida']
            );
            $mensagens[count($mensagens) - 1]['remetente_id'] = (int)$row['remetente_id'];
        }
        if (!empty($mensagens)) {
            $last_msg_id = (int)end($mensagens)['id'];
        }
    }

} catch (Throwable $e) {
    $traceId = bin2hex(random_bytes(8));
    error_log('Erro fatal chat.php [trace=' . $traceId . ']: ' . $e->getMessage());
    http_response_code(500);
    $isLocal = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true);
    if ($isLocal) {
        echo '<pre>' . htmlspecialchars($e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine()) . '</pre>';
    } else {
        echo 'Erro ao carregar o chat. Por favor, tente novamente.';
    }
    exit;
}

function formatarData(string $data): string {
    $ts   = strtotime($data);
    $hoje = strtotime(date('Y-m-d'));
    if ($ts >= $hoje) return 'Hoje às ' . date('H:i', $ts);
    if ($ts >= ($hoje - 86400)) return 'Ontem às ' . date('H:i', $ts);
    return date('d/m/Y H:i', $ts);
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <style>
        /* ── Tema & Variáveis ── */
        :root {
            --chat-bg: #f0f2f5;
            --sidebar-bg: #ffffff;
            --header-bg: #ffffff;
            --bubble-mine: #dcf8c6;
            --bubble-their: #ffffff;
            --text-primary: #1a1a2e;
            --text-secondary: #6b7280;
            --accent: #0d6efd;
            --accent-gradient: linear-gradient(135deg, #0d6efd 0%, #0dcaf0 100%);
            --border: #e8ecf0;
            --shadow: 0 4px 20px rgba(0,0,0,0.06);
            --radius: 18px;
        }
        [data-theme="dark"] {
            --chat-bg: #0f172a;
            --sidebar-bg: #1e293b;
            --header-bg: #1e293b;
            --bubble-mine: #059669;
            --bubble-their: #334155;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --accent: #38bdf8;
            --accent-gradient: linear-gradient(135deg, #38bdf8 0%, #818cf8 100%);
            --border: #334155;
            --shadow: 0 4px 20px rgba(0,0,0,0.3);
        }

        /* ── Layout base ── */
        html, body { height: 100%; overflow: hidden; background: var(--chat-bg); transition: background .3s; }
        .chat-wrap  { display: flex; flex-direction: column; height: calc(100vh - 56px); }
        .chat-body  { display: flex; flex: 1; overflow: hidden; }

        /* ── Sidebar ── */
        .chat-sidebar {
            width: 320px; min-width: 280px; flex-shrink: 0;
            display: flex; flex-direction: column;
            border-right: 1px solid var(--border); background: var(--sidebar-bg);
            transition: background .3s, border-color .3s;
        }
        .sidebar-head {
            padding: 14px 16px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
            background: var(--sidebar-bg); flex-shrink: 0;
            transition: background .3s, border-color .3s;
        }
        .sidebar-search { padding: 8px 14px; border-bottom: 1px solid var(--border); flex-shrink: 0; }
        .sidebar-search input {
            border-radius: 20px; border: 1px solid var(--border); background: var(--chat-bg);
            padding: 6px 14px; font-size: .85rem; width: 100%; color: var(--text-primary);
            transition: all .2s;
        }
        .sidebar-search input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(13,110,253,.12); }
        .conv-list { flex: 1; overflow-y: auto; }
        .conv-item {
            padding: 12px 16px; border-bottom: 1px solid rgba(0,0,0,.03);
            cursor: pointer; transition: all .15s;
            display: flex; flex-direction: column; gap: 2px;
        }
        .conv-item:hover  { background: rgba(13,110,253,.05); }
        .conv-item.active { background: rgba(13,110,253,.08); border-left: 3px solid var(--accent); }
        .conv-avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: var(--accent-gradient); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; font-weight: 700; flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(13,110,253,.25);
        }
        .conv-meta { font-size: .72rem; color: var(--text-secondary); }
        .conv-preview { font-size: .78rem; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px; }

        /* ── Área de chat ── */
        .chat-main  { flex: 1; display: flex; flex-direction: column; background: var(--chat-bg); overflow: hidden; transition: background .3s; }
        .msg-header {
            padding: 12px 18px; background: var(--header-bg);
            border-bottom: 1px solid var(--border); flex-shrink: 0;
            display: flex; align-items: center; gap: 12px;
            box-shadow: 0 1px 4px rgba(0,0,0,.03); transition: background .3s, border-color .3s;
        }
        .msg-header .conv-avatar { width: 38px; height: 38px; font-size: .9rem; }
        .msg-body   {
            flex: 1; overflow-y: auto; padding: 18px 20px;
            display: flex; flex-direction: column; gap: 8px;
            scroll-behavior: smooth;
        }
        .msg-footer {
            padding: 10px 16px; background: var(--header-bg);
            border-top: 1px solid var(--border); flex-shrink: 0;
            transition: background .3s, border-color .3s;
        }

        /* ── Bolhas ── */
        .bubble      { max-width: 72%; padding: 10px 14px; border-radius: var(--radius); word-break: break-word; font-size: .93rem; line-height: 1.45; animation: popIn .25s ease; }
        @keyframes popIn { from { opacity: 0; transform: scale(.92) translateY(6px); } to { opacity: 1; transform: scale(1) translateY(0); } }
        .bubble-mine { align-self: flex-end; background: var(--bubble-mine); border-bottom-right-radius: 6px; color: #1a3c0a; }
        [data-theme="dark"] .bubble-mine { color: #ecfdf5; }
        .bubble-their{ align-self: flex-start; background: var(--bubble-their); border: 1px solid var(--border); border-bottom-left-radius: 6px; box-shadow: var(--shadow); color: var(--text-primary); }
        .bubble-name { font-size: .72rem; font-weight: 700; color: var(--accent); margin-bottom: 3px; }
        .bubble-time { font-size: .67rem; color: var(--text-secondary); margin-top: 4px; text-align: right; display: flex; align-items: center; justify-content: flex-end; gap: 4px; }
        .bubble-pending { opacity: .6; }
        .bubble-img { max-width: 240px; max-height: 180px; border-radius: 12px; cursor: pointer; transition: transform .2s; }
        .bubble-img:hover { transform: scale(1.02); }
        .bubble-file { display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: rgba(0,0,0,.04); border-radius: 10px; text-decoration: none; color: inherit; }
        .bubble-file:hover { background: rgba(0,0,0,.08); }

        /* ── Input & Anexos ── */
        #msgInput  { resize: none; max-height: 120px; border-radius: 22px; padding: 10px 16px; line-height: 1.4; border: 1px solid var(--border); background: var(--chat-bg); color: var(--text-primary); transition: all .2s; }
        #msgInput:focus { background: var(--sidebar-bg); border-color: var(--accent); box-shadow: 0 0 0 3px rgba(13,110,253,.12); outline: none; }
        .send-btn  { width: 44px; height: 44px; border-radius: 50%; padding: 0; display: flex; align-items: center; justify-content: center; flex-shrink: 0; background: var(--accent-gradient); border: none; color: #fff; box-shadow: 0 2px 8px rgba(13,110,253,.3); transition: transform .15s, box-shadow .15s; }
        .send-btn:hover { transform: scale(1.05); box-shadow: 0 4px 14px rgba(13,110,253,.4); }
        .send-btn:active { transform: scale(.95); }
        .attach-btn { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text-secondary); transition: all .15s; border: none; background: transparent; }
        .attach-btn:hover { background: rgba(13,110,253,.08); color: var(--accent); }
        .no-chat   { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--text-secondary); }

        /* ── Pesquisa de mensagens ── */
        .msg-search-bar { position: absolute; top: 0; left: 0; right: 0; background: var(--header-bg); border-bottom: 1px solid var(--border); padding: 8px 16px; display: none; align-items: center; gap: 8px; z-index: 20; }
        .msg-search-bar.active { display: flex; }
        .msg-search-bar input { flex: 1; border-radius: 16px; border: 1px solid var(--border); padding: 5px 14px; font-size: .85rem; background: var(--chat-bg); color: var(--text-primary); }
        .msg-search-bar input:focus { outline: none; border-color: var(--accent); }
        .highlight { background: #fef08a; color: #1a1a2e; border-radius: 2px; }

        /* ── Status & Typing ── */
        .status-online { color: #22c55e; }
        .status-offline { color: #9ca3af; }
        .typing-indicator { display: flex; align-items: center; gap: 3px; padding: 6px 12px; background: var(--bubble-their); border-radius: 14px; align-self: flex-start; box-shadow: var(--shadow); border: 1px solid var(--border); }
        .typing-dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: var(--accent); animation: tdot .8s infinite alternate; }
        .typing-dot:nth-child(2) { animation-delay: .2s; }
        .typing-dot:nth-child(3) { animation-delay: .4s; }
        @keyframes tdot { from { opacity: .3; transform: translateY(0); } to { opacity: 1; transform: translateY(-4px); } }

        /* ── Modo escuro toggle ── */
        .theme-toggle { cursor: pointer; width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: none; background: transparent; color: var(--text-secondary); transition: all .2s; }
        .theme-toggle:hover { background: rgba(0,0,0,.05); color: var(--accent); }
        [data-theme="dark"] .theme-toggle:hover { background: rgba(255,255,255,.08); }

        /* ── MOBILE (< 768px) ── */
        @media (max-width: 767.98px) {
            .chat-sidebar { width: 100%; min-width: unset; border-right: none; position: absolute; top: 0; left: 0; right: 0; bottom: 0; z-index: 10; transition: transform .25s ease; }
            .chat-sidebar.hidden-mobile { transform: translateX(-100%); }
            .chat-main { position: absolute; top: 0; left: 0; right: 0; bottom: 0; z-index: 5; }
            .chat-body { position: relative; }
            .btn-back-mobile { display: flex !important; }
            .chat-sidebar { width: 100%; }
        }
        @media (min-width: 768px) {
            .btn-back-mobile { display: none !important; }
            .chat-sidebar { position: relative; transform: none !important; }
        }
    </style>
</head>
<body>
<?php include_once('../includes/menu.php'); ?>

<div class="chat-wrap">
<div class="chat-body">

    <!-- ── Sidebar ── -->
    <div class="chat-sidebar <?php echo $contato ? 'hidden-mobile' : ''; ?>" id="chatSidebar">
        <div class="sidebar-head">
            <h6 class="mb-0 fw-bold d-flex align-items-center gap-2" style="color:var(--text-primary)">
                <i class="bi bi-chat-dots" style="color:var(--accent)"></i> Mensagens
            </h6>
            <button class="theme-toggle" id="themeToggle" title="Alternar tema">
                <i class="bi bi-moon-stars" id="themeIcon"></i>
            </button>
        </div>
        <div class="sidebar-search">
            <div class="position-relative">
                <i class="bi bi-search position-absolute" style="left:12px;top:50%;transform:translateY(-50%);font-size:.8rem;color:var(--text-secondary)"></i>
                <input type="text" id="convSearch" placeholder="Pesquisar conversas..." style="padding-left:34px">
            </div>
        </div>
        <div class="conv-list" id="convList">
            <?php if (empty($conversas)): ?>
                <div class="p-4 text-center text-muted small">
                    <i class="bi bi-chat-square fs-2 d-block mb-2 opacity-25"></i>
                    Nenhuma conversa ainda.
                </div>
            <?php else: ?>
                <?php foreach ($conversas as $c):
                    $cMissao = chat_normalizar_missao_id($c['missao_id'] ?? null);
                    $isActive = ($contato_id === (int)$c['contato_id'])
                        && (($missao_id ?? null) === ($cMissao ?? null));
                    $inicial  = mb_strtoupper(mb_substr($c['contato_nome'], 0, 1));
                    $convHref = chat_url((int)$c['contato_id'], $cMissao);
                ?>
                <div class="conv-item <?php echo $isActive ? 'active' : ''; ?>"
                     onclick="window.location.href='<?php echo htmlspecialchars($convHref, ENT_QUOTES); ?>'">
                    <div class="d-flex align-items-center gap-3">
                        <div class="conv-avatar"><?php echo htmlspecialchars($inicial); ?></div>
                        <div class="flex-fill min-w-0">
                            <div class="d-flex justify-content-between align-items-start">
                                <span class="fw-semibold text-truncate" style="max-width:160px">
                                    <?php echo htmlspecialchars($c['contato_nome']); ?>
                                </span>
                                <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                    <?php if ($c['nao_lidas'] > 0): ?>
                                        <span class="badge bg-success rounded-pill"><?php echo $c['nao_lidas']; ?></span>
                                    <?php endif; ?>
                                    <?php if ($c['ultima_atualizacao']): ?>
                                        <span class="text-muted" style="font-size:.65rem">
                                            <?php echo formatarData($c['ultima_atualizacao']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($cMissao): ?>
                                <div class="small text-primary text-truncate mt-1" style="max-width:200px">
                                    <i class="bi bi-box-seam me-1"></i><?php echo htmlspecialchars($c['missao_titulo'] ?? ''); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Área principal ── -->
    <div class="chat-main">
        <?php if ($contato): ?>

            <!-- Cabeçalho do chat -->
            <div class="msg-header">
                <!-- Botão voltar (só mobile) -->
                <button class="btn btn-link p-0 me-1 text-dark btn-back-mobile" id="btnBack" style="display:none">
                    <i class="bi bi-arrow-left fs-5"></i>
                </button>

                <div class="conv-avatar" style="width:36px;height:36px;font-size:.85rem">
                    <?php echo mb_strtoupper(mb_substr($contato['nome'], 0, 1)); ?>
                </div>
                <div class="flex-fill">
                    <div class="fw-semibold" style="line-height:1.2"><?php echo htmlspecialchars($contato['nome']); ?></div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-secondary text-lowercase" style="font-size:.65rem">
                            <?php echo $contato['tipo_usuario'] === 'caminhoneiro' ? 'Motorista' : 'Empresa'; ?>
                        </span>
                        <?php if ($missao_info): ?>
                            <a href="<?php echo BASE_URL; ?>/pages/contratante/detalhes-missao.php?id=<?php echo $missao_id; ?>"
                               class="badge bg-primary bg-opacity-75 text-decoration-none" style="font-size:.65rem">
                                <i class="bi bi-box-seam me-1"></i><?php echo htmlspecialchars($missao_info['titulo']); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2 flex-shrink-0">
                    <button class="btn btn-link p-1 text-decoration-none" style="color:var(--text-secondary)" id="btnSearchMsg" title="Pesquisar mensagens">
                        <i class="bi bi-search fs-5"></i>
                    </button>
                    <div id="statusIndicator" class="small text-success flex-shrink-0 d-none d-sm-block">
                        <i class="bi bi-circle-fill" style="font-size:.45rem"></i> Online
                    </div>
                </div>
            </div>

            <!-- Barra de pesquisa de mensagens (overlay) -->
            <div class="msg-search-bar" id="msgSearchBar">
                <i class="bi bi-search" style="color:var(--text-secondary)"></i>
                <input type="text" id="msgSearchInput" placeholder="Pesquisar nesta conversa...">
                <span class="small" style="color:var(--text-secondary)" id="msgSearchCount"></span>
                <button class="btn btn-sm btn-link p-1" id="btnCloseSearch" style="color:var(--text-secondary)">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <!-- Mensagens -->
            <div class="msg-body" id="msgBody" style="position:relative">
                <?php if ($chat_bloqueado): ?>
                    <div class="alert alert-warning m-3">
                        <i class="bi bi-shield-exclamation me-1"></i>
                        <?php echo htmlspecialchars($chat_bloqueado); ?>
                    </div>
                <?php elseif (empty($mensagens)): ?>
                    <div class="text-center text-muted my-auto">
                        <i class="bi bi-chat-text" style="font-size:2.5rem;opacity:.25"></i>
                        <p class="mt-2 small">Inicia a conversa com <strong><?php echo htmlspecialchars($contato['nome']); ?></strong></p>
                    </div>
                <?php else: ?>
                    <?php foreach ($mensagens as $m):
                        $mine = ((int)$m['remetente_id'] === (int)$user_id);
                        $hasAnexo = !empty($m['anexo_url']);
                        $isImg = $hasAnexo && strpos($m['anexo_tipo'] ?? '', 'image/') === 0;
                    ?>
                        <div class="d-flex <?php echo $mine ? 'justify-content-end' : 'justify-content-start'; ?>">
                            <div class="bubble <?php echo $mine ? 'bubble-mine' : 'bubble-their'; ?>">
                                <?php if (!$mine): ?>
                                    <div class="bubble-name"><?php echo htmlspecialchars($m['remetente_nome']); ?></div>
                                <?php endif; ?>
                                <?php if ($isImg): ?>
                                    <img src="<?php echo htmlspecialchars($m['anexo_url']); ?>" class="bubble-img mb-1" alt="Imagem" onclick="window.open(this.src,'_blank')">
                                <?php elseif ($hasAnexo): ?>
                                    <a href="<?php echo htmlspecialchars($m['anexo_url']); ?>" target="_blank" class="bubble-file mb-1">
                                        <i class="bi bi-file-earmark fs-5"></i>
                                        <span><?php echo htmlspecialchars($m['anexo_nome'] ?? 'Ficheiro'); ?></span>
                                        <i class="bi bi-download"></i>
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($m['mensagem'])): ?>
                                    <div><?php echo nl2br(htmlspecialchars($m['mensagem'])); ?></div>
                                <?php endif; ?>
                                <div class="bubble-time">
                                    <?php echo formatarData($m['data_envio']); ?>
                                    <?php if ($mine): ?>
                                        <i class="bi <?php echo $m['lida'] ? 'bi-check-all text-primary' : 'bi-check'; ?>"></i>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if (!$chat_bloqueado): ?>
            <div id="typingIndicator" class="px-4 py-1" style="min-height:20px"></div>

            <!-- Rodapé de envio -->
            <div class="msg-footer">
                <div id="filePreview" class="small mb-1 d-none" style="color:var(--text-secondary)">
                    <i class="bi bi-paperclip"></i> <span id="fileName"></span>
                    <button class="btn btn-link btn-sm p-0 ms-1" id="btnRemoveFile" style="color:#dc3545">
                        <i class="bi bi-x-circle"></i>
                    </button>
                </div>
                <div id="sendError" class="alert alert-danger py-2 mb-2 d-none small"></div>
                <div class="d-flex align-items-end gap-2">
                    <input type="file" id="fileInput" style="display:none" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt">
                    <label for="fileInput" class="attach-btn" id="fileLabel" title="Anexar ficheiro">
                        <i class="bi bi-paperclip fs-5"></i>
                    </label>
                    <textarea class="form-control" id="msgInput"
                              placeholder="Escreve uma mensagem..." rows="1"></textarea>
                    <button class="send-btn" id="sendBtn" title="Enviar">
                        <i class="bi bi-send-fill"></i>
                    </button>
                </div>
            </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="no-chat d-none d-md-flex">
                <i class="bi bi-chat-dots-fill" style="font-size:4rem;opacity:.15"></i>
                <h5 class="mt-3">Seleciona uma conversa</h5>
                <p class="small">Escolhe da lista à esquerda</p>
            </div>
        <?php endif; ?>
    </div>

</div><!-- /chat-body -->
</div><!-- /chat-wrap -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>

<?php if ($contato && !$chat_bloqueado): ?>
<script>
const BASE_URL    = <?php echo json_encode(BASE_URL); ?>;
const CURRENT_UID = <?php echo json_encode((int)$user_id); ?>;
const CONTATO_ID  = <?php echo json_encode((int)$contato_id); ?>;
const MISSAO_ID   = <?php echo json_encode($missao_id); ?>;
const CSRF_TOKEN  = <?php echo json_encode(csrf_token()); ?>;

let lastMsgId = <?php echo json_encode($last_msg_id); ?>;
let pollTimer = null;
let isSending = false;
let isTyping = false;
let typingTimer = null;

const msgBody    = document.getElementById('msgBody');
const msgInput   = document.getElementById('msgInput');
const sendBtn    = document.getElementById('sendBtn');
const sendError  = document.getElementById('sendError');
const statusEl   = document.getElementById('statusIndicator');

function scrollToBottom(smooth = false) {
    msgBody.scrollTo({ top: msgBody.scrollHeight, behavior: smooth ? 'smooth' : 'instant' });
}

function formatTime(dateStr) {
    const d    = new Date(dateStr);
    const now  = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const yesterday = new Date(today - 86400000);
    const dDay = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    const hhmm = d.toLocaleTimeString('pt', { hour: '2-digit', minute: '2-digit' });
    if (dDay >= today)     return 'Hoje às '  + hhmm;
    if (dDay >= yesterday) return 'Ontem às ' + hhmm;
    return d.toLocaleDateString('pt') + ' ' + hhmm;
}

function buildBubble(msg) {
    const wrap = document.createElement('div');
    wrap.className = 'd-flex ' + (msg.is_mine ? 'justify-content-end' : 'justify-content-start');
    wrap.dataset.msgId = msg.id;

    const bubble = document.createElement('div');
    bubble.className = 'bubble ' + (msg.is_mine ? 'bubble-mine' : 'bubble-their');
    if (msg.pending) bubble.classList.add('bubble-pending');

    if (!msg.is_mine) {
        const name = document.createElement('div');
        name.className = 'bubble-name';
        name.textContent = msg.remetente_nome;
        bubble.appendChild(name);
    }

    // Anexos
    if (msg.anexo_url) {
        if (msg.anexo_tipo && msg.anexo_tipo.startsWith('image/')) {
            const img = document.createElement('img');
            img.src = msg.anexo_url;
            img.className = 'bubble-img mb-1';
            img.alt = 'Imagem';
            img.onclick = () => window.open(msg.anexo_url, '_blank');
            bubble.appendChild(img);
        } else {
            const link = document.createElement('a');
            link.href = msg.anexo_url;
            link.target = '_blank';
            link.className = 'bubble-file mb-1';
            link.innerHTML = '<i class="bi bi-file-earmark fs-5"></i><span>' + e(msg.anexo_nome || 'Ficheiro') + '</span><i class="bi bi-download"></i>';
            bubble.appendChild(link);
        }
    }

    if (msg.mensagem) {
        const text = document.createElement('div');
        text.innerHTML = msg.mensagem
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/\n/g,'<br>');
        bubble.appendChild(text);
    }

    const time = document.createElement('div');
    time.className = 'bubble-time';
    let statusIcon = '';
    if (msg.is_mine) {
        if (msg.pending) statusIcon = ' <i class="bi bi-clock"></i>';
        else statusIcon = ' <i class="bi ' + (msg.lida ? 'bi-check-all text-primary' : 'bi-check') + '"></i>';
    }
    time.innerHTML = formatTime(msg.data_envio) + statusIcon;
    bubble.appendChild(time);

    wrap.appendChild(bubble);
    return wrap;
}
function e(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function appendMessage(msg) {
    // Remove placeholder "sem mensagens"
    const placeholder = msgBody.querySelector('.text-center.text-muted');
    if (placeholder) placeholder.remove();

    const el = buildBubble(msg);
    msgBody.appendChild(el);
    if (msg.id > lastMsgId) lastMsgId = msg.id;
}

async function pollMessages() {
    try {
        let url = BASE_URL + '/api/chat-messages.php?user=' + CONTATO_ID + '&after=' + lastMsgId;
        if (MISSAO_ID) url += '&missao=' + MISSAO_ID;
        const res = await fetch(url);
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.ok === false) {
            throw new Error(data.error || ('HTTP ' + res.status));
        }

        if (data.messages && data.messages.length > 0) {
            const wasAtBottom = (msgBody.scrollHeight - msgBody.scrollTop - msgBody.clientHeight) < 60;
            data.messages.forEach(appendMessage);
            if (wasAtBottom) scrollToBottom(true);
        }

        statusEl.innerHTML = '<i class="bi bi-circle-fill text-success" style="font-size:0.5rem"></i> Online';
    } catch (e) {
        statusEl.innerHTML = '<i class="bi bi-circle-fill text-warning" style="font-size:0.5rem"></i> Offline';
        if (sendError) {
            sendError.textContent = e.message || 'Erro ao carregar mensagens.';
            sendError.classList.remove('d-none');
        }
    } finally {
        pollTimer = setTimeout(pollMessages, 4000);
    }
}

async function sendMessage() {
    const text = msgInput.value.trim();
    const fileInput = document.getElementById('fileInput');
    const file = fileInput && fileInput.files[0] ? fileInput.files[0] : null;

    if ((!text && !file) || isSending) return;

    isSending = true;
    sendBtn.disabled = true;
    sendError.classList.add('d-none');

    const form = new FormData();
    form.append('user',     CONTATO_ID);
    form.append('mensagem', text);
    form.append('csrf_token', CSRF_TOKEN);
    if (MISSAO_ID) form.append('missao', MISSAO_ID);
    if (file) form.append('anexo', file);

    // Preview temporário
    const tempId = 'temp-' + Date.now();
    if (text) appendMessage({
        id: tempId, is_mine: true, remetente_nome: 'Eu',
        mensagem: text + (file ? '\n[Enviando ficheiro...]' : ''),
        data_envio: new Date().toISOString(), lida: false, pending: true
    });

    msgInput.value = '';
    msgInput.style.height = 'auto';
    if (fileInput) fileInput.value = '';
    const fileLabel = document.getElementById('fileLabel');
    if (fileLabel) fileLabel.innerHTML = '<i class="bi bi-paperclip"></i>';

    try {
        const res  = await fetch(BASE_URL + '/api/chat-send.php', {
            method: 'POST',
            body: form,
            headers: { 'X-CSRF-Token': CSRF_TOKEN }
        });
        const data = await res.json();

        if (!res.ok || !data.ok) {
            sendError.textContent = data.error || 'Erro ao enviar mensagem.';
            sendError.classList.remove('d-none');
            msgInput.value = text;
            // Remover preview
            const temp = document.querySelector(`[data-msg-id="${tempId}"]`);
            if (temp) temp.remove();
        } else {
            // Substituir preview temporário
            const temp = document.querySelector(`[data-msg-id="${tempId}"]`);
            if (temp) temp.remove();
            appendMessage(data.message);
            scrollToBottom(true);
        }
    } catch (e) {
        sendError.textContent = 'Erro de rede. Tenta novamente.';
        sendError.classList.remove('d-none');
        msgInput.value = text;
        const temp = document.querySelector(`[data-msg-id="${tempId}"]`);
        if (temp) temp.remove();
    } finally {
        isSending = false;
        sendBtn.disabled = false;
        msgInput.focus();
    }
}

// Auto-expand textarea
msgInput.addEventListener('input', function () {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
});

// Enter envia, Shift+Enter nova linha
msgInput.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
});

sendBtn.addEventListener('click', sendMessage);

// ── Mobile: botão voltar ──
const btnBack   = document.getElementById('btnBack');
const sidebar   = document.getElementById('chatSidebar');

function isMobile() { return window.innerWidth < 768; }

if (btnBack) {
    btnBack.addEventListener('click', () => {
        sidebar.classList.remove('hidden-mobile');
    });
}

// Garantir que no desktop o sidebar está sempre visível
window.addEventListener('resize', () => {
    if (!isMobile() && sidebar) sidebar.classList.remove('hidden-mobile');
});

// ── Dark Mode ──
const themeToggle = document.getElementById('themeToggle');
const themeIcon   = document.getElementById('themeIcon');
const savedTheme  = localStorage.getItem('trackmoz-theme');
if (savedTheme === 'dark') {
    document.documentElement.setAttribute('data-theme', 'dark');
    themeIcon.className = 'bi bi-sun-fill';
}
if (themeToggle) {
    themeToggle.addEventListener('click', () => {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        if (isDark) {
            document.documentElement.removeAttribute('data-theme');
            localStorage.setItem('trackmoz-theme', 'light');
            themeIcon.className = 'bi bi-moon-stars';
        } else {
            document.documentElement.setAttribute('data-theme', 'dark');
            localStorage.setItem('trackmoz-theme', 'dark');
            themeIcon.className = 'bi bi-sun-fill';
        }
    });
}

// ── Pesquisa de conversas ──
const convSearch = document.getElementById('convSearch');
if (convSearch) {
    convSearch.addEventListener('input', function() {
        const term = this.value.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        document.querySelectorAll('.conv-item').forEach(item => {
            const nome = item.querySelector('.fw-semibold')?.textContent.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '') || '';
            const preview = item.querySelector('.conv-preview')?.textContent.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '') || '';
            item.style.display = (nome.includes(term) || preview.includes(term)) ? '' : 'none';
        });
    });
}

// ── Pesquisa de mensagens ──
const btnSearchMsg = document.getElementById('btnSearchMsg');
const msgSearchBar = document.getElementById('msgSearchBar');
const msgSearchInput = document.getElementById('msgSearchInput');
const msgSearchCount = document.getElementById('msgSearchCount');
const btnCloseSearch = document.getElementById('btnCloseSearch');
let searchMatches = [];
let currentMatch = -1;

if (btnSearchMsg) {
    btnSearchMsg.addEventListener('click', () => {
        msgSearchBar.classList.toggle('active');
        if (msgSearchBar.classList.contains('active')) msgSearchInput.focus();
    });
}
if (btnCloseSearch) {
    btnCloseSearch.addEventListener('click', () => {
        msgSearchBar.classList.remove('active');
        msgSearchInput.value = '';
        clearMsgHighlights();
    });
}
function clearMsgHighlights() {
    document.querySelectorAll('.bubble .highlight').forEach(el => {
        const parent = el.parentNode;
        parent.insertBefore(document.createTextNode(el.textContent), el);
        parent.removeChild(el);
        parent.normalize();
    });
    msgSearchCount.textContent = '';
}
if (msgSearchInput) {
    msgSearchInput.addEventListener('input', function() {
        clearMsgHighlights();
        const term = this.value.trim();
        if (!term) { msgSearchCount.textContent = ''; return; }
        const regex = new RegExp('(' + term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
        let count = 0;
        document.querySelectorAll('.bubble div:not(.bubble-name):not(.bubble-time)').forEach(div => {
            if (div.querySelector('.bubble-file') || div.querySelector('img')) return;
            if (regex.test(div.textContent)) {
                div.innerHTML = div.textContent.replace(regex, '<span class="highlight">$1</span>');
                count++;
            }
        });
        msgSearchCount.textContent = count + ' resultado' + (count !== 1 ? 's' : '');
    });
}

// ── Upload de ficheiros ──
const fileInput = document.getElementById('fileInput');
const filePreview = document.getElementById('filePreview');
const fileName = document.getElementById('fileName');
const btnRemoveFile = document.getElementById('btnRemoveFile');
if (fileInput) {
    fileInput.addEventListener('change', function() {
        if (this.files[0]) {
            fileName.textContent = this.files[0].name;
            filePreview.classList.remove('d-none');
        }
    });
}
if (btnRemoveFile) {
    btnRemoveFile.addEventListener('click', () => {
        fileInput.value = '';
        filePreview.classList.add('d-none');
    });
}

// ── Indicador de digitação ──
const typingEl = document.getElementById('typingIndicator');
msgInput.addEventListener('input', () => {
    if (isTyping) return;
    isTyping = true;
    // Aqui poderia enviar evento de typing para o servidor
    clearTimeout(typingTimer);
    typingTimer = setTimeout(() => { isTyping = false; }, 3000);
});

// ── Som de notificação (opcional) ──
const audioCtx = window.AudioContext || window.webkitAudioContext;
function playNotificationSound() {
    if (!audioCtx) return;
    try {
        const ctx = new audioCtx();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.frequency.value = 600;
        gain.gain.value = 0.05;
        osc.start();
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.15);
        osc.stop(ctx.currentTime + 0.15);
    } catch(e) {}
}

// Iniciar
scrollToBottom();
pollMessages();
</script>
<?php endif; ?>
</body>
</html>
