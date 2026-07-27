<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');

require_role(['transportador'], '../login.php');

$motoristas = [];
try {
    $sql = "SELECT * FROM transportador_motoristas WHERE transportador_id = :id ORDER BY data_criacao DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $motoristas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Erro ao listar motoristas: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Motoristas - Transportador - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mb-0">Motoristas</h3>
            <a class="btn btn-primary" href="<?php echo BASE_URL; ?>/pages/transportador/novo-motorista.php">
                <i class="bi bi-plus-circle"></i> Novo Motorista
            </a>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Nome</th>
                                <th>Telefone</th>
                                <th>Email</th>
                                <th>CNH</th>
                                <th>Status</th>
                                <th>Criado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($motoristas)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4">Nenhum motorista cadastrado.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($motoristas as $m): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($m['nome']); ?></td>
                                        <td><?php echo htmlspecialchars($m['telefone'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($m['email'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($m['cnh'] ?? ''); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $m['status'] === 'ativo' ? 'success' : 'secondary'; ?>">
                                                <?php echo htmlspecialchars($m['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo !empty($m['data_criacao']) ? date('d/m/Y H:i', strtotime($m['data_criacao'])) : ''; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
</body>
</html>
