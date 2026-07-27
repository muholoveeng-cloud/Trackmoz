<?php
/**
 * Branding empresarial — colunas em perfil_empresa e perfil_transportador.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$colunasEmpresa = [
    'razao_social'        => "VARCHAR(255) DEFAULT NULL AFTER nome_empresa",
    'pais'                => "VARCHAR(80) DEFAULT 'Moçambique' AFTER cidade",
    'website'             => "VARCHAR(255) DEFAULT NULL AFTER email_comercial",
    'cor_institucional'   => "VARCHAR(7) DEFAULT '#2563eb' AFTER website",
    'ano_fundacao'        => "SMALLINT DEFAULT NULL AFTER cor_institucional",
    'especialidade'       => "VARCHAR(120) DEFAULT NULL AFTER ano_fundacao",
    'licenca'             => "VARCHAR(80) DEFAULT NULL AFTER especialidade",
    'provincias_operacao' => "TEXT DEFAULT NULL AFTER licenca",
];

$colunasTransportador = [
    'logo_empresa'        => "VARCHAR(255) DEFAULT NULL AFTER nome_empresa",
    'razao_social'        => "VARCHAR(255) DEFAULT NULL AFTER logo_empresa",
    'pais'                => "VARCHAR(80) DEFAULT 'Moçambique' AFTER cidade",
    'website'             => "VARCHAR(255) DEFAULT NULL AFTER email_comercial",
    'cor_institucional'   => "VARCHAR(7) DEFAULT '#2563eb' AFTER website",
    'descricao'           => "TEXT DEFAULT NULL AFTER cor_institucional",
    'ano_fundacao'        => "SMALLINT DEFAULT NULL AFTER descricao",
    'especialidade'       => "VARCHAR(120) DEFAULT NULL AFTER ano_fundacao",
    'licenca'             => "VARCHAR(80) DEFAULT NULL AFTER especialidade",
    'provincias_operacao' => "TEXT DEFAULT NULL AFTER licenca",
];

try {
    $conn = getConnection();

    foreach ($colunasEmpresa as $col => $def) {
        if (!table_has_column($conn, 'perfil_empresa', $col)) {
            $conn->exec("ALTER TABLE perfil_empresa ADD COLUMN {$col} {$def}");
            echo "perfil_empresa.{$col} adicionada.\n";
        }
    }

    foreach ($colunasTransportador as $col => $def) {
        if (!table_has_column($conn, 'perfil_transportador', $col)) {
            $conn->exec("ALTER TABLE perfil_transportador ADD COLUMN {$col} {$def}");
            echo "perfil_transportador.{$col} adicionada.\n";
        }
    }

    // Sincronizar site -> website se existir
    if (table_has_column($conn, 'perfil_empresa', 'site') && table_has_column($conn, 'perfil_empresa', 'website')) {
        $conn->exec("UPDATE perfil_empresa SET website = site WHERE (website IS NULL OR website = '') AND site IS NOT NULL AND site != ''");
    }

    echo "Migration branding concluída.\n";
} catch (Throwable $e) {
    echo 'Erro: ' . $e->getMessage() . "\n";
    exit(1);
}
