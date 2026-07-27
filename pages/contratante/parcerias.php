<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');

require_role(['empresa'], '../login.php');

$empresa_id = (int)$_SESSION['user_id'];
$msg_ok  = '';
$msg_err = '';

// Acção: terminar parceria
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $parceria_id = (int)($_POST['parceria_id'] ?? 0);

    if ($_POST['action'] === 'terminar' && $parceria_id > 0) {
        try {
            $stmt = $conn->prepare(
                "UPDATE parcerias SET status = 'terminada', data_atualizacao = NOW()
                 WHERE id = :id AND empresa_id = :eid AND status IN ('ativa','pedido_enviado','em_negociacao','pendente')"
            );
            $stmt->execute([':id' => $parceria_id, ':eid' => $empresa_id]);
            if ($stmt->rowCount() > 0) {
                $msg_ok = 'Parceria terminada com sucesso.';
                // Notificar transportador
                $stmt2 = $conn->prepare(
                    "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link)
                     SELECT transportador_id, 'parceria', 'Parceria Terminada',
                     CONCAT('A empresa terminou a parceria de longo prazo.'),
                     '/trackmoz/pages/transportador/parcerias.php'
                     FROM parcerias WHERE id = :id"
                );
                $stmt2->execute([':id' => $parceria_id]);
            }
        } catch (PDOException $e) {
            error_log('Erro ao terminar parceria: ' . $e->getMessage());
            $msg_err = 'Erro ao terminar parceria.';
        }
    }
}

// Buscar parcerias da empresa
try {
    $stmt = $conn->prepare(
        "SELECT p.*,
                pt.nome_empresa AS transportador_nome,
                u.email AS transportador_email,
                u.telefone AS transportador_telefone,
                (SELECT COUNT(*) FROM missoes WHERE parceria_id = p.id) AS total_missoes
         FROM parcerias p
         JOIN perfil_transportador pt ON p.transportador_id = pt.usuario_id
         JOIN usuarios u ON p.transportador_id = u.id
         WHERE p.empresa_id = :eid
         ORDER BY
           CASE p.status
               WHEN 'pedido_enviado' THEN 0
               WHEN 'em_negociacao' THEN 1
               WHEN 'aguardando_aprovacao_transportador' THEN 2
               WHEN 'aguardando_aprovacao_empresa' THEN 3
               WHEN 'aguardando_validacao_admin' THEN 4
               WHEN 'ativa' THEN 5
               ELSE 6
           END,
           p.data_criacao DESC"
    );
    $stmt->execute([':eid' => $empresa_id]);
    $parcerias = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Erro ao listar parcerias: ' . $e->getMessage());
    $parcerias = [];
    $msg_err = 'Erro ao carregar parcerias.';
}

