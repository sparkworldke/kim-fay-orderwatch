<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliverySlaConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliverySlaConfigController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            DeliverySlaConfig::query()
                ->orderBy('region_key')
                ->get()
                ->map(fn (DeliverySlaConfig $config) => $config->toPublicArray())
                ->values(),
        );
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rules' => ['required', 'array', 'min:1'],
            'rules.*.region_key' => ['required', 'string', 'max:50'],
            'rules.*.label' => ['required', 'string', 'max:100'],
            'rules.*.sla_hours' => ['required', 'integer', 'min:1', 'max:240'],
            'rules.*.warning_hours' => ['nullable', 'integer', 'min:1', 'max:240'],
            'rules.*.breach_hours' => ['nullable', 'integer', 'min:1', 'max:240'],
            'rules.*.is_metro' => ['required', 'boolean'],
            'rules.*.is_active' => ['required', 'boolean'],
            'rules.*.alert_min_orders' => ['required', 'integer', 'min:1', 'max:1000'],
            'rules.*.alert_delayed_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'rules.*.clock_start' => ['required', 'string', 'in:order_date,approved_at,ship_date'],
        ]);

        foreach ($validated['rules'] as $row) {
            DeliverySlaConfig::query()->updateOrCreate(
                ['region_key' => strtolower($row['region_key'])],
                [
                    'label' => $row['label'],
                    'sla_hours' => $row['sla_hours'],
                    'warning_hours' => $row['warning_hours'],
                    'breach_hours' => $row['breach_hours'] ?? $row['sla_hours'],
                    'is_metro' => $row['is_metro'],
                    'is_active' => $row['is_active'],
                    'alert_min_orders' => $row['alert_min_orders'],
                    'alert_delayed_pct' => $row['alert_delayed_pct'],
                    'clock_start' => $row['clock_start'],
                ],
            );
        }

        return response()->json(
            DeliverySlaConfig::query()
                ->orderBy('region_key')
                ->get()
                ->map(fn (DeliverySlaConfig $config) => $config->toPublicArray())
                ->values(),
        );
    }
}