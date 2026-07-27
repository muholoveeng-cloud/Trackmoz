<?php
require_once __DIR__ . '/../config/database.php';
try {
    $conn = getConnection();
    $conn->exec("CREATE TABLE IF NOT EXISTS historico_emergencias (
        id INT AUTO_INCREMENT PRIMARY KEY,
        missao_id INT NOT NULL,
        caminhoneiro_id INT NOT NULL,
        latitude DECIMAL(10,8) DEFAULT NULL,
        longitude DECIMAL(11,8) DEFAULT NULL,
        data_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        resolvida TINYINT(1) DEFAULT 0,
        INDEX idx_missao (missao_id),
        INDEX idx_caminhoneiro (caminhoneiro_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabela historico_emergencias criada/atualizada.\n";
} catch (Throwable $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
