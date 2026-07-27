<?php
session_start();
include_once('../config/app.php');
include_once('../config/database.php');

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitizar inputs usando htmlspecialchars em vez de FILTER_SANITIZE_STRING
    $nome = htmlspecialchars(trim($_POST['nome'] ?? ''), ENT_QUOTES, 'UTF-8');
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $senha = $_POST['senha'];
    $confirmar_senha = $_POST['confirmar_senha'];
    $tipo_usuario = htmlspecialchars(trim($_POST['tipo_usuario'] ?? ''), ENT_QUOTES, 'UTF-8');

    // Validações
    if (strlen($senha) < 6) {
        $error = 'A senha deve ter pelo menos 6 caracteres';
    } elseif ($senha !== $confirmar_senha) {
        $error = 'As senhas não coincidem';
    } else {
        try {
            // Verificar se o email já existe
            $sql = "SELECT id FROM usuarios WHERE email = :email";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':email' => $email]);
            
            if ($stmt->fetch()) {
                $error = 'Este email já está cadastrado';
            } else {
                // Iniciar transação
                $conn->beginTransaction();

                // Inserir novo usuário
                $sql = "INSERT INTO usuarios (nome, email, senha, tipo_usuario, status, data_registro) 
                        VALUES (:nome, :email, :senha, :tipo_usuario, 'pendente', NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':nome' => $nome,
                    ':email' => $email,
                    ':senha' => password_hash($senha, PASSWORD_DEFAULT),
                    ':tipo_usuario' => $tipo_usuario
                ]);

                $usuario_id = $conn->lastInsertId();

                // Criar perfil correspondente
                if ($tipo_usuario === 'caminhoneiro') {
                    $sql = "INSERT INTO perfil_caminhoneiro (usuario_id, tipo_veiculo, disponibilidade) 
                           VALUES (:usuario_id, :tipo_veiculo, :disponibilidade)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':usuario_id' => $usuario_id,
                        ':tipo_veiculo' => 'Não informado', // Valor padrão para evitar NULL
                        ':disponibilidade' => 'indisponivel'
                    ]);
                } elseif ($tipo_usuario === 'empresa') {
                    $sql = "INSERT INTO perfil_empresa (usuario_id, nome_empresa) VALUES (:usuario_id, :nome_empresa)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':usuario_id' => $usuario_id,
                        ':nome_empresa' => $nome // Usar o nome como nome da empresa inicialmente
                    ]);
                } elseif ($tipo_usuario === 'transportador') {
                    $sql = "INSERT INTO perfil_transportador (usuario_id, nome_empresa) VALUES (:usuario_id, :nome_empresa)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':usuario_id' => $usuario_id,
                        ':nome_empresa' => $nome
                    ]);
                }

                // Confirmar transação
                $conn->commit();

                $success = 'Cadastro realizado com sucesso! Aguarde a aprovação do administrador para acessar sua conta.';
            }
        } catch (PDOException $e) {
            // Reverter transação em caso de erro
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log("Erro no cadastro: " . $e->getMessage());
            $error = 'Erro ao realizar cadastro. Por favor, tente novamente.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/auth.css?v=6">
    <?php include_once __DIR__ . '/../includes/pwa-head.php'; ?>
</head>
<body class="tm-auth tm-auth--cadastro">
    <div class="tm-auth__wrap">
        <div class="tm-auth__panel tm-auth__panel--wide">
            <div class="tm-auth__brand">
                <img src="<?php echo BASE_URL; ?>/assets/img/Logo_sem_background.png" alt="TrackMoz">
                <h1>Criar conta</h1>
                <p>Sistema de Gestão de Fretes</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success" role="alert">
                    <?php echo htmlspecialchars($success); ?>
                    <div class="mt-3">
                        <a href="login.php" class="btn btn-primary">Ir para Login</a>
                    </div>
                </div>
            <?php else: ?>
                <form method="POST" action="" class="needs-validation" novalidate>
                    <div class="mb-3">
                        <label for="nome" class="form-label">Nome completo</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person"></i></span>
                            <input type="text" class="form-control" id="nome" name="nome" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="tipo_usuario" class="form-label">Tipo de conta</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                            <select class="form-select" id="tipo_usuario" name="tipo_usuario" required>
                                <option value="">Selecione...</option>
                                <option value="caminhoneiro">Caminhoneiro</option>
                                <option value="transportador">Transportador</option>
                                <option value="empresa">Empresa</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="senha" class="form-label">Senha</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" class="form-control" id="senha" name="senha" required>
                        </div>
                        <div class="form-text">Mínimo 6 caracteres</div>
                    </div>

                    <div class="mb-4">
                        <label for="confirmar_senha" class="form-label">Confirmar senha</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                            <input type="password" class="form-control" id="confirmar_senha" name="confirmar_senha" required>
                        </div>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary tm-auth__btn">
                            <i class="bi bi-person-plus"></i>
                            Criar conta
                        </button>
                    </div>
                </form>

                <div class="tm-auth__footer">
                    <span class="text-muted">Já tem conta?</span>
                    <a href="login.php" class="text-decoration-none ms-1">Faça login</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Validação do formulário
        (function () {
            'use strict'
            var forms = document.querySelectorAll('.needs-validation')
            Array.prototype.slice.call(forms).forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (!form.checkValidity()) {
                        event.preventDefault()
                        event.stopPropagation()
                    }
                    form.classList.add('was-validated')
                }, false)
            })
        })()
    </script>
</body>
</html> 