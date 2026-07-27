<?php
session_start();
require_once '../../../config/app.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth.php';
require_once '../../../includes/helpers.php';
require_once '../../../includes/documento-profissional.php';
require_once '../../../includes/documentos-registry.php';

require_role(['empresa', 'admin'], '../login.php');
tmz_docs_bootstrap($conn);

$missao_id = isset($_GET['missao']) ? (int)$_GET['missao'] : 0;
if ($missao_id <= 0) {
    http_response_code(400);
    echo 'Parâmetro inválido.';
    exit;
}

$empresaFilter = (($_SESSION['user_type'] ?? '') === 'empresa') ? (int)$_SESSION['user_id'] : null;
$hasLogoColumn = table_has_column($conn, 'perfil_empresa', 'logo_empresa');
$logoSelect = $hasLogoColumn ? ', pe.logo_empresa' : ", '' AS logo_empresa";

$sql = "SELECT m.*, ue.nome AS nome_empresa_usuario, ue.foto_perfil,
        pe.nome_empresa, pe.nuit, pe.endereco, pe.telefone_comercial, pe.email_comercial, pe.iban, pe.banco {$logoSelect},
        u.id AS caminhoneiro_id, u.nome AS nome_caminhoneiro, u.telefone AS telefone_caminhoneiro,
        pc.tipo_veiculo, pc.placa_veiculo
        FROM missoes m
        JOIN usuarios ue ON ue.id = m.empresa_id
        LEFT JOIN perfil_empresa pe ON pe.usuario_id = m.empresa_id
        LEFT JOIN usuarios u ON u.id = m.caminhoneiro_id
        LEFT JOIN perfil_caminhoneiro pc ON pc.usuario_id = m.caminhoneiro_id
        WHERE m.id = :id";
if ($empresaFilter !== null) {
    $sql .= " AND m.empresa_id = :empresa_id";
}
$st = $conn->prepare($sql);
$params = [':id' => $missao_id];
if ($empresaFilter !== null) $params[':empresa_id'] = $empresaFilter;
$st->execute($params);
$doc = $st->fetch(PDO::FETCH_ASSOC);
if (!$doc) {
    http_response_code(404);
    echo 'Missão não encontrada.';
    exit;
}

$ids = tmz_docs_number_and_tracking($conn, 'fatura', $missao_id, (int)$doc['empresa_id']);
$documentId = $ids['numero_documento'];
$trackingId = $ids['tracking_id'];

$base = (float)($doc['valor'] ?? 0);
$taxaIva = 0.16;
$iva = round($base * $taxaIva, 2);
$total = $base + $iva;

tmz_docs_register($conn, [
    'titulo' => 'Factura - Missão #' . $missao_id,
    'tipo' => 'fatura',
    'numero_documento' => $documentId,
    'tracking_id' => $trackingId,
    'status' => 'gerado',
    'data_emissao' => date('Y-m-d H:i:s'),
    'url_visualizacao' => BASE_URL . '/pages/contratante/documentos/fatura.php?missao=' . $missao_id,
    'criado_por' => (int)$_SESSION['user_id'],
    'empresa_id' => (int)$doc['empresa_id'],
    'missao_id' => $missao_id,
    'condutor_id' => !empty($doc['caminhoneiro_id']) ? (int)$doc['caminhoneiro_id'] : null,
    'viatura_ref' => trim((string)($doc['placa_veiculo'] ?? '')),
    'payload_json' => json_encode(['subtotal' => $base, 'iva' => $iva, 'total' => $total], JSON_UNESCAPED_UNICODE),
]);

$docBrand = tmz_doc_brand_for_missao($conn, $doc);
$logoUrl = $docBrand['logoUrl'];
$accentColor = $docBrand['accent'];
$brand = $docBrand['brand'];
$backUrl = BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id;

ob_start();
?>
<?php echo tmz_html_empresa_emissora($brand, 'Dados Fiscais da Empresa'); ?>

<div class="doc-section">
    <h6>Cliente/Remetente e Serviço</h6>
    <div class="kv"><span class="k">Ref. Missão:</span> #<?php echo (int)$missao_id; ?> — <?php echo e(tmz_safe_text($doc['titulo'] ?? null)); ?></div>
    <div class="kv"><span class="k">Origem:</span> <?php echo e(tmz_safe_text($doc['origem'] ?? null)); ?></div>
    <div class="kv"><span class="k">Destino:</span> <?php echo e(tmz_safe_text($doc['destino'] ?? null)); ?></div>
    <div class="kv"><span class="k">Condutor:</span> <?php echo e(tmz_safe_text($doc['nome_caminhoneiro'] ?? null)); ?></div>
    <div class="kv"><span class="k">Viatura:</span> <?php echo e(tmz_safe_text(($doc['tipo_veiculo'] ?? '') . ' ' . ($doc['placa_veiculo'] ?? ''))); ?></div>
</div>

<div class="doc-section">
    <h6>Valores</h6>
    <div class="kv"><span class="k">Subtotal:</span> <?php echo e(tmz_doc_money($base)); ?></div>
    <div class="kv"><span class="k">IVA (16%):</span> <?php echo e(tmz_doc_money($iva)); ?></div>
    <div class="kv"><span class="k">Total:</span> <strong><?php echo e(tmz_doc_money($total)); ?></strong></div>
    <div class="doc-note mt-2">Documento financeiro emitido para efeitos administrativos e fiscais internos.</div>
</div>
<?php
$body = ob_get_clean();

tmz_render_document_page(
    'Factura',
    'Documento de cobrança de serviço de transporte',
    $documentId,
    $trackingId,
    $backUrl,
    $logoUrl,
    $body,
    [
        'Assinatura e carimbo da Empresa',
        'Assinatura do Cliente/Remetente',
    ],
    $accentColor
);
exit;

