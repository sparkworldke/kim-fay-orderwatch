<?php

namespace App\Console\Commands;

use App\Services\Team\StaffImportService;
use Illuminate\Console\Command;

class ImportTeamStaff extends Command
{
    protected $signature = 'team:import-staff
                            {--path= : Path to staff_email_match.xlsx or .json}
                            {--dry-run : Preview without writing}
                            {--preserve-manual : Skip users with manual org edits}
                            {--min-confidence=high : Minimum match confidence (low|medium|high)}';

    protected $description = 'Import staff org assignments from matched HR/email spreadsheet';

    public function handle(StaffImportService $importService): int
    {
        $path = $this->option('path')
            ?: base_path('../agent-tools/staff_email_match.json');

        if (! is_file($path)) {
            foreach ([
                base_path('../docs/data/staff_email_match.xlsx'),
                base_path('../docs/data/staff_email_match.json'),
                base_path('../agent-tools/staff_email_match.xlsx'),
            ] as $candidate) {
                if (is_file($candidate)) {
                    $path = $candidate;
                    break;
                }
            }
        }

        if (! is_file($path)) {
            $this->error("Import file not found: {$path}");

            return Command::FAILURE;
        }

        $this->info('Importing from: ' . $path);

        $stats = $importService->import(
            $path,
            (bool) $this->option('dry-run'),
            (bool) $this->option('preserve-manual'),
            (string) $this->option('min-confidence'),
        );

        $this->table(
            ['Metric', 'Count'],
            [
                ['Created', $stats['created']],
                ['Updated', $stats['updated']],
                ['Skipped (manual)', $stats['skipped']],
                ['Gaps recorded', $stats['gaps']],
            ],
        );

        foreach ($stats['errors'] as $error) {
            $this->warn($error);
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run — no changes written.');
        }

        return Command::SUCCESS;
    }
}