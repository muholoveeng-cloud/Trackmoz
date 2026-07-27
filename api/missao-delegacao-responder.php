<?php
/**
 * API: Transportador aceita ou recusa missão delegada
 * POST: missao_id, acao (aceitar|recusar), motivo?, csrf_token
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

session_start();

function json_out(bool $ok, string $msg = '', array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'transportador') {
    json_out(false, 'Não autorizado.');
}

require_csrf_json();

$transportador_id = (int)$_SESSION['user_id'];
$missao_id        = isset($_POST['missao_id']) ? (int)$_POST['missao_id'] : 0;
$acao             = $_POST['acao'] ?? '';
$motivo           = trim($_POST['motivo'] ?? '');

if ($missao_id <= 0 || !in_array($acao, ['aceitar','recusar'], true)) {
    json_out(false, 'Dados inválidos.');
}

try {
    $conn = getConnection();

    $stmt = $conn->prepare(
        "SELECT id, titulo, status, empresa_id, transportador_id, parceria_id, caminhoneiro_id
         FROM missoes WHERE id = :mid AND transportador_id = :tid"
    );
    $stmt->execute([':mid' => $missao_id, ':tid' => $transportador_id]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$missao) {
        json_out(false, 'Missão não encontrada ou não delegada a si.');
    }

    if (!in_array($missao['status'], ['aceita','em_andamento'], true)) {
        json_out(false, 'Não é possível responder a esta missão no estado actual.');
    }

    if ($acao === 'aceitar') {
        // Transportador aceita — missão continua aceita
        $conn->prepare("UPDATE missoes SET ultima_atualizacao = NOW() WHERE id = :mid")
             ->execute([':mid' => $missao_id]);

        // Notificar empresa
        notificar_usuario($conn, (int)$missao['empresa_id'], 'parceria',
            'Missão aceita pelo transportador',
            "O transportador aceitou a missão '{$missao['titulo']}' (#{$missao_id}).",
            BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id
        );

        registrar_log($conn, $transportador_id, 'aceitar_delegacao', 'missao', $missao_id,
            "Transportador #{$transportador_id} aceitou missão delegada #{$missao_id}"
        );

        json_out(true, 'Missão aceita. Pode agora atribuir um motorista e iniciar a condução.');
    } else {
        // Recusar — desvincular transportador e parceria
        $conn->prepare(
            "UPDATE missoes SET
                transportador_id = NULL,
                parceria_id = NULL,
                status = 'aberta',
                ultima_atualizacao = NOW()
             WHERE id = :mid"
        )->execute([':mid' => $missao_id]);

        // Notificar empresa
        $msgMotivo = $motivo ? " Motivo: {$motivo}" : '';
        notificar_usuario($conn, (int)$missao['empresa_id'], 'parceria',
            'Missão recusada pelo transportador',
            "O transportador recusou a missão '{$missao['titulo']}' (#{$missao_id}).{$msgMotivo}",
            BASE_URL . '/pages/contratante/detalhes-missao.php?id=' . $missao_id
        );

        registrar_log($conn, $transportador_id, 'recusar_delegacao', 'missao', $missao_id,
            "Transportador #{$transportador_id} recusou missão delegada #{$missao_id}. Motivo: {$motivo}",
            ['transportador_id' => $missao['transportador_id'], 'parceria_id' => $missao['parceria_id']]
        );

        json_out(true, 'Missão recusada. A missão voltou ao estado aberto para nova atribuição.');
    }

} catch (Throwable $e) {
    error_log('missao-delegacao-responder: ' . $e->getMessage());
    json_out(false, 'Erro interno.');
}
