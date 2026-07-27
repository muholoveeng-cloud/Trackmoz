<?php
session_start();
require_once '../../config/app.php';
require_once '../../config/database.php';
require_once '../../includes/helpers.php';
require_once '../../includes/branding-helpers.php';
require_once '../../includes/regras-negocio.php';

// Verificar se usuário está logado e é do tipo empresa
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] != 'empresa') {
    header('Location: ' . BASE_URL . '/pages/login.php');
    exit;
}

$conn = getConnection();

// Backward-compatible migration for company logo support.
try {
    if (!table_has_column($conn, 'perfil_empresa', 'logo_empresa')) {
        $conn->exec("ALTER TABLE perfil_empresa ADD COLUMN logo_empresa VARCHAR(255) DEFAULT NULL");
    }
} catch (Throwable $e) {
    error_log('Não foi possível criar coluna logo_empresa: ' . $e->getMessage());
}
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
    
    $nome_arquivo = bin2hex(random_bytes(12)) . '.' . $ext;
    $caminho_completo = "../../uploads/$pasta/" . $nome_arquivo;
    
    if (!move_uploaded_file($arquivo['tmp_name'], $caminho_completo)) {
        throw new Exception("Falha ao mover o arquivo para o destino final.");
    }
    
    return $nome_arquivo;
}

// Buscar dados do usuário
$usuario = null;
$documentos = [];
try {
    $sql = "SELECT u.*, 
            pe.nome_empresa, pe.nuit, pe.endereco, pe.tipo_empresa,
            pe.provincia, pe.distrito, pe.cidade,
            pe.responsavel_legal, pe.telefone_comercial, pe.email_comercial,
            pe.banco, pe.iban,
            pe.razao_social, pe.pais, pe.website, pe.cor_institucional,
            pe.ano_fundacao, pe.especialidade, pe.licenca, pe.provincias_operacao, pe.descricao,
            pe.verificada, pe.observacoes_verificacao, pe.logo_empresa
            FROM usuarios u
            LEFT JOIN perfil_empresa pe ON u.id = pe.usuario_id
            WHERE u.id = :id AND u.tipo_usuario = 'empresa'";

    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        $erro = "Usuário não encontrado ou tipo incorreto.";
    }

    // Buscar documentos do usuário
    $sql = "SELECT * FROM documentos WHERE usuario_id = :usuario_id ORDER BY data_upload DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':usuario_id' => $_SESSION['user_id']]);
    $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Erro ao carregar perfil da empresa (pages/contratante/perfil.php): ' . $e->getMessage());
    $erro = "Erro ao carregar dados do perfil. Verifique se as colunas do banco de dados foram atualizadas.";
    $usuario = [];
    $documentos = [];
}

