<?php
/**
 * Helpers partilhados para APIs de chat — compatibilidade de schema e permissões.
 */

if (!function_exists('chat_coluna_existe')) {
    function chat_coluna_existe(PDO $conn, string $table, string $column, bool $forceRefresh = false): bool
    {
        static $cache = [];
        $key = strtolower($table . '.' . $column);
        if (!$forceRefresh && array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            if (function_exists('coluna_existe')) {
                $cache[$key] = coluna_existe($conn, $table, $column);
            } else {
                $stmt = $conn->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
                );
                $stmt->execute([':t' => $table, ':c' => $column]);
                $cache[$key] = ((int)$stmt->fetchColumn() > 0);
            }
        } catch (Throwable $e) {
            error_log('chat_coluna_existe: ' . $e->getMessage());
            $cache[$key] = false;
        }
        return $cache[$key];
    }
}

/** NULL, 0 e string vazia são tratados como “sem missão”. */
function chat_normalizar_missao_id(mixed $missaoId): ?int
{
    if ($missaoId === null || $missaoId === '' || $missaoId === false) {
        return null;
    }
    $id = (int)$missaoId;
    return $id > 0 ? $id : null;
}

function chat_url(int $contactId, mixed $missaoId = null): string
{
    $url = BASE_URL . '/pages/chat.php?user=' . $contactId;
    $mid = chat_normalizar_missao_id($missaoId);
    if ($mid !== null) {
        $url .= '&missao=' . $mid;
    }
    return $url;
}

if (!function_exists('chat_garantir_colunas_anexo')) {
    function chat_garantir_colunas_anexo(PDO $conn): void
    {
        if (chat_coluna_existe($conn, 'mensagens', 'anexo_url')) {
            return;
        }
        try {
            $conn->exec("ALTER TABLE mensagens ADD COLUMN anexo_url VARCHAR(500) DEFAULT NULL");
            $conn->exec("ALTER TABLE mensagens ADD COLUMN anexo_nome VARCHAR(255) DEFAULT NULL");
            $conn->exec("ALTER TABLE mensagens ADD COLUMN anexo_tipo VARCHAR(100) DEFAULT NULL");
            try {
                $conn->exec("ALTER TABLE mensagens MODIFY mensagem TEXT NULL");
            } catch (Throwable $e) {
                // opcional
            }
        } catch (Throwable $e) {
            error_log('chat_garantir_colunas_anexo: ' . $e->getMessage());
            return;
        }
        chat_coluna_existe($conn, 'mensagens', 'anexo_url', true);
        chat_coluna_existe($conn, 'mensagens', 'anexo_nome', true);
        chat_coluna_existe($conn, 'mensagens', 'anexo_tipo', true);
    }
}

/** Corrige conversas antigas com missao_id = 0 em vez de NULL. */
function chat_garantir_schema_conversas(PDO $conn): void
{
    try {
        $conn->exec('UPDATE conversas SET missao_id = NULL WHERE missao_id = 0');
        $conn->exec('UPDATE mensagens SET missao_id = NULL WHERE missao_id = 0');
    } catch (Throwable $e) {
        error_log('chat_garantir_schema_conversas: ' . $e->getMessage());
    }
}

function chat_tem_mensagens_thread(PDO $conn, int $userId, int $contatoId, ?int $missaoId): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM mensagens
         WHERE ((remetente_id = :u1 AND destinatario_id = :c1)
             OR (remetente_id = :c2 AND destinatario_id = :u2))
         AND (missao_id <=> :missao_id)'
    );
    $stmt->execute([
        ':u1'        => $userId,
        ':c1'        => $contatoId,
        ':c2'        => $contatoId,
        ':u2'        => $userId,
        ':missao_id' => $missaoId,
    ]);
    return (int)$stmt->fetchColumn() > 0;
}

function chat_tem_conversa_thread(PDO $conn, int $userId, int $contatoId, ?int $missaoId): bool
{
    $u1 = min($userId, $contatoId);
    $u2 = max($userId, $contatoId);
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM conversas
         WHERE usuario1_id = :u1 AND usuario2_id = :u2 AND (missao_id <=> :missao_id)'
    );
    $stmt->execute([':u1' => $u1, ':u2' => $u2, ':missao_id' => $missaoId]);
    return (int)$stmt->fetchColumn() > 0;
}

function chat_podem_conversar_missao(PDO $conn, int $userId, int $contatoId, int $missaoId): bool
{
    $stmt = $conn->prepare(
        'SELECT empresa_id, caminhoneiro_id, transportador_id FROM missoes WHERE id = :id'
    );
    $stmt->execute([':id' => $missaoId]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) {
        return false;
    }

    $allowed = array_values(array_filter([
        (int)($m['empresa_id'] ?? 0),
        (int)($m['caminhoneiro_id'] ?? 0),
        (int)($m['transportador_id'] ?? 0),
    ]));
    if (in_array($userId, $allowed, true) && in_array($contatoId, $allowed, true)) {
        return true;
    }

    $empresaId = (int)($m['empresa_id'] ?? 0);
    if ($empresaId <= 0) {
        return false;
    }

    // Empresa ↔ caminhoneiro com proposta nesta missão (mesmo pendente)
    $ids = [$userId, $contatoId];
    if (in_array($empresaId, $ids, true)) {
        $outro = $userId === $empresaId ? $contatoId : $userId;
        try {
            $stmt = $conn->prepare(
                'SELECT COUNT(*) FROM propostas
                 WHERE missao_id = :mid AND caminhoneiro_id = :cid'
            );
            $stmt->execute([':mid' => $missaoId, ':cid' => $outro]);
            if ((int)$stmt->fetchColumn() > 0) {
                return true;
            }
        } catch (Throwable $e) {
            error_log('chat propostas: ' . $e->getMessage());
        }
    }

    return false;
}

