<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationRule;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\AuditLogger;
use App\Services\Admin\NotificationRulesConfigMailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationRuleController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationRulesConfigMailService $configMail,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json(NotificationRule::with([
            'emailRecipients:id,name,email',
            'roleRecipients:id,name',
        ])->orderBy('rule_key')->get()->map(fn (NotificationRule $rule) => $this->present($rule)));
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_enabled' => ['sometimes', 'boolean'],
            'recipient_emails' => ['sometimes', 'array'],
            'recipient_emails.*' => ['email', Rule::exists('users', 'email')->where('is_active', true)],
            'recipient_roles' => ['sometimes', 'array'],
            'recipient_roles.*' => ['string', Rule::exists('roles', 'name')],
        ]);

        $rule = NotificationRule::with(['emailRecipients:id,email', 'roleRecipients:id,name'])->findOrFail($id);
        $before = [
            ...$rule->only(['is_enabled']),
            'recipient_emails' => $rule->emailRecipients->pluck('email')->values()->all(),
            'recipient_roles' => $rule->roleRecipients->pluck('name')->values()->all(),
        ];

        if (array_key_exists('is_enabled', $validated)) {
            $rule->forceFill(['is_enabled' => $validated['is_enabled']])->save();
        }

        if (array_key_exists('recipient_emails', $validated)) {
            $userIds = User::query()
                ->where('is_active', true)
                ->whereIn('email', array_map('strtolower', $validated['recipient_emails']))
                ->pluck('id')
                ->all();
            $rule->emailRecipients()->sync($userIds);
        }

        if (array_key_exists('recipient_roles', $validated)) {
            $roleIds = Role::query()
                ->whereIn('name', $validated['recipient_roles'])
                ->pluck('id')
                ->all();
            $rule->roleRecipients()->sync($roleIds);
        }

        $fresh = $rule->fresh(['emailRecipients:id,name,email', 'roleRecipients:id,name']);

        $this->audit->log('notification_rule_updated', 'notification_rule', $rule->id, [
            'before' => $before,
            'after' => [
                ...$fresh->only(['is_enabled']),
                'recipient_emails' => $fresh->emailRecipients->pluck('email')->values()->all(),
                'recipient_roles' => $fresh->roleRecipients->pluck('name')->values()->all(),
            ],
        ], $request->user()?->id, $request->ip());

        return response()->json($this->present($fresh));
    }

    public function sendConfig(Request $request): JsonResponse
    {
        try {
            $result = $this->configMail->send();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to send notification rules configuration email.'], 500);
        }

        $this->audit->log('notification_rules_config_sent', 'notification_rule', null, [
            'recipient' => $result['recipient'],
            'rule_count' => $result['rule_count'],
        ], $request->user()?->id, $request->ip());

        return response()->json([
            'message' => 'Notification rules configuration sent.',
            'recipient' => $result['recipient'],
            'rule_count' => $result['rule_count'],
        ]);
    }

    private function present(NotificationRule $rule): array
    {
        return [
            ...$rule->attributesToArray(),
            'recipient_emails' => $rule->emailRecipients->pluck('email')->values()->all(),
            'recipient_roles' => $rule->roleRecipients->pluck('name')->values()->all(),
        ];
    }
}
