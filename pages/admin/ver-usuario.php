<?php
session_start();
require_once '../../config/app.php';
require_once '../../config/database.php';

// Verificar se o usuário está logado e é um administrador
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'admin') {
    header('Location: ../login.php');
    exit();
}

// Verificar se o ID foi fornecido
if (!isset($_GET['id'])) {
    header('Location: ' . BASE_URL . '/pages/admin/usuarios.php');
    exit();
}

$id = $_GET['id'];

// Buscar dados do usuário
$sql = "SELECT u.*, 
            CASE 
                WHEN u.tipo_usuario = 'caminhoneiro' THEN pc.tipo_veiculo
                WHEN u.tipo_usuario = 'empresa' THEN pe.nome_empresa
                ELSE NULL
            END as detalhe_perfil
        FROM usuarios u
        LEFT JOIN perfil_caminhoneiro pc ON u.id = pc.usuario_id
        LEFT JOIN perfil_empresa pe ON u.id = pe.usuario_id
        WHERE u.id = ?";

$stmt = $conn->prepare($sql);
$stmt->execute([$id]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    header('Location: ' . BASE_URL . '/pages/admin/usuarios.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalhes do Usuário - TrackMoz Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css">
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8 offset-md-2">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Detalhes do Usuário</h5>
                        <a href="<?php echo BASE_URL; ?>/pages/admin/usuarios.php" class="btn btn-secondary btn-sm">
                            <i class="bi bi-arrow-left"></i> Voltar
                        </a>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h6 class="text-muted">Nome</h6>
                                <p><?php echo htmlspecialchars($usuario['nome']); ?></p>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted">Email</h6>
                                <p><?php echo htmlspecialchars($usuario['email']); ?></p>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h6 class="text-muted">Tipo de Usuário</h6>
                                <p><?php echo ucfirst($usuario['tipo_usuario']); ?></p>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted">Status</h6>
                                <p>
                                    <span class="badge bg-<?php 
                                        echo $usuario['status'] === 'ativo' ? 'success' : 
                                            ($usuario['status'] === 'pendente' ? 'warning' : 'secondary'); 
                                    ?>">
                                        <?php echo ucfirst($usuario['status']); ?>
                                    </span>
                                </p>
                            </div>
                        </div>
                        <?php if (!empty($usuario['detalhe_perfil'])): ?>
                        <div class="row mb-3">
                            <div class="col-12">
                                <h6 class="text-muted">Detalhes do Perfil</h6>
                                <p><?php echo htmlspecialchars($usuario['detalhe_perfil']); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h6 class="text-muted">Data de Registro</h6>
                                <p><?php echo date('d/m/Y H:i', strtotime($usuario['data_registro'])); ?></p>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted">Telefone</h6>
                                <p><?php echo htmlspecialchars($usuario['telefone'] ?? 'Não informado'); ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div class="btn-group">
                            <a href="<?php echo BASE_URL; ?>/pages/admin/editar-usuario.php?id=<?php echo $usuario['id']; ?>" class="btn btn-primary">
                                <i class="bi bi-pencil"></i> Editar
                            </a>
                            <?php if ($usuario['status'] === 'pendente'): ?>
                                <a href="<?php echo BASE_URL; ?>/pages/admin/aprovar-usuario.php?id=<?php echo $usuario['id']; ?>" class="btn btn-success">
                                    <i class="bi bi-check-lg"></i> Aprovar
                                </a>
                                <a href="<?php echo BASE_URL; ?>/pages/admin/rejeitar-usuario.php?id=<?php echo $usuario['id']; ?>" class="btn btn-danger">
                                    <i class="bi bi-x-lg"></i> Rejeitar
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 