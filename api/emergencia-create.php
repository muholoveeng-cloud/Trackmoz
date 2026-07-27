<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
include_once('../config/app.php');
include_once('../config/database.php');
include_once('../includes/helpers.php');

require_csrf_json();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'caminhoneiro') {
    http_response_code(403);
    echo json_encode(['ok'=>false, 'error'=>'Acesso restrito a caminhoneiros']);
    exit;
}

$uid = (int)$_SESSION['user_id'];
$missao_id = isset($_POST['missao_id']) ? (int)$_POST['missao_id'] : 0;
$tipo = $_POST['tipo'] ?? '';
$descricao = trim($_POST['descricao'] ?? '');
$gravidade = $_POST['gravidade'] ?? 'media';
$latitude  = isset($_POST['latitude'])  ? floatval($_POST['latitude'])  : null;
$longitude = isset($_POST['longitude']) ? floatval($_POST['longitude']) : null;

$tiposValidos = ['acidente','avaria','furo','problema_carga','roubo','saude','fiscalizacao','atraso_grave','outro'];
$gravidadesValidas = ['baixa','media','alta','critica'];

if ($missao_id <= 0 || !in_array($tipo, $tiposValidos, true) || $descricao === '' || !in_array($gravidade, $gravidadesValidas, true)) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'Dados inválidos']);
    exit;
}

try {
    // Verificar missão e permissão
    $stmt = $conn->prepare("SELECT m.*, u.nome AS motorista_nome FROM missoes m JOIN usuarios u ON m.caminhoneiro_id = u.id WHERE m.id = ? AND m.caminhoneiro_id = ?");
    $stmt->execute([$missao_id, $uid]);
    $missao = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$missao) {
        http_response_code(404);
        echo json_encode(['ok'=>false, 'error'=>'Missão não encontrada']);
        exit;
    }

    // Upload de anexo (foto/vídeo/documento)
    $anexo_url = $anexo_tipo = null;
    if (!empty($_FILES['anexo']) && $_FILES['anexo']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['anexo'];
        $maxSize = 20 * 1024 * 1024; // 20MB
        $allowedTypes = ['image/jpeg','image/png','image/gif','image/webp','video/mp4','video/quicktime','application/pdf','application/msword'];
        $allowedExts = ['jpg','jpeg','png','gif','webp','mp4','mov','pdf','doc','docx'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file['size'] > $maxSize) {
            echo json_encode(['ok'=>false, 'error'=>'Ficheiro demasiado grande (máx 20MB)']);
            exit;
        }
        if (!in_array($file['type'], $allowedTypes, true) && !in_array($ext, $allowedExts, true)) {
            echo json_encode(['ok'=>false, 'error'=>'Tipo de ficheiro não permitido']);
            exit;
        }
        $uploadDir = __DIR__ . '/../uploads/emergencias/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $safeName = bin2hex(random_bytes(8)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));
        if (move_uploaded_file($file['tmp_name'], $uploadDir . $safeName)) {
            $anexo_url = BASE_URL . '/uploads/emergencias/' . $safeName;
            $anexo_tipo = $file['type'];
        }
    }

    // Inserir emergência
    $stmt = $conn->prepare("INSERT INTO emergencias (missao_id, caminhoneiro_id, tipo, descricao, gravidade, latitude, longitude, anexo_url, anexo_tipo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$missao_id, $uid, $tipo, $descricao, $gravidade, $latitude, $longitude, $anexo_url, $anexo_tipo]);
    $emergencia_id = (int)$conn->lastInsertId();

    // Atualizar status da missão
    $conn->prepare("UPDATE missoes SET status = 'emergencia_reportada', ultima_atualizacao = NOW() WHERE id = ?")
         ->execute([$missao_id]);

    // Notificar empresa
    $empresaId = (int)($missao['empresa_id'] ?? 0);
    $motoristaNome = $missao['motorista_nome'] ?? 'Motorista';
    $gravidadeLabel = ['baixa'=>'Baixa','media'=>'Média','alta'=>'Alta','critica'=>'Crítica'][$gravidade] ?? $gravidade;
    if ($empresaId) {
        $conn->prepare("INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link, lida) VALUES (?, 'emergencia', ?, ?, ?, 0)")
             ->execute([
                 $empresaId,
                 '🚨 Emergência ' . $gravidadeLabel . ' - ' . str_replace('_', ' ', $tipo),
                 $motoristaNome . ' reportou: ' . mb_substr($descricao, 0, 200),
                 BASE_URL . '/pages/admin/emergencias.php?id=' . $emergencia_id
             ]);
    }

    // Notificar administradores (todos os admins)
    $admins = $conn->query("SELECT id FROM usuarios WHERE tipo_usuario = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($admins as $adminId) {
        $conn->prepare("INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link, lida) VALUES (?, 'emergencia', ?, ?, ?, 0)")
             ->execute([
                 $adminId,
                 '🚨 Emergência ' . $gravidadeLabel . ' na missão #' . $missao_id,
                 $motoristaNome . ': ' . mb_substr($descricao, 0, 200),
                 BASE_URL . '/pages/admin/emergencias.php?id=' . $emergencia_id
             ]);
    }

    echo json_encode(['ok'=>true, 'emergencia_id'=>$emergencia_id, 'message'=>'Emergência reportada. A central e a empresa foram notificadas.']);

} catch (Throwable $e) {
    error_log('emergencia-create.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Erro interno ao registar emergência']);
}
