<?php
/**
 * Meta tags e assets PWA — incluir dentro de <head>.
 */
if (defined('TRACKMOZ_PWA_LOADED')) {
    return;
}
define('TRACKMOZ_PWA_LOADED', true);

if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../config/app.php';
}
require_once __DIR__ . '/pwa-url.php';

$__pwaPath = rtrim((string)BASE_URL, '/');
$__pwaAbs = tmz_pwa_base_url();
$__pwaIcon = $__pwaPath . '/assets/img/icons';
$__pwaScope = ($__pwaPath === '' ? '/' : $__pwaPath . '/');
?>
<link rel="manifest" href="<?php echo htmlspecialchars($__pwaPath); ?>/manifest.php">
<meta name="theme-color" content="#1a647f">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="TrackMoz">
<meta name="application-name" content="TrackMoz">
<link rel="icon" type="image/png" sizes="192x192" href="<?php echo htmlspecialchars($__pwaIcon); ?>/icon-192.png">
<link rel="icon" type="image/png" sizes="512x512" href="<?php echo htmlspecialchars($__pwaIcon); ?>/icon-512.png">
<link rel="apple-touch-icon" href="<?php echo htmlspecialchars($__pwaIcon); ?>/apple-touch-icon.png">
<link rel="stylesheet" href="<?php echo htmlspecialchars($__pwaPath); ?>/assets/css/pwa-install.css?v=7">
<script>
window.TRACKMOZ_PWA = {
    baseUrl: <?php echo json_encode($__pwaPath); ?>,
    absoluteBase: <?php echo json_encode($__pwaAbs); ?>,
    swUrl: <?php echo json_encode($__pwaPath . '/sw.js'); ?>,
    scopeUrl: <?php echo json_encode($__pwaScope); ?>,
    manifestUrl: <?php echo json_encode($__pwaPath . '/manifest.php'); ?>,
    iconUrl: <?php echo json_encode($__pwaIcon . '/icon-192.png'); ?>
};
(function () {
    try {
        var c = window.TRACKMOZ_PWA;
        var ok = location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
        if (ok && 'serviceWorker' in navigator) {
            navigator.serviceWorker.register(c.swUrl, { scope: c.scopeUrl, updateViaCache: 'none' });
        }
    } catch (e) {}
})();
</script>
<script src="<?php echo htmlspecialchars($__pwaPath); ?>/assets/js/pwa-install.js?v=7" defer></script>
