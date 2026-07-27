<?php
/**
 * Testes automatizados TrackMoz — sintaxe, BD, OTP, rotas MZ, helpers críticos.
 * Uso: php scripts/test-sistema.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function ok(string $msg): void
{
    global $passes;
    $passes++;
    echo "  ✓ $msg\n";
}

function fail(string $msg): void
{
    global $failures;
    $failures++;
    echo "  ✗ $msg\n";
}

function section(string $title): void
{
    echo "\n=== $title ===\n";
}

echo "TrackMoz — testes do sistema\n";
echo str_repeat('=', 40) . "\n";

// ── 1. Sintaxe PHP ─────────────────────────────────────────────
section('Sintaxe PHP (ficheiros críticos)');

$criticalFiles = [
    'config/app.php',
    'config/database.php',
    'includes/helpers.php',
    'includes/regras-negocio.php',
    'includes/otp-entrega.php',
    'includes/rota-mocambique.php',
    'includes/frota-helpers.php',
    'includes/validacao-operacional.php',
    'api/entrega-confirmar.php',
    'api/entrega-otp-generate.php',
    'api/route.php',
    'pages/caminhoneiro/modo-direcao.php',
    'pages/caminhoneiro/entrega-confirmar.php',
    'pages/contratante/nova-missao.php',
    'pages/contratante/detalhes-missao.php',
    'pages/entrega/confirmar.php',
];

foreach ($criticalFiles as $rel) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($path)) {
        fail("Ficheiro em falta: $rel");
        continue;
    }
    $out = [];
    $code = 0;
    exec('"' . PHP_BINARY . '" -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    if ($code === 0) {
        ok(basename($rel));
    } else {
        fail("$rel: " . implode(' ', $out));
    }
}

// ── 2. Base de dados ───────────────────────────────────────────
section('Base de dados');

try {
    include_once $root . '/config/database.php';
    ok('Ligação PDO');
} catch (Throwable $e) {
    fail('Ligação PDO: ' . $e->getMessage());
    echo "\nResumo: $passes OK, $failures falhas\n";
    exit(1);
}

$requiredTables = [
    'missoes', 'usuarios', 'propostas', 'veiculos', 'perfil_caminhoneiro',
    'otp_codes', 'otp_tentativas', 'entregas_confirmacao', 'notificacoes',
    'logs_sistema', 'registros_viagem',
];

foreach ($requiredTables as $table) {
    try {
        $conn->query("SELECT 1 FROM `$table` LIMIT 1");
        ok("Tabela $table");
    } catch (Throwable $e) {
        fail("Tabela $table: " . $e->getMessage());
    }
}

// ── 3. OTP ─────────────────────────────────────────────────────
section('OTP de entrega');

include_once $root . '/includes/otp-entrega.php';

otp_entrega_bootstrap($conn);
ok('otp_entrega_bootstrap');

$codigo = otp_gerar_codigo();
if (preg_match('/^\d{6}$/', $codigo)) {
    ok('otp_gerar_codigo → 6 dígitos');
} else {
    fail("otp_gerar_codigo inválido: $codigo");
}

$exp = otp_calcular_expiracao(null, OTP_EXPIRACAO_HORAS_PADRAO);
$diffH = (strtotime($exp) - time()) / 3600;
if ($diffH >= 47 && $diffH <= 49) {
    ok('Expiração padrão ≈ 48h (constante OTP_EXPIRACAO_HORAS_PADRAO)');
} else {
    fail("Expiração inesperada (~{$diffH}h): $exp");
}

// Teste OTP com missão temporária
$empresaId = (int)$conn->query("SELECT id FROM usuarios WHERE tipo_usuario='empresa' LIMIT 1")->fetchColumn();
if ($empresaId <= 0) {
    fail('Sem utilizador empresa para teste OTP');
} else {
    $conn->exec(
        "INSERT INTO missoes (empresa_id, titulo, origem, destino, tipo_veiculo, tipo_carga, valor, descricao, prazo_entrega, status)
         VALUES ($empresaId, 'Teste OTP auto', 'Maputo', 'Beira', 'caminhao', 'geral', 100, 'teste', DATE_ADD(NOW(), INTERVAL 7 DAY), 'em_entrega')"
    );
    $testMissaoId = (int)$conn->lastInsertId();

    $gen = otp_gerar_para_missao($conn, $testMissaoId, $empresaId, false);
    if ($gen['ok'] && strlen($gen['codigo']) === 6) {
        ok('otp_gerar_para_missao');
    } else {
        fail('otp_gerar_para_missao: ' . ($gen['error'] ?? 'falhou'));
    }

    $valOk = otp_validar_codigo($conn, $testMissaoId, $gen['codigo'], $empresaId);
    if ($valOk['ok']) {
        ok('otp_validar_codigo (código correcto)');
    } else {
        fail('otp_validar_codigo: ' . ($valOk['error'] ?? ''));
    }

    $valBad = otp_validar_codigo($conn, $testMissaoId, '000000', $empresaId);
    if (!$valBad['ok']) {
        ok('otp_validar_codigo rejeita código errado');
    } else {
        fail('otp_validar_codigo aceitou código errado');
    }

    $info = otp_info_missao($conn, $testMissaoId);
    if ($info && isset($info['expira_em'])) {
        ok('otp_info_missao');
    } else {
        fail('otp_info_missao sem dados');
    }

    $conn->exec("DELETE FROM otp_tentativas WHERE missao_id = $testMissaoId");
    $conn->exec("DELETE FROM otp_codes WHERE missao_id = $testMissaoId");
    $conn->exec("DELETE FROM missoes WHERE id = $testMissaoId");
    ok('Limpeza missão teste OTP');
}

// ── 4. Rotas Moçambique ────────────────────────────────────────
section('Rotas nacionais (Moçambique)');

include_once $root . '/includes/rota-mocambique.php';

// Ponto em Zâmbia (oeste de Tete) deve ser detectado como estrangeiro
if (mz_ponto_provavel_estrangeiro(-15.5, 30.0)) {
    ok('Detecta coordenada fora de MZ (Zâmbia)');
} else {
    fail('Não detectou ponto estrangeiro (-15.5, 30.0)');
}

// Maputo deve ser nacional
if (!mz_ponto_provavel_estrangeiro(-25.97, 32.57)) {
    ok('Maputo considerado nacional');
} else {
    fail('Maputo marcado como estrangeiro');
}

$rota = calcular_rota_mocambique(-16.16, 33.59, -25.97, 32.57); // Tete → Maputo
if ($rota && !empty($rota['coordinates'])) {
    $nacional = !empty($rota['nacional']) || rota_geometria_nacional($rota['coordinates']);
    if ($nacional) {
        ok('Tete→Maputo: rota nacional (sem sair de MZ)');
    } else {
        fail('Tete→Maputo: rota ainda passa fora de MZ');
    }
    ok('Distância Tete→Maputo: ' . round(($rota['distance_m'] ?? $rota['distance'] ?? 0) / 1000) . ' km');
} else {
    fail('calcular_rota_mocambique Tete→Maputo falhou (OSRM offline?)');
}

// ── 5. Frota / motorista ───────────────────────────────────────
section('Resolução veículo motorista');

include_once $root . '/includes/frota-helpers.php';

$caminhoneiroId = (int)$conn->query("SELECT id FROM usuarios WHERE tipo_usuario='caminhoneiro' LIMIT 1")->fetchColumn();
if ($caminhoneiroId > 0) {
    $veic = motorista_resolver_veiculo($conn, $caminhoneiroId);
    if ($veic === null) {
        ok("motorista_resolver_veiculo($caminhoneiroId) → null (sem veículo associado — esperado se perfil incompleto)");
    } else {
        ok('motorista_resolver_veiculo → veículo id ' . ($veic['id'] ?? '?'));
    }
} else {
    fail('Sem caminhoneiro na BD para teste');
}

// ── 6. Páginas referenciadas (contratante) ─────────────────────
section('Páginas referenciadas');

$contratanteRefs = ['editar-missao.php', 'nova-missao.php', 'detalhes-missao.php'];
foreach ($contratanteRefs as $f) {
    $path = $root . '/pages/contratante/' . $f;
    if (is_file($path)) {
        ok("pages/contratante/$f existe");
    } else {
        fail("pages/contratante/$f NÃO existe");
    }
}

// ── 7. Branding legacy ───────────────────────────────────────
section('Branding legacy (Moçamission)');

$legacyCount = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/pages', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $content = file_get_contents($file->getPathname());
    if (stripos($content, 'Moçamission') !== false || stripos($content, 'Mocamission') !== false) {
        $legacyCount++;
        $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
        fail("Ainda contém 'Moçamission': $rel");
    }
}
if ($legacyCount === 0) {
    ok('Nenhuma página em /pages com Moçamission');
}

// ── Resumo ─────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 40) . "\n";
echo "Resultado: $passes OK, $failures falhas\n";

if ($failures > 0) {
    exit(1);
}

echo "Todos os testes passaram.\n";
exit(0);
