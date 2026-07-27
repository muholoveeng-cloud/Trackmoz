<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/documentos-registry.php');

require_role(['transportador'], '../login.php');

$transportador_id = (int)$_SESSION['user_id'];
$msg_ok  = '';
$msg_err = '';

// Processar acções: aceitar / rejeitar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $parceria_id = (int)($_POST['parceria_id'] ?? 0);
    $action      = $_POST['action'];

    if ($parceria_id > 0 && in_array($action, ['aceitar', 'rejeitar'])) {
        try {
            // Verificar que a parceria pertence a este transportador e aguarda resposta
            $chk = $conn->prepare(
                "SELECT id, empresa_id FROM parcerias
                 WHERE id = :id AND transportador_id = :tid
                   AND status IN ('pedido_enviado','em_negociacao','aguardando_aprovacao_transportador','pendente')"
            );
            $chk->execute([':id' => $parceria_id, ':tid' => $transportador_id]);
            $parceria = $chk->fetch(PDO::FETCH_ASSOC);

            if (!$parceria) {
                $msg_err = 'Parceria não encontrada ou já processada.';
            } else {
                if ($action === 'aceitar') {
                    $stmt = $conn->prepare(
                        "UPDATE parcerias SET status = 'ativa', data_atualizacao = NOW() WHERE id = :id"
                    );
                    $stmt->execute([':id' => $parceria_id]);
                    $msg_ok = 'Parceria aceite! As missões desta empresa serão enviadas directamente para si.';

                    try {
                        $full = $conn->prepare('SELECT * FROM parcerias WHERE id = :id LIMIT 1');
                        $full->execute([':id' => $parceria_id]);
                        $pFull = $full->fetch(PDO::FETCH_ASSOC);
                        if ($pFull) {
                            tmz_docs_criar_contrato_parceria($conn, $pFull, $transportador_id);
                        }
                    } catch (Throwable $e) {
                        error_log('Doc contrato_parceria aceite legado #' . $parceria_id . ': ' . $e->getMessage());
                    }

                    // Notificar empresa
                    $tNome = $conn->prepare("SELECT nome_empresa FROM perfil_transportador WHERE usuario_id = :id");
                    $tNome->execute([':id' => $transportador_id]);
                    $nome_t = $tNome->fetchColumn() ?: 'A transportadora';

                    $notif = $conn->prepare(
                        "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link)
                         VALUES (:uid, 'parceria', 'Parceria Aceite',
                         :msg, '/trackmoz/pages/contratante/parcerias.php')"
                    );
                    $notif->execute([
                        ':uid' => $parceria['empresa_id'],
                        ':msg' => $nome_t . ' aceitou o contrato de parceria. As missões serão encaminhadas directamente.',
                    ]);

                } else {
                    $motivo = trim($_POST['motivo'] ?? '');
                    $stmt = $conn->prepare(
                        "UPDATE parcerias SET status = 'rejeitada', motivo_rejeicao = :motivo, data_atualizacao = NOW()
                         WHERE id = :id"
                    );
                    $stmt->execute([':id' => $parceria_id, ':motivo' => $motivo ?: null]);
                    $msg_ok = 'Proposta de parceria rejeitada.';

                    // Notificar empresa
                    $notif = $conn->prepare(
                        "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link)
                         VALUES (:uid, 'parceria', 'Parceria Rejeitada',
                         :msg, '/trackmoz/pages/contratante/parcerias.php')"
                    );
                    $notif->execute([
                        ':uid' => $parceria['empresa_id'],
                        ':msg' => 'A transportadora rejeitou a proposta de parceria.' . ($motivo ? ' Motivo: ' . $motivo : ''),
                    ]);
                }
            }
        } catch (PDOException $e) {
            error_log('Erro ao processar parceria: ' . $e->getMessage());
            $msg_err = 'Erro ao processar a acção. Tente novamente.';
        }
    }
}

