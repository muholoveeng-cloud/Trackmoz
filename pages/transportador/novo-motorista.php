<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');

require_role(['transportador'], '../login.php');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = htmlspecialchars(trim($_POST['nome'] ?? ''), ENT_QUOTES, 'UTF-8');
    $telefone = htmlspecialchars(trim($_POST['telefone'] ?? ''), ENT_QUOTES, 'UTF-8');
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $cnh = htmlspecialchars(trim($_POST['cnh'] ?? ''), ENT_QUOTES, 'UTF-8');
    $status = htmlspecialchars(trim($_POST['status'] ?? 'ativo'), ENT_QUOTES, 'UTF-8');

    if ($nome === '') {
        $error = 'Nome é obrigatório.';
    } else {
        try {
            $sql = "INSERT INTO transportador_motoristas (transportador_id, nome, telefone, email, cnh, status)
                    VALUES (:transportador_id, :nome, :telefone, :email, :cnh, :status)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':transportador_id' => $_SESSION['user_id'],
                ':nome' => $nome,
                ':telefone' => $telefone !== '' ? $telefone : null,
                ':email' => $email !== '' ? $email : null,
                ':cnh' => $cnh !== '' ? $cnh : null,
                ':status' => in_array($status, ['ativo','inativo'], true) ? $status : 'ativo'
            ]);

            header('Location: ' . BASE_URL . '/pages/transportador/motoristas.php');
            exit;
        } catch (PDOException $e) {
            error_log('Erro ao criar motorista: ' . $e->getMessage());
            $error = 'Erro ao salvar motorista.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Novo Motorista - Transportador - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container mt-4" style="max-width: 720px;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mb-0">Novo Motorista</h3>
            <a class="btn btn-outline-secondary" href="<?php echo BASE_URL; ?>/pages/transportador/motoristas.php">Voltar</a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label" for="nome">Nome</label>
                        <input class="form-control" id="nome" name="nome" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="telefone">Telefone</label>
                        <input class="form-control" id="telefone" name="telefone">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="email">Email</label>
                        <input class="form-control" id="email" name="email" type="email">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="cnh">CNH</label>
                        <input class="form-control" id="cnh" name="cnh">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="ativo">Ativo</option>
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
