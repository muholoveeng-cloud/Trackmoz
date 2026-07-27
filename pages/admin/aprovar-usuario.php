<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');
include_once('../../includes/kyc-helpers.php');
include_once('../../includes/notificacoes-helpers.php');

require_role(['admin'], '../login.php');

if (!isset($_GET['id'])) {
    header('Location: usuarios.php');
    exit;
}

$id = (int)$_GET['id'];

try {
    if ($id <= 0) {
        throw new Exception('ID de usuário inválido');
    }

    kyc_bootstrap($conn);

    $sql = "SELECT nome, email, tipo_usuario FROM usuarios WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        throw new Exception('Usuário não encontrado');
    }

    $conn->beginTransaction();

    // Activo para login, mas permanece visitante até KYC completo
    kyc_marcar_visitante($conn, $id);

    notificar_usuario(
        $conn,
        $id,
        'info',
        'Conta aprovada — complete a verificação',
        'A sua conta foi aprovada. Está em modo visitante: complete os dados legais e envie os documentos para poder negociar ou criar missões.',
        kyc_url_verificacao()
    );

    $to = $usuario['email'];
    $subject = 'TrackMoz - Conta aprovada (verificação pendente)';
    $message = "Olá " . $usuario['nome'] . ",\n\n";
    $message .= "A sua conta na TrackMoz foi aprovada. Pode fazer login.\n\n";
    $message .= "IMPORTANTE: ainda está como visitante. Para operar (missões/propostas) deve:\n";
    $message .= "1) Preencher o formulário legal\n";
    $message .= "2) Enviar os documentos obrigatórios\n";
    $message .= "3) Aguardar aprovação da administração\n\n";
    $message .= "Verificação: " . kyc_url_verificacao() . "\n\n";
    $message .= "Equipe TrackMoz";
    $headers = "From: noreply@trackmoz.com\r\n";
    @mail($to, $subject, $message, $headers);

    $conn->commit();
    header('Location: usuarios.php?success=2');
    exit;
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('aprovar-usuario: ' . $e->getMessage());
    header('Location: usuarios.php?error=1&msg=' . urlencode($e->getMessage()));
    exit;
}
