<?php
session_start();
require_once '../../../config/app.php';
require_once '../../../config/database.php';

require_once '../../../includes/auth.php';
require_once '../../../includes/helpers.php';
require_once '../../../includes/documentos-registry.php';

require_role(['empresa', 'admin'], '../login.php');

$missao_id = isset($_GET['missao']) ? (int)$_GET['missao'] : 0;
$proposta_id = isset($_GET['proposta']) ? (int)$_GET['proposta'] : 0;

if ($missao_id <= 0) {
    http_response_code(400);
    echo 'Parâmetro inválido.';
    exit;
}

try {
    $hasLogoColumn = table_has_column($conn, 'perfil_empresa', 'logo_empresa');
    $logoSelect = $hasLogoColumn ? ', pe.logo_empresa' : ", '' AS logo_empresa";
    $tipo_documento_oficial = 'contrato_transporte';
    $empresa_id_filter = null;
    if (($_SESSION['user_type'] ?? null) === 'empresa') {
        $empresa_id_filter = (int)$_SESSION['user_id'];
    }

    $sql = "SELECT m.*, 
            ue.nome AS nome_empresa_usuario,
            pe.nome_empresa, pe.nuit, pe.endereco, pe.provincia, pe.distrito, pe.cidade {$logoSelect},
            pe.responsavel_legal, pe.telefone_comercial, pe.email_comercial,
            u.nome AS nome_caminhoneiro, u.email AS email_caminhoneiro, u.telefone AS telefone_caminhoneiro, u.foto_perfil,
            pc.tipo_veiculo, pc.placa_veiculo,
            p.id AS proposta_id, p.valor AS valor_proposta, p.observacoes AS observacoes_proposta, p.status AS status_proposta, p.data_criacao AS data_proposta
            FROM missoes m
            JOIN usuarios ue ON ue.id = m.empresa_id
            LEFT JOIN perfil_empresa pe ON pe.usuario_id = m.empresa_id
            LEFT JOIN usuarios u ON u.id = m.caminhoneiro_id
            LEFT JOIN perfil_caminhoneiro pc ON pc.usuario_id = m.caminhoneiro_id
            LEFT JOIN propostas p ON p.missao_id = m.id AND p.status = 'aceita'
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
        error_log('Erro ao registrar emissão (contrato-transporte.php): ' . $e->getMessage());
    }

    if ($proposta_id > 0) {
        $sql = "SELECT p.*, u.nome AS nome_caminhoneiro, u.email AS email_caminhoneiro, u.telefone AS telefone_caminhoneiro
                FROM propostas p
                JOIN usuarios u ON u.id = p.caminhoneiro_id
                WHERE p.id = :proposta_id AND p.missao_id = :missao_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':proposta_id' => $proposta_id,
            ':missao_id' => $missao_id,
        ]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($p) {
            $doc['proposta_id'] = $p['id'];
            $doc['valor_proposta'] = $p['valor'];
            $doc['observacoes_proposta'] = $p['observacoes'];
            $doc['status_proposta'] = $p['status'];
            $doc['data_proposta'] = $p['data_criacao'];
            $doc['nome_caminhoneiro'] = $p['nome_caminhoneiro'];
            $doc['email_caminhoneiro'] = $p['email_caminhoneiro'];
            $doc['telefone_caminhoneiro'] = $p['telefone_caminhoneiro'];
        }
    }

    $codigo = 'CTR-' . str_pad((string)$missao_id, 6, '0', STR_PAD_LEFT);
    $emitido_em = date('d/m/Y H:i');

} catch (PDOException $e) {
    error_log('Erro documento contrato-transporte.php: ' . $e->getMessage());
    http_response_code(500);
    echo 'Erro ao gerar documento.';
    exit;
}
require_once '../../../includes/documento-profissional.php';

$trackingId = tmz_generate_document_id('TRK', $missao_id);
$documentId = tmz_docs_next_number($conn, 'contrato_transporte');
$backUrl = BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id;
$docBrand = tmz_doc_brand_for_missao($conn, $doc);
$logoUrl = $docBrand['logoUrl'];
$accentColor = $docBrand['accent'];
$brand = $docBrand['brand'];

