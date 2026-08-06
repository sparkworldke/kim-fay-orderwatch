<?php

namespace App\Console\Commands;

use App\Services\Team\StaffPortfolioImportService;
use Illuminate\Console\Command;

class PreviewStaffPortfolioImport extends Command
{
    protected $signature = 'customers:preview-staff-portfolio
        {--staff= : Matched staff workbook}
        {--outlets= : MT outlets workbook}
        {--customers= : Acumatica customer export}';

    protected $description = 'Build the July 2026 portfolio precedence result as a dry-run assignment batch';

    public function handle(StaffPortfolioImportService $service): int
    {
        $staff = $this->option('staff') ?: base_path('../excels/Staff_Email_Match_July_2026.xlsx');
        $outlets = $this->option('outlets') ?: base_path('../excels/MT Outlets by Rep.xlsx');
        $customers = $this->option('customers') ?: base_path('../excels/Customers 20260713.xlsx');
        foreach ([$staff, $outlets, $customers] as $path) {
            if (! is_file($path)) {
                $this->error("File not found: {$path}");
                return self::FAILURE;
            }
        }

        $batch = $service->preview($staff, $outlets, $customers);
        $this->info("Dry-run batch: {$batch->uuid}");
        $this->table(['Metric', 'Count'], collect($batch->stats_json)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->comment('Review this batch in the existing assignment admin surface before applying it.');
        return self::SUCCESS;
    }
}
