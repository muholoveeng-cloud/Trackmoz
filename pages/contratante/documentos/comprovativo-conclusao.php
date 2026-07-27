<?php
session_start();
require_once '../../../config/app.php';
require_once '../../../config/database.php';

require_once '../../../includes/auth.php';
require_once '../../../includes/helpers.php';
require_once '../../../includes/documentos-registry.php';

require_role(['empresa', 'admin'], '../login.php');

$missao_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($missao_id <= 0) {
    http_response_code(400);
    echo 'Parâmetro inválido.';
    exit;
}

try {
    $hasLogoColumn = table_has_column($conn, 'perfil_empresa', 'logo_empresa');
    $logoSelect = $hasLogoColumn ? ', pe.logo_empresa' : ", '' AS logo_empresa";
    $tipo_documento_oficial = 'comprovativo_conclusao';
    $empresa_id_filter = null;
    if (($_SESSION['user_type'] ?? null) === 'empresa') {
        $empresa_id_filter = (int)$_SESSION['user_id'];
    }

    $sql = "SELECT m.*, 
            pe.nome_empresa, pe.nuit {$logoSelect},
            u.nome AS nome_caminhoneiro, u.telefone AS telefone_caminhoneiro, u.foto_perfil
            FROM missoes m
            LEFT JOIN perfil_empresa pe ON pe.usuario_id = m.empresa_id
            LEFT JOIN usuarios u ON u.id = m.caminhoneiro_id
            WHERE m.id = :id";
    if ($empresa_id_filter !== null) {
        $sql .= " AND m.empresa_id = :empresa_id";
    }

    $stmt = $conn->prepare($sql);
    $params = [':id' => $missao_id];
    if ($empresa_id_filter !== null) {
        $params[':empresa_id'] = $empresa_id_filter;
    }
    $stmt->execute($params);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        http_response_code(404);
        echo 'Missão não encontrada.';
        exit;
    }

    // Registrar emissão (Opção A) - empresa e admin podem reemitir (upsert)
    try {
        $sql = "INSERT INTO documentos_oficiais_missao (missao_id, tipo_documento, emitido_em, emitido_por_usuario_id, bloqueado)
                VALUES (:missao_id, :tipo_documento, NOW(), :emitido_por, 1)
                ON DUPLICATE KEY UPDATE emitido_em = NOW(), emitido_por_usuario_id = :emitido_por, bloqueado = 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':missao_id' => $missao_id,
            ':tipo_documento' => $tipo_documento_oficial,
            ':emitido_por' => (int)($_SESSION['user_id'] ?? 0),
        ]);
    } catch (PDOException $e) {
        error_log('Erro ao registrar emissão (comprovativo-conclusao.php): ' . $e->getMessage());
    }

    $codigo = 'CCM-' . str_pad((string)$missao_id, 6, '0', STR_PAD_LEFT);
    $emitido_em = date('d/m/Y H:i');

} catch (PDOException $e) {
    error_log('Erro documento comprovativo-conclusao.php: ' . $e->getMessage());
    http_response_code(500);
    echo 'Erro ao gerar documento.';
    exit;
}
require_once '../../../includes/documento-profissional.php';

$trackingId = tmz_generate_document_id('TRK', $missao_id);
$documentId = tmz_docs_next_number($conn, 'comprovativo_conclusao');
$backUrl = BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id;
$docBrand = tmz_doc_brand_for_missao($conn, $doc);
$logoUrl = $docBrand['logoUrl'];
$accentColor = $docBrand['accent'];
$brand = $docBrand['brand'];

try {
    tmz_docs_register($conn, [
        'titulo' => 'Comprovativo de Entrega - Missão #' . $missao_id,
        'tipo' => 'comprovativo_conclusao',
        'numero_documento' => $documentId,
        'tracking_id' => $trackingId,
        'status' => (($doc['status'] ?? '') === 'concluida') ? 'assinado' : 'gerado',
        'data_emissao' => date('Y-m-d H:i:s'),
        'url_visualizacao' => BASE_URL . '/pages/contratante/documentos/comprovativo-conclusao.php?id=' . $missao_id,
        'criado_por' => (int)$_SESSION['user_id'],
        'empresa_id' => (int)$doc['empresa_id'],
        'missao_id' => $missao_id,
        'condutor_id' => !empty($doc['caminhoneiro_id']) ? (int)$doc['caminhoneiro_id'] : null,
        'payload_json' => json_encode([
            'status' => $doc['status'] ?? null,
            'data_atualizacao' => $doc['data_atualizacao'] ?? null,
            'valor' => $doc['valor'] ?? null,
        ], JSON_UNESCAPED_UNICODE),
    ]);
} catch (Throwable $e) {
    error_log('registro comprovativo_conclusao: ' . $e->getMessage());
}

ob_start();
?>
<?php echo tmz_html_empresa_emissora($brand, 'Dados da Empresa'); ?>

<div class="doc-section">
    <h6>Dados do Motorista</h6>
    <div class="kv"><span class="k">Nome:</span> <?php echo e(tmz_safe_text($doc['nome_caminhoneiro'] ?? null)); ?></div>
    <div class="kv"><span class="k">Telefone:</span> <?php echo e(tmz_safe_text($doc['telefone_caminhoneiro'] ?? null)); ?></div>
</div>

<div class="doc-section">
    <h6>Resumo de Entrega</h6>
    <div class="kv"><span class="k">Missão:</span> <?php echo e(tmz_safe_text($doc['titulo'] ?? null)); ?></div>
    <div class="kv"><span class="k">Origem:</span> <?php echo e(tmz_safe_text($doc['origem'] ?? null)); ?></div>
    <div class="kv"><span class="k">Destino:</span> <?php echo e(tmz_safe_text($doc['destino'] ?? null)); ?></div>
    <div class="kv"><span class="k">Estado final:</span> <?php echo e(tmz_safe_text($doc['status'] ?? null)); ?></div>
    <div class="kv"><span class="k">Concluída em:</span> <?php echo e(tmz_doc_date($doc['data_atualizacao'] ?? null, 'd/m/Y H:i')); ?></div>
    <div class="kv"><span class="k">Valor da missão:</span> <?php echo e(tmz_doc_money($doc['valor'] ?? null)); ?></div>
    <div class="doc-note mt-2">Confirmação formal de entrega para efeitos operacionais e de auditoria.</div>
</div>
<?php
$body = ob_get_clean();

tmz_render_document_page(
    'Confirmação de Entrega',
    'Comprovativo de conclusão de transporte',
    $documentId,
    $trackingId,
    $backUrl,
    $logoUrl,
    $body,
    [
        'Assinatura e carimbo da Empresa Transportadora',
        'Assinatura do Condutor',
        'Assinatura do Cliente/Remetente',
        'Assinatura do Destinatário',
    ],
    $accentColor
);
exit;
