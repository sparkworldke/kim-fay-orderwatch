<?php

namespace App\Support;

class FrontendUrl
{
    public static function base(): string
    {
        $configured = trim((string) config('app.frontend_url', ''));

        if ($configured === '') {
            $configured = trim((string) config('services.microsoft.frontend_url', ''));
        }

        if ($configured === '') {
            $configured = 'http://localhost:5173';
        }

        return rtrim($configured, '/');
    }

    /** @param array<string, scalar|null> $query */
    public static function path(string $path = '', array $query = []): string
    {
        $normalized = '/'.ltrim($path, '/');
        $base = $normalized === '/' ? self::base() : self::base().$normalized;

        if ($query === []) {
            return $base;
        }

        $queryString = http_build_query(array_filter($query, fn (mixed $value) => $value !== null && $value !== ''));

        return $queryString === '' ? $base : "{$base}?{$queryString}";
    }
}
