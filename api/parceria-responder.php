<?php
/**
 * API: Responder a parceria (aceitar, recusar, contra-propor, aprovar)
 * POST: parceria_id, acao, [campos da contra-proposta], csrf_token
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/documentos-registry.php';

session_start();

$uid = (int)($_SESSION['user_id'] ?? 0);
$tipo = $_SESSION['user_type'] ?? '';

if (!$uid || !in_array($tipo, ['empresa', 'transportador'], true)) {
    echo json_encode(['success' => false, 'message' => 'Não autorizado.']);
    exit;
}

require_csrf_json();

$parceria_id = (int)($_POST['parceria_id'] ?? 0);
$acao = $_POST['acao'] ?? '';

if ($parceria_id <= 0 || !in_array($acao, ['aceitar','recusar','contra_propor','aprovar'], true)) {
    echo json_encode(['success' => false, 'message' => 'Dados inválidos.']);
    exit;
}

try {
    $conn = getConnection();

    $stmt = $conn->prepare("SELECT * FROM parcerias WHERE id = :id");
    $stmt->execute([':id' => $parceria_id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$p) {
        echo json_encode(['success' => false, 'message' => 'Parceria não encontrada.']);
        exit;
    }

    // Verificar permissão
    if ($tipo === 'empresa' && (int)$p['empresa_id'] !== $uid) {
        echo json_encode(['success' => false, 'message' => 'Sem permissão.']);
        exit;
    }
    if ($tipo === 'transportador' && (int)$p['transportador_id'] !== $uid) {
        echo json_encode(['success' => false, 'message' => 'Sem permissão.']);
        exit;
    }

    switch ($acao) {
        case 'recusar':
            $motivo = trim($_POST['motivo'] ?? '');
            $conn->prepare("UPDATE parcerias SET status = 'cancelada', motivo_rejeicao = :mot, data_atualizacao = NOW() WHERE id = :id")
                 ->execute([':id' => $parceria_id, ':mot' => $motivo ?: null]);

            $conn->prepare(
                "INSERT INTO parceria_negociacoes (parceria_id, proposto_por, proposto_por_usuario_id, versao, campo_alterado, valor_novo, comentario)
                 VALUES (:pid, :quem, :uid, :ver, 'recusa', :mot, :com)"
            )->execute([':pid' => $parceria_id, ':quem' => $tipo, ':uid' => $uid, ':ver' => (int)$p['versao_contrato'], ':mot' => $motivo, ':com' => 'Parceria recusada']);

            notificar_outro($conn, $p, $tipo, 'Parceria Cancelada', 'A parceria foi cancelada.' . ($motivo ? ' Motivo: ' . $motivo : ''));
            registrar_log($conn, $uid, 'cancelar', 'parceria', $parceria_id, 'Parceria recusada/cancelada');
            echo json_encode(['success' => true, 'message' => 'Parceria cancelada.']);
            break;

        case 'contra_propor':
            $camposAlterados = [];
            $updates = [];
            $params = [':id' => $parceria_id];

            $camposPossiveis = [
                'valor_missao','valor_km','valor_mensal','comissao_plataforma_pct',
                'condicoes_pagamento','sla_resposta_horas','penalidade_atraso_pct',
                'responsabilidade_carga','tipos_carga_permitidos','rotas_cobertas',
                'data_fim','tipo_contrato','observacoes_negociacao'
            ];

            foreach ($camposPossiveis as $campo) {
                if (isset($_POST[$campo])) {
                    $valorAnterior = $p[$campo] ?? null;
                    $valorNovo = $_POST[$campo] !== '' ? $_POST[$campo] : null;
                    if ($valorNovo !== $valorAnterior) {
                        $camposAlterados[] = ['campo' => $campo, 'anterior' => $valorAnterior, 'novo' => $valorNovo];
                        $updates[] = "$campo = :$campo";
                        $params[":$campo"] = is_numeric($valorNovo) && strpos($valorNovo, '.') !== false ? (float)$valorNovo : $valorNovo;
                    }
                }
            }

            if (empty($camposAlterados)) {
                echo json_encode(['success' => false, 'message' => 'Nenhuma alteração proposta.']);
                exit;
            }

            $novaVersao = ((int)$p['versao_contrato']) + 1;
            $updates[] = "versao_contrato = :ver";
            $updates[] = "status = 'em_negociacao'";
            $updates[] = "aprovado_por_empresa = 0";
            $updates[] = "aprovado_por_transportador = 0";
            $updates[] = "data_atualizacao = NOW()";
            $params[':ver'] = $novaVersao;

            $conn->prepare("UPDATE parcerias SET " . implode(', ', $updates) . " WHERE id = :id")
                 ->execute($params);

            foreach ($camposAlterados as $alt) {
                $conn->prepare(
                    "INSERT INTO parceria_negociacoes (parceria_id, proposto_por, proposto_por_usuario_id, versao, campo_alterado, valor_anterior, valor_novo, comentario)
                     VALUES (:pid, :quem, :uid, :ver, :campo, :ant, :nov, :com)"
                )->execute([
                    ':pid' => $parceria_id, ':quem' => $tipo, ':uid' => $uid, ':ver' => $novaVersao,
                    ':campo' => $alt['campo'], ':ant' => $alt['anterior'], ':nov' => $alt['novo'],
                    ':com' => trim($_POST['comentario'] ?? '') ?: 'Contra-proposta enviada'
                ]);
            }

            notificar_outro($conn, $p, $tipo, 'Contra-proposta Recebida', 'Recebeu uma contra-proposta para a parceria. Revise os novos termos.');
            registrar_log($conn, $uid, 'actualizar', 'parceria', $parceria_id, 'Contra-proposta versao ' . $novaVersao);
            echo json_encode(['success' => true, 'message' => 'Contra-proposta enviada.']);
            break;

        case 'aceitar':
            if ($tipo === 'transportador' && $p['status'] === 'pedido_enviado') {
                // Primeira aceitação (simples, antes da negociação)
                $conn->prepare("UPDATE parcerias SET status = 'aguardando_aprovacao_empresa', aprovado_por_transportador = 1, data_aprovacao_transportador = NOW(), data_atualizacao = NOW() WHERE id = :id")
                     ->execute([':id' => $parceria_id]);

                $conn->prepare(
                    "INSERT INTO parceria_negociacoes (parceria_id, proposto_por, proposto_por_usuario_id, versao, campo_alterado, valor_novo, comentario)
                     VALUES (:pid, 'transportador', :uid, :ver, 'aceite', 'sim', 'Transportadora aceitou a proposta inicial')"
                )->execute([':pid' => $parceria_id, ':uid' => $uid, ':ver' => (int)$p['versao_contrato']]);

                notificar_outro($conn, $p, $tipo, 'Parceria Aceita pela Transportadora', 'A transportadora aceitou a proposta inicial. Aprove a parceria para activá-la.');
                registrar_log($conn, $uid, 'aceitar', 'parceria', $parceria_id, 'Transportador aceitou proposta inicial');
                echo json_encode(['success' => true, 'message' => 'Proposta aceite. Aguardando aprovação da contratante.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Não é possível aceitar neste estado.']);
            }
            break;

        case 'aprovar':
            if ($tipo === 'empresa') {
                $conn->prepare("UPDATE parcerias SET aprovado_por_empresa = 1, data_aprovacao_empresa = NOW(), data_atualizacao = NOW() WHERE id = :id")
                     ->execute([':id' => $parceria_id]);

                $conn->prepare(
                    "INSERT INTO parceria_negociacoes (parceria_id, proposto_por, proposto_por_usuario_id, versao, campo_alterado, valor_novo, comentario)
                     VALUES (:pid, 'empresa', :uid, :ver, 'aprovacao_empresa', 'sim', 'Empresa aprovou a parceria')"
                )->execute([':pid' => $parceria_id, ':uid' => $uid, ':ver' => (int)$p['versao_contrato']]);

                // Verificar se ambos aprovaram
                if ((int)$p['aprovado_por_transportador'] === 1) {
                    verificar_ativar($conn, $p, $parceria_id);
                } else {
                    notificar_outro($conn, $p, $tipo, 'Aprovação da Empresa', 'A empresa aprovou os termos. Aguardando a sua aprovação final.');
                }
                registrar_log($conn, $uid, 'aprovar', 'parceria', $parceria_id, 'Empresa aprovou parceria');
                echo json_encode(['success' => true, 'message' => 'Aprovação registada.']);
            } elseif ($tipo === 'transportador') {
                $conn->prepare("UPDATE parcerias SET aprovado_por_transportador = 1, data_aprovacao_transportador = NOW(), data_atualizacao = NOW() WHERE id = :id")
                     ->execute([':id' => $parceria_id]);

                $conn->prepare(
                    "INSERT INTO parceria_negociacoes (parceria_id, proposto_por, proposto_por_usuario_id, versao, campo_alterado, valor_novo, comentario)
                     VALUES (:pid, 'transportador', :uid, :ver, 'aprovacao_transportador', 'sim', 'Transportadora aprovou a parceria')"
                )->execute([':pid' => $parceria_id, ':uid' => $uid, ':ver' => (int)$p['versao_contrato']]);

                if ((int)$p['aprovado_por_empresa'] === 1) {
                    verificar_ativar($conn, $p, $parceria_id);
                } else {
                    notificar_outro($conn, $p, $tipo, 'Aprovação da Transportadora', 'A transportadora aprovou os termos. Aguardando aprovação da contratante.');
                }
                registrar_log($conn, $uid, 'aprovar', 'parceria', $parceria_id, 'Transportador aprovou parceria');
                echo json_encode(['success' => true, 'message' => 'Aprovação registada.']);
            }
            break;
    }

} catch (Throwable $e) {
    error_log('parceria-responder: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno.']);
}

function notificar_outro(PDO $conn, array $p, string $quem, string $titulo, string $mensagem): void {
    $outro_id = $quem === 'empresa' ? (int)$p['transportador_id'] : (int)$p['empresa_id'];
    $conn->prepare(
        "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link)
         VALUES (:uid, 'contrato_negociacao', :tit, :msg, '/trackmoz/pages/" . ($quem === 'empresa' ? 'transportador' : 'contratante') . "/parcerias.php')"
    )->execute([':uid' => $outro_id, ':tit' => $titulo, ':msg' => $mensagem]);
}

function verificar_ativar(PDO $conn, array $p, int $parceria_id): void {
    $requerAdmin = (int)$p['requer_validacao_admin'] === 1;
    if ($requerAdmin) {
        $conn->prepare("UPDATE parcerias SET status = 'aguardando_validacao_admin', data_atualizacao = NOW() WHERE id = :id")
             ->execute([':id' => $parceria_id]);

        // Notificar admin
        $admins = $conn->query("SELECT id FROM usuarios WHERE tipo_usuario = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $aid) {
            $conn->prepare(
                "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link)
                 VALUES (:uid, 'contrato_negociacao', 'Validação de Parceria Pendente',
                 'Uma parceria aguarda validação administrativa.', '/trackmoz/pages/admin/parcerias.php')"
            )->execute([':uid' => $aid]);
        }

        // Notificar empresa
        $conn->prepare(
            "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link)
             VALUES (:uid, 'contrato_negociacao', 'Parceria Aguardando Validação',
             'Ambas as partes aprovaram. A parceria aguarda validação do administrador.', '/trackmoz/pages/contratante/parcerias.php')"
        )->execute([':uid' => (int)$p['empresa_id']]);

        $conn->prepare(
            "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link)
             VALUES (:uid, 'contrato_negociacao', 'Parceria Aguardando Validação',
             'Ambas as partes aprovaram. A parceria aguarda validação do administrador.', '/trackmoz/pages/transportador/parcerias.php')"
        )->execute([':uid' => (int)$p['transportador_id']]);
    } else {
        $conn->prepare("UPDATE parcerias SET status = 'ativa', data_atualizacao = NOW() WHERE id = :id")
             ->execute([':id' => $parceria_id]);

        try {
            tmz_docs_criar_contrato_parceria($conn, $p, (int)$p['empresa_id']);
        } catch (Throwable $e) {
            error_log('Doc contrato_parceria ao activar #' . $parceria_id . ': ' . $e->getMessage());
        }

        $conn->prepare(
            "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link)
             VALUES (:uid, 'contrato_aprovado', 'Parceria Activada',
             'A parceria foi activada. Pode começar a publicar/receber missões.', '/trackmoz/pages/contratante/parcerias.php')"
        )->execute([':uid' => (int)$p['empresa_id']]);

        $conn->prepare(
            "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link)
             VALUES (:uid, 'contrato_aprovado', 'Parceria Activada',
             'A parceria foi activada. Pode começar a receber missões.', '/trackmoz/pages/transportador/parcerias.php')"
        )->execute([':uid' => (int)$p['transportador_id']]);
    }
}
