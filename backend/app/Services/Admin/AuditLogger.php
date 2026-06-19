<?php

namespace App\Services\Admin;

use App\Models\AuditLog;
use Illuminate\Support\Str;
use Throwable;

class AuditLogger
{
    public function __construct(private readonly EncryptionService $encryption)
    {
    }

    public function log(
        string $actionType,
        string $resourceType,
        string|int|null $resourceId,
        array $changes = [],
        ?int $actorUserId = null,
        ?string $actorIp = null,
    ): void {
        try {
            AuditLog::create([
                'id' => (string) Str::uuid(),
                'timestamp' => now('UTC')->format('Y-m-d H:i:s.u'),
                'actor_user_id' => $actorUserId,
                'actor_ip' => $actorIp,
                'action_type' => $actionType,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId === null ? null : (string) $resourceId,
                'changes' => $this->maskChanges($changes),
            ]);
        } catch (Throwable $exception) {
            StructuredLogger::write('error', 'audit', 'audit_log_write_failed', [
                'message' => $exception->getMessage(),
            ], $actorUserId, $actorIp);
        }
    }

    private function maskChanges(array $changes): array
    {
        foreach ($changes as $key => $value) {
            if (is_array($value)) {
                $changes[$key] = $this->maskChanges($value);
                continue;
            }

            if (is_string($value) && preg_match('/(key|secret|password|token|credential)/i', (string) $key)) {
                $changes[$key] = $this->encryption->maskCredential($value);
            }
        }

        return $changes;
    }
}
