<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');

require_role(['transportador'], '../login.php');

$transportador_id = (int)$_SESSION['user_id'];

$stmt = $conn->prepare(
    "SELECT m.id, m.titulo, m.origem, m.destino, m.status, m.valor, m.prazo_entrega,
            m.data_criacao, m.ultima_atualizacao,
            u.nome AS nome_empresa, pe.nome_empresa AS razao_social
     FROM missoes m
     LEFT JOIN usuarios u ON m.empresa_id = u.id
     LEFT JOIN perfil_empresa pe ON u.id = pe.usuario_id
     WHERE m.transportador_id = :tid AND m.parceria_id IS NOT NULL
     ORDER BY m.ultima_atualizacao DESC"
);
$stmt->execute([':tid' => $transportador_id]);
$missoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Missões Delegadas — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mb-0"><i class="bi bi-handshake me-2 text-primary"></i>Missões Delegadas</h3>
            <a href="parcerias.php" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-people me-1"></i>Ver Parcerias
            </a>
        </div>

        <?php if (empty($missoes)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>Ainda não recebeu missões delegadas pelas empresas parceiras.
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($missoes as $m): ?>
                    <?php
                    $lbl = status_missao_label($m['status'] ?? '');
                    $cls = status_missao_badge($m['status'] ?? '');
                    $ico = 'bi-circle';
                    $delegada = ($m['status'] === 'aceita');
                    ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h6 class="card-title mb-0 text-truncate" style="max-width:70%"><?php echo e($m['titulo']); ?></h6>
                                    <span class="badge bg-<?php echo $cls; ?>"><i class="bi <?php echo $ico; ?> me-1"></i><?php echo e($lbl); ?></span>
                                </div>
                                <div class="small text-muted mb-2">
                                    <div><i class="bi bi-building me-1"></i><?php echo e($m['razao_social'] ?? $m['nome_empresa'] ?? '—'); ?></div>
                                    <div><i class="bi bi-geo me-1"></i><?php echo e($m['origem']); ?> → <?php echo e($m['destino']); ?></div>
                                    <?php if (!empty($m['valor'])): ?>
                                        <div><i class="bi bi-cash me-1"></i><?php echo number_format((float)$m['valor'], 2); ?> MZN</div>
                                    <?php endif; ?>
                                    <?php if (!empty($m['prazo_entrega'])): ?>
                                        <div><i class="bi bi-calendar me-1"></i>Prazo: <?php echo date('d/m/Y', strtotime($m['prazo_entrega'])); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="d-grid gap-2 mt-3">
                                    <a href="detalhes-missao.php?id=<?php echo (int)$m['id']; ?>" class="btn btn-primary btn-sm">
                                        <i class="bi bi-eye me-1"></i>Ver Detalhes
                                    </a>
                                    <?php if ($delegada): ?>
                                        <button type="button" class="btn btn-success btn-sm"
                                                onclick="responderDelegacao(<?php echo (int)$m['id']; ?>, 'aceitar')">
                                            <i class="bi bi-check-circle me-1"></i>Aceitar
                                        </button>
                                        <button type="button" class="btn btn-outline-danger btn-sm"
                                                onclick="responderDelegacao(<?php echo (int)$m['id']; ?>, 'recusar')">
                                            <i class="bi bi-x-circle me-1"></i>Recusar
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal recusar -->
    <div class="modal fade" id="modalRecusar" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Recusar Missão Delegada</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="recusarMissaoId">
                    <div class="mb-3">
                        <label class="form-label">Motivo da recusa (opcional)</label>
                        <textarea id="recusarMotivo" class="form-control" rows="2" placeholder="Ex: Frota ocupada, não cobrimos a rota..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-danger" onclick="confirmarRecusa()">Confirmar Recusa</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;

    function responderDelegacao(missaoId, acao) {
        if (acao === 'recusar') {
            document.getElementById('recusarMissaoId').value = missaoId;
            const modal = new bootstrap.Modal(document.getElementById('modalRecusar'));
            modal.show();
            return;
        }
        enviarResposta(missaoId, acao, '');
    }

    function confirmarRecusa() {
        const missaoId = document.getElementById('recusarMissaoId').value;
        const motivo   = document.getElementById('recusarMotivo').value;
        bootstrap.Modal.getInstance(document.getElementById('modalRecusar')).hide();
        enviarResposta(missaoId, 'recusar', motivo);
    }

    async function enviarResposta(missaoId, acao, motivo) {
        if (!confirm(acao === 'aceitar' ? 'Confirma aceitar esta missão?' : 'Confirma recusar esta missão?')) return;
        const form = new FormData();
        form.append('missao_id', missaoId);
        form.append('acao', acao);
        form.append('motivo', motivo);
        form.append('csrf_token', CSRF_TOKEN);
        try {
            const r = await fetch('<?php echo BASE_URL; ?>/api/missao-delegacao-responder.php', { method: 'POST', body: form });
            const d = await r.json();
            alert(d.message);
            if (d.success) location.reload();
        } catch(e) {
            alert('Erro de ligação. Tente novamente.');
        }
    }
    </script>
</body>
</html>
