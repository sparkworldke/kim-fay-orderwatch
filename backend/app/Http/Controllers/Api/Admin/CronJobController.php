<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CronJob;
use App\Services\Cron\HourlyAutoMatchCronService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CronJobController extends Controller
{
    public function index(): JsonResponse
    {
        CronJob::hourlyAutoMatch();
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
        ]);
        if (isset($validated['settings'])) {
            $validated['settings'] = array_merge($cronJob->settings ?? [], $validated['settings'], [
                'deterministic_auto_link' => true, 'ai_auto_link' => false,
            ]);
        }
        if (array_key_exists('is_enabled', $validated)) $validated['status'] = $validated['is_enabled'] ? 'active' : 'paused';
        $cronJob->update($validated);
        return response()->json($this->present($cronJob->fresh('runLogs')));
    }

    public function run(Request $request, CronJob $cronJob, HourlyAutoMatchCronService $service): JsonResponse
    {
        $jobId = $cronJob->id;
        $userId = $request->user()->id;
        defer(fn () => $service->run(CronJob::findOrFail($jobId), 'manual', $userId));
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
        return array_merge($job->attributesToArray(), [
            'command_reference' => 'php artisan schedule:work',
            'scheduler_reference' => '* * * * * php artisan schedule:run',
            'runs' => $job->relationLoaded('runLogs') ? $job->runLogs->values() : [],
        ]);
    }
}
