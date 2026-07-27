<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');

require_role(['caminhoneiro'], '../login.php');

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Tipos de documentos permitidos
$tiposDocumentos = [
    'bi' => 'Bilhete de Identidade',
    'cnh' => 'Carta de Condução',
    'alvara' => 'Licença do Camião',
    'outros' => 'Outros Documentos'
];

// Extensões de arquivo permitidas
$extensoesPermitidas = ['jpg', 'jpeg', 'png', 'pdf'];
$tamanhoMaximo = 5 * 1024 * 1024; // 5MB

// Verificar se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipoDocumento = isset($_POST['tipo_documento']) ? $_POST['tipo_documento'] : '';
    
    // Verificar se o tipo de documento é válido
    if (!array_key_exists($tipoDocumento, $tiposDocumentos)) {
        $error = "Tipo de documento inválido.";
    } elseif (!isset($_FILES['arquivo_documento']) || $_FILES['arquivo_documento']['error'] == UPLOAD_ERR_NO_FILE) {
        $error = "Por favor, selecione um arquivo.";
    } else {
        $arquivo = $_FILES['arquivo_documento'];
        
        // Verificar erros no upload
        if ($arquivo['error'] !== UPLOAD_ERR_OK) {
            switch ($arquivo['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $error = "O arquivo é muito grande.";
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $error = "O upload foi interrompido.";
                    break;
                default:
                    $error = "Ocorreu um erro no upload.";
                    break;
            }
        } else {
            // Verificar tamanho do arquivo
            if ($arquivo['size'] > $tamanhoMaximo) {
                $error = "O arquivo excede o tamanho máximo permitido (5MB).";
            } else {
                // Verificar extensão do arquivo
                $nomeArquivo = $arquivo['name'];
                $extensao = strtolower(pathinfo($nomeArquivo, PATHINFO_EXTENSION));
                
                if (!in_array($extensao, $extensoesPermitidas)) {
                    $error = "Extensão de arquivo não permitida. Apenas JPG, JPEG, PNG e PDF são aceitos.";
                } else {
                    // Criar diretório se não existir
                    $diretorioUpload = '../../uploads/documentos/';
                    if (!file_exists($diretorioUpload)) {
                        mkdir($diretorioUpload, 0777, true);
                    }
                    
                    // Gerar nome único para o arquivo
                    $novoNomeArquivo = $user_id . '_' . $tipoDocumento . '_' . time() . '.' . $extensao;
                    $caminhoCompleto = $diretorioUpload . $novoNomeArquivo;
                    
                    // Mover arquivo para o diretório de upload
                    if (move_uploaded_file($arquivo['tmp_name'], $caminhoCompleto)) {
                        try {
                            // Verificar se já existe um documento do mesmo tipo
                            $sql = "SELECT id, status, bloqueado FROM documentos 
                                    WHERE usuario_id = :usuario_id AND tipo_documento = :tipo_documento";
                            $stmt = $conn->prepare($sql);
                            $stmt->execute([
                                ':usuario_id' => $user_id,
                                ':tipo_documento' => $tipoDocumento
                            ]);
                            
                            $doc_existente = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($doc_existente) {
                                if (!empty($doc_existente['bloqueado']) && ($doc_existente['status'] ?? '') === 'aprovado') {
                                    $success = '';
                                    $error = "Este documento já foi aprovado e não pode ser substituído.";
                                } else {
                                    // Atualizar documento existente
                                    $sql = "UPDATE documentos 
                                            SET nome_arquivo = :nome_arquivo, 
                                                caminho_arquivo = :caminho_arquivo, 
                                                data_upload = NOW(),
                                                status = 'pendente'
                                            WHERE usuario_id = :usuario_id AND tipo_documento = :tipo_documento";
                                    $stmt = $conn->prepare($sql);
                                    $stmt->execute([
                                        ':usuario_id' => $user_id,
                                        ':tipo_documento' => $tipoDocumento,
                                        ':nome_arquivo' => $nomeArquivo,
                                        ':caminho_arquivo' => $novoNomeArquivo
                                    ]);
                                    
                                    $success = "Documento enviado com sucesso! Aguarde a verificação pela administração.";
                                }
                            } else {
                                // Inserir novo documento
                                $sql = "INSERT INTO documentos 
                                        (usuario_id, tipo_documento, nome_arquivo, caminho_arquivo, data_upload, status) 
                                        VALUES 
                                        (:usuario_id, :tipo_documento, :nome_arquivo, :caminho_arquivo, NOW(), 'pendente')";
                                $stmt = $conn->prepare($sql);
                                $stmt->execute([
                                    ':usuario_id' => $user_id,
                                    ':tipo_documento' => $tipoDocumento,
                                    ':nome_arquivo' => $nomeArquivo,
                                    ':caminho_arquivo' => $novoNomeArquivo
                                ]);
                                
                                $success = "Documento enviado com sucesso! Aguarde a verificação pela administração.";
                            }

                            if ($error === '' && $success !== '') {
                                require_once __DIR__ . '/../../includes/kyc-helpers.php';
                                require_once __DIR__ . '/../../includes/notificacoes-helpers.php';
                                kyc_apos_envio_documento($conn, (int)$user_id);
                            }
                            
                            // Adicionar notificação para os administradores
                            $sql = "INSERT INTO notificacoes 
                                    (usuario_id, tipo, titulo, mensagem) 
                                    SELECT id, 'sistema', 'Novo documento enviado', 
                                    'Um caminhoneiro enviou um novo documento para verificação.' 
                                    FROM usuarios WHERE tipo_usuario = 'admin'";
                            $conn->query($sql);
                            
                            // Adicionar notificação para o caminhoneiro
                            $sql = "INSERT INTO notificacoes 
                                    (usuario_id, tipo, titulo, mensagem) 
                                    VALUES (:usuario_id, 'sistema', 'Documento enviado', 
                                    'Seu documento foi enviado e está aguardando verificação.')";
                            $stmt = $conn->prepare($sql);
                            $stmt->execute([':usuario_id' => $user_id]);
                            
                        } catch (PDOException $e) {
                            $error = "Erro ao salvar dados do documento: " . $e->getMessage();
                        }
                    } else {
                        $error = "Falha ao mover o arquivo. Tente novamente.";
                    }
                }
            }
        }
    }
}

