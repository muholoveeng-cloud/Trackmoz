<?php
/**
 * Migration TMS — Tabelas GPS, rotas, checkpoints e posições de viaturas.
 * Executar: php database/migrate_tms_gps.php
 */
include_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/missao-helpers.php';

echo "=== Migration TMS GPS ===\n\n";

try {
    $conn = getConnection();

    $conn->exec("
        CREATE TABLE IF NOT EXISTS gps_locations (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            mission_id INT DEFAULT NULL,
            driver_id INT NOT NULL,
            vehicle_id INT DEFAULT NULL,
            latitude DECIMAL(10,7) NOT NULL,
            longitude DECIMAL(10,7) NOT NULL,
            speed DECIMAL(8,2) DEFAULT NULL,
            heading DECIMAL(6,2) DEFAULT NULL,
            accuracy DECIMAL(8,2) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_mission (mission_id),
            KEY idx_driver (driver_id),
            KEY idx_created (created_at),
            KEY idx_mission_created (mission_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ gps_locations\n";

    $conn->exec("
        CREATE TABLE IF NOT EXISTS mission_routes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mission_id INT NOT NULL,
            origin_lat DECIMAL(10,7) NOT NULL,
            origin_lng DECIMAL(10,7) NOT NULL,
            dest_lat DECIMAL(10,7) NOT NULL,
            dest_lng DECIMAL(10,7) NOT NULL,
            distance_km DECIMAL(10,2) DEFAULT NULL,
            duration_min INT DEFAULT NULL,
            route_geojson MEDIUMTEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_mission (mission_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ mission_routes\n";

    $conn->exec("
        CREATE TABLE IF NOT EXISTS mission_checkpoints (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mission_id INT NOT NULL,
            driver_id INT NOT NULL,
            tipo ENUM(
                'chegou_recolha','carga_recolhida','chegou_destino','missao_concluida','gps_offline','gps_online'
            ) NOT NULL,
            latitude DECIMAL(10,7) NOT NULL,
            longitude DECIMAL(10,7) NOT NULL,
            distancia_m DECIMAL(8,2) DEFAULT NULL,
            automatico TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_mission (mission_id),
            KEY idx_tipo (tipo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ mission_checkpoints\n";

    $conn->exec("
        CREATE TABLE IF NOT EXISTS vehicle_positions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            vehicle_id INT NOT NULL,
            mission_id INT DEFAULT NULL,
            driver_id INT DEFAULT NULL,
            latitude DECIMAL(10,7) NOT NULL,
            longitude DECIMAL(10,7) NOT NULL,
            speed DECIMAL(8,2) DEFAULT NULL,
            heading DECIMAL(6,2) DEFAULT NULL,
            estado ENUM('em_transito','em_recolha','parado','emergencia','offline') DEFAULT 'parado',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_vehicle (vehicle_id),
            KEY idx_mission (mission_id),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ vehicle_positions\n";

    $conn->exec("
        CREATE TABLE IF NOT EXISTS realtime_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(50) NOT NULL,
            mission_id INT DEFAULT NULL,
            driver_id INT DEFAULT NULL,
            payload_json TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_type_created (event_type, created_at),
            KEY idx_mission (mission_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ realtime_events\n";

    if (!coluna_existe($conn, 'historico_localizacao', 'missao_id')) {
        $conn->exec("ALTER TABLE historico_localizacao ADD COLUMN missao_id INT DEFAULT NULL AFTER usuario_id");
        $conn->exec("ALTER TABLE historico_localizacao ADD COLUMN speed DECIMAL(8,2) DEFAULT NULL AFTER longitude");
        $conn->exec("ALTER TABLE historico_localizacao ADD COLUMN heading DECIMAL(6,2) DEFAULT NULL AFTER speed");
        echo "✓ historico_localizacao (missao_id, speed, heading)\n";
    }

    if (!coluna_existe($conn, 'locais', 'nome')) {
        $conn->exec("ALTER TABLE locais ADD COLUMN nome VARCHAR(255) DEFAULT NULL AFTER id");
        echo "✓ locais.nome\n";
    }

    if (!coluna_existe($conn, 'missoes', 'gps_offline_desde')) {
        $conn->exec("ALTER TABLE missoes ADD COLUMN gps_offline_desde DATETIME DEFAULT NULL");
        echo "✓ missoes.gps_offline_desde\n";
    }

    echo "\nMigration TMS GPS concluída.\n";
} catch (Throwable $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
