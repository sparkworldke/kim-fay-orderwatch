<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()->orderByDesc('timestamp');

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

        return response()->json($query->paginate(50));
    }
}
