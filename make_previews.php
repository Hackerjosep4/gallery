#!/usr/bin/env php
<?php
// Executa desde la carpeta amb les fotos:
//   php make_previews.php
//
// O com www-data:
//   sudo -u www-data php make_previews.php

$PREVIEW_DIR = __DIR__ . '/_previews';
$PREVIEW_W   = 500;
$IMAGE_EXTS  = ['jpg','jpeg','png','webp','gif'];
$VIDEO_EXTS  = ['mov','mp4','avi','mkv','webm','m4v','3gp'];
$SELF        = basename(__FILE__);

set_time_limit(0);
ini_set('memory_limit', '512M');

if (!is_dir($PREVIEW_DIR)) mkdir($PREVIEW_DIR, 0755, true);

// ─── HELPERS ───────────────────────────────────────────────
function generateImagePreview(string $src, string $dst, int $maxW, string $ext): bool {
    if (!function_exists('imagecreatefromjpeg')) return false;
    $img = match($ext) {
        'jpg','jpeg' => @imagecreatefromjpeg($src),
        'png'        => @imagecreatefrompng($src),
        'webp'       => @imagecreatefromwebp($src),
        'gif'        => @imagecreatefromgif($src),
        default      => false,
    };
    if (!$img) return false;
    if (in_array($ext, ['jpg','jpeg']) && function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        $img = match($exif['Orientation'] ?? 1) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };
    }
    $ow = imagesx($img); $oh = imagesy($img);
    $tw = min($ow, $maxW);
    $th = (int)round($oh * $tw / $ow);
    $out = imagecreatetruecolor($tw, $th);
    imagecopyresampled($out, $img, 0, 0, 0, 0, $tw, $th, $ow, $oh);
    $r = imagejpeg($out, $dst, 60);
    imagedestroy($img); imagedestroy($out);
    return $r;
}

function generateVideoPreview(string $src, string $dst): bool {
    $s = escapeshellarg($src);
    $d = escapeshellarg($dst);
    exec("ffmpeg -y -i {$s} -ss 00:00:01 -vframes 1 -vf scale=500:-2 {$d} 2>/dev/null", $_, $code);
    if ($code !== 0 || !file_exists($dst)) {
        exec("ffmpeg -y -i {$s} -vframes 1 -vf scale=500:-2 {$d} 2>/dev/null");
    }
    return file_exists($dst);
}

// ─── SCAN & GENERATE ───────────────────────────────────────
$allExts  = array_unique(array_merge($IMAGE_EXTS, $VIDEO_EXTS));
$globExts = implode(',', array_merge($allExts, array_map('strtoupper', $allExts)));

$files = glob(__DIR__ . '/*.{' . $globExts . '}', GLOB_BRACE);
$total = count($files);
$done  = 0;
$skip  = 0;
$fail  = 0;

echo "Trobats {$total} fitxers.\n";

foreach ($files as $path) {
    $file = basename($path);
    if ($file === $SELF) continue;

    $ext     = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $isVideo = in_array($ext, $VIDEO_EXTS);
    $previewName = $isVideo ? ($file . '.jpg') : $file;
    $previewPath = $PREVIEW_DIR . '/' . $previewName;

    if (file_exists($previewPath) && filemtime($previewPath) >= filemtime($path)) {
        echo "  [skip] {$file}\n";
        $skip++;
        continue;
    }

    echo "  [gen]  {$file} ... ";
    flush();

    $ok = $isVideo
        ? generateVideoPreview($path, $previewPath)
        : generateImagePreview($path, $previewPath, $PREVIEW_W, $ext);

    if ($ok) { echo "ok\n"; $done++; }
    else     { echo "FAILED\n"; $fail++; }
    flush();
}

echo "\nFet! Generats: {$done} | Saltats (ja existien): {$skip} | Errors: {$fail}\n";
