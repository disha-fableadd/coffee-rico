<?php

namespace App\Support;

class UploadPath
{
    /**
     * Shared hosting (docroot = /services, not /services/public) needs /public in URLs.
     */
    public static function needsPublicPrefix(): bool
    {
        $url = strtolower((string) config('app.url'));

        return str_contains($url, 'fableadtech.com')
            || str_contains($url, 'fableadtech.in');
    }

    /**
     * Path stored in DB / returned to clients, e.g. /public/uploads/company/file.jpg
     */
    public static function store(string $relativePath): string
    {
        $relativePath = '/' . ltrim(str_replace('\\', '/', $relativePath), '/');

        if (self::needsPublicPrefix() && !str_starts_with($relativePath, '/public/')) {
            return '/public' . $relativePath;
        }

        return $relativePath;
    }

    /**
     * Absolute public URL using APP_URL (includes /services on production).
     */
    public static function url(?string $storedPath, string $defaultRelative = 'images/default-avatar.svg'): string
    {
        $path = $storedPath ? self::normalizeForUrl($storedPath) : self::store($defaultRelative);

        return rtrim((string) config('app.url'), '/') . '/' . ltrim($path, '/');
    }

    /**
     * Filesystem path under public/ for a DB-stored path.
     */
    public static function disk(?string $storedPath): ?string
    {
        if (!$storedPath) {
            return null;
        }

        $relative = str_replace('\\', '/', $storedPath);
        $relative = preg_replace('#^/public/#', '/', $relative) ?? $relative;
        $relative = ltrim($relative, '/');

        return public_path($relative);
    }

    public static function exists(?string $storedPath): bool
    {
        $disk = self::disk($storedPath);

        return $disk && is_file($disk);
    }

    public static function delete(?string $storedPath): void
    {
        $disk = self::disk($storedPath);
        if ($disk && is_file($disk)) {
            @unlink($disk);
        }
    }

    private static function normalizeForUrl(string $storedPath): string
    {
        $path = '/' . ltrim(str_replace('\\', '/', $storedPath), '/');

        if (self::needsPublicPrefix() && !str_contains($path, '/public/')) {
            $path = '/public' . $path;
        }

        return $path;
    }
}
