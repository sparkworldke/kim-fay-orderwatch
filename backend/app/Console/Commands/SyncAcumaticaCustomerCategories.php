<?php

namespace App\Console\Commands;

use App\Models\AcumaticaCustomerCategory;
use App\Models\AcumaticaDeadLetter;
use App\Models\AcumaticaSyncLog;
use App\Services\Admin\AcumaticaClient;
use App\Services\Admin\StructuredLogger;
use Illuminate\Console\Command;
use Throwable;

class SyncAcumaticaCustomerCategories extends Command
{
    protected $signature = 'acumatica:sync-categories';

    protected $description = 'Sync Acumatica CustomerClass master data to local acumatica_customer_categories table';

    public function __construct(private readonly AcumaticaClient $client)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $run = AcumaticaSyncLog::create([
            'sync_type'    => 'customer_categories',
            'started_at'   => now(),
            'status'       => 'running',
            'record_count' => 0,
            'success_count'=> 0,
            'failed_count' => 0,
            'trigger_type' => 'scheduled',
        ]);

        StructuredLogger::write('info', 'acumatica', 'category_sync_started', ['sync_run_id' => $run->id]);

        try {
            $categories = $this->client->fetchAllCustomerCategories();
            $total   = count($categories);
            $success = 0;
            $failed  = 0;

            foreach ($categories as $raw) {
                $classId = AcumaticaClient::val($raw['ClassID'] ?? null);

                if (! $classId) {
                    $failed++;
                    continue;
                }

                try {
                    AcumaticaCustomerCategory::updateOrCreate(
                        ['acumatica_id' => $classId],
                        [
                            'description' => AcumaticaClient::val($raw['Description'] ?? null),
                            'sync_run_id' => $run->id,
                            'synced_at'   => now(),
                        ],
                    );
                    $success++;
                } catch (Throwable $e) {
                    $failed++;

                    AcumaticaDeadLetter::create([
                        'sync_run_id'   => $run->id,
                        'resource_type' => 'category',
                        'resource_id'   => $classId,
                        'attempt_count' => 1,
                        'last_error'    => $e->getMessage(),
                        'raw_payload'   => $raw,
                    ]);

                    StructuredLogger::write('error', 'acumatica', 'category_sync_record_failed', [
                        'sync_run_id' => $run->id,
                        'class_id'    => $classId,
                        'error'       => $e->getMessage(),
                    ]);
                }
            }

            $run->update([
                'ended_at'      => now(),
                'status'        => $failed === $total && $total > 0 ? 'failed' : 'completed',
                'record_count'  => $total,
                'success_count' => $success,
                'failed_count'  => $failed,
            ]);

            $this->info("Category sync complete: {$success}/{$total} synced, {$failed} failed.");
            StructuredLogger::write('info', 'acumatica', 'category_sync_completed', [
                'sync_run_id' => $run->id,
                'total'       => $total,
                'success'     => $success,
                'failed'      => $failed,
            ]);
        } catch (Throwable $e) {
            $run->update([
                'ended_at'      => now(),
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            StructuredLogger::write('error', 'acumatica', 'category_sync_failed', [
                'sync_run_id' => $run->id,
                'error'       => $e->getMessage(),
            ]);

            $this->error("Category sync failed: {$e->getMessage()}");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
