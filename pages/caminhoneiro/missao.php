<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');
include_once('../../includes/regras-negocio.php');

require_role(['caminhoneiro'], '../login.php');

// Verificar se o ID da missão foi fornecido
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: missoes.php');
    exit();
}

$missao_id = (int)$_GET['id'];
$user_id = (int)$_SESSION['user_id'];
$erro = '';
$sucesso = '';

// Buscar detalhes da missão
$sql = "SELECT m.*, u.nome as empresa_nome, u.email as empresa_email, u.telefone as empresa_telefone,
        (SELECT COUNT(*) FROM propostas WHERE missao_id = m.id) as total_propostas
        FROM missoes m
        JOIN usuarios u ON m.empresa_id = u.id
        WHERE m.id = :id";
$stmt = $conn->prepare($sql);
$stmt->execute([':id' => $missao_id]);

if ($stmt->rowCount() === 0) {
    header('Location: missoes.php');
    exit();
}

$missao = $stmt->fetch(PDO::FETCH_ASSOC);

$valorMissao = (float)($missao['valor'] ?? $missao['valor_total'] ?? $missao['valor_base'] ?? $missao['valor_proposto'] ?? 0);
$pesoMissao = (float)($missao['peso_carga'] ?? $missao['peso_kg'] ?? 0);

// Missões atribuídas ao motorista usam a página operacional completa
if ((int)($missao['caminhoneiro_id'] ?? 0) === $user_id
    && ($missao['status'] ?? '') !== 'aberta') {
    header('Location: detalhes-missao.php?id=' . $missao_id);
    exit();
}

// Verificar se o usuário já enviou uma proposta para esta missão
$sql = "SELECT * FROM propostas WHERE missao_id = :missao_id AND caminhoneiro_id = :caminhoneiro_id";
$stmt = $conn->prepare($sql);
$stmt->execute([
    ':missao_id' => $missao_id,
    ':caminhoneiro_id' => $user_id
]);
$proposta_existente = $stmt->fetch(PDO::FETCH_ASSOC);

$podePropor = true;
$validacao = validar_motorista_nova_missao($conn, $user_id);
if (!$validacao['ok']) {
    $podePropor = false;
    if (!$proposta_existente) {
        $erro = implode(' ', $validacao['erros']);
    }
}

// Processar envio de proposta
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_proposta'])) {
    if (!$podePropor) {
        $erro = implode(' ', $validacao['erros'] ?: ['Não pode enviar propostas neste momento.']);
    } else {
    $valor_proposto = isset($_POST['valor_proposto']) ? filter_var($_POST['valor_proposto'], FILTER_VALIDATE_FLOAT) : false;
    $prazo_entrega = isset($_POST['prazo_entrega']) ? trim((string)$_POST['prazo_entrega']) : '';
    $mensagem = isset($_POST['mensagem']) ? trim((string)$_POST['mensagem']) : '';
    
    // Validar dados
    if ($valor_proposto === false || $valor_proposto <= 0) {
        $erro = "Por favor, insira um valor válido para a proposta.";
    } elseif (empty($prazo_entrega)) {
        $erro = "Por favor, insira um prazo de entrega.";
    } elseif (empty($mensagem)) {
        $erro = "Por favor, insira uma mensagem para o contratante.";
    } elseif ($proposta_existente) {
        $erro = "Você já enviou uma proposta para esta missão.";
    } else {
        // Verificar se a data está dentro dos limites
        $data_hoje = date('Y-m-d');
        $data_prazo_missao = !empty($missao['prazo_entrega']) ? date('Y-m-d', strtotime($missao['prazo_entrega'])) : null;
        
        if (strtotime($prazo_entrega) < strtotime($data_hoje)) {
            $erro = "O prazo de entrega não pode ser anterior à data atual.";
        } elseif ($data_prazo_missao && strtotime($prazo_entrega) > strtotime($data_prazo_missao)) {
            $erro = "O prazo de entrega não pode ser posterior ao prazo estipulado pela missão.";
        } else {
            // Inserir proposta (schema: valor + observacoes)
            try {
                $obs = 'Prazo proposto: ' . date('d/m/Y', strtotime($prazo_entrega)) . "\n\n" . $mensagem;
                $sql = "INSERT INTO propostas (missao_id, caminhoneiro_id, valor, observacoes, status) 
                        VALUES (:missao_id, :caminhoneiro_id, :valor, :observacoes, 'pendente')";
                $stmt = $conn->prepare($sql);
                $result = $stmt->execute([
                    ':missao_id' => $missao_id,
                    ':caminhoneiro_id' => $user_id,
                    ':valor' => $valor_proposto,
                    ':observacoes' => $obs,
                ]);
                
                if ($result) {
                    // Criar notificação para a empresa
                    $sql = "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem) 
                            VALUES (:usuario_id, 'proposta', 'Nova proposta recebida', 
                            :mensagem)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':usuario_id' => $missao['empresa_id'],
                        ':mensagem' => 'Recebeu uma nova proposta para a missão: ' . ($missao['titulo'] ?? ''),
                    ]);
                    
                    $proposta_id = $conn->lastInsertId();
                    $sucesso = "Sua proposta foi enviada com sucesso!";
                    
                    // Recarregar proposta existente
                    $sql = "SELECT * FROM propostas WHERE id = :id";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([':id' => $proposta_id]);
                    $proposta_existente = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $erro = "Erro ao enviar proposta. Por favor, tente novamente.";
                }
            } catch (PDOException $e) {
                error_log('missao.php proposta: ' . $e->getMessage());
                $erro = "Erro ao enviar proposta. Tente novamente.";
            }
        }
    }
    }
}

