<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

include_once('../config/app.php');
include_once('../config/database.php');
include_once('../includes/helpers.php');
include_once('../includes/chat-helpers.php');

chat_garantir_colunas_anexo($conn);

if (!isset($_SESSION['user_id'])) {
    chat_json_erro('Não autenticado', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    chat_json_erro('Método inválido', 405);
}

require_csrf_json();

$user_id    = (int)$_SESSION['user_id'];
$contato_id = isset($_POST['user'])    ? (int)$_POST['user']    : 0;
$missao_id  = chat_normalizar_missao_id($_POST['missao'] ?? null);
$mensagem   = isset($_POST['mensagem']) ? trim($_POST['mensagem']) : '';

$acesso = chat_validar_acesso($conn, $user_id, $contato_id, $missao_id);
if (!$acesso['ok']) {
    chat_json_erro($acesso['error'], $acesso['code'] ?? 403);
}

$anexo_url = $anexo_nome = $anexo_tipo = null;
$hasAnexo  = chat_coluna_existe($conn, 'mensagens', 'anexo_url');

if ($hasAnexo && !empty($_FILES['anexo']) && $_FILES['anexo']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['anexo'];
    $maxSize = 10 * 1024 * 1024;
    $allowedTypes = ['image/jpeg','image/png','image/gif','image/webp','application/pdf',
                     'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                     'application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                     'text/plain'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','txt'];

    if ($file['size'] > $maxSize) {
        chat_json_erro('Ficheiro demasiado grande (máx 10MB)', 413);
    }
    if (!in_array($file['type'], $allowedTypes, true) && !in_array($ext, $allowedExts, true)) {
        chat_json_erro('Tipo de ficheiro não permitido', 415);
    }

    $uploadDir = __DIR__ . '/../uploads/chat/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            error_log('chat-send: não foi possível criar uploads/chat/');
            chat_json_erro('Erro ao preparar pasta de uploads', 500);
        }
    }
    $safeName = bin2hex(random_bytes(8)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));
    $destPath = $uploadDir . $safeName;

    if (move_uploaded_file($file['tmp_name'], $destPath)) {
        $anexo_url   = BASE_URL . '/uploads/chat/' . $safeName;
        $anexo_nome  = basename($file['name']);
        $anexo_tipo  = $file['type'];
    } else {
        error_log('chat-send: move_uploaded_file falhou para ' . $destPath);
        chat_json_erro('Erro ao guardar ficheiro', 500);
    }
}

if ($contato_id <= 0 || ($mensagem === '' && !$anexo_url)) {
    chat_json_erro('Dados inválidos', 400);
}

try {
    $cols = ['remetente_id', 'destinatario_id', 'missao_id', 'mensagem', 'data_envio'];
    $vals = [':rem', ':dest', ':missao_id', ':msg', 'NOW()'];
    $params = [
        ':rem'      => $user_id,
        ':dest'     => $contato_id,
        ':missao_id'=> $missao_id,
        ':msg'      => $mensagem !== '' ? $mensagem : null,
    ];

    if ($hasAnexo) {
        $cols[] = 'anexo_url';
        $cols[] = 'anexo_nome';
        $cols[] = 'anexo_tipo';
        $vals[] = ':aurl';
        $vals[] = ':anome';
        $vals[] = ':atipo';
        $params[':aurl']  = $anexo_url;
        $params[':anome'] = $anexo_nome;
        $params[':atipo'] = $anexo_tipo;
    }

    $sql = 'INSERT INTO mensagens (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $novo_id = (int)$conn->lastInsertId();

    $u1 = min($user_id, $contato_id);
    $u2 = max($user_id, $contato_id);

    $hasConvUltAtual = chat_coluna_existe($conn, 'conversas', 'ultima_atualizacao');
    $hasConvNaoLidas = chat_coluna_existe($conn, 'conversas', 'nao_lidas');

    $stmt = $conn->prepare(
        'SELECT id FROM conversas
         WHERE usuario1_id = :u1 AND usuario2_id = :u2 AND missao_id <=> :missao_id'
    );
    $stmt->execute([':u1' => $u1, ':u2' => $u2, ':missao_id' => $missao_id]);
    $conv_id = $stmt->fetchColumn();

    if ($conv_id) {
        $upd = 'UPDATE conversas SET ';
        $updParts = [];
        $updParams = [':id' => $conv_id];
        if ($hasConvUltAtual) {
            $updParts[] = 'ultima_atualizacao = NOW()';
        }
        if ($hasConvNaoLidas) {
            $updParts[] = 'nao_lidas = nao_lidas + 1';
        }
        if ($updParts) {
            $upd .= implode(', ', $updParts) . ' WHERE id = :id';
            $conn->prepare($upd)->execute($updParams);
        }
    } else {
        $insCols = ['usuario1_id', 'usuario2_id', 'missao_id'];
        $insVals = [':u1', ':u2', ':missao_id'];
        if ($hasConvUltAtual) {
            $insCols[] = 'ultima_atualizacao';
            $insVals[] = 'NOW()';
        }
        if ($hasConvNaoLidas) {
            $insCols[] = 'nao_lidas';
            $insVals[] = '1';
        }
        $conn->prepare(
            'INSERT INTO conversas (' . implode(', ', $insCols) . ') VALUES (' . implode(', ', $insVals) . ')'
        )->execute([':u1' => $u1, ':u2' => $u2, ':missao_id' => $missao_id]);
    }

    try {
        $stmt = $conn->prepare('SELECT nome FROM usuarios WHERE id = :id');
        $stmt->execute([':id' => $user_id]);
        $remetente = $stmt->fetch(PDO::FETCH_ASSOC);
        $conn->prepare(
            'INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem)
             VALUES (:uid, \'mensagem\', \'Nova mensagem\', :txt)'
        )->execute([
            ':uid' => $contato_id,
            ':txt' => 'Mensagem de ' . ($remetente['nome'] ?? 'utilizador'),
        ]);
    } catch (Throwable $e) {
        error_log('chat-send notificacao: ' . $e->getMessage());
    }

    $campos = chat_campos_mensagem($conn);
    $stmt = $conn->prepare(
        "SELECT {$campos['select']}, u.nome AS remetente_nome
         FROM mensagens m
         JOIN usuarios u ON m.remetente_id = u.id
         WHERE m.id = :id"
    );
    $stmt->execute([':id' => $novo_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'      => true,
        'message' => chat_formatar_mensagem(
            $row,
            $user_id,
            $campos['has_anexo'],
            $campos['has_lida']
        ),
    ]);

} catch (Throwable $e) {
    error_log('chat-send.php: ' . $e->getMessage());
    chat_json_erro('Erro interno ao enviar mensagem', 500);
}
