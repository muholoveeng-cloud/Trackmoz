<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/geocode.php');
include_once('../../includes/validacao-operacional.php');
include_once('../../includes/motorista-regras.php');
include_once('../../includes/helpers.php');
include_once('../../includes/disputas-helpers.php');

require_role(['caminhoneiro'], '../login.php');

if (!isset($_GET['id'])) { header('Location: missoes.php'); exit; }

$missao_id = (int)$_GET['id'];
$user_id   = (int)$_SESSION['user_id'];
$error     = '';

try {
    $stmt = $conn->prepare(
        "SELECT m.*,
                m.modo_conducao_ativo, m.tempo_conducao_acumulado_seg,
                m.data_inicio_conducao, m.data_pausa_conducao,
                u.nome    AS nome_empresa,
                u.telefone AS telefone_empresa,
                u.email   AS email_empresa,
                pe.nome_empresa AS razao_social,
                p.valor   AS valor_proposta,
                p.status  AS status_proposta,
                lo.latitude  AS origem_lat,  lo.longitude  AS origem_lng,
                ld.latitude  AS destino_lat, ld.longitude  AS destino_lng
         FROM missoes m
         LEFT JOIN usuarios u       ON m.empresa_id = u.id
         LEFT JOIN perfil_empresa pe ON u.id = pe.usuario_id
         LEFT JOIN propostas p      ON m.id = p.missao_id AND p.caminhoneiro_id = :uid1
         LEFT JOIN locais lo        ON m.local_origem_id  = lo.id
         LEFT JOIN locais ld        ON m.local_destino_id = ld.id
         WHERE m.id = :mid AND (m.caminhoneiro_id = :uid2 OR m.status = 'aberta')"
    );
    $stmt->execute([':mid' => $missao_id, ':uid1' => $user_id, ':uid2' => $user_id]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) { header('Location: missoes.php'); exit; }

    garantir_locais_missao($conn, $missao_id);
    $stmt->execute([':mid' => $missao_id, ':uid1' => $user_id, ':uid2' => $user_id]);
    $missao = enriquecer_missao_mapa($stmt->fetch(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    error_log('caminhoneiro/detalhes-missao: ' . $e->getMessage());
    $error = 'Erro ao carregar detalhes da missão.';
}

// Modo condução — missão aceite/atribuída ao motorista
missao_garantir_colunas_operacionais($conn);
$modoConducao = motorista_pode_modo_conducao($conn, $user_id, $missao);
$statusMissao = $missao['status'] ?? '';
$caminhoneiroAtribuido = (int)($missao['caminhoneiro_id'] ?? 0) === $user_id;
$mostrarCardConducao = $caminhoneiroAtribuido && (
    in_array($statusMissao, missoes_status_modo_conducao(), true)
    || in_array($statusMissao, ['aguardando_confirmacao', 'em_entrega'], true)
);
$podeIniciar = $modoConducao['ok'];

$disputaMissao = null;
$podeAbrirDisputa = false;
try {
    disputas_bootstrap($conn);
    if (!empty($missao) && disputas_tabela_existe($conn)) {
        $disputaMissao = disputa_da_missao($conn, $missao_id);
        if (($missao['status'] ?? '') === 'concluida' && (int)($missao['caminhoneiro_id'] ?? 0) === $user_id) {
            $chk = validar_missao_pode_disputar($conn, $missao_id, $user_id, 'caminhoneiro');
            $podeAbrirDisputa = !empty($chk['ok']);
        }
    }
} catch (Throwable $e) { /* ignore */ }

// Validar operacional (alertas antes de iniciar)
$validacaoOperacional = null;
if ($mostrarCardConducao && empty($missao['data_inicio_conducao'])) {
    try {
        $validacaoOperacional = validar_operacional_missao($conn, $user_id);
    } catch (Throwable $e) {
        error_log('validacao detalhes-missao: ' . $e->getMessage());
    }
}

function status_label(string $s): array {
    return match($s) {
        'aberta'                 => ['Publicada',          'success',   'bi-broadcast'],
        'aceita'                 => ['Aceita — Pronta',    'success',   'bi-check-circle-fill'],
        'em_andamento'           => ['Em Execução',        'warning',   'bi-truck'],
        'em_transito'            => ['Em Trânsito',        'primary',   'bi-truck'],
        'em_entrega'             => ['Em Entrega',         'info',      'bi-box-arrow-in-down'],
        'aguardando_confirmacao' => ['Ag. Confirmação',    'secondary', 'bi-hourglass-split'],
        'concluida'              => ['Concluída',          'success',   'bi-patch-check-fill'],
        'emergencia_reportada'   => ['Emergência',         'danger',    'bi-exclamation-triangle-fill'],
        'entrega_confirmada'     => ['Entrega Conf.',      'success',   'bi-clipboard-check-fill'],
        'cancelada'              => ['Cancelada',          'danger',    'bi-x-circle'],
        default                  => [ucfirst($s),          'secondary', 'bi-circle'],
    };
}
[$slabel, $sclass, $sicon] = status_label($missao['status'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($missao['titulo'] ?? 'Missão'); ?> — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <style>
        .detail-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: #888; font-weight: 600; margin-bottom: 2px; }
        .detail-value { font-size: .93rem; font-weight: 500; color: #222; }
        .info-grid    { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 1.1rem; }
        .rota-block   { background: #f0f4ff; border-radius: 12px; padding: 14px 18px; }
        .btn-drive    { background: linear-gradient(135deg, #2563eb, #1e40af);
                        border: none; border-radius: 12px; padding: 14px 20px;
                        font-size: 1rem; font-weight: 700; color: #fff;
                        display: flex; align-items: center; gap: 8px; justify-content: center;
                        box-shadow: 0 4px 16px rgba(37,99,235,.3);
                        transition: transform .12s, box-shadow .12s; }
        .btn-drive:hover { transform: translateY(-1px); box-shadow: 0 6px 22px rgba(37,99,235,.4); color: #fff; }
        .btn-drive:active { transform: translateY(0); }
        @media (max-width: 576px) { .info-grid { grid-template-columns: 1fr 1fr; } }
    </style>
</head>
<body>
<?php include_once('../../includes/menu.php'); ?>

<div class="container py-4">

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php else: ?>

    <?php if ($validacaoOperacional && !$validacaoOperacional['ok']): ?>
        <div class="alert alert-warning">
            <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>Validação operacional pendente</div>
            <ul class="mb-0 small">
                <?php foreach ($validacaoOperacional['erros'] as $erro): ?>
                    <li><?php echo e($erro); ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="mt-2 small text-muted">Resolva os itens acima antes de iniciar a condução.</div>
        </div>
    <?php elseif ($validacaoOperacional && $validacaoOperacional['ok']): ?>
        <div class="alert alert-success d-flex align-items-center">
            <i class="bi bi-check-circle-fill me-2"></i>
            <div>Validação operacional aprovada. <?php echo $validacaoOperacional['veiculo'] ? 'Veículo: ' . e($validacaoOperacional['veiculo']['matricula']) : ''; ?></div>
        </div>
    <?php endif; ?>

    <!-- Breadcrumb -->
    <nav class="mb-2"><ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="missoes.php" class="text-decoration-none">Missões</a></li>
        <li class="breadcrumb-item active text-truncate" style="max-width:200px"><?php echo htmlspecialchars($missao['titulo']); ?></li>
    </ol></nav>

    <!-- Título + estado -->
    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
        <div>
            <h4 class="mb-1 fw-bold"><?php echo htmlspecialchars($missao['titulo']); ?></h4>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge bg-<?php echo $sclass; ?> fs-6">
                    <i class="bi <?php echo $sicon; ?> me-1"></i><?php echo $slabel; ?>
                </span>
                <span class="text-muted small">
                    <i class="bi bi-building me-1"></i><?php echo htmlspecialchars($missao['nome_empresa'] ?? ''); ?>
                </span>
            </div>
        </div>
        <a href="missoes.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Voltar
        </a>
    </div>

    <div class="row g-4">

        <!-- Coluna principal -->
        <div class="col-lg-8">

            <?php
            $mapa_missao_id = $missao_id;
            $mapa_origem_lat = $missao['origem_lat'] ?? null;
            $mapa_origem_lng = $missao['origem_lng'] ?? null;
            $mapa_destino_lat = $missao['destino_lat'] ?? null;
            $mapa_destino_lng = $missao['destino_lng'] ?? null;
            $mapa_origem_txt = $missao['origem'] ?? '';
            $mapa_destino_txt = $missao['destino'] ?? '';
            $mapa_poll_missao_id = $mostrarCardConducao ? $missao_id : null;
            include __DIR__ . '/../../includes/mapa-missao-widget.php';
            ?>

            <!-- Rota em destaque -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <div class="rota-block mb-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="text-center">
                                <div class="small text-muted mb-1">Origem</div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($missao['origem'] ?? '—'); ?></div>
                            </div>
                            <div class="flex-fill text-center text-muted fs-4">→</div>
                            <div class="text-center">
                                <div class="small text-muted mb-1">Destino</div>
                                <div class="fw-semibold"><?php echo htmlspecialchars($missao['destino'] ?? '—'); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="info-grid">
                        <div>
                            <div class="detail-label">Veículo</div>
                            <div class="detail-value"><i class="bi bi-truck me-1 text-primary"></i><?php echo htmlspecialchars($missao['tipo_veiculo'] ?? '—'); ?></div>
                        </div>
                        <div>
                            <div class="detail-label">Carga</div>
                            <div class="detail-value"><i class="bi bi-box me-1 text-primary"></i><?php echo htmlspecialchars($missao['tipo_carga'] ?? '—'); ?></div>
                        </div>
                        <div>
                            <div class="detail-label">Valor</div>
                            <div class="detail-value fw-bold text-success">
                                <?php echo $missao['valor'] ? number_format((float)$missao['valor'], 0, ',', '.') . ' MT' : '—'; ?>
                            </div>
                        </div>
                        <div>
                            <div class="detail-label">Prazo</div>
                            <div class="detail-value">
                                <?php echo $missao['prazo_entrega'] ? date('d/m/Y', strtotime($missao['prazo_entrega'])) : '—'; ?>
                            </div>
                        </div>
                        <?php if ($missao['peso_carga']): ?>
                        <div>
                            <div class="detail-label">Peso</div>
                            <div class="detail-value"><?php echo number_format((float)$missao['peso_carga'], 0, ',', '.'); ?> kg</div>
                        </div>
                        <?php endif; ?>
                        <?php if ($missao['valor_proposta']): ?>
                        <div>
                            <div class="detail-label">Minha Proposta</div>
                            <div class="detail-value fw-bold text-primary">
                                <?php echo number_format((float)$missao['valor_proposta'], 0, ',', '.'); ?> MT
                                <span class="badge bg-<?php echo $missao['status_proposta'] === 'aceita' ? 'success' : 'secondary'; ?> ms-1">
                                    <?php echo ucfirst($missao['status_proposta'] ?? ''); ?>
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($missao['descricao'])): ?>
                        <hr class="my-3">
                        <div class="detail-label">Descrição</div>
                        <p class="mb-0 text-secondary small"><?php echo nl2br(htmlspecialchars($missao['descricao'])); ?></p>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- Coluna lateral -->
        <div class="col-lg-4">

            <!-- ★ BOTÃO MODO CONDUÇÃO — principal acção do motorista -->
            <?php if ($mostrarCardConducao): ?>
            <?php
            $modoAtivo  = (bool)($missao['modo_conducao_ativo'] ?? false);
            $jaIniciou  = !empty($missao['data_inicio_conducao']);
            $tempoSeg   = (int)($missao['tempo_conducao_acumulado_seg'] ?? 0);
            $tempoH     = floor($tempoSeg / 3600);
            $tempoM     = floor(($tempoSeg % 3600) / 60);
            $tempoStr   = ($tempoH > 0 ? $tempoH.'h ' : '') . str_pad((string)$tempoM,2,'0',STR_PAD_LEFT) . 'min';
            $btnLabel   = botao_modo_conducao_label($missao);
            ?>
            <?php if (!$modoConducao['ok'] && ($statusMissao === 'aceita' || in_array($statusMissao, missoes_status_operacionais_ativos(), true))): ?>
            <div class="alert alert-warning mb-3 small">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <?php echo htmlspecialchars($modoConducao['motivo']); ?>
            </div>
            <?php endif; ?>
            <div class="card border-0 shadow-sm mb-4 overflow-hidden">
                <div class="card-body" style="background: linear-gradient(135deg,#eff6ff,#dbeafe); border: 1px solid #bfdbfe;">
                    <div class="text-center mb-3">
                        <div style="font-size:2.5rem">🚛</div>
                        <?php if ($modoAtivo): ?>
                            <div class="text-success fw-bold mt-1"><i class="bi bi-record-circle-fill me-1"></i>Condução activa</div>
                            <div class="text-muted small">Tempo: <?php echo htmlspecialchars($tempoStr); ?></div>
                        <?php elseif ($jaIniciou): ?>
                            <div class="text-warning fw-bold mt-1"><i class="bi bi-pause-circle-fill me-1"></i>Condução pausada</div>
                            <div class="text-muted small">Tempo acumulado: <?php echo htmlspecialchars($tempoStr); ?></div>
                        <?php elseif ($statusMissao === 'aceita'): ?>
                            <div class="fw-bold mt-1 text-dark">Missão agendada</div>
                            <div class="text-muted small">Pendente de execução</div>
                        <?php else: ?>
                            <div class="fw-bold mt-1 text-dark">Em execução</div>
                            <div class="text-muted small"><?php echo htmlspecialchars(status_operacional_missao_label($missao)); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($podeIniciar): ?>
                    <a href="modo-direcao.php?missao_id=<?php echo $missao_id; ?>"
                       class="btn btn-drive w-100">
                        <i class="bi bi-play-circle-fill fs-5"></i>
                        <?php echo htmlspecialchars($btnLabel); ?>
                    </a>
                    <?php else: ?>
                    <button type="button" class="btn btn-drive w-100 opacity-50" disabled>
                        <i class="bi bi-lock-fill fs-5"></i> <?php echo htmlspecialchars($modoConducao['motivo'] ?: 'Modo condução indisponível'); ?>
                    </button>
                    <?php endif; ?>
                    <div class="text-center mt-2 text-muted" style="font-size:.7rem">
                        <i class="bi bi-geo-alt me-1"></i>A localização é enviada ao contratante em tempo real
                    </div>
                </div>
            </div>
            <?php elseif (in_array($statusMissao, ['aguardando_confirmacao', 'em_entrega'], true)): ?>
            <div class="card border-0 shadow-sm mb-4 overflow-hidden">
                <div class="card-body" style="background: linear-gradient(135deg,#eff6ff,#dbeafe); border: 1px solid #bfdbfe;">
                    <div class="text-center mb-3">
                        <div style="font-size:2rem">📦</div>
                        <div class="fw-bold mt-1 text-dark"><?php echo htmlspecialchars(status_operacional_missao_label($missao)); ?></div>
                    </div>
                    <a href="modo-direcao.php?missao_id=<?php echo $missao_id; ?>" class="btn btn-drive w-100 mb-2">
                        <i class="bi bi-arrow-repeat fs-5"></i> Continuar viagem
                    </a>
                    <a href="entrega-confirmar.php?missao_id=<?php echo $missao_id; ?>" class="btn btn-success w-100">
                        <i class="bi bi-key me-1"></i> Confirmar entrega (OTP)
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Contacto da empresa -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-transparent border-bottom pt-3 pb-2">
                    <h6 class="mb-0 fw-semibold"><i class="bi bi-building me-2 text-primary"></i>Empresa Contratante</h6>
                </div>
                <div class="card-body">
                    <div class="fw-semibold mb-1"><?php echo htmlspecialchars($missao['razao_social'] ?? $missao['nome_empresa'] ?? '—'); ?></div>
                    <?php if ($missao['telefone_empresa']): ?>
                        <a href="tel:<?php echo htmlspecialchars($missao['telefone_empresa']); ?>" class="d-flex align-items-center gap-2 small text-muted text-decoration-none mb-1 mt-2">
                            <i class="bi bi-telephone text-success"></i><?php echo htmlspecialchars($missao['telefone_empresa']); ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($missao['email_empresa']): ?>
                        <div class="d-flex align-items-center gap-2 small text-muted mb-3">
                            <i class="bi bi-envelope text-muted"></i><?php echo htmlspecialchars($missao['email_empresa']); ?>
                        </div>
                    <?php endif; ?>
                    <a href="<?php echo BASE_URL; ?>/pages/chat.php?user=<?php echo (int)$missao['empresa_id']; ?>&missao=<?php echo $missao_id; ?>"
                       class="btn btn-outline-primary btn-sm w-100">
                        <i class="bi bi-chat me-1"></i>Enviar Mensagem
                    </a>
                </div>
            </div>

            <!-- Acções adicionais -->
            <div class="card border-0 shadow-sm">
                <div class="card-body d-grid gap-2">
                    <?php if ($missao['status'] === 'aberta' && !$missao['valor_proposta']): ?>
                        <a href="enviar-proposta.php?id=<?php echo $missao_id; ?>" class="btn btn-primary">
                            <i class="bi bi-send me-1"></i>Enviar Proposta
                        </a>
                    <?php endif; ?>

                    <?php if ($mostrarCardConducao): ?>
                        <a href="mapa-missao.php?missao_id=<?php echo $missao_id; ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-map me-1"></i>Ver Mapa da Rota
                        </a>
                    <?php endif; ?>

                    <?php if (in_array($statusMissao, missoes_status_operacionais_ativos(), true)): ?>
                        <a href="modo-direcao.php?missao_id=<?php echo $missao_id; ?>" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-car-front me-1"></i><?php echo htmlspecialchars(botao_modo_conducao_label($missao)); ?>
                        </a>
                        <a href="modo-direcao.php?missao_id=<?php echo $missao_id; ?>#emergencia" class="btn btn-outline-danger btn-sm"
                           onclick="alert('Abra o Modo Condução e clique no botão vermelho de emergência para reportar com todos os detalhes.');return false;">
                            <i class="bi bi-exclamation-triangle me-1"></i>Reportar Emergência
                        </a>
                    <?php endif; ?>

                    <?php if (in_array($statusMissao, ['em_entrega','aguardando_confirmacao','entrega_confirmada'], true)): ?>
                        <a href="entrega-confirmar.php?missao_id=<?php echo $missao_id; ?>" class="btn btn-success btn-sm">
                            <i class="bi bi-clipboard-check me-1"></i>Confirmar Entrega (ePOD)
                        </a>
                    <?php endif; ?>

                    <?php if ($missao['status'] === 'entrega_confirmada' || $missao['status'] === 'concluida'): ?>
                        <a href="<?php echo BASE_URL; ?>/pages/admin/entrega-comprovante.php?missao_id=<?php echo $missao_id; ?>" class="btn btn-outline-dark btn-sm" target="_blank">
                            <i class="bi bi-file-earmark-text me-1"></i>Ver Comprovante de Entrega
                        </a>
                    <?php endif; ?>

                    <?php if ($disputaMissao): ?>
                        <a href="<?php echo BASE_URL; ?>/pages/shared/disputa.php?id=<?php echo (int)$disputaMissao['id']; ?>"
                           class="btn btn-<?php echo $disputaMissao['status'] === 'encerrada' ? 'outline-secondary' : 'warning'; ?> btn-sm">
                            <i class="bi bi-shield-exclamation me-1"></i>
                            Disputa #<?php echo (int)$disputaMissao['id']; ?>
                            (<?php echo e(disputa_status_label($disputaMissao['status'])); ?>)
                        </a>
                    <?php elseif ($podeAbrirDisputa): ?>
                        <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#modalDisputa">
                            <i class="bi bi-shield-exclamation me-1"></i>Abrir disputa
                        </button>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
    <?php endif; ?>

</div>

<?php if ($podeAbrirDisputa): ?>
<div class="modal fade" id="modalDisputa" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Abrir disputa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formDisputa">
                    <input type="hidden" name="missao_id" value="<?php echo (int)$missao_id; ?>">
                    <?php echo csrf_field(); ?>
                    <div class="mb-2">
                        <label class="form-label">Categoria</label>
                        <select name="categoria" class="form-select">
                            <?php foreach (DISPUTA_CATEGORIAS as $k => $lab): ?>
                                <option value="<?php echo e($k); ?>"><?php echo e($lab); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <label class="form-label">Motivo (mín. 20 caracteres)</label>
                    <textarea name="motivo" class="form-control" rows="4" required minlength="20"></textarea>
                </form>
                <div id="msgDisputa" class="small mt-2"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning" id="btnSubmeterDisputa">Submeter</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/mapa-missao-detalhe.js"></script>
<?php if ($podeAbrirDisputa): ?>
<script>
document.getElementById('btnSubmeterDisputa')?.addEventListener('click', async () => {
    const form = document.getElementById('formDisputa');
    if (!form.querySelector('[name=motivo]').value.trim()) { alert('Indique o motivo.'); return; }
    if (!confirm('Confirma abertura de disputa?')) return;
    const msg = document.getElementById('msgDisputa');
    msg.textContent = 'A submeter…';
    try {
        const r = await fetch('<?php echo BASE_URL; ?>/api/disputa-criar.php', { method: 'POST', body: new FormData(form) });
        const d = await r.json();
        if (d.success) location.href = d.redirect || location.href;
        else msg.innerHTML = '<span class="text-danger">' + (d.message || 'Erro') + '</span>';
    } catch (e) { msg.innerHTML = '<span class="text-danger">Erro de ligação</span>'; }
});
</script>
<?php endif; ?>
</body>
</html>
