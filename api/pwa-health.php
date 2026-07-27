<?php
/**
 * Diagnóstico PWA no servidor (ficheiros + HTTPS).
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/app.php';

$root = dirname(__DIR__);
$icons = $root . '/assets/img/icons';
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

$host = (string)($_SERVER['HTTP_HOST'] ?? '');
$base = rtrim((string)BASE_URL, '/');

$needed = [
    'sw.js' => $root . '/sw.js',
    'manifest.php' => $root . '/manifest.php',
    'offline.html' => $root . '/offline.html',
    'icon-192.png' => $icons . '/icon-192.png',
    'icon-512.png' => $icons . '/icon-512.png',
    'icon-512-maskable.png' => $icons . '/icon-512-maskable.png',
    'pwa-install.js' => $root . '/assets/js/pwa-install.js',
];

$files = [];
$allOk = true;
foreach ($needed as $label => $path) {
    $ok = is_file($path);
    $meta = ['ok' => $ok, 'bytes' => $ok ? filesize($path) : 0];
    if ($ok && preg_match('/\.png$/i', $path)) {
        $img = @getimagesize($path);
        $meta['width'] = $img[0] ?? null;
        $meta['height'] = $img[1] ?? null;
        if (!$img || (int)$img[0] < 192) {
            $ok = false;
            $meta['ok'] = false;
        }
    }
    $files[$label] = $meta;
    if (!$ok) $allOk = false;
}

echo json_encode([
    'ok' => $allOk && ($https || in_array($host, ['localhost', '127.0.0.1'], true)),
    'https' => $https,
    'host' => $host,
    'base_url' => $base,
    'manifest_url' => $base . '/manifest.php',
    'sw_url' => $base . '/sw.js',
    'files' => $files,
    'hint' => $allOk
        ? (
            (stripos($host, 'site.je') !== false)
                ? 'Ficheiros OK, mas site.je pode injectar aes.js e bloquear a instalação PWA. Desactive a protecção anti-bot no painel ou mude de hosting.'
                : 'Ficheiros OK no servidor. Se ainda não instalar: desinstale ícone antigo, use Chrome, janela anónima.'
        )
        : 'Faltam ficheiros PWA no servidor — faça upload da pasta icons/ e dos ficheiros sw.js/manifest.php.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
