<?php
/**
 * Idempotência de operações offline (client_op_id).
 */

if (!function_exists('tmz_sync_ops_ensure')) {
    function tmz_sync_ops_ensure(PDO $conn): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $conn->exec("
                CREATE TABLE IF NOT EXISTS sync_ops (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    client_op_id VARCHAR(64) NOT NULL,
                    usuario_id INT NOT NULL,
                    tipo VARCHAR(40) NOT NULL,
                    missao_id INT NULL DEFAULT NULL,
                    response_json MEDIUMTEXT NULL,
                    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_client_op (client_op_id),
                    KEY idx_user_missao (usuario_id, missao_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            error_log('tmz_sync_ops_ensure: ' . $e->getMessage());
        }
    }
}

if (!function_exists('tmz_sync_find')) {
    function tmz_sync_find(PDO $conn, string $clientOpId): ?array
    {
        $clientOpId = trim($clientOpId);
        if ($clientOpId === '' || strlen($clientOpId) > 64) {
            return null;
        }
        tmz_sync_ops_ensure($conn);
        $st = $conn->prepare('SELECT response_json FROM sync_ops WHERE client_op_id = ? LIMIT 1');
        $st->execute([$clientOpId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['response_json'])) {
            return null;
        }
        $decoded = json_decode((string)$row['response_json'], true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('tmz_sync_store')) {
    function tmz_sync_store(
        PDO $conn,
        string $clientOpId,
        int $usuarioId,
        string $tipo,
        ?int $missaoId,
        array $response
    ): void {
        $clientOpId = trim($clientOpId);
        if ($clientOpId === '' || strlen($clientOpId) > 64) {
            return;
        }
        tmz_sync_ops_ensure($conn);
        try {
            $st = $conn->prepare("
                INSERT INTO sync_ops (client_op_id, usuario_id, tipo, missao_id, response_json)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE response_json = VALUES(response_json)
            ");
            $st->execute([
                $clientOpId,
                $usuarioId,
                substr($tipo, 0, 40),
                $missaoId,
                json_encode($response, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            error_log('tmz_sync_store: ' . $e->getMessage());
        }
    }
}