// Processar atualização de perfil
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        // Processar upload de foto de perfil
        if (isset($_FILES['foto_perfil']) && $_FILES['foto_perfil']['error'] != UPLOAD_ERR_NO_FILE) {
            $foto_perfil = processarUploadFoto($_FILES['foto_perfil'], 'logos');

            // Guardar logo principal em perfil_empresa
            $sql = "UPDATE perfil_empresa SET logo_empresa = :logo_empresa WHERE usuario_id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':logo_empresa' => $foto_perfil,
                ':id' => $_SESSION['user_id']
            ]);

            if ($stmt->rowCount() === 0) {
                $sql = "INSERT INTO perfil_empresa (usuario_id, nome_empresa, logo_empresa) VALUES (:id, :nome_empresa, :logo_empresa)
                        ON DUPLICATE KEY UPDATE logo_empresa = VALUES(logo_empresa)";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':id' => $_SESSION['user_id'],
                    ':nome_empresa' => (string)($_POST['nome_empresa'] ?? $_SESSION['user_name'] ?? 'Empresa'),
                    ':logo_empresa' => $foto_perfil,
                ]);
            }
        }
        
        // Atualizar informações do usuário
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
            
            // Verificar se já existe perfil de empresa
            $sql = "SELECT COUNT(*) FROM perfil_empresa WHERE usuario_id = :usuario_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':usuario_id' => $_SESSION['user_id']]);
            $existe_perfil = $stmt->fetchColumn();
            
            $nome_empresa = htmlspecialchars(isset($_POST['nome_empresa']) ? $_POST['nome_empresa'] : '');
            $nuit = htmlspecialchars(isset($_POST['nuit']) ? $_POST['nuit'] : '');
            $nuitCheck = validar_nuit_unico($conn, $nuit, (int)$_SESSION['user_id']);
            if (!$nuitCheck['ok']) {
                throw new Exception(regras_erro_mensagem($nuitCheck));
            }
            $endereco = htmlspecialchars(isset($_POST['endereco']) ? $_POST['endereco'] : '');
            $tipo_empresa = htmlspecialchars(isset($_POST['tipo_empresa']) ? $_POST['tipo_empresa'] : '');
            $provincia = htmlspecialchars(isset($_POST['provincia']) ? $_POST['provincia'] : '');
            $distrito = htmlspecialchars(isset($_POST['distrito']) ? $_POST['distrito'] : '');
            $cidade = htmlspecialchars(isset($_POST['cidade']) ? $_POST['cidade'] : '');
            $responsavel_legal = htmlspecialchars(isset($_POST['responsavel_legal']) ? $_POST['responsavel_legal'] : '');
            $telefone_comercial = htmlspecialchars(isset($_POST['telefone_comercial']) ? $_POST['telefone_comercial'] : '');
            $email_comercial = htmlspecialchars(isset($_POST['email_comercial']) ? $_POST['email_comercial'] : '');
            $banco = htmlspecialchars(isset($_POST['banco']) ? $_POST['banco'] : '');
            $iban = htmlspecialchars(isset($_POST['iban']) ? $_POST['iban'] : '');
            $razao_social = htmlspecialchars($_POST['razao_social'] ?? '');
            $pais = htmlspecialchars($_POST['pais'] ?? 'Moçambique');
            $website = htmlspecialchars($_POST['website'] ?? '');
            $cor_institucional = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['cor_institucional'] ?? '') ? $_POST['cor_institucional'] : '#2563eb';
            $ano_fundacao = !empty($_POST['ano_fundacao']) ? (int)$_POST['ano_fundacao'] : null;
            $especialidade = htmlspecialchars($_POST['especialidade'] ?? '');
            $licenca = htmlspecialchars($_POST['licenca'] ?? '');
            $provincias_operacao = htmlspecialchars($_POST['provincias_operacao'] ?? '');
            $descricao = htmlspecialchars($_POST['descricao'] ?? '');
            
            if ($existe_perfil) {
                $sql = "UPDATE perfil_empresa SET 
                        nome_empresa = :nome_empresa,
                        razao_social = :razao_social,
                        nuit = :nuit,
                        endereco = :endereco,
                        tipo_empresa = :tipo_empresa,
                        provincia = :provincia,
                        distrito = :distrito,
                        cidade = :cidade,
                        pais = :pais,
                        responsavel_legal = :responsavel_legal,
                        telefone_comercial = :telefone_comercial,
                        email_comercial = :email_comercial,
                        website = :website,
                        cor_institucional = :cor_institucional,
                        ano_fundacao = :ano_fundacao,
                        especialidade = :especialidade,
                        licenca = :licenca,
                        provincias_operacao = :provincias_operacao,
                        descricao = :descricao,
                        banco = :banco,
                        iban = :iban
                        WHERE usuario_id = :usuario_id";
            } else {
                $sql = "INSERT INTO perfil_empresa (
                        usuario_id, nome_empresa, razao_social, nuit, endereco, tipo_empresa,
                        provincia, distrito, cidade, pais,
                        responsavel_legal, telefone_comercial, email_comercial,
                        website, cor_institucional, ano_fundacao, especialidade, licenca, provincias_operacao, descricao,
                        banco, iban
                    ) VALUES (
                        :usuario_id, :nome_empresa, :razao_social, :nuit, :endereco, :tipo_empresa,
                        :provincia, :distrito, :cidade, :pais,
                        :responsavel_legal, :telefone_comercial, :email_comercial,
                        :website, :cor_institucional, :ano_fundacao, :especialidade, :licenca, :provincias_operacao, :descricao,
                        :banco, :iban
                    )";
            }
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':nome_empresa' => $nome_empresa,
                ':razao_social' => $razao_social,
                ':nuit' => $nuit,
                ':endereco' => $endereco,
                ':tipo_empresa' => $tipo_empresa,
                ':provincia' => $provincia,
                ':distrito' => $distrito,
                ':cidade' => $cidade,
                ':pais' => $pais,
                ':responsavel_legal' => $responsavel_legal,
                ':telefone_comercial' => $telefone_comercial,
                ':email_comercial' => $email_comercial,
                ':website' => $website,
                ':cor_institucional' => $cor_institucional,
                ':ano_fundacao' => $ano_fundacao,
                ':especialidade' => $especialidade,
                ':licenca' => $licenca,
                ':provincias_operacao' => $provincias_operacao,
                ':descricao' => $descricao,
                ':banco' => $banco,
                ':iban' => $iban,
                ':usuario_id' => $_SESSION['user_id']
            ]);
        }
        
        $conn->commit();
        $sucesso = "Perfil atualizado com sucesso!";
        
        // Atualizar dados do usuário na sessão
        if (isset($_POST['nome'])) {
            $_SESSION['user_name'] = $_POST['nome'];
        }
        
        // Recarregar dados do usuário
        $sql = "SELECT u.*, 
                pe.nome_empresa, pe.nuit, pe.endereco, pe.tipo_empresa,
                pe.provincia, pe.distrito, pe.cidade,
                pe.responsavel_legal, pe.telefone_comercial, pe.email_comercial,
                pe.banco, pe.iban,
                pe.razao_social, pe.pais, pe.website, pe.cor_institucional,
                pe.ano_fundacao, pe.especialidade, pe.licenca, pe.provincias_operacao, pe.descricao,
                pe.verificada, pe.observacoes_verificacao, pe.logo_empresa
                FROM usuarios u
                LEFT JOIN perfil_empresa pe ON u.id = pe.usuario_id
                WHERE u.id = :id";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $conn->rollBack();
        $erro = "Erro ao atualizar perfil: " . $e->getMessage();
    }
}

