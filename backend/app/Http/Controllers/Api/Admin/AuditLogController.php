<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Admin\UserActivityExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()
            ->with(['actor:id,name,email,role'])
            ->orderByDesc('timestamp');

        foreach (['actor_user_id', 'action_type', 'resource_type'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('start_date')) {
            $query->where('timestamp', '>=', $request->input('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->where('timestamp', '<=', $request->input('end_date'));
        }

        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($builder) use ($q) {
                $builder->where('action_type', 'like', "%{$q}%")
                    ->orWhere('resource_type', 'like', "%{$q}%")
                    ->orWhere('resource_id', 'like', "%{$q}%")
                    ->orWhereHas('actor', fn ($u) => $u
                        ->where('name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%"));
            });
        }

        $page = $query->paginate(min(100, max(1, $request->integer('per_page', 50))));

        $page->getCollection()->transform(function (AuditLog $entry) {
            $actor = $entry->actor;

            return [
                'id' => $entry->id,
                'timestamp' => $entry->timestamp?->toIso8601String() ?? (string) $entry->getRawOriginal('timestamp'),
                'actor_user_id' => $entry->actor_user_id,
                'actor_name' => $actor?->name,
                'actor_email' => $actor?->email,
                'actor_role' => $actor?->role,
                'actor_label' => $actor
                    ? trim($actor->name.($actor->email ? " <{$actor->email}>" : ''))
                    : ($entry->actor_user_id ? "User #{$entry->actor_user_id}" : 'system'),
                'actor_ip' => $entry->actor_ip,
                'action_type' => $entry->action_type,
                'resource_type' => $entry->resource_type,
                'resource_id' => $entry->resource_id,
                'changes' => $entry->changes,
            ];
        });

        return response()->json($page);
    }

    /**
     * Excel export: sheet "Login" + sheet "Activity" (pages, downloads, audit actions).
     */
    public function export(Request $request, UserActivityExportService $exporter): StreamedResponse
    {
        $filters = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'actor_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        return $exporter->streamExcel($filters);
    }
}

