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

$id = $_GET['id'];

try {
    $conn->beginTransaction();

    // Excluir registros relacionados primeiro
    $sql = "DELETE FROM perfil_caminhoneiro WHERE usuario_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id]);

    $sql = "DELETE FROM perfil_empresa WHERE usuario_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id]);

    // Excluir o usuário
    $sql = "DELETE FROM usuarios WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id]);

    $conn->commit();
    header('Location: usuarios.php?success=4');
    exit();
} catch (Exception $e) {
    $conn->rollBack();
    header('Location: usuarios.php?error=3');
    exit();
} 