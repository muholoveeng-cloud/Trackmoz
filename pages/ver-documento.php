<?php
/**
 * Visualização segura de documentos de utilizador (documentos.*)
 */
session_start();
include_once('../config/app.php');
include_once('../config/database.php');
include_once('../includes/auth.php');
include_once('../includes/helpers.php');

require_login(BASE_URL . '/pages/login.php');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit('ID inválido.');
}

$stmt = $conn->prepare(
    'SELECT d.*, u.nome AS nome_usuario, u.tipo_usuario
     FROM documentos d
     INNER JOIN usuarios u ON d.usuario_id = u.id
     WHERE d.id = :id'
);
$stmt->execute([':id' => $id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    http_response_code(404);
    exit('Documento não encontrado.');
}

$userId   = (int)$_SESSION['user_id'];
$userType = $_SESSION['user_type'] ?? '';

$permitido = $userType === 'admin'
    || (int)$doc['usuario_id'] === $userId;

if (!$permitido && $userType === 'empresa') {
    $chk = $conn->prepare(
        'SELECT 1 FROM missoes m
         WHERE m.empresa_id = :eid AND m.caminhoneiro_id = :cid LIMIT 1'
    );
    $chk->execute([':eid' => $userId, ':cid' => (int)$doc['usuario_id']]);
    $permitido = (bool)$chk->fetchColumn();
}

if (!$permitido) {
    http_response_code(403);
    exit('Sem permissão para ver este documento.');
}

$caminhoFicheiro = upload_path('documentos', $doc['caminho_arquivo']);
if (!is_file($caminhoFicheiro)) {
    $alt = upload_path('documentos', $doc['nome_arquivo'] ?? '');
    if (is_file($alt)) {
        $caminhoFicheiro = $alt;
    }
}

$download = isset($_GET['download']);
if ($download && is_file($caminhoFicheiro)) {
    header('Content-Type: ' . documento_tipo_mime($doc['nome_arquivo']));
    header('Content-Disposition: attachment; filename="' . basename($doc['nome_arquivo']) . '"');
    header('Content-Length: ' . filesize($caminhoFicheiro));
    readfile($caminhoFicheiro);
    exit;
}

$ext       = strtolower(pathinfo($doc['nome_arquivo'], PATHINFO_EXTENSION));
$preview   = documento_pode_previsualizar($doc['nome_arquivo']) && is_file($caminhoFicheiro);
$fileUrl   = BASE_URL . '/pages/ver-documento.php?id=' . $id . '&raw=1';
$tiposDoc  = ['bi' => 'BI', 'cnh' => 'CNH', 'alvara' => 'Alvará', 'registro_empresa' => 'Registo empresa', 'outros' => 'Outros'];

if (isset($_GET['raw']) && is_file($caminhoFicheiro)) {
    header('Content-Type: ' . documento_tipo_mime($doc['nome_arquivo']));
    header('Content-Length: ' . filesize($caminhoFicheiro));
    readfile($caminhoFicheiro);
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documento — <?php echo e($doc['nome_arquivo']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <style>
        .doc-preview { min-height: 60vh; background: #1e293b; border-radius: .75rem; display: flex; align-items: center; justify-content: center; overflow: auto; }
        .doc-preview img { max-width: 100%; max-height: 75vh; object-fit: contain; }
        .doc-preview embed, .doc-preview iframe { width: 100%; min-height: 75vh; border: none; }
    </style>
</head>
<body class="bg-light">
<?php include_once('../includes/menu.php'); ?>
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
            <h1 class="h4 mb-1"><?php echo e($doc['nome_arquivo']); ?></h1>
            <p class="text-muted small mb-0">
                <?php echo e($doc['nome_usuario']); ?> ·
                <?php echo e($tiposDoc[$doc['tipo_documento']] ?? $doc['tipo_documento']); ?> ·
                <span class="badge bg-<?php echo status_documento_badge($doc['status']); ?>"><?php echo e(ucfirst($doc['status'])); ?></span>
            </p>
        </div>
        <div class="d-flex gap-2">
            <?php if (is_file($caminhoFicheiro)): ?>
                <a href="?id=<?php echo $id; ?>&download=1" class="btn btn-primary btn-sm">
                    <i class="bi bi-download"></i> Descarregar
                </a>
            <?php endif; ?>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="history.back()">
                <i class="bi bi-arrow-left"></i> Voltar
            </button>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-2">
            <?php if (!$preview): ?>
                <div class="alert alert-warning m-3">
                    <i class="bi bi-exclamation-triangle"></i>
                    <?php if (!is_file($caminhoFicheiro)): ?>
                        Ficheiro não encontrado no servidor. Caminho: <code><?php echo e($doc['caminho_arquivo']); ?></code>
                    <?php else: ?>
                        Pré-visualização não disponível para este tipo de ficheiro.
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="doc-preview">
                    <?php if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)): ?>
                        <img src="<?php echo e($fileUrl); ?>" alt="<?php echo e($doc['nome_arquivo']); ?>">
                    <?php elseif ($ext === 'pdf'): ?>
                        <iframe src="<?php echo e($fileUrl); ?>" title="PDF"></iframe>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
