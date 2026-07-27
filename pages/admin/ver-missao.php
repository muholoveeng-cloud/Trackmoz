<?php
session_start();
require_once '../../config/app.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/helpers.php';
require_once '../../includes/missao-helpers.php';

require_role(['admin'], '../login.php');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: missoes.php');
    exit;
}

missao_garantir_colunas_operacionais($conn);

$stmt = $conn->prepare(
    "SELECT m.*, ue.nome AS nome_empresa, uc.nome AS nome_motorista, ut.nome AS nome_transportador
     FROM missoes m
     LEFT JOIN usuarios ue ON m.empresa_id = ue.id
     LEFT JOIN usuarios uc ON m.caminhoneiro_id = uc.id
     LEFT JOIN usuarios ut ON m.transportador_id = ut.id
     WHERE m.id = :id"
);
$stmt->execute([':id' => $id]);
$missao = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$missao) {
    header('Location: missoes.php?error=Missão não encontrada');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Missão #<?php echo $id; ?> — Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css">
</head>
<body>
<?php include_once('../../includes/menu.php'); ?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-box-seam me-2"></i><?php echo htmlspecialchars($missao['titulo'] ?? 'Missão'); ?></h1>
        <a href="missoes.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Voltar</a>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6"><div class="text-muted small">Origem</div><strong><?php echo e($missao['origem'] ?? '—'); ?></strong></div>
                        <div class="col-md-6"><div class="text-muted small">Destino</div><strong><?php echo e($missao['destino'] ?? '—'); ?></strong></div>
                        <div class="col-md-4"><div class="text-muted small">Status</div><span class="badge bg-secondary"><?php echo e($missao['status'] ?? '—'); ?></span></div>
                        <div class="col-md-4"><div class="text-muted small">Viagem</div><?php echo e($missao['status_viagem'] ?? '—'); ?></div>
                        <div class="col-md-4"><div class="text-muted small">Entrega</div><?php echo e($missao['status_entrega'] ?? '—'); ?></div>
                        <div class="col-md-4"><div class="text-muted small">Valor</div><?php echo number_format((float)($missao['valor'] ?? 0), 2, ',', '.'); ?> MT</div>
                        <div class="col-md-4"><div class="text-muted small">Peso</div><?php echo e($missao['peso_carga'] ?? $missao['peso_kg'] ?? '—'); ?></div>
                        <div class="col-md-4"><div class="text-muted small">Código</div><?php echo e($missao['codigo_missao'] ?? '—'); ?></div>
                    </div>
                    <?php if (!empty($missao['descricao'])): ?>
                        <hr><p class="mb-0 small"><?php echo nl2br(e($missao['descricao'])); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header">Actores</div>
                <div class="card-body small">
                    <div class="mb-2"><strong>Empresa:</strong> <?php echo e($missao['nome_empresa'] ?? '—'); ?></div>
                    <div class="mb-2"><strong>Motorista:</strong> <?php echo e($missao['nome_motorista'] ?? '—'); ?></div>
                    <div><strong>Transportador:</strong> <?php echo e($missao['nome_transportador'] ?? '—'); ?></div>
                </div>
            </div>
            <div class="card">
                <div class="card-body d-grid gap-2">
                    <a href="<?php echo BASE_URL; ?>/pages/contratante/documentos/explorador.php?missao_id=<?php echo $id; ?>" class="btn btn-outline-primary btn-sm">Documentos</a>
                    <a href="<?php echo BASE_URL; ?>/pages/admin/emergencias.php?missao_id=<?php echo $id; ?>" class="btn btn-outline-danger btn-sm">Emergências</a>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
