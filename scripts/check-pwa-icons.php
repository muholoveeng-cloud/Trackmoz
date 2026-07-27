<?php
$dir = dirname(__DIR__) . '/assets/img/icons';
foreach (glob($dir . '/*.png') as $p) {
    $i = getimagesize($p);
    echo basename($p) . ' ' . ($i ? $i[0] . 'x' . $i[1] : 'fail') . ' ' . filesize($p) . " bytes\n";
}
