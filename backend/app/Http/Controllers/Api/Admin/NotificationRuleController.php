<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationRule;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationRuleController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json(NotificationRule::orderBy('rule_key')->get());
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_enabled' => ['required', 'boolean'],
        ]);

        $rule = NotificationRule::findOrFail($id);
        $before = $rule->only(['is_enabled']);
        $rule->forceFill(['is_enabled' => $validated['is_enabled']])->save();

        $this->audit->log('notification_rule_updated', 'notification_rule', $rule->id, [
            'before' => $before,
            'after' => $rule->only(['is_enabled']),
        ], $request->user()?->id, $request->ip());

        return response()->json($rule->fresh());
    }
}
