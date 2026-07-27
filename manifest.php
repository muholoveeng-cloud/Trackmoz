<?php
/**
 * Web App Manifest — caminhos relativos ao origin.
 */
require_once __DIR__ . '/config/app.php';

$path = rtrim((string)BASE_URL, '/');
$root = ($path === '' ? '' : $path);
$icon = $root . '/assets/img/icons';
$scope = ($root === '' ? '/' : $root . '/');

$manifest = [
    'name' => 'TrackMoz',
    'short_name' => 'TrackMoz',
    'description' => 'Plataforma inteligente de gestão de transporte rodoviário de cargas',
    'start_url' => $root . '/pages/login.php?source=pwa',
    'scope' => $scope,
    'display' => 'standalone',
    'orientation' => 'any',
    'background_color' => '#e8eef4',
    'theme_color' => '#1a647f',
    'lang' => 'pt-PT',
    'dir' => 'ltr',
    'prefer_related_applications' => false,
    'icons' => [
        [
            'src' => $icon . '/icon-192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => $icon . '/icon-512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => $icon . '/icon-512-maskable.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'maskable',
        ],
    ],
];

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
