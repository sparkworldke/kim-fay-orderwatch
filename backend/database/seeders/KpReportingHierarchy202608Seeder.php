<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Team\KpReportingHierarchyService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class KpReportingHierarchy202608Seeder extends Seeder
{
    public function run(): void
    {
        $vignesh = User::query()->where('employee_number', KpReportingHierarchyService::VIGNESH_EMPLOYEE_NUMBER)->first();
        $susan = User::query()->where('employee_number', KpReportingHierarchyService::SUSAN_EMPLOYEE_NUMBER)->first();

        if (! $vignesh || ! $susan) {
            throw new RuntimeException('Vignesh (P320) and Susan (P025) must exist before seeding the KP reporting hierarchy.');
        }

        DB::transaction(function () use ($vignesh, $susan): void {
            $susan->forceFill(['reports_to_user_id' => $vignesh->id])->save();

            User::query()
                ->whereIn('employee_number', KpReportingHierarchyService::TEAM_EMPLOYEE_NUMBERS)
                ->update([
                    'reports_to_user_id' => $susan->id,
                    'updated_at' => now(),
                ]);
        });

        $found = User::query()->whereIn('employee_number', KpReportingHierarchyService::TEAM_EMPLOYEE_NUMBERS)->count();
        $missing = count(KpReportingHierarchyService::TEAM_EMPLOYEE_NUMBERS) - $found;
        $this->command?->info("KP reporting hierarchy: Susan reports to Vignesh; {$found} KP team profiles report to Susan.");
        if ($missing > 0) {
            $this->command?->warn("{$missing} expected KP team profiles were not found; no placeholder users were created.");
        }
    }
}
