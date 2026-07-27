<?php
/**
 * API: Transportador aceitar ou recusar missão recebida via parceria
 * POST: missao_id, acao (aceitar|recusar), [motivo], csrf_token
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/penalizacoes-helpers.php';

session_start();

$uid = (int)($_SESSION['user_id'] ?? 0);
if (!$uid || ($_SESSION['user_type'] ?? '') !== 'transportador') {
    echo json_encode(['success' => false, 'message' => 'Não autorizado.']);
    exit;
}

require_csrf_json();

$missao_id = (int)($_POST['missao_id'] ?? 0);
$acao = $_POST['acao'] ?? '';

if ($missao_id <= 0 || !in_array($acao, ['aceitar','recusar'], true)) {
    echo json_encode(['success' => false, 'message' => 'Dados inválidos.']);
    exit;
}

try {
    $conn = getConnection();

    $stmt = $conn->prepare("SELECT * FROM missoes WHERE id = :id AND transportador_id = :tid AND status = 'aguardando_aceitacao_transportadora'");
    $stmt->execute([':id' => $missao_id, ':tid' => $uid]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) {
        echo json_encode(['success' => false, 'message' => 'Missão não encontrada ou já respondida.']);
        exit;
    }

    if ($acao === 'aceitar') {
        $conn->prepare("UPDATE missoes SET status = 'aceita', data_atualizacao = NOW(), data_atribuicao_transportador = NOW() WHERE id = :id")
             ->execute([':id' => $missao_id]);

        if (coluna_existe($conn, 'usuarios', 'recusas_consecutivas')) {
            $conn->prepare('UPDATE usuarios SET recusas_consecutivas = 0 WHERE id = :id')
                ->execute([':id' => $uid]);
        }

        require_once __DIR__ . '/../includes/notificacoes-helpers.php';
        require_once __DIR__ . '/../config/app.php';
        try {
            notificar_usuario(
                $conn,
                (int)$missao['empresa_id'],
                'missao',
                'Missão Aceita',
                'A transportadora aceitou a missão "' . $missao['titulo'] . '". Aguardando atribuição de motorista e viatura.',
                (defined('BASE_URL') ? BASE_URL : '') . '/pages/contratante/detalhes-missao.php?id=' . $missao_id
            );
        } catch (Throwable $e) {
            error_log('notif aceitar missao: ' . $e->getMessage());
        }

        registrar_log($conn, $uid, 'aceitar', 'missao', $missao_id, 'Transportador aceitou missao via parceria');
        echo json_encode(['success' => true, 'message' => 'Missão aceita com sucesso.']);
    } else {
        $motivo = trim($_POST['motivo'] ?? '');
        $conn->prepare("UPDATE missoes SET status = 'recusada_pelo_transportador', data_atualizacao = NOW(), motivo_rejeicao = :mot WHERE id = :id")
             ->execute([':id' => $missao_id, ':mot' => $motivo ?: null]);

        $conn->prepare(
            "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link)
             VALUES (:uid, 'missao', 'Missão Recusada', :msg, '/trackmoz/pages/contratante/detalhes-missao.php?id=:mid')"
        )->execute([
            ':uid' => $missao['empresa_id'],
            ':msg' => 'A transportadora recusou a missão "' . $missao['titulo'] . '".' . ($motivo ? ' Motivo: ' . $motivo : ''),
            ':mid' => $missao_id
        ]);

        registrar_log($conn, $uid, 'recusar', 'missao', $missao_id, 'Transportador recusou missao');
        penalizacao_registar_recusa($conn, $uid, $missao_id, $motivo);
        echo json_encode(['success' => true, 'message' => 'Missão recusada.']);
    }
} catch (Throwable $e) {
    error_log('missao-transportador-responder: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno.']);
}
