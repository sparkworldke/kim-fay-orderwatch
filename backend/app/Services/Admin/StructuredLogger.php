<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Log;

class StructuredLogger
{
    public static function write(
        string $level,
        string $service,
        string $event,
        array $context = [],
        ?int $userId = null,
        ?string $ip = null,
    ): void {
        Log::channel('daily')->log($level, json_encode([
            'timestamp' => now()->toISOString(),
            'level' => $level,
            'service' => $service,
            'event' => $event,
            'user_id' => $userId,
            'ip_address' => $ip,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES));
    }
}
