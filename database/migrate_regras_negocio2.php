<?php
/**
 * Migration — Regras RN04, RN38, RN40-43, RN54-56
 * Executar: php database/migrate_regras_negocio2.php
 */
include_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/missao-helpers.php';

echo "=== Migration Regras de Negócio (fase 2) ===\n\n";

try {
    $conn = getConnection();

    $conn->exec("
        CREATE TABLE IF NOT EXISTS penalizacoes_reputacao (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            missao_id INT DEFAULT NULL,
            tipo ENUM('recusa_excessiva','abandono_missao','atraso_entrega','avaliacao_baixa') NOT NULL,
            impacto DECIMAL(4,2) NOT NULL DEFAULT 0.10,
            motivo VARCHAR(500) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_usuario (usuario_id),
            KEY idx_tipo (tipo),
            KEY idx_missao (missao_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ penalizacoes_reputacao\n";

    $conn->exec("
        CREATE TABLE IF NOT EXISTS disputas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            missao_id INT NOT NULL,
            aberto_por INT NOT NULL,
            motivo TEXT NOT NULL,
            status ENUM('aberta','em_analise','encerrada') NOT NULL DEFAULT 'aberta',
            resolucao TEXT DEFAULT NULL,
            encerrado_por INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            encerrado_em TIMESTAMP NULL DEFAULT NULL,
            KEY idx_missao (missao_id),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ disputas\n";

    try {
        $conn->exec('ALTER TABLE avaliacoes_entrega ADD UNIQUE KEY uq_missao_avaliacao (missao_id)');
        echo "✓ avaliacoes_entrega.uq_missao_avaliacao\n";
    } catch (Throwable $e) {
        echo "· avaliacoes_entrega UNIQUE (já existe ou dados duplicados)\n";
    }

    try {
        $conn->exec('ALTER TABLE avaliacoes ADD UNIQUE KEY uq_missao_avaliador (missao_id, avaliador_id)');
        echo "✓ avaliacoes.uq_missao_avaliador\n";
    } catch (Throwable $e) {
        echo "· avaliacoes UNIQUE (já existe ou dados duplicados)\n";
    }

    if (!coluna_existe($conn, 'usuarios', 'recusas_consecutivas')) {
        $conn->exec("ALTER TABLE usuarios ADD COLUMN recusas_consecutivas INT NOT NULL DEFAULT 0");
        echo "✓ usuarios.recusas_consecutivas\n";
    }

    echo "\nMigration concluída.\n";
} catch (Throwable $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
