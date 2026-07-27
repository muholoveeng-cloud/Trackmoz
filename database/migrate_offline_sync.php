<?php
/**
 * Idempotência de sync offline (client_op_id).
 * Uso: php database/migrate_offline_sync.php
 */
require_once __DIR__ . '/../config/database.php';

echo "=== Migration Offline Sync ===\n\n";

try {
    $conn = getConnection();
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
    echo "OK tabela sync_ops\n";
} catch (Throwable $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
