<?php
/**
 * API: Upload de documento no explorador
 * POST: entidade_tipo, entidade_id, categoria, descricao, arquivo, csrf_token
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

session_start();

$uid = (int)($_SESSION['user_id'] ?? 0);
if (!$uid) {
    echo json_encode(['success' => false, 'message' => 'Não autorizado.']);
    exit;
}

require_csrf_json();

$entidade_tipo = $_POST['entidade_tipo'] ?? '';
$entidade_id = (int)($_POST['entidade_id'] ?? 0);
$categoria = $_POST['categoria'] ?? 'outro';
$descricao = trim($_POST['descricao'] ?? '');

if (empty($entidade_tipo) || $entidade_id <= 0 || empty($_FILES['arquivo'])) {
    echo json_encode(['success' => false, 'message' => 'Dados incompletos.']);
    exit;
}

$perm = [
    'empresa' => ['parceria','missao','factura','motorista','veiculo','empresa'],
    'transportador' => ['parceria','missao','factura','motorista','veiculo','transportador'],
    'motorista' => ['missao','motorista'],
    'admin' => ['parceria','missao','factura','motorista','veiculo','empresa','transportador']
];
$tipo = $_SESSION['user_type'] ?? '';
if (!in_array($entidade_tipo, $perm[$tipo] ?? [], true)) {
    echo json_encode(['success' => false, 'message' => 'Categoria não permitida.']);
    exit;
}

$file = $_FILES['arquivo'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Erro no upload do arquivo.']);
    exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$permitidas = ['pdf','jpg','jpeg','png','doc','docx','xls','xlsx','zip','rar'];
if (!in_array($ext, $permitidas, true)) {
    echo json_encode(['success' => false, 'message' => 'Extensão não permitida.']);
    exit;
}

$nomeUnico = $entidade_tipo . '_' . $entidade_id . '_' . time() . '.' . $ext;
$dir = __DIR__ . '/../uploads/documentos/';
if (!is_dir($dir)) mkdir($dir, 0777, true);

if (!move_uploaded_file($file['tmp_name'], $dir . $nomeUnico)) {
    echo json_encode(['success' => false, 'message' => 'Falha ao mover o arquivo.']);
    exit;
}

try {
    $conn = getConnection();
    $conn->prepare(
        "INSERT INTO documentos_explorador (entidade_tipo, entidade_id, categoria, nome_original, caminho, tamanho, extensao, descricao, uploaded_by)
         VALUES (:etipo, :eid, :cat, :norig, :caminho, :tam, :ext, :desc, :uid)"
    )->execute([
        ':etipo' => $entidade_tipo, ':eid' => $entidade_id, ':cat' => $categoria,
        ':norig' => $file['name'], ':caminho' => $nomeUnico, ':tam' => $file['size'],
        ':ext' => $ext, ':desc' => $descricao ?: null, ':uid' => $uid
    ]);
    $doc_id = (int)$conn->lastInsertId();
    registrar_log($conn, $uid, 'criar', 'documento', $doc_id, "Upload documento {$entidade_tipo}:{$entidade_id}");
    echo json_encode(['success' => true, 'message' => 'Documento enviado com sucesso.', 'documento_id' => $doc_id]);
} catch (Throwable $e) {
    error_log('documento-upload: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro interno.']);
}
