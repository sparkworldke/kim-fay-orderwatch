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
            // Full replace of the dynamic PCR notification list.
            'mail_recipients' => ['sometimes', 'array'],
            'mail_recipients.*' => ['email', 'max:255'],
            // Incremental add/remove without replacing the whole list.
            'attach_mail_recipients' => ['sometimes', 'array'],
            'attach_mail_recipients.*' => ['email', 'max:255'],
            'detach_mail_recipients' => ['sometimes', 'array'],
            'detach_mail_recipients.*' => ['email', 'max:255'],
            'allow_admin_testing_override' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('attach_mail_recipients', $validated)) {
            $this->pcr->attachMailRecipients($validated['attach_mail_recipients']);
            unset($validated['attach_mail_recipients']);
        }
        if (array_key_exists('detach_mail_recipients', $validated)) {
            $this->pcr->detachMailRecipients($validated['detach_mail_recipients']);
            unset($validated['detach_mail_recipients']);
        }

        $settings = $validated === []
            ? $this->pcr->settings()
            : $this->pcr->saveSettings($validated);

        return response()->json([
            'settings' => $settings,
            'stages' => $this->pcr->stages(activeOnly: false),
        ]);
    }
}
