<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Pricing\PriceChangeRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PriceChangeSettingsController extends Controller
{
    public function __construct(private readonly PriceChangeRequestService $pcr) {}

    public function show(Request $request): JsonResponse
    {
        $this->pcr->ensureCan($request->user(), 'pricing.pcr.config');

        return response()->json([
            'settings' => $this->pcr->settings(),
            'stages' => $this->pcr->stages(activeOnly: false),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->pcr->ensureCan($request->user(), 'pricing.pcr.config');
        $validated = $request->validate([
            'margin_floor_pct' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'erp_updater_roles' => ['sometimes', 'array'],
            'erp_updater_roles.*' => ['string', 'max:100'],
            'erp_updater_emails' => ['sometimes', 'array'],
            'erp_updater_emails.*' => ['email', 'max:255'],
            'mail_from_address' => ['sometimes', 'email', 'max:255'],
            'mail_from_name' => ['sometimes', 'string', 'max:255'],
            'allow_admin_testing_override' => ['sometimes', 'boolean'],
        ]);

        return response()->json([
            'settings' => $this->pcr->saveSettings($validated),
            'stages' => $this->pcr->stages(activeOnly: false),
        ]);
    }
}
