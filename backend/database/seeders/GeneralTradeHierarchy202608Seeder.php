<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\User;
use App\Models\UserSectorScope;
use App\Services\Cache\DomainCache;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GeneralTradeHierarchy202608Seeder extends Seeder
{
    private const GT_EMPLOYEES = [
        'P028', 'P033', 'P150', 'P154', 'P158', 'P172', 'P178', 'P276', 'P323',
        'P335', 'P339', 'P342', 'P343', 'P344', 'P345', 'P346', 'P348', 'P373',
        'P395', 'P413', 'P421', 'P427', 'P428', 'P430', 'P455', 'P461', 'P481', 'P487',
    ];

    public function run(): void
    {
        $department = Department::query()->where('slug', 'gt')->firstOrFail();
        $steve = User::query()->where('is_active', true)
            ->whereRaw('LOWER(TRIM(name)) = ?', ['steve ananda'])
            ->first();
        if (! $steve) {
            throw new RuntimeException('Steve Ananda is not present as an active Sight user. Create/link his login first, then rerun this seeder.');
        }

        $cco = User::query()->where('employee_number', 'P320')
            ->orWhereRaw('LOWER(name) LIKE ?', ['%vignesh%ramachandran%'])
            ->first();
        if (! $cco) {
            throw new RuntimeException('CCO Vignesh Ramachandran (P320) was not found.');
        }

        DB::transaction(function () use ($department, $steve, $cco): void {
            $steve->forceFill([
                'employee_number' => 'XGT001',
                'department_id' => $department->id,
                'department_role' => 'hod',
                'org_level' => 'hod',
                'division' => 'General Trade',
                'designation' => $steve->designation ?: 'General Trade Head',
                'reports_to_user_id' => $cco->id,
                'data_scope_mode' => 'scoped',
            ])->save();
            $this->attachDepartment($steve, $department, 'hod');
            UserSectorScope::query()->firstOrCreate(['user_id' => $steve->id, 'sector' => 'GT']);

            $members = User::query()->whereIn('employee_number', self::GT_EMPLOYEES)->get();
            foreach ($members as $member) {
                $member->forceFill([
                    'department_id' => $department->id,
                    'department_role' => str_contains(strtolower((string) $member->designation), 'manager') ? 'manager' : 'member',
                    'org_level' => 'sales',
                    'division' => 'General Trade',
                    'reports_to_user_id' => $steve->id,
                    'data_scope_mode' => 'scoped',
                    'is_consultant' => true,
                ])->save();
                $this->attachDepartment($member, $department, (string) $member->department_role);
                UserSectorScope::query()->firstOrCreate(['user_id' => $member->id, 'sector' => 'GT']);
            }

            $missing = array_values(array_diff(self::GT_EMPLOYEES, $members->pluck('employee_number')->map(fn ($v) => strtoupper((string) $v))->all()));
            if ($missing !== []) {
                $this->command?->warn('GT staff not yet represented by active users: '.implode(', ', $missing));
            }
            $this->command?->info(sprintf('GT hierarchy applied: Vignesh -> Steve -> %d active GT users.', $members->count()));
        });

        app(DomainCache::class)->bump(DomainCache::CAPABILITIES, DomainCache::SALES_PORTFOLIO, DomainCache::REFERENCES);
    }

    private function attachDepartment(User $user, Department $department, string $role): void
    {
        DB::table('department_user')->where('user_id', $user->id)->update(['is_primary' => false, 'updated_at' => now()]);
        DB::table('department_user')->updateOrInsert(
            ['user_id' => $user->id, 'department_id' => $department->id],
            ['membership_role' => $role, 'is_primary' => true, 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
