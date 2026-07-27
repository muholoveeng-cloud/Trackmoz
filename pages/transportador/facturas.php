<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');

require_role(['transportador'], '../login.php');

$transportador_id = (int)$_SESSION['user_id'];
$status = $_GET['status'] ?? 'todas';

$facturas = [];
try {
    $where = "f.transportador_id = :tid";
    $params = [':tid' => $transportador_id];
    if ($status !== 'todas') {
        $where .= " AND f.status = :st";
        $params[':st'] = $status;
    }
    $stmt = $conn->prepare(
        "SELECT f.*, m.titulo AS missao_titulo, pe.nome_empresa AS empresa_nome
         FROM facturas f
         LEFT JOIN missoes m ON f.missao_id = m.id
         LEFT JOIN perfil_empresa pe ON f.empresa_id = pe.usuario_id
         WHERE $where
         ORDER BY f.data_emissao DESC"
    );
    $stmt->execute($params);
    $facturas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Erro facturas transportador: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Facturas — TrackMoz</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
<?php include_once('../../includes/menu.php'); ?>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h4 class="mb-0">Facturas Recebidas</h4><p class="text-muted small mb-0">Acompanhamento de recebíveis</p></div>
        <div class="btn-group">
            <a href="?status=todas" class="btn btn-<?php echo $status==='todas'?'primary':'outline-primary'; ?>">Todas</a>
            <a href="?status=emitida" class="btn btn-<?php echo $status==='emitida'?'primary':'outline-primary'; ?>">Pendentes</a>
            <a href="?status=paga" class="btn btn-<?php echo $status==='paga'?'primary':'outline-primary'; ?>">Pagas</a>
        </div>
    </div>
    <?php if (empty($facturas)): ?>
        <div class="alert alert-info">Nenhuma factura encontrada.</div>
    <?php else: ?>
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr>
                        <th>Nº Factura</th><th>Missão</th><th>Empresa</th><th>Data Emissão</th><th>Vencimento</th><th>Valor Total</th><th>Status</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($facturas as $f): ?>
                        <tr>
                            <td class="fw-semibold"><?php echo e($f['numero_factura']); ?></td>
                            <td><?php echo e($f['missao_titulo'] ?? '—'); ?></td>
                            <td><?php echo e($f['empresa_nome'] ?? '—'); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($f['data_emissao'])); ?></td>
                            <td><?php echo $f['data_vencimento'] ? date('d/m/Y', strtotime($f['data_vencimento'])) : '—'; ?></td>
                            <td><?php echo number_format((float)$f['valor_total'], 2, ',', '.'); ?> MT</td>
                            <td><?php echo renderBadge($f['status']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
