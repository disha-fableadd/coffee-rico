<?php

/**
 * One-time helper: create Laravel storage dirs and set permissions.
 * Visit: https://your-domain/services/public/fix-storage.php
 * Delete this file after use.
 */

$base = dirname(__DIR__);
$dirs = [
    $base . '/storage/framework',
    $base . '/storage/framework/cache',
    $base . '/storage/framework/cache/data',
    $base . '/storage/framework/sessions',
    $base . '/storage/framework/views',
    $base . '/storage/logs',
    $base . '/bootstrap/cache',
    $base . '/public/uploads',
    $base . '/public/uploads/company',
    $base . '/public/uploads/profiles',
    $base . '/public/uploads/media',
];

header('Content-Type: text/plain; charset=utf-8');

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        if (@mkdir($dir, 0755, true)) {
            echo "CREATED: {$dir}\n";
        } else {
            echo "FAILED:  {$dir}\n";
        }
    } else {
        echo "OK:      {$dir}\n";
    }
    @chmod($dir, 0755);
}

$views = $base . '/storage/framework/views';
echo "\nviews realpath: " . var_export(realpath($views), true) . "\n";
echo "Done.\n";
