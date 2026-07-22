<?php

$file = __DIR__ . '/app/Http/Controllers/ApiDocsController.php';
$content = file_get_contents($file);

$content = preg_replace_callback('/(\[\'name\'\s*=>\s*\'(.*?)\',\s*\'type\'\s*=>\s*\'(.*?)\',\s*\'required\'\s*=>\s*(true|false),\s*\'description\'\s*=>\s*\'(.*?)\')(\])/', function($m) {
    $name = $m[2];
    $type = $m[3];
    
    $example = '';
    if (strpos(strtolower($name), 'email') !== false) {
        $example = 'john@example.com';
    } elseif (strpos(strtolower($name), 'password') !== false) {
        $example = 'secret123';
    } elseif (strpos(strtolower($name), 'name') !== false) {
        $example = 'John Doe';
    } elseif (strpos(strtolower($name), 'number') !== false || strpos(strtolower($name), 'phone') !== false) {
        $example = '+1234567890';
    } elseif (strpos(strtolower($name), 'id') !== false) {
        $example = '1';
    } elseif (strpos($type, 'integer') !== false || strpos($type, 'numeric') !== false) {
        $example = '100';
    } elseif (strpos($type, 'array') !== false) {
        $example = '[1, 2, 3]';
    } elseif (strpos($type, 'file') !== false) {
        $example = 'file_upload.jpg';
    } else {
        $example = 'example_value';
    }
    
    return $m[1] . ", 'example' => '" . $example . "'" . $m[6];
}, $content);

file_put_contents($file, $content);

echo "Updated!";
