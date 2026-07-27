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

$user_id    = (int)$_SESSION['user_id'];
$contato_id = isset($_GET['user'])   ? (int)$_GET['user']   : 0;
$missao_id  = chat_normalizar_missao_id($_GET['missao'] ?? null);
$after_id   = isset($_GET['after'])  ? (int)$_GET['after']  : 0;

$acesso = chat_validar_acesso($conn, $user_id, $contato_id, $missao_id);
if (!$acesso['ok']) {
    chat_json_erro($acesso['error'], $acesso['code'] ?? 403);
}

try {
    $campos = chat_campos_mensagem($conn);

    if ($campos['has_lida']) {
        $stmt = $conn->prepare(
            "UPDATE mensagens SET lida = 1
             WHERE remetente_id = :rem AND destinatario_id = :dest
             AND (missao_id <=> :missao_id)"
        );
        $stmt->execute([':rem' => $contato_id, ':dest' => $user_id, ':missao_id' => $missao_id]);
    }

    $sql = "SELECT {$campos['select']}, u.nome AS remetente_nome
            FROM mensagens m
            JOIN usuarios u ON m.remetente_id = u.id
            WHERE ((m.remetente_id = :uid1 AND m.destinatario_id = :cid1)
                OR (m.remetente_id = :cid2 AND m.destinatario_id = :uid2))
            AND (m.missao_id <=> :missao_id)
            AND m.id > :after_id
            ORDER BY m.data_envio ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':uid1'     => $user_id,
        ':cid1'     => $contato_id,
        ':cid2'     => $contato_id,
        ':uid2'     => $user_id,
        ':missao_id'=> $missao_id,
        ':after_id' => $after_id,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $messages = [];
    foreach ($rows as $row) {
        $messages[] = chat_formatar_mensagem(
            $row,
            $user_id,
            $campos['has_anexo'],
            $campos['has_lida']
        );
    }

    echo json_encode(['ok' => true, 'messages' => $messages]);

} catch (Throwable $e) {
    error_log('chat-messages.php: ' . $e->getMessage());
    chat_json_erro('Erro interno ao carregar mensagens', 500);
}
