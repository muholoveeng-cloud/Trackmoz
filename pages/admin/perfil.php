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
$erro = '';
$sucesso = '';

// Função para processar upload de foto
function processarUploadFoto($arquivo, $pasta, $tipos_permitidos = ['jpg', 'jpeg', 'png']) {
    if ($arquivo['error'] != UPLOAD_ERR_OK) {
        throw new Exception("Erro no upload do arquivo: " . $arquivo['error']);
    }
    
    $info = pathinfo($arquivo['name']);
    $ext = strtolower($info['extension']);
    
    if (!in_array($ext, $tipos_permitidos)) {
        throw new Exception("Tipo de arquivo não permitido. Apenas " . implode(", ", $tipos_permitidos) . " são aceitos.");
    }
    
    if ($arquivo['size'] > 5 * 1024 * 1024) { // 5MB
        throw new Exception("Arquivo muito grande. Tamanho máximo: 5MB.");
    }
    
    // Criar diretório se não existir
    if (!file_exists("../../uploads/$pasta")) {
        mkdir("../../uploads/$pasta", 0777, true);
    }
    
    $nome_arquivo = uniqid() . '_' . $arquivo['name'];
    $caminho_completo = "../../uploads/$pasta/" . $nome_arquivo;
    
    if (!move_uploaded_file($arquivo['tmp_name'], $caminho_completo)) {
        throw new Exception("Falha ao mover o arquivo para o destino final.");
    }
    
    return $nome_arquivo;
}

// Buscar dados do usuário
$sql = "SELECT * FROM usuarios WHERE id = :id AND tipo_usuario = 'admin'";
$stmt = $conn->prepare($sql);
$stmt->execute([':id' => $_SESSION['user_id']]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    $erro = "Usuário não encontrado ou tipo incorreto.";
}

// Estatísticas do sistema
$estatisticas = [
    'total_usuarios' => 0,
    'total_caminhoneiros' => 0,
    'total_empresas' => 0,
    'total_missoes' => 0,
    'total_propostas' => 0,
    'documentos_pendentes' => 0
];

try {
    // Total de usuários
    $sql = "SELECT COUNT(*) FROM usuarios WHERE tipo_usuario != 'admin'";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $estatisticas['total_usuarios'] = $stmt->fetchColumn();
    
    // Total de caminhoneiros
    $sql = "SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'caminhoneiro'";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $estatisticas['total_caminhoneiros'] = $stmt->fetchColumn();
    
    // Total de empresas
    $sql = "SELECT COUNT(*) FROM usuarios WHERE tipo_usuario = 'empresa'";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $estatisticas['total_empresas'] = $stmt->fetchColumn();
    
    // Total de missões
    $sql = "SELECT COUNT(*) FROM missoes";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $estatisticas['total_missoes'] = $stmt->fetchColumn();
    
    // Total de propostas
    $sql = "SELECT COUNT(*) FROM propostas";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $estatisticas['total_propostas'] = $stmt->fetchColumn();
    
    // Documentos pendentes
    $sql = "SELECT COUNT(*) FROM documentos WHERE status = 'pendente'";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $estatisticas['documentos_pendentes'] = $stmt->fetchColumn();
} catch (PDOException $e) {
    $erro = "Erro ao carregar estatísticas: " . $e->getMessage();
}

// Processar atualização de perfil
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        // Processar upload de foto de perfil
        if (isset($_FILES['foto_perfil']) && $_FILES['foto_perfil']['error'] != UPLOAD_ERR_NO_FILE) {
            $foto_perfil = processarUploadFoto($_FILES['foto_perfil'], 'perfil');
            
            $sql = "UPDATE usuarios SET foto_perfil = :foto_perfil WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':foto_perfil' => $foto_perfil,
                ':id' => $_SESSION['user_id']
            ]);
        }
        
        // Atualizar informações do usuário
        $nome = $usuario['nome']; // Usar valor atual como padrão
        if (isset($_POST['nome']) && isset($_POST['email']) && isset($_POST['telefone'])) {
            $nome = htmlspecialchars($_POST['nome']);
            $email = htmlspecialchars($_POST['email']);
            $telefone = htmlspecialchars($_POST['telefone']);
            
            $sql = "UPDATE usuarios SET nome = :nome, email = :email, telefone = :telefone WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':nome' => $nome,
                ':email' => $email,
                ':telefone' => $telefone,
                ':id' => $_SESSION['user_id']
            ]);
        }
        
        $conn->commit();
        $sucesso = "Perfil atualizado com sucesso!";
        
        // Atualizar dados do usuário na sessão
        $_SESSION['user_name'] = $nome;
        
        // Recarregar dados do usuário
        $sql = "SELECT * FROM usuarios WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $conn->rollBack();
        $erro = "Erro ao atualizar perfil: " . $e->getMessage();
    }
}

