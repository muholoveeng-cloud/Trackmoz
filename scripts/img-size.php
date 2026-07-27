<?php
$candidates = [
    __DIR__ . '/../assets/img/login-bg.png',
    __DIR__ . '/../assets/img/login img.png',
];
foreach ($candidates as $p) {
    if (!is_file($p)) continue;
    $i = getimagesize($p);
    echo basename($p) . ' ' . $i[0] . 'x' . $i[1] . PHP_EOL;
}
