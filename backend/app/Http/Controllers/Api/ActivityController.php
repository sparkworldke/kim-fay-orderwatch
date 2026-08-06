<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserActivityLog;
use App\Services\Team\BrandAssignmentScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    /**
     * Record a page navigation (or other light activity) for the authenticated user.
     * Debounced on the client; throttled server-side.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'activity_type' => ['sometimes', 'string', 'in:page_view,download,action'],
            'path' => ['required', 'string', 'max:500'],
            'page_title' => ['nullable', 'string', 'max:255'],
            'meta' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $type = $validated['activity_type'] ?? UserActivityLog::TYPE_PAGE_VIEW;
        $path = '/'.ltrim($validated['path'], '/');

        // Collapse rapid duplicate page_views of the same path (10s window).
        if ($type === UserActivityLog::TYPE_PAGE_VIEW) {
            $recent = UserActivityLog::query()
                ->where('user_id', $user->id)
                ->where('activity_type', UserActivityLog::TYPE_PAGE_VIEW)
                ->where('path', $path)
                ->where('created_at', '>=', now()->subSeconds(10))
                ->exists();
            if ($recent) {
                return response()->json(['message' => 'ok', 'deduped' => true]);
            }
        }

        $meta = $validated['meta'] ?? [];
        if ($user->isPartnerBrandsUser()) {
            $meta['partner_brand_scope'] = [
                'is_hod' => $user->department_role === 'hod',
                'brands' => app(BrandAssignmentScope::class)->allowedBrands($user) ?? [],
            ];
        }

        UserActivityLog::create([
            'user_id' => $user->id,
            'activity_type' => $type,
            'path' => $path,
            'page_title' => $validated['page_title'] ?? null,
            'method' => 'GET',
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 2000),
            'meta' => $meta !== [] ? $meta : null,
        ]);

        return response()->json(['message' => 'ok'], 201);
    }
}
