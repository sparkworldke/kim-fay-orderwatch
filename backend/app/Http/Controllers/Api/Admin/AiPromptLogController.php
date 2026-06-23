<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiPromptLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiPromptLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AiPromptLog::with('user:id,name,email')
            ->orderByDesc('created_at');

        if ($request->filled('intent')) {
            $query->where('intent', $request->input('intent'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        if ($request->filled('start_date')) {
            $query->where('created_at', '>=', $request->input('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->where('created_at', '<=', $request->input('end_date'));
        }

        return response()->json($query->paginate(50));
    }

    public function stats(): JsonResponse
    {
        $stats = [
            'total'          => AiPromptLog::count(),
            'success'        => AiPromptLog::where('status', 'success')->count(),
            'failed'         => AiPromptLog::where('status', 'failed')->count(),
            'avg_response_ms'=> (int) AiPromptLog::avg('response_time_ms'),
            'by_intent'      => AiPromptLog::selectRaw('intent, COUNT(*) as count')
                ->whereNotNull('intent')
                ->groupBy('intent')
                ->orderByDesc('count')
                ->pluck('count', 'intent'),
            'by_provider'    => AiPromptLog::selectRaw('provider, COUNT(*) as count')
                ->whereNotNull('provider')
                ->groupBy('provider')
                ->pluck('count', 'provider'),
        ];

        return response()->json($stats);
    }
}
