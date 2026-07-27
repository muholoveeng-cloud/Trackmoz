<?php
/**
 * API: Criar parceria profissional (empresa contratante)
 * POST com todos os campos do contrato
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/parceria-helpers.php';

session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'empresa') {
    echo json_encode(['success' => false, 'message' => 'Não autorizado.']);
    exit;
}

require_csrf_json();

$empresa_id = (int)$_SESSION['user_id'];
$transportador_id = (int)($_POST['transportador_id'] ?? 0);

if ($transportador_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Transportadora inválida.']);
    exit;
}

try {
    $conn = getConnection();

    // Verificar se já existe parceria activa/pendente
    $chk = $conn->prepare("SELECT id FROM parcerias WHERE empresa_id = :eid AND transportador_id = :tid AND status IN ('rascunho','pedido_enviado','em_negociacao','aguardando_aprovacao_empresa','aguardando_aprovacao_transportador','aguardando_validacao_admin','ativa')");
    $chk->execute([':eid' => $empresa_id, ':tid' => $transportador_id]);
    if ($chk->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Já existe uma parceria activa ou em negociação com esta transportadora.']);
        exit;
    }

    $dados = [
        ':eid' => $empresa_id,
        ':tid' => $transportador_id,
        ':inicio' => $_POST['data_inicio'] ?? date('Y-m-d'),
        ':fim' => !empty($_POST['data_fim']) ? $_POST['data_fim'] : null,
        ':tipo_contrato' => $_POST['tipo_contrato'] ?? 'por_missao',
        ':valor_missao' => $_POST['valor_missao'] !== '' ? (float)$_POST['valor_missao'] : null,
        ':valor_km' => $_POST['valor_km'] !== '' ? (float)$_POST['valor_km'] : null,
        ':valor_mensal' => $_POST['valor_mensal'] !== '' ? (float)$_POST['valor_mensal'] : null,
        ':comissao' => $_POST['comissao_plataforma_pct'] !== '' ? (float)$_POST['comissao_plataforma_pct'] : 0,
        ':cond_pag' => $_POST['condicoes_pagamento'] ?? '30_dias',
        ':sla' => (int)($_POST['sla_resposta_horas'] ?? 24),
        ':penalidade' => $_POST['penalidade_atraso_pct'] !== '' ? (float)$_POST['penalidade_atraso_pct'] : 0,
        ':resp_carga' => $_POST['responsabilidade_carga'] ?? 'seguro',
        ':tipos_carga' => $_POST['tipos_carga_permitidos'] ?? null,
        ':rotas' => $_POST['rotas_cobertas'] ?? null,
        ':desc' => $_POST['descricao'] ?? null,
        ':obs' => $_POST['observacoes_negociacao'] ?? null,
        ':req_admin' => isset($_POST['requer_validacao_admin']) ? 1 : 0,
    ];

    $conn->prepare(
        "INSERT INTO parcerias
         (empresa_id, transportador_id, data_inicio, data_fim, tipo_contrato,
          valor_missao, valor_km, valor_mensal, comissao_plataforma_pct,
          condicoes_pagamento, sla_resposta_horas, penalidade_atraso_pct,
          responsabilidade_carga, tipos_carga_permitidos, rotas_cobertas,
          descricao, observacoes_negociacao, status, proposto_por, requer_validacao_admin,
          aprovado_por_empresa, data_criacao)
         VALUES
         (:eid, :tid, :inicio, :fim, :tipo_contrato,
          :valor_missao, :valor_km, :valor_mensal, :comissao,
          :cond_pag, :sla, :penalidade,
          :resp_carga, :tipos_carga, :rotas,
          :desc, :obs, 'pedido_enviado', 'empresa', :req_admin,
          1, NOW())"
    )->execute($dados);

    $parceria_id = (int)$conn->lastInsertId();

    // Histórico da negociação (snapshot limpo)
    $snapshot = parceria_snapshot_de_binds($dados);
    $conn->prepare(
        "INSERT INTO parceria_negociacoes (parceria_id, proposto_por, proposto_por_usuario_id, versao, campo_alterado, valor_novo, comentario)
         VALUES (:pid, 'empresa', :uid, 1, 'criacao', :json, :obs)"
    )->execute([
        ':pid'  => $parceria_id,
        ':uid'  => $empresa_id,
        ':json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
        ':obs'  => 'Proposta inicial de parceria',
    ]);

    // Notificar transportador
    $emp = $conn->prepare("SELECT nome_empresa FROM perfil_empresa WHERE usuario_id = :id");
    $emp->execute([':id' => $empresa_id]);
    $nome_emp = $emp->fetchColumn() ?: 'Uma empresa';

    $conn->prepare(
        "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link)
         VALUES (:uid, 'parceria', 'Nova Proposta de Parceria',
         :msg, '/trackmoz/pages/transportador/parcerias.php')"
    )->execute([
        ':uid' => $transportador_id,
        ':msg' => $nome_emp . ' enviou uma proposta de parceria profissional com condições comerciais. Revise e responda.',
    ]);

    registrar_log($conn, $empresa_id, 'criar', 'parceria', $parceria_id, 'Parceria profissional criada');

    echo json_encode(['success' => true, 'message' => 'Proposta de parceria enviada.', 'parceria_id' => $parceria_id]);

} catch (Throwable $e) {
    error_log('parceria-criar: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno.']);
}
