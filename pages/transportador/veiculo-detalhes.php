<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');

require_role(['transportador'], '../login.php');

$transportador_id = (int)$_SESSION['user_id'];
$veiculo_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($veiculo_id <= 0) { header('Location: frota.php'); exit; }

$stmt = $conn->prepare(
    "SELECT * FROM veiculos WHERE id = :id AND proprietario_id = :pid AND proprietario_tipo = 'transportador'"
);
$stmt->execute([':id' => $veiculo_id, ':pid' => $transportador_id]);
$veiculo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$veiculo) { header('Location: frota.php'); exit; }

// Documentos
$stmt = $conn->prepare("SELECT * FROM veiculo_documentos WHERE veiculo_id = :vid ORDER BY data_validade ASC");
$stmt->execute([':vid' => $veiculo_id]);
$documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Manutenções
$stmt = $conn->prepare("SELECT * FROM manutencoes WHERE veiculo_id = :vid ORDER BY data DESC LIMIT 10");
$stmt->execute([':vid' => $veiculo_id]);
$manutencoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Abastecimentos
$stmt = $conn->prepare("SELECT * FROM abastecimentos WHERE veiculo_id = :vid ORDER BY data DESC LIMIT 10");
$stmt->execute([':vid' => $veiculo_id]);
$abastecimentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Consumo médio (últimos 3 abastecimentos)
$consumo = null;
if (count($abastecimentos) >= 2) {
    $recentes = array_slice($abastecimentos, 0, 3);
    $kmTotal = (int)$recentes[0]['km_atual'] - (int)$recentes[count($recentes)-1]['km_atual'];
    $litrosTotal = array_sum(array_map(fn($a) => (float)$a['litros'], $recentes));
    if ($litrosTotal > 0 && $kmTotal > 0) {
        $consumo = round($kmTotal / $litrosTotal, 2);
    }
}

