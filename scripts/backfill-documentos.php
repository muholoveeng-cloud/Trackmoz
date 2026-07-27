<?php
/**
 * Gera documentos em falta: contratos de parceria activos e registos de missão.
 * Uso: php scripts/backfill-documentos.php
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/documentos-registry.php';

$conn = getConnection();
$r = tmz_docs_backfill_pendentes($conn);
echo "Backfill OK — contratos parceria: {$r['parcerias']}, registos missão: {$r['missoes']}\n";
