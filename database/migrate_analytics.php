<?php
/**
 * Analytics de acessos: pageviews públicas + login/logout.
 * Uso: php database/migrate_analytics.php
 */
require_once __DIR__ . '/../config/database.php';

echo "=== Migration Analytics ===\n\n";

try {
    $conn = getConnection();

    $conn->exec("
        CREATE TABLE IF NOT EXISTS analytics_eventos (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tipo ENUM('pageview','login','logout','heartbeat') NOT NULL,
            usuario_id INT NULL DEFAULT NULL,
            path VARCHAR(255) NULL DEFAULT NULL,
            ip VARCHAR(45) NULL DEFAULT NULL,
            user_agent VARCHAR(255) NULL DEFAULT NULL,
            session_key VARCHAR(64) NULL DEFAULT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_tipo_data (tipo, criado_em),
            KEY idx_session_tipo (session_key, tipo, criado_em),
            KEY idx_usuario_data (usuario_id, criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "OK tabela analytics_eventos\n";

    echo "\nConcluído.\n";
} catch (Throwable $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