function chat_tem_parceria(PDO $conn, int $userId, int $contatoId): bool
{
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM parcerias
             WHERE status IN ('ativa', 'em_negociacao', 'aguardando_aprovacao_empresa',
                              'aguardando_aprovacao_transportador', 'aguardando_validacao_admin', 'pendente')
             AND ((empresa_id = :u1 AND transportador_id = :u2)
               OR (empresa_id = :u2 AND transportador_id = :u1))"
        );
        $stmt->execute([':u1' => $userId, ':u2' => $contatoId]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Verifica se dois utilizadores podem conversar.
 *
 * Regras (por ordem):
 * 1. Thread já existe (conversa ou mensagens com o mesmo missao_id)
 * 2. Chat ligado a missão: participantes da missão ou proposta
 * 3. Chat geral: parceria activa entre empresa e transportador
 * 4. Admin
 */
function chat_validar_acesso(PDO $conn, int $userId, int $contatoId, ?int $missaoId): array
{
    $missaoId = chat_normalizar_missao_id($missaoId);

    if ($contatoId <= 0 || $contatoId === $userId) {
        return ['ok' => false, 'error' => 'Contacto inválido', 'code' => 400];
    }

    $stmt = $conn->prepare('SELECT id FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $contatoId]);
    if (!$stmt->fetchColumn()) {
        return ['ok' => false, 'error' => 'Utilizador não encontrado', 'code' => 404];
    }

    if (chat_tem_conversa_thread($conn, $userId, $contatoId, $missaoId)) {
        return ['ok' => true];
    }
    if (chat_tem_mensagens_thread($conn, $userId, $contatoId, $missaoId)) {
        return ['ok' => true];
    }

    if ($missaoId !== null) {
        if (chat_podem_conversar_missao($conn, $userId, $contatoId, $missaoId)) {
            return ['ok' => true];
        }
        return ['ok' => false, 'error' => 'Sem permissão para conversar nesta missão', 'code' => 403];
    }

    // Sem missão: qualquer histórico entre os dois (threads antigas)
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM mensagens
         WHERE (remetente_id = :u1 AND destinatario_id = :u2)
            OR (remetente_id = :u2 AND destinatario_id = :u1)'
    );
    $stmt->execute([':u1' => $userId, ':u2' => $contatoId]);
    if ((int)$stmt->fetchColumn() > 0) {
        return ['ok' => true];
    }

    $u1 = min($userId, $contatoId);
    $u2 = max($userId, $contatoId);
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM conversas WHERE usuario1_id = :u1 AND usuario2_id = :u2'
    );
    $stmt->execute([':u1' => $u1, ':u2' => $u2]);
    if ((int)$stmt->fetchColumn() > 0) {
        return ['ok' => true];
    }

    if (chat_tem_parceria($conn, $userId, $contatoId)) {
        return ['ok' => true];
    }

    if (($_SESSION['user_type'] ?? '') === 'admin') {
        return ['ok' => true];
    }

    return ['ok' => false, 'error' => 'Sem permissão para esta conversa', 'code' => 403];
}

function chat_json_erro(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function chat_campos_mensagem(PDO $conn): array
{
    $hasAnexo = chat_coluna_existe($conn, 'mensagens', 'anexo_url');
    $hasLida  = chat_coluna_existe($conn, 'mensagens', 'lida');

    $select = 'm.id, m.remetente_id, m.mensagem, m.data_envio';
    if ($hasLida) {
        $select .= ', m.lida';
    }
    if ($hasAnexo) {
        $select .= ', m.anexo_url, m.anexo_nome, m.anexo_tipo';
    }

    return ['select' => $select, 'has_anexo' => $hasAnexo, 'has_lida' => $hasLida];
}

function chat_formatar_mensagem(array $row, int $userId, bool $hasAnexo, bool $hasLida): array
{
    $msg = [
        'id'             => (int)$row['id'],
        'is_mine'        => ((int)$row['remetente_id'] === $userId),
        'remetente_nome' => $row['remetente_nome'] ?? '',
        'mensagem'       => $row['mensagem'],
        'data_envio'     => $row['data_envio'],
        'lida'           => $hasLida ? (bool)($row['lida'] ?? false) : false,
        'anexo_url'      => null,
        'anexo_nome'     => null,
        'anexo_tipo'     => null,
    ];
    if ($hasAnexo) {
        $msg['anexo_url']  = $row['anexo_url'] ?? null;
        $msg['anexo_nome'] = $row['anexo_nome'] ?? null;
        $msg['anexo_tipo'] = $row['anexo_tipo'] ?? null;
    }
    return $msg;
}
