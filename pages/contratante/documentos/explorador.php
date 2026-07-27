<?php
session_start();
require_once '../../../config/app.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth.php';
require_once '../../../includes/helpers.php';
require_once '../../../includes/documentos-registry.php';

require_role(['empresa', 'transportador', 'admin'], '../login.php');
tmz_docs_bootstrap($conn);

$userType = $_SESSION['user_type'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);
$empresaFilter = ($userType === 'empresa') ? $userId : null;
$transportadorFilter = ($userType === 'transportador') ? $userId : null;

// Gerar documentos em falta (parcerias activas / missões recentes)
try {
    tmz_docs_backfill_pendentes($conn, $empresaFilter, $transportadorFilter);
} catch (Throwable $e) {
    error_log('explorador backfill: ' . $e->getMessage());
}

$q = trim((string)($_GET['q'] ?? ''));
$missaoIdFilter = max(0, (int)($_GET['missao_id'] ?? 0));
$tipo = trim((string)($_GET['tipo'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$dataInicio = trim((string)($_GET['inicio'] ?? ''));
$dataFim = trim((string)($_GET['fim'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if ($empresaFilter !== null) {
    $where[] = 'd.empresa_id = :empresa_id';
    $params[':empresa_id'] = $empresaFilter;
} elseif ($transportadorFilter !== null) {
    $where[] = '(d.transportador_id = :tid
        OR d.parceria_id IN (SELECT id FROM parcerias WHERE transportador_id = :tid2)
        OR d.missao_id IN (SELECT id FROM missoes WHERE transportador_id = :tid3))';
    $params[':tid'] = $transportadorFilter;
    $params[':tid2'] = $transportadorFilter;
    $params[':tid3'] = $transportadorFilter;
}
if ($q !== '') {
    $where[] = '(d.titulo LIKE :q OR d.numero_documento LIKE :q OR d.tracking_id LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($missaoIdFilter > 0) {
    $where[] = 'd.missao_id = :missao_id';
    $params[':missao_id'] = $missaoIdFilter;
}
if ($tipo !== '') {
    $where[] = 'd.tipo = :tipo';
    $params[':tipo'] = $tipo;
}
if ($status !== '') {
    $where[] = 'd.status = :status';
    $params[':status'] = $status;
}
if ($dataInicio !== '') {
    $where[] = 'DATE(d.data_emissao) >= :inicio';
    $params[':inicio'] = $dataInicio;
}
if ($dataFim !== '') {
    $where[] = 'DATE(d.data_emissao) <= :fim';
    $params[':fim'] = $dataFim;
}

$sqlWhere = implode(' AND ', $where);

$cnt = $conn->prepare("SELECT COUNT(*) FROM documentos_sistema d WHERE {$sqlWhere}");
foreach ($params as $k => $v) {
    $cnt->bindValue($k, $v, PDO::PARAM_STR);
}
$cnt->execute();
$total = (int)$cnt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$sql = "SELECT d.*,
               u.nome AS criado_por_nome
        FROM documentos_sistema d
        LEFT JOIN usuarios u ON u.id = d.criado_por
        WHERE {$sqlWhere}
        ORDER BY d.data_emissao DESC, d.id DESC
        LIMIT :limit OFFSET :offset";
$stmt = $conn->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tipos = [
    '' => 'Todos',
    'contrato_parceria' => 'Contrato de Parceria',
    'contrato_transporte' => 'Contrato Transporte',
    'ordem_transporte' => 'Guia/Ordem',
    'comprovativo_conclusao' => 'Comprovativo',
    'missao_registo' => 'Registo Missão',
    'relatorio' => 'Relatório Actividades',
    'fatura' => 'Factura',
    'recibo' => 'Recibo',
    'termo_responsabilidade' => 'Termo Responsabilidade',
    'relatorio_incidente' => 'Relatório Incidente',
    'avaliacao' => 'Avaliação',
];

$statuses = [
    '' => 'Todos',
    'gerado' => 'Gerado',
    'assinado' => 'Assinado',
    'cancelado' => 'Cancelado',
    'arquivado' => 'Arquivado',
];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Explorador de Documentos - TrackMoz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
</head>
<body>
<?php include_once('../../../includes/menu.php'); ?>
<div class="container py-4">
    <div class="tm-page-header">
        <h4 class="mb-0">Explorador de Documentos</h4>
        <span class="badge bg-primary"><?php echo $total; ?> documento(s)</span>
    </div>

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2">
            <div class="col-md-3">
                <label class="form-label">Pesquisar</label>
                <input type="text" class="form-control" name="q" value="<?php echo e($q); ?>" placeholder="Titulo, numero, tracking">
            </div>
            <div class="col-md-2">
                <label class="form-label">Tipo</label>
                <select class="form-select" name="tipo">
                    <?php foreach ($tipos as $k => $v): ?>
                        <option value="<?php echo e($k); ?>" <?php echo $k === $tipo ? 'selected' : ''; ?>><?php echo e($v); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label">Missão</label>
                <input type="number" class="form-control" name="missao_id" value="<?php echo $missaoIdFilter > 0 ? (int)$missaoIdFilter : ''; ?>" min="1">
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <?php foreach ($statuses as $k => $v): ?>
                        <option value="<?php echo e($k); ?>" <?php echo $k === $status ? 'selected' : ''; ?>><?php echo e($v); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Início</label>
                <input type="date" class="form-control" name="inicio" value="<?php echo e($dataInicio); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Fim</label>
                <input type="date" class="form-control" name="fim" value="<?php echo e($dataFim); ?>">
            </div>
            <div class="col-md-1 d-grid">
                <label class="form-label">&nbsp;</label>
                <button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i></button>
            </div>
        </div>
    </form>

    <div class="card tm-panel">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 tm-table-compact">
                <thead class="table-light">
                <tr>
                    <th>Documento</th>
                    <th>Tipo</th>
                    <th>Status</th>
                    <th>Missão</th>
                    <th>Emissão</th>
                    <th>Criado por</th>
                    <th class="text-end">Ações</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($docs)): ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted">Nenhum documento encontrado.</td></tr>
                <?php endif; ?>
                <?php foreach ($docs as $d): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?php echo e($d['numero_documento']); ?></div>
                            <div class="small text-muted"><?php echo e($d['titulo']); ?></div>
                            <div class="small text-muted">TRK: <?php echo e($d['tracking_id']); ?></div>
                        </td>
                        <td><?php echo e($tipos[$d['tipo']] ?? $d['tipo']); ?></td>
                        <td>
                            <?php
                            $b = match ($d['status']) {
                                'gerado' => 'secondary',
                                'assinado' => 'success',
                                'cancelado' => 'danger',
                                'arquivado' => 'dark',
                                default => 'secondary',
                            };
                            ?>
                            <span class="badge bg-<?php echo $b; ?>"><?php echo e($d['status']); ?></span>
                        </td>
                        <td><?php echo $d['missao_id'] ? ('#' . (int)$d['missao_id']) : '—'; ?></td>
                        <td><?php echo e(date('d/m/Y H:i', strtotime((string)$d['data_emissao']))); ?></td>
                        <td><?php echo e($d['criado_por_nome'] ?? 'Sistema'); ?></td>
                        <td class="text-end">
                            <?php if (!empty($d['url_visualizacao'])): ?>
                                <button type="button" class="btn btn-sm btn-outline-primary btn-preview-doc"
                                        data-url="<?php echo e($d['url_visualizacao']); ?>"
                                        data-title="<?php echo e($d['numero_documento']); ?>">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <a href="<?php echo e($d['url_visualizacao']); ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Abrir em nova aba">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($d['tracking_id'])): ?>
                                <a href="<?php echo e(BASE_URL . '/pages/shared/validar-documento.php?tracking=' . rawurlencode($d['tracking_id'])); ?>"
                                   class="btn btn-sm btn-outline-info" target="_blank" title="Validar QR">
                                    <i class="bi bi-qr-code"></i>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($d['caminho_ficheiro'])): ?>
                                <a href="<?php echo e(BASE_URL . '/' . ltrim((string)$d['caminho_ficheiro'], '/')); ?>" class="btn btn-sm btn-outline-success" target="_blank">
                                    <i class="bi bi-download"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination justify-content-center">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?<?php
                    $qs = $_GET;
                    $qs['page'] = $i;
                    echo e(http_build_query($qs));
                    ?>"><?php echo $i; ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<div class="modal fade" id="modalPreviewDoc" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="previewDocTitle">Pré-visualizar documento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="previewDocFrame" class="tm-doc-preview-frame" title="Pré-visualização"></iframe>
            </div>
            <div class="modal-footer">
                <a href="#" id="previewDocOpen" class="btn btn-primary" target="_blank">Abrir documento</a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.btn-preview-doc').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var url = btn.getAttribute('data-url');
        var title = btn.getAttribute('data-title') || 'Documento';
        document.getElementById('previewDocTitle').textContent = title;
        document.getElementById('previewDocFrame').src = url;
        document.getElementById('previewDocOpen').href = url;
        new bootstrap.Modal(document.getElementById('modalPreviewDoc')).show();
    });
});
document.getElementById('modalPreviewDoc').addEventListener('hidden.bs.modal', function() {
    document.getElementById('previewDocFrame').src = 'about:blank';
});
</script>
</body>
</html>