// Buscar todas as parcerias deste transportador
try {
    $stmt = $conn->prepare(
        "SELECT p.*,
                pe.nome_empresa,
                u.email AS empresa_email,
                u.telefone AS empresa_telefone,
                (SELECT COUNT(*) FROM missoes WHERE parceria_id = p.id) AS total_missoes
         FROM parcerias p
         JOIN perfil_empresa pe ON p.empresa_id = pe.usuario_id
         JOIN usuarios u ON p.empresa_id = u.id
         WHERE p.transportador_id = :tid
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
    $stmt->execute([':tid' => $transportador_id]);
    $parcerias = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Erro ao listar parcerias: ' . $e->getMessage());
    $parcerias = [];
    $msg_err = 'Erro ao carregar parcerias.';
}

$pedidos = array_filter($parcerias, fn($p) => $p['status'] === 'pedido_enviado');
$negociacoes = array_filter($parcerias, fn($p) => $p['status'] === 'em_negociacao');
$aguardando = array_filter($parcerias, fn($p) => in_array($p['status'], ['aguardando_aprovacao_empresa','aguardando_aprovacao_transportador','aguardando_validacao_admin']));
$activas   = array_filter($parcerias, fn($p) => $p['status'] === 'ativa');
$outras    = array_filter($parcerias, fn($p) => !in_array($p['status'], ['pedido_enviado','em_negociacao','aguardando_aprovacao_empresa','aguardando_aprovacao_transportador','aguardando_validacao_admin','ativa']));

function badge_status(string $s): string {
    return match($s) {
        'rascunho' => '<span class="badge bg-light text-dark">Rascunho</span>',
        'pedido_enviado' => '<span class="badge bg-info">Pedido</span>',
        'em_negociacao' => '<span class="badge bg-warning text-dark">Negociação</span>',
        'aguardando_aprovacao_empresa' => '<span class="badge bg-primary">Aguardando Contratante</span>',
        'aguardando_aprovacao_transportador' => '<span class="badge bg-primary">Aguardando Você</span>',
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
    <div class="mb-4">
        <h2 class="mb-1"><i class="bi bi-handshake me-2 text-primary"></i>Contratos de Parceria</h2>
        <p class="text-muted mb-0">Parcerias de longo prazo com empresas clientes</p>
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

    <!-- Propostas pendentes (pedido_enviado) -->
    <?php if (!empty($pedidos)): ?>
        <div class="mb-4">
            <h5 class="text-warning mb-3">
                <i class="bi bi-clock me-1"></i>Propostas Pendentes
                <span class="badge bg-warning text-dark"><?php echo count($pedidos); ?></span>
            </h5>
            <div class="row g-3">
                <?php foreach ($pedidos as $p): ?>
                    <div class="col-md-6">
                        <div class="card border-warning h-100">
                            <div class="card-header d-flex justify-content-between align-items-center bg-warning bg-opacity-10">
                                <div class="fw-semibold">
                                    <i class="bi bi-building me-1"></i>
                                    <?php echo htmlspecialchars($p['nome_empresa']); ?>
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
                                <div class="row g-2 mb-3">
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
                                    <div class="col-12">
                                        <div class="small text-muted">Contacto</div>
                                        <div class="small"><?php echo htmlspecialchars($p['empresa_email'] ?? '—'); ?></div>
                                    </div>
                                </div>
                                <div class="d-flex gap-2">
                                    <a href="../shared/parceria-detalhes.php?id=<?php echo (int)$p['id']; ?>" class="btn btn-outline-primary flex-fill">
                                        <i class="bi bi-eye me-1"></i>Ver Detalhes
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Em negociação -->
    <?php if (!empty($negociacoes)): ?>
        <div class="mb-4">
            <h5 class="text-warning mb-3">
                <i class="bi bi-pencil-square me-1"></i>Em Negociação
                <span class="badge bg-warning text-dark"><?php echo count($negociacoes); ?></span>
            </h5>
            <div class="row g-3">
                <?php foreach ($negociacoes as $p): ?>
                    <div class="col-md-6">
                        <div class="card border-warning h-100">
                            <div class="card-header d-flex justify-content-between align-items-center bg-warning bg-opacity-10">
                                <div class="fw-semibold"><i class="bi bi-building me-1"></i><?php echo htmlspecialchars($p['nome_empresa']); ?></div>
                                <?php echo badge_status($p['status']); ?>
                            </div>
                            <div class="card-body">
                                <div class="row g-2 mb-3">
                                    <div class="col-6"><div class="small text-muted">Início</div><div class="fw-semibold"><?php echo date('d/m/Y', strtotime($p['data_inicio'])); ?></div></div>
                                    <div class="col-6"><div class="small text-muted">Fim</div><div class="fw-semibold"><?php echo $p['data_fim'] ? date('d/m/Y', strtotime($p['data_fim'])) : 'Indeterminado'; ?></div></div>
                                    <div class="col-6"><div class="small text-muted">Versão contrato</div><div class="fw-semibold">v<?php echo (int)$p['versao_contrato']; ?></div></div>
                                </div>
                                <a href="../shared/parceria-detalhes.php?id=<?php echo (int)$p['id']; ?>" class="btn btn-outline-primary w-100"><i class="bi bi-eye me-1"></i>Ver Detalhes e Responder</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Aguardando aprovação -->
    <?php if (!empty($aguardando)): ?>
        <div class="mb-4">
            <h5 class="text-primary mb-3">
                <i class="bi bi-hourglass-split me-1"></i>Aguardando Aprovação
                <span class="badge bg-primary"><?php echo count($aguardando); ?></span>
            </h5>
            <div class="row g-3">
                <?php foreach ($aguardando as $p): ?>
                    <div class="col-md-6">
                        <div class="card h-100 shadow-sm">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div class="fw-semibold"><i class="bi bi-building me-1 text-primary"></i><?php echo htmlspecialchars($p['nome_empresa']); ?></div>
                                <?php echo badge_status($p['status']); ?>
                            </div>
                            <div class="card-body">
                                <div class="row g-2">
                                    <div class="col-6"><div class="small text-muted">Início</div><div class="fw-semibold"><?php echo date('d/m/Y', strtotime($p['data_inicio'])); ?></div></div>
                                    <div class="col-6"><div class="small text-muted">Fim</div><div class="fw-semibold"><?php echo $p['data_fim'] ? date('d/m/Y', strtotime($p['data_fim'])) : 'Indeterminado'; ?></div></div>
                                </div>
                                <a href="../shared/parceria-detalhes.php?id=<?php echo (int)$p['id']; ?>" class="btn btn-outline-primary w-100 mt-3"><i class="bi bi-eye me-1"></i>Ver Detalhes</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Parcerias activas -->
    <?php if (!empty($activas)): ?>
        <div class="mb-4">
            <h5 class="text-success mb-3">
                <i class="bi bi-check-circle me-1"></i>Parcerias Activas
                <span class="badge bg-success"><?php echo count($activas); ?></span>
            </h5>
            <div class="row g-3">
                <?php foreach ($activas as $p): ?>
                    <div class="col-md-6">
                        <div class="card h-100 shadow-sm">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div class="fw-semibold">
                                    <i class="bi bi-building me-1 text-success"></i>
                                    <?php echo htmlspecialchars($p['nome_empresa']); ?>
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
                                <div class="row g-2">
                                    <div class="col-6"><div class="small text-muted">Início</div><div class="fw-semibold"><?php echo date('d/m/Y', strtotime($p['data_inicio'])); ?></div></div>
                                    <div class="col-6"><div class="small text-muted">Fim</div><div class="fw-semibold"><?php echo $p['data_fim'] ? date('d/m/Y', strtotime($p['data_fim'])) : 'Indeterminado'; ?></div></div>
                                    <div class="col-6"><div class="small text-muted">Missões recebidas</div><div class="fw-bold text-primary"><?php echo (int)$p['total_missoes']; ?></div></div>
                                </div>
                                <a href="../shared/parceria-detalhes.php?id=<?php echo (int)$p['id']; ?>" class="btn btn-outline-secondary btn-sm w-100 mt-3"><i class="bi bi-eye me-1"></i>Ver Detalhes</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Histórico -->
    <?php if (!empty($outras)): ?>
        <div class="mb-4">
            <h5 class="text-muted mb-3"><i class="bi bi-archive me-1"></i>Histórico</h5>
            <div class="row g-3">
                <?php foreach ($outras as $p): ?>
                    <div class="col-md-6">
                        <div class="card h-100 opacity-75">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div><?php echo htmlspecialchars($p['nome_empresa']); ?></div>
                                <?php echo badge_status($p['status']); ?>
                            </div>
                            <div class="card-body">
                                <div class="row g-1 small text-muted">
                                    <div class="col-6">Início: <?php echo date('d/m/Y', strtotime($p['data_inicio'])); ?></div>
                                    <div class="col-6">Missões: <?php echo (int)$p['total_missoes']; ?></div>
                                </div>
                                <?php if ($p['status'] === 'cancelada' && !empty($p['motivo_rejeicao'])): ?>
                                    <div class="small text-danger mt-2">Motivo: <?php echo htmlspecialchars($p['motivo_rejeicao']); ?></div>
                                <?php endif; ?>
                                <a href="../shared/parceria-detalhes.php?id=<?php echo (int)$p['id']; ?>" class="btn btn-outline-secondary btn-sm w-100 mt-2"><i class="bi bi-eye me-1"></i>Ver</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (empty($parcerias)): ?>
        <div class="card text-center py-5">
            <div class="card-body">
                <i class="bi bi-handshake" style="font-size:3rem;color:#ccc"></i>
                <h5 class="mt-3 text-muted">Nenhuma parceria ainda</h5>
                <p class="text-muted">Quando uma empresa lhe enviar uma proposta de parceria, aparecerá aqui para aceitar ou rejeitar.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal rejeitar -->
<div class="modal fade" id="modalRejeitar" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="bi bi-x-circle me-2"></i>Rejeitar Proposta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <p>Rejeitar proposta de parceria de <strong id="nomeEmpresa"></strong>?</p>
                    <div class="mb-3">
                        <label class="form-label">Motivo (opcional)</label>
                        <textarea class="form-control" name="motivo" rows="3"
                                  placeholder="Explique o motivo da rejeição..."></textarea>
                    </div>
                    <input type="hidden" name="action" value="rejeitar">
                    <input type="hidden" name="parceria_id" id="inputParceriaIdRej">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger">Rejeitar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function abrirRejeitar(id, nome) {
    document.getElementById('inputParceriaIdRej').value = id;
    document.getElementById('nomeEmpresa').textContent = nome;
    new bootstrap.Modal(document.getElementById('modalRejeitar')).show();
}
</script>
</body>
</html>
