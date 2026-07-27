<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'caminhoneiro') {
    header('Location: ' . BASE_URL . '/pages/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['foto_veiculo'])) {
    try {
        $upload_dir = '../../uploads/veiculos/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_extension = strtolower(pathinfo($_FILES['foto_veiculo']['name'], PATHINFO_EXTENSION));
        $new_filename = 'veiculo_' . $_SESSION['user_id'] . '_' . time() . '.' . $file_extension;
        $upload_path = $upload_dir . $new_filename;

        if (move_uploaded_file($_FILES['foto_veiculo']['tmp_name'], $upload_path)) {
            // Inserir foto no banco de dados
            $sql = "INSERT INTO fotos_veiculo (usuario_id, caminho_arquivo, nome_arquivo, tipo_veiculo) 
                    VALUES (:usuario_id, :caminho_arquivo, :nome_arquivo, :tipo_veiculo)";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':usuario_id' => $_SESSION['user_id'],
                ':caminho_arquivo' => $upload_path,
                ':nome_arquivo' => $new_filename,
                ':tipo_veiculo' => $_POST['tipo_veiculo'] ?? null
            ]);

            header('Location: ' . BASE_URL . '/pages/caminhoneiro/perfil.php?success=3');
            exit;
        } else {
            throw new Exception("Erro ao fazer upload da foto.");
        }
    } catch (Exception $e) {
        header('Location: ' . BASE_URL . '/pages/caminhoneiro/perfil.php?error=' . urlencode($e->getMessage()));
        exit;
    }
} else {
    header('Location: ' . BASE_URL . '/pages/caminhoneiro/perfil.php');
    exit;
} 