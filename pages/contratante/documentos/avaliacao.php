<?php
session_start();
require_once '../../../config/app.php';
require_once '../../../config/database.php';

require_once '../../../includes/auth.php';

require_role(['empresa', 'admin'], '../login.php');

$missao_id = isset($_GET['missao']) ? (int)$_GET['missao'] : 0;
if ($missao_id <= 0) {
    http_response_code(400);
    echo 'Parâmetro inválido.';
    exit;
}

try {
    $tipo_documento_oficial = 'avaliacao';
    $empresa_id_filter = null;
    if (($_SESSION['user_type'] ?? null) === 'empresa') {
        $empresa_id_filter = (int)$_SESSION['user_id'];
    }

    $sql = "SELECT m.*, 
            ue.nome AS nome_empresa_usuario,
            pe.nome_empresa, pe.nuit,
            u.id AS caminhoneiro_id, u.nome AS nome_caminhoneiro, u.telefone AS telefone_caminhoneiro,
            a.id AS avaliacao_id, a.nota, a.comentario, a.data_avaliacao
            FROM missoes m
            JOIN usuarios ue ON ue.id = m.empresa_id
            LEFT JOIN perfil_empresa pe ON pe.usuario_id = m.empresa_id
            LEFT JOIN usuarios u ON u.id = m.caminhoneiro_id
            LEFT JOIN avaliacoes a ON a.missao_id = m.id AND a.avaliador_id = m.empresa_id AND a.avaliado_id = m.caminhoneiro_id
            WHERE m.id = :missao_id";
    if ($empresa_id_filter !== null) {
        $sql .= " AND m.empresa_id = :empresa_id";
    }

    $stmt = $conn->prepare($sql);
    $params = [':missao_id' => $missao_id];
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
        error_log('Erro ao registrar emissão (avaliacao.php): ' . $e->getMessage());
    }

    $codigo = 'AVL-' . str_pad((string)$missao_id, 6, '0', STR_PAD_LEFT);
    $emitido_em = date('d/m/Y H:i');

} catch (PDOException $e) {
    error_log('Erro documento avaliacao.php: ' . $e->getMessage());
    http_response_code(500);
    echo 'Erro ao gerar documento.';
    exit;
}

require_once '../../../includes/documento-profissional.php';
require_once '../../../includes/documentos-registry.php';

$trackingId = tmz_generate_document_id('TRK', $missao_id);
$documentId = tmz_docs_next_number($conn, 'avaliacao');
$backUrl = BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id;
$docBrand = tmz_doc_brand_for_missao($conn, $doc);
$logoUrl = $docBrand['logoUrl'];
$accentColor = $docBrand['accent'];
$brand = $docBrand['brand'];

ob_start();
echo tmz_html_empresa_emissora($brand);
?>
<div class="doc-section">
    <h6>Missão</h6>
    <div class="kv"><span class="k">ID:</span> #<?php echo (int)$missao_id; ?></div>
    <div class="kv"><span class="k">Título:</span> <?php echo e(tmz_safe_text($doc['titulo'] ?? null)); ?></div>
    <div class="kv"><span class="k">Rota:</span> <?php echo e(tmz_safe_text($doc['origem'] ?? null)); ?> → <?php echo e(tmz_safe_text($doc['destino'] ?? null)); ?></div>
    <div class="kv"><span class="k">Status:</span> <?php echo e(tmz_safe_text($doc['status'] ?? null)); ?></div>
</div>
<div class="doc-section">
    <h6>Motorista</h6>
    <div class="kv"><span class="k">Nome:</span> <?php echo e(tmz_safe_text($doc['nome_caminhoneiro'] ?? null)); ?></div>
    <div class="kv"><span class="k">Telefone:</span> <?php echo e(tmz_safe_text($doc['telefone_caminhoneiro'] ?? null)); ?></div>
</div>
<div class="doc-section">
    <h6>Resultado da Avaliação</h6>
    <?php if (!empty($doc['avaliacao_id'])): ?>
        <?php $nota = max(0, min(5, (int)($doc['nota'] ?? 0))); ?>
        <div class="kv"><span class="k">Nota:</span> <?php echo str_repeat('★', $nota) . str_repeat('☆', 5 - $nota); ?> (<?php echo $nota; ?>/5)</div>
        <div class="kv"><span class="k">Comentário:</span> <?php echo nl2br(e($doc['comentario'] ?? '')); ?></div>
        <div class="kv"><span class="k">Data:</span> <?php echo e(tmz_doc_date($doc['data_avaliacao'] ?? null, 'd/m/Y H:i')); ?></div>
    <?php else: ?>
        <div class="doc-note">Nenhuma avaliação registada para esta missão.</div>
    <?php endif; ?>
</div>
<?php echo tmz_doc_qr_html($trackingId); ?>
<?php
$body = ob_get_clean();
tmz_render_document_page(
    'Avaliação da Missão',
    'Registo formal de avaliação de serviço',
    $documentId,
    $trackingId,
    $backUrl,
    $logoUrl,
    $body,
    ['Assinatura da Empresa', 'Assinatura do Motorista'],
    $accentColor
);
exit;
