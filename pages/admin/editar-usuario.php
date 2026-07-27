<?php
session_start();
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/regras-negocio.php');

require_role(['admin'], '../login.php');

// Verificar se o ID foi fornecido
if (!isset($_GET['id'])) {
    header('Location: usuarios.php');
    exit();
}

$id = $_GET['id'];

// Buscar dados do usuário
$sql = "SELECT u.*, 
            CASE 
                WHEN u.tipo_usuario = 'caminhoneiro' THEN pc.tipo_veiculo
                WHEN u.tipo_usuario = 'empresa' THEN pe.nome_empresa
                WHEN u.tipo_usuario = 'transportador' THEN pt.nome_empresa
                ELSE NULL
            END as detalhe_perfil
        FROM usuarios u
        LEFT JOIN perfil_caminhoneiro pc ON u.id = pc.usuario_id
        LEFT JOIN perfil_empresa pe ON u.id = pe.usuario_id
        LEFT JOIN perfil_transportador pt ON u.id = pt.usuario_id
        WHERE u.id = ?";

$stmt = $conn->prepare($sql);
$stmt->execute([$id]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    header('Location: usuarios.php');
    exit();
}

// Processar o formulário quando enviado
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = $_POST['nome'];
    $email = $_POST['email'];
    $tipo_usuario = $_POST['tipo_usuario'];
    $status = $_POST['status'];
    $detalhe_perfil = $_POST['detalhe_perfil'];

    $emailCheck = validar_email_unico($conn, $email, (int)$id);
    if (!$emailCheck['ok']) {
        $erro_edicao = regras_erro_mensagem($emailCheck);
    } else {
    try {
        $conn->beginTransaction();

        // Atualizar usuário
        $sql = "UPDATE usuarios SET 
                nome = ?, 
                email = ?, 
                tipo_usuario = ?, 
                status = ?
                WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([$nome, $email, $tipo_usuario, $status, $id]);

        // Atualizar perfil específico
        if ($tipo_usuario === 'caminhoneiro') {
            $sql = "UPDATE perfil_caminhoneiro SET tipo_veiculo = ? WHERE usuario_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$detalhe_perfil, $id]);
        } elseif ($tipo_usuario === 'empresa') {
            $sql = "UPDATE perfil_empresa SET nome_empresa = ? WHERE usuario_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$detalhe_perfil, $id]);
        } elseif ($tipo_usuario === 'transportador') {
            $sql = "UPDATE perfil_transportador SET nome_empresa = ? WHERE usuario_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$detalhe_perfil, $id]);
        }

        $conn->commit();
        header('Location: usuarios.php?success=1');
        exit();
    } catch (Exception $e) {
        $conn->rollBack();
        $error = "Erro ao atualizar usuário: " . $e->getMessage();
    }
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Usuário - TrackMoz Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css">
</head>
<body>
    <div class="container mt-5">
        <div class="row">
            <div class="col-md-8 offset-md-2">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Editar Usuário</h5>
                        <a href="usuarios.php" class="btn btn-secondary btn-sm">
                            <i class="bi bi-arrow-left"></i> Voltar
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (isset($error) || isset($erro_edicao)): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error ?? $erro_edicao); ?></div>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="mb-3">
                                <label for="nome" class="form-label">Nome</label>
                                <input type="text" class="form-control" id="nome" name="nome" value="<?php echo htmlspecialchars($usuario['nome']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($usuario['email']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="tipo_usuario" class="form-label">Tipo de Usuário</label>
                                <select class="form-select" id="tipo_usuario" name="tipo_usuario" required>
                                    <option value="admin" <?php echo $usuario['tipo_usuario'] === 'admin' ? 'selected' : ''; ?>>Administrador</option>
                                    <option value="caminhoneiro" <?php echo $usuario['tipo_usuario'] === 'caminhoneiro' ? 'selected' : ''; ?>>Caminhoneiro</option>
                                    <option value="transportador" <?php echo $usuario['tipo_usuario'] === 'transportador' ? 'selected' : ''; ?>>Transportador</option>
                                    <option value="empresa" <?php echo $usuario['tipo_usuario'] === 'empresa' ? 'selected' : ''; ?>>Empresa</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="ativo" <?php echo $usuario['status'] === 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                                    <option value="pendente" <?php echo $usuario['status'] === 'pendente' ? 'selected' : ''; ?>>Pendente</option>
                                    <option value="inativo" <?php echo $usuario['status'] === 'inativo' ? 'selected' : ''; ?>>Inativo</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="detalhe_perfil" class="form-label">
                                    <?php echo $usuario['tipo_usuario'] === 'caminhoneiro' ? 'Tipo de Veículo' : 'Nome da Empresa'; ?>
                                </label>
                                <input type="text" class="form-control" id="detalhe_perfil" name="detalhe_perfil" value="<?php echo htmlspecialchars($usuario['detalhe_perfil'] ?? ''); ?>">
                            </div>
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Salvar Alterações
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 