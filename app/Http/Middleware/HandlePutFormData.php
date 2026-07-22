<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandlePutFormData
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only handle PUT requests with multipart/form-data
        if ($request->isMethod('PUT') && str_contains($request->header('Content-Type', ''), 'multipart/form-data')) {
            $rawContent = $request->getContent();
            
            if (strpos($rawContent, 'form-data') !== false) {
                // Parse multipart form data
                $lines = explode("\n", $rawContent);
                $data = [];
                $currentKey = null;
                
                foreach ($lines as $line) {
                    if (preg_match('/name="([^"]+)"/', $line, $matches)) {
                        $currentKey = $matches[1];
                    } elseif ($currentKey && trim($line) && !strpos($line, '--')) {
                        $data[$currentKey] = trim($line);
                        $currentKey = null;
                    }
                }
                
                // Merge the parsed data into the request
                $request->merge($data);
            }
        }
        
        return $next($request);
    }
}