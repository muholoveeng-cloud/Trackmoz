<?php
session_start();
require_once '../../../config/app.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth.php';
require_once '../../../includes/helpers.php';

require_role(['empresa', 'admin'], '../login.php');

$inicio = isset($_GET['inicio']) ? trim($_GET['inicio']) : '';
$fim = isset($_GET['fim']) ? trim($_GET['fim']) : '';
$missoes = [];
$total = 0.0;
$concluidas = 0;
$canceladas = 0;
$andamento = 0;
$periodo = 'Todos os períodos';
$empresaId = (($_SESSION['user_type'] ?? null) === 'empresa') ? (int)$_SESSION['user_id'] : 0;

try {
    $params = [];
    $filtro_empresa = '';
    if ($empresaId > 0) {
        $filtro_empresa = ' AND m.empresa_id = :empresa_id ';
        $params[':empresa_id'] = $empresaId;
    }

    $filtro = '';
    if ($inicio !== '' && $fim !== '') {
        $filtro = ' AND DATE(m.data_criacao) BETWEEN :inicio AND :fim ';
        $params[':inicio'] = $inicio;
        $params[':fim'] = $fim;
        $periodo = date('d/m/Y', strtotime($inicio)) . ' — ' . date('d/m/Y', strtotime($fim));
    }

    $sql = "SELECT m.id, m.titulo, m.origem, m.destino, m.status, m.valor, m.data_criacao, m.empresa_id
            FROM missoes m
            WHERE 1=1 {$filtro_empresa} {$filtro}
            ORDER BY m.data_criacao DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $missoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($missoes as $m) {
        $total += (float)($m['valor'] ?? 0);
        $st = $m['status'] ?? '';
        if (in_array($st, ['concluida', 'entrega_confirmada'], true)) {
            $concluidas++;
        } elseif ($st === 'cancelada') {
            $canceladas++;
        } elseif (in_array($st, ['aceita', 'em_andamento', 'em_transito', 'em_entrega', 'aguardando_confirmacao'], true)) {
            $andamento++;
        }
    }

    try {
        $missao_id_registro = $empresaId > 0 ? $empresaId : 0;
        $tipo_documento_registro = $empresaId > 0 ? 'relatorio_empresa' : 'relatorio_admin';
        $sql = "INSERT INTO documentos_oficiais_missao (missao_id, tipo_documento, emitido_em, emitido_por_usuario_id, bloqueado)
                VALUES (:missao_id, :tipo_documento, NOW(), :emitido_por, 1)
                ON DUPLICATE KEY UPDATE emitido_em = NOW(), emitido_por_usuario_id = :emitido_por, bloqueado = 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':missao_id' => $missao_id_registro,
            ':tipo_documento' => $tipo_documento_registro,
            ':emitido_por' => (int)($_SESSION['user_id'] ?? 0),
        ]);
    } catch (PDOException $e) {
        error_log('Erro ao registrar emissão (relatorio.php): ' . $e->getMessage());
    }
} catch (PDOException $e) {
    error_log('Erro documento relatorio.php: ' . $e->getMessage());
    http_response_code(500);
    echo 'Erro ao gerar relatório.';
    exit;
}

require_once '../../../includes/documento-profissional.php';
require_once '../../../includes/documentos-registry.php';

$refId = $empresaId > 0 ? $empresaId : (int)($_SESSION['user_id'] ?? 1);
$trackingId = tmz_generate_document_id('TRK', $refId);
$documentId = tmz_docs_next_number($conn, 'relatorio');
$backUrl = BASE_URL . '/pages/contratante/documentos/explorador.php';

$docStub = ['empresa_id' => $empresaId > 0 ? $empresaId : ($missoes[0]['empresa_id'] ?? 0)];
$docBrand = tmz_doc_brand_for_missao($conn, $docStub);
$logoUrl = $docBrand['logoUrl'];
$accentColor = $docBrand['accent'];
$brand = $docBrand['brand'];

