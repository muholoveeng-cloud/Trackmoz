<?php
/**
 * Gera ícones PWA a partir do logo TrackMoz.
 * Uso: php scripts/generate-pwa-icons.php
 */
$root = dirname(__DIR__);
$src = $root . '/assets/img/Logo_com_background.png';
if (!file_exists($src)) {
    $src = $root . '/assets/img/Logo_sem_background.png';
}
$dir = $root . '/assets/img/icons';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

$img = @imagecreatefrompng($src);
if (!$img) {
    fwrite(STDERR, "Não foi possível carregar: {$src}\n");
    exit(1);
}

$w = imagesx($img);
$h = imagesy($img);

function tm_make_icon($img, $w, $h, $size, $path, $padRatio = 0.14): void
{
    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
    imagefilledrectangle($out, 0, 0, $size, $size, $transparent);
    imagealphablending($out, true);
    $bg = imagecolorallocate($out, 37, 99, 235);
    imagefilledrectangle($out, 0, 0, $size, $size, $bg);

    $pad = (int) round($size * $padRatio);
    $box = $size - 2 * $pad;
    $scale = min($box / $w, $box / $h);
    $nw = (int) round($w * $scale);
    $nh = (int) round($h * $scale);
    $dx = (int) (($size - $nw) / 2);
    $dy = (int) (($size - $nh) / 2);
    imagecopyresampled($out, $img, $dx, $dy, 0, 0, $nw, $nh, $w, $h);
    imagepng($out, $path);
    imagedestroy($out);
}

tm_make_icon($img, $w, $h, 192, $dir . '/icon-192.png', 0.14);
tm_make_icon($img, $w, $h, 512, $dir . '/icon-512.png', 0.14);
tm_make_icon($img, $w, $h, 512, $dir . '/icon-512-maskable.png', 0.20);
tm_make_icon($img, $w, $h, 180, $dir . '/apple-touch-icon.png', 0.14);
imagedestroy($img);

echo "Ícones PWA gerados em assets/img/icons/\n";
foreach (glob($dir . '/*.png') as $f) {
    echo basename($f) . ' (' . filesize($f) . " bytes)\n";
}