function badge_status(string $s): string {
    return match($s) {
        'rascunho' => '<span class="badge bg-light text-dark">Rascunho</span>',
        'pedido_enviado' => '<span class="badge bg-info">Pedido</span>',
        'em_negociacao' => '<span class="badge bg-warning text-dark">Negociação</span>',
        'aguardando_aprovacao_empresa' => '<span class="badge bg-primary">Aguardando Você</span>',
        'aguardando_aprovacao_transportador' => '<span class="badge bg-primary">Aguardando Transportadora</span>',
        'aguardando_validacao_admin' => '<span class="badge bg-secondary">Aguardando Admin</span>',
        'ativa'     => '<span class="badge bg-success">Ativa</span>',
        'suspensa'  => '<span class="badge bg-secondary">Suspensa</span>',
        'expirada' => '<span class="badge bg-dark">Expirada</span>',
        'cancelada' => '<span class="badge bg-danger">Cancelada</span>',
        default     => '<span class="badge bg-light text-dark">' . htmlspecialchars($s) . '</span>',
    };
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parcerias - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
<?php include_once('../../includes/menu.php'); ?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1"><i class="bi bi-handshake me-2 text-primary"></i>Contratos de Parceria</h2>
            <p class="text-muted mb-0">Gerencie os seus contratos de longo prazo com transportadoras</p>
        </div>
        <a href="nova-parceria.php" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> Nova Parceria
        </a>
    </div>

    <?php if ($msg_ok): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-1"></i><?php echo htmlspecialchars($msg_ok); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($msg_err): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-circle me-1"></i><?php echo htmlspecialchars($msg_err); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (empty($parcerias)): ?>
        <div class="card text-center py-5">
            <div class="card-body">
                <i class="bi bi-handshake" style="font-size:3rem;color:#ccc"></i>
                <h5 class="mt-3 text-muted">Nenhuma parceria registada</h5>
                <p class="text-muted">Proponha um contrato de longo prazo a uma transportadora para que as suas missões sejam enviadas directamente.</p>
                <a href="nova-parceria.php" class="btn btn-primary mt-2">
                    <i class="bi bi-plus-circle me-1"></i> Propor Primeira Parceria
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($parcerias as $p): ?>
                <div class="col-md-6">
                    <div class="card h-100 shadow-sm">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div class="fw-semibold">
                                <i class="bi bi-building me-1 text-primary"></i>
                                <?php echo htmlspecialchars($p['transportador_nome']); ?>
                            </div>
                            <?php echo badge_status($p['status']); ?>
                        </div>
                        <div class="card-body">
                            <?php if ($p['exclusiva']): ?>
                                <span class="badge bg-primary mb-2">
                                    <i class="bi bi-star-fill me-1"></i>Exclusiva
                                </span>
                            <?php endif; ?>

                            <?php if (!empty($p['descricao'])): ?>
                                <p class="text-muted small mb-3"><?php echo nl2br(htmlspecialchars($p['descricao'])); ?></p>
                            <?php endif; ?>

                            <div class="row g-2 text-sm">
                                <div class="col-6">
                                    <div class="small text-muted">Início</div>
                                    <div class="fw-semibold"><?php echo date('d/m/Y', strtotime($p['data_inicio'])); ?></div>
                                </div>
                                <div class="col-6">
                                    <div class="small text-muted">Fim</div>
                                    <div class="fw-semibold">
                                        <?php echo $p['data_fim'] ? date('d/m/Y', strtotime($p['data_fim'])) : '<span class="text-muted">Indeterminado</span>'; ?>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="small text-muted">Missões via parceria</div>
                                    <div class="fw-semibold"><?php echo (int)$p['total_missoes']; ?></div>
                                </div>
                                <div class="col-6">
                                    <div class="small text-muted">Contacto</div>
                                    <div class="fw-semibold small"><?php echo htmlspecialchars($p['transportador_email'] ?? '—'); ?></div>
                                </div>
                            </div>

                            <div class="mt-3">
                                <a href="../shared/parceria-detalhes.php?id=<?php echo (int)$p['id']; ?>" class="btn btn-outline-primary btn-sm w-100">
                                    <i class="bi bi-eye me-1"></i>Ver Detalhes
                                </a>
                            </div>
                            <?php if ($p['status'] === 'pedido_enviado' || $p['status'] === 'em_negociacao'): ?>
                                <div class="alert alert-warning py-2 mt-2 mb-0 small">
                                    <i class="bi bi-clock me-1"></i>A aguardar resposta da transportadora.
                                </div>
                            <?php elseif ($p['status'] === 'cancelada' && !empty($p['motivo_rejeicao'])): ?>
                                <div class="alert alert-danger py-2 mt-2 mb-0 small">
                                    <i class="bi bi-x-circle me-1"></i>Motivo: <?php echo htmlspecialchars($p['motivo_rejeicao']); ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (in_array($p['status'], ['ativa','aguardando_aprovacao_empresa','aguardando_aprovacao_transportador','aguardando_validacao_admin'])): ?>
                            <div class="card-footer text-end bg-transparent">
                                <a href="../shared/parceria-detalhes.php?id=<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-outline-primary me-1">
                                    <i class="bi bi-eye me-1"></i>Detalhes
                                </a>
                                <button class="btn btn-sm btn-outline-danger" onclick="confirmarTerminar(<?php echo (int)$p['id']; ?>, '<?php echo htmlspecialchars($p['transportador_nome'], ENT_QUOTES); ?>')">
                                    <i class="bi bi-x-circle me-1"></i>Terminar
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modal confirmação -->
<div class="modal fade" id="modalTerminar" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Terminar Parceria</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Tem a certeza que pretende terminar a parceria com <strong id="nomeTransportador"></strong>?</p>
                <p class="text-muted small">As missões já criadas não serão afectadas. As novas missões voltarão a ser publicadas no feed público.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <form method="POST">
                    <input type="hidden" name="action" value="terminar">
                    <input type="hidden" name="parceria_id" id="inputParceriaId">
                    <button type="submit" class="btn btn-danger">Terminar</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function confirmarTerminar(id, nome) {
    document.getElementById('inputParceriaId').value = id;
    document.getElementById('nomeTransportador').textContent = nome;
    new bootstrap.Modal(document.getElementById('modalTerminar')).show();
}
</script>
</body>
</html>