$valorPropostaExistente = (float)($proposta_existente['valor'] ?? $proposta_existente['valor_proposto'] ?? 0);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($missao['titulo']); ?> - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><?php echo htmlspecialchars($missao['titulo']); ?></h1>
            <a href="missoes.php" class="btn btn-outline-primary">
                <i class="bi bi-arrow-left"></i> Voltar para lista
            </a>
        </div>
        
        <?php if ($erro): ?>
            <div class="alert alert-danger"><?php echo $erro; ?></div>
        <?php endif; ?>
        
        <?php if ($sucesso): ?>
            <div class="alert alert-success"><?php echo $sucesso; ?></div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-md-8">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Detalhes da Missão</h5>
                    </div>
                    <div class="card-body">
                        <h6>Descrição</h6>
                        <p><?php echo nl2br(htmlspecialchars($missao['descricao'])); ?></p>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Carga</h6>
                                <ul class="list-unstyled">
                                    <li><strong>Tipo:</strong> <?php echo htmlspecialchars(isset($missao['tipo_carga']) ? $missao['tipo_carga'] : 'Não especificado'); ?></li>
                                    <li><strong>Peso:</strong> <?php echo number_format($pesoMissao, 2, ',', '.'); ?> kg</li>
                                    <?php if (isset($missao['dimensoes_carga']) && !empty($missao['dimensoes_carga'])): ?>
                                    <li><strong>Dimensões:</strong> <?php echo htmlspecialchars($missao['dimensoes_carga']); ?></li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                            
                            <div class="col-md-6">
                                <h6>Rota</h6>
                                <ul class="list-unstyled">
                                    <li><strong>Origem:</strong> <?php echo htmlspecialchars($missao['origem']); ?></li>
                                    <li><strong>Destino:</strong> <?php echo htmlspecialchars($missao['destino']); ?></li>
                                    <li><strong>Prazo de Entrega:</strong> <?php echo date('d/m/Y', strtotime($missao['prazo_entrega'])); ?></li>
                                </ul>
                            </div>
                        </div>
                        
                        <h6>Valor Proposto</h6>
                        <p class="fs-4 text-primary">MT <?php echo number_format($valorMissao, 2, ',', '.'); ?></p>
                    </div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Empresa Contratante</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled">
                            <li><strong>Nome:</strong> <?php echo htmlspecialchars($missao['empresa_nome']); ?></li>
                            <li><strong>Telefone:</strong> <?php echo (!empty($missao['empresa_telefone'])) ? htmlspecialchars($missao['empresa_telefone']) : 'Não informado'; ?></li>
                            <li><strong>Email:</strong> <?php echo htmlspecialchars($missao['empresa_email']); ?></li>
                        </ul>
                        
                        <?php if ($proposta_existente): ?>
                            <a href="../chat.php?user=<?php echo $missao['empresa_id']; ?>&missao=<?php echo $missao_id; ?>" class="btn btn-primary">
                                <i class="bi bi-chat"></i> Conversar com a Empresa
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <?php if ($missao['status'] === 'aberta' && !$proposta_existente && $podePropor): ?>
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">Enviar Proposta</h5>
                        </div>
                        <div class="card-body">
                            <form method="post" action="">
                                <div class="mb-3">
                                    <label for="valor_proposto" class="form-label">Seu valor (MT)</label>
                                    <input type="number" step="0.01" min="1" class="form-control" id="valor_proposto" name="valor_proposto" required>
                                    <small class="text-muted">Valor proposto pelo contratante: MT <?php echo number_format($valorMissao, 2, ',', '.'); ?></small>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="prazo_entrega" class="form-label">Prazo de entrega</label>
                                    <input type="date" class="form-control" id="prazo_entrega" name="prazo_entrega" 
                                           min="<?php echo date('Y-m-d'); ?>" 
                                           max="<?php echo date('Y-m-d', strtotime($missao['prazo_entrega'])); ?>" required>
                                    <small class="text-muted">Limite: <?php echo date('d/m/Y', strtotime($missao['prazo_entrega'])); ?></small>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="mensagem" class="form-label">Mensagem para o contratante</label>
                                    <textarea class="form-control" id="mensagem" name="mensagem" rows="4" required placeholder="Explique por que você é a melhor opção para esta missão"></textarea>
                                </div>
                                
                                <div class="d-grid">
                                    <button type="submit" name="enviar_proposta" class="btn btn-primary">
                                        <i class="bi bi-send"></i> Enviar Proposta
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php elseif ($missao['status'] === 'aberta' && !$proposta_existente && !$podePropor): ?>
                    <div class="card border-warning">
                        <div class="card-header bg-warning">Verificação necessária</div>
                        <div class="card-body">
                            <p class="mb-2 small">Conta ainda em análise ou incompleta — não pode enviar propostas.</p>
                            <a href="<?php echo BASE_URL; ?>/pages/shared/verificacao-conta.php" class="btn btn-warning btn-sm">Completar verificação</a>
                        </div>
                    </div>
                <?php elseif ($proposta_existente): ?>
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0">Sua Proposta</h5>
                        </div>
                        <div class="card-body">
                            <p>Você já enviou uma proposta para esta missão.</p>
                            
                            <div class="mb-3">
                                <strong>Valor Proposto:</strong>
                                <p class="fs-4 text-success">MT <?php echo number_format($valorPropostaExistente, 2, ',', '.'); ?></p>
                            </div>
                            
                            <?php if (!empty($proposta_existente['prazo_entrega'])): ?>
                            <div class="mb-3">
                                <strong>Prazo de Entrega:</strong>
                                <p><?php echo date('d/m/Y', strtotime($proposta_existente['prazo_entrega'])); ?></p>
                            </div>
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <strong>Status:</strong>
                                <p>
                                    <span class="badge bg-<?php 
                                        echo $proposta_existente['status'] === 'aceita' ? 'success' : 
                                            ($proposta_existente['status'] === 'rejeitada' ? 'danger' : 'warning'); 
                                    ?>">
                                        <?php 
                                            echo $proposta_existente['status'] === 'aceita' ? 'Aceita' : 
                                                ($proposta_existente['status'] === 'rejeitada' ? 'Rejeitada' : 'Pendente'); 
                                        ?>
                                    </span>
                                </p>
                            </div>
                            
                            <div class="mb-3">
                                <strong>Sua Mensagem:</strong>
                                <p><?php echo nl2br(e($proposta_existente['observacoes'] ?? $proposta_existente['mensagem'] ?? '')); ?></p>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <a href="../chat.php?user=<?php echo $missao['empresa_id']; ?>&missao=<?php echo $missao_id; ?>" class="btn btn-primary">
                                    <i class="bi bi-chat"></i> Conversar com a Empresa
                                </a>
                                
                                <?php if ($proposta_existente['status'] === 'pendente'): ?>
                                    <form method="post" action="cancelar_proposta.php" onsubmit="return confirm('Tem certeza que deseja cancelar esta proposta?');">
                                        <input type="hidden" name="proposta_id" value="<?php echo $proposta_existente['id']; ?>">
                                        <button type="submit" class="btn btn-danger w-100">
                                            <i class="bi bi-x-circle"></i> Cancelar Proposta
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="card-header bg-secondary text-white">
                            <h5 class="mb-0">Missão não disponível</h5>
                        </div>
                        <div class="card-body">
                            <p>Esta missão não está mais disponível para propostas.</p>
                            <p>Status atual: 
                                <span class="badge bg-<?php 
                                    echo $missao['status'] === 'concluida' ? 'success' : 
                                        ($missao['status'] === 'cancelada' ? 'danger' : 'info'); 
                                ?>">
                                    <?php 
                                        echo $missao['status'] === 'concluida' ? 'Concluída' : 
                                            ($missao['status'] === 'cancelada' ? 'Cancelada' : ucfirst($missao['status'])); 
                                    ?>
                                </span>
                            </p>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0">Estatísticas</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Total de Propostas
                                <span class="badge bg-primary rounded-pill"><?php echo $missao['total_propostas']; ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Data de Publicação
                                <span><?php echo date('d/m/Y', strtotime($missao['data_criacao'])); ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Última Atualização
                                <span><?php echo date('d/m/Y', strtotime($missao['data_atualizacao'])); ?></span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="bg-light mt-5 py-3">
        <div class="container text-center">
            <p>&copy; 2024 TrackMoz. Todos os direitos reservados.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
    <script>
        // Validação adicional para o formulário de proposta
        document.addEventListener('DOMContentLoaded', function() {
            var formProposta = document.querySelector('form[name="enviar_proposta"]');
            if (formProposta) {
                var prazoInput = document.getElementById('prazo_entrega');
                var dataHoje = new Date().toISOString().split('T')[0];
                var dataPrazoMissao = '<?php echo date('Y-m-d', strtotime($missao['prazo_entrega'])); ?>';
                
                // Definir valores min e max manualmente (para navegadores que não suportam os atributos HTML5)
                prazoInput.setAttribute('min', dataHoje);
                prazoInput.setAttribute('max', dataPrazoMissao);
                
                formProposta.addEventListener('submit', function(event) {
                    var prazoSelecionado = prazoInput.value;
                    
                    if (prazoSelecionado < dataHoje) {
                        alert('O prazo de entrega não pode ser anterior à data atual.');
                        event.preventDefault();
                        return false;
                    }
                    
                    if (prazoSelecionado > dataPrazoMissao) {
                        alert('O prazo de entrega não pode ser posterior ao prazo estipulado pela missão.');
                        event.preventDefault();
                        return false;
                    }
                    
                    return true;
                });
            }
        });
    </script>
</body>
</html> 