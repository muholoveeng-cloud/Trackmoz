<?php
/**
 * Base absoluta do site para PWA (manifest / SW).
 */
if (!function_exists('tmz_pwa_base_url')) {
    function tmz_pwa_base_url(): string
    {
        if (!defined('BASE_URL')) {
            require_once __DIR__ . '/../config/app.php';
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && (string)$_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');

        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        $path = rtrim((string)BASE_URL, '/');

        if ($host === '') {
            return $path;
        }

        return ($https ? 'https' : 'http') . '://' . $host . $path;
    }
}
