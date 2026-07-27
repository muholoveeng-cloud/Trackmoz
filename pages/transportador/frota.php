<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');
include_once('../../includes/alertas-frota.php');

require_role(['transportador'], '../login.php');

$transportador_id = (int)$_SESSION['user_id'];

// Verificar alertas de frota
$alertas = verificar_alertas_frota($conn, $transportador_id);
$totalAlertas = array_sum($alertas);

// Buscar veículos da nova tabela veiculos
$veiculos = [];
$alertasDoc = 0;
try {
    $stmt = $conn->prepare(
        "SELECT v.*,
            (SELECT COUNT(*) FROM veiculo_documentos WHERE veiculo_id = v.id AND data_validade < DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS docs_vencer
         FROM veiculos v
         WHERE v.proprietario_id = :id AND v.proprietario_tipo = 'transportador'
         ORDER BY v.atualizado_em DESC"
    );
    $stmt->execute([':id' => $transportador_id]);
    $veiculos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Contar alertas
    $stmtA = $conn->prepare(
        "SELECT COUNT(*) FROM veiculo_documentos vd
         JOIN veiculos v ON vd.veiculo_id = v.id
         WHERE v.proprietario_id = :id AND v.proprietario_tipo = 'transportador'
         AND vd.data_validade < DATE_ADD(CURDATE(), INTERVAL 30 DAY)
         AND vd.alerta_enviado_30 = 0"
    );
    $stmtA->execute([':id' => $transportador_id]);
    $alertasDoc = (int)$stmtA->fetchColumn();
} catch (PDOException $e) {
    error_log('Erro ao listar veiculos: ' . $e->getMessage());
}

function estadoBadge(string $e): string {
    return match($e) {
        'ativo' => 'success',
        'manutencao' => 'warning',
        'inativo' => 'secondary',
        'avariado' => 'danger',
        'vendido' => 'dark',
        default => 'secondary',
    };
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Frota Profissional — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h3 class="mb-0"><i class="bi bi-truck-flatbed me-2"></i>Frota Profissional</h3>
                <div class="small text-muted"><?php echo count($veiculos); ?> veículo(s) registado(s)</div>
            </div>
            <a class="btn btn-primary" href="<?php echo BASE_URL; ?>/pages/transportador/veiculo-form.php">
                <i class="bi bi-plus-circle me-1"></i>Adicionar Veículo
            </a>
        </div>

        <?php if ($totalAlertas > 0): ?>
        <div class="alert alert-warning d-flex align-items-center mb-3">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <div>
                <strong>Alertas de Frota:</strong>
                <?php if ($alertas['manutencao'] > 0): ?>
                    <span class="badge bg-danger ms-1"><?php echo $alertas['manutencao']; ?> manutenção(ões)</span>
                <?php endif; ?>
                <?php if ($alertas['seguro'] > 0): ?>
                    <span class="badge bg-danger ms-1"><?php echo $alertas['seguro']; ?> seguro(s)</span>
                <?php endif; ?>
                <?php if ($alertas['inspecao'] > 0): ?>
                    <span class="badge bg-warning ms-1"><?php echo $alertas['inspecao']; ?> inspecção(ões)</span>
                <?php endif; ?>
                <?php if ($alertas['documentos'] > 0): ?>
                    <span class="badge bg-info ms-1"><?php echo $alertas['documentos']; ?> documento(s)</span>
                <?php endif; ?>
                <a href="<?php echo BASE_URL; ?>/pages/transportador/documentos-alerta.php" class="ms-2">Ver detalhes</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($veiculos)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>Ainda não tem veículos registados na frota profissional.
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($veiculos as $v): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <h6 class="mb-0 fw-bold"><?php echo e($v['matricula']); ?></h6>
                                    <div class="small text-muted"><?php echo e($v['marca'] . ' ' . $v['modelo']); ?> <?php echo $v['ano'] ? '('.$v['ano'].')' : ''; ?></div>
                                </div>
                                <span class="badge bg-<?php echo estadoBadge($v['estado_operacional']); ?>"><?php echo e($v['estado_operacional']); ?></span>
                            </div>
                            <div class="small text-muted mb-2">
                                <div><i class="bi bi-truck me-1"></i><?php echo e($v['tipo']); ?> · <?php echo e($v['combustivel']); ?></div>
                                <?php if ($v['capacidade_kg']): ?>
                                    <div><i class="bi bi-box-seam me-1"></i>Capacidade: <?php echo number_format((float)$v['capacidade_kg'], 0, ',', '.'); ?> kg</div>
                                <?php endif; ?>
                                <?php if ($v['km_atual']): ?>
                                    <div><i class="bi bi-speedometer2 me-1"></i><?php echo number_format((int)$v['km_atual'], 0, ',', '.'); ?> km</div>
                                <?php endif; ?>
                                <?php if ($v['docs_vencer'] > 0): ?>
                                    <div class="text-warning"><i class="bi bi-exclamation-circle me-1"></i><?php echo (int)$v['docs_vencer']; ?> doc(s) a vencer</div>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex gap-2 mt-2">
                                <a href="veiculo-detalhes.php?id=<?php echo (int)$v['id']; ?>" class="btn btn-sm btn-outline-primary flex-fill">
                                    <i class="bi bi-eye me-1"></i>Detalhes
                                </a>
                                <a href="veiculo-form.php?id=<?php echo (int)$v['id']; ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-pencil"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
