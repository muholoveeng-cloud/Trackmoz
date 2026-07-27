<?php
session_start();
include_once('../../config/database.php');
include_once('../../includes/auth.php');

require_role(['admin'], '../login.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validar dados usando htmlspecialchars em vez de FILTER_SANITIZE_STRING
        $nome = htmlspecialchars(trim($_POST['nome'] ?? ''), ENT_QUOTES, 'UTF-8');
        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $telefone = htmlspecialchars(trim($_POST['telefone'] ?? ''), ENT_QUOTES, 'UTF-8');
        $tipo_usuario = htmlspecialchars(trim($_POST['tipo_usuario'] ?? ''), ENT_QUOTES, 'UTF-8');
        $status = htmlspecialchars(trim($_POST['status'] ?? ''), ENT_QUOTES, 'UTF-8');
        $senha = $_POST['senha'];
        $confirmar_senha = $_POST['confirmar_senha'];

        // Validações
        if (empty($nome) || empty($email) || empty($tipo_usuario) || empty($status) || empty($senha)) {
            throw new Exception('Todos os campos obrigatórios devem ser preenchidos');
        }

        if ($senha !== $confirmar_senha) {
            throw new Exception('As senhas não coincidem');
        }

        if (strlen($senha) < 6) {
            throw new Exception('A senha deve ter pelo menos 6 caracteres');
        }

        // Verificar se o email já existe
        $stmt = $conn->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new Exception('Este email já está cadastrado');
        }

        // Iniciar transação
        $conn->beginTransaction();

        // Inserir usuário
        $sql = "INSERT INTO usuarios (nome, email, senha, tipo_usuario, telefone, status, data_registro) 
                VALUES (:nome, :email, :senha, :tipo_usuario, :telefone, :status, NOW())";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':nome' => $nome,
            ':email' => $email,
            ':senha' => password_hash($senha, PASSWORD_DEFAULT),
            ':tipo_usuario' => $tipo_usuario,
            ':telefone' => $telefone,
            ':status' => $status
        ]);

        $usuario_id = $conn->lastInsertId();

        // Se for caminhoneiro, empresa ou transportador, criar perfil correspondente
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

        // Enviar email de notificação
        $to = $email;
        $subject = "TrackMoz - Sua conta foi criada!";
        $message = "Olá " . $nome . ",\n\n";
        $message .= "Uma conta foi criada para você na TrackMoz.\n";
        $message .= "Seus dados de acesso são:\n\n";
        $message .= "Email: " . $email . "\n";
        $message .= "Senha: " . $senha . "\n\n";
        $message .= "Por favor, altere sua senha após o primeiro acesso.\n\n";
        $message .= "Acesse agora: http://localhost/Frete%20Ship/pages/login.php\n\n";
        $message .= "Atenciosamente,\nEquipe TrackMoz";
        
        $headers = "From: noreply@trackmoz.com\r\n";
        $headers .= "Reply-To: suporte@trackmoz.com\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        // Tentar enviar o email, mas não impedir a criação se falhar
        if (!mail($to, $subject, $message, $headers)) {
            error_log("Aviso: Não foi possível enviar o email de notificação para " . $to);
        }

        // Confirmar transação
        $conn->commit();

        header('Location: usuarios.php?success=1');
        exit();
    } catch (Exception $e) {
        // Reverter transação em caso de erro
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        
        // Log detalhado do erro
        error_log("Erro ao adicionar usuário: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        header('Location: usuarios.php?error=1&msg=' . urlencode($e->getMessage()));
        exit();
    }
} else {
    header('Location: usuarios.php');
    exit();
} 