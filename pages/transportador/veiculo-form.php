<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');

require_role(['transportador'], '../login.php');

$transportador_id = (int)$_SESSION['user_id'];
$veiculo_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$veiculo = [];
$errors = [];

if ($veiculo_id > 0) {
    $stmt = $conn->prepare(
        "SELECT * FROM veiculos WHERE id = :id AND proprietario_id = :pid AND proprietario_tipo = 'transportador'"
    );
    $stmt->execute([':id' => $veiculo_id, ':pid' => $transportador_id]);
    $veiculo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$veiculo) { header('Location: frota.php'); exit; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $data = [
        'matricula' => trim($_POST['matricula'] ?? ''),
        'marca' => trim($_POST['marca'] ?? ''),
        'modelo' => trim($_POST['modelo'] ?? ''),
        'ano' => (int)($_POST['ano'] ?? 0),
        'chassis' => trim($_POST['chassis'] ?? ''),
        'capacidade_kg' => $_POST['capacidade_kg'] !== '' ? (float)$_POST['capacidade_kg'] : null,
        'peso_bruto_kg' => $_POST['peso_bruto_kg'] !== '' ? (float)$_POST['peso_bruto_kg'] : null,
        'tipo' => $_POST['tipo'] ?? 'camiao',
        'combustivel' => $_POST['combustivel'] ?? 'diesel',
        'estado_operacional' => $_POST['estado_operacional'] ?? 'ativo',
        'km_atual' => (int)($_POST['km_atual'] ?? 0),
        'data_aquisicao' => $_POST['data_aquisicao'] ?: null,
        'observacoes' => trim($_POST['observacoes'] ?? ''),
    ];

    if (empty($data['matricula'])) $errors[] = 'Matrícula é obrigatória.';
    if (empty($data['marca'])) $errors[] = 'Marca é obrigatória.';
    if (empty($data['modelo'])) $errors[] = 'Modelo é obrigatório.';

    if (empty($errors)) {
        try {
            if ($veiculo_id > 0) {
                $params = [
                    ':matricula' => $data['matricula'],
                    ':marca' => $data['marca'],
                    ':modelo' => $data['modelo'],
                    ':ano' => $data['ano'] ?: null,
                    ':chassis' => $data['chassis'],
                    ':capacidade_kg' => $data['capacidade_kg'],
                    ':peso_bruto_kg' => $data['peso_bruto_kg'],
                    ':tipo' => $data['tipo'],
                    ':combustivel' => $data['combustivel'],
                    ':estado_operacional' => $data['estado_operacional'],
                    ':km_atual' => $data['km_atual'],
                    ':data_aquisicao' => $data['data_aquisicao'],
                    ':observacoes' => $data['observacoes'],
                    ':id' => $veiculo_id,
                    ':pid' => $transportador_id,
                ];
                $conn->prepare(
                    "UPDATE veiculos SET
                        matricula = :matricula, marca = :marca, modelo = :modelo, ano = :ano,
                        chassis = :chassis, capacidade_kg = :capacidade_kg, peso_bruto_kg = :peso_bruto_kg,
                        tipo = :tipo, combustivel = :combustivel, estado_operacional = :estado_operacional,
                        km_atual = :km_atual, data_aquisicao = :data_aquisicao, observacoes = :observacoes
                     WHERE id = :id AND proprietario_id = :pid AND proprietario_tipo = 'transportador'"
                )->execute($params);
                registrar_log($conn, $transportador_id, 'actualizar', 'veiculo', $veiculo_id, 'Veiculo actualizado');
                header('Location: veiculo-detalhes.php?id=' . $veiculo_id . '&success=1');
                exit;
            } else {
                $params = [
                    ':pid' => $transportador_id,
                    ':matricula' => $data['matricula'],
                    ':marca' => $data['marca'],
                    ':modelo' => $data['modelo'],
                    ':ano' => $data['ano'] ?: null,
                    ':chassis' => $data['chassis'],
                    ':capacidade_kg' => $data['capacidade_kg'],
                    ':peso_bruto_kg' => $data['peso_bruto_kg'],
                    ':tipo' => $data['tipo'],
                    ':combustivel' => $data['combustivel'],
                    ':estado_operacional' => $data['estado_operacional'],
                    ':km_atual' => $data['km_atual'],
                    ':data_aquisicao' => $data['data_aquisicao'],
                    ':observacoes' => $data['observacoes'],
                ];
                $conn->prepare(
                    "INSERT INTO veiculos
                     (proprietario_id, proprietario_tipo, matricula, marca, modelo, ano, chassis, capacidade_kg, peso_bruto_kg, tipo, combustivel, estado_operacional, km_atual, data_aquisicao, observacoes)
                     VALUES (:pid, 'transportador', :matricula, :marca, :modelo, :ano, :chassis, :capacidade_kg, :peso_bruto_kg, :tipo, :combustivel, :estado_operacional, :km_atual, :data_aquisicao, :observacoes)"
                )->execute($params);
                $novoId = (int)$conn->lastInsertId();
                registrar_log($conn, $transportador_id, 'criar', 'veiculo', $novoId, 'Veiculo criado');
                header('Location: veiculo-detalhes.php?id=' . $novoId . '&success=1');
                exit;
            }
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'uniq_matricula') !== false) {
                $errors[] = 'Já existe um veículo com esta matrícula.';
            } else {
                $errors[] = 'Erro ao guardar. Tente novamente.';
                error_log('veiculo-form: ' . $e->getMessage());
            }
        }
    }
}

