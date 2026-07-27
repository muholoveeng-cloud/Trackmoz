<?php
/**
 * API: Sugerir / aplicar actualização de status por geofence.
 * POST: missao_id, acao=recolha|entrega|acompanhar, aplicar=0|1
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

include_once(__DIR__ . '/../config/database.php');
include_once(__DIR__ . '/../config/app.php');
include_once(__DIR__ . '/../includes/ops-automation-helpers.php');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Não autenticado']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$missaoId = (int)($input['missao_id'] ?? 0);
$acao = $input['acao'] ?? '';
$aplicar = !empty($input['aplicar']);

if ($missaoId < 1 || !in_array($acao, ['recolha', 'entrega', 'acompanhar'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Parâmetros inválidos']);
    exit;
}

try {
    $stmt = $conn->prepare('SELECT * FROM missoes WHERE id = ? LIMIT 1');
    $stmt->execute([$missaoId]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Missão não encontrada']);
        exit;
    }

    $okScope = $user_type === 'admin'
        || ((int)($m['empresa_id'] ?? 0) === $user_id)
        || ((int)($m['transportador_id'] ?? 0) === $user_id)
        || ((int)($m['caminhoneiro_id'] ?? 0) === $user_id);
    if (!$okScope) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Sem permissão']);
        exit;
    }

    if ($acao === 'acompanhar') {
        $_SESSION['ops_watch_' . $missaoId] = time();
        echo json_encode(['ok' => true, 'message' => 'Missão em acompanhamento']);
        exit;
    }

    $cols = $conn->query('SHOW COLUMNS FROM missoes')->fetchAll(PDO::FETCH_COLUMN);
    $hasStatusViagem = in_array('status_viagem', $cols, true);

    if (!$aplicar) {
        echo json_encode([
            'ok' => true,
            'preview' => true,
            'novo_status_viagem' => $acao === 'recolha' ? 'coleta' : 'em_entrega',
            'message' => $acao === 'recolha'
                ? 'Sugerido: marcar em recolha'
                : 'Sugerido: marcar em entrega',
        ]);
        exit;
    }

    if ($hasStatusViagem) {
        $sv = $acao === 'recolha' ? 'coleta' : 'em_entrega';
        $st = $acao === 'entrega' ? 'em_entrega' : ($m['status'] === 'aberta' ? 'em_andamento' : $m['status']);
        $up = $conn->prepare('UPDATE missoes SET status_viagem = :sv, status = :st WHERE id = :id');
        $up->execute([':sv' => $sv, ':st' => $st, ':id' => $missaoId]);
    } else {
        $st = $acao === 'entrega' ? 'em_entrega' : 'em_andamento';
        $up = $conn->prepare('UPDATE missoes SET status = :st WHERE id = :id');
        $up->execute([':st' => $st, ':id' => $missaoId]);
    }

    echo json_encode(['ok' => true, 'message' => 'Estado actualizado', 'aplicado' => true]);
} catch (Throwable $e) {
    error_log('ops-geofence-acao: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erro interno']);
}
