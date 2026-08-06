<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Department;
use App\Models\TradingGroup;
use App\Models\User;
use App\Models\UserBrandAssignment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as CommandAlias;

class SetupPartnerBrands extends Command
{
    protected $signature = 'partner-brands:setup {--dry-run : Validate and preview without writing}';

    protected $description = 'Create Partner Brand groups, confirmed brand allocations, and the team reporting hierarchy.';

    private const GROUPS = [
        'Unilever International (UI)' => ['Dove', 'Dove Baby', 'Lux', 'Rexona'],
        'Kimberly-Clark (KC)' => ['Huggies', 'Kotex'],
        'Dabur' => ['Ors', 'Dabur', 'Miswak', 'Fem', 'Hobby', 'Vatika', 'Dermoviva'],
        'Union Swiss' => ['Bio Oil'],
        'Danone' => ['Aptamil', 'Cow & Gate'],
        'Duracell' => ['Duracell'],
    ];

    private const CONFIRMED_ALLOCATIONS = [
        'brandoperations.unilever@kimfay.com' => ['Unilever International (UI)'],
        'brandoperations.dabur@kimfay.com' => ['Dabur'],
        'brandoperations@kimfay.com' => ['Kimberly-Clark (KC)', 'Union Swiss', 'Duracell'],
    ];

    private const CONFIRMED_EMPLOYEE_ALLOCATIONS = [
        'P456' => ['Unilever International (UI)'],
    ];

    public function handle(): int
    {
        $anne = $this->activeUser('partnerbrands@kimfay.com');
        $vignesh = $this->activeUser('cco@kimfay.com');
        if (! $anne || ! $vignesh) {
            $this->error('Anne (partnerbrands@kimfay.com) and Vignesh (cco@kimfay.com) must both exist and be active.');

            return CommandAlias::FAILURE;
        }

        $this->info('Groups: '.count(self::GROUPS).' | brands: '.collect(self::GROUPS)->flatten()->unique()->count());
        $this->line("Hierarchy: Partner Brands team -> {$anne->name} -> {$vignesh->name}");
        if ($this->option('dry-run')) {
            $this->comment('Dry run complete; no changes were written.');

            return CommandAlias::SUCCESS;
        }

        DB::transaction(function () use ($anne, $vignesh) {
            $department = Department::query()->firstOrCreate(
                ['slug' => 'partner_brands'],
                ['name' => 'Partner Brands', 'segment' => 'Commercial', 'is_customer_facing' => false, 'sort_order' => 12],
            );

            $brandsByGroup = [];
            foreach (self::GROUPS as $groupName => $brandNames) {
                $group = TradingGroup::query()->updateOrCreate(
                    ['name' => $groupName],
                    ['is_active' => true, 'source' => 'manual'],
                );
                foreach ($brandNames as $brandName) {
                    $brandsByGroup[$groupName][] = Brand::query()->updateOrCreate(
                        ['name' => $brandName],
                        ['ownership' => 'partner', 'partner_brand_group_id' => $group->id, 'is_active' => true, 'source' => 'manual'],
                    );
                }
            }

            $anne->forceFill([
                'department_id' => $department->id,
                'department_role' => 'hod',
                'org_level' => 'hod',
                'reports_to_user_id' => $vignesh->id,
                'product_type_scope' => 'both',
                'data_scope_mode' => 'scoped',
            ])->save();

            User::query()
                ->where('department_id', $department->id)
                ->where('id', '!=', $anne->id)
                ->where('is_active', true)
                ->update(['reports_to_user_id' => $anne->id, 'product_type_scope' => 'both', 'data_scope_mode' => 'scoped']);

            foreach (self::CONFIRMED_ALLOCATIONS as $email => $groupNames) {
                $user = $this->activeUser($email);
                if (! $user) {
                    $this->warn("Skipped missing/inactive allocation user: {$email}");

                    continue;
                }
                $user->forceFill([
                    'department_id' => $department->id,
                    'org_level' => 'brandsops',
                    'reports_to_user_id' => $anne->id,
                    'product_type_scope' => 'both',
                    'data_scope_mode' => 'scoped',
                ])->save();
                UserBrandAssignment::query()->where('user_id', $user->id)->delete();
                foreach ($groupNames as $groupName) {
                    foreach ($brandsByGroup[$groupName] as $brand) {
                        UserBrandAssignment::query()->create([
                            'user_id' => $user->id,
                            'brand' => $brand->name,
                            'brand_id' => $brand->id,
                            'assigned_by' => null,
                        ]);
                    }
                }
            }

            foreach (self::CONFIRMED_EMPLOYEE_ALLOCATIONS as $employeeNumber => $groupNames) {
                $user = User::query()
                    ->where('employee_number', $employeeNumber)
                    ->where('is_active', true)
                    ->first();
                if (! $user) {
                    $this->warn("Skipped missing/inactive allocation user: {$employeeNumber}");

                    continue;
                }
                $user->forceFill([
                    'department_id' => $department->id,
                    'org_level' => 'brandsops',
                    'reports_to_user_id' => $anne->id,
                    'product_type_scope' => 'both',
                    'data_scope_mode' => 'scoped',
                ])->save();
                foreach ($groupNames as $groupName) {
                    foreach ($brandsByGroup[$groupName] as $brand) {
                        UserBrandAssignment::query()->updateOrCreate(
                            ['user_id' => $user->id, 'brand_id' => $brand->id],
                            ['brand' => $brand->name, 'assigned_by' => null],
                        );
                    }
                }
            }
        });

        $this->info('Partner Brand hierarchy and confirmed assignments applied successfully.');

        return CommandAlias::SUCCESS;
    }

    private function activeUser(string $email): ?User
    {
        return User::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->where('is_active', true)->first();
    }
}
