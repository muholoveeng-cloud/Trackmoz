<?php
/**
 * Sitemap XML dinâmico para páginas públicas.
 */
header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/seo.php';

$today = date('Y-m-d');
$pages = [
    ['loc' => tmz_site_url('index.php'), 'priority' => '1.0', 'changefreq' => 'weekly'],
    ['loc' => tmz_site_url('sobre.php'), 'priority' => '0.8', 'changefreq' => 'monthly'],
    ['loc' => tmz_site_url('funcionalidades.php'), 'priority' => '0.8', 'changefreq' => 'monthly'],
    ['loc' => tmz_site_url('contactos.php'), 'priority' => '0.7', 'changefreq' => 'monthly'],
    ['loc' => tmz_site_url('termos.php'), 'priority' => '0.4', 'changefreq' => 'yearly'],
    ['loc' => tmz_site_url('privacidade.php'), 'priority' => '0.4', 'changefreq' => 'yearly'],
    ['loc' => tmz_site_url('pages/login.php'), 'priority' => '0.5', 'changefreq' => 'monthly'],
    ['loc' => tmz_site_url('pages/cadastro.php'), 'priority' => '0.6', 'changefreq' => 'monthly'],
];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($pages as $p): ?>
  <url>
    <loc><?php echo htmlspecialchars($p['loc'], ENT_XML1); ?></loc>
    <lastmod><?php echo $today; ?></lastmod>
    <changefreq><?php echo $p['changefreq']; ?></changefreq>
    <priority><?php echo $p['priority']; ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
