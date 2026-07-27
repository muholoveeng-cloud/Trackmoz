<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');
include_once('../../includes/kyc-helpers.php');
include_once('../../includes/notificacoes-helpers.php');

require_login('../login.php');

$userId = (int)$_SESSION['user_id'];
$userType = (string)($_SESSION['user_type'] ?? '');

if ($userType === 'admin') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

kyc_bootstrap($conn);
$info = kyc_obter_estado($conn, $userId);
$obrigatorios = kyc_documentos_obrigatorios($userType);
$success = $error = '';

// Carregar perfil
$perfil = [];
try {
    if ($userType === 'caminhoneiro') {
        $st = $conn->prepare('SELECT * FROM perfil_caminhoneiro WHERE usuario_id = ?');
        $st->execute([$userId]);
        $perfil = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } elseif ($userType === 'empresa') {
        $st = $conn->prepare('SELECT * FROM perfil_empresa WHERE usuario_id = ?');
        $st->execute([$userId]);
        $perfil = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } elseif ($userType === 'transportador') {
        $st = $conn->prepare('SELECT * FROM perfil_transportador WHERE usuario_id = ?');
        $st->execute([$userId]);
        $perfil = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    error_log('verificacao-conta perfil: ' . $e->getMessage());
}

$usuarioRow = $conn->prepare('SELECT nome, telefone, email FROM usuarios WHERE id = ?');
$usuarioRow->execute([$userId]);
$usuario = $usuarioRow->fetch(PDO::FETCH_ASSOC) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'guardar_dados') {
        try {
            if ($userType === 'caminhoneiro') {
                $nome = trim($_POST['nome'] ?? '');
                $telefone = trim($_POST['telefone'] ?? '');
                $numero_cnh = trim($_POST['numero_cnh'] ?? '');
                $validade_cnh = trim($_POST['validade_cnh'] ?? '');
                $tipo_veiculo = trim($_POST['tipo_veiculo'] ?? '');
                $placa = trim($_POST['placa_veiculo'] ?? '');
                $bi = trim($_POST['numero_bi'] ?? '');
                $morada = trim($_POST['morada'] ?? '');

                if ($nome === '' || $telefone === '' || $numero_cnh === '' || $validade_cnh === '' || $bi === '') {
                    throw new RuntimeException('Preencha nome, telefone, BI, número e validade da CNH.');
                }

                $conn->prepare('UPDATE usuarios SET nome = ?, telefone = ? WHERE id = ?')
                     ->execute([$nome, $telefone, $userId]);
                $_SESSION['user_name'] = $nome;

                // Garantir colunas opcionais
                try {
                    $pc = $conn->query('SHOW COLUMNS FROM perfil_caminhoneiro')->fetchAll(PDO::FETCH_COLUMN);
                    if (!in_array('numero_bi', $pc, true)) {
                        $conn->exec('ALTER TABLE perfil_caminhoneiro ADD COLUMN numero_bi VARCHAR(40) NULL');
                    }
                    if (!in_array('morada', $pc, true)) {
                        $conn->exec('ALTER TABLE perfil_caminhoneiro ADD COLUMN morada VARCHAR(255) NULL');
                    }
                } catch (Throwable $e) { /* ignore */ }

                $exists = $conn->prepare('SELECT id FROM perfil_caminhoneiro WHERE usuario_id = ?');
                $exists->execute([$userId]);
                if ($exists->fetch()) {
                    $conn->prepare(
                        "UPDATE perfil_caminhoneiro SET numero_cnh = ?, validade_cnh = ?, tipo_veiculo = ?,
                            placa_veiculo = ?, numero_bi = ?, morada = ?, disponibilidade = 'indisponivel'
                         WHERE usuario_id = ?"
                    )->execute([$numero_cnh, $validade_cnh, $tipo_veiculo ?: 'Não informado', $placa ?: null, $bi, $morada ?: null, $userId]);
                } else {
                    $conn->prepare(
                        "INSERT INTO perfil_caminhoneiro (usuario_id, numero_cnh, validade_cnh, tipo_veiculo, placa_veiculo, numero_bi, morada, disponibilidade)
                         VALUES (?,?,?,?,?,?,?,'indisponivel')"
                    )->execute([$userId, $numero_cnh, $validade_cnh, $tipo_veiculo ?: 'Não informado', $placa ?: null, $bi, $morada ?: null]);
                }
            } elseif ($userType === 'empresa' || $userType === 'transportador') {
                $nomeEmp = trim($_POST['nome_empresa'] ?? '');
                $nuit = trim($_POST['nuit'] ?? '');
                $responsavel = trim($_POST['responsavel_legal'] ?? '');
                $telefone = trim($_POST['telefone'] ?? '');
                $morada = trim($_POST['endereco'] ?? '');
                $cidade = trim($_POST['cidade'] ?? '');

                if ($nomeEmp === '' || $nuit === '' || $telefone === '') {
                    throw new RuntimeException('Preencha nome da empresa, NUIT e telefone.');
                }
                if ($userType === 'empresa' && $responsavel === '') {
                    throw new RuntimeException('Indique o responsável legal.');
                }

                $conn->prepare('UPDATE usuarios SET telefone = ? WHERE id = ?')->execute([$telefone, $userId]);

                if ($userType === 'empresa') {
                    $exists = $conn->prepare('SELECT usuario_id FROM perfil_empresa WHERE usuario_id = ?');
                    $exists->execute([$userId]);
                    if ($exists->fetch()) {
                        $conn->prepare(
                            "UPDATE perfil_empresa SET nome_empresa = ?, nuit = ?, responsavel_legal = ?,
                                endereco = ?, cidade = ?, verificada = 0 WHERE usuario_id = ?"
                        )->execute([$nomeEmp, $nuit, $responsavel, $morada ?: null, $cidade ?: null, $userId]);
                    } else {
                        $conn->prepare(
                            "INSERT INTO perfil_empresa (usuario_id, nome_empresa, nuit, responsavel_legal, endereco, cidade, verificada)
                             VALUES (?,?,?,?,?,?,0)"
                        )->execute([$userId, $nomeEmp, $nuit, $responsavel, $morada ?: null, $cidade ?: null]);
                    }
                } else {
                    $exists = $conn->prepare('SELECT usuario_id FROM perfil_transportador WHERE usuario_id = ?');
                    $exists->execute([$userId]);
                    if ($exists->fetch()) {
                        $conn->prepare(
                            "UPDATE perfil_transportador SET nome_empresa = ?, nuit = ?,
                                endereco = ?, cidade = ?, verificada = 0 WHERE usuario_id = ?"
                        )->execute([$nomeEmp, $nuit, $morada ?: null, $cidade ?: null, $userId]);
                    } else {
                        $conn->prepare(
                            "INSERT INTO perfil_transportador (usuario_id, nome_empresa, nuit, endereco, cidade, verificada)
                             VALUES (?,?,?,?,?,0)"
                        )->execute([$userId, $nomeEmp, $nuit, $morada ?: null, $cidade ?: null]);
                    }
                }
            }

            kyc_marcar_dados_completos($conn, $userId);
            kyc_apos_envio_documento($conn, $userId); // reavalia se docs já existem
            $success = 'Dados legais guardados. Envie agora os documentos obrigatórios.';
            $info = kyc_obter_estado($conn, $userId);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    if ($acao === 'upload_doc') {
        $tipoDoc = trim($_POST['tipo_documento'] ?? '');
        if (!isset($obrigatorios[$tipoDoc]) && !in_array($tipoDoc, ['outros'], true)) {
            $error = 'Tipo de documento inválido.';
        } elseif (empty($_FILES['arquivo']['name'])) {
            $error = 'Seleccione um ficheiro.';
        } else {
            $arquivo = $_FILES['arquivo'];
            $ext = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','pdf'], true)) {
                $error = 'Apenas JPG, PNG ou PDF.';
            } elseif ($arquivo['size'] > 5 * 1024 * 1024) {
                $error = 'Ficheiro demasiado grande (máx. 5MB).';
            } elseif ($arquivo['error'] !== UPLOAD_ERR_OK) {
                $error = 'Erro no upload.';
            } else {
                $dir = __DIR__ . '/../../uploads/documentos/';
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                $novo = $userId . '_' . $tipoDoc . '_' . time() . '.' . $ext;
                if (!move_uploaded_file($arquivo['tmp_name'], $dir . $novo)) {
                    $error = 'Falha ao guardar o ficheiro.';
                } else {
                    try {
                        $chk = $conn->prepare('SELECT id, status, bloqueado FROM documentos WHERE usuario_id = ? AND tipo_documento = ?');
                        $chk->execute([$userId, $tipoDoc]);
                        $ex = $chk->fetch(PDO::FETCH_ASSOC);
                        if ($ex && !empty($ex['bloqueado']) && ($ex['status'] ?? '') === 'aprovado') {
                            $error = 'Este documento já foi aprovado e não pode ser substituído.';
                            @unlink($dir . $novo);
                        } elseif ($ex) {
                            $conn->prepare(
                                "UPDATE documentos SET nome_arquivo = ?, caminho_arquivo = ?, data_upload = NOW(), status = 'pendente', bloqueado = 0
                                 WHERE id = ?"
                            )->execute([$arquivo['name'], $novo, $ex['id']]);
                            $success = 'Documento actualizado. A administração será notificada.';
                        } else {
                            $conn->prepare(
                                "INSERT INTO documentos (usuario_id, tipo_documento, nome_arquivo, caminho_arquivo, data_upload, status)
                                 VALUES (?,?,?,?,NOW(),'pendente')"
                            )->execute([$userId, $tipoDoc, $arquivo['name'], $novo]);
                            $success = 'Documento enviado. A administração será notificada.';
                        }
                        if ($error === '') {
                            kyc_apos_envio_documento($conn, $userId);
                            notificar_usuario(
                                $conn,
                                $userId,
                                'info',
                                'Documento enviado',
                                'O seu documento está a aguardar verificação.',
                                kyc_url_verificacao()
                            );
                            $info = kyc_obter_estado($conn, $userId);
                        }
                    } catch (Throwable $e) {
                        $error = 'Erro ao registar documento.';
                        error_log('kyc upload: ' . $e->getMessage());
                    }
                }
            }
        }
    }
}

