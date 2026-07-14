<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CronJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class CronJobController extends Controller
{
    public function index(): JsonResponse
    {
        CronJob::ensureDefaults();
        return response()->json(CronJob::with(['runLogs' => fn ($query) => $query->latest('started_at')->limit(1)])
            ->orderBy('name')->get()->map(fn ($job) => $this->present($job)));
    }

    public function show(CronJob $cronJob): JsonResponse
    {
        return response()->json($this->present($cronJob->load(['runLogs' => fn ($query) => $query->latest('started_at')->limit(20)])));
    }

    public function update(Request $request, CronJob $cronJob): JsonResponse
    {
        $validated = $request->validate([
            'is_enabled' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'settings' => ['sometimes', 'array'],
            'settings.email_sync_enabled' => ['sometimes', 'boolean'],
            'settings.acumatica_sync_enabled' => ['sometimes', 'boolean'],
            'settings.matching_enabled' => ['sometimes', 'boolean'],
            'settings.sales_order_lookback_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'settings.status_sync_lookback_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'settings.status_sync_max_orders' => ['sometimes', 'integer', 'min:50', 'max:5000'],
            'settings.fill_rate_lookback_days' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ]);
        if (isset($validated['settings'])) {
            $settings = array_merge($cronJob->settings ?? [], $validated['settings']);
            if ($cronJob->job_key === 'email-sales-order-auto-match') {
                $settings = array_merge($settings, ['deterministic_auto_link' => true, 'ai_auto_link' => false]);
            }
            $validated['settings'] = $settings;
        }
        if (array_key_exists('is_enabled', $validated)) $validated['status'] = $validated['is_enabled'] ? 'active' : 'paused';
        $cronJob->update($validated);
        return response()->json($this->present($cronJob->fresh('runLogs')));
    }

    public function run(Request $request, CronJob $cronJob): JsonResponse
    {
        $jobId = $cronJob->id;
        $userId = $request->user()->id;
        defer(function () use ($jobId, $userId): void {
            $job = CronJob::findOrFail($jobId);
            $command = trim((string) $job->command);
            if (str_starts_with($command, 'php artisan ')) {
                $command = trim(substr($command, strlen('php artisan ')));
            }
            if ($command === '') {
                return;
            }

            // Parse "name --opt=value --flag" into Artisan::call($name, $params)
            $parts = preg_split('/\s+/', $command) ?: [];
            $name = array_shift($parts) ?: '';
            if ($name === '') {
                return;
            }

            $params = [
                '--source' => 'manual',
                '--user-id' => $userId,
            ];
            foreach ($parts as $part) {
                if (! str_starts_with($part, '--')) {
                    continue;
                }
                $opt = substr($part, 2);
                if (str_contains($opt, '=')) {
                    [$key, $value] = explode('=', $opt, 2);
                    $params['--'.$key] = $value;
                } else {
                    $params['--'.$opt] = true;
                }
            }

            // Ensure per-warehouse inventory jobs always bind to their cron row.
            if (! isset($params['--job-key'])) {
                $params['--job-key'] = $job->job_key;
            }

            Artisan::call($name, $params);
        });
        return response()->json(['message' => 'Cron run started. History will update automatically.'], 202);
    }

    public function runs(Request $request, CronJob $cronJob): JsonResponse
    {
        $query = $cronJob->runLogs()->latest('started_at');
        if ($request->filled('status')) {
            $statuses = match ($request->string('status')->toString()) {
                'failures' => ['partial', 'failed'],
                'successes' => ['success'],
                default => ['running', 'success', 'partial', 'failed', 'skipped'],
            };
            $query->whereIn('status', $statuses);
        }
        return response()->json($query->paginate(min(100, max(10, $request->integer('per_page', 50)))));
    }

    private function present(CronJob $job): array
    {
        $nextRunAt = $job->computedNextRunAt();
        if ($nextRunAt && (! $job->next_run_at || $job->next_run_at->getTimestamp() !== $nextRunAt->getTimestamp())) {
            $job->forceFill(['next_run_at' => $nextRunAt])->save();
            $job->refresh();
        }

        return array_merge($job->attributesToArray(), [
            'next_run_at' => $nextRunAt?->format(DATE_ATOM),
            'command_reference' => 'php artisan schedule:work',
            'scheduler_reference' => '* * * * * php artisan schedule:run',
            'runs' => $job->relationLoaded('runLogs') ? $job->runLogs->values() : [],
        ]);
    }
}
