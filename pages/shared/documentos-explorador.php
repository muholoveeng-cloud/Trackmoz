<?php
session_start();
include_once('../../config/app.php');
include_once('../../config/database.php');
include_once('../../includes/auth.php');
include_once('../../includes/helpers.php');

$uid = (int)($_SESSION['user_id'] ?? 0);
$tipo = $_SESSION['user_type'] ?? '';
if (!$uid) { header('Location: ' . BASE_URL . '/pages/login.php'); exit; }

$entidade_tipo = $_GET['entidade_tipo'] ?? '';
$entidade_id = (int)($_GET['entidade_id'] ?? 0);
$categoria = $_GET['categoria'] ?? '';

$docs = [];
try {
    $where = "1=1";
    $params = [];

    if ($entidade_tipo) {
        $where .= " AND d.entidade_tipo = :et";
        $params[':et'] = $entidade_tipo;
    }
    if ($entidade_id > 0) {
        $where .= " AND d.entidade_id = :eid";
        $params[':eid'] = $entidade_id;
    }
    if ($categoria) {
        $where .= " AND d.categoria = :cat";
        $params[':cat'] = $categoria;
    }

    // Restringir visibilidade por perfil
    if ($tipo === 'empresa') {
        $where .= " AND (d.entidade_tipo IN ('empresa','missao','factura','parceria','motorista','veiculo')
                        AND d.entidade_id IN (
                            SELECT id FROM missoes WHERE empresa_id = :uid
                            UNION SELECT id FROM parcerias WHERE empresa_id = :uid
                            UNION SELECT id FROM facturas WHERE empresa_id = :uid
                        )
                        OR d.uploaded_by = :uid)";
        $params[':uid'] = $uid;
    } elseif ($tipo === 'transportador') {
        $where .= " AND (d.entidade_tipo IN ('transportador','missao','factura','parceria','motorista','veiculo')
                        AND d.entidade_id IN (
                            SELECT id FROM missoes WHERE transportador_id = :uid
                            UNION SELECT id FROM parcerias WHERE transportador_id = :uid
                            UNION SELECT id FROM facturas WHERE transportador_id = :uid
                            UNION SELECT id FROM veiculos WHERE transportador_id = :uid
                            UNION SELECT id FROM usuarios WHERE transportador_id = :uid AND tipo_usuario = 'motorista'
                        )
                        OR d.uploaded_by = :uid)";
        $params[':uid'] = $uid;
    } elseif ($tipo === 'motorista') {
        $where .= " AND (d.entidade_tipo = 'missao' AND d.entidade_id IN (SELECT id FROM missoes WHERE motorista_id = :uid)
                        OR d.entidade_tipo = 'motorista' AND d.entidade_id = :uid
                        OR d.uploaded_by = :uid)";
        $params[':uid'] = $uid;
    }

    $stmt = $conn->prepare(
        "SELECT d.*, u.nome AS uploader_nome
         FROM documentos_explorador d
         LEFT JOIN usuarios u ON d.uploaded_by = u.id
         WHERE $where
         ORDER BY d.data_upload DESC"
    );
    $stmt->execute($params);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Erro documentos: ' . $e->getMessage());
}

