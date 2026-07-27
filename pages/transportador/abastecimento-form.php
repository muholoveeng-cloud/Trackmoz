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
        'data' => $_POST['data'] ?? '',
        'posto' => trim($_POST['posto'] ?? ''),
        'litros' => (float)($_POST['litros'] ?? 0),
        'valor_total' => (float)($_POST['valor_total'] ?? 0),
        'km_atual' => (int)($_POST['km_atual'] ?? 0),
        'tipo_combustivel' => $_POST['tipo_combustivel'] ?? 'diesel',
        'observacoes' => trim($_POST['observacoes'] ?? ''),
    ];
    if (empty($data['data'])) $errors[] = 'Data é obrigatória.';
    if ($data['litros'] <= 0) $errors[] = 'Litros deve ser maior que zero.';
    if ($data['valor_total'] <= 0) $errors[] = 'Valor deve ser maior que zero.';
    if (empty($errors)) {
        $conn->prepare(
            "INSERT INTO abastecimentos (veiculo_id, data, posto, litros, valor_total, km_atual, tipo_combustivel, observacoes)
             VALUES (:vid, :data, :posto, :litros, :valor, :km, :tipo, :obs)"
        )->execute([
            ':vid'   => $veiculo_id,
            ':data'  => $data['data'],
            ':posto' => $data['posto'],
            ':litros'=> $data['litros'],
            ':valor' => $data['valor_total'],
            ':km'    => $data['km_atual'],
            ':tipo'  => $data['tipo_combustivel'],
            ':obs'   => $data['observacoes'],
        ]);
        // Actualizar KM
        $conn->prepare("UPDATE veiculos SET km_atual = GREATEST(km_atual, :km), combustivel = :comb WHERE id = :vid")
             ->execute([':km' => $data['km_atual'], ':comb' => $data['tipo_combustivel'], ':vid' => $veiculo_id]);
        registrar_log($conn, $transportador_id, 'criar', 'abastecimento', null, "Abastecimento registado para veiculo #{$veiculo_id}");
        header('Location: veiculo-detalhes.php?id=' . $veiculo_id . '&success=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Registar Abastecimento — TrackMoz</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css"></head>
<body>
<?php include_once('../../includes/menu.php'); ?>
<div class="container mt-4">
    <h4 class="mb-3"><i class="bi bi-fuel-pump me-2"></i>Registar Abastecimento</h4>
    <?php if (!empty($errors)): ?><div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div>'.e($e).'</div>'; ?></div><?php endif; ?>
    <form method="POST" class="card border-0 shadow-sm">
        <div class="card-body">
            <?php echo csrf_field(); ?>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Data *</label>
                    <input type="date" name="data" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Posto</label>
                    <input type="text" name="posto" class="form-control" placeholder="Ex: Total, Puma...">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Litros *</label>
                    <input type="number" step="0.01" name="litros" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Valor Total *</label>
                    <input type="number" step="0.01" name="valor_total" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">KM Actual</label>
                    <input type="number" name="km_atual" class="form-control" value="0">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Combustível</label>
                    <select name="tipo_combustivel" class="form-select">
                        <?php foreach (['diesel'=>'Gasóleo/Diesel','gasolina'=>'Gasolina','eletrico'=>'Eléctrico','hibrido'=>'Híbrido','gasoleo'=>'Gasóleo','outro'=>'Outro'] as $k=>$t): ?>
                            <option value="<?php echo $k; ?>"><?php echo e($t); ?></option>
                        <?php endforeach; ?>
                    </select>
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
