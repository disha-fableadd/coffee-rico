<?php

$apiFile = __DIR__ . '/routes/api.php';
$content = file_get_contents($apiFile);

preg_match_all('/Route::(get|post|put|delete|match|apiResource)\s*\(\s*\[?\'(.*?)\'/', $content, $matches);

$apis = [];

foreach ($matches[2] as $index => $endpoint) {
    if ($endpoint === 'GET' || $endpoint === 'POST') continue; // from Route::match(['GET', 'POST'], '/url')
    
    // Quick fix for match route
    if (strpos($matches[0][$index], 'match') !== false) {
        $method = 'GET/POST';
    } elseif ($matches[1][$index] === 'apiResource') {
        // apiResource generates multiple routes, but we'll just add one placeholder for it
        $method = 'RESOURCE';
        $endpoint = '/' . $endpoint;
    } else {
        $method = strtoupper($matches[1][$index]);
    }
    
    // Ensure endpoint starts with /api if not public root
    $fullEndpoint = $endpoint === '/' ? '/api' : '/api/' . ltrim($endpoint, '/');
    
    // Simple grouping
    $group = 'Other';
    if (strpos($endpoint, 'auth') !== false) $group = 'Auth & Profile';
    elseif (strpos($endpoint, 'dashboard') !== false) $group = 'Dashboard';
    elseif (strpos($endpoint, 'contact') !== false) $group = 'Contacts';
    elseif (strpos($endpoint, 'group') !== false) $group = 'Groups';
    elseif (strpos($endpoint, 'template') !== false) $group = 'Templates';
    elseif (strpos($endpoint, 'bulk') !== false) $group = 'Bulk Messages';
    elseif (strpos($endpoint, 'package') !== false || strpos($endpoint, 'plan') !== false) $group = 'Packages & Plans';
    elseif (strpos($endpoint, 'credit') !== false) $group = 'Credits';
    elseif (strpos($endpoint, 'report') !== false || strpos($endpoint, 'campaign') !== false) $group = 'Reports';
    elseif (strpos($endpoint, 'setting') !== false) $group = 'Settings';
    elseif (strpos($endpoint, 'notification') !== false) $group = 'Notifications';
    elseif (strpos($endpoint, 'user') !== false) $group = 'Users';
    elseif (strpos($endpoint, 'media') !== false) $group = 'Media';
    elseif (strpos($endpoint, 'conversation') !== false || strpos($endpoint, 'message') !== false) $group = 'Live Chat';
    
    $requiresAuth = !in_array($endpoint, ['/', '/redirectwhatsapp', '/clear-cache', '/migrate', '/passport-install', 'auth/register', 'auth/login', 'whatsapp/webhook']);

    $apis[$group][] = [
        'method' => $method,
        'endpoint' => $fullEndpoint,
        'description' => 'Endpoint for ' . $fullEndpoint,
        'requires_auth' => $requiresAuth,
        'parameters' => [],
        'response' => '{"status": true}'
    ];
}

// Generate Controller Code
$controllerCode = "<?php\n\nnamespace App\Http\Controllers;\n\nuse Illuminate\Http\Request;\n\nclass ApiDocsController extends Controller\n{\n    public function index()\n    {\n        \$apis = " . var_export($apis, true) . ";\n\n        return view('api-docs', compact('apis'));\n    }\n}\n";

file_put_contents(__DIR__ . '/app/Http/Controllers/ApiDocsController.php', $controllerCode);

echo "Done generating " . count($matches[0]) . " routes.";
