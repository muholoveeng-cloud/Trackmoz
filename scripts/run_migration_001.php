<?php
/**
 * Aplica migration 001 (colunas codigo_missao, distancia_km, tempo_estimado_min)
 * Uso: php scripts/run_migration_001.php
 */
include_once __DIR__ . '/../config/database.php';
include_once __DIR__ . '/../includes/missao-helpers.php';

$alteracoes = [
    "ALTER TABLE missoes ADD COLUMN codigo_missao varchar(20) DEFAULT NULL AFTER id",
    "ALTER TABLE missoes ADD COLUMN distancia_km decimal(10,2) DEFAULT NULL",
    "ALTER TABLE missoes ADD COLUMN tempo_estimado_min int DEFAULT NULL",
];

foreach ($alteracoes as $sql) {
    try {
        $conn->exec($sql);
        echo "OK: $sql\n";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate column')) {
            echo "SKIP (já existe): $sql\n";
        } else {
            echo "ERRO: " . $e->getMessage() . "\n";
        }
    }
}

$conn->exec(
    "UPDATE missoes SET codigo_missao = CONCAT('TMZ-', YEAR(data_criacao), '-', LPAD(id, 5, '0'))
     WHERE codigo_missao IS NULL OR codigo_missao = ''"
);
echo "Códigos de missão actualizados.\n";
