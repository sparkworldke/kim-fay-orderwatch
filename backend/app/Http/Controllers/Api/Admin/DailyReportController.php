<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DailyReportConfig;
use App\Models\DailyReportRun;
use App\Services\Admin\AuditLogger;
use App\Services\Reports\DailyReportRunnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailyReportController extends Controller
{
    public function __construct(
        private readonly DailyReportRunnerService $runner,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): JsonResponse
    {
        $config = DailyReportConfig::singleton()->load('latestRun');

        return response()->json($this->presentConfig($config));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'is_enabled' => ['sometimes', 'boolean'],
            'send_time' => ['sometimes', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'recipients' => ['sometimes', 'array'],
            'recipients.*' => ['email'],
            'reply_to' => ['sometimes', 'array'],
            'reply_to.*' => ['email'],
            'send_to' => ['sometimes', 'array'],
            'send_to.*' => ['email'],
            'cc' => ['sometimes', 'array'],
            'cc.*' => ['email'],
            'subject_template' => ['sometimes', 'string', 'max:255'],
            'include_ai_insights' => ['sometimes', 'boolean'],
            'include_comparison' => ['sometimes', 'boolean'],
            'include_mtd' => ['sometimes', 'boolean'],
            'include_customer_highlights' => ['sometimes', 'boolean'],
        ]);

        $config = DailyReportConfig::singleton();
        $before = $config->only([
            'is_enabled', 'send_time', 'timezone', 'recipients_json', 'reply_to_json', 'subject_template',
            'include_ai_insights', 'include_comparison', 'include_mtd', 'include_customer_highlights',
        ]);

        $sendTo = null;
        $cc = null;

        if (isset($validated['send_to'])) {
            $sendTo = $validated['send_to'];
            unset($validated['send_to']);
        }
        if (isset($validated['cc'])) {
            $cc = $validated['cc'];
            unset($validated['cc']);
        }

        if (isset($validated['reply_to']) && $sendTo === null) {
            $sendTo = $validated['reply_to'];
            unset($validated['reply_to']);
        }
        if (isset($validated['recipients']) && $cc === null) {
            $cc = $validated['recipients'];
            unset($validated['recipients']);
        }

        if ($sendTo !== null || $cc !== null) {
            $sendTo = $sendTo ?? $config->replyTo();
            $cc = $cc ?? array_values(array_diff($config->recipients(), $sendTo));
            $validated['reply_to_json'] = $sendTo;
            $validated['recipients_json'] = array_values(array_unique(array_merge($sendTo, $cc)));
        }

        $config->update($validated);

        $this->audit->log('daily_report_config_updated', 'daily_report_config', $config->id, [
            'before' => $before,
            'after' => $config->fresh()->only(array_keys($before)),
        ], $request->user()?->id, $request->ip());

        return response()->json($this->presentConfig($config->fresh('latestRun')));
    }

    public function testSend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'recipients' => ['sometimes', 'array'],
            'recipients.*' => ['email'],
            'send_to' => ['sometimes', 'array'],
            'send_to.*' => ['email'],
            'cc' => ['sometimes', 'array'],
            'cc.*' => ['email'],
        ]);

        $config = DailyReportConfig::singleton();
        $sendTo = $validated['send_to'] ?? null;
        $cc = $validated['cc'] ?? ($validated['recipients'] ?? null);

        if (($sendTo ?? $config->replyTo()) === [] && ($cc ?? $config->recipients()) === []) {
            return response()->json(['message' => 'Add at least one Reply-To or CC recipient before sending a test.'], 422);
        }

        $run = $this->runner->run($config, 'manual_test', true, null, $sendTo, $cc);

        $this->audit->log('daily_report_test_sent', 'daily_report_run', $run->id, [
            'status' => $run->status,
            'recipient_count' => $run->recipient_count,
        ], $request->user()?->id, $request->ip());

        return response()->json([
            'message' => $run->status === 'completed' ? 'Test report sent.' : 'Test report finished with issues.',
            'run' => $this->presentRun($run),
        ], $run->status === 'failed' ? 500 : 200);
    }

    public function resendLast(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'recipients' => ['sometimes', 'array'],
            'recipients.*' => ['email'],
            'send_to' => ['sometimes', 'array'],
            'send_to.*' => ['email'],
            'cc' => ['sometimes', 'array'],
            'cc.*' => ['email'],
        ]);

        $config = DailyReportConfig::singleton();
        $sendTo = $validated['send_to'] ?? null;
        $cc = $validated['cc'] ?? ($validated['recipients'] ?? null);
        $run = $this->runner->resendLast($config, $sendTo, $cc);

        if (! $run) {
            return response()->json(['message' => 'No completed report is available to resend.'], 404);
        }

        $this->audit->log('daily_report_resent', 'daily_report_run', $run->id, [
            'status' => $run->status,
        ], $request->user()?->id, $request->ip());

        return response()->json([
            'message' => $run->status === 'completed' ? 'Last report resent.' : 'Resend finished with issues.',
            'run' => $this->presentRun($run),
        ], $run->status === 'failed' ? 500 : 200);
    }

    public function runs(Request $request): JsonResponse
    {
        $config = DailyReportConfig::singleton();

        $runs = DailyReportRun::query()
            ->where('report_config_id', $config->id)
            ->latest('report_date')
            ->latest('id')
            ->paginate(min(50, max(10, $request->integer('per_page', 20))));

        $runs->getCollection()->transform(fn (DailyReportRun $run) => $this->presentRun($run));

        return response()->json($runs);
    }

    private function presentConfig(DailyReportConfig $config): array
    {
        $latest = $config->latestRun;
        $sendTo = $config->replyTo();
        $cc = array_values(array_diff($config->recipients(), $sendTo));

        return [
            'id' => $config->id,
            'name' => $config->name,
            'is_enabled' => $config->is_enabled,
            'send_time' => $config->send_time,
            'timezone' => $config->timezone,
            'send_to' => $sendTo,
            'cc' => $cc,
            'recipients' => $config->recipients(),
            'reply_to' => $config->replyTo(),
            'subject_template' => $config->subject_template,
            'include_ai_insights' => $config->include_ai_insights,
            'include_comparison' => $config->include_comparison,
            'include_mtd' => $config->include_mtd,
            'include_customer_highlights' => $config->include_customer_highlights,
            'last_sent_at' => $latest?->sent_at?->toIso8601String(),
            'last_sent_status' => $latest?->status,
            'last_delivery_status' => $latest?->delivery_status,
            'last_run' => $latest ? $this->presentRun($latest) : null,
            'command_reference' => 'php artisan orderwatch:send-daily-report-fixed --source=manual',
            'scheduler_reference' => 'Runs every minute; sends at configured send_time in configured timezone.',
        ];
    }

    private function presentRun(DailyReportRun $run): array
    {
        return [
            'id' => $run->id,
            'report_date' => $run->report_date?->toDateString(),
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'sent_at' => $run->sent_at?->toIso8601String(),
            'status' => $run->status,
            'ai_status' => $run->ai_status,
            'delivery_status' => $run->delivery_status,
            'recipient_count' => $run->recipient_count,
            'duration_ms' => $run->duration_ms,
            'error_summary' => $run->error_summary,
            'has_payload' => $run->payload_json !== null,
        ];
    }
}
