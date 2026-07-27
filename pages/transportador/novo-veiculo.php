<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');

require_role(['transportador'], '../login.php');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $placa = htmlspecialchars(trim($_POST['placa'] ?? ''), ENT_QUOTES, 'UTF-8');
    $tipo_veiculo = htmlspecialchars(trim($_POST['tipo_veiculo'] ?? ''), ENT_QUOTES, 'UTF-8');
    $capacidade_carga = $_POST['capacidade_carga'] ?? null;
    $status = htmlspecialchars(trim($_POST['status'] ?? 'ativo'), ENT_QUOTES, 'UTF-8');

    if ($placa === '') {
        $error = 'Placa é obrigatória.';
    } else {
        try {
            $sql = "INSERT INTO transportador_veiculos (transportador_id, placa, tipo_veiculo, capacidade_carga, status)
                    VALUES (:transportador_id, :placa, :tipo_veiculo, :capacidade_carga, :status)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':transportador_id' => $_SESSION['user_id'],
                ':placa' => $placa,
                ':tipo_veiculo' => $tipo_veiculo !== '' ? $tipo_veiculo : null,
                ':capacidade_carga' => $capacidade_carga !== '' ? $capacidade_carga : null,
                ':status' => in_array($status, ['ativo','manutencao','inativo'], true) ? $status : 'ativo'
            ]);

            header('Location: ' . BASE_URL . '/pages/transportador/frota.php');
            exit;
        } catch (PDOException $e) {
            error_log('Erro ao criar veículo: ' . $e->getMessage());
            $error = 'Erro ao salvar veículo. Verifique se a placa já existe.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Novo Veículo - Transportador - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container mt-4" style="max-width: 720px;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mb-0">Novo Veículo</h3>
            <a class="btn btn-outline-secondary" href="<?php echo BASE_URL; ?>/pages/transportador/frota.php">Voltar</a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label" for="placa">Placa</label>
                        <input class="form-control" id="placa" name="placa" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="tipo_veiculo">Tipo de Veículo</label>
                        <input class="form-control" id="tipo_veiculo" name="tipo_veiculo">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="capacidade_carga">Capacidade de Carga</label>
                        <input class="form-control" id="capacidade_carga" name="capacidade_carga" type="number" step="0.01">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="ativo">Ativo</option>
                            <option value="manutencao">Manutenção</option>
                            <option value="inativo">Inativo</option>
                        </select>
                    </div>

                    <div class="d-grid">
                        <button class="btn btn-primary" type="submit">
                            <i class="bi bi-save"></i> Salvar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
</body>
</html>