// Buscar documentos do usuário
try {
    $sql = "SELECT * FROM documentos 
            WHERE usuario_id = :usuario_id ORDER BY data_upload DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':usuario_id' => $user_id]);
    $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Erro ao carregar documentos: " . $e->getMessage();
    $documentos = [];
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload de Documentos - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <style>
        .document-card {
            transition: transform 0.2s;
        }
        .document-card:hover {
            transform: translateY(-5px);
        }
        .document-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        .pdf-icon { color: #dc3545; }
        .img-icon { color: #0d6efd; }
    </style>
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container mt-4">
        <div class="row">
            <div class="col-lg-12">
                <div class="card fade-in">
                    <div class="card-body">
                        <h2 class="card-title">
                            <i class="bi bi-file-earmark-text"></i> Upload de Documentos
                        </h2>
                        
                        <div class="alert alert-info mb-4">
                            <i class="bi bi-info-circle-fill"></i>
                            Para garantir a segurança e confiabilidade da plataforma, solicitamos que você faça 
                            o upload dos seus documentos. Eles serão verificados pela nossa equipe.
                        </div>
                        
                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <?php echo $success; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <?php echo $error; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>
                        
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h5 class="card-title">Enviar Novo Documento</h5>
                                        <form method="POST" enctype="multipart/form-data">
                                            <div class="mb-3">
                                                <label for="tipo_documento" class="form-label">Tipo de Documento:</label>
                                                <select class="form-select" id="tipo_documento" name="tipo_documento" required>
                                                    <option value="">Selecione um tipo</option>
                                                    <?php foreach ($tiposDocumentos as $valor => $nome): ?>
                                                        <option value="<?php echo $valor; ?>"><?php echo $nome; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="arquivo_documento" class="form-label">Arquivo:</label>
                                                <input type="file" class="form-control" id="arquivo_documento" name="arquivo_documento" required>
                                                <div class="form-text">
                                                    Formatos aceitos: JPG, PNG, PDF. Tamanho máximo: 5MB.
                                                </div>
                                            </div>
                                            
                                            <button type="submit" class="btn btn-primary">
                                                <i class="bi bi-upload"></i> Enviar Documento
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <h5 class="card-title">Documentos Necessários</h5>
                                        <div class="list-group">
                                            <div class="list-group-item">
                                                <h6>Bilhete de Identidade (BI)</h6>
                                                <p class="text-muted mb-0">Cópia digital do seu documento de identidade válido</p>
                                            </div>
                                            <div class="list-group-item">
                                                <h6>Carta de Condução</h6>
                                                <p class="text-muted mb-0">Sua carteira de motorista/CNH válida</p>
                                            </div>
                                            <div class="list-group-item">
                                                <h6>Licença do Camião</h6>
                                                <p class="text-muted mb-0">Documento do veículo e licenças necessárias</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <h5 class="mb-3">Meus Documentos</h5>
                        
                        <?php if (empty($documentos)): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                                Você ainda não enviou nenhum documento. Para garantir a verificação do seu perfil, 
                                por favor, envie os documentos solicitados.
                            </div>
                        <?php else: ?>
                            <div class="row row-cols-1 row-cols-md-3 g-4">
                                <?php foreach ($documentos as $documento): ?>
                                    <div class="col">
                                        <div class="card h-100 document-card">
                                            <div class="card-body text-center">
                                                <?php 
                                                    $extensao = strtolower(pathinfo($documento['nome_arquivo'], PATHINFO_EXTENSION));
                                                    $iconClass = ($extensao === 'pdf') ? 'bi-file-earmark-pdf pdf-icon' : 'bi-file-earmark-image img-icon';
                                                ?>
                                                <div class="document-icon">
                                                    <i class="bi <?php echo $iconClass; ?>"></i>
                                                </div>
                                                <h5 class="card-title">
                                                    <?php echo $tiposDocumentos[$documento['tipo_documento']] ?? 'Documento'; ?>
                                                </h5>
                                                <p class="card-text text-muted">
                                                    Enviado em: <?php echo date('d/m/Y H:i', strtotime($documento['data_upload'])); ?>
                                                </p>
                                                <p>
                                                    <?php if ($documento['status'] === 'pendente'): ?>
                                                        <span class="badge bg-warning">Pendente de verificação</span>
                                                    <?php elseif ($documento['status'] === 'aprovado'): ?>
                                                        <span class="badge bg-success">Aprovado</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Rejeitado</span>
                                                    <?php endif; ?>
                                                </p>
                                                <a href="<?php echo documento_view_url((int)$documento['id']); ?>" 
                                                    class="btn btn-sm btn-outline-primary" target="_blank">
                                                    <i class="bi bi-eye"></i> Visualizar
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="mt-4">
                            <a href="<?php echo BASE_URL; ?>/pages/caminhoneiro/perfil.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left"></i> Voltar ao Perfil
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 