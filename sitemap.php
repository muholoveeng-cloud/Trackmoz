<?php
/**
 * Sitemap XML — sem dependências internas (compatível com hosting gratuito).
 */
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
$host = trim((string)($_SERVER['HTTP_HOST'] ?? 'trackmoz.site.je'));
$base = ($https ? 'https' : 'http') . '://' . $host;

$pages = [
    ['/', '1.0', 'weekly'],
    ['/index.php', '1.0', 'weekly'],
    ['/sobre.php', '0.8', 'monthly'],
    ['/funcionalidades.php', '0.8', 'monthly'],
    ['/contactos.php', '0.7', 'monthly'],
    ['/termos.php', '0.4', 'yearly'],
    ['/privacidade.php', '0.4', 'yearly'],
    ['/pages/login.php', '0.5', 'monthly'],
    ['/pages/cadastro.php', '0.6', 'monthly'],
];

$today = date('Y-m-d');

header('Content-Type: text/xml; charset=utf-8');
header('X-Robots-Tag: noindex');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($pages as $p) {
    $loc = htmlspecialchars($base . $p[0], ENT_QUOTES, 'UTF-8');
    echo "  <url>\n";
    echo "    <loc>{$loc}</loc>\n";
    echo "    <lastmod>{$today}</lastmod>\n";
    echo "    <changefreq>{$p[2]}</changefreq>\n";
    echo "    <priority>{$p[1]}</priority>\n";
    echo "  </url>\n";
}
echo '</urlset>';