// Buscar usuários recentes
$sql = "SELECT id, nome, email, tipo_usuario, status, data_registro FROM usuarios 
        WHERE tipo_usuario != 'admin' 
        ORDER BY data_registro DESC LIMIT 10";
$stmt = $conn->prepare($sql);
$stmt->execute();
$usuarios_recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar documentos pendentes
$sql = "SELECT d.*, u.nome, u.email, u.tipo_usuario 
        FROM documentos d 
        JOIN usuarios u ON d.usuario_id = u.id 
        WHERE d.status = 'pendente' 
        ORDER BY d.data_upload DESC LIMIT 10";
$stmt = $conn->prepare($sql);
$stmt->execute();
$documentos_pendentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil do Administrador - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css">
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container mt-4">
        <?php if ($erro): ?>
            <div class="alert alert-danger"><?php echo $erro; ?></div>
        <?php endif; ?>
        
        <?php if ($sucesso): ?>
            <div class="alert alert-success"><?php echo $sucesso; ?></div>
        <?php endif; ?>
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Perfil do Administrador</h1>
            <div class="btn-group">
                <a href="dashboard.php" class="btn btn-outline-primary">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a>
                <a href="usuarios.php" class="btn btn-outline-primary">
                    <i class="bi bi-people"></i> Usuários
                </a>
                <a href="documentos.php" class="btn btn-outline-primary">
                    <i class="bi bi-file-earmark-text"></i> Documentos
                </a>
                <a href="missoes.php" class="btn btn-outline-primary">
                    <i class="bi bi-truck"></i> Missões
                </a>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Foto de Perfil</h5>
                    </div>
                    <div class="card-body text-center">
                        <?php if (!empty($usuario['foto_perfil'])): ?>
                            <img src="../../uploads/perfil/<?php echo htmlspecialchars($usuario['foto_perfil']); ?>" 
                                 class="rounded-circle mb-3" style="width: 150px; height: 150px; object-fit: cover;">
                        <?php else: ?>
                            <div class="rounded-circle bg-light mb-3 d-flex align-items-center justify-content-center" 
                                 style="width: 150px; height: 150px; margin: 0 auto;">
                                <i class="bi bi-person-badge" style="font-size: 4rem;"></i>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="foto_perfil" class="form-label">Alterar Foto</label>
                                <input type="file" class="form-control" id="foto_perfil" name="foto_perfil" accept="image/*">
                            </div>
                            <button type="submit" class="btn btn-primary">Atualizar Foto</button>
                        </form>
                    </div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Informações Pessoais</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="nome" class="form-label">Nome Completo</label>
                                <input type="text" class="form-control" id="nome" name="nome" 
                                       value="<?php echo htmlspecialchars(isset($usuario['nome']) ? $usuario['nome'] : ''); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo htmlspecialchars(isset($usuario['email']) ? $usuario['email'] : ''); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="telefone" class="form-label">Telefone</label>
                                <input type="text" class="form-control" id="telefone" name="telefone" 
                                       value="<?php echo htmlspecialchars(isset($usuario['telefone']) ? $usuario['telefone'] : ''); ?>" required>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">Salvar Alterações</button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Estatísticas do Sistema</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Total de Usuários
                                <span class="badge bg-primary rounded-pill"><?php echo $estatisticas['total_usuarios']; ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Caminhoneiros
                                <span class="badge bg-primary rounded-pill"><?php echo $estatisticas['total_caminhoneiros']; ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Empresas
                                <span class="badge bg-primary rounded-pill"><?php echo $estatisticas['total_empresas']; ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Missões Publicadas
                                <span class="badge bg-primary rounded-pill"><?php echo $estatisticas['total_missoes']; ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Propostas Enviadas
                                <span class="badge bg-primary rounded-pill"><?php echo $estatisticas['total_propostas']; ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Documentos Pendentes
                                <span class="badge bg-danger rounded-pill"><?php echo $estatisticas['documentos_pendentes']; ?></span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Usuários Recentes</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($usuarios_recentes)): ?>
                            <p class="text-center">Nenhum usuário cadastrado.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Nome</th>
                                            <th>Email</th>
                                            <th>Tipo</th>
                                            <th>Status</th>
                                            <th>Data Cadastro</th>
                                            <th>Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($usuarios_recentes as $usr): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($usr['nome']); ?></td>
                                                <td><?php echo htmlspecialchars($usr['email']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo $usr['tipo_usuario'] == 'caminhoneiro' ? 'primary' : 'success'; 
                                                    ?>">
                                                        <?php echo ucfirst($usr['tipo_usuario']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo $usr['status'] == 'ativo' ? 'success' : 
                                                            ($usr['status'] == 'pendente' ? 'warning' : 'danger'); 
                                                    ?>">
                                                        <?php echo ucfirst($usr['status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('d/m/Y', strtotime($usr['data_registro'])); ?></td>
                                                <td>
                                                    <a href="<?php echo BASE_URL; ?>/pages/admin/ver-usuario.php?id=<?php echo $usr['id']; ?>" class="btn btn-sm btn-primary">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-end mt-3">
                                <a href="usuarios.php" class="btn btn-outline-primary">Ver Todos os Usuários</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Documentos Pendentes de Aprovação</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($documentos_pendentes)): ?>
                            <p class="text-center">Nenhum documento pendente de aprovação.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Usuário</th>
                                            <th>Tipo</th>
                                            <th>Arquivo</th>
                                            <th>Data Upload</th>
                                            <th>Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($documentos_pendentes as $doc): ?>
                                            <tr>
                                                <td>
                                                    <?php echo htmlspecialchars($doc['nome']); ?>
                                                    <br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($doc['email']); ?></small>
                                                </td>
                                                <td><?php echo ucfirst(str_replace('_', ' ', $doc['tipo_documento'])); ?></td>
                                                <td><?php echo htmlspecialchars($doc['nome_arquivo']); ?></td>
                                                <td><?php echo date('d/m/Y H:i', strtotime($doc['data_upload'])); ?></td>
                                                <td>
                                                    <div class="btn-group">
                                                        <a href="<?php echo documento_view_url((int)$doc['id']); ?>" 
                                                           class="btn btn-sm btn-primary" target="_blank">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <a href="aprovar-documento.php?id=<?php echo $doc['id']; ?>&action=aprovar" 
                                                           class="btn btn-sm btn-success">
                                                            <i class="bi bi-check-lg"></i>
                                                        </a>
                                                        <a href="aprovar-documento.php?id=<?php echo $doc['id']; ?>&action=rejeitar" 
                                                           class="btn btn-sm btn-danger">
                                                            <i class="bi bi-x-lg"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="text-end mt-3">
                                <a href="documentos.php" class="btn btn-outline-primary">Ver Todos os Documentos</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Últimas Atividades</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        // Buscar últimas atividades do sistema
                        $sql = "SELECT 'missao' as tipo, m.titulo as descricao, m.data_criacao as data, u.nome 
                                FROM missoes m 
                                JOIN usuarios u ON m.empresa_id = u.id
                                UNION 
                                SELECT 'proposta' as tipo, CONCAT('Proposta para missão ID:', p.missao_id) as descricao, p.data_criacao as data, u.nome 
                                FROM propostas p 
                                JOIN usuarios u ON p.caminhoneiro_id = u.id
                                UNION 
                                SELECT 'documento' as tipo, CONCAT('Upload de ', d.tipo_documento) as descricao, d.data_upload as data, u.nome 
                                FROM documentos d 
                                JOIN usuarios u ON d.usuario_id = u.id
                                ORDER BY data DESC LIMIT 10";
                                
                        $stmt = $conn->prepare($sql);
                        $stmt->execute();
                        $atividades = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        
                        <?php if (empty($atividades)): ?>
                            <p class="text-center">Nenhuma atividade registrada.</p>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($atividades as $atividade): ?>
                                    <a href="#" class="list-group-item list-group-item-action">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h5 class="mb-1">
                                                <?php 
                                                    switch($atividade['tipo']) {
                                                        case 'missao': 
                                                            echo '<i class="bi bi-truck text-primary me-2"></i>'; 
                                                            break;
                                                        case 'proposta': 
                                                            echo '<i class="bi bi-envelope text-success me-2"></i>'; 
                                                            break;
                                                        case 'documento': 
                                                            echo '<i class="bi bi-file-earmark text-warning me-2"></i>'; 
                                                            break;
                                                    }
                                                    echo htmlspecialchars($atividade['descricao']);
                                                ?>
                                            </h5>
                                            <small><?php echo date('d/m/Y H:i', strtotime($atividade['data'])); ?></small>
                                        </div>
                                        <p class="mb-1">Usuário: <?php echo htmlspecialchars($atividade['nome']); ?></p>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
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
</body>
</html> 