$info = kyc_obter_estado($conn, $userId);
$docs = $info['docs'] ?? kyc_estado_documentos($conn, $userId, $userType);
$estado = $info['estado'] ?? 'visitante';

$prazoReg = null;
$prazoExpirado = false;
$numAdvertencias = 0;
try {
    require_once __DIR__ . '/../../includes/kyc-advertencias-helpers.php';
    kyc_advertencias_bootstrap($conn);
    $stPrazo = $conn->prepare(
        'SELECT kyc_prazo_regularizacao, kyc_advertencias_count FROM usuarios WHERE id = ?'
    );
    $stPrazo->execute([$userId]);
    $rowPrazo = $stPrazo->fetch(PDO::FETCH_ASSOC) ?: [];
    $prazoReg = $rowPrazo['kyc_prazo_regularizacao'] ?? null;
    $numAdvertencias = (int)($rowPrazo['kyc_advertencias_count'] ?? 0);
    if ($prazoReg) {
        $prazoExpirado = (new DateTimeImmutable($prazoReg)) < new DateTimeImmutable('today');
    }
} catch (Throwable $e) { /* ignore */ }
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificação da Conta — TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
<?php include_once('../../includes/menu.php'); ?>
<div class="container py-4" style="max-width:820px">
    <h1 class="h3 mb-1"><i class="bi bi-shield-check text-primary me-2"></i>Verificação da conta</h1>
    <p class="text-muted">Para evitar fraudes, só pode negociar ou criar missões depois da verificação completa.</p>

    <?php if ($numAdvertencias > 0 && $estado !== 'verificado'): ?>
        <div class="alert alert-<?php echo $prazoExpirado ? 'danger' : 'warning'; ?>">
            <strong><i class="bi bi-megaphone"></i> Advertência da administração</strong>
            <?php if ($prazoReg): ?>
                — prazo para regularizar:
                <strong><?php echo date('d/m/Y', strtotime($prazoReg)); ?></strong>
                <?php if ($prazoExpirado): ?>
                    (expirado — a conta pode ser bloqueada ou removida).
                <?php else: ?>
                    . Se não regularizar, a conta pode ser bloqueada ou removida.
                <?php endif; ?>
            <?php else: ?>
                — complete a documentação o mais depressa possível.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="alert alert-<?php echo $estado === 'verificado' ? 'success' : ($estado === 'em_analise' ? 'info' : ($estado === 'rejeitado' ? 'danger' : 'warning')); ?>">
        <strong>Estado:</strong> <?php echo e(kyc_estado_label($estado)); ?>
        <?php if ($estado === 'verificado'): ?>
            — já pode operar normalmente.
        <?php elseif ($estado === 'em_analise'): ?>
            — aguarde a análise do administrador.
        <?php else: ?>
            — complete os passos abaixo.
        <?php endif; ?>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

    <!-- Passo 1: dados legais -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent fw-semibold">1. Dados legais</div>
        <div class="card-body">
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="acao" value="guardar_dados">
                <?php if ($userType === 'caminhoneiro'): ?>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Nome completo *</label>
                            <input class="form-control" name="nome" required value="<?php echo e($usuario['nome'] ?? ''); ?>"></div>
                        <div class="col-md-6"><label class="form-label">Telefone *</label>
                            <input class="form-control" name="telefone" required value="<?php echo e($usuario['telefone'] ?? ''); ?>"></div>
                        <div class="col-md-6"><label class="form-label">Número do BI *</label>
                            <input class="form-control" name="numero_bi" required value="<?php echo e($perfil['numero_bi'] ?? ''); ?>"></div>
                        <div class="col-md-6"><label class="form-label">Morada</label>
                            <input class="form-control" name="morada" value="<?php echo e($perfil['morada'] ?? ''); ?>"></div>
                        <div class="col-md-6"><label class="form-label">Número da CNH *</label>
                            <input class="form-control" name="numero_cnh" required value="<?php echo e($perfil['numero_cnh'] ?? ''); ?>"></div>
                        <div class="col-md-6"><label class="form-label">Validade da CNH *</label>
                            <input type="date" class="form-control" name="validade_cnh" required value="<?php echo e($perfil['validade_cnh'] ?? ''); ?>"></div>
                        <div class="col-md-6"><label class="form-label">Tipo de viatura</label>
                            <input class="form-control" name="tipo_veiculo" value="<?php echo e($perfil['tipo_veiculo'] ?? ''); ?>"></div>
                        <div class="col-md-6"><label class="form-label">Matrícula</label>
                            <input class="form-control" name="placa_veiculo" value="<?php echo e($perfil['placa_veiculo'] ?? ''); ?>"></div>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Nome da empresa *</label>
                            <input class="form-control" name="nome_empresa" required value="<?php echo e($perfil['nome_empresa'] ?? ''); ?>"></div>
                        <div class="col-md-6"><label class="form-label">NUIT *</label>
                            <input class="form-control" name="nuit" required value="<?php echo e($perfil['nuit'] ?? ''); ?>"></div>
                        <?php if ($userType === 'empresa'): ?>
                        <div class="col-md-6"><label class="form-label">Responsável legal *</label>
                            <input class="form-control" name="responsavel_legal" required value="<?php echo e($perfil['responsavel_legal'] ?? ''); ?>"></div>
                        <?php endif; ?>
                        <div class="col-md-6"><label class="form-label">Telefone *</label>
                            <input class="form-control" name="telefone" required value="<?php echo e($usuario['telefone'] ?? ''); ?>"></div>
                        <div class="col-md-6"><label class="form-label">Endereço</label>
                            <input class="form-control" name="endereco" value="<?php echo e($perfil['endereco'] ?? ''); ?>"></div>
                        <div class="col-md-6"><label class="form-label">Cidade</label>
                            <input class="form-control" name="cidade" value="<?php echo e($perfil['cidade'] ?? ''); ?>"></div>
                    </div>
                <?php endif; ?>
                <button class="btn btn-primary mt-3" type="submit" <?php echo $estado === 'verificado' ? 'disabled' : ''; ?>>
                    <i class="bi bi-save me-1"></i>Guardar dados legais
                </button>
            </form>
        </div>
    </div>

    <!-- Passo 2: documentos -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent fw-semibold">2. Documentos obrigatórios</div>
        <div class="card-body">
            <ul class="list-group mb-3">
                <?php foreach ($obrigatorios as $cod => $label):
                    $d = $docs['por_tipo'][$cod] ?? null;
                    $st = $d['status'] ?? null;
                    $badge = $st === 'aprovado' ? 'success' : ($st === 'pendente' ? 'warning text-dark' : ($st === 'rejeitado' ? 'danger' : 'secondary'));
                    $txt = $st ? ucfirst($st) : 'Em falta';
                ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><?php echo e($label); ?></span>
                        <span class="badge bg-<?php echo $badge; ?>"><?php echo e($txt); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($estado !== 'verificado'): ?>
            <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="acao" value="upload_doc">
                <div class="col-md-5">
                    <label class="form-label">Tipo</label>
                    <select name="tipo_documento" class="form-select" required>
                        <?php foreach ($obrigatorios as $cod => $label): ?>
                            <option value="<?php echo e($cod); ?>"><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Ficheiro (JPG/PNG/PDF)</label>
                    <input type="file" name="arquivo" class="form-control" accept=".jpg,.jpeg,.png,.pdf" required>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-success w-100" type="submit"><i class="bi bi-upload"></i></button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <a href="<?php echo BASE_URL; ?>/index.php" class="btn btn-outline-secondary">Voltar ao painel</a>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
