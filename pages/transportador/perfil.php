<?php
session_start();
require_once '../../config/app.php';
require_once '../../config/database.php';
require_once '../../includes/helpers.php';
require_once '../../includes/branding-helpers.php';
require_once '../../includes/kpi-helpers.php';
require_once '../../includes/reputacao-helpers.php';
require_once '../../includes/regras-negocio.php';
require_once '../../includes/auth.php';

require_role(['transportador'], '../login.php');

$conn = getConnection();
$user_id = (int)$_SESSION['user_id'];
$erro = '';
$sucesso = '';

foreach (['logo_empresa', 'ano_fundacao', 'licenca'] as $col) {
    try {
        if (!table_has_column($conn, 'perfil_transportador', $col)) {
            $def = match ($col) {
                'logo_empresa' => 'VARCHAR(255) DEFAULT NULL',
                'ano_fundacao' => 'SMALLINT DEFAULT NULL',
                'licenca'        => 'VARCHAR(80) DEFAULT NULL',
            };
            $conn->exec("ALTER TABLE perfil_transportador ADD COLUMN {$col} {$def}");
        }
    } catch (Throwable $e) {
        error_log('perfil transportador coluna ' . $col . ': ' . $e->getMessage());
    }
}

function processarUploadLogoTransportador(array $arquivo): string
{
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new Exception('Erro no upload do logo.');
    }
    $ext = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        throw new Exception('Formato inválido. Use JPG ou PNG.');
    }
    if ($arquivo['size'] > 5 * 1024 * 1024) {
        throw new Exception('Logo demasiado grande (máx. 5MB).');
    }
    $dir = __DIR__ . '/../../uploads/logos';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $nome = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($arquivo['tmp_name'], $dir . '/' . $nome)) {
        throw new Exception('Falha ao guardar o logo.');
    }
    return $nome;
}

// Função para garantir que o perfil do transportador exista
function verificarECorrigirPerfil($conn, $user_id) {
    $check_sql = "SELECT * FROM perfil_transportador WHERE usuario_id = :id";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->execute([':id' => $user_id]);
    $perfil = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$perfil) {
        $init_sql = "INSERT INTO perfil_transportador 
                    (usuario_id, nome_empresa, nuit, alvara, endereco, cidade, provincia, 
                     telefone_comercial, email_comercial, verificada) 
                    VALUES 
                    (:id, '', '', '', '', '', '', '', '', 0)";
        $init_stmt = $conn->prepare($init_sql);
        $init_stmt->execute([':id' => $user_id]);
        
        $check_stmt->execute([':id' => $user_id]);
        $perfil = $check_stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    return $perfil;
}

// Buscar dados do usuário e perfil
$usuario = ['verificada' => 0, 'avaliacao_media' => 0, 'total_missoes' => 0];
$perfil = null;
try {
    $sql = "SELECT u.*, 
            pt.nome_empresa, pt.nuit, pt.alvara, pt.endereco, 
            pt.cidade, pt.provincia, pt.pais, pt.telefone_comercial, pt.email_comercial,
            pt.website, pt.cor_institucional, pt.razao_social, pt.especialidade,
            pt.provincias_operacao, pt.descricao, pt.logo_empresa, pt.ano_fundacao, pt.licenca,
            pt.avaliacao_media, pt.total_missoes, pt.verificada
            FROM usuarios u
            LEFT JOIN perfil_transportador pt ON u.id = pt.usuario_id
            WHERE u.id = :id AND u.tipo_usuario = 'transportador'";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $user_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        $erro = "Usuário não encontrado.";
    } else {
        $perfil = verificarECorrigirPerfil($conn, $user_id);
        // Mesclar dados do perfil
        foreach ($perfil as $key => $value) {
            $usuario[$key] = $value;
        }
    }
} catch (PDOException $e) {
    error_log('Erro ao carregar perfil do transportador: ' . $e->getMessage());
    $erro = "Erro ao carregar dados do perfil.";
}

