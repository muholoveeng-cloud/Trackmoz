<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');

// Verificar se o usuário está logado e é um caminhoneiro
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'caminhoneiro') {
    header('Location: ' . BASE_URL . '/pages/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();

        // Atualizar dados do usuário
        $sql = "UPDATE usuarios SET 
                nome = :nome,
                email = :email,
                telefone = :telefone,
                ultima_atividade = CURRENT_TIMESTAMP
                WHERE id = :id";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':nome' => $_POST['nome'],
            ':email' => $_POST['email'],
            ':telefone' => $_POST['telefone'],
            ':id' => $_SESSION['user_id']
        ]);

        // Verificar se já existe um perfil de caminhoneiro
        $sql = "SELECT COUNT(*) FROM perfil_caminhoneiro WHERE usuario_id = :usuario_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':usuario_id' => $_SESSION['user_id']]);
        $exists = $stmt->fetchColumn();

        if ($exists) {
            // Atualizar perfil existente
            $sql = "UPDATE perfil_caminhoneiro SET 
                    tipo_veiculo = :tipo_veiculo,
                    capacidade_carga = :capacidade_carga,
                    numero_cnh = :numero_cnh,
                    validade_cnh = :validade_cnh,
                    placa_veiculo = :placa_veiculo,
                    descricao_veiculo = :descricao_veiculo
                    WHERE usuario_id = :usuario_id";
        } else {
            // Inserir novo perfil
            $sql = "INSERT INTO perfil_caminhoneiro (
                    usuario_id, tipo_veiculo, capacidade_carga, numero_cnh,
                    validade_cnh, placa_veiculo, descricao_veiculo
                ) VALUES (
                    :usuario_id, :tipo_veiculo, :capacidade_carga, :numero_cnh,
                    :validade_cnh, :placa_veiculo, :descricao_veiculo
                )";
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':usuario_id' => $_SESSION['user_id'],
            ':tipo_veiculo' => $_POST['tipo_veiculo'],
            ':capacidade_carga' => $_POST['capacidade_carga'],
            ':numero_cnh' => $_POST['numero_cnh'],
            ':validade_cnh' => $_POST['validade_cnh'],
            ':placa_veiculo' => $_POST['placa_veiculo'],
            ':descricao_veiculo' => $_POST['descricao_veiculo']
        ]);

        // Processar foto de perfil se foi enviada
        if (isset($_FILES['foto_perfil']) && $_FILES['foto_perfil']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../../uploads/perfil/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $file_extension = strtolower(pathinfo($_FILES['foto_perfil']['name'], PATHINFO_EXTENSION));
            $new_filename = 'perfil_' . $_SESSION['user_id'] . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $new_filename;

            if (move_uploaded_file($_FILES['foto_perfil']['tmp_name'], $upload_path)) {
                $sql = "UPDATE usuarios SET foto_perfil = :foto_perfil WHERE id = :id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':foto_perfil' => $new_filename,
                    ':id' => $_SESSION['user_id']
                ]);
            }
        }

        // Atualizar senha se fornecida
        if (!empty($_POST['senha_atual']) && !empty($_POST['nova_senha']) && !empty($_POST['confirmar_senha'])) {
            // Verificar senha atual
            $sql = "SELECT senha FROM usuarios WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':id' => $_SESSION['user_id']]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

            if (password_verify($_POST['senha_atual'], $usuario['senha'])) {
                if ($_POST['nova_senha'] === $_POST['confirmar_senha']) {
                    $nova_senha_hash = password_hash($_POST['nova_senha'], PASSWORD_DEFAULT);
                    
                    $sql = "UPDATE usuarios SET senha = :senha WHERE id = :id";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':senha' => $nova_senha_hash,
                        ':id' => $_SESSION['user_id']
                    ]);
                } else {
                    throw new Exception("As senhas não coincidem.");
                }
            } else {
                throw new Exception("Senha atual incorreta.");
            }
        }

        $conn->commit();
        header('Location: ' . BASE_URL . '/pages/caminhoneiro/perfil.php?success=1');
        exit;
    } catch (Exception $e) {
        $conn->rollBack();
        header('Location: ' . BASE_URL . '/pages/caminhoneiro/perfil.php?error=' . urlencode($e->getMessage()));
        exit;
    }
} else {
    header('Location: ' . BASE_URL . '/pages/caminhoneiro/perfil.php');
    exit;
}