try {
    tmz_docs_register($conn, [
        'titulo' => 'Contrato de Transporte - Missão #' . $missao_id,
        'tipo' => 'contrato_transporte',
        'numero_documento' => $documentId,
        'tracking_id' => $trackingId,
        'status' => 'gerado',
        'data_emissao' => date('Y-m-d H:i:s'),
        'url_visualizacao' => BASE_URL . '/pages/contratante/documentos/contrato-transporte.php?missao=' . $missao_id,
        'criado_por' => (int)$_SESSION['user_id'],
        'empresa_id' => (int)$doc['empresa_id'],
        'missao_id' => $missao_id,
        'condutor_id' => !empty($doc['caminhoneiro_id']) ? (int)$doc['caminhoneiro_id'] : null,
        'viatura_ref' => trim((string)($doc['placa_veiculo'] ?? '')),
        'payload_json' => json_encode([
            'origem' => $doc['origem'] ?? null,
            'destino' => $doc['destino'] ?? null,
            'valor' => $doc['valor_proposta'] ?? $doc['valor'] ?? null,
        ], JSON_UNESCAPED_UNICODE),
    ]);
} catch (Throwable $e) {
    error_log('registro contrato_transporte: ' . $e->getMessage());
}

ob_start();
?>
<?php echo tmz_html_empresa_emissora($brand, 'Dados da Empresa Contratante'); ?>

<div class="doc-section">
    <h6>Dados do Motorista/Transportador</h6>
    <div class="kv"><span class="k">Nome:</span> <?php echo e(tmz_safe_text($doc['nome_caminhoneiro'] ?? null)); ?></div>
    <div class="kv"><span class="k">Documento/ID:</span> <?php echo e('Pendente de preenchimento manual'); ?></div>
    <div class="kv"><span class="k">Telefone:</span> <?php echo e(tmz_safe_text($doc['telefone_caminhoneiro'] ?? null)); ?></div>
    <div class="kv"><span class="k">Veículo:</span> <?php echo e(tmz_safe_text($doc['tipo_veiculo'] ?? null)); ?></div>
    <div class="kv"><span class="k">Matrícula:</span> <?php echo e(tmz_safe_text($doc['placa_veiculo'] ?? null)); ?></div>
</div>

<div class="doc-section">
    <h6>Dados da Carga e Rota</h6>
    <div class="kv"><span class="k">Missão:</span> <?php echo e(tmz_safe_text($doc['titulo'] ?? null)); ?></div>
    <div class="kv"><span class="k">Tipo de carga:</span> <?php echo e(tmz_safe_text($doc['tipo_carga'] ?? null)); ?></div>
    <div class="kv"><span class="k">Peso estimado:</span> <?php echo e(tmz_safe_text($doc['peso_carga'] ?? null)); ?></div>
    <div class="kv"><span class="k">Origem:</span> <?php echo e(tmz_safe_text($doc['origem'] ?? null)); ?></div>
    <div class="kv"><span class="k">Destino:</span> <?php echo e(tmz_safe_text($doc['destino'] ?? null)); ?></div>
</div>

<div class="doc-section">
    <h6>Condições Financeiras e Contratuais</h6>
    <div class="kv"><span class="k">Preço acordado:</span> <?php echo e(tmz_doc_money($doc['valor_proposta'] ?? $doc['valor'] ?? null)); ?></div>
    <div class="kv"><span class="k">Prazo de entrega:</span> <?php echo e(tmz_doc_date($doc['prazo_entrega'] ?? null)); ?></div>
    <div class="kv"><span class="k">Condição de pagamento:</span> Pagamento conforme termo contratual (placeholder legal).</div>
    <div class="kv"><span class="k">Observações:</span> <?php echo e(tmz_safe_text($doc['observacoes_proposta'] ?? null)); ?></div>
    <div class="doc-note mt-2">
        Este contrato representa aceite digital da missão e deve ser validado pelas assinaturas das partes.
    </div>
</div>
<?php
$body = ob_get_clean();

tmz_render_document_page(
    'Contrato de Transporte',
    'Documento contratual de prestação de serviço logístico',
    $documentId,
    $trackingId,
    $backUrl,
    $logoUrl,
    $body,
    [
        'Assinatura e carimbo da Empresa Transportadora',
        'Assinatura do Cliente/Remetente',
        'Assinatura do Condutor',
        'Assinatura do Destinatário',
    ],
    $accentColor
);
exit;