// Processar atualização de perfil
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        // Atualizar dados básicos do usuário
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefone = trim($_POST['telefone'] ?? '');
        
        if (empty($nome)) {
            throw new Exception("Nome é obrigatório.");
        }
        if (empty($email)) {
            throw new Exception("Email é obrigatório.");
        }
        
        $sql = "UPDATE usuarios SET nome = :nome, email = :email, telefone = :telefone WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':nome' => $nome, ':email' => $email, ':telefone' => $telefone, ':id' => $user_id]);
        
        // Atualizar perfil do transportador
        $nome_empresa = trim($_POST['nome_empresa'] ?? '');
        $nuit = trim($_POST['nuit'] ?? '');
        $nuitCheck = validar_nuit_unico($conn, $nuit, $user_id);
        if (!$nuitCheck['ok']) {
            throw new Exception(regras_erro_mensagem($nuitCheck));
        }
        $alvara = trim($_POST['alvara'] ?? '');
        $endereco = trim($_POST['endereco'] ?? '');
        $cidade = trim($_POST['cidade'] ?? '');
        $provincia = trim($_POST['provincia'] ?? '');
        $telefone_comercial = trim($_POST['telefone_comercial'] ?? '');
        $email_comercial = trim($_POST['email_comercial'] ?? '');
        $razao_social = trim($_POST['razao_social'] ?? '');
        $pais = trim($_POST['pais'] ?? 'Moçambique');
        $website = trim($_POST['website'] ?? '');
        $cor_institucional = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['cor_institucional'] ?? '') ? $_POST['cor_institucional'] : '#2563eb';
        $especialidade = trim($_POST['especialidade'] ?? '');
        $provincias_operacao = trim($_POST['provincias_operacao'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $ano_fundacao = !empty($_POST['ano_fundacao']) ? (int)$_POST['ano_fundacao'] : null;
        $licenca = trim($_POST['licenca'] ?? '');

        if (!empty($_FILES['logo_empresa']['name'])) {
            $logo = processarUploadLogoTransportador($_FILES['logo_empresa']);
            $conn->prepare('UPDATE perfil_transportador SET logo_empresa = :logo WHERE usuario_id = :id')
                ->execute([':logo' => $logo, ':id' => $user_id]);
        }
        
        $sql = "UPDATE perfil_transportador 
                SET nome_empresa = :nome_empresa, razao_social = :razao_social, nuit = :nuit, alvara = :alvara,
                    endereco = :endereco, cidade = :cidade, provincia = :provincia, pais = :pais,
                    telefone_comercial = :telefone_comercial, email_comercial = :email_comercial,
                    website = :website, cor_institucional = :cor_institucional,
                    especialidade = :especialidade, provincias_operacao = :provincias_operacao, descricao = :descricao,
                    ano_fundacao = :ano_fundacao, licenca = :licenca
                WHERE usuario_id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':nome_empresa' => $nome_empresa,
            ':razao_social' => $razao_social,
            ':nuit' => $nuit,
            ':alvara' => $alvara,
            ':endereco' => $endereco,
            ':cidade' => $cidade,
            ':provincia' => $provincia,
            ':pais' => $pais,
            ':telefone_comercial' => $telefone_comercial,
            ':email_comercial' => $email_comercial,
            ':website' => $website,
            ':cor_institucional' => $cor_institucional,
            ':especialidade' => $especialidade,
            ':provincias_operacao' => $provincias_operacao,
            ':descricao' => $descricao,
            ':ano_fundacao' => $ano_fundacao,
            ':licenca' => $licenca,
            ':id' => $user_id
        ]);
        
        $conn->commit();
        $sucesso = "Perfil atualizado com sucesso!";
        
        // Recarregar dados
        $reload = $conn->prepare(
            "SELECT u.*, 
            pt.nome_empresa, pt.nuit, pt.alvara, pt.endereco, 
            pt.cidade, pt.provincia, pt.pais, pt.telefone_comercial, pt.email_comercial,
            pt.website, pt.cor_institucional, pt.razao_social, pt.especialidade,
            pt.provincias_operacao, pt.descricao, pt.logo_empresa, pt.ano_fundacao, pt.licenca,
            pt.avaliacao_media, pt.total_missoes, pt.verificada
            FROM usuarios u
            LEFT JOIN perfil_transportador pt ON u.id = pt.usuario_id
            WHERE u.id = :id"
        );
        $reload->execute([':id' => $user_id]);
        $usuario = $reload->fetch(PDO::FETCH_ASSOC) ?: $usuario;
        if ($perfil = verificarECorrigirPerfil($conn, $user_id)) {
            foreach ($perfil as $key => $value) {
                $usuario[$key] = $value;
            }
        }
        
    } catch (Exception $e) {
        $conn->rollBack();
        $erro = "Erro ao atualizar perfil: " . $e->getMessage();
    }
}

$kpiTransp = kpi_transportador($conn, $user_id);
$reputacao = reputacao_utilizador($conn, $user_id);
$brandingPreview = tmz_get_branding($conn, $user_id, 'transportador');
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
</head>
<body>
<?php include_once '../../includes/menu.php'; ?>

