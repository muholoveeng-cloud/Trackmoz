<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');

include_once('../../includes/auth.php');

require_role(['empresa'], '../login.php');

$user = isset($_GET['caminhoneiro']) ? (int)$_GET['caminhoneiro'] : 0;
$missao = isset($_GET['missao']) ? (int)$_GET['missao'] : 0;

if ($user > 0) {
    $url = BASE_URL . '/pages/chat.php?user=' . $user;
    if ($missao > 0) {
        $url .= '&missao=' . $missao;
    }
    header('Location: ' . $url);
    exit;
}

// Verificar se o ID do caminhoneiro foi fornecido
if (!isset($_GET['caminhoneiro'])) {
    header('Location: ' . BASE_URL . '/pages/contratante/missoes.php');
    exit;
}

$caminhoneiro_id = (int)$_GET['caminhoneiro'];
$empresa_id = $_SESSION['user_id'];
$usuario1_id = min($empresa_id, $caminhoneiro_id);
$usuario2_id = max($empresa_id, $caminhoneiro_id);
$error = $success = '';

try {
    // Buscar informações do caminhoneiro
    $sql = "SELECT u.nome, u.telefone, u.email, pc.avaliacao_media, pc.total_entregas 
            FROM usuarios u 
            LEFT JOIN perfil_caminhoneiro pc ON u.id = pc.usuario_id
            WHERE u.id = :caminhoneiro_id AND u.tipo_usuario = 'caminhoneiro'";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':caminhoneiro_id' => $caminhoneiro_id]);
    $caminhoneiro = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$caminhoneiro) {
        header('Location: ' . BASE_URL . '/pages/contratante/missoes.php');
        exit;
    }

    // Processar envio de nova mensagem
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mensagem'])) {
        $mensagem = trim($_POST['mensagem']);
        if (!empty($mensagem)) {
            // Inserir a mensagem
            $sql = "INSERT INTO mensagens (remetente_id, destinatario_id, mensagem) 
                    VALUES (:remetente_id, :destinatario_id, :mensagem)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':remetente_id' => $empresa_id,
                ':destinatario_id' => $caminhoneiro_id,
                ':mensagem' => $mensagem
            ]);

            // Atualizar ou criar conversa (missao_id NULL não dispara UNIQUE no MySQL)
            $sql = "SELECT id FROM conversas
                    WHERE usuario1_id = :usuario1_id AND usuario2_id = :usuario2_id AND missao_id IS NULL
                    ORDER BY id DESC
                    LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':usuario1_id' => $usuario1_id,
                ':usuario2_id' => $usuario2_id,
            ]);
            $conversa_id = (int)($stmt->fetchColumn() ?: 0);

            if ($conversa_id > 0) {
                $sql = "UPDATE conversas SET ultima_atualizacao = NOW() WHERE id = :id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':id' => $conversa_id]);
            } else {
                $sql = "INSERT INTO conversas (usuario1_id, usuario2_id, missao_id, ultima_atualizacao)
                        VALUES (:usuario1_id, :usuario2_id, NULL, NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':usuario1_id' => $usuario1_id,
                    ':usuario2_id' => $usuario2_id,
                ]);
            }

            $success = "Mensagem enviada com sucesso!";
        }
    }

    // Buscar histórico de mensagens
    $sql = "SELECT m.*, u.nome as nome_remetente 
            FROM mensagens m
            JOIN usuarios u ON m.remetente_id = u.id
            WHERE (m.remetente_id = :usuario1_id AND m.destinatario_id = :usuario2_id)
            OR (m.remetente_id = :usuario2_id AND m.destinatario_id = :usuario1_id)
            ORDER BY m.data_envio DESC
            LIMIT 50";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':usuario1_id' => $empresa_id,
        ':usuario2_id' => $caminhoneiro_id
    ]);
    $mensagens = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Marcar mensagens como lidas
    $sql = "UPDATE mensagens SET lida = 1 
            WHERE destinatario_id = :destinatario_id 
            AND remetente_id = :remetente_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':destinatario_id' => $empresa_id,
        ':remetente_id' => $caminhoneiro_id
    ]);

} catch (PDOException $e) {
    error_log("Erro no chat: " . $e->getMessage());
    $error = "Erro ao processar mensagens. Por favor, tente novamente.";
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <style>
        .chat-container {
            height: 60vh;
            overflow-y: auto;
            display: flex;
            flex-direction: column-reverse;
        }
        .message {
            margin-bottom: 1rem;
            padding: 0.5rem 1rem;
            border-radius: 15px;
            max-width: 75%;
        }
        .message-sent {
            background-color: #007bff;
            color: white;
            margin-left: auto;
        }
        .message-received {
            background-color: #e9ecef;
            margin-right: auto;
        }
        .message-time {
            font-size: 0.75rem;
            opacity: 0.8;
        }
    </style>
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container mt-4">
        <div class="row mb-4">
            <div class="col-md-8">
                <h2>Chat com <?php echo htmlspecialchars($caminhoneiro['nome']); ?></h2>
                <p class="text-muted">
                    <i class="bi bi-star-fill text-warning"></i>
                    <?php echo number_format($caminhoneiro['avaliacao_media'], 1); ?> 
                    (<?php echo $caminhoneiro['total_entregas']; ?> entregas)
                </p>
            </div>
            <div class="col-md-4 text-end">
                <a href="javascript:history.back()" class="btn btn-outline-primary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body chat-container" id="chatContainer">
                <?php foreach ($mensagens as $mensagem): ?>
                    <div class="message <?php echo $mensagem['remetente_id'] == $empresa_id ? 'message-sent' : 'message-received'; ?>">
                        <div class="message-content">
                            <?php echo nl2br(htmlspecialchars($mensagem['mensagem'])); ?>
                        </div>
                        <div class="message-time">
                            <?php echo date('d/m/Y H:i', strtotime($mensagem['data_envio'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="card-footer">
                <form method="POST" action="" id="messageForm">
                    <div class="input-group">
                        <textarea class="form-control" name="mensagem" placeholder="Digite sua mensagem..." rows="2" required></textarea>
                        <button class="btn btn-primary" type="submit">
                            <i class="bi bi-send"></i> Enviar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-scroll para a última mensagem
        document.addEventListener('DOMContentLoaded', function() {
            const chatContainer = document.getElementById('chatContainer');
            chatContainer.scrollTop = 0;
        });

        // Atualizar chat a cada 10 segundos
        setInterval(function() {
            location.reload();
        }, 10000);

        // Limpar mensagem de sucesso após envio
        const successAlert = document.querySelector('.alert-success');
        if (successAlert) {
            setTimeout(function() {
                successAlert.remove();
            }, 3000);
        }
    </script>
</body>
</html> 