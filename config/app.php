<?php

if (!function_exists('app_base_path')) {
    function app_base_path(): string {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

        if ($dir === '' || $dir === '.') {
            return '';
        }

        if (preg_match('#^(.*?)/(pages|api|includes|config|assets|scripts|uploads)(/.*)?$#', $dir, $m)) {
            $dir = $m[1];
        }

        if ($dir === '' || $dir === '.') {
            return '';
        }

        return $dir;
    }
}

if (!defined('BASE_URL')) {
    define('BASE_URL', app_base_path());
}

require_once __DIR__ . '/../includes/helpers.php';
