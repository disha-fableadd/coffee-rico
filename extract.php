<?php

$dir = __DIR__ . '/app/Http/Controllers/Api';
$files = glob($dir . '/*.php');
$out = "";

foreach ($files as $file) {
    $content = file_get_contents($file);
    preg_match_all('/Validator::make\(\$request->all\(\),\s*\[(.*?)\]\)/s', $content, $matches);
    
    if (!empty($matches[1])) {
        $out .= "=== " . basename($file) . " ===\n";
        foreach ($matches[1] as $match) {
            $out .= trim($match) . "\n-----------------\n";
        }
    }
}

// Also get Route parameters
$apiRoutes = file_get_contents(__DIR__ . '/routes/api.php');
$out .= "\n\n=== ROUTES ===\n";
$out .= $apiRoutes;

file_put_contents(__DIR__ . '/extract_out.txt', $out);
