<?php
/**
 * API: Admin validar/rejeitar parceria
 * POST: parceria_id, acao (validar|rejeitar), motivo, csrf_token
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/documentos-registry.php';

session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Não autorizado.']);
    exit;
}

require_csrf_json();

$parceria_id = (int)($_POST['parceria_id'] ?? 0);
$acao = $_POST['acao'] ?? '';

if ($parceria_id <= 0 || !in_array($acao, ['validar','rejeitar'], true)) {
    echo json_encode(['success' => false, 'message' => 'Dados inválidos.']);
    exit;
}

try {
    $conn = getConnection();

    $stmt = $conn->prepare("SELECT * FROM parcerias WHERE id = :id AND status = 'aguardando_validacao_admin'");
    $stmt->execute([':id' => $parceria_id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$p) {
        echo json_encode(['success' => false, 'message' => 'Parceria não encontrada ou não aguarda validação.']);
        exit;
    }

    $admin_id = (int)$_SESSION['user_id'];

    if ($acao === 'validar') {
        $conn->prepare("UPDATE parcerias SET status = 'ativa', validado_por_admin = 1, data_validacao_admin = NOW(), data_atualizacao = NOW() WHERE id = :id")
             ->execute([':id' => $parceria_id]);

        $conn->prepare(
            "INSERT INTO parceria_negociacoes (parceria_id, proposto_por, proposto_por_usuario_id, versao, campo_alterado, valor_novo, comentario)
             VALUES (:pid, 'admin', :uid, :ver, 'validacao_admin', 'aprovada', 'Admin validou a parceria')"
        )->execute([':pid' => $parceria_id, ':uid' => $admin_id, ':ver' => (int)$p['versao_contrato']]);

        try {
            tmz_docs_criar_contrato_parceria($conn, $p, $admin_id);
        } catch (Throwable $e) {
            error_log('Doc contrato_parceria admin #' . $parceria_id . ': ' . $e->getMessage());
        }

        // Notificar ambas as partes
        foreach ([(int)$p['empresa_id'], (int)$p['transportador_id']] as $uid) {
            $conn->prepare(
                "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link)
                 VALUES (:uid, 'contrato_aprovado', 'Parceria Validada e Activada',
                 'O administrador validou e activou a parceria. Pode começar a operar.', '')"
            )->execute([':uid' => $uid]);
        }

        registrar_log($conn, $admin_id, 'validar', 'parceria', $parceria_id, 'Admin validou parceria');
        echo json_encode(['success' => true, 'message' => 'Parceria validada e activada.']);
    } else {
        $motivo = trim($_POST['motivo'] ?? '');
        $conn->prepare("UPDATE parcerias SET status = 'cancelada', motivo_rejeicao = :mot, data_atualizacao = NOW() WHERE id = :id")
             ->execute([':id' => $parceria_id, ':mot' => $motivo ?: null]);

        $conn->prepare(
            "INSERT INTO parceria_negociacoes (parceria_id, proposto_por, proposto_por_usuario_id, versao, campo_alterado, valor_novo, comentario)
             VALUES (:pid, 'admin', :uid, :ver, 'validacao_admin', 'rejeitada', :com)"
        )->execute([':pid' => $parceria_id, ':uid' => $admin_id, ':ver' => (int)$p['versao_contrato'], ':com' => $motivo ?: 'Admin rejeitou a parceria']);

        foreach ([(int)$p['empresa_id'], (int)$p['transportador_id']] as $uid) {
            $conn->prepare(
                "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link)
                 VALUES (:uid, 'contrato_negociacao', 'Parceria Rejeitada pelo Admin',
                 'O administrador rejeitou a parceria.' . ($motivo ? ' Motivo: ' . $motivo : ''), '')"
            )->execute([':uid' => $uid]);
        }

        registrar_log($conn, $admin_id, 'rejeitar', 'parceria', $parceria_id, 'Admin rejeitou parceria');
        echo json_encode(['success' => true, 'message' => 'Parceria rejeitada.']);
    }

} catch (Throwable $e) {
    error_log('parceria-validar-admin: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno.']);
}
