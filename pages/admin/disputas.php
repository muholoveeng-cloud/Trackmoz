<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');
include_once('../../includes/disputas-helpers.php');

require_role(['admin'], '../login.php');
disputas_bootstrap($conn);

$disputaId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$statusFiltro = $_GET['status'] ?? '';
$q = trim($_GET['q'] ?? '');

$contagens = disputas_contagens($conn);
$lista = disputas_tabela_existe($conn)
    ? disputas_listar($conn, $statusFiltro ?: null, $q ?: null, 120)
    : [];

$disputa = $disputaId > 0 ? disputa_obter($conn, $disputaId) : null;
$mensagens = $disputa ? disputa_listar_mensagens($conn, $disputaId, true) : [];
$evidencias = $disputa ? disputa_listar_evidencias($conn, $disputaId) : [];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disputas — TrackMoz Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/profissional.css">
    <style>
        .dsp-kpi { border-radius: 1rem; border: 1px solid #e2e8f0; background: #fff; padding: 1rem 1.15rem; height: 100%; box-shadow: 0 4px 6px -1px rgb(0 0 0 / .06); }
        .dsp-kpi b { font-size: 1.6rem; font-weight: 800; display: block; line-height: 1.1; color: #0f172a; }
        .dsp-kpi span { font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; color: #64748b; font-weight: 700; }
        .dsp-kpi.danger b { color: #dc2626; }
        .dsp-kpi.warning b { color: #d97706; }
        .dsp-kpi.success b { color: #16a34a; }
        .dsp-thread { max-height: 380px; overflow-y: auto; background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0; padding: 12px; }
        .dsp-msg { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 12px; margin-bottom: 8px; }
        .dsp-msg.interno { border-left: 3px solid #f59e0b; background: #fffbeb; }
        .dsp-msg.admin { border-left: 3px solid #2563eb; }
        .dsp-prio-urgente { color: #dc2626; font-weight: 700; }
        .dsp-prio-alta { color: #d97706; font-weight: 700; }
        .dsp-card { border: 1px solid #e2e8f0; border-radius: 1rem; box-shadow: 0 4px 12px rgba(15,23,42,.05); }
        .dsp-ev { font-size: .85rem; }
    </style>
</head>
<body>
<?php include_once('../../includes/menu.php'); ?>

<div class="container-fluid py-4 px-3 px-lg-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-shield-exclamation text-warning"></i> Centro de Disputas</h1>
            <p class="text-muted mb-0 small">Mediação comercial: análise, evidências, decisão e encerramento.</p>
        </div>
    </div>

    <?php if (!disputas_tabela_existe($conn)): ?>
        <div class="alert alert-warning">Módulo indisponível. Execute a migration de regras de negócio.</div>
    <?php else: ?>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <a href="disputas.php" class="text-decoration-none">
                <div class="dsp-kpi"><span>Total</span><b><?php echo (int)$contagens['total']; ?></b></div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="?status=aberta" class="text-decoration-none">
                <div class="dsp-kpi danger"><span>Abertas</span><b><?php echo (int)$contagens['aberta']; ?></b></div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="?status=em_analise" class="text-decoration-none">
                <div class="dsp-kpi warning"><span>Em análise</span><b><?php echo (int)$contagens['em_analise']; ?></b></div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="?status=encerrada" class="text-decoration-none">
                <div class="dsp-kpi success"><span>Encerradas</span><b><?php echo (int)$contagens['encerrada']; ?></b></div>
            </a>
        </div>
    </div>

    <?php if ($disputa): ?>
    <div class="card dsp-card mb-4">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
            <div>
                <strong>Disputa #<?php echo (int)$disputa['id']; ?></strong>
                <span class="badge bg-<?php echo disputa_status_badge($disputa['status']); ?> ms-2">
                    <?php echo e(disputa_status_label($disputa['status'])); ?>
                </span>
                <?php if (!empty($disputa['prioridade']) && $disputa['prioridade'] !== 'normal'): ?>
                    <span class="dsp-prio-<?php echo e($disputa['prioridade']); ?> ms-2 small text-uppercase">
                        <?php echo e($disputa['prioridade']); ?>
                    </span>
                <?php endif; ?>
            </div>
            <a href="disputas.php<?php echo $statusFiltro ? '?status=' . urlencode($statusFiltro) : ''; ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Lista
            </a>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-lg-5">
                    <h2 class="h6 text-uppercase text-muted">Contexto</h2>
                    <p class="mb-1">
                        <strong>Missão:</strong>
                        <a href="ver-missao.php?id=<?php echo (int)$disputa['missao_id']; ?>">
                            <?php echo e($disputa['titulo']); ?> (#<?php echo (int)$disputa['missao_id']; ?>)
                        </a>
                    </p>
                    <p class="mb-1 small text-muted"><?php echo e(($disputa['origem'] ?? '') . ' → ' . ($disputa['destino'] ?? '')); ?></p>
                    <p class="mb-1"><strong>Categoria:</strong> <?php echo e(disputa_categoria_label($disputa['categoria'] ?? null)); ?></p>
                    <p class="mb-1"><strong>Reclamante:</strong> <?php echo e($disputa['aberto_por_nome']); ?>
                        <span class="badge bg-light text-dark"><?php echo e($disputa['aberto_por_tipo'] ?? ''); ?></span>
                    </p>
                    <p class="mb-1 small"><strong>Empresa:</strong> <?php echo e($disputa['empresa_nome'] ?? '—'); ?></p>
                    <p class="mb-1 small"><strong>Motorista:</strong> <?php echo e($disputa['motorista_nome'] ?? '—'); ?></p>
                    <p class="mb-1 small"><strong>Transportador:</strong> <?php echo e($disputa['transportador_nome'] ?? '—'); ?></p>
                    <p class="mb-0 small text-muted">Aberta em <?php echo date('d/m/Y H:i', strtotime($disputa['created_at'])); ?></p>
                    <?php if (!empty($disputa['assumido_por_nome'])): ?>
                        <p class="mb-0 small text-muted">Assumida por <?php echo e($disputa['assumido_por_nome']); ?></p>
                    <?php endif; ?>

                    <div class="mt-3 p-3 bg-light rounded-3">
                        <div class="small text-muted mb-1">Motivo inicial</div>
                        <?php echo nl2br(e($disputa['motivo'])); ?>
                    </div>

                    <?php if ($disputa['status'] === 'encerrada'): ?>
                        <div class="alert alert-success mt-3 mb-0">
                            <strong><?php echo e(disputa_resultado_label($disputa['resultado'] ?? null)); ?></strong><br>
                            <?php echo nl2br(e($disputa['resolucao'] ?? '')); ?>
                            <div class="small mt-2 mb-0">
                                Encerrada por <?php echo e($disputa['encerrado_por_nome'] ?? '—'); ?>
                                <?php if (!empty($disputa['encerrado_em'])): ?>
                                    · <?php echo date('d/m/Y H:i', strtotime($disputa['encerrado_em'])); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <h2 class="h6 text-uppercase text-muted mt-4">Evidências</h2>
                    <?php if (!$evidencias): ?>
                        <p class="small text-muted">Nenhuma evidência anexada.</p>
                    <?php else: ?>
                        <ul class="list-unstyled dsp-ev">
                            <?php foreach ($evidencias as $ev): ?>
                                <li class="mb-2">
                                    <a href="<?php echo BASE_URL . '/' . e($ev['caminho_arquivo']); ?>" target="_blank" rel="noopener">
                                        <i class="bi bi-paperclip"></i> <?php echo e($ev['nome_arquivo']); ?>
                                    </a>
                                    <div class="text-muted small"><?php echo e($ev['autor_nome']); ?> · <?php echo date('d/m/Y H:i', strtotime($ev['created_at'])); ?></div>
                                    <?php if (!empty($ev['descricao'])): ?><div class="small"><?php echo e($ev['descricao']); ?></div><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($disputa['status'] !== 'encerrada'): ?>
                    <form id="formEvidencia" class="mt-2" enctype="multipart/form-data">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="disputa_id" value="<?php echo (int)$disputa['id']; ?>">
                        <div class="input-group input-group-sm">
                            <input type="file" name="ficheiro" class="form-control" required>
                            <button class="btn btn-outline-primary" type="submit">Anexar</button>
                        </div>
                        <input type="text" name="descricao" class="form-control form-control-sm mt-1" placeholder="Descrição (opcional)">
                    </form>
                    <?php endif; ?>
                </div>

                <div class="col-lg-7">
                    <h2 class="h6 text-uppercase text-muted">Mediação</h2>
                    <div class="dsp-thread mb-3" id="dsp-thread">
                        <?php if (!$mensagens): ?>
                            <p class="text-muted small mb-0">Ainda sem mensagens.</p>
                        <?php else: foreach ($mensagens as $msg): ?>
                            <div class="dsp-msg <?php echo !empty($msg['interno']) ? 'interno' : ''; ?> <?php echo ($msg['autor_tipo'] ?? '') === 'admin' ? 'admin' : ''; ?>">
                                <div class="d-flex justify-content-between gap-2 small mb-1">
                                    <strong><?php echo e($msg['autor_nome']); ?>
                                        <span class="text-muted fw-normal">(<?php echo e($msg['autor_tipo']); ?>)</span>
                                        <?php if (!empty($msg['interno'])): ?><span class="badge bg-warning text-dark">Interno</span><?php endif; ?>
                                    </strong>
                                    <span class="text-muted"><?php echo date('d/m/Y H:i', strtotime($msg['created_at'])); ?></span>
                                </div>
                                <div><?php echo nl2br(e($msg['mensagem'])); ?></div>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>

                    <?php if ($disputa['status'] !== 'encerrada'): ?>
                    <form id="formMsg" class="mb-3">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="disputa_id" value="<?php echo (int)$disputa['id']; ?>">
                        <textarea name="mensagem" class="form-control mb-2" rows="3" required placeholder="Mensagem às partes ou nota de mediação…"></textarea>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="interno" value="1" id="msgInterno">
                                <label class="form-check-label small" for="msgInterno">Nota interna (só admin)</label>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm ms-auto"><i class="bi bi-send"></i> Enviar</button>
                        </div>
                    </form>

                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <?php if ($disputa['status'] === 'aberta'): ?>
                        <button type="button" class="btn btn-warning" id="btnAssumir">
                            <i class="bi bi-person-check"></i> Assumir e analisar
                        </button>
                        <?php endif; ?>
                    </div>

                    <div class="border rounded-3 p-3 bg-white">
                        <h3 class="h6 mb-2">Encerrar disputa (decisão final)</h3>
                        <form id="formEncerrar">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="disputa_id" value="<?php echo (int)$disputa['id']; ?>">
                            <div class="mb-2">
                                <label class="form-label small">Resultado *</label>
                                <select name="resultado" class="form-select form-select-sm" required>
                                    <?php foreach (DISPUTA_RESULTADOS as $k => $lab): ?>
                                        <option value="<?php echo e($k); ?>"><?php echo e($lab); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">Resolução / fundamentação *</label>
                                <textarea name="resolucao" class="form-control" rows="3" required
                                          placeholder="Explique a decisão para as partes…"></textarea>
                            </div>
                            <button type="submit" class="btn btn-success btn-sm">
                                <i class="bi bi-check2-circle"></i> Encerrar disputa
                            </button>
                            <div id="msgEncerrar" class="small mt-2"></div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card dsp-card">
        <div class="card-header bg-white py-3">
            <form class="row g-2 align-items-center" method="get">
                <?php if ($disputaId): ?><input type="hidden" name="id" value="<?php echo $disputaId; ?>"><?php endif; ?>
                <div class="col-auto">
                    <div class="btn-group btn-group-sm">
                        <a href="disputas.php" class="btn <?php echo !$statusFiltro ? 'btn-dark' : 'btn-outline-secondary'; ?>">Todas</a>
                        <a href="?status=aberta" class="btn <?php echo $statusFiltro === 'aberta' ? 'btn-danger' : 'btn-outline-danger'; ?>">Abertas</a>
                        <a href="?status=em_analise" class="btn <?php echo $statusFiltro === 'em_analise' ? 'btn-warning' : 'btn-outline-warning'; ?>">Em análise</a>
                        <a href="?status=encerrada" class="btn <?php echo $statusFiltro === 'encerrada' ? 'btn-success' : 'btn-outline-success'; ?>">Encerradas</a>
                    </div>
                </div>
                <div class="col">
                    <input type="search" name="q" value="<?php echo e($q); ?>" class="form-control form-control-sm" placeholder="Pesquisar #, missão, reclamante…">
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-primary">Filtrar</button>
                </div>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Missão / partes</th>
                        <th>Categoria</th>
                        <th>Reclamante</th>
                        <th>Estado</th>
                        <th>Data</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$lista): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Nenhuma disputa neste filtro.</td></tr>
                <?php else: foreach ($lista as $row): ?>
                    <tr class="<?php echo $disputaId === (int)$row['id'] ? 'table-primary' : ''; ?>">
                        <td class="fw-semibold"><?php echo (int)$row['id']; ?></td>
                        <td>
                            <div class="fw-semibold"><?php echo e($row['missao_titulo']); ?></div>
                            <div class="small text-muted">
                                Missão #<?php echo (int)$row['missao_id']; ?>
                                · <?php echo e(mb_strimwidth($row['motivo'] ?? '', 0, 70, '…')); ?>
                            </div>
                            <div class="small text-muted">
                                <?php echo e($row['empresa_nome'] ?? ''); ?>
                                <?php if (!empty($row['motorista_nome'])): ?> · <?php echo e($row['motorista_nome']); ?><?php endif; ?>
                            </div>
                        </td>
                        <td class="small"><?php echo e(disputa_categoria_label($row['categoria'] ?? null)); ?></td>
                        <td>
                            <?php echo e($row['aberto_por_nome']); ?>
                            <div class="small text-muted"><?php echo e($row['aberto_por_tipo'] ?? ''); ?></div>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo disputa_status_badge($row['status']); ?>">
                                <?php echo e(disputa_status_label($row['status'])); ?>
                            </span>
                            <?php if (($row['prioridade'] ?? '') === 'urgente'): ?>
                                <div class="small dsp-prio-urgente">Urgente</div>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?php echo date('d/m/Y H:i', strtotime($row['created_at'])); ?></td>
                        <td>
                            <a class="btn btn-sm btn-outline-primary" href="?id=<?php echo (int)$row['id']; ?><?php echo $statusFiltro ? '&status=' . urlencode($statusFiltro) : ''; ?>">
                                Gerir
                            </a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
const CSRF = <?php echo json_encode(csrf_token()); ?>;
const DID = <?php echo (int)$disputaId; ?>;
const BASE = <?php echo json_encode(BASE_URL); ?>;

async function postForm(url, form) {
    const fd = form instanceof FormData ? form : new FormData(form);
    if (!fd.has('csrf_token')) fd.append('csrf_token', CSRF);
    const r = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
    return r.json();
}

const formEncerrar = document.getElementById('formEncerrar');
if (formEncerrar) {
    formEncerrar.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!confirm('Confirma encerrar esta disputa? A decisão será comunicada às partes.')) return;
        const msg = document.getElementById('msgEncerrar');
        msg.textContent = 'A processar…';
        try {
            const d = await postForm(BASE + '/api/disputa-encerrar.php', formEncerrar);
            if (d.success) location.reload();
            else msg.innerHTML = '<span class="text-danger">' + (d.message || 'Erro') + '</span>';
        } catch (err) {
            msg.innerHTML = '<span class="text-danger">Erro de ligação</span>';
        }
    });
}

const btnAssumir = document.getElementById('btnAssumir');
if (btnAssumir) {
    btnAssumir.addEventListener('click', async () => {
        const fd = new FormData();
        fd.append('disputa_id', DID);
        fd.append('csrf_token', CSRF);
        const d = await postForm(BASE + '/api/disputa-status.php', fd);
        if (d.success) location.reload();
        else alert(d.message || 'Erro');
    });
}

const formMsg = document.getElementById('formMsg');
if (formMsg) {
    formMsg.addEventListener('submit', async (e) => {
        e.preventDefault();
        const d = await postForm(BASE + '/api/disputa-mensagem.php', formMsg);
        if (d.success) location.reload();
        else alert(d.message || 'Erro');
    });
}

const formEv = document.getElementById('formEvidencia');
if (formEv) {
    formEv.addEventListener('submit', async (e) => {
        e.preventDefault();
        const d = await postForm(BASE + '/api/disputa-evidencia.php', formEv);
        if (d.success) location.reload();
        else alert(d.message || 'Erro');
    });
}

const th = document.getElementById('dsp-thread');
if (th) th.scrollTop = th.scrollHeight;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
