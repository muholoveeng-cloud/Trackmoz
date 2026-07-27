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
        pe.nome_empresa, pe.nuit, pe.endereco, pe.telefone_comercial, pe.email_comercial {$logoSelect},
        u.id AS caminhoneiro_id, u.nome AS nome_caminhoneiro, u.telefone AS telefone_caminhoneiro,
        pc.tipo_veiculo, pc.placa_veiculo
        FROM missoes m
        JOIN usuarios ue ON ue.id = m.empresa_id
        LEFT JOIN perfil_empresa pe ON pe.usuario_id = m.empresa_id
        LEFT JOIN usuarios u ON u.id = m.caminhoneiro_id
        LEFT JOIN perfil_caminhoneiro pc ON pc.usuario_id = m.caminhoneiro_id
        WHERE m.id = :id";
if ($empresaFilter !== null) $sql .= " AND m.empresa_id = :empresa_id";
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

$ids = tmz_docs_number_and_tracking($conn, 'termo_responsabilidade', $missao_id, (int)$doc['empresa_id']);
$documentId = $ids['numero_documento'];
$trackingId = $ids['tracking_id'];

tmz_docs_register($conn, [
    'titulo' => 'Termo de Responsabilidade - Missão #' . $missao_id,
    'tipo' => 'termo_responsabilidade',
    'numero_documento' => $documentId,
    'tracking_id' => $trackingId,
    'status' => 'gerado',
    'data_emissao' => date('Y-m-d H:i:s'),
    'url_visualizacao' => BASE_URL . '/pages/contratante/documentos/termo-responsabilidade.php?missao=' . $missao_id,
    'criado_por' => (int)$_SESSION['user_id'],
    'empresa_id' => (int)$doc['empresa_id'],
    'missao_id' => $missao_id,
    'condutor_id' => !empty($doc['caminhoneiro_id']) ? (int)$doc['caminhoneiro_id'] : null,
    'viatura_ref' => trim((string)($doc['placa_veiculo'] ?? '')),
]);

$docBrand = tmz_doc_brand_for_missao($conn, $doc);
$logoUrl = $docBrand['logoUrl'];
$accentColor = $docBrand['accent'];
$brand = $docBrand['brand'];
$backUrl = BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id;

ob_start();
?>
<?php echo tmz_html_empresa_emissora($brand, 'Transportadora'); ?>
<div class="doc-section">
    <h6>Partes Envolvidas</h6>
    <div class="kv"><span class="k">Condutor:</span> <?php echo e(tmz_safe_text($doc['nome_caminhoneiro'] ?? null)); ?></div>
    <div class="kv"><span class="k">Viatura:</span> <?php echo e(tmz_safe_text(($doc['tipo_veiculo'] ?? '') . ' ' . ($doc['placa_veiculo'] ?? ''))); ?></div>
</div>
<div class="doc-section">
    <h6>Objeto</h6>
    <div class="kv"><span class="k">Missão:</span> #<?php echo (int)$missao_id; ?> — <?php echo e(tmz_safe_text($doc['titulo'] ?? null)); ?></div>
    <div class="kv"><span class="k">Origem/Destino:</span> <?php echo e(tmz_safe_text($doc['origem'] ?? null)); ?> → <?php echo e(tmz_safe_text($doc['destino'] ?? null)); ?></div>
    <div class="kv"><span class="k">Carga:</span> <?php echo e(tmz_safe_text($doc['tipo_carga'] ?? null)); ?></div>
    <div class="kv"><span class="k">Condições:</span> O condutor declara responsabilidade pela integridade da carga durante o percurso, observando requisitos de segurança e legislação rodoviária aplicável.</div>
    <div class="doc-note mt-2">Este termo deve acompanhar a missão e ser assinado pelas partes competentes.</div>
</div>
<?php
$body = ob_get_clean();

tmz_render_document_page(
    'Termo de Responsabilidade',
    'Declaração operacional de responsabilidade no transporte',
    $documentId,
    $trackingId,
    $backUrl,
    $logoUrl,
    $body,
    [
        'Assinatura da Empresa Transportadora',
        'Assinatura do Condutor',
        'Assinatura do Cliente/Remetente',
        'Assinatura do Destinatário',
    ],
    $accentColor
);
exit;