<div class="container py-4">
    <div class="row">
        <div class="col-lg-8 mx-auto">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-person-badge me-2"></i>Meu Perfil</h5>
                </div>
                <div class="card-body">
                    <?php if ($erro): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($erro); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($sucesso): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($sucesso); ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" enctype="multipart/form-data">
                        <div class="row mb-4 align-items-center">
                            <div class="col-auto">
                                <?php if (!empty($usuario['logo_empresa'])): ?>
                                    <img src="<?php echo BASE_URL; ?>/uploads/logos/<?php echo rawurlencode($usuario['logo_empresa']); ?>"
                                         alt="Logo" class="rounded border" style="width:80px;height:80px;object-fit:contain;background:#fff;padding:4px;">
                                <?php else: ?>
                                    <div class="rounded border d-flex align-items-center justify-content-center bg-light" style="width:80px;height:80px;">
                                        <i class="bi bi-building fs-3 text-muted"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col">
                                <label class="form-label">Logo da empresa</label>
                                <input type="file" class="form-control form-control-sm" name="logo_empresa" accept="image/png,image/jpeg,image/webp">
                                <div class="form-text">Aparece em documentos e contratos gerados.</div>
                            </div>
                        </div>
                        <h6 class="text-muted mb-3">Dados Pessoais</h6>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Nome</label>
                                <input type="text" class="form-control" name="nome" 
                                       value="<?php echo htmlspecialchars($usuario['nome'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" 
                                       value="<?php echo htmlspecialchars($usuario['email'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Telefone</label>
                            <input type="text" class="form-control" name="telefone" 
                                   value="<?php echo htmlspecialchars($usuario['telefone'] ?? ''); ?>">
                        </div>
                        
                        <hr>
                        
                        <h6 class="text-muted mb-3">Dados da Empresa</h6>
                        <div class="mb-3">
                            <label class="form-label">Nome comercial</label>
                            <input type="text" class="form-control" name="nome_empresa" 
                                   value="<?php echo htmlspecialchars($usuario['nome_empresa'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Razão social</label>
                            <input type="text" class="form-control" name="razao_social" 
                                   value="<?php echo htmlspecialchars($usuario['razao_social'] ?? ''); ?>">
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Ano de fundação</label>
                                <input type="number" class="form-control" name="ano_fundacao" min="1900" max="2099"
                                       value="<?php echo htmlspecialchars((string)($usuario['ano_fundacao'] ?? '')); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Licença operacional</label>
                                <input type="text" class="form-control" name="licenca" 
                                       value="<?php echo htmlspecialchars($usuario['licenca'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Alvará</label>
                                <input type="text" class="form-control" name="alvara" 
                                       value="<?php echo htmlspecialchars($usuario['alvara'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">NUIT</label>
                                <input type="text" class="form-control" name="nuit" 
                                       value="<?php echo htmlspecialchars($usuario['nuit'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Endereço</label>
                            <input type="text" class="form-control" name="endereco" 
                                   value="<?php echo htmlspecialchars($usuario['endereco'] ?? ''); ?>">
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Cidade</label>
                                <input type="text" class="form-control" name="cidade" 
                                       value="<?php echo htmlspecialchars($usuario['cidade'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Província</label>
                                <input type="text" class="form-control" name="provincia" 
                                       value="<?php echo htmlspecialchars($usuario['provincia'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">País</label>
                                <input type="text" class="form-control" name="pais" 
                                       value="<?php echo htmlspecialchars($usuario['pais'] ?? 'Moçambique'); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Telefone Comercial</label>
                                <input type="text" class="form-control" name="telefone_comercial" 
                                       value="<?php echo htmlspecialchars($usuario['telefone_comercial'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Email Comercial</label>
                            <input type="email" class="form-control" name="email_comercial" 
                                   value="<?php echo htmlspecialchars($usuario['email_comercial'] ?? ''); ?>">
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Website</label>
                                <input type="url" class="form-control" name="website" 
                                       value="<?php echo htmlspecialchars($usuario['website'] ?? ''); ?>" placeholder="https://">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Cor institucional</label>
                                <input type="color" class="form-control form-control-color w-100" name="cor_institucional" 
                                       value="<?php echo htmlspecialchars($usuario['cor_institucional'] ?? '#2563eb'); ?>">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Especialidade</label>
                                <input type="text" class="form-control" name="especialidade" 
                                       value="<?php echo htmlspecialchars($usuario['especialidade'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Províncias onde opera</label>
                                <input type="text" class="form-control" name="provincias_operacao" 
                                       value="<?php echo htmlspecialchars($usuario['provincias_operacao'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Descrição</label>
                            <textarea class="form-control" name="descricao" rows="2"><?php echo htmlspecialchars($usuario['descricao'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="badge bg-<?php echo !empty($usuario['verificada']) ? 'success' : 'warning'; ?>">
                                    <?php echo !empty($usuario['verificada']) ? 'Verificado' : 'Pendente Verificação'; ?>
                                </span>
                                <span class="text-muted small ms-2">
                                    <?php echo reputacao_badge_html($reputacao); ?>
                                </span>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save me-2"></i>Salvar Alterações
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="card shadow-sm mt-3">
                <div class="card-header">
                    <h6 class="mb-0">Operação</h6>
                </div>
                <div class="card-body">
                    <div class="row text-center g-3">
                        <div class="col-4">
                            <div class="h4 mb-0"><?php echo (int)($kpiTransp['missoes_ativas'] ?? 0); ?></div>
                            <small class="text-muted">Missões activas</small>
                        </div>
                        <div class="col-4">
                            <div class="h4 mb-0"><?php echo (int)($kpiTransp['frota_ativa'] ?? 0); ?></div>
                            <small class="text-muted">Viaturas activas</small>
                        </div>
                        <div class="col-4">
                            <div class="h4 mb-0"><?php echo (int)($kpiTransp['motoristas_ativos'] ?? 0); ?></div>
                            <small class="text-muted">Motoristas</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
