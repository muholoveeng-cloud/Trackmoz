<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');

require_role(['transportador'], '../login.php');

$transportador_id = (int)$_SESSION['user_id'];
$veiculo_id = isset($_GET['veiculo_id']) ? (int)$_GET['veiculo_id'] : 0;
if ($veiculo_id <= 0) { header('Location: frota.php'); exit; }

$stmt = $conn->prepare("SELECT id FROM veiculos WHERE id = :vid AND proprietario_id = :pid AND proprietario_tipo = 'transportador'");
$stmt->execute([':vid' => $veiculo_id, ':pid' => $transportador_id]);
if (!$stmt->fetch()) { header('Location: frota.php'); exit; }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $data = [
        'tipo' => $_POST['tipo'] ?? 'preventiva',
        'oficina' => trim($_POST['oficina'] ?? ''),
        'data' => $_POST['data'] ?? '',
        'km_atual' => (int)($_POST['km_atual'] ?? 0),
        'valor' => $_POST['valor'] !== '' ? (float)$_POST['valor'] : 0,
        'pecas_substituuidas' => trim($_POST['pecas_substituuidas'] ?? ''),
        'observacoes' => trim($_POST['observacoes'] ?? ''),
        'proxima_manutencao_km' => $_POST['proxima_manutencao_km'] !== '' ? (int)$_POST['proxima_manutencao_km'] : null,
        'proxima_manutencao_data' => $_POST['proxima_manutencao_data'] ?: null,
        'responsavel' => trim($_POST['responsavel'] ?? ''),
    ];
    if (empty($data['data'])) $errors[] = 'Data é obrigatória.';
    if (empty($errors)) {
        $conn->prepare(
            "INSERT INTO manutencoes (veiculo_id, tipo, oficina, data, km_atual, valor, pecas_substituuidas, observacoes, proxima_manutencao_km, proxima_manutencao_data, responsavel)
             VALUES (:vid, :tipo, :oficina, :data, :km, :valor, :pecas, :obs, :prox_km, :prox_data, :resp)"
        )->execute([
            ':vid'       => $veiculo_id,
            ':tipo'      => $data['tipo'],
            ':oficina'   => $data['oficina'],
            ':data'      => $data['data'],
            ':km'        => $data['km_atual'],
            ':valor'     => $data['valor'],
            ':pecas'     => $data['pecas_substituuidas'],
            ':obs'       => $data['observacoes'],
            ':prox_km'   => $data['proxima_manutencao_km'],
            ':prox_data' => $data['proxima_manutencao_data'],
            ':resp'      => $data['responsavel'],
        ]);
        // Actualizar KM do veículo se maior
        $conn->prepare("UPDATE veiculos SET km_atual = GREATEST(km_atual, :km) WHERE id = :vid")
             ->execute([':km' => $data['km_atual'], ':vid' => $veiculo_id]);
        registrar_log($conn, $transportador_id, 'criar', 'manutencao', null, "Manutencao registada para veiculo #{$veiculo_id}");
        header('Location: veiculo-detalhes.php?id=' . $veiculo_id . '&success=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Registar Manutenção — TrackMoz</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css"></head>
<body>
<?php include_once('../../includes/menu.php'); ?>
<div class="container mt-4">
    <h4 class="mb-3"><i class="bi bi-wrench me-2"></i>Registar Manutenção</h4>
    <?php if (!empty($errors)): ?><div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div>'.e($e).'</div>'; ?></div><?php endif; ?>
    <form method="POST" class="card border-0 shadow-sm">
        <div class="card-body">
            <?php echo csrf_field(); ?>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Tipo</label>
                    <select name="tipo" class="form-select">
                        <?php foreach (['preventiva'=>'Preventiva','corretiva'=>'Correctiva','revisao'=>'Revisão','troca_oleo'=>'Troca de Óleo','pneus'=>'Pneus','outro'=>'Outro'] as $k=>$t): ?>
                            <option value="<?php echo $k; ?>"><?php echo e($t); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Oficina</label>
                    <input type="text" name="oficina" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Data *</label>
                    <input type="date" name="data" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">KM Actual</label>
                    <input type="number" name="km_atual" class="form-control" value="0">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Valor (MT)</label>
                    <input type="number" step="0.01" name="valor" class="form-control" value="0">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Próx. Manutenção (km)</label>
                    <input type="number" name="proxima_manutencao_km" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Próx. Manutenção (data)</label>
                    <input type="date" name="proxima_manutencao_data" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Responsável</label>
                    <input type="text" name="responsavel" class="form-control">
                </div>
                <div class="col-12">
                    <label class="form-label">Peças Substituídas</label>
                    <textarea name="pecas_substituuidas" class="form-control" rows="2"></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">Observações</label>
                    <textarea name="observacoes" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Guardar</button>
                <a href="veiculo-detalhes.php?id=<?php echo $veiculo_id; ?>" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </div>
    </form>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
