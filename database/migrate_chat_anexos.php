<?php
require_once __DIR__ . '/../config/database.php';

try {
    $conn = getConnection();
    $cols = $conn->query("SHOW COLUMNS FROM mensagens LIKE 'anexo_url'")->fetch();
    if (!$cols) {
        $conn->exec("ALTER TABLE mensagens ADD COLUMN anexo_url VARCHAR(500) DEFAULT NULL AFTER mensagem");
        $conn->exec("ALTER TABLE mensagens ADD COLUMN anexo_nome VARCHAR(255) DEFAULT NULL AFTER anexo_url");
        $conn->exec("ALTER TABLE mensagens ADD COLUMN anexo_tipo VARCHAR(100) DEFAULT NULL AFTER anexo_nome");
        echo "Colunas de anexo adicionadas a mensagens.\n";
    } else {
        echo "Colunas de anexo ja existem.\n";
    }
    // Tornar mensagem nullable para permitir anexos sem texto
    $conn->exec("ALTER TABLE mensagens MODIFY mensagem TEXT NULL");
    echo "Migration concluida.\n";
} catch (Throwable $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
