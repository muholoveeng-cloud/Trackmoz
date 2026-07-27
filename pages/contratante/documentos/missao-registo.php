<?php
session_start();
require_once '../../../config/app.php';
require_once '../../../config/database.php';

require_once '../../../includes/auth.php';
require_once '../../../includes/documento-profissional.php';
require_once '../../../includes/documentos-registry.php';

require_role(['empresa', 'transportador', 'admin'], '../login.php');

$missao_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($missao_id <= 0) {
    http_response_code(400);
    echo 'Parâmetro inválido.';
    exit;
}

try {
    $tipo_documento_oficial = 'missao_registo';
    $userType = (string)($_SESSION['user_type'] ?? '');
    $userId = (int)($_SESSION['user_id'] ?? 0);

    $sql = "SELECT m.*, 
            u.nome AS nome_empresa_usuario,
            u.email AS email_representante,
            u.telefone AS telefone_representante,
            pe.nome_empresa, pe.nuit, pe.endereco, pe.provincia, pe.distrito, pe.cidade,
            pe.responsavel_legal, pe.telefone_comercial, pe.email_comercial
            FROM missoes m
            JOIN usuarios u ON u.id = m.empresa_id
            LEFT JOIN perfil_empresa pe ON pe.usuario_id = m.empresa_id
            WHERE m.id = :id";
    $params = [':id' => $missao_id];
    if ($userType === 'empresa') {
        $sql .= ' AND m.empresa_id = :uid';
        $params[':uid'] = $userId;
    } elseif ($userType === 'transportador') {
        $sql .= ' AND m.transportador_id = :uid';
        $params[':uid'] = $userId;
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) {
        http_response_code(404);
        echo 'Missão não encontrada.';
        exit;
    }

    try {
        $sql = "INSERT INTO documentos_oficiais_missao (missao_id, tipo_documento, emitido_em, emitido_por_usuario_id, bloqueado)
                VALUES (:missao_id, :tipo_documento, NOW(), :emitido_por, 1)
                ON DUPLICATE KEY UPDATE emitido_em = NOW(), emitido_por_usuario_id = :emitido_por, bloqueado = 1";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':missao_id' => $missao_id,
            ':tipo_documento' => $tipo_documento_oficial,
            ':emitido_por' => $userId,
        ]);
    } catch (PDOException $e) {
        error_log('Erro ao registrar emissão (missao-registo.php): ' . $e->getMessage());
    }

    $ids = tmz_docs_number_and_tracking($conn, 'missao_registo', $missao_id, (int)$missao['empresa_id']);
    $documentId = $ids['numero_documento'];
    $trackingId = $ids['tracking_id'];

    try {
        tmz_docs_criar_registo_missao(
            $conn,
            $missao_id,
            (int)$missao['empresa_id'],
            $userId > 0 ? $userId : (int)$missao['empresa_id'],
            [
                'titulo' => $missao['titulo'] ?? null,
                'origem' => $missao['origem'] ?? null,
                'destino' => $missao['destino'] ?? null,
                'valor' => $missao['valor'] ?? null,
            ],
            !empty($missao['transportador_id']) ? (int)$missao['transportador_id'] : null
        );
    } catch (Throwable $e) {
        error_log('registro missao_registo: ' . $e->getMessage());
    }

} catch (PDOException $e) {
    error_log('Erro documento missao-registo.php: ' . $e->getMessage());
    http_response_code(500);
    echo 'Erro ao gerar documento.';
    exit;
}

$backUrl = BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id;
if ($userType === 'transportador') {
    $backUrl = BASE_URL . '/pages/transportador/detalhes-missao.php?id=' . $missao_id;
}
$docBrand = tmz_doc_brand_for_missao($conn, $missao);
$logoUrl = $docBrand['logoUrl'];
$accentColor = $docBrand['accent'];
$brand = $docBrand['brand'];

ob_start();
echo tmz_html_empresa_emissora($brand, 'Empresa Contratante');
?>
<div class="doc-section">
    <h6>Dados da Missão</h6>
    <div class="kv"><span class="k">Título:</span> <?php echo e(tmz_safe_text($missao['titulo'] ?? null)); ?></div>
    <div class="kv"><span class="k">Origem:</span> <?php echo e(tmz_safe_text($missao['origem'] ?? null)); ?></div>
    <div class="kv"><span class="k">Destino:</span> <?php echo e(tmz_safe_text($missao['destino'] ?? null)); ?></div>
    <div class="kv"><span class="k">Tipo de carga:</span> <?php echo e(tmz_safe_text($missao['tipo_carga'] ?? null)); ?></div>
    <div class="kv"><span class="k">Peso:</span> <?php echo e(tmz_safe_text($missao['peso_carga'] ?? null)); ?></div>
    <div class="kv"><span class="k">Valor:</span> <?php echo e(tmz_doc_money($missao['valor'] ?? null)); ?></div>
    <div class="kv"><span class="k">Prazo:</span> <?php echo e(tmz_doc_date($missao['prazo_entrega'] ?? null)); ?></div>
    <div class="kv"><span class="k">Status:</span> <?php echo e(tmz_safe_text($missao['status'] ?? null)); ?></div>
    <div class="kv"><span class="k">Publicada em:</span> <?php echo e(tmz_doc_date($missao['data_criacao'] ?? null, 'd/m/Y H:i')); ?></div>
    <div class="doc-note mt-2"><?php echo e(tmz_safe_text($missao['descricao'] ?? null)); ?></div>
</div>
<?php echo tmz_doc_qr_html($trackingId); ?>
<?php
$body = ob_get_clean();
tmz_render_document_page(
    'Registo da Missão',
    'Prova de publicação/registo no TrackMoz',
    $documentId,
    $trackingId,
    $backUrl,
    $logoUrl,
    $body,
    ['Assinatura da Empresa Contratante'],
    $accentColor
);
exit;
