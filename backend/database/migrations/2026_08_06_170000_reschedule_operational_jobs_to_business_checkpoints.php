<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<int> */
    private array $baseMinutes = [480, 630, 780, 900, 980];

    public function up(): void
    {
        $jobs = [
            'email-sync-3h' => 0,
            'sales-order-sync-3h' => 5,
            'order-matching-3h' => 10,
            'sales-order-status-sync' => 15,
            'fol-so-retry' => 20,
        ];

        foreach ($jobs as $jobKey => $offset) {
            $this->updateJob($jobKey, $this->expressions($offset), [
                'frequency_label' => '5 daily checkpoints (08:00-16:20)',
                'cron_expression' => '0 8 * * *',
            ]);
        }

        $warehouses = array_values((array) config('inventory.warehouses', []));
        foreach ($warehouses as $index => $warehouse) {
            $warehouse = strtoupper(trim((string) $warehouse));
            $jobKey = 'inventory-sync-'.trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($warehouse)) ?? '', '-');
            $slot = $index % count($this->baseMinutes);
            $expression = $this->expression($this->baseMinutes[$slot]);
            $label = sprintf('%02d:%02d', intdiv($this->baseMinutes[$slot], 60), $this->baseMinutes[$slot] % 60);
            $this->updateJob($jobKey, [$expression], [
                'frequency_label' => "Daily at {$label} EAT",
                'cron_expression' => $expression,
            ], [$label]);
        }
    }

    public function down(): void
    {
        // Operational schedules are intentionally not rolled back to high-frequency polling.
    }

    /** @param list<string> $expressions @param array<string, mixed> $columns @param list<string>|null $labels */
    private function updateJob(string $jobKey, array $expressions, array $columns, ?array $labels = null): void
    {
        $row = DB::table('cron_jobs')->where('job_key', $jobKey)->first();
        if (! $row) {
            return;
        }

        $settings = json_decode((string) ($row->settings ?? '{}'), true);
        $settings = is_array($settings) ? $settings : [];
        $settings['cron_expressions'] = $expressions;
        $settings['schedule_times'] = $labels ?? ['08:00', '10:30', '13:00', '15:00', '16:20'];

        DB::table('cron_jobs')->where('job_key', $jobKey)->update([
            ...$columns,
            'settings' => json_encode($settings),
            'updated_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function expressions(int $offset): array
    {
        return array_map(fn (int $base): string => $this->expression($base + $offset), $this->baseMinutes);
    }

    private function expression(int $totalMinutes): string
    {
        return sprintf('%d %d * * *', $totalMinutes % 60, intdiv($totalMinutes, 60));
    }
};
