<?php
/**
 * Adiciona colunas operacionais de entrega/condução à tabela missoes.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/missao-helpers.php';

try {
    $conn = getConnection();
    missao_garantir_colunas_operacionais($conn);
    echo "Colunas operacionais de missão verificadas.\n";
} catch (Throwable $e) {
    echo 'Erro: ' . $e->getMessage() . "\n";
    exit(1);
}
