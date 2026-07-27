<?php
session_start();
require_once '../../config/app.php';
require_once '../../config/database.php';

// Verificar se usuário está logado e é do tipo admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] != 'admin') {
    header('Location: ../login.php');
    exit;
}

$conn = getConnection();

// Verificar se o ID do documento foi fornecido
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "ID do documento não fornecido.";
    header('Location: perfil.php');
    exit;
}

$documento_id = (int)$_GET['id'];

// Verificar se a ação foi fornecida
if (!isset($_GET['action']) || !in_array($_GET['action'], ['aprovar', 'rejeitar'])) {
    $_SESSION['error'] = "Ação inválida.";
    header('Location: perfil.php');
    exit;
}

$action = $_GET['action'];

try {
    // Buscar informações do documento
    $sql = "SELECT d.*, u.nome, u.email, u.tipo_usuario 
            FROM documentos d 
            JOIN usuarios u ON d.usuario_id = u.id 
            WHERE d.id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $documento_id]);
    $documento = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$documento) {
        $_SESSION['error'] = "Documento não encontrado.";
        header('Location: perfil.php');
        exit;
    }

    // Verificar se o documento já foi processado
    if ($documento['status'] != 'pendente') {
        $_SESSION['error'] = "Este documento já foi processado.";
        header('Location: perfil.php');
        exit;
    }

    // Atualizar status do documento
    $novo_status = ($action == 'aprovar') ? 'aprovado' : 'rejeitado';
    $sql = "UPDATE documentos SET status = :status, data_aprovacao = NOW() WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':status' => $novo_status,
        ':id' => $documento_id
    ]);

    // Se aprovado, atualizar o status do usuário se necessário
    if ($action == 'aprovar') {
        // Verificar se o usuário tem documentos pendentes restantes
        $sql = "SELECT COUNT(*) FROM documentos WHERE usuario_id = :usuario_id AND status = 'pendente'";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':usuario_id' => $documento['usuario_id']]);
        $pendentes = $stmt->fetchColumn();

        if ($pendentes == 0) {
            // Se não há mais documentos pendentes, verificar se o usuário pode ser ativado
            $sql = "SELECT COUNT(*) FROM documentos WHERE usuario_id = :usuario_id AND status = 'aprovado'";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':usuario_id' => $documento['usuario_id']]);
            $aprovados = $stmt->fetchColumn();

            if ($aprovados > 0) {
                // Atualizar status do usuário para ativo
                $sql = "UPDATE usuarios SET status = 'ativo' WHERE id = :id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':id' => $documento['usuario_id']]);
            }
        }
    }

    $_SESSION['success'] = "Documento " . ($action == 'aprovar' ? 'aprovado' : 'rejeitado') . " com sucesso!";
    header('Location: perfil.php');
    exit;

} catch (PDOException $e) {
    $_SESSION['error'] = "Erro ao processar documento: " . $e->getMessage();
    header('Location: perfil.php');
    exit;
}
