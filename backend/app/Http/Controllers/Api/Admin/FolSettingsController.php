<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AuditLogger;
use App\Services\Fol\FolSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FolSettingsController extends Controller
{
    public function __construct(
        private readonly FolSettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->ensureAdmin($request->user());

        $payload = $this->settings->all();
        $payload['users'] = User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
            ])
            ->values();

        return response()->json($payload);
    }

    public function update(Request $request): JsonResponse
    {
        $this->ensureAdmin($request->user());

        $validated = $request->validate([
            'mail_from_address' => ['sometimes', 'email', 'max:255'],
            'mail_from_name' => ['sometimes', 'string', 'max:100'],
            'max_attachment_kb' => ['sometimes', 'integer', 'min:100', 'max:102400'],
            'attachment_mimes' => ['sometimes', 'array', 'min:1'],
            'attachment_mimes.*' => ['string', 'max:20'],
            'invoicing_roles' => ['sometimes', 'array', 'min:1'],
            'invoicing_roles.*' => ['string', 'max:100'],
            'cc_watcher_emails' => ['sometimes', 'array'],
            'cc_watcher_emails.*' => ['email', 'max:255'],
            'duplicate_policy' => ['sometimes', Rule::in(['block', 'warn', 'allow'])],
            'consumables_months' => ['sometimes', 'integer', 'min:1', 'max:24'],
            'require_attachment' => ['sometimes', 'boolean'],
            'allow_admin_on_all_stages' => ['sometimes', 'boolean'],
        ]);

        $result = $this->settings->updateSettings($validated);

        $this->audit->log('fol_settings_updated', 'fol_settings', 'global', [
            'keys' => array_keys($validated),
        ], $request->user()?->id, $request->ip());

        return response()->json($result);
    }

    public function updateStages(Request $request): JsonResponse
    {
        $this->ensureAdmin($request->user());

        $validated = $request->validate([
            'stages' => ['required', 'array', 'min:1'],
            'stages.*.key' => ['required', 'string', 'max:50'],
            'stages.*.name' => ['required', 'string', 'max:100'],
            'stages.*.sort_order' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'stages.*.is_active' => ['sometimes', 'boolean'],
            'stages.*.assignee_mode' => ['sometimes', Rule::in(['role', 'user_list', 'manager_of_submitter'])],
            'stages.*.role_names' => ['sometimes', 'array'],
            'stages.*.role_names.*' => ['string', 'max:100'],
            'stages.*.user_ids' => ['sometimes', 'array'],
            'stages.*.user_ids.*' => ['integer', 'exists:users,id'],
            'stages.*.require_comment' => ['sometimes', 'boolean'],
            'stages.*.sla_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
        ]);

        // Each stage must have at least one assignee path
        foreach ($validated['stages'] as $i => $stage) {
            $roles = $stage['role_names'] ?? [];
            $users = $stage['user_ids'] ?? [];
            if (($stage['is_active'] ?? true) && $roles === [] && $users === []) {
                return response()->json([
                    'message' => 'Each active stage needs at least one role or user assignee.',
                    'errors' => ["stages.{$i}" => ['Add role_names or user_ids.']],
                ], 422);
            }
        }

        $stages = $this->settings->syncStages($validated['stages']);

        $this->audit->log('fol_stages_updated', 'fol_approval_stages', 'global', [
            'stage_count' => count($stages),
            'keys' => array_column($stages, 'key'),
        ], $request->user()?->id, $request->ip());

        return response()->json([
            'message' => 'FOL approval stages updated.',
            'stages' => $stages,
        ]);
    }

    private function ensureAdmin(?User $user): void
    {
        if (! $user || ($user->role !== 'Administrator' && ! $user->is_super_admin)) {
            abort(403, 'Only administrators can manage FOL settings.');
        }
    }
}
