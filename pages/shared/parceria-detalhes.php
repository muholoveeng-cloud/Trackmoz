<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');
include_once('../../includes/parceria-helpers.php');

$permitidos = ['empresa','transportador','admin'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'] ?? '', $permitidos, true)) {
    header('Location: ' . BASE_URL . '/pages/login.php'); exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_type = $_SESSION['user_type'];
$parceria_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$p = null;
$negociacoes = [];
$missoes = [];
$error = null;

if ($parceria_id <= 0) {
    header('Location: ' . BASE_URL . '/pages/' . ($user_type === 'empresa' ? 'contratante' : ($user_type === 'transportador' ? 'transportador' : 'admin')) . '/parcerias.php');
    exit;
}

function pv(?array $row, string $key, $default = null) {
    return $row[$key] ?? $default;
}

try {
    $conn = getConnection();

    $stmt = $conn->prepare(
        "SELECT p.*,
                pe.nome_empresa AS nome_empresa_contratante,
                pt.nome_empresa AS nome_transportadora,
                ue.email AS email_empresa, ue.telefone AS tel_empresa,
                ut.email AS email_transportador, ut.telefone AS tel_transportador
         FROM parcerias p
         JOIN perfil_empresa pe ON p.empresa_id = pe.usuario_id
         JOIN perfil_transportador pt ON p.transportador_id = pt.usuario_id
         JOIN usuarios ue ON p.empresa_id = ue.id
         JOIN usuarios ut ON p.transportador_id = ut.id
         WHERE p.id = :id"
    );
    $stmt->execute([':id' => $parceria_id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$p) {
        header('Location: ' . BASE_URL . '/pages/' . ($user_type === 'empresa' ? 'contratante' : 'transportador') . '/parcerias.php');
        exit;
    }

    if ($user_type === 'empresa' && (int)$p['empresa_id'] !== $user_id) { header('Location: ' . BASE_URL . '/pages/contratante/parcerias.php'); exit; }
    if ($user_type === 'transportador' && (int)$p['transportador_id'] !== $user_id) { header('Location: ' . BASE_URL . '/pages/transportador/parcerias.php'); exit; }

    try {
        $stmt = $conn->prepare("SELECT * FROM parceria_negociacoes WHERE parceria_id = :pid ORDER BY versao DESC, data_criacao DESC");
        $stmt->execute([':pid' => $parceria_id]);
        $negociacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('parceria-detalhes negociacoes: ' . $e->getMessage());
        $negociacoes = [];
    }

    try {
        $stmt = $conn->prepare("SELECT id, titulo, status, data_criacao FROM missoes WHERE parceria_id = :pid ORDER BY data_criacao DESC LIMIT 10");
        $stmt->execute([':pid' => $parceria_id]);
        $missoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('parceria-detalhes missoes: ' . $e->getMessage());
        $missoes = [];
    }

} catch (PDOException $e) {
    error_log('parceria-detalhes: ' . $e->getMessage());
    $error = 'Erro ao carregar detalhes.';
}

if (!$p) {
    $error = $error ?: 'Parceria não encontrada.';
}

function badge_status_parceria(string $s): string {
    return match($s) {
        'rascunho' => '<span class="badge bg-light text-dark">Rascunho</span>',
        'pedido_enviado' => '<span class="badge bg-info">Pedido Enviado</span>',
        'em_negociacao' => '<span class="badge bg-warning text-dark">Em Negociação</span>',
        'aguardando_aprovacao_empresa' => '<span class="badge bg-primary">Aguardando Aprovação Contratante</span>',
        'aguardando_aprovacao_transportador' => '<span class="badge bg-primary">Aguardando Aprovação Transportadora</span>',
        'aguardando_validacao_admin' => '<span class="badge bg-secondary">Aguardando Admin</span>',
        'ativa' => '<span class="badge bg-success">Activa</span>',
        'suspensa' => '<span class="badge bg-secondary">Suspensa</span>',
        'expirada' => '<span class="badge bg-dark">Expirada</span>',
        'cancelada' => '<span class="badge bg-danger">Cancelada</span>',
        default => '<span class="badge bg-light text-dark">' . e($s) . '</span>',
    };
}

if ($p) {
$meuPapel = $user_type === 'empresa' ? 'empresa' : ($user_type === 'transportador' ? 'transportador' : 'admin');
$outroNome = $meuPapel === 'empresa' ? pv($p, 'nome_transportadora', '—') : pv($p, 'nome_empresa_contratante', '—');
$outroPapel = $meuPapel === 'empresa' ? 'transportador' : 'empresa';
$aprovadoMeu = (int)($meuPapel === 'empresa' ? pv($p, 'aprovado_por_empresa', 0) : pv($p, 'aprovado_por_transportador', 0));
$aprovadoOutro = (int)($meuPapel === 'empresa' ? pv($p, 'aprovado_por_transportador', 0) : pv($p, 'aprovado_por_empresa', 0));

$voltar = BASE_URL . '/pages/' . ($user_type === 'empresa' ? 'contratante' : ($user_type === 'transportador' ? 'transportador' : 'admin')) . '/parcerias.php';
} else {
    $voltar = BASE_URL . '/pages/' . ($user_type === 'empresa' ? 'contratante' : ($user_type === 'transportador' ? 'transportador' : 'admin')) . '/parcerias.php';
}
?>
<!DOCTYPE html>
<html lang="pt">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Parceria #<?php echo $parceria_id; ?> — TrackMoz</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css"></head>
<body>
<?php include_once('../../includes/menu.php'); ?>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0"><i class="bi bi-file-earmark-text me-2 text-primary"></i>Detalhes da Parceria</h4>
            <div class="small text-muted">#<?php echo $parceria_id; ?> · <?php echo badge_status_parceria((string)pv($p, 'status', 'pendente')); ?></div>
        </div>
        <div class="d-flex gap-2">
            <?php if ($p && in_array((string)pv($p, 'status', ''), ['ativa', 'suspensa'], true)): ?>
                <a href="<?php echo BASE_URL; ?>/pages/contratante/documentos/contrato-parceria.php?id=<?php echo (int)$parceria_id; ?>"
                   class="btn btn-outline-primary btn-sm" target="_blank">
                    <i class="bi bi-file-earmark-text me-1"></i>Ver contrato
                </a>
            <?php endif; ?>
            <a href="<?php echo $voltar; ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Voltar</a>
        </div>
    </div>

    <?php if (!empty($error) && !$p): ?><div class="alert alert-danger"><?php echo e($error ?: 'Parceria não encontrada.'); ?></div><?php endif; ?>

    <?php if ($p): ?>
    <div class="row g-3">
        <!-- Dados da parceria -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-transparent fw-semibold"><i class="bi bi-building me-2"></i>Empresas</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="small text-muted">Contratante</div>
                            <div class="fw-semibold"><?php echo e($p['nome_empresa_contratante']); ?></div>
                            <div class="small"><?php echo e($p['email_empresa']); ?> · <?php echo e($p['tel_empresa']); ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="small text-muted">Transportadora</div>
                            <div class="fw-semibold"><?php echo e($p['nome_transportadora']); ?></div>
                            <div class="small"><?php echo e($p['email_transportador']); ?> · <?php echo e($p['tel_transportador']); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-transparent fw-semibold"><i class="bi bi-file-earmark-text me-2"></i>Termos do Contrato</div>
                <div class="card-body">
                    <div class="row g-3 small">
                        <div class="col-md-4"><div class="text-muted">Tipo de contrato</div><div class="fw-semibold"><?php echo e(pv($p, 'tipo_contrato', 'Parceria logística')); ?></div></div>
                        <div class="col-md-4"><div class="text-muted">Início</div><div class="fw-semibold"><?php echo pv($p, 'data_inicio') ? date('d/m/Y', strtotime($p['data_inicio'])) : '—'; ?></div></div>
                        <div class="col-md-4"><div class="text-muted">Fim</div><div class="fw-semibold"><?php echo pv($p, 'data_fim') ? date('d/m/Y', strtotime($p['data_fim'])) : 'Indeterminado'; ?></div></div>
                        <div class="col-md-4"><div class="text-muted">Valor por missão</div><div class="fw-semibold"><?php echo pv($p, 'valor_missao') ? number_format((float)$p['valor_missao'],2,',','.').' MT' : '—'; ?></div></div>
                        <div class="col-md-4"><div class="text-muted">Valor por KM</div><div class="fw-semibold"><?php echo pv($p, 'valor_km') ? number_format((float)$p['valor_km'],4,',','.').' MT' : '—'; ?></div></div>
                        <div class="col-md-4"><div class="text-muted">Valor mensal</div><div class="fw-semibold"><?php echo pv($p, 'valor_mensal') ? number_format((float)$p['valor_mensal'],2,',','.').' MT' : '—'; ?></div></div>
                        <div class="col-md-4"><div class="text-muted">Comissão plataforma</div><div class="fw-semibold"><?php echo (float)pv($p, 'comissao_plataforma_pct', 0); ?>%</div></div>
                        <div class="col-md-4"><div class="text-muted">Condições pagamento</div><div class="fw-semibold"><?php echo e(pv($p, 'condicoes_pagamento', '—')); ?></div></div>
                        <div class="col-md-4"><div class="text-muted">SLA resposta</div><div class="fw-semibold"><?php echo (int)pv($p, 'sla_resposta_horas', 24); ?>h</div></div>
                        <div class="col-md-4"><div class="text-muted">Penalidade atraso</div><div class="fw-semibold"><?php echo (float)pv($p, 'penalidade_atraso_pct', 0); ?>%</div></div>
                        <div class="col-md-4"><div class="text-muted">Responsabilidade carga</div><div class="fw-semibold"><?php echo e(pv($p, 'responsabilidade_carga', '—')); ?></div></div>
                        <div class="col-md-4"><div class="text-muted">Exclusiva</div><div class="fw-semibold"><?php echo (int)pv($p, 'exclusiva', 0) ? 'Sim' : 'Não'; ?></div></div>
                        <?php if (pv($p, 'tipos_carga_permitidos')): ?>
                            <div class="col-12"><div class="text-muted">Tipos de carga permitidos</div><div><?php echo e($p['tipos_carga_permitidos']); ?></div></div>
                        <?php endif; ?>
                        <?php if (pv($p, 'rotas_cobertas')): ?>
                            <div class="col-12"><div class="text-muted">Rotas cobertas</div><div><?php echo e($p['rotas_cobertas']); ?></div></div>
                        <?php endif; ?>
                        <?php if (pv($p, 'descricao')): ?>
                            <div class="col-12"><div class="text-muted">Descrição</div><div><?php echo nl2br(e($p['descricao'])); ?></div></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Histórico de negociação -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent fw-semibold"><i class="bi bi-clock-history me-2"></i>Histórico de Negociação</div>
                <div class="card-body">
                    <?php if (empty($negociacoes)): ?>
                        <div class="text-muted small">Sem histórico registado.</div>
                    <?php else: ?>
                        <div class="timeline small">
                            <?php foreach ($negociacoes as $n): ?>
                                <?php echo parceria_negociacao_html($n); ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Ações e missões -->
        <div class="col-lg-4">
            <!-- Estado de aprovação -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-transparent fw-semibold"><i class="bi bi-check-circle me-2"></i>Aprovações</div>
                <div class="card-body small">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Contratante</span>
                        <span class="badge bg-<?php echo (int)pv($p, 'aprovado_por_empresa', 0) ? 'success' : 'warning text-dark'; ?>"><?php echo (int)pv($p, 'aprovado_por_empresa', 0) ? 'Aprovado' : 'Pendente'; ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Transportadora</span>
                        <span class="badge bg-<?php echo (int)pv($p, 'aprovado_por_transportador', 0) ? 'success' : 'warning text-dark'; ?>"><?php echo (int)pv($p, 'aprovado_por_transportador', 0) ? 'Aprovado' : 'Pendente'; ?></span>
                    </div>
                    <?php if ((int)pv($p, 'requer_validacao_admin', 0)): ?>
                        <div class="d-flex justify-content-between">
                            <span>Admin</span>
                            <span class="badge bg-<?php echo (int)pv($p, 'validado_por_admin', 0) ? 'success' : 'secondary'; ?>"><?php echo (int)pv($p, 'validado_por_admin', 0) ? 'Validado' : 'Pendente'; ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Ações -->
            <?php if ($user_type !== 'admin' && in_array(pv($p, 'status', ''), ['pedido_enviado','em_negociacao','aguardando_aprovacao_empresa','aguardando_aprovacao_transportador'], true)): ?>
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-transparent fw-semibold"><i class="bi bi-gear me-2"></i>Acções</div>
                    <div class="card-body">
                        <?php if (pv($p, 'status') === 'pedido_enviado' && $meuPapel === 'transportador'): ?>
                            <button class="btn btn-success w-100 mb-2" onclick="responderParceria('aceitar')"><i class="bi bi-check-circle me-1"></i>Aceitar Proposta</button>
                        <?php endif; ?>

                        <?php if (!$aprovadoMeu && in_array(pv($p, 'status', ''), ['em_negociacao','aguardando_aprovacao_empresa','aguardando_aprovacao_transportador'], true)): ?>
                            <button class="btn btn-primary w-100 mb-2" onclick="responderParceria('aprovar')"><i class="bi bi-check-lg me-1"></i>Aprovar Termos</button>
                        <?php endif; ?>

                        <button class="btn btn-outline-primary w-100 mb-2" data-bs-toggle="modal" data-bs-target="#modalContraPropor"><i class="bi bi-pencil me-1"></i>Contra-propor</button>
                        <button class="btn btn-outline-danger w-100" data-bs-toggle="modal" data-bs-target="#modalRecusar"><i class="bi bi-x-circle me-1"></i>Cancelar / Recusar</button>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($user_type === 'admin' && pv($p, 'status') === 'aguardando_validacao_admin'): ?>
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-transparent fw-semibold"><i class="bi bi-shield-check me-2"></i>Validação Admin</div>
                    <div class="card-body">
                        <button class="btn btn-success w-100 mb-2" onclick="validarAdmin('validar')"><i class="bi bi-check-lg me-1"></i>Validar e Activar</button>
                        <button class="btn btn-outline-danger w-100" data-bs-toggle="modal" data-bs-target="#modalRejeitarAdmin"><i class="bi bi-x-lg me-1"></i>Rejeitar</button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Missões -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent fw-semibold"><i class="bi bi-list-task me-2"></i>Missões da Parceria</div>
                <div class="card-body p-0">
                    <?php if (empty($missoes)): ?>
                        <div class="text-center py-3 text-muted small">Nenhuma missão.</div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($missoes as $m): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center small">
                                    <span class="text-truncate" style="max-width:65%"><?php echo e($m['titulo']); ?></span>
                                    <?php echo status_missao_badge_html($m['status']); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($p): ?>
<!-- Modal Contra-propor -->
<div class="modal fade" id="modalContraPropor" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Contra-proposta</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form id="formContraPropor" onsubmit="return enviarContraPropor(event)">
                <div class="modal-body">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="parceria_id" value="<?php echo $parceria_id; ?>">
                    <input type="hidden" name="acao" value="contra_propor">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label">Valor por missão (MT)</label><input type="number" step="0.01" name="valor_missao" class="form-control" value="<?php echo e($p['valor_missao'] ?? ''); ?>"></div>
                        <div class="col-md-4"><label class="form-label">Valor por KM (MT)</label><input type="number" step="0.0001" name="valor_km" class="form-control" value="<?php echo e($p['valor_km'] ?? ''); ?>"></div>
                        <div class="col-md-4"><label class="form-label">Valor mensal (MT)</label><input type="number" step="0.01" name="valor_mensal" class="form-control" value="<?php echo e($p['valor_mensal'] ?? ''); ?>"></div>
                        <div class="col-md-4"><label class="form-label">Comissão plataforma (%)</label><input type="number" step="0.01" name="comissao_plataforma_pct" class="form-control" value="<?php echo e($p['comissao_plataforma_pct'] ?? ''); ?>"></div>
                        <div class="col-md-4"><label class="form-label">SLA resposta (horas)</label><input type="number" name="sla_resposta_horas" class="form-control" value="<?php echo e($p['sla_resposta_horas'] ?? ''); ?>"></div>
                        <div class="col-md-4"><label class="form-label">Penalidade atraso (%)</label><input type="number" step="0.01" name="penalidade_atraso_pct" class="form-control" value="<?php echo e($p['penalidade_atraso_pct'] ?? ''); ?>"></div>
                        <div class="col-md-6"><label class="form-label">Condições pagamento</label><input type="text" name="condicoes_pagamento" class="form-control" value="<?php echo e($p['condicoes_pagamento'] ?? ''); ?>"></div>
                        <div class="col-md-6"><label class="form-label">Data de fim</label><input type="date" name="data_fim" class="form-control" value="<?php echo e($p['data_fim'] ?? ''); ?>"></div>
                        <div class="col-12"><label class="form-label">Tipos de carga permitidos</label><textarea name="tipos_carga_permitidos" class="form-control" rows="2"><?php echo e($p['tipos_carga_permitidos'] ?? ''); ?></textarea></div>
                        <div class="col-12"><label class="form-label">Rotas cobertas</label><textarea name="rotas_cobertas" class="form-control" rows="2"><?php echo e($p['rotas_cobertas'] ?? ''); ?></textarea></div>
                        <div class="col-12"><label class="form-label">Observações / Comentário</label><textarea name="comentario" class="form-control" rows="2" placeholder="Explique as alterações propostas..."></textarea></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Enviar Contra-proposta</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Recusar -->
<div class="modal fade" id="modalRecusar" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title text-danger">Cancelar Parceria</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form id="formRecusar" onsubmit="return enviarRecusar(event)">
            <div class="modal-body">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="parceria_id" value="<?php echo $parceria_id; ?>">
                <input type="hidden" name="acao" value="recusar">
                <p>Tem certeza que deseja cancelar esta parceria?</p>
                <textarea name="motivo" class="form-control" rows="3" placeholder="Motivo (opcional)"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Voltar</button>
                <button type="submit" class="btn btn-danger">Cancelar Parceria</button>
            </div>
        </form>
    </div></div>
</div>

<!-- Modal Rejeitar Admin -->
<div class="modal fade" id="modalRejeitarAdmin" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title text-danger">Rejeitar Parceria</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form id="formRejeitarAdmin" onsubmit="return enviarValidarAdmin(event, 'rejeitar')">
            <div class="modal-body">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="parceria_id" value="<?php echo $parceria_id; ?>">
                <input type="hidden" name="acao" value="rejeitar">
                <textarea name="motivo" class="form-control" rows="3" placeholder="Motivo da rejeição..."></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger">Rejeitar</button>
            </div>
        </form>
    </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const BASE_URL = '<?php echo BASE_URL; ?>';
const CSRF_TOKEN = '<?php echo csrf_token(); ?>';

async function responderParceria(acao) {
    if (!confirm(acao === 'aceitar' ? 'Aceitar proposta de parceria?' : 'Aprovar os termos actuais?')) return;
    const form = new FormData();
    form.append('parceria_id', <?php echo $parceria_id; ?>);
    form.append('acao', acao);
    form.append('csrf_token', CSRF_TOKEN);
    try {
        const r = await fetch(BASE_URL + '/api/parceria-responder.php', { method: 'POST', body: form });
        const d = await r.json();
        alert(d.message);
        if (d.success) location.reload();
    } catch(e) { alert('Erro de ligação.'); }
}

async function enviarContraPropor(e) {
    e.preventDefault();
    const form = e.target;
    const data = new FormData(form);
    data.append('csrf_token', CSRF_TOKEN);
    try {
        const r = await fetch(BASE_URL + '/api/parceria-responder.php', { method: 'POST', body: data });
        const d = await r.json();
        alert(d.message);
        if (d.success) location.reload();
    } catch(err) { alert('Erro de ligação.'); }
    return false;
}

async function enviarRecusar(e) {
    e.preventDefault();
    const form = e.target;
    const data = new FormData(form);
    data.append('csrf_token', CSRF_TOKEN);
    try {
        const r = await fetch(BASE_URL + '/api/parceria-responder.php', { method: 'POST', body: data });
        const d = await r.json();
        alert(d.message);
        if (d.success) location.href = '<?php echo $voltar; ?>';
    } catch(err) { alert('Erro de ligação.'); }
    return false;
}

async function validarAdmin(acao) {
    if (!confirm(acao === 'validar' ? 'Validar e activar esta parceria?' : 'Rejeitar esta parceria?')) return;
    const form = new FormData();
    form.append('parceria_id', <?php echo $parceria_id; ?>);
    form.append('acao', acao);
    form.append('csrf_token', CSRF_TOKEN);
    try {
        const r = await fetch(BASE_URL + '/api/parceria-validar-admin.php', { method: 'POST', body: form });
        const d = await r.json();
        alert(d.message);
        if (d.success) location.reload();
    } catch(e) { alert('Erro de ligação.'); }
}

async function enviarValidarAdmin(e, acao) {
    e.preventDefault();
    const form = e.target;
    const data = new FormData(form);
    data.append('csrf_token', CSRF_TOKEN);
    try {
        const r = await fetch(BASE_URL + '/api/parceria-validar-admin.php', { method: 'POST', body: data });
        const d = await r.json();
        alert(d.message);
        if (d.success) location.reload();
    } catch(err) { alert('Erro de ligação.'); }
    return false;
}
</script>
<?php endif; ?>
</body></html>