$titulo = $veiculo_id > 0 ? 'Editar Veículo' : 'Novo Veículo';
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($titulo); ?> — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="bi bi-truck me-2"></i><?php echo e($titulo); ?></h4>
            <a href="frota.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Voltar</a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $er): ?><div><i class="bi bi-x-circle me-1"></i><?php echo e($er); ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="card border-0 shadow-sm">
            <div class="card-body">
                <?php echo csrf_field(); ?>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Matrícula *</label>
                        <input type="text" name="matricula" class="form-control" value="<?php echo e($veiculo['matricula'] ?? $_POST['matricula'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Marca *</label>
                        <input type="text" name="marca" class="form-control" value="<?php echo e($veiculo['marca'] ?? $_POST['marca'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Modelo *</label>
                        <input type="text" name="modelo" class="form-control" value="<?php echo e($veiculo['modelo'] ?? $_POST['modelo'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Ano</label>
                        <input type="number" name="ano" class="form-control" value="<?php echo (int)($veiculo['ano'] ?? $_POST['ano'] ?? 0); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Chassis</label>
                        <input type="text" name="chassis" class="form-control" value="<?php echo e($veiculo['chassis'] ?? $_POST['chassis'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Capacidade (kg)</label>
                        <input type="number" step="0.01" name="capacidade_kg" class="form-control" value="<?php echo e($veiculo['capacidade_kg'] ?? $_POST['capacidade_kg'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Peso Bruto (kg)</label>
                        <input type="number" step="0.01" name="peso_bruto_kg" class="form-control" value="<?php echo e($veiculo['peso_bruto_kg'] ?? $_POST['peso_bruto_kg'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Tipo</label>
                        <select name="tipo" class="form-select">
                            <?php foreach (['camiao'=>'Camião','semi_reboque'=>'Semi-reboque','reboque'=>'Reboque','furgao'=>'Furgão','pickup'=>'Pickup','motociclo'=>'Motociclo','outro'=>'Outro'] as $k=>$t): ?>
                                <option value="<?php echo $k; ?>" <?php echo ($veiculo['tipo'] ?? $_POST['tipo'] ?? 'camiao') === $k ? 'selected' : ''; ?>><?php echo e($t); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Combustível</label>
                        <select name="combustivel" class="form-select">
                            <?php foreach (['diesel'=>'Gasóleo/Diesel','gasolina'=>'Gasolina','eletrico'=>'Eléctrico','hibrido'=>'Híbrido','gasoleo'=>'Gasóleo','outro'=>'Outro'] as $k=>$t): ?>
                                <option value="<?php echo $k; ?>" <?php echo ($veiculo['combustivel'] ?? $_POST['combustivel'] ?? 'diesel') === $k ? 'selected' : ''; ?>><?php echo e($t); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Estado Operacional</label>
                        <select name="estado_operacional" class="form-select">
                            <?php foreach (['ativo'=>'Activo','manutencao'=>'Manutenção','inativo'=>'Inactivo','avariado'=>'Avariado','vendido'=>'Vendido'] as $k=>$t): ?>
                                <option value="<?php echo $k; ?>" <?php echo ($veiculo['estado_operacional'] ?? $_POST['estado_operacional'] ?? 'ativo') === $k ? 'selected' : ''; ?>><?php echo e($t); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">KM Actual</label>
                        <input type="number" name="km_atual" class="form-control" value="<?php echo (int)($veiculo['km_atual'] ?? $_POST['km_atual'] ?? 0); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Data de Aquisição</label>
                        <input type="date" name="data_aquisicao" class="form-control" value="<?php echo e($veiculo['data_aquisicao'] ?? $_POST['data_aquisicao'] ?? ''); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Observações</label>
                        <textarea name="observacoes" class="form-control" rows="2"><?php echo e($veiculo['observacoes'] ?? $_POST['observacoes'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Guardar</button>
                    <a href="frota.php" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </div>
        </form>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
