<?php
session_start();
include_once('../../config/database.php');

// Verificar se o usuário está logado e é um administrador
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'admin') {
    header('Location: ../login.php');
    exit();
}

// Verificar se o ID foi fornecido
if (!isset($_GET['id'])) {
    header('Location: usuarios.php');
    exit();
}

$id = (int)$_GET['id'];

try {
    // Verificar conexão com o banco
    if (!isset($conn) || !$conn) {
        throw new Exception('Erro de conexão com o banco de dados');
    }

    // Verificar se o ID é válido
    if ($id <= 0) {
        throw new Exception('ID de usuário inválido');
    }

    // Buscar informações do usuário
    $sql = "SELECT nome, email FROM usuarios WHERE id = :id";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Erro ao preparar consulta: ' . $conn->error);
    }
    
    $stmt->execute([':id' => $id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        throw new Exception('Usuário não encontrado');
    }

    // Iniciar transação
    $conn->beginTransaction();

    // Atualizar status do usuário para inativo
    // Nota: ultima_atualizacao será atualizado automaticamente pelo trigger ON UPDATE CURRENT_TIMESTAMP
    $sql = "UPDATE usuarios SET status = 'inativo' WHERE id = :id";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Erro ao preparar atualização: ' . $conn->error);
    }
    
    if (!$stmt->execute([':id' => $id])) {
        throw new Exception('Erro ao executar atualização: ' . implode(', ', $stmt->errorInfo()));
    }

    // Verificar se a atualização afetou alguma linha
    if ($stmt->rowCount() === 0) {
        throw new Exception('Nenhum usuário foi atualizado');
    }

    // Enviar email de notificação
    $to = $usuario['email'];
    $subject = "TrackMoz - Status da sua conta";
    $message = "Olá " . $usuario['nome'] . ",\n\n";
    $message .= "Infelizmente, sua solicitação de conta na TrackMoz não foi aprovada neste momento.\n";
    $message .= "Se você acredita que isso foi um erro ou gostaria de mais informações, por favor, entre em contato com nosso suporte.\n\n";
    $message .= "Email de suporte: suporte@trackmoz.com\n\n";
    $message .= "Atenciosamente,\nEquipe TrackMoz";
    
    $headers = "From: noreply@trackmoz.com\r\n";
    $headers .= "Reply-To: suporte@trackmoz.com\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    // Tentar enviar o email, mas não impedir a rejeição se falhar
    if (!mail($to, $subject, $message, $headers)) {
        error_log("Aviso: Não foi possível enviar o email de notificação para " . $to);
    }

    // Confirmar transação
    $conn->commit();

    header('Location: usuarios.php?success=3');
    exit();
} catch (Exception $e) {
    // Reverter transação em caso de erro
    if (isset($conn) && $conn && $conn->inTransaction()) {
        $conn->rollBack();
    }
    
    // Log detalhado do erro
    error_log("Erro ao rejeitar usuário (ID: $id): " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    // Redirecionar com mensagem de erro específica
    header('Location: usuarios.php?error=2&msg=' . urlencode($e->getMessage()));
    exit();
} 