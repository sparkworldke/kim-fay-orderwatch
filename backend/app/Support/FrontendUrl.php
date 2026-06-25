<?php

namespace App\Support;

class FrontendUrl
{
    public static function base(): string
    {
        return rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
    }

    public static function path(string $path = ''): string
    {
        $normalized = '/'.ltrim($path, '/');

        if ($normalized === '/') {
            return self::base();
        }

        return self::base().$normalized;
    }
}