<?php
session_start();
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');
include_once('../../includes/regras-negocio.php');
include_once('../../includes/missao-helpers.php');

require_role(['caminhoneiro', 'transportador'], '../login.php');

$success = $error = '';
$avisosValidacao = [];
if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'caminhoneiro') {
    $validacao = validar_motorista_nova_missao($conn, (int)$_SESSION['user_id']);
    if (!$validacao['ok']) {
        $error = implode(' ', $validacao['erros']);
    }
    $avisosValidacao = $validacao['warnings'] ?? [];
}

// Regra: transportador precisa ter pelo menos 1 veículo operacional para enviar proposta
if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'transportador') {
    $transpCheck = validar_transportador_pode_candidatar($conn, (int)$_SESSION['user_id']);
    if (!$transpCheck['ok']) {
        $error = regras_erro_mensagem($transpCheck);
    }
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM veiculos
             WHERE proprietario_id = :id AND proprietario_tipo = 'transportador'
               AND estado_operacional = 'ativo'"
        );
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $ativos = (int)$stmt->fetchColumn();

        if ($ativos <= 0) {
            header('Location: ' . BASE_URL . '/pages/transportador/frota.php');
            exit;
        }
    } catch (PDOException $e) {
        error_log('Erro ao validar frota do transportador: ' . $e->getMessage());
        header('Location: ' . BASE_URL . '/pages/transportador/frota.php');
        exit;
    }
}

// Verificar se o ID da missão foi fornecido
if (!isset($_GET['id'])) {
    header('Location: missoes.php');
    exit;
}

$missao_id = (int)$_GET['id'];

try {
    // Buscar informações da missão
    $sql = "SELECT m.*, pe.nome_empresa, u.telefone as telefone_empresa
            FROM missoes m
            JOIN usuarios u ON m.empresa_id = u.id
            JOIN perfil_empresa pe ON u.id = pe.usuario_id
            WHERE m.id = :id AND m.status = 'aberta'";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $missao_id]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) {
        header('Location: missoes.php');
        exit;
    }

    if (($_SESSION['user_type'] ?? '') === 'caminhoneiro') {
        $pesoCheck = validar_peso_capacidade_missao($conn, $missao_id, (int)$_SESSION['user_id']);
        if (!$pesoCheck['ok']) {
            $error = implode(' ', $pesoCheck['erros']);
        }
        $avisosValidacao = array_merge($avisosValidacao, $pesoCheck['warnings'] ?? []);
    }

    // Verificar se já existe uma proposta do usuário
    $sql = "SELECT id FROM propostas 
            WHERE missao_id = :missao_id AND caminhoneiro_id = :caminhoneiro_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':missao_id' => $missao_id,
        ':caminhoneiro_id' => $_SESSION['user_id']
    ]);
    
    if ($stmt->fetch()) {
        $error = "Você já enviou uma proposta para esta missão.";
    }

    // Processar o envio da proposta
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
        $valor = filter_input(INPUT_POST, 'valor', FILTER_VALIDATE_FLOAT);
        $observacoes = trim(htmlspecialchars($_POST['observacoes'] ?? ''));

        if ($valor === false || $valor <= 0) {
            $error = "O valor da proposta deve ser maior que zero.";
        } else {
            $sql = "INSERT INTO propostas (missao_id, caminhoneiro_id, valor, observacoes, status, data_criacao)
                    VALUES (:missao_id, :caminhoneiro_id, :valor, :observacoes, 'pendente', NOW())";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':missao_id' => $missao_id,
                ':caminhoneiro_id' => $_SESSION['user_id'],
                ':valor' => $valor,
                ':observacoes' => $observacoes
            ]);

            notificar_proposta_nova($conn, $missao_id, (int)$_SESSION['user_id'], (float)$valor);
            registrar_log($conn, (int)$_SESSION['user_id'], 'criar', 'proposta', (int)$conn->lastInsertId(), 'Proposta enviada para missão #' . $missao_id);

            $success = "Proposta enviada com sucesso!";
        }
    }

} catch (PDOException $e) {
    error_log("Erro ao processar proposta: " . $e->getMessage());
    $error = "Erro ao processar sua proposta. Por favor, tente novamente.";
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enviar Proposta - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card fade-in">
                    <div class="card-body">
                        <h2 class="card-title mb-4">Enviar Proposta</h2>

                        <?php if ($success): ?>
                            <div class="alert alert-success" role="alert">
                                <?php echo $success; ?>
                                <div class="mt-3">
                                    <a href="missoes.php" class="btn btn-primary">Voltar para Missões</a>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php if ($error): ?>
                                <div class="alert alert-danger" role="alert">
                                    <?php echo $error; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($avisosValidacao)): ?>
                                <div class="alert alert-warning" role="alert">
                                    <ul class="mb-0 ps-3">
                                        <?php foreach ($avisosValidacao as $aviso): ?>
                                            <li><?php echo htmlspecialchars($aviso); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <div class="mb-4">
                                <h5>Detalhes da Missão</h5>
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h6 class="card-title"><?php echo htmlspecialchars($missao['titulo'] ?? ''); ?></h6>
                                        <div class="d-flex align-items-center mb-2">
                                            <i class="bi bi-building text-primary me-2"></i>
                                            <span><?php echo htmlspecialchars($missao['nome_empresa'] ?? ''); ?></span>
                                        </div>
                                        <div class="d-flex align-items-center mb-2">
                                            <i class="bi bi-geo-alt text-primary me-2"></i>
                                            <span><?php echo htmlspecialchars($missao['origem'] ?? ''); ?> → <?php echo htmlspecialchars($missao['destino'] ?? ''); ?></span>
                                        </div>
                                        <div class="d-flex align-items-center mb-2">
                                            <i class="bi bi-truck text-primary me-2"></i>
                                            <span><?php echo htmlspecialchars($missao['tipo_veiculo'] ?? ''); ?></span>
                                        </div>
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-currency-dollar text-primary me-2"></i>
                                            <span>Valor Sugerido: <?php echo number_format($missao['valor'] ?? 0, 2, ',', '.'); ?> MT</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <form method="POST" action="">
                                <div class="mb-3">
                                    <label for="valor" class="form-label">Valor da Proposta (MT)</label>
                                    <div class="input-group">
                                        <span class="input-group-text">MT</span>
                                        <input type="number" class="form-control" id="valor" name="valor" 
                                               step="0.01" min="0" required
                                               value="<?php echo $missao['valor'] ?? 0; ?>">
                                    </div>
                                    <div class="form-text">Digite o valor que você propõe para realizar esta missão.</div>
                                </div>

                                <div class="mb-4">
                                    <label for="observacoes" class="form-label">Observações</label>
                                    <textarea class="form-control" id="observacoes" name="observacoes" 
                                              rows="4" placeholder="Adicione informações relevantes sobre sua proposta..."></textarea>
                                </div>

                                <div class="d-flex justify-content-between">
                                    <a href="missoes.php" class="btn btn-outline-secondary">
                                        <i class="bi bi-arrow-left"></i> Voltar
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-send"></i> Enviar Proposta
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 