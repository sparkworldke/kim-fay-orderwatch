<?php

namespace Database\Seeders;

use App\Models\StaffImportGap;
use App\Models\User;
use App\Models\UserAcumaticaRepMapping;
use App\Models\UserCustomerAssignment;
use App\Models\UserRepCodeHistory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class StaffPortfolioImport2026JulySeeder extends Seeder
{
    private const BATCH_ID = 'staff-portfolio-import-2026-07';

    public function run(): void
    {
        if (! Schema::hasColumns('users', ['designation', 'division'])) {
            throw new \RuntimeException('Run the staff identity migration before this seeder.');
        }
        $path = database_path('seeders/data/2026-07-staff-and-portfolio-import.json');
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (($data['review_status'] ?? null) !== 'approved') {
            throw new \RuntimeException('Frozen staff portfolio data has not been approved.');
        }
        $stats = ['users_updated' => 0, 'rep_code_backfills' => 0, 'conflicts' => 0, 'assignments_created' => 0, 'assignments_updated' => 0, 'assignments_skipped' => 0];

        try {
            DB::transaction(function () use ($data, &$stats): void {
                $this->assertUniqueOwners($data['assignments'] ?? []);
                foreach ($data['users'] ?? [] as $row) {
                    $user = User::query()->findOrFail((int) $row['user_id']);
                    $user->forceFill(array_intersect_key($row, array_flip(['employee_number', 'designation', 'division', 'department_id'])))->save();
                    $stats['users_updated']++;
                    $backfill = strtoupper(trim((string) ($row['rep_code_backfill'] ?? '')));
                    if ($backfill !== '' && trim((string) $user->rep_code) === '') {
                        $user->forceFill(['rep_code' => $backfill])->save();
                        UserRepCodeHistory::query()->firstOrCreate(
                            ['user_id' => $user->id, 'rep_code' => $backfill, 'change_reason' => 'staff_import_backfill'],
                            ['changed_by_name' => 'July 2026 staff portfolio seeder', 'changed_at' => now()],
                        );
                        $stats['rep_code_backfills']++;
                    }
                    foreach ($row['alternate_rep_codes'] ?? [] as $code) {
                        $code = strtoupper(trim((string) $code));
                        UserAcumaticaRepMapping::query()->updateOrCreate(
                            ['user_id' => $user->id, 'acumatica_rep_code' => $code],
                            ['is_primary' => false],
                        );
                        StaffImportGap::query()->updateOrCreate(
                            ['email' => $user->email, 'employee_number' => $code, 'gap_reason' => 'rep_code_employee_number_mismatch'],
                            ['display_name' => $user->name, 'match_score' => 1, 'resolution_status' => 'open', 'source_payload' => ['source_batch_id' => self::BATCH_ID, 'existing_rep_code' => $user->rep_code]],
                        );
                        $stats['conflicts']++;
                    }
                }
                foreach ($data['assignments'] ?? [] as $row) {
                    $key = ['user_id' => (int) $row['user_id'], 'customer_acumatica_id' => strtoupper(trim((string) $row['customer_id'])), 'assignment_type' => UserCustomerAssignment::TYPE_SERVICING];
                    $existing = UserCustomerAssignment::query()->where($key)->first();
                    if ($existing && $existing->source_batch_id === self::BATCH_ID && $existing->source === $row['source']) {
                        $stats['assignments_skipped']++;
                        continue;
                    }
                    UserCustomerAssignment::query()->where('customer_acumatica_id', $key['customer_acumatica_id'])
                        ->whereIn('assignment_type', [UserCustomerAssignment::TYPE_SERVICING, UserCustomerAssignment::TYPE_LEGACY_PRIMARY])
                        ->where('user_id', '!=', $key['user_id'])->delete();
                    $assignment = UserCustomerAssignment::query()->updateOrCreate($key, [
                        'source' => $row['source'], 'source_batch_id' => self::BATCH_ID,
                        'notes' => 'Reviewed July 2026 staff/customer portfolio import',
                    ]);
                    $assignment->wasRecentlyCreated ? $stats['assignments_created']++ : $stats['assignments_updated']++;
                }
            });
        } catch (\Throwable $e) {
            Log::error('staff_portfolio_import.failed', ['batch_id' => self::BATCH_ID, 'stats' => $stats, 'error' => $e->getMessage()]);
            throw $e;
        }
        Log::info('staff_portfolio_import.completed', ['batch_id' => self::BATCH_ID, 'stats' => $stats]);
        $this->command?->info(json_encode(['batch_id' => self::BATCH_ID, 'stats' => $stats], JSON_PRETTY_PRINT));
    }

    private function assertUniqueOwners(array $rows): void
    {
        $owners = [];
        foreach ($rows as $row) {
            $customer = strtoupper(trim((string) ($row['customer_id'] ?? '')));
            $userId = (int) ($row['user_id'] ?? 0);
            if ($customer === '' || $userId < 1) {
                throw new \RuntimeException('Frozen assignment rows require customer_id and user_id.');
            }
            if (isset($owners[$customer]) && $owners[$customer] !== $userId) {
                throw new \RuntimeException("Customer {$customer} has multiple servicing owners in frozen data.");
            }
            $owners[$customer] = $userId;
        }
    }
}
