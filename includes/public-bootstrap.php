<?php
/**
 * Bootstrap leve para páginas públicas (sem helpers.php / match / PDO).
 */
if (!defined('BASE_URL')) {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    if ($dir === '' || $dir === '.' || $dir === '/') {
        define('BASE_URL', '');
    } else {
        if (preg_match('#^(.*?)/(pages|api|includes|config|assets|scripts|uploads)(/.*)?$#', $dir, $m)) {
            $dir = $m[1];
        }
        define('BASE_URL', ($dir === '' || $dir === '.') ? '' : $dir);
    }
}

require_once __DIR__ . '/pwa-url.php';
require_once __DIR__ . '/seo.php';
