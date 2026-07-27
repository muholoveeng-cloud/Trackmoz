<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/regras-negocio.php');

require_role(['empresa'], '../login.php');

// Verificar se o ID da missão foi fornecido
if (!isset($_GET['id'])) {
    header('Location: missoes.php');
    exit;
}

$missao_id = (int)$_GET['id'];
$success = $error = '';

try {
    // Verificar se a missão pertence à empresa e está aberta
    $sql = "SELECT * FROM missoes 
            WHERE id = :id AND empresa_id = :empresa_id 
            AND status = 'aberta'";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':id' => $missao_id,
        ':empresa_id' => $_SESSION['user_id']
    ]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) {
        header('Location: missoes.php');
        exit;
    }

    $editCheck = validar_missao_pode_editar($missao);
    if (!$editCheck['ok']) {
        header('Location: detalhes-missao.php?id=' . $missao_id . '&error=' . urlencode(regras_erro_mensagem($editCheck)));
        exit;
    }

    // Processar o envio do formulário
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Validar e sanitizar os dados
        $titulo = htmlspecialchars(trim($_POST['titulo'] ?? ''), ENT_QUOTES, 'UTF-8');
        $origem = htmlspecialchars(trim($_POST['origem'] ?? ''), ENT_QUOTES, 'UTF-8');
        $destino = htmlspecialchars(trim($_POST['destino'] ?? ''), ENT_QUOTES, 'UTF-8');
        $tipo_veiculo = htmlspecialchars(trim($_POST['tipo_veiculo'] ?? ''), ENT_QUOTES, 'UTF-8');
        $tipo_carga = htmlspecialchars(trim($_POST['tipo_carga'] ?? ''), ENT_QUOTES, 'UTF-8');
        $valor = filter_input(INPUT_POST, 'valor', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $descricao = htmlspecialchars(trim($_POST['descricao'] ?? ''), ENT_QUOTES, 'UTF-8');
        $prazo_entrega = htmlspecialchars(trim($_POST['prazo_entrega'] ?? ''), ENT_QUOTES, 'UTF-8');

        // Validações básicas
        if (empty($titulo) || empty($origem) || empty($destino) || empty($tipo_veiculo) || 
            empty($tipo_carga) || empty($valor) || empty($descricao) || empty($prazo_entrega)) {
            $error = "Todos os campos são obrigatórios.";
        } elseif ($valor <= 0) {
            $error = "O valor deve ser maior que zero.";
        } else {
            // Atualizar a missão
            $sql = "UPDATE missoes SET 
                    titulo = :titulo,
                    origem = :origem,
                    destino = :destino,
                    tipo_veiculo = :tipo_veiculo,
                    tipo_carga = :tipo_carga,
                    valor = :valor,
                    descricao = :descricao,
                    prazo_entrega = :prazo_entrega
                    WHERE id = :id AND empresa_id = :empresa_id";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':titulo' => $titulo,
                ':origem' => $origem,
                ':destino' => $destino,
                ':tipo_veiculo' => $tipo_veiculo,
                ':tipo_carga' => $tipo_carga,
                ':valor' => $valor,
                ':descricao' => $descricao,
                ':prazo_entrega' => $prazo_entrega,
                ':id' => $missao_id,
                ':empresa_id' => $_SESSION['user_id']
            ]);

            // Processar novos documentos, se houver
            if (!empty($_FILES['documentos']['name'][0])) {
                $upload_dir = '../../uploads/documentos_missao/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                foreach ($_FILES['documentos']['tmp_name'] as $key => $tmp_name) {
                    $file_name = $_FILES['documentos']['name'][$key];
                    $file_tmp = $_FILES['documentos']['tmp_name'][$key];
                    $file_type = $_FILES['documentos']['type'][$key];
                    
                    // Gerar nome único para o arquivo
                    $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
                    $new_file_name = 'missao_' . $missao_id . '_' . uniqid() . '.' . $file_extension;
                    
                    if (move_uploaded_file($file_tmp, $upload_dir . $new_file_name)) {
                        $sql = "INSERT INTO documentos_missao (missao_id, nome, arquivo, tipo, data_upload, is_from_contratante, descricao)
                                VALUES (:missao_id, :nome, :arquivo, :tipo, NOW(), 1, '')";
                        
                        $stmt = $conn->prepare($sql);
                        $stmt->execute([
                            ':missao_id' => $missao_id,
                            ':nome' => $file_name,
                            ':arquivo' => $new_file_name,
                            ':tipo' => $file_type
                        ]);
                    }
                }
            }

            $success = "Missão atualizada com sucesso!";
        }
    }

    // Buscar documentos da missão
    $sql = "SELECT * FROM documentos_missao WHERE missao_id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $missao_id]);
    $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Erro ao editar missão: " . $e->getMessage());
    $error = "Erro ao editar missão. Por favor, tente novamente.";
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Missão - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card fade-in">
                    <div class="card-body">
                        <h2 class="card-title mb-4">Editar Missão</h2>

                        <?php if ($success): ?>
                            <div class="alert alert-success" role="alert">
                                <?php echo $success; ?>
                                <div class="mt-3">
                                    <a href="detalhes-missao.php?id=<?php echo $missao_id; ?>" class="btn btn-primary">
                                        Voltar para Detalhes
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php if ($error): ?>
                                <div class="alert alert-danger" role="alert">
                                    <?php echo $error; ?>
                                </div>
                            <?php endif; ?>

                            <form method="POST" action="" enctype="multipart/form-data">
                                <div class="mb-3">
                                    <label for="titulo" class="form-label">Título da Missão</label>
                                    <input type="text" class="form-control" id="titulo" name="titulo" 
                                           value="<?php echo htmlspecialchars($missao['titulo']); ?>" required>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="origem" class="form-label">Origem</label>
                                        <input type="text" class="form-control" id="origem" name="origem" 
                                               value="<?php echo htmlspecialchars($missao['origem']); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="destino" class="form-label">Destino</label>
                                        <input type="text" class="form-control" id="destino" name="destino" 
                                               value="<?php echo htmlspecialchars($missao['destino']); ?>" required>
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="tipo_veiculo" class="form-label">Tipo de Veículo</label>
                                        <select class="form-select" id="tipo_veiculo" name="tipo_veiculo" required>
                                            <option value="">Selecione...</option>
                                            <option value="caminhao" <?php echo $missao['tipo_veiculo'] === 'caminhao' ? 'selected' : ''; ?>>
                                                Caminhão
                                            </option>
                                            <option value="van" <?php echo $missao['tipo_veiculo'] === 'van' ? 'selected' : ''; ?>>
                                                Van
                                            </option>
                                            <option value="pickup" <?php echo $missao['tipo_veiculo'] === 'pickup' ? 'selected' : ''; ?>>
                                                Pickup
                                            </option>
                                            <option value="moto" <?php echo $missao['tipo_veiculo'] === 'moto' ? 'selected' : ''; ?>>
                                                Moto
                                            </option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="tipo_carga" class="form-label">Tipo de Carga</label>
                                        <select class="form-select" id="tipo_carga" name="tipo_carga" required>
                                            <option value="">Selecione...</option>
                                            <option value="geral" <?php echo $missao['tipo_carga'] === 'geral' ? 'selected' : ''; ?>>
                                                Carga Geral
                                            </option>
                                            <option value="granel" <?php echo $missao['tipo_carga'] === 'granel' ? 'selected' : ''; ?>>
                                                Granel
                                            </option>
                                            <option value="refrigerada" <?php echo $missao['tipo_carga'] === 'refrigerada' ? 'selected' : ''; ?>>
                                                Refrigerada
                                            </option>
                                            <option value="perigosa" <?php echo $missao['tipo_carga'] === 'perigosa' ? 'selected' : ''; ?>>
                                                Carga Perigosa
                                            </option>
                                        </select>
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="valor" class="form-label">Valor Sugerido (MT)</label>
                                        <div class="input-group">
                                            <span class="input-group-text">MT</span>
                                            <input type="number" class="form-control" id="valor" name="valor" 
                                                   step="0.01" min="0" required
                                                   value="<?php echo $missao['valor']; ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="prazo_entrega" class="form-label">Prazo de Entrega</label>
                                        <input type="date" class="form-control" id="prazo_entrega" name="prazo_entrega" 
                                               value="<?php echo date('Y-m-d', strtotime($missao['prazo_entrega'])); ?>" required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="descricao" class="form-label">Descrição</label>
                                    <textarea class="form-control" id="descricao" name="descricao" rows="4" required
                                              placeholder="Descreva os detalhes da missão, requisitos especiais, etc..."><?php echo htmlspecialchars($missao['descricao']); ?></textarea>
                                </div>

                                <?php if (!empty($documentos)): ?>
                                    <div class="mb-3">
                                        <label class="form-label">Documentos Atuais</label>
                                        <div class="list-group">
                                            <?php foreach ($documentos as $doc): ?>
                                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <i class="bi bi-file-earmark-text text-primary me-2"></i>
                                                        <?php echo htmlspecialchars($doc['nome']); ?>
                                                    </div>
                                                    <a href="<?php echo BASE_URL; ?>/uploads/documentos_missao/<?php echo $doc['arquivo']; ?>" 
                                                       class="btn btn-sm btn-outline-primary" target="_blank">
                                                        <i class="bi bi-download"></i>
                                                    </a>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="mb-4">
                                    <label for="documentos" class="form-label">Adicionar Novos Documentos (opcional)</label>
                                    <input type="file" class="form-control" id="documentos" name="documentos[]" multiple>
                                    <div class="form-text">
                                        Você pode adicionar novos documentos relevantes como contratos, especificações da carga, etc.
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between">
                                    <a href="detalhes-missao.php?id=<?php echo $missao_id; ?>" class="btn btn-outline-secondary">
                                        <i class="bi bi-arrow-left"></i> Voltar
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-save"></i> Salvar Alterações
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 