<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/checklist-helpers.php';
require_once __DIR__ . '/../includes/notificacoes-helpers.php';

$uid = (int)($_SESSION['user_id'] ?? 0);
if (!$uid || ($_SESSION['user_type'] ?? '') !== 'caminhoneiro') {
    echo json_encode(['success' => false, 'message' => 'Não autorizado.']);
    exit;
}

require_csrf_json();

$missaoId = (int)($_POST['missao_id'] ?? 0);
$fase = trim((string)($_POST['fase'] ?? ''));
$items = $_POST['items'] ?? [];

if ($missaoId <= 0 || !in_array($fase, ['pre_viagem', 'recolha', 'entrega'], true)) {
    echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos.']);
    exit;
}

if (!is_array($items)) {
    $items = [];
}

try {
    $stmt = $conn->prepare('SELECT id FROM missoes WHERE id = :id AND caminhoneiro_id = :uid');
    $stmt->execute([':id' => $missaoId, ':uid' => $uid]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Missão não encontrada.']);
        exit;
    }

    if (checklist_fase_concluida($conn, $missaoId, $fase)) {
        echo json_encode(['success' => true, 'message' => 'Checklist já registado.', 'already' => true]);
        exit;
    }

    $marcados = [];
    foreach (array_keys(checklist_itens($fase)) as $key) {
        $marcados[$key] = !empty($items[$key]);
    }

    $todosMarcados = true;
    foreach ($marcados as $ok) {
        if (!$ok) { $todosMarcados = false; break; }
    }
    if (!$todosMarcados) {
        echo json_encode(['success' => false, 'message' => 'Marque todos os itens do checklist antes de continuar.']);
        exit;
    }

    if (!checklist_registar($conn, $missaoId, $uid, $fase, $marcados)) {
        echo json_encode([
            'success' => false,
            'message' => 'Não foi possível guardar o checklist na base de dados. Tente novamente.',
            'solucao' => 'Recarregue a página. Se o erro persistir, contacte o suporte.',
        ]);
        exit;
    }

    notificar_checklist_missao($conn, $missaoId, $fase);

    echo json_encode(['success' => true, 'message' => checklist_titulo($fase) . ' registado.']);
} catch (Throwable $e) {
    error_log('checklist-viagem: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno ao guardar checklist.']);
}