$categorias = [
    'contrato' => 'Contrato', 'factura' => 'Factura', 'recibo' => 'Recibo',
    'comprovativo_pagamento' => 'Comprovativo de Pagamento', 'licenca' => 'Licença',
    'seguro' => 'Seguro', 'cnh' => 'Carta de Condução', 'certidao' => 'Certidão',
    'foto_carga' => 'Foto da Carga', 'foto_entrega' => 'Foto da Entrega', 'outro' => 'Outro'
];
?>
<!DOCTYPE html>
<html lang="pt">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Explorador de Documentos — TrackMoz</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
<?php include_once('../../includes/menu.php'); ?>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h4 class="mb-0"><i class="bi bi-folder me-2 text-primary"></i>Explorador de Documentos</h4></div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalUploadDoc"><i class="bi bi-upload me-1"></i>Upload</button>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small text-muted">Entidade</label>
                    <select name="entidade_tipo" class="form-select">
                        <option value="">Todas</option>
                        <option value="missao" <?php echo $entidade_tipo==='missao'?'selected':''; ?>>Missão</option>
                        <option value="parceria" <?php echo $entidade_tipo==='parceria'?'selected':''; ?>>Parceria</option>
                        <option value="factura" <?php echo $entidade_tipo==='factura'?'selected':''; ?>>Factura</option>
                        <option value="motorista" <?php echo $entidade_tipo==='motorista'?'selected':''; ?>>Motorista</option>
                        <option value="veiculo" <?php echo $entidade_tipo==='veiculo'?'selected':''; ?>>Veículo</option>
                        <option value="empresa" <?php echo $entidade_tipo==='empresa'?'selected':''; ?>>Empresa</option>
                        <option value="transportador" <?php echo $entidade_tipo==='transportador'?'selected':''; ?>>Transportador</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">ID</label>
                    <input type="number" name="entidade_id" class="form-control" value="<?php echo $entidade_id > 0 ? (int)$entidade_id : ''; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Categoria</label>
                    <select name="categoria" class="form-select">
                        <option value="">Todas</option>
                        <?php foreach ($categorias as $k => $v): ?>
                            <option value="<?php echo $k; ?>" <?php echo $categoria===$k?'selected':''; ?>><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-funnel"></i> Filtrar</button>
                </div>
            </form>
        </div>
    </div>

    <?php if (empty($docs)): ?>
        <div class="alert alert-info">Nenhum documento encontrado.</div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($docs as $d): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-start gap-3">
                                <div class="fs-2 text-primary">
                                    <?php $icon = match($d['extensao']) { 'pdf' => 'bi-file-earmark-pdf', 'jpg' => 'bi-file-earmark-image', 'jpeg' => 'bi-file-earmark-image', 'png' => 'bi-file-earmark-image', default => 'bi-file-earmark' }; ?>
                                    <i class="bi <?php echo $icon; ?>"></i>
                                </div>
                                <div class="flex-fill min-w-0">
                                    <h6 class="mb-1 text-truncate" title="<?php echo e($d['nome_original']); ?>"><?php echo e($d['nome_original']); ?></h6>
                                    <div class="small text-muted mb-1">
                                        <span class="badge bg-light text-dark border"><?php echo e($categorias[$d['categoria']] ?? $d['categoria']); ?></span>
                                        <span class="badge bg-secondary"><?php echo e($d['entidade_tipo']); ?> #<?php echo (int)$d['entidade_id']; ?></span>
                                    </div>
                                    <div class="small text-muted"><?php echo e($d['uploader_nome'] ?? '—'); ?> • <?php echo date('d/m/Y H:i', strtotime($d['data_upload'])); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-white d-flex justify-content-between">
                            <a href="<?php echo BASE_URL; ?>/uploads/documentos/<?php echo urlencode($d['caminho']); ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> Ver</a>
                            <?php if (!empty($d['descricao'])): ?>
                                <span class="text-muted small my-auto" title="<?php echo e($d['descricao']); ?>"><i class="bi bi-info-circle"></i></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Upload -->
<div class="modal fade" id="modalUploadDoc" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Enviar Documento</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form id="formUploadDoc" enctype="multipart/form-data" onsubmit="return enviarUpload(event)">
            <?php echo csrf_field(); ?>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Entidade Tipo</label>
                    <select name="entidade_tipo" class="form-select" required>
                        <option value="">Seleccione...</option>
                        <option value="missao">Missão</option>
                        <option value="parceria">Parceria</option>
                        <option value="factura">Factura</option>
                        <option value="motorista">Motorista</option>
                        <option value="veiculo">Veículo</option>
                        <option value="empresa">Empresa</option>
                        <option value="transportador">Transportador</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Entidade ID</label>
                    <input type="number" name="entidade_id" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Categoria</label>
                    <select name="categoria" class="form-select" required>
                        <?php foreach ($categorias as $k => $v): ?>
                            <option value="<?php echo $k; ?>"><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Descrição</label>
                    <textarea name="descricao" class="form-control" rows="2"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Arquivo</label>
                    <input type="file" name="arquivo" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.zip,.rar" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Enviar</button>
            </div>
        </form>
    </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const BASE_URL = '<?php echo BASE_URL; ?>';
const CSRF_TOKEN = '<?php echo csrf_token(); ?>';
async function enviarUpload(e) {
    e.preventDefault();
    const form = e.target;
    const data = new FormData(form);
    data.append('csrf_token', CSRF_TOKEN);
    try {
        const r = await fetch(BASE_URL + '/api/documento-upload.php', {method:'POST', body:data});
        const d = await r.json();
        alert(d.message);
        if (d.success) location.reload();
    } catch(err){ alert('Erro de ligação.'); }
    return false;
}
</script>
</body></html>
