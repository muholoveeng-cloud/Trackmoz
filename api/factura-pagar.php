<?php
/**
 * API: Confirmar pagamento de factura
 * POST: factura_id, comprovativo_opcional, csrf_token
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

session_start();

$uid = (int)($_SESSION['user_id'] ?? 0);
$tipo = $_SESSION['user_type'] ?? '';

if (!$uid || !in_array($tipo, ['empresa','admin'], true)) {
    echo json_encode(['success' => false, 'message' => 'Não autorizado.']);
    exit;
}

require_csrf_json();

$factura_id = (int)($_POST['factura_id'] ?? 0);
if ($factura_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Factura inválida.']);
    exit;
}

try {
    $conn = getConnection();

    $stmt = $conn->prepare("SELECT * FROM facturas WHERE id = :id");
    $stmt->execute([':id' => $factura_id]);
    $f = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$f) {
        echo json_encode(['success' => false, 'message' => 'Factura não encontrada.']);
        exit;
    }

    if ($tipo === 'empresa' && (int)$f['empresa_id'] !== $uid) {
        echo json_encode(['success' => false, 'message' => 'Sem permissão.']);
        exit;
    }

    if ($f['status'] !== 'emitida' && $f['status'] !== 'pendente') {
        echo json_encode(['success' => false, 'message' => 'Estado da factura não permite pagamento.']);
        exit;
    }

    $comprovativo = null;
    if (!empty($_FILES['comprovativo']) && $_FILES['comprovativo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['comprovativo']['name'], PATHINFO_EXTENSION));
        $nome = 'pagamento_' . $factura_id . '_' . time() . '.' . $ext;
        $dir = __DIR__ . '/../uploads/comprovativos/';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        if (move_uploaded_file($_FILES['comprovativo']['tmp_name'], $dir . $nome)) {
            $comprovativo = $nome;
        }
    }

    $conn->prepare("UPDATE facturas SET status = 'paga', data_pagamento = NOW(), comprovativo = :comp WHERE id = :id")
         ->execute([':id' => $factura_id, ':comp' => $comprovativo]);

    $conn->prepare("UPDATE pagamentos_missao SET status = 'pago', data_pagamento = NOW() WHERE factura_id = :fid")
         ->execute([':fid' => $factura_id]);

    // Notificar transportador
    $conn->prepare(
        "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link)
         VALUES (:uid, 'pagamento', 'Pagamento Confirmado', :msg, '')"
    )->execute([
        ':uid' => $f['transportador_id'],
        ':msg' => "O pagamento da factura {$f['numero_factura']} foi confirmado. Valor: " . number_format((float)$f['valor_total'], 2, ',', '.') . " MT."
    ]);

    registrar_log($conn, $uid, 'actualizar', 'factura', $factura_id, 'Pagamento confirmado');
    echo json_encode(['success' => true, 'message' => 'Pagamento confirmado com sucesso.']);

} catch (Throwable $e) {
    error_log('factura-pagar: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno.']);
}
