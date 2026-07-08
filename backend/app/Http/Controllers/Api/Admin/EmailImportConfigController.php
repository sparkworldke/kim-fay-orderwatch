<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Models\EmailImportConfig;
use App\Models\MailboxSyncItemLog;
use App\Models\NotificationRule;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailImportConfigController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            EmailImportConfig::with(['customer:id,acumatica_id,name', 'creator:id,name,email', 'approver:id,name,email'])
                ->orderBy('display_name')
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);
        $this->authorizeConfigMutation($request, $validated);

        $config = EmailImportConfig::create($this->buildPayload($validated, $request, null));

        return response()->json($config->load(['customer:id,acumatica_id,name', 'creator:id,name,email', 'approver:id,name,email']), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $config = EmailImportConfig::findOrFail($id);
        $validated = $this->validatePayload($request, true);
        $this->authorizeConfigMutation($request, $validated, $config);

        $config->update($this->buildPayload($validated, $request, $config));

        return response()->json($config->fresh(['customer:id,acumatica_id,name', 'creator:id,name,email', 'approver:id,name,email']));
    }

    public function destroy(int $id): JsonResponse
    {
        EmailImportConfig::findOrFail($id)->delete();

        return response()->json(['message' => 'Sender config deleted.']);
    }

    /** Test whether a sender email would match any active config. */
    public function testSender(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);

        $config = EmailImportConfig::findForSender($validated['email']);

        return response()->json([
            'email'   => $validated['email'],
            'matched' => $config !== null,
            'config'  => $config,
            'branch_tag' => $config?->extractBranchTag($validated['email']),
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $config = EmailImportConfig::findOrFail($id);
        $user = $request->user();

        abort_unless($user && $user->hasPermission('email-import.approve'), 403, 'You are not authorized to approve sender configs.');

        if (! $config->requiresDualApproval()) {
            return response()->json(['message' => 'This config does not require dual approval.', 'config' => $config]);
        }

        if ($config->created_by && $config->created_by === $user->id) {
            return response()->json(['message' => 'A second administrator must approve this sender config.'], 422);
        }

        $config->update([
            'approval_status' => EmailImportConfig::APPROVAL_APPROVED,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Sender config approved.',
            'config' => $config->fresh(['customer:id,acumatica_id,name', 'creator:id,name,email', 'approver:id,name,email']),
        ]);
    }

    public function metrics(): JsonResponse
    {
        EmailImportConfig::autoDeactivateDormantExactConfigs();

        $last24Hours = now()->subDay();
        $processed = Email::query()->where('received_at', '>=', $last24Hours);
        $importedOrders = (clone $processed)->where('ingestion_classification', 'po_processing')->count();
        $unrecognized = (clone $processed)->where('import_guardrail_status', 'unrecognized')->count();
        $matched = (clone $processed)->where('import_guardrail_status', 'matched')->count();
        $successRate = $importedOrders + $unrecognized > 0
            ? round(($matched / max(1, $importedOrders + $unrecognized)) * 100, 2)
            : 100.0;

        $metrics = [
            'imported_orders_last_24h' => $importedOrders,
            'unrecognized_emails_last_24h' => $unrecognized,
            'success_rate' => $successRate,
            'pending_approvals' => EmailImportConfig::where('approval_status', EmailImportConfig::APPROVAL_PENDING)->count(),
            'auto_deactivated_configs' => EmailImportConfig::whereNotNull('auto_deactivated_at')->count(),
            'reason_counts' => MailboxSyncItemLog::query()
                ->where('processed_at', '>=', $last24Hours)
                ->selectRaw('reason, COUNT(*) as count')
                ->groupBy('reason')
                ->orderByDesc('count')
                ->limit(8)
                ->get(),
        ];

        $this->dispatchLowSuccessRateAlert($metrics['success_rate']);

        return response()->json($metrics);
    }

    private function validatePayload(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'sender_pattern'        => [($partial ? 'sometimes' : 'required'), 'string', 'max:500'],
            'match_mode'            => [($partial ? 'sometimes' : 'required'), 'in:exact,wildcard,regex'],
            'is_wildcard'           => ['sometimes', 'boolean'],
            'display_name'          => [($partial ? 'sometimes' : 'required'), 'string', 'max:255'],
            'customer_id'           => ['sometimes', 'nullable', 'integer', 'exists:acumatica_customers,id'],
            'branch_name'           => ['sometimes', 'nullable', 'string', 'max:255'],
            'branch_tag_pattern'    => ['sometimes', 'nullable', 'string', 'max:500'],
            'customer_class'        => ['sometimes', 'nullable', 'string', 'max:100'],
            'po_patterns'           => ['sometimes', 'nullable', 'array'],
            'po_patterns.*'         => ['string'],
            'po_extraction_source'  => ['sometimes', 'in:subject,body,pdf,all'],
            'ai_fallback_enabled'   => ['sometimes', 'boolean'],
            'is_active'             => ['sometimes', 'boolean'],
            'notes'                 => ['sometimes', 'nullable', 'string'],
        ]);
    }

    private function authorizeConfigMutation(Request $request, array $validated, ?EmailImportConfig $existing = null): void
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $matchMode = $validated['match_mode'] ?? $existing?->match_mode ?? EmailImportConfig::MATCH_MODE_EXACT;

        if (in_array($matchMode, [EmailImportConfig::MATCH_MODE_WILDCARD, EmailImportConfig::MATCH_MODE_REGEX], true)
            && ! $user->hasPermission('email-import.create-wildcards')) {
            abort(403, 'You are not authorized to create wildcard sender rules.');
        }

        if ($matchMode === EmailImportConfig::MATCH_MODE_REGEX) {
            $pattern = $validated['sender_pattern'] ?? $existing?->sender_pattern ?? '';
            if (! EmailImportConfig::isSafeRegexPattern($pattern)) {
                abort(422, 'Regex wildcard patterns must stay scoped to approved Chandara domains.');
            }
        }
    }

    private function buildPayload(array $validated, Request $request, ?EmailImportConfig $existing): array
    {
        $user = $request->user();
        $matchMode = $validated['match_mode'] ?? $existing?->match_mode ?? EmailImportConfig::MATCH_MODE_EXACT;
        $payload = $validated;
        $payload['sender_pattern'] = strtolower(trim((string) ($validated['sender_pattern'] ?? $existing?->sender_pattern)));
        $payload['match_mode'] = $matchMode;
        $payload['is_wildcard'] = $matchMode !== EmailImportConfig::MATCH_MODE_EXACT;
        $payload['created_by'] = $existing?->created_by ?? $user?->id;

        if ($existing === null) {
            $payload['approval_status'] = $matchMode === EmailImportConfig::MATCH_MODE_EXACT
                ? EmailImportConfig::APPROVAL_PENDING
                : EmailImportConfig::APPROVAL_APPROVED;
            $payload['approved_by'] = $matchMode === EmailImportConfig::MATCH_MODE_EXACT ? null : $user?->id;
            $payload['approved_at'] = $matchMode === EmailImportConfig::MATCH_MODE_EXACT ? null : now();
            $payload['is_active'] = $matchMode === EmailImportConfig::MATCH_MODE_EXACT
                ? false
                : ($validated['is_active'] ?? true);
        } elseif (($validated['sender_pattern'] ?? null) !== null || ($validated['match_mode'] ?? null) !== null) {
            if ($matchMode === EmailImportConfig::MATCH_MODE_EXACT) {
                $payload['approval_status'] = EmailImportConfig::APPROVAL_PENDING;
                $payload['approved_by'] = null;
                $payload['approved_at'] = null;
                $payload['is_active'] = false;
            }
        }

        return $payload;
    }

    private function dispatchLowSuccessRateAlert(float $successRate): void
    {
        if ($successRate >= 99) {
            return;
        }

        $rule = NotificationRule::query()->firstWhere('rule_key', 'R7');
        if ($rule && ! $rule->is_enabled) {
            return;
        }

        $subject = sprintf('[Email Import] Success rate dropped to %.2f%%', $successRate);
        $body = sprintf(
            "Email import success rate over the last 24 hours is %.2f%%.\n\nReview mailbox guardrails and unmatched senders in the dashboard.",
            $successRate,
        );

        $recipients = User::query()
            ->whereIn('role', ['Administrator', 'Customer Service Manager'])
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();

        foreach ($recipients as $email) {
            try {
                Mail::raw($body, fn ($message) => $message->to($email)->subject($subject));
            } catch (\Throwable $exception) {
                Log::error('email_import_alert_send_failed', ['to' => $email, 'error' => $exception->getMessage()]);
            }
        }

        $webhook = trim((string) env('SLACK_WEBHOOK_URL', ''));
        if ($webhook !== '') {
            Http::post($webhook, ['text' => $subject]);
        }
    }
}
