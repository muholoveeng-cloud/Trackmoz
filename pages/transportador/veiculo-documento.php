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
    $tipo = $_POST['tipo'] ?? '';
    $numero = trim($_POST['numero_documento'] ?? '');
    $validade = $_POST['data_validade'] ?? '';
    $obs = trim($_POST['observacoes'] ?? '');

    if (empty($tipo)) $errors[] = 'Tipo de documento é obrigatório.';
    if (empty($validade)) $errors[] = 'Data de validade é obrigatória.';

    if (empty($errors)) {
        $conn->prepare("INSERT INTO veiculo_documentos (veiculo_id, tipo, numero_documento, data_validade, observacoes) VALUES (:vid, :tipo, :num, :val, :obs)")
             ->execute([':vid' => $veiculo_id, ':tipo' => $tipo, ':num' => $numero, ':val' => $validade, ':obs' => $obs]);
        registrar_log($conn, $transportador_id, 'criar', 'veiculo_documento', null, "Documento {$tipo} adicionado ao veiculo #{$veiculo_id}");
        header('Location: veiculo-detalhes.php?id=' . $veiculo_id . '&success=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Adicionar Documento — TrackMoz</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css"></head>
<body>
<?php include_once('../../includes/menu.php'); ?>
<div class="container mt-4">
    <h4 class="mb-3"><i class="bi bi-file-earmark-text me-2"></i>Adicionar Documento ao Veículo</h4>
    <?php if (!empty($errors)): ?><div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div>'.e($e).'</div>'; ?></div><?php endif; ?>
    <form method="POST" class="card border-0 shadow-sm">
        <div class="card-body">
            <?php echo csrf_field(); ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Tipo *</label>
                    <select name="tipo" class="form-select" required>
                        <option value="">Seleccione...</option>
                        <?php foreach (['livrete'=>'Livrete','titulo'=>'Título','seguro'=>'Seguro','inspecao'=>'Inspecção','licenca'=>'Licença','certificado'=>'Certificado','outro'=>'Outro'] as $k=>$t): ?>
                            <option value="<?php echo $k; ?>"><?php echo e($t); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Número do Documento</label>
                    <input type="text" name="numero_documento" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Data de Validade *</label>
                    <input type="date" name="data_validade" class="form-control" required>
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