$success = isset($_GET['success']);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($veiculo['matricula']); ?> — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>
    <div class="container mt-4">
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-1"></i>Dados guardados com sucesso.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h4 class="mb-0"><i class="bi bi-truck me-2"></i><?php echo e($veiculo['matricula']); ?></h4>
                <div class="small text-muted"><?php echo e($veiculo['marca'] . ' ' . $veiculo['modelo']); ?> · <?php echo e($veiculo['tipo']); ?></div>
            </div>
            <div class="d-flex gap-2">
                <a href="veiculo-form.php?id=<?php echo $veiculo_id; ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil me-1"></i>Editar</a>
                <a href="frota.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Voltar</a>
            </div>
        </div>

        <div class="row g-3">
            <!-- Info principal -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h6 class="card-title fw-semibold mb-3"><i class="bi bi-info-circle me-2 text-primary"></i>Dados do Veículo</h6>
                        <div class="mb-2"><span class="text-muted small">Estado:</span> <span class="badge bg-<?php echo match($veiculo['estado_operacional']){'ativo'=>'success','manutencao'=>'warning','inativo'=>'secondary','avariado'=>'danger','vendido'=>'dark',default=>'secondary'}; ?>"><?php echo e($veiculo['estado_operacional']); ?></span></div>
                        <div class="mb-2"><span class="text-muted small">Ano:</span> <?php echo (int)$veiculo['ano']; ?></div>
                        <div class="mb-2"><span class="text-muted small">Chassis:</span> <?php echo e($veiculo['chassis'] ?: '—'); ?></div>
                        <div class="mb-2"><span class="text-muted small">Capacidade:</span> <?php echo $veiculo['capacidade_kg'] ? number_format((float)$veiculo['capacidade_kg'],0,',','.').' kg' : '—'; ?></div>
                        <div class="mb-2"><span class="text-muted small">Peso Bruto:</span> <?php echo $veiculo['peso_bruto_kg'] ? number_format((float)$veiculo['peso_bruto_kg'],0,',','.').' kg' : '—'; ?></div>
                        <div class="mb-2"><span class="text-muted small">Combustível:</span> <?php echo e($veiculo['combustivel']); ?></div>
                        <div class="mb-2"><span class="text-muted small">KM Actual:</span> <?php echo number_format((int)$veiculo['km_atual'],0,',','.'); ?> km</div>
                        <?php if ($consumo): ?>
                            <div class="mb-2"><span class="text-muted small">Consumo médio:</span> <span class="text-success fw-bold"><?php echo $consumo; ?> km/l</span></div>
                        <?php endif; ?>
                        <div class="mb-0"><span class="text-muted small">Aquisição:</span> <?php echo $veiculo['data_aquisicao'] ? date('d/m/Y', strtotime($veiculo['data_aquisicao'])) : '—'; ?></div>
                    </div>
                </div>
            </div>

            <!-- Documentos -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-semibold"><i class="bi bi-file-earmark-text me-2 text-primary"></i>Documentos</h6>
                        <a href="veiculo-documento.php?veiculo_id=<?php echo $veiculo_id; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus me-1"></i>Adicionar</a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($documentos)): ?>
                            <div class="text-center py-4 text-muted small">Nenhum documento registado.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0 align-middle">
                                    <thead class="table-light"><tr><th>Tipo</th><th>Nº</th><th>Validade</th><th>Estado</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($documentos as $doc):
                                            $dias = (int)ceil((strtotime($doc['data_validade']) - time()) / 86400);
                                            $alertaClass = $dias < 0 ? 'text-danger fw-bold' : ($dias <= 7 ? 'text-warning fw-bold' : ($dias <= 30 ? 'text-warning' : 'text-success'));
                                            $alertaIcon = $dias < 0 ? 'bi-x-circle-fill' : ($dias <= 30 ? 'bi-exclamation-circle-fill' : 'bi-check-circle-fill');
                                        ?>
                                            <tr>
                                                <td><?php echo e($doc['tipo']); ?></td>
                                                <td class="small"><?php echo e($doc['numero_documento'] ?: '—'); ?></td>
                                                <td class="small <?php echo $alertaClass; ?>"><i class="bi <?php echo $alertaIcon; ?> me-1"></i><?php echo date('d/m/Y', strtotime($doc['data_validade'])); ?></td>
                                                <td><?php echo $dias < 0 ? '<span class="badge bg-danger">Expirado</span>' : ($dias <= 7 ? '<span class="badge bg-warning text-dark">'.$dias.'d</span>' : ($dias <= 30 ? '<span class="badge bg-info">'.$dias.'d</span>' : '<span class="badge bg-success">OK</span>')); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Manutenções -->
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-semibold"><i class="bi bi-wrench me-2 text-primary"></i>Manutenções</h6>
                        <a href="manutencao-form.php?veiculo_id=<?php echo $veiculo_id; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus me-1"></i>Registar</a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($manutencoes)): ?>
                            <div class="text-center py-4 text-muted small">Nenhuma manutenção registada.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0 align-middle">
                                    <thead class="table-light"><tr><th>Data</th><th>Tipo</th><th>Oficina</th><th>Valor</th><th>KM</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($manutencoes as $m): ?>
                                            <tr>
                                                <td class="small"><?php echo date('d/m/Y', strtotime($m['data'])); ?></td>
                                                <td class="small"><?php echo e($m['tipo']); ?></td>
                                                <td class="small"><?php echo e($m['oficina'] ?: '—'); ?></td>
                                                <td class="small"><?php echo $m['valor'] ? number_format((float)$m['valor'],2,',','.').' MT' : '—'; ?></td>
                                                <td class="small"><?php echo $m['km_atual'] ? number_format((int)$m['km_atual'],0,',','.') : '—'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Abastecimentos -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 fw-semibold"><i class="bi bi-fuel-pump me-2 text-primary"></i>Abastecimentos</h6>
                        <a href="abastecimento-form.php?veiculo_id=<?php echo $veiculo_id; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus me-1"></i>Registar</a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($abastecimentos)): ?>
                            <div class="text-center py-4 text-muted small">Nenhum abastecimento registado.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0 align-middle">
                                    <thead class="table-light"><tr><th>Data</th><th>Posto</th><th>Litros</th><th>Valor</th><th>KM</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($abastecimentos as $a): ?>
                                            <tr>
                                                <td class="small"><?php echo date('d/m/Y', strtotime($a['data'])); ?></td>
                                                <td class="small"><?php echo e($a['posto'] ?: '—'); ?></td>
                                                <td class="small"><?php echo number_format((float)$a['litros'],2,',','.'); ?> l</td>
                                                <td class="small"><?php echo number_format((float)$a['valor_total'],2,',','.'); ?> MT</td>
                                                <td class="small"><?php echo number_format((int)$a['km_atual'],0,',','.'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
