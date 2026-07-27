<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/helpers.php');
include_once('../../includes/motorista-regras.php');

// Função para garantir que o perfil do caminhoneiro exista e tenha dados válidos
function verificarECorrigirPerfil($conn, $user_id) {
    // Verificar se o perfil existe
    $check_sql = "SELECT * FROM perfil_caminhoneiro WHERE usuario_id = :id";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->execute([':id' => $user_id]);
    $perfil = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$perfil) {
        // Criar perfil se não existir
        $init_sql = "INSERT INTO perfil_caminhoneiro 
                    (usuario_id, tipo_veiculo, placa_veiculo, capacidade_carga, descricao_veiculo, disponibilidade) 
                    VALUES 
                    (:id, 'Não informado', 'Não informado', 0, 'Não informado', 'indisponivel')";
        $init_stmt = $conn->prepare($init_sql);
        $init_stmt->execute([':id' => $user_id]);
        
        // Buscar o perfil recém-criado
        $check_stmt->execute([':id' => $user_id]);
        $perfil = $check_stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    return $perfil;
}

// Verificar se o usuário está logado e é um caminhoneiro
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'caminhoneiro') {
    header('Location: ' . BASE_URL . '/pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Verificar e corrigir o perfil antes de continuar
$perfil_caminhoneiro = verificarECorrigirPerfil($conn, $user_id);

// Verificar se o perfil existe antes de continuar
try {
    // Verificar se os dados do veículo estão completos
    if (empty($perfil_caminhoneiro['tipo_veiculo']) || 
        $perfil_caminhoneiro['tipo_veiculo'] == 'Não informado' ||
        empty($perfil_caminhoneiro['placa_veiculo']) || 
        $perfil_caminhoneiro['placa_veiculo'] == 'Não informado' ||
        empty($perfil_caminhoneiro['capacidade_carga']) || 
        $perfil_caminhoneiro['capacidade_carga'] == 0 ||
        empty($perfil_caminhoneiro['numero_cnh'])) {
        $mostrar_modal_completar_perfil = true;
    }
} catch (Exception $e) {
    $erro = "Erro ao verificar/criar perfil: " . $e->getMessage();
}

// Buscar informações do caminhoneiro
try {
    // Buscar informações do usuário
    $sql_user = "SELECT id, nome, email, telefone, tipo_usuario, foto_perfil, data_registro, status
                FROM usuarios 
                WHERE id = :id";
    $stmt_user = $conn->prepare($sql_user);
    $stmt_user->execute([':id' => $user_id]);
    $usuario = $stmt_user->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        throw new Exception("Usuário não encontrado");
    }
    
    // Mesclar os dados do usuário com os dados do perfil
    if ($perfil_caminhoneiro) {
        foreach ($perfil_caminhoneiro as $key => $value) {
            $usuario[$key] = $value;
        }
    }
    
    // Buscar fotos do veículo
    $sql = "SELECT * FROM fotos_veiculo WHERE usuario_id = :usuario_id ORDER BY data_upload DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':usuario_id' => $user_id]);
    $fotos_veiculo = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Buscar documentos
    $sql = "SELECT * FROM documentos 
            WHERE usuario_id = :usuario_id 
            AND tipo_documento IN ('cnh', 'bi', 'outros')";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':usuario_id' => $user_id]);
    $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Verificar se existe um perfil de caminhoneiro
    if (!$usuario) {
        $erro = "Perfil não encontrado.";
    }
} catch (PDOException $e) {
    $erro = "Erro ao buscar informações do perfil: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meu Perfil - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/perfil.css">
    <style>
        /* Estilos para corrigir problemas de exibição das abas */
        .tab-pane.active {
            display: block !important;
        }
        .tab-pane {
            display: none;
        }
        .nav-tabs .nav-link.active {
            color: #495057;
            background-color: #fff;
            border-color: #dee2e6 #dee2e6 #fff;
        }
    </style>
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container perfil-page mt-4">
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php
                switch ($_GET['success']) {
                    case 1: echo "Perfil atualizado com sucesso!"; break;
                    case 2: echo "Disponibilidade atualizada com sucesso!"; break;
                    case 3: echo "Foto do veículo adicionada com sucesso!"; break;
                }
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($_GET['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($erro)): ?>
            <div class="alert alert-danger"><?php echo $erro; ?></div>
        <?php else: ?>

        <div class="perfil-hero">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div class="avatar-wrap">
                    <?php if (!empty($usuario['foto_perfil'])): ?>
                        <img src="<?php echo upload_url('perfil', $usuario['foto_perfil']); ?>" alt="Foto" onerror="this.style.display='none'">
                    <?php else: ?>
                        <i class="bi bi-person-fill fs-1"></i>
                    <?php endif; ?>
                </div>
                <div class="flex-fill">
                    <h1 class="h4 mb-1"><?php echo htmlspecialchars($usuario['nome'] ?? ''); ?></h1>
                    <p class="mb-0 opacity-75 small">Motorista · <?php echo htmlspecialchars($usuario['email'] ?? ''); ?></p>
                </div>
                <div class="perfil-actions">
                    <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#editarPerfilModal">
                        <i class="bi bi-pencil"></i> Editar perfil
                    </button>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-4">
                <div class="perfil-card">
                    <div class="card-head"><span><i class="bi bi-circle-fill me-2"></i>Status</span></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="perfil-kv"><div class="label">Disponibilidade</div>
                                <div class="value">
                                    <span class="badge bg-<?php echo ($usuario['disponibilidade'] ?? '') === 'disponivel' ? 'success' : 'warning'; ?>" data-status>
                                        <?php echo ucfirst($usuario['disponibilidade'] ?? 'indisponivel'); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php
                        $esta_disponivel = ($usuario['disponibilidade'] ?? 'indisponivel') === 'disponivel';
                        $missaoActivaPerfil = motorista_missao_ativa($conn, (int)$user_id);
                        $bloqueiaIndisponivel = $missaoActivaPerfil !== null;
                        ?>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="switchDisponibilidade"
                                   <?php echo $esta_disponivel ? 'checked' : ''; ?>
                                   <?php echo $bloqueiaIndisponivel ? 'disabled' : ''; ?>>
                            <label class="form-check-label small" for="switchDisponibilidade">
                                <?php
                                if ($bloqueiaIndisponivel) {
                                    echo 'Ocupado em missão (não pode ficar indisponível)';
                                } else {
                                    echo $esta_disponivel ? 'Disponível para missões' : 'Indisponível';
                                }
                                ?>
                            </label>
                        </div>
                        <?php if ($bloqueiaIndisponivel): ?>
                            <div class="form-text text-warning mt-1">
                                Missão activa: <?php echo htmlspecialchars($missaoActivaPerfil['titulo'] ?? '#' . $missaoActivaPerfil['id']); ?>.
                                Conclua a entrega antes de ficar indisponível.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="perfil-card">
                    <div class="card-head"><span><i class="bi bi-truck me-2"></i>Veículo</span></div>
                    <div class="card-body">
                        <div class="perfil-kv"><div class="label">Tipo</div><div class="value"><?php echo htmlspecialchars($usuario['tipo_veiculo'] ?? '—'); ?></div></div>
                        <div class="perfil-kv"><div class="label">Placa</div><div class="value"><?php echo htmlspecialchars($usuario['placa_veiculo'] ?? '—'); ?></div></div>
                        <div class="perfil-kv"><div class="label">Capacidade</div><div class="value"><?php echo !empty($usuario['capacidade_carga']) ? number_format((float)$usuario['capacidade_carga'], 0, ',', '.') . ' kg' : '—'; ?></div></div>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="perfil-card">
                    <div class="card-head p-0 border-0 bg-transparent">
                                    <ul class="nav nav-tabs card-header-tabs" id="myTab" role="tablist">
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link active" id="dados-pessoais-tab" data-bs-toggle="tab" data-bs-target="#dados-pessoais" type="button" role="tab" aria-controls="dados-pessoais" aria-selected="true">
                                                Perfil Completo
                                            </button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" id="veiculo-tab" data-bs-toggle="tab" data-bs-target="#veiculo" type="button" role="tab" aria-controls="veiculo" aria-selected="false">
                                                Veículo
                                            </button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" id="documentos-tab" data-bs-toggle="tab" data-bs-target="#documentos" type="button" role="tab" aria-controls="documentos" aria-selected="false">
                                                Documentos
                                            </button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" id="avaliacoes-tab" data-bs-toggle="tab" data-bs-target="#avaliacoes" type="button" role="tab" aria-controls="avaliacoes" aria-selected="false">
                                                Avaliações
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                                
                                <div class="card-body">
                                    <div class="tab-content" id="myTabContent">
                                        <!-- Dados Pessoais -->
                                        <div class="tab-pane fade show active" id="dados-pessoais" role="tabpanel" aria-labelledby="dados-pessoais-tab">
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <p class="text-muted mb-1">Nome</p>
                                                    <p class="fw-bold"><?php echo htmlspecialchars($usuario['nome'] ?? ''); ?></p>
                                                </div>
                                                <div class="col-md-6">
                                                    <p class="text-muted mb-1">E-mail</p>
                                                    <p class="fw-bold"><?php echo htmlspecialchars($usuario['email'] ?? ''); ?></p>
                                                </div>
                                            </div>
                                            
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <p class="text-muted mb-1">Telefone</p>
                                                    <p class="fw-bold"><?php echo htmlspecialchars($usuario['telefone'] ?? ''); ?></p>
                                                </div>
                                                <div class="col-md-6">
                                                    <p class="text-muted mb-1">Data de Registro</p>
                                                    <p class="fw-bold">
                                                        <?php echo isset($usuario['data_registro']) ? date('d/m/Y', strtotime($usuario['data_registro'])) : ''; ?>
                                                    </p>
                                                </div>
                                            </div>
                                            
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <p class="text-muted mb-1">Status da Conta</p>
                                                    <p class="fw-bold">
                                                        <span class="badge bg-<?php 
                                                            echo isset($usuario['status']) && $usuario['status'] == 'ativo' ? 'success' : 
                                                                (isset($usuario['status']) && $usuario['status'] == 'pendente' ? 'warning' : 'danger'); 
                                                        ?>">
                                                            <?php echo isset($usuario['status']) ? ucfirst($usuario['status']) : ''; ?>
                                                        </span>
                                                    </p>
                                                </div>
                                                <div class="col-md-6">
                                                    <p class="text-muted mb-1">Disponibilidade</p>
                                                    <p class="fw-bold">
                                                        <span class="badge bg-<?php 
                                                            $status_color = match($usuario['disponibilidade'] ?? 'indisponivel') {
                                                                'disponivel' => 'success',
                                                                'ocupado' => 'warning',
                                                                'manutencao' => 'danger',
                                                                default => 'secondary'
                                                            };
                                                            echo $status_color;
                                                        ?>">
                                                            <?php echo ucfirst($usuario['disponibilidade'] ?? 'Indisponível'); ?>
                                                        </span>
                                                    </p>
                                                </div>
                                            </div>
                                            
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <p class="text-muted mb-1">Total de Entregas</p>
                                                    <p class="fw-bold"><?php echo number_format($usuario['total_entregas'] ?? 0); ?></p>
                                                </div>
                                                <div class="col-md-6">
                                                    <p class="text-muted mb-1">Avaliação Média</p>
                                                    <p class="fw-bold">
                                                        <?php 
                                                            $rating = isset($usuario['avaliacao_media']) ? round($usuario['avaliacao_media']) : 0;
                                                            for ($i = 1; $i <= 5; $i++) {
                                                                echo '<i class="bi ' . ($i <= $rating ? 'bi-star-fill' : 'bi-star') . ' text-warning"></i> ';
                                                            }
                                                            echo ' ' . (isset($usuario['avaliacao_media']) ? number_format($usuario['avaliacao_media'], 1) : '0.0');
                                                        ?>
                                                    </p>
                                                </div>
                                            </div>
                                            
                                            <hr>
                                            <h5 class="mb-3">Informações do Veículo</h5>
                                            
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <p class="text-muted mb-1">Tipo de Veículo</p>
                                                    <p class="fw-bold"><?php 
                                                        echo isset($usuario['tipo_veiculo']) && !empty($usuario['tipo_veiculo']) && $usuario['tipo_veiculo'] != 'Não informado' 
                                                            ? htmlspecialchars($usuario['tipo_veiculo']) 
                                                            : 'Não informado';
                                                    ?></p>
                                                </div>
                                                <div class="col-md-6">
                                                    <p class="text-muted mb-1">Placa</p>
                                                    <p class="fw-bold"><?php 
                                                        echo isset($usuario['placa_veiculo']) && !empty($usuario['placa_veiculo']) && $usuario['placa_veiculo'] != 'Não informado'
                                                            ? htmlspecialchars($usuario['placa_veiculo']) 
                                                            : 'Não informado';
                                                    ?></p>
                                                </div>
                                            </div>
                                            
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <p class="text-muted mb-1">Capacidade de Carga</p>
                                                    <p class="fw-bold"><?php 
                                                        echo isset($usuario['capacidade_carga']) && !empty($usuario['capacidade_carga']) && $usuario['capacidade_carga'] > 0
                                                            ? number_format($usuario['capacidade_carga'], 2, ',', '.') . ' kg' 
                                                            : 'Não informado'; 
                                                    ?></p>
                                                </div>
                                                <div class="col-md-6">
                                                    <p class="text-muted mb-1">Descrição do Veículo</p>
                                                    <p class="fw-bold"><?php 
                                                        echo isset($usuario['descricao_veiculo']) && !empty($usuario['descricao_veiculo']) && $usuario['descricao_veiculo'] != 'Não informado'
                                                            ? htmlspecialchars($usuario['descricao_veiculo'])
                                                            : 'Não informado';
                                                    ?></p>
                                                </div>
                                            </div>
                                            
                                            <hr>
                                            <h5 class="mb-3">Documentos</h5>
                                            
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <p class="text-muted mb-1">Número da CNH</p>
                                                    <p class="fw-bold"><?php echo htmlspecialchars($usuario['numero_cnh'] ?? 'Não informado'); ?></p>
                                                </div>
                                                <div class="col-md-6">
                                                    <p class="text-muted mb-1">Validade da CNH</p>
                                                    <p class="fw-bold">
                                                        <?php echo isset($usuario['validade_cnh']) && $usuario['validade_cnh'] != '0000-00-00' ? date('d/m/Y', strtotime($usuario['validade_cnh'])) : 'Não informado'; ?>
                                                    </p>
                                                </div>
                                            </div>
                                            
                                            <?php if (isset($mostrar_modal_completar_perfil) && $mostrar_modal_completar_perfil): ?>
                                            <div class="alert alert-warning mb-4">
                                                <i class="bi bi-exclamation-triangle"></i> 
                                                Algumas informações do seu perfil estão incompletas.
                                                <button type="button" class="btn btn-sm btn-warning ms-2" data-bs-toggle="modal" data-bs-target="#completarPerfilModal">
                                                    Completar Perfil
                                                </button>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Aba Veículo -->
                                        <div class="tab-pane fade" id="veiculo" role="tabpanel" aria-labelledby="veiculo-tab">
                                            <div class="row mb-3">
                                                <div class="col-12">
                                                    <h5 class="mb-3">
                                                        Última Localização
                                                        <small class="text-muted">(Atualização em tempo real)</small>
                                                    </h5>
                                                    <div class="row">
                                                        <div class="col-md-4">
                                                            <p class="text-muted mb-1">Latitude</p>
                                                            <p class="fw-bold" id="latitude">
                                                                <?php echo isset($usuario['ultima_localizacao_lat']) ?
                                                                    number_format($usuario['ultima_localizacao_lat'], 6) :
                                                                    'Aguardando...'; ?>
                                                            </p>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <p class="text-muted mb-1">Longitude</p>
                                                            <p class="fw-bold" id="longitude">
                                                                <?php echo isset($usuario['ultima_localizacao_lng']) ?
                                                                    number_format($usuario['ultima_localizacao_lng'], 6) :
                                                                    'Aguardando...'; ?>
                                                            </p>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <p class="text-muted mb-1">Última Atualização</p>
                                                            <p class="fw-bold" id="ultima_atualizacao">
                                                                <?php echo isset($usuario['ultima_atualizacao_local']) ?
                                                                    date('d/m/Y H:i:s', strtotime($usuario['ultima_atualizacao_local'])) :
                                                                    'Aguardando...'; ?>
                                                            </p>
                                                        </div>
                                                    </div>
                                                    <div class="alert alert-info mt-3">
                                                        <i class="bi bi-info-circle"></i>
                                                        Sua localização está sendo atualizada automaticamente para melhor gerenciamento das entregas.
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="mt-4">
                                                <h5>Fotos do Veículo</h5>
                                                <div class="row">
                                                    <?php if (empty($fotos_veiculo)): ?>
                                                        <div class="col text-center">
                                                            <p class="text-muted">Nenhuma foto cadastrada.</p>
                                                        </div>
                                                    <?php else: ?>
                                                        <?php foreach ($fotos_veiculo as $foto): ?>
                                                            <div class="col-md-4 mb-3">
                                                                <div class="card">
                                                                    <img src="<?php echo upload_url('veiculos', $foto['nome_arquivo']); ?>" 
                                                                         class="card-img-top" alt="Foto do Veículo"
                                                                         style="height: 200px; object-fit: cover;">
                                                                    <div class="card-body">
                                                                        <p class="card-text small text-muted">
                                                                            <?php echo date('d/m/Y H:i', strtotime($foto['data_upload'])); ?>
                                                                        </p>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="mt-3">
                                                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#uploadFotoModal">
                                                        <i class="bi bi-camera"></i> Adicionar Fotos
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Documentos -->
                                        <div class="tab-pane fade" id="documentos" role="tabpanel" aria-labelledby="documentos-tab">
                                            <div class="mt-4">
                                                <h5>Documentos Cadastrados</h5>
                                                <div class="row">
                                                    <?php if (empty($documentos)): ?>
                                                    <div class="col text-center">
                                                        <p class="text-muted">Nenhum documento cadastrado.</p>
                                                        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#uploadDocumentoModal">
                                                            <i class="bi bi-file-earmark-plus"></i> Adicionar Documentos
                                                        </button>
                                                    </div>
                                                    <?php else: ?>
                                                    <div class="table-responsive">
                                                        <table class="table table-striped">
                                                            <thead>
                                                                <tr>
                                                                    <th>Tipo</th>
                                                                    <th>Arquivo</th>
                                                                    <th>Data de Upload</th>
                                                                    <th>Status</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($documentos as $documento): ?>
                                                                <tr>
                                                                    <td><?php echo ucfirst($documento['tipo_documento']); ?></td>
                                                                    <td>
                                                                        <a href="<?php echo documento_view_url((int)$documento['id']); ?>" target="_blank">
                                                                            <?php echo htmlspecialchars($documento['nome_arquivo']); ?>
                                                                        </a>
                                                                    </td>
                                                                    <td><?php echo date('d/m/Y', strtotime($documento['data_upload'])); ?></td>
                                                                    <td>
                                                                        <span class="badge bg-<?php 
                                                                            echo $documento['status'] == 'aprovado' ? 'success' : 
                                                                                ($documento['status'] == 'rejeitado' ? 'danger' : 'warning'); 
                                                                        ?>">
                                                                            <?php echo ucfirst($documento['status']); ?>
                                                                        </span>
                                                                    </td>
                                                                </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                    <div class="mt-3">
                                                        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#uploadDocumentoModal">
                                                            <i class="bi bi-file-earmark-plus"></i> Adicionar Novo Documento
                                                        </button>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Avaliações -->
                                        <div class="tab-pane fade" id="avaliacoes" role="tabpanel" aria-labelledby="avaliacoes-tab">
                                            <div class="row mb-4">
                                                <div class="col text-center">
                                                    <div class="display-4 fw-bold text-warning">
                                                        <?php echo isset($usuario['avaliacao_media']) ? number_format($usuario['avaliacao_media'], 1) : '0.0'; ?>
                                                    </div>
                                                    <div class="mb-2">
                                                        <?php 
                                                            $rating = isset($usuario['avaliacao_media']) ? round($usuario['avaliacao_media']) : 0;
                                                            for ($i = 1; $i <= 5; $i++) {
                                                                echo '<i class="bi ' . ($i <= $rating ? 'bi-star-fill' : 'bi-star') . ' text-warning"></i> ';
                                                            }
                                                        ?>
                                                    </div>
                                                    <p class="text-muted">
                                                        Baseado em <?php echo isset($usuario['total_entregas']) ? $usuario['total_entregas'] : '0'; ?> entregas
                                                    </p>
                                                </div>
                                            </div>
                                            
                                            <hr>
                                            
                                            <!-- Aqui viriam as avaliações individuais -->
                                            <div class="text-center py-4">
                                                <p class="text-muted">Ainda não há avaliações.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Função para atualizar localização
        function atualizarLocalizacao(position) {
            const latitude = position.coords.latitude;
            const longitude = position.coords.longitude;
            
            console.log('Nova localização:', latitude, longitude);
            
            // Enviar para o servidor
            fetch('atualizar-localizacao-ajax.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `latitude=${latitude}&longitude=${longitude}`
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Erro na resposta do servidor: ' + response.status);
                }
                return response.text();
            })
            .then(text => {
                console.log('Resposta do servidor:', text);
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Erro ao analisar JSON:', e);
                    console.error('Texto recebido:', text);
                    throw new Error('Resposta inválida do servidor');
                }
            })
            .then(data => {
                if (data.success) {
                    // Atualizar os elementos na página
                    document.getElementById('latitude').textContent = latitude.toFixed(6);
                    document.getElementById('longitude').textContent = longitude.toFixed(6);
                    document.getElementById('ultima_atualizacao').textContent = new Date().toLocaleString();
                    
                    console.log('Localização atualizada com sucesso');
                } else {
                    console.error('Erro ao atualizar localização:', data.message);
                }
            })
            .catch(error => {
                console.error('Erro ao enviar localização:', error);
            });
        }

        function handleLocationError(error) {
            console.error('Erro ao obter localização:', error);
            let mensagem = '';
            switch(error.code) {
                case error.PERMISSION_DENIED:
                    mensagem = "Você precisa permitir o acesso à sua localização.";
                    break;
                case error.POSITION_UNAVAILABLE:
                    mensagem = "Informação de localização indisponível.";
                    break;
                case error.TIMEOUT:
                    mensagem = "Tempo esgotado ao tentar obter localização.";
                    break;
                default:
                    mensagem = "Erro desconhecido ao obter localização.";
            }
            console.log(mensagem);
        }

        // Iniciar rastreamento de localização
        function iniciarRastreamento() {
            if ("geolocation" in navigator) {
                // Obter localização imediatamente
                navigator.geolocation.getCurrentPosition(atualizarLocalizacao, handleLocationError);
                
                // Configurar atualização periódica
                const watchId = navigator.geolocation.watchPosition(atualizarLocalizacao, handleLocationError, {
                    enableHighAccuracy: true,
                    timeout: 5000,
                    maximumAge: 0
                });
                
                // Armazenar o ID do watch para poder parar depois se necessário
                window.locationWatchId = watchId;
            } else {
                console.log("Seu navegador não suporta geolocalização.");
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            console.log("DOM carregado, inicializando...");
            
            // Inicializar as abas
            const triggerTabList = [].slice.call(document.querySelectorAll('.nav-tabs button'));
            triggerTabList.forEach(function(triggerEl) {
                const tabTrigger = new bootstrap.Tab(triggerEl);
                triggerEl.addEventListener('click', function(event) {
                    event.preventDefault();
                    tabTrigger.show();
                });
            });
            
            // Iniciar rastreamento de localização
            iniciarRastreamento();
            
            // Gerenciar o switch de disponibilidade
            const switchDisponibilidade = document.getElementById('switchDisponibilidade');
            if (switchDisponibilidade) {
                switchDisponibilidade.addEventListener('change', function(e) {
                    const novaDisponibilidade = this.checked ? 'disponivel' : 'indisponivel';
                    const statusLabel = document.querySelector('[data-status]');
                    
                    // Atualizar no servidor
                    fetch('atualizar-disponibilidade.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `disponibilidade=${novaDisponibilidade}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Atualizar a interface
                            if (this.checked) {
                                statusLabel.textContent = 'Disponível';
                                statusLabel.className = 'badge bg-success p-2';
                                this.nextElementSibling.textContent = 'Disponível para novas missões';
                            } else {
                                statusLabel.textContent = 'Indisponível';
                                statusLabel.className = 'badge bg-warning p-2';
                                this.nextElementSibling.textContent = 'Indisponível para novas missões';
                            }
                        } else {
                            alert(data.message || 'Não foi possível alterar a disponibilidade.');
                            this.checked = !this.checked;
                        }
                    })
                    .catch(error => {
                        console.error('Erro ao enviar disponibilidade:', error);
                        // Reverter o switch se houver erro
                        this.checked = !this.checked;
                    });
                });
            }
            
            // Inicializar modal se necessário
            if (document.getElementById('completarPerfilModal')) {
                const completarPerfilModal = new bootstrap.Modal(document.getElementById('completarPerfilModal'));
                completarPerfilModal.show();
            }
        });
    </script>
    
    <!-- Modal de editar perfil -->
    <div class="modal fade perfil-modal" id="editarPerfilModal" tabindex="-1" aria-labelledby="editarPerfilModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editarPerfilModalLabel">Editar Perfil</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <form id="formEditarPerfil" method="post" action="atualizar-perfil.php" enctype="multipart/form-data">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="nome" class="form-label">Nome</label>
                                <input type="text" class="form-control" id="nome" name="nome" value="<?php echo htmlspecialchars($usuario['nome'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="telefone" class="form-label">Telefone</label>
                                <input type="text" class="form-control" id="telefone" name="telefone" value="<?php echo htmlspecialchars($usuario['telefone'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="email" class="form-label">E-mail</label>
                            <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($usuario['email'] ?? ''); ?>">
                        </div>
                        
                        <hr>
                        <h5>Informações do Veículo</h5>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="tipo_veiculo_edit" class="form-label">Tipo de Veículo</label>
                                <input type="text" class="form-control" id="tipo_veiculo_edit" name="tipo_veiculo" value="<?php echo htmlspecialchars($usuario['tipo_veiculo'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="placa_veiculo" class="form-label">Placa</label>
                                <input type="text" class="form-control" id="placa_veiculo" name="placa_veiculo" value="<?php echo htmlspecialchars($usuario['placa_veiculo'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="capacidade_carga" class="form-label">Capacidade de Carga (kg)</label>
                            <input type="number" step="0.01" class="form-control" id="capacidade_carga" name="capacidade_carga" value="<?php echo htmlspecialchars($usuario['capacidade_carga'] ?? ''); ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label for="descricao_veiculo" class="form-label">Descrição do Veículo</label>
                            <textarea class="form-control" id="descricao_veiculo" name="descricao_veiculo" rows="3"><?php echo htmlspecialchars($usuario['descricao_veiculo'] ?? ''); ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="disponibilidade" class="form-label">Status de Disponibilidade</label>
                            <select class="form-control" id="disponibilidade" name="disponibilidade"
                                <?php echo !empty($bloqueiaIndisponivel) ? 'disabled' : ''; ?>>
                                <option value="disponivel" <?php echo ($usuario['disponibilidade'] ?? '') === 'disponivel' ? 'selected' : ''; ?>>Disponível</option>
                                <option value="indisponivel" <?php echo ($usuario['disponibilidade'] ?? '') === 'indisponivel' ? 'selected' : ''; ?>
                                    <?php echo !empty($bloqueiaIndisponivel) ? 'disabled' : ''; ?>>Indisponível</option>
                                <option value="ocupado" <?php echo ($usuario['disponibilidade'] ?? '') === 'ocupado' || !empty($bloqueiaIndisponivel) ? 'selected' : ''; ?>>Ocupado</option>
                                <option value="manutencao" <?php echo ($usuario['disponibilidade'] ?? '') === 'manutencao' ? 'selected' : ''; ?>
                                    <?php echo !empty($bloqueiaIndisponivel) ? 'disabled' : ''; ?>>Em Manutenção</option>
                            </select>
                            <?php if (!empty($bloqueiaIndisponivel)): ?>
                                <input type="hidden" name="disponibilidade" value="ocupado">
                                <div class="form-text text-warning">Com missão activa só é permitido o estado Ocupado.</div>
                            <?php endif; ?>
                        </div>
                        
                        <hr>
                        <h5>Documentos</h5>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="numero_cnh" class="form-label">Número da CNH</label>
                                <input type="text" class="form-control" id="numero_cnh" name="numero_cnh" value="<?php echo htmlspecialchars($usuario['numero_cnh'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="validade_cnh" class="form-label">Validade da CNH</label>
                                <input type="date" class="form-control" id="validade_cnh" name="validade_cnh" value="<?php echo htmlspecialchars($usuario['validade_cnh'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="foto_perfil" class="form-label">Foto de Perfil</label>
                            <input type="file" class="form-control" id="foto_perfil" name="foto_perfil" accept="image/*">
                        </div>
                        
                        <hr>
                        <h5>Segurança</h5>
                        
                        <div class="mb-3">
                            <label for="senha_atual" class="form-label">Senha Atual</label>
                            <input type="password" class="form-control" id="senha_atual" name="senha_atual">
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="nova_senha" class="form-label">Nova Senha</label>
                                <input type="password" class="form-control" id="nova_senha" name="nova_senha">
                            </div>
                            <div class="col-md-6">
                                <label for="confirmar_senha" class="form-label">Confirmar Nova Senha</label>
                                <input type="password" class="form-control" id="confirmar_senha" name="confirmar_senha">
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" form="formEditarPerfil" class="btn btn-primary">Salvar Alterações</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de upload de foto do veículo -->
    <div class="modal fade" id="uploadFotoModal" tabindex="-1" aria-labelledby="uploadFotoModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="uploadFotoModalLabel">Adicionar Foto do Veículo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <form action="upload-foto-veiculo.php" method="post" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label for="foto_veiculo" class="form-label">Selecione a foto</label>
                            <input type="file" class="form-control" id="foto_veiculo" name="foto_veiculo" accept="image/*" required>
                        </div>
                        <div class="mb-3">
                            <label for="tipo_veiculo" class="form-label">Tipo do Veículo</label>
                            <input type="text" class="form-control" id="tipo_veiculo" name="tipo_veiculo" value="<?php echo htmlspecialchars($usuario['tipo_veiculo'] ?? ''); ?>">
                        </div>
                        <div class="text-end">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Enviar Foto</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para completar perfil -->
    <?php if (isset($mostrar_modal_completar_perfil) && $mostrar_modal_completar_perfil): ?>
    <div class="modal fade perfil-modal" id="completarPerfilModal" tabindex="-1" aria-labelledby="completarPerfilModalLabel" data-bs-backdrop="static" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="completarPerfilModalLabel">Complete seu perfil</h5>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> Para utilizar todos os recursos do sistema, por favor complete as informações do seu veículo e documentos.
                    </div>
                    
                    <form id="formCompletarPerfil" method="post" action="atualizar-perfil.php" enctype="multipart/form-data">
                        <h5 class="mb-3">Informações do Veículo</h5>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="tipo_veiculo_completar" class="form-label">Tipo de Veículo *</label>
                                <input type="text" class="form-control" id="tipo_veiculo_completar" name="tipo_veiculo" value="<?php echo htmlspecialchars($usuario['tipo_veiculo'] ?? ''); ?>" required>
                                <div class="form-text">Ex: Caminhão, Carreta, Van, etc.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="placa_veiculo_completar" class="form-label">Placa do Veículo *</label>
                                <input type="text" class="form-control" id="placa_veiculo_completar" name="placa_veiculo" value="<?php echo htmlspecialchars($usuario['placa_veiculo'] ?? ''); ?>" required>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="capacidade_carga_completar" class="form-label">Capacidade de Carga (kg) *</label>
                                <input type="number" step="0.01" class="form-control" id="capacidade_carga_completar" name="capacidade_carga" value="<?php echo htmlspecialchars($usuario['capacidade_carga'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="descricao_veiculo_completar" class="form-label">Descrição do Veículo</label>
                                <textarea class="form-control" id="descricao_veiculo_completar" name="descricao_veiculo" rows="2"><?php echo htmlspecialchars($usuario['descricao_veiculo'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        
                        <hr>
                        <h5 class="mb-3">Documentos</h5>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="numero_cnh_completar" class="form-label">Número da CNH *</label>
                                <input type="text" class="form-control" id="numero_cnh_completar" name="numero_cnh" value="<?php echo htmlspecialchars($usuario['numero_cnh'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="validade_cnh_completar" class="form-label">Validade da CNH *</label>
                                <input type="date" class="form-control" id="validade_cnh_completar" name="validade_cnh" value="<?php echo htmlspecialchars($usuario['validade_cnh'] ?? ''); ?>" required>
                            </div>
                        </div>
                        
                        <!-- Campos ocultos para manter os outros valores -->
                        <input type="hidden" name="nome" value="<?php echo htmlspecialchars($usuario['nome'] ?? ''); ?>">
                        <input type="hidden" name="email" value="<?php echo htmlspecialchars($usuario['email'] ?? ''); ?>">
                        <input type="hidden" name="telefone" value="<?php echo htmlspecialchars($usuario['telefone'] ?? ''); ?>">
                        <input type="hidden" name="disponibilidade" value="<?php echo htmlspecialchars($usuario['disponibilidade'] ?? 'indisponivel'); ?>">
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="submit" form="formCompletarPerfil" class="btn btn-primary">Salvar Informações</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</body>
</html> 