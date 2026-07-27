<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');

require_role(['transportador'], '../login.php');

$transportador_id = (int)$_SESSION['user_id'];
$alertas = [];
$erro = '';

try {
    // Buscar documentos de veículos a vencer nos próximos 30 dias
    $stmt = $conn->prepare(
        "SELECT vd.*, v.matricula, v.marca, v.modelo, v.tipo,
                DATEDIFF(vd.data_validade, CURDATE()) AS dias_restantes
         FROM veiculo_documentos vd
         JOIN veiculos v ON vd.veiculo_id = v.id
         WHERE v.proprietario_id = :id 
         AND v.proprietario_tipo = 'transportador'
         AND vd.data_validade < DATE_ADD(CURDATE(), INTERVAL 30 DAY)
         ORDER BY vd.data_validade ASC"
    );
    $stmt->execute([':id' => $transportador_id]);
    $alertas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Marcar alertas como enviados (opcional - pode ser usado para tracking)
    if (!empty($alertas)) {
        $stmtUpdate = $conn->prepare(
            "UPDATE veiculo_documentos 
             SET alerta_enviado_30 = 1 
             WHERE id IN (" . implode(',', array_column($alertas, 'id')) . ")"
        );
        $stmtUpdate->execute();
    }
} catch (PDOException $e) {
    error_log('Erro ao buscar alertas de documentos: ' . $e->getMessage());
    $erro = "Erro ao carregar alertas.";
}

function urgenciaBadge(int $dias): string {
    if ($dias < 0) return 'danger';
    if ($dias <= 7) return 'danger';
    if ($dias <= 15) return 'warning';
    return 'info';
}

function urgenciaTexto(int $dias): string {
    if ($dias < 0) return 'Expirado';
    if ($dias === 0) return 'Expira hoje';
    if ($dias === 1) return 'Expira amanhã';
    return "Expira em $dias dias";
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alertas de Documentos — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h3 class="mb-0"><i class="bi bi-bell-exclamation me-2"></i>Alertas de Documentos</h3>
                <div class="small text-muted">Documentos de veículos a vencer nos próximos 30 dias</div>
            </div>
            <a class="btn btn-outline-secondary" href="<?php echo BASE_URL; ?>/pages/transportador/frota.php">
                <i class="bi bi-arrow-left me-1"></i>Voltar à Frota
            </a>
        </div>

        <?php if ($erro): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($erro); ?>
            </div>
        <?php endif; ?>

        <?php if (empty($alertas)): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>Nenhum documento a vencer nos próximos 30 dias.
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong><?php echo count($alertas); ?></strong> documento(s) requerem atenção.
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Veículo</th>
                                    <th>Tipo de Documento</th>
                                    <th>Número</th>
                                    <th>Validade</th>
                                    <th>Urgência</th>
                                    <th>Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alertas as $a): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo e($a['matricula']); ?></div>
                                        <div class="small text-muted"><?php echo e($a['marca'] . ' ' . $a['modelo']); ?></div>
                                    </td>
                                    <td><?php echo e($a['tipo_documento']); ?></td>
                                    <td><?php echo e($a['numero_documento'] ?? '—'); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($a['data_validade'])); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo urgenciaBadge((int)$a['dias_restantes']); ?>">
                                            <?php echo urgenciaTexto((int)$a['dias_restantes']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="<?php echo BASE_URL; ?>/pages/transportador/veiculo-detalhes.php?id=<?php echo $a['veiculo_id']; ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-pencil me-1"></i>Actualizar
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
