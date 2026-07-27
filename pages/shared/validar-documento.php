<?php
/**
 * Validação pública de documentos via Tracking ID (QR).
 */
session_start();
require_once '../../config/app.php';
require_once '../../config/database.php';
require_once '../../includes/helpers.php';
require_once '../../includes/documentos-registry.php';

$tracking = trim((string)($_GET['tracking'] ?? ''));
$doc = null;

if ($tracking !== '') {
    try {
        tmz_docs_bootstrap($conn);
        $stmt = $conn->prepare(
            'SELECT d.*, u.nome AS emitido_por_nome
             FROM documentos_sistema d
             LEFT JOIN usuarios u ON u.id = d.criado_por
             WHERE d.tracking_id = :t LIMIT 1'
        );
        $stmt->execute([':t' => $tracking]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('validar-documento: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validar Documento — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:640px;">
    <div class="text-center mb-4">
        <h3 class="mb-1">Validação de Documento</h3>
        <p class="text-muted mb-0">TrackMoz — verificação por Tracking ID</p>
    </div>

    <?php if ($tracking === ''): ?>
        <div class="alert alert-warning">Indique um Tracking ID válido na URL.</div>
    <?php elseif (!$doc): ?>
        <div class="alert alert-danger">
            <i class="bi bi-x-circle me-2"></i>Documento não encontrado ou Tracking ID inválido.
        </div>
    <?php else: ?>
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <i class="bi bi-patch-check-fill text-success fs-3"></i>
                    <div>
                        <div class="fw-bold text-success">Documento autêntico</div>
                        <small class="text-muted">Registado no sistema TrackMoz</small>
                    </div>
                </div>
                <dl class="row mb-0">
                    <dt class="col-sm-4">Tracking ID</dt>
                    <dd class="col-sm-8"><code><?php echo e($doc['tracking_id']); ?></code></dd>
                    <dt class="col-sm-4">Número</dt>
                    <dd class="col-sm-8"><?php echo e($doc['numero_documento'] ?? ''); ?></dd>
                    <dt class="col-sm-4">Título</dt>
                    <dd class="col-sm-8"><?php echo e($doc['titulo'] ?? ''); ?></dd>
                    <dt class="col-sm-4">Tipo</dt>
                    <dd class="col-sm-8"><?php echo e(str_replace('_', ' ', (string)($doc['tipo'] ?? ''))); ?></dd>
                    <dt class="col-sm-4">Estado</dt>
                    <dd class="col-sm-8"><span class="badge bg-secondary"><?php echo e($doc['status'] ?? ''); ?></span></dd>
                    <dt class="col-sm-4">Emitido em</dt>
                    <dd class="col-sm-8"><?php echo e(!empty($doc['data_emissao']) ? date('d/m/Y H:i', strtotime($doc['data_emissao'])) : 'N/D'); ?></dd>
                    <?php if (!empty($doc['missao_id'])): ?>
                    <dt class="col-sm-4">Missão</dt>
                    <dd class="col-sm-8">#<?php echo (int)$doc['missao_id']; ?></dd>
                    <?php endif; ?>
                    <?php if (!empty($doc['emitido_por_nome'])): ?>
                    <dt class="col-sm-4">Emitido por</dt>
                    <dd class="col-sm-8"><?php echo e($doc['emitido_por_nome']); ?></dd>
                    <?php endif; ?>
                </dl>
                <?php if (!empty($doc['url_visualizacao'])): ?>
                <a href="<?php echo e($doc['url_visualizacao']); ?>" class="btn btn-outline-primary btn-sm mt-3">Ver documento original</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
