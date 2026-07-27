<?php
session_start();
require_once '../../../config/app.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth.php';
require_once '../../../includes/helpers.php';
require_once '../../../includes/documento-profissional.php';
require_once '../../../includes/documentos-registry.php';

require_role(['empresa', 'admin', 'caminhoneiro', 'transportador'], '../login.php');
tmz_docs_bootstrap($conn);

$missao_id = isset($_GET['missao']) ? (int)$_GET['missao'] : 0;
if ($missao_id <= 0) {
    http_response_code(400);
    echo 'Parâmetro inválido.';
    exit;
}

$sql = "SELECT m.*, ue.nome AS nome_empresa_usuario, ue.foto_perfil,
        pe.nome_empresa, pe.nuit, pe.telefone_comercial, pe.email_comercial,
        u.id AS caminhoneiro_id, u.nome AS nome_caminhoneiro, u.telefone AS telefone_caminhoneiro,
        pc.tipo_veiculo, pc.placa_veiculo
        FROM missoes m
        JOIN usuarios ue ON ue.id = m.empresa_id
        LEFT JOIN perfil_empresa pe ON pe.usuario_id = m.empresa_id
        LEFT JOIN usuarios u ON u.id = m.caminhoneiro_id
        LEFT JOIN perfil_caminhoneiro pc ON pc.usuario_id = m.caminhoneiro_id
        WHERE m.id = :id";
$hasLogoColumn = table_has_column($conn, 'perfil_empresa', 'logo_empresa');
if ($hasLogoColumn) {
    $sql = str_replace(
        "pe.nome_empresa, pe.nuit, pe.telefone_comercial, pe.email_comercial,",
        "pe.nome_empresa, pe.nuit, pe.telefone_comercial, pe.email_comercial, pe.logo_empresa,",
        $sql
    );
}
$st = $conn->prepare($sql);
$st->execute([':id' => $missao_id]);
$doc = $st->fetch(PDO::FETCH_ASSOC);
if (!$doc) {
    http_response_code(404);
    echo 'Missão não encontrada.';
    exit;
}

if (($_SESSION['user_type'] ?? '') === 'empresa' && (int)$doc['empresa_id'] !== (int)$_SESSION['user_id']) {
    http_response_code(403);
    echo 'Acesso negado.';
    exit;
}

$incidente = trim((string)($_GET['incidente'] ?? 'Incidente operacional reportado.'));

$ids = tmz_docs_number_and_tracking($conn, 'relatorio_incidente', $missao_id, (int)$doc['empresa_id']);
$documentId = $ids['numero_documento'];
$trackingId = $ids['tracking_id'];

tmz_docs_register($conn, [
    'titulo' => 'Relatório de Incidente - Missão #' . $missao_id,
    'tipo' => 'relatorio_incidente',
    'numero_documento' => $documentId,
    'tracking_id' => $trackingId,
    'status' => 'gerado',
    'data_emissao' => date('Y-m-d H:i:s'),
    'url_visualizacao' => BASE_URL . '/pages/contratante/documentos/relatorio-incidente.php?missao=' . $missao_id,
    'criado_por' => (int)$_SESSION['user_id'],
    'empresa_id' => (int)$doc['empresa_id'],
    'missao_id' => $missao_id,
    'condutor_id' => !empty($doc['caminhoneiro_id']) ? (int)$doc['caminhoneiro_id'] : null,
    'viatura_ref' => trim((string)($doc['placa_veiculo'] ?? '')),
    'payload_json' => json_encode(['incidente' => $incidente], JSON_UNESCAPED_UNICODE),
]);

$docBrand = tmz_doc_brand_for_missao($conn, $doc);
$logoUrl = $docBrand['logoUrl'];
$accentColor = $docBrand['accent'];
$brand = $docBrand['brand'];
$backUrl = BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id;

ob_start();
?>
<?php echo tmz_html_empresa_emissora($brand); ?>
<div class="doc-section">
    <h6>Identificação</h6>
    <div class="kv"><span class="k">Condutor:</span> <?php echo e(tmz_safe_text($doc['nome_caminhoneiro'] ?? null)); ?></div>
    <div class="kv"><span class="k">Viatura:</span> <?php echo e(tmz_safe_text(($doc['tipo_veiculo'] ?? '') . ' ' . ($doc['placa_veiculo'] ?? ''))); ?></div>
</div>
<div class="doc-section">
    <h6>Detalhes do Incidente</h6>
    <div class="kv"><span class="k">Missão:</span> #<?php echo (int)$missao_id; ?> — <?php echo e(tmz_safe_text($doc['titulo'] ?? null)); ?></div>
    <div class="kv"><span class="k">Rota:</span> <?php echo e(tmz_safe_text($doc['origem'] ?? null)); ?> → <?php echo e(tmz_safe_text($doc['destino'] ?? null)); ?></div>
    <div class="kv"><span class="k">Data/hora do relato:</span> <?php echo e(date('d/m/Y H:i:s')); ?></div>
    <div class="kv"><span class="k">Descrição:</span> <?php echo e($incidente); ?></div>
    <div class="doc-note mt-2">Anexar evidências externas (fotos, boletins, testemunhos) conforme procedimento interno.</div>
</div>
<?php
$body = ob_get_clean();

tmz_render_document_page(
    'Relatório de Incidente',
    'Registo de ocorrência operacional durante a missão',
    $documentId,
    $trackingId,
    $backUrl,
    $logoUrl,
    $body,
    [
        'Assinatura do Condutor',
        'Assinatura da Empresa Transportadora',
        'Assinatura do Cliente/Remetente (se aplicável)',
    ],
    $accentColor
);
exit;

