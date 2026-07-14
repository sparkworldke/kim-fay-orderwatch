<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\SalesManagement\SalesManagementPromptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesManagementSettingsController extends Controller
{
    public function __construct(private readonly SalesManagementPromptService $service) {}

    public function show(Request $request): JsonResponse
    {
        $this->service->ensureCan($request->user(), 'sales.management.config');

        return response()->json(['settings' => $this->service->settings()]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->service->ensureCan($request->user(), 'sales.management.config');
        $validated = $request->validate([
            'cycle_history_orders' => ['sometimes', 'integer', 'min:2', 'max:12'],
            'cycle_due_multiplier' => ['sometimes', 'numeric', 'min:1', 'max:3'],
            'cycle_overdue_multiplier' => ['sometimes', 'numeric', 'min:1', 'max:5'],
            'max_snooze_days' => ['sometimes', 'integer', 'min:1', 'max:180'],
            'stale_so_sync_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'month_gap_statuses' => ['sometimes', 'array'],
            'month_gap_statuses.*' => ['string', 'max:60'],
        ]);

        return response()->json(['settings' => $this->service->saveSettings($validated)]);
    }

    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', 'date_format:Y-m'],
            'force' => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->service->generate(
            $request->user(),
            $validated['period'] ?? null,
            (bool) ($validated['force'] ?? false),
        ));
    }
}