$mostrar_edicao = ($_SERVER['REQUEST_METHOD'] === 'POST' && $erro !== '');
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil da Empresa - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/perfil.css">
</head>
<body>
    <?php include_once('../../includes/menu.php'); ?>

    <div class="container perfil-page mt-4">
        <?php if ($erro): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($erro); ?></div>
        <?php endif; ?>
        
        <?php if ($sucesso): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($sucesso); ?></div>
        <?php endif; ?>

        <div class="perfil-hero">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div class="avatar-wrap">
                    <?php if (!empty($usuario['logo_empresa'])): ?>
                        <img src="<?php echo BASE_URL; ?>/uploads/logos/<?php echo rawurlencode($usuario['logo_empresa']); ?>" alt="Logo">
                    <?php else: ?>
                        <i class="bi bi-building fs-1"></i>
                    <?php endif; ?>
                </div>
                <div class="flex-fill">
                    <h1 class="h4 mb-1"><?php echo htmlspecialchars($usuario['nome_empresa'] ?? $usuario['nome'] ?? 'Empresa'); ?></h1>
                    <p class="mb-0 opacity-75 small"><?php echo htmlspecialchars($usuario['email'] ?? ''); ?></p>
                </div>
                <div class="perfil-actions">
                    <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#modalEditarEmpresa">
                        <i class="bi bi-pencil"></i> Editar dados
                    </button>
                    <a href="missoes.php" class="btn btn-outline-light btn-sm"><i class="bi bi-truck"></i> Missões</a>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-4">
                <div class="perfil-card">
                    <div class="card-head"><span><i class="bi bi-image me-2"></i>Logo</span></div>
                    <div class="card-body text-center">
                        <?php if (!empty($usuario['logo_empresa'])): ?>
                            <img src="<?php echo BASE_URL; ?>/uploads/logos/<?php echo rawurlencode($usuario['logo_empresa']); ?>" 
                                 class="mb-3 rounded" style="width: 120px; height: 120px; object-fit: contain;">
                        <?php else: ?>
                            <div class="bg-light mb-3 d-flex align-items-center justify-content-center mx-auto rounded" 
                                 style="width: 120px; height: 120px;">
                                <i class="bi bi-building fs-1 text-muted"></i>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="foto_perfil" class="form-label small">Alterar logo</label>
                                <input type="file" class="form-control form-control-sm" id="foto_perfil" name="foto_perfil" accept="image/*">
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm w-100">Atualizar logo</button>
                        </form>
                    </div>
                </div>
                
                <div class="perfil-card">
                    <div class="card-head"><span><i class="bi bi-person me-2"></i>Dados da empresa</span></div>
                    <div class="card-body">
                        <div class="perfil-kv"><div class="label">Representante</div><div class="value"><?php echo htmlspecialchars($usuario['nome'] ?? ''); ?></div></div>
                        <div class="perfil-kv"><div class="label">Email</div><div class="value"><?php echo htmlspecialchars($usuario['email'] ?? ''); ?></div></div>
                        <div class="perfil-kv"><div class="label">Telefone</div><div class="value"><?php echo htmlspecialchars($usuario['telefone'] ?? ''); ?></div></div>
                        <div class="perfil-kv"><div class="label">Empresa</div><div class="value"><?php echo htmlspecialchars($usuario['nome_empresa'] ?? ''); ?></div></div>
                        <div class="perfil-kv"><div class="label">NUIT</div><div class="value"><?php echo htmlspecialchars($usuario['nuit'] ?? '—'); ?></div></div>
                        <div class="perfil-kv"><div class="label">Localização</div><div class="value"><?php echo htmlspecialchars(trim(($usuario['provincia'] ?? '') . ' / ' . ($usuario['distrito'] ?? '') . ' / ' . ($usuario['cidade'] ?? ''), ' /') ?: '—'); ?></div></div>
                        <div class="perfil-kv"><div class="label">Endereço</div><div class="value"><?php echo htmlspecialchars($usuario['endereco'] ?? '—'); ?></div></div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-8">
                <div class="perfil-card mb-3">
                    <div class="card-head"><span><i class="bi bi-bar-chart me-2"></i>Estatísticas</span></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="perfil-stat">
                                    <div class="num"><?php
                                        $stmt = $conn->prepare("SELECT COUNT(*) FROM missoes WHERE empresa_id = :empresa_id");
                                        $stmt->execute([':empresa_id' => $_SESSION['user_id']]);
                                        echo (int)$stmt->fetchColumn();
                                    ?></div>
                                    <div class="lbl">Missões publicadas</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="perfil-stat">
                                    <div class="num"><?php
                                        $stmt = $conn->prepare("SELECT COUNT(*) FROM missoes WHERE empresa_id = :empresa_id AND status = 'concluida'");
                                        $stmt->execute([':empresa_id' => $_SESSION['user_id']]);
                                        echo (int)$stmt->fetchColumn();
                                    ?></div>
                                    <div class="lbl">Concluídas</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="perfil-stat">
                                    <div class="num"><?php
                                        $stmt = $conn->prepare("SELECT COUNT(*) FROM propostas p JOIN missoes m ON p.missao_id = m.id WHERE m.empresa_id = :empresa_id");
                                        $stmt->execute([':empresa_id' => $_SESSION['user_id']]);
                                        echo (int)$stmt->fetchColumn();
                                    ?></div>
                                    <div class="lbl">Propostas recebidas</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="perfil-card mb-3">
                    <div class="card-head"><span><i class="bi bi-list-task me-2"></i>Missões recentes</span></div>
                    <div class="card-body">
                        <?php
                        $stmt = $conn->prepare("SELECT id, titulo, origem, destino, status, data_criacao FROM missoes WHERE empresa_id = :empresa_id ORDER BY data_criacao DESC LIMIT 5");
                        $stmt->execute([':empresa_id' => $_SESSION['user_id']]);
                        $missoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        <?php if (empty($missoes)): ?>
                            <p class="text-center text-muted mb-3">Nenhuma missão publicada ainda.</p>
                            <div class="text-center"><a href="nova-missao.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle"></i> Nova missão</a></div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>Título</th><th>Rota</th><th>Status</th><th></th></tr></thead>
                                    <tbody>
                                        <?php foreach ($missoes as $missao): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($missao['titulo']); ?></td>
                                            <td class="small text-muted"><?php echo htmlspecialchars($missao['origem'] . ' → ' . $missao['destino']); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $missao['status']))); ?></span></td>
                                            <td><a href="detalhes-missao.php?id=<?php echo (int)$missao['id']; ?>" class="btn btn-sm btn-outline-primary">Ver</a></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="perfil-card">
                    <div class="card-head"><span><i class="bi bi-file-earmark me-2"></i>Documentos</span></div>
                    <div class="card-body">
                        <?php if (empty($documentos)): ?>
                            <p class="text-center text-muted mb-0">Nenhum documento cadastrado.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>Tipo</th><th>Ficheiro</th><th>Status</th><th></th></tr></thead>
                                    <tbody>
                                        <?php foreach ($documentos as $doc): ?>
                                        <tr>
                                            <td><?php echo ucfirst(str_replace('_', ' ', $doc['tipo_documento'])); ?></td>
                                            <td><?php echo htmlspecialchars($doc['nome_arquivo']); ?></td>
                                            <td><span class="badge bg-<?php echo $doc['status'] === 'aprovado' ? 'success' : ($doc['status'] === 'rejeitado' ? 'danger' : 'warning'); ?>"><?php echo ucfirst($doc['status']); ?></span></td>
                                            <td><a href="<?php echo documento_view_url((int)$doc['id']); ?>" class="btn btn-sm btn-outline-primary" target="_blank">Ver</a></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade perfil-modal" id="modalEditarEmpresa" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Editar dados da empresa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="nome" class="form-label">Nome do representante</label>
                                <input type="text" class="form-control" id="nome" name="nome" value="<?php echo htmlspecialchars($usuario['nome'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="telefone" class="form-label">Telefone</label>
                                <input type="text" class="form-control" id="telefone" name="telefone" value="<?php echo htmlspecialchars($usuario['telefone'] ?? ''); ?>" required>
                            </div>
                            <div class="col-12">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($usuario['email'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="nome_empresa" class="form-label">Nome comercial</label>
                                <input type="text" class="form-control" id="nome_empresa" name="nome_empresa" value="<?php echo htmlspecialchars($usuario['nome_empresa'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="razao_social" class="form-label">Razão social</label>
                                <input type="text" class="form-control" id="razao_social" name="razao_social" value="<?php echo htmlspecialchars($usuario['razao_social'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="responsavel_legal" class="form-label">Responsável legal</label>
                                <input type="text" class="form-control" id="responsavel_legal" name="responsavel_legal" value="<?php echo htmlspecialchars($usuario['responsavel_legal'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="nuit" class="form-label">NUIT</label>
                                <input type="text" class="form-control" id="nuit" name="nuit" value="<?php echo htmlspecialchars($usuario['nuit'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="provincia" class="form-label">Província</label>
                                <input type="text" class="form-control" id="provincia" name="provincia" value="<?php echo htmlspecialchars($usuario['provincia'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="distrito" class="form-label">Distrito</label>
                                <input type="text" class="form-control" id="distrito" name="distrito" value="<?php echo htmlspecialchars($usuario['distrito'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="cidade" class="form-label">Cidade</label>
                                <input type="text" class="form-control" id="cidade" name="cidade" value="<?php echo htmlspecialchars($usuario['cidade'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="pais" class="form-label">País</label>
                                <input type="text" class="form-control" id="pais" name="pais" value="<?php echo htmlspecialchars($usuario['pais'] ?? 'Moçambique'); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="tipo_empresa" class="form-label">Tipo de empresa</label>
                                <select class="form-select" id="tipo_empresa" name="tipo_empresa">
                                    <?php foreach (['transportadora','comercial','industrial','outro'] as $te): ?>
                                    <option value="<?php echo $te; ?>" <?php echo ($usuario['tipo_empresa'] ?? '') === $te ? 'selected' : ''; ?>><?php echo ucfirst($te); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label for="endereco" class="form-label">Endereço</label>
                                <textarea class="form-control" id="endereco" name="endereco" rows="2"><?php echo htmlspecialchars($usuario['endereco'] ?? ''); ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <label for="telefone_comercial" class="form-label">Telefone comercial</label>
                                <input type="text" class="form-control" id="telefone_comercial" name="telefone_comercial" value="<?php echo htmlspecialchars($usuario['telefone_comercial'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="email_comercial" class="form-label">Email institucional</label>
                                <input type="email" class="form-control" id="email_comercial" name="email_comercial" value="<?php echo htmlspecialchars($usuario['email_comercial'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="website" class="form-label">Website</label>
                                <input type="url" class="form-control" id="website" name="website" value="<?php echo htmlspecialchars($usuario['website'] ?? ($usuario['site'] ?? '')); ?>" placeholder="https://">
                            </div>
                            <div class="col-md-4">
                                <label for="cor_institucional" class="form-label">Cor institucional</label>
                                <input type="color" class="form-control form-control-color w-100" id="cor_institucional" name="cor_institucional" value="<?php echo htmlspecialchars($usuario['cor_institucional'] ?? '#2563eb'); ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="ano_fundacao" class="form-label">Ano fundação</label>
                                <input type="number" class="form-control" id="ano_fundacao" name="ano_fundacao" min="1900" max="2099" value="<?php echo htmlspecialchars((string)($usuario['ano_fundacao'] ?? '')); ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="licenca" class="form-label">Licença / Alvará</label>
                                <input type="text" class="form-control" id="licenca" name="licenca" value="<?php echo htmlspecialchars($usuario['licenca'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="especialidade" class="form-label">Especialidade</label>
                                <input type="text" class="form-control" id="especialidade" name="especialidade" value="<?php echo htmlspecialchars($usuario['especialidade'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="provincias_operacao" class="form-label">Províncias onde opera</label>
                                <input type="text" class="form-control" id="provincias_operacao" name="provincias_operacao" value="<?php echo htmlspecialchars($usuario['provincias_operacao'] ?? ''); ?>" placeholder="Maputo, Gaza, Sofala...">
                            </div>
                            <div class="col-12">
                                <label for="descricao" class="form-label">Descrição</label>
                                <textarea class="form-control" id="descricao" name="descricao" rows="2"><?php echo htmlspecialchars($usuario['descricao'] ?? ''); ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <label for="banco" class="form-label">Banco</label>
                                <input type="text" class="form-control" id="banco" name="banco" value="<?php echo htmlspecialchars($usuario['banco'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="iban" class="form-label">IBAN / Conta</label>
                                <input type="text" class="form-control" id="iban" name="iban" value="<?php echo htmlspecialchars($usuario['iban'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar alterações</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php if ($mostrar_edicao): ?><script>document.addEventListener('DOMContentLoaded',()=>new bootstrap.Modal(document.getElementById('modalEditarEmpresa')).show());</script><?php endif; ?>

    <footer class="bg-light mt-5 py-3">
        <div class="container text-center">
            <p>&copy; 2024 TrackMoz. Todos os direitos reservados.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
</body>
</html>