try {
    tmz_docs_register($conn, [
        'titulo' => 'Relatório de Atividades' . ($periodo !== 'Todos os períodos' ? ' — ' . $periodo : ''),
        'tipo' => 'relatorio',
        'numero_documento' => $documentId,
        'tracking_id' => $trackingId,
        'status' => 'gerado',
        'data_emissao' => date('Y-m-d H:i:s'),
        'url_visualizacao' => BASE_URL . '/pages/contratante/documentos/relatorio.php?' . http_build_query(array_filter(['inicio' => $inicio, 'fim' => $fim])),
        'criado_por' => (int)$_SESSION['user_id'],
        'empresa_id' => $empresaId > 0 ? $empresaId : null,
        'payload_json' => json_encode(['total' => count($missoes), 'concluidas' => $concluidas], JSON_UNESCAPED_UNICODE),
    ]);
} catch (Throwable $e) {
    error_log('registro relatorio: ' . $e->getMessage());
}

ob_start();
?>
<div class="no-print mb-3">
    <form class="row g-2 align-items-end" method="GET">
        <div class="col-md-3">
            <label class="form-label">Início</label>
            <input type="date" class="form-control" name="inicio" value="<?php echo e($inicio); ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">Fim</label>
            <input type="date" class="form-control" name="fim" value="<?php echo e($fim); ?>">
        </div>
        <div class="col-md-6 d-flex gap-2">
            <button class="btn btn-primary" type="submit">Filtrar período</button>
            <a class="btn btn-outline-secondary" href="<?php echo e($backUrl); ?>">Explorador</a>
        </div>
    </form>
</div>

<?php if ($empresaId > 0): ?>
<?php echo tmz_html_empresa_emissora($brand, 'Empresa emissora'); ?>
<?php endif; ?>

<div class="doc-section">
    <h6>Resumo do período</h6>
    <div class="row g-2">
        <div class="col-md-3"><div class="kv"><span class="k">Período:</span> <?php echo e($periodo); ?></div></div>
        <div class="col-md-3"><div class="kv"><span class="k">Total missões:</span> <?php echo count($missoes); ?></div></div>
        <div class="col-md-3"><div class="kv"><span class="k">Concluídas:</span> <?php echo $concluidas; ?></div></div>
        <div class="col-md-3"><div class="kv"><span class="k">Em execução:</span> <?php echo $andamento; ?></div></div>
        <div class="col-md-3"><div class="kv"><span class="k">Canceladas:</span> <?php echo $canceladas; ?></div></div>
        <div class="col-md-3"><div class="kv"><span class="k">Valor total:</span> <?php echo e(tmz_doc_money($total)); ?></div></div>
    </div>
</div>

<div class="doc-section">
    <h6>Detalhe das missões</h6>
    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Título</th>
                    <th>Origem</th>
                    <th>Destino</th>
                    <th>Status</th>
                    <th>Valor</th>
                    <th>Data</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($missoes)): ?>
                <tr><td colspan="7" class="text-muted text-center py-3">Nenhuma missão no período seleccionado.</td></tr>
            <?php else: ?>
                <?php foreach ($missoes as $m): ?>
                <tr>
                    <td><?php echo (int)$m['id']; ?></td>
                    <td><?php echo e($m['titulo'] ?? ''); ?></td>
                    <td><?php echo e($m['origem'] ?? ''); ?></td>
                    <td><?php echo e($m['destino'] ?? ''); ?></td>
                    <td><?php echo e($m['status'] ?? ''); ?></td>
                    <td><?php echo e(tmz_doc_money($m['valor'] ?? null)); ?></td>
                    <td><?php echo e(tmz_doc_date($m['data_criacao'] ?? null, 'd/m/Y H:i')); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php echo tmz_doc_qr_html($trackingId, 'Validar relatório'); ?>
<?php
$body = ob_get_clean();

tmz_render_document_page(
    'Relatório de Atividades',
    'Consolidado operacional de missões',
    $documentId,
    $trackingId,
    $backUrl,
    $logoUrl,
    $body,
    ['Assinatura e carimbo da Empresa'],
    $accentColor
);
exit;
