<?php
/**
 * Vista da disputa para as partes (empresa / motorista / transportador).
 */
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');
include_once('../../includes/disputas-helpers.php');

require_login('../login.php');

$userId = (int)$_SESSION['user_id'];
$userType = (string)($_SESSION['user_type'] ?? '');

if ($userType === 'admin') {
    $id = (int)($_GET['id'] ?? 0);
    header('Location: ' . BASE_URL . '/pages/admin/disputas.php' . ($id ? '?id=' . $id : ''));
    exit;
}

if (!in_array($userType, ['empresa', 'transportador', 'caminhoneiro'], true)) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

disputas_bootstrap($conn);

$disputaId = (int)($_GET['id'] ?? 0);
$missaoId = (int)($_GET['missao_id'] ?? 0);

if ($disputaId <= 0 && $missaoId > 0) {
    $tmp = disputa_da_missao($conn, $missaoId);
    if ($tmp) {
        $disputaId = (int)$tmp['id'];
    }
}

$disputa = $disputaId > 0 ? disputa_obter($conn, $disputaId) : null;

if (!$disputa || !disputa_utilizador_pode_ver($disputa, $userId, $userType)) {
    http_response_code(403);
    echo 'Disputa não encontrada ou sem permissão.';
    exit;
}

$mensagens = disputa_listar_mensagens($conn, $disputaId, false);
$evidencias = disputa_listar_evidencias($conn, $disputaId);
$podeInteragir = disputa_utilizador_pode_interagir($disputa, $userId, $userType);
$voltar = disputa_url_missao_para_tipo($userType, (int)$disputa['missao_id']);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disputa #<?php echo $disputaId; ?> — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <style>
        .dsp-msg { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:10px 12px; margin-bottom:8px; }
        .dsp-thread { background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:12px; max-height:420px; overflow-y:auto; }
    </style>
</head>
<body>
<?php include_once('../../includes/menu.php'); ?>
<div class="container py-4" style="max-width:860px">
    <a href="<?php echo e($voltar); ?>" class="btn btn-sm btn-outline-secondary mb-3">
        <i class="bi bi-arrow-left"></i> Voltar à missão
    </a>

    <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1"><i class="bi bi-shield-exclamation text-warning"></i> Disputa #<?php echo (int)$disputa['id']; ?></h1>
            <p class="text-muted mb-0 small"><?php echo e($disputa['titulo']); ?> · missão #<?php echo (int)$disputa['missao_id']; ?></p>
        </div>
        <span class="badge bg-<?php echo disputa_status_badge($disputa['status']); ?> fs-6">
            <?php echo e(disputa_status_label($disputa['status'])); ?>
        </span>
    </div>

    <div class="alert alert-light border">
        <div class="small text-muted mb-1">Categoria · <?php echo e(disputa_categoria_label($disputa['categoria'] ?? null)); ?></div>
        <strong>Motivo</strong>
        <div><?php echo nl2br(e($disputa['motivo'])); ?></div>
    </div>

    <?php if ($disputa['status'] === 'encerrada'): ?>
        <div class="alert alert-success">
            <strong>Decisão:</strong> <?php echo e(disputa_resultado_label($disputa['resultado'] ?? null)); ?><br>
            <?php echo nl2br(e($disputa['resolucao'] ?? '')); ?>
        </div>
    <?php elseif ($disputa['status'] === 'em_analise'): ?>
        <div class="alert alert-warning">A administração está a analisar este caso. Pode enviar mensagens e evidências.</div>
    <?php else: ?>
        <div class="alert alert-danger">Disputa aberta — aguarda atribuição do mediador.</div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-md-7">
            <h2 class="h6 text-uppercase text-muted">Conversa</h2>
            <div class="dsp-thread mb-3" id="thread">
                <?php if (!$mensagens): ?>
                    <p class="text-muted small mb-0">Sem mensagens ainda.</p>
                <?php else: foreach ($mensagens as $msg): ?>
                    <div class="dsp-msg">
                        <div class="d-flex justify-content-between small mb-1">
                            <strong><?php echo e($msg['autor_nome']); ?></strong>
                            <span class="text-muted"><?php echo date('d/m/Y H:i', strtotime($msg['created_at'])); ?></span>
                        </div>
                        <div><?php echo nl2br(e($msg['mensagem'])); ?></div>
                    </div>
                <?php endforeach; endif; ?>
            </div>

            <?php if ($podeInteragir): ?>
            <form id="formMsg">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="disputa_id" value="<?php echo (int)$disputa['id']; ?>">
                <textarea name="mensagem" class="form-control mb-2" rows="3" required placeholder="Escreva à administração / outra parte…"></textarea>
                <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-send"></i> Enviar</button>
            </form>
            <?php endif; ?>
        </div>
        <div class="col-md-5">
            <h2 class="h6 text-uppercase text-muted">Evidências</h2>
            <?php if (!$evidencias): ?>
                <p class="small text-muted">Nenhuma evidência.</p>
            <?php else: ?>
                <ul class="list-unstyled small">
                    <?php foreach ($evidencias as $ev): ?>
                        <li class="mb-2">
                            <a href="<?php echo BASE_URL . '/' . e($ev['caminho_arquivo']); ?>" target="_blank" rel="noopener">
                                <i class="bi bi-paperclip"></i> <?php echo e($ev['nome_arquivo']); ?>
                            </a>
                            <div class="text-muted"><?php echo e($ev['autor_nome']); ?> · <?php echo date('d/m/Y', strtotime($ev['created_at'])); ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($podeInteragir): ?>
            <form id="formEv" enctype="multipart/form-data" class="mt-2">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="disputa_id" value="<?php echo (int)$disputa['id']; ?>">
                <input type="file" name="ficheiro" class="form-control form-control-sm mb-1" required>
                <input type="text" name="descricao" class="form-control form-control-sm mb-2" placeholder="Descrição">
                <button class="btn btn-outline-primary btn-sm" type="submit">Anexar evidência</button>
            </form>
            <?php endif; ?>

            <hr>
            <p class="small text-muted mb-0">
                Partes: <?php echo e($disputa['empresa_nome'] ?? '—'); ?>
                <?php if (!empty($disputa['motorista_nome'])): ?> · <?php echo e($disputa['motorista_nome']); ?><?php endif; ?>
            </p>
        </div>
    </div>
</div>
<script>
const BASE = <?php echo json_encode(BASE_URL); ?>;
async function send(url, form) {
    const r = await fetch(url, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
    return r.json();
}
const fm = document.getElementById('formMsg');
if (fm) fm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const d = await send(BASE + '/api/disputa-mensagem.php', fm);
    if (d.success) location.reload(); else alert(d.message || 'Erro');
});
const fe = document.getElementById('formEv');
if (fe) fe.addEventListener('submit', async (e) => {
    e.preventDefault();
    const d = await send(BASE + '/api/disputa-evidencia.php', fe);
    if (d.success) location.reload(); else alert(d.message || 'Erro');
});
const th = document.getElementById('thread');
if (th) th.scrollTop = th.scrollHeight;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
