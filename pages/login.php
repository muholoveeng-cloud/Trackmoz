<?php
session_start();
include_once('../config/app.php');
include_once('../config/database.php');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $senha = (string)($_POST['senha'] ?? '');

    try {
        $sql = "SELECT id, nome, email, tipo_usuario, senha, status FROM usuarios WHERE LOWER(email) = :email LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':email' => $email]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        // Hash incompleto na BD (coluna truncada / importação má) → nunca valida
        $hashOk = is_string($usuario['senha'] ?? null) && strlen($usuario['senha']) >= 60;

        if ($usuario && $hashOk && password_verify($senha, $usuario['senha'])) {
            include_once('../includes/regras-negocio.php');
            $contaOk = validar_conta_pode_autenticar($usuario['status'] ?? '');
            if (!$contaOk['ok']) {
                $error = $contaOk['erros'][0] ?? 'Conta suspensa.';
            } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $usuario['id'];
            $_SESSION['user_name'] = $usuario['nome'];
            $_SESSION['user_type'] = $usuario['tipo_usuario'];
            $_SESSION['user_email'] = $usuario['email'];

            try {
                require_once __DIR__ . '/../includes/analytics-helpers.php';
                tmz_analytics_track($conn, 'login', (int)$usuario['id'], '/pages/login.php');
            } catch (Throwable $e) { /* ignore */ }

            if ($usuario['tipo_usuario'] === 'caminhoneiro') {
                $stmt = $conn->prepare("SELECT * FROM perfil_caminhoneiro WHERE usuario_id = ?");
                $stmt->execute([$usuario['id']]);
                $perfil = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$perfil) {
                    $sql = "INSERT INTO perfil_caminhoneiro (usuario_id, tipo_veiculo, disponibilidade) 
                            VALUES (:usuario_id, :tipo_veiculo, :disponibilidade)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':usuario_id' => $usuario['id'],
                        ':tipo_veiculo' => 'Não informado',
                        ':disponibilidade' => 'indisponivel'
                    ]);
                } else {
                    $campos_para_corrigir = [];
                    if (empty($perfil['disponibilidade'])) $campos_para_corrigir[] = "disponibilidade = 'indisponivel'";

                    if (!empty($campos_para_corrigir)) {
                        $sql = "UPDATE perfil_caminhoneiro SET " . implode(", ", $campos_para_corrigir) . " WHERE usuario_id = ?";
                        $stmt = $conn->prepare($sql);
                        $stmt->execute([$usuario['id']]);
                    }
                }
            }

            if ($usuario['tipo_usuario'] === 'transportador') {
                $stmt = $conn->prepare("SELECT * FROM perfil_transportador WHERE usuario_id = ?");
                $stmt->execute([$usuario['id']]);
                $perfil = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$perfil) {
                    $sql = "INSERT INTO perfil_transportador (usuario_id, nome_empresa)
                            VALUES (:usuario_id, :nome_empresa)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':usuario_id' => $usuario['id'],
                        ':nome_empresa' => $usuario['nome']
                    ]);
                }
            }

            include_once('../includes/kyc-helpers.php');
            kyc_bootstrap($conn);

            if ($usuario['tipo_usuario'] === 'admin') {
                include_once('../includes/admin-atencao-helpers.php');
                include_once('../includes/notificacoes-helpers.php');
                admin_sincronizar_lembretes($conn, (int)$usuario['id']);
                $_SESSION['_admin_lembretes_sync'] = time();
            }

            if (in_array($usuario['tipo_usuario'], ['caminhoneiro', 'empresa', 'transportador'], true)) {
                $kyc = kyc_obter_estado($conn, (int)$usuario['id']);
                kyc_sincronizar_lembrete_utilizador($conn, (int)$usuario['id']);
                $_SESSION['_kyc_lembrete_sync'] = time();
                if (empty($kyc['pode_operar'])) {
                    header('Location: ' . BASE_URL . '/pages/shared/verificacao-conta.php');
                    exit;
                }
            }

            header('Location: ' . BASE_URL . '/index.php');
            exit;
            }
        } else {
            if ($usuario && !$hashOk) {
                $error = 'A senha desta conta está corrompida na base online. Use a recuperação de acesso ou redefina a senha.';
            } else {
                $error = 'Email ou senha inválidos';
            }
        }
    } catch (PDOException $e) {
        $error = 'Erro ao fazer login. Tente novamente.';
    }
}

$loginBg = BASE_URL . '/assets/img/login-bg.png';
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/auth.css?v=7">
    <?php include_once __DIR__ . '/../includes/pwa-head.php'; ?>
</head>
<body class="tm-login">
    <div class="tm-login__stage">
        <img class="tm-login__art" src="<?php echo htmlspecialchars($loginBg); ?>" alt="" width="1536" height="1024" decoding="async">

        <div class="tm-login__panel" role="form" aria-label="Login TrackMoz">
            <div class="tm-auth__brand">
                <img src="<?php echo BASE_URL; ?>/assets/img/Logo_sem_background.png" alt="TrackMoz">
                <h1>TrackMoz</h1>
                <p>Sistema de Gestão de Fretes</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2 px-3 mb-2" role="alert">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php elseif (!empty($_GET['error'])): ?>
                <div class="alert alert-danger py-2 px-3 mb-2" role="alert">
                    <?php echo htmlspecialchars($_GET['error']); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" class="form-control" id="email" name="email" required autocomplete="username">
                    </div>
                </div>

                <div class="mb-4">
                    <label for="senha" class="form-label">Senha</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="senha" name="senha" required autocomplete="current-password">
                    </div>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary tm-login__btn">
                        <i class="bi bi-box-arrow-in-right"></i>
                        Entrar
                    </button>
                </div>
            </form>

            <div class="tm-login__footer">
                <a href="recuperar-senha.php" class="text-decoration-none">Esqueceu sua senha?</a>
                <div class="mt-2">
                    <span class="text-muted">Não tem uma conta?</span>
                    <a href="cadastro.php" class="text-decoration-none ms-1">Cadastre-se</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
