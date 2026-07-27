<?php
/**
 * Alarga status_viagem para o fluxo recolha → destino do modo condução.
 * Executar uma vez: php database/migrate_status_viagem.php
 */
require_once __DIR__ . '/../config/database.php';

$sql = "ALTER TABLE missoes
        MODIFY COLUMN status_viagem ENUM(
            'nao_iniciada','a_caminho_recolha','aguardando_recolha','carga_recolhida',
            'em_transito','coleta','entrega','finalizada'
        ) DEFAULT 'nao_iniciada'";

try {
    $conn->exec($sql);
    echo "OK: status_viagem actualizado.\n";
    $col = $conn->query("SHOW COLUMNS FROM missoes LIKE 'status_viagem'")->fetch(PDO::FETCH_ASSOC);
    echo ($col['Type'] ?? '') . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
    exit(1);
}
