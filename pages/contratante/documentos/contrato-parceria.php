<?php
session_start();
require_once '../../../config/app.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth.php';
require_once '../../../includes/helpers.php';
require_once '../../../includes/documentos-registry.php';
require_once '../../../includes/documento-profissional.php';

require_role(['empresa', 'transportador', 'admin'], '../login.php');

$parceria_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($parceria_id <= 0) {
    http_response_code(400);
    echo 'Parâmetro inválido.';
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$userType = (string)($_SESSION['user_type'] ?? '');

try {
    $stmt = $conn->prepare(
        "SELECT p.*,
                pe.nome_empresa AS nome_empresa_contratante,
                pe.nuit AS nuit_empresa,
                pe.endereco AS endereco_empresa,
                pe.responsavel_legal AS responsavel_empresa,
                pt.nome_empresa AS nome_transportadora,
                ue.email AS email_empresa,
                ue.telefone AS tel_empresa,
                ut.email AS email_transportador,
                ut.telefone AS tel_transportador,
                ut.nome AS nome_user_transportador
         FROM parcerias p
         LEFT JOIN perfil_empresa pe ON pe.usuario_id = p.empresa_id
         LEFT JOIN perfil_transportador pt ON pt.usuario_id = p.transportador_id
         LEFT JOIN usuarios ue ON ue.id = p.empresa_id
         LEFT JOIN usuarios ut ON ut.id = p.transportador_id
         WHERE p.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $parceria_id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$p) {
        http_response_code(404);
        echo 'Parceria não encontrada.';
        exit;
    }

    if ($userType === 'empresa' && (int)$p['empresa_id'] !== $userId) {
        http_response_code(403);
        echo 'Sem permissão.';
        exit;
    }
    if ($userType === 'transportador' && (int)$p['transportador_id'] !== $userId) {
        http_response_code(403);
        echo 'Sem permissão.';
        exit;
    }

    // Garantir registo no explorador
    try {
        tmz_docs_criar_contrato_parceria($conn, $p, $userId > 0 ? $userId : (int)$p['empresa_id']);
    } catch (Throwable $e) {
        error_log('contrato-parceria register: ' . $e->getMessage());
    }

    $docRow = tmz_docs_find_by_parceria($conn, 'contrato_parceria', $parceria_id);
    $documentId = (string)($docRow['numero_documento'] ?? ('CPA-' . date('Y') . '-' . str_pad((string)$parceria_id, 5, '0', STR_PAD_LEFT)));
    $trackingId = (string)($docRow['tracking_id'] ?? tmz_generate_document_id('TRK', $parceria_id));

} catch (PDOException $e) {
    error_log('contrato-parceria: ' . $e->getMessage());
    http_response_code(500);
    echo 'Erro ao gerar documento.';
    exit;
}

$backUrl = BASE_URL . '/pages/shared/parceria-detalhes.php?id=' . $parceria_id;
$logoUrl = null;
$accentColor = '#0f766e';
$brand = [
    'nome' => $p['nome_empresa_contratante'] ?? 'Empresa Contratante',
    'nuit' => $p['nuit_empresa'] ?? null,
    'endereco' => $p['endereco_empresa'] ?? null,
    'email' => $p['email_empresa'] ?? null,
];

ob_start();
echo tmz_html_empresa_emissora($brand, 'Empresa Contratante');
?>
<div class="doc-section">
    <h6>Transportadora Parceira</h6>
    <div class="kv"><span class="k">Nome:</span> <?php echo e(tmz_safe_text($p['nome_transportadora'] ?? $p['nome_user_transportador'] ?? null)); ?></div>
    <div class="kv"><span class="k">Contacto:</span> <?php echo e(tmz_safe_text($p['tel_transportador'] ?? null)); ?></div>
    <div class="kv"><span class="k">Email:</span> <?php echo e(tmz_safe_text($p['email_transportador'] ?? null)); ?></div>
</div>

<div class="doc-section">
    <h6>Termos do Contrato de Parceria</h6>
    <div class="kv"><span class="k">Nº Parceria:</span> #<?php echo (int)$parceria_id; ?></div>
    <div class="kv"><span class="k">Estado:</span> <?php echo e(tmz_safe_text($p['status'] ?? null)); ?></div>
    <div class="kv"><span class="k">Tipo de contrato:</span> <?php echo e(tmz_safe_text($p['tipo_contrato'] ?? null)); ?></div>
    <div class="kv"><span class="k">Valor por missão:</span> <?php echo e(tmz_doc_money($p['valor_missao'] ?? null)); ?></div>
    <div class="kv"><span class="k">Valor por km:</span> <?php echo e(tmz_doc_money($p['valor_km'] ?? null)); ?></div>
    <div class="kv"><span class="k">Valor mensal:</span> <?php echo e(tmz_doc_money($p['valor_mensal'] ?? null)); ?></div>
    <div class="kv"><span class="k">Comissão plataforma:</span> <?php echo e(tmz_safe_text(($p['comissao_plataforma_pct'] ?? null) !== null ? ($p['comissao_plataforma_pct'] . '%') : null)); ?></div>
    <div class="kv"><span class="k">Tipos de carga:</span> <?php echo e(tmz_safe_text($p['tipos_carga_permitidos'] ?? null)); ?></div>
    <div class="kv"><span class="k">Rotas cobertas:</span> <?php echo e(tmz_safe_text($p['rotas_cobertas'] ?? null)); ?></div>
    <div class="kv"><span class="k">Responsabilidade carga:</span> <?php echo e(tmz_safe_text($p['responsabilidade_carga'] ?? null)); ?></div>
    <div class="kv"><span class="k">Condições pagamento:</span> <?php echo e(tmz_safe_text($p['condicoes_pagamento'] ?? null)); ?></div>
    <div class="kv"><span class="k">Activada em:</span> <?php echo e(tmz_doc_date($p['data_atualizacao'] ?? $p['data_criacao'] ?? null, 'd/m/Y H:i')); ?></div>
    <?php if (!empty($p['observacoes'])): ?>
        <div class="doc-note mt-2"><?php echo e(tmz_safe_text($p['observacoes'])); ?></div>
    <?php endif; ?>
</div>
<?php echo tmz_doc_qr_html($trackingId, 'Validar contrato de parceria'); ?>
<?php
$body = ob_get_clean();
tmz_render_document_page(
    'Contrato de Parceria',
    'Acordo comercial entre empresa e transportadora no TrackMoz',
    $documentId,
    $trackingId,
    $backUrl,
    $logoUrl,
    $body,
    ['Assinatura da Empresa', 'Assinatura da Transportadora'],
    $accentColor
);
exit;
