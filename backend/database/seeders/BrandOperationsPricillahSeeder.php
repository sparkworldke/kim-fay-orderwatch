<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Models\UserAcumaticaRepMapping;
use App\Models\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seed Brand Operations Manager: Pricillah Njeri Gichuhi.
 *
 * Staff identity (Active staff / Partner Brands):
 * - Employee / rep code: P506
 * - Email: brandoperations@kimfay.com
 * - Reports to: P086 (Anne Christine Muthoni — partnerbrands@kimfay.com)
 * - Department: Partner Brands
 * - Designation: Brand Operations Manager - KC, Bio & Duracell
 *
 * Run:
 *   php artisan db:seed --class=BrandOperationsPricillahSeeder
 */
class BrandOperationsPricillahSeeder extends Seeder
{
    private const EMAIL = 'brandoperations@kimfay.com';

    private const EMPLOYEE_NUMBER = 'P506';

    private const REP_CODE = 'P506';

    private const MANAGER_EMPLOYEE_NUMBER = 'P086';

    private const MANAGER_EMAIL = 'partnerbrands@kimfay.com';

    public function run(): void
    {
        $department = Department::query()->firstOrCreate(
            ['slug' => 'partner_brands'],
            [
                'name' => 'Partner Brands',
                'segment' => 'Commercial',
                'is_customer_facing' => false,
                'sort_order' => 12,
            ],
        );

        $manager = $this->resolveManager();
        if ($manager === null) {
            $this->command?->warn(
                'Manager P086 (partnerbrands@kimfay.com) was not found. '.
                'Pricillah will be created without reports_to_user_id — re-run after the manager exists.'
            );
        }

        // Brand Operations is org_level brandsops; app role matches other brand-ops seats (Sales Operations).
        $role = Role::query()->firstOrCreate(
            ['name' => 'Sales Operations'],
            ['description' => 'Sales Operations', 'is_system' => true],
        );

        $user = User::query()->firstOrNew(['email' => self::EMAIL]);
        $isNew = ! $user->exists;

        // Prefer matching an existing row by payroll code if email was never seeded.
        if ($isNew) {
            $byCode = User::query()
                ->where(function ($q) {
                    $q->where('employee_number', self::EMPLOYEE_NUMBER)
                        ->orWhere('rep_code', self::REP_CODE);
                })
                ->first();
            if ($byCode !== null) {
                $user = $byCode;
                $isNew = false;
                $this->command?->info(
                    "Found existing user id={$user->id} by P506 — updating email/profile to brandoperations@kimfay.com."
                );
            }
        }

        $user->fill([
            'name' => 'Pricillah Njeri Gichuhi',
            'email' => self::EMAIL,
            'role' => 'Sales Operations',
            'rep_code' => self::REP_CODE,
            'employee_number' => self::EMPLOYEE_NUMBER,
            'designation' => 'Brand Operations Manager - KC, Bio & Duracell',
            'division' => 'Partner Brands',
            'department_id' => $department->id,
            'department_role' => 'member',
            'org_level' => 'brandsops',
            'reports_to_user_id' => $manager?->id,
            'product_type_scope' => 'both',
            'data_scope_mode' => 'scoped',
            'is_consultant' => false,
            'is_active' => true,
            'is_account_manager' => false,
            'is_super_admin' => false,
            'is_shared_mailbox' => false,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        if ($isNew || blank($user->password)) {
            $user->password = Str::random(48);
        }

        $user->save();

        UserRole::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['role_id' => $role->id],
        );

        DB::table('department_user')->updateOrInsert(
            [
                'user_id' => $user->id,
                'department_id' => $department->id,
            ],
            [
                'membership_role' => 'member',
                'is_primary' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        UserAcumaticaRepMapping::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'acumatica_rep_code' => self::REP_CODE,
            ],
            [
                'is_primary' => true,
                'acumatica_consultant_id' => null,
            ],
        );

        $managerLabel = $manager
            ? "{$manager->name} ({$manager->email} / {$manager->employee_number})"
            : 'none';

        $this->command?->info(sprintf(
            'Pricillah Njeri Gichuhi ready: %s | %s / %s | reports_to=%s | user_id=%d | %s',
            self::EMAIL,
            self::EMPLOYEE_NUMBER,
            self::REP_CODE,
            $managerLabel,
            $user->id,
            $isNew ? 'created' : 'updated',
        ));
    }

    private function resolveManager(): ?User
    {
        $byEmployee = User::query()
            ->where(function ($q) {
                $q->whereRaw('UPPER(TRIM(employee_number)) = ?', [self::MANAGER_EMPLOYEE_NUMBER])
                    ->orWhereRaw('UPPER(TRIM(rep_code)) = ?', [self::MANAGER_EMPLOYEE_NUMBER]);
            })
            ->first();

        if ($byEmployee !== null) {
            return $byEmployee;
        }

        return User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower(self::MANAGER_EMAIL)])
            ->first();
    }
}
