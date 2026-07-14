<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Team\CustomerAssignmentService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Console\Command\Command as CommandAlias;

class UploadCustomerAssignments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * --actor is the acting admin user id that will be stamped as assigned_by.
     * --dry-run performs only the preview without writing assignments.
     *
     * @var string
     */
    protected $signature = 'customers:upload-assignments
                            {file : Absolute path to the customer assignment spreadsheet (csv/xlsx/xls)}
                            {--actor= : Acting admin user id (assigned_by). Defaults to first super admin.}
                            {--dry-run : Preview only; do not apply.}';

    /** @var string */
    protected $description = 'Upload a customer assignment spreadsheet (rep_code + customer_id), resolve active users, and optionally apply the batch.';

    public function handle(CustomerAssignmentService $service): int
    {
        $filePath = (string) $this->argument('file');

        if (! is_file($filePath)) {
            $this->error("File not found: {$filePath}");

            return CommandAlias::FAILURE;
        }

        if (version_compare(PHP_VERSION, '8.1.0') >= 0) {
            $file = new UploadedFile($filePath, basename($filePath), test: true);
        } else {
            $file = new UploadedFile($filePath, basename($filePath), null, null, true);
        }

        $actorId = $this->resolveActorId();
        if ($actorId === null) {
            $this->error('No active admin user found to attribute the upload. Pass --actor=<id>.');

            return CommandAlias::FAILURE;
        }

        $this->info('Previewing upload (resolving rep codes against active users)...');

        try {
            $batch = $service->previewUpload($file, $actorId);
        } catch (\Throwable $e) {
            $this->error('Upload preview failed: '.$e->getMessage());

            return CommandAlias::FAILURE;
        }

        $stats = $batch->stats_json ?? [];
        $this->newLine();
        $this->info(sprintf(
            'Batch %s | rows=%d valid=%d errors=%d | source=%s',
            $batch->uuid,
            $stats['rows'] ?? 0,
            $stats['valid'] ?? 0,
            $stats['errors'] ?? 0,
            $batch->source,
        ));

        $rows = $batch->rows()->orderBy('row_no')->get();
        $errorRows = $rows->where('status', 'error');

        if ($errorRows->isNotEmpty()) {
            $this->newLine();
            $this->warn(sprintf('%d row(s) with errors (NOT applied):', $errorRows->count()));
            foreach ($errorRows as $row) {
                $this->line(sprintf(
                    '  row %d | rep=%s | customer=%s | %s',
                    $row->row_no,
                    $row->rep_code ?? '(none)',
                    $row->customer_acumatica_id ?? '(none)',
                    $row->message,
                ));
            }
        }

        if ((bool) $this->option('dry-run')) {
            $this->newLine();
            $this->info('Dry run only. No assignments were written.');

            return CommandAlias::SUCCESS;
        }

        $this->newLine();
        $applied = $service->applyBatch($batch, $actorId);
        $appliedStats = $applied->stats_json ?? [];

        $this->info(sprintf(
            'Applied batch %s | created=%d updated=%d applied=%d',
            $applied->uuid,
            $appliedStats['created'] ?? 0,
            $appliedStats['updated'] ?? 0,
            $appliedStats['applied'] ?? 0,
        ));

        return CommandAlias::SUCCESS;
    }

    private function resolveActorId(): ?int
    {
        $explicit = $this->option('actor');
        if ($explicit !== null && $explicit !== '') {
            return (int) $explicit;
        }

        $admin = User::query()
            ->where('is_active', true)
            ->where('is_super_admin', true)
            ->orderBy('id')
            ->first();

        return $admin?->id;
    }
}
