<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Department;
use App\Models\Role;
use App\Models\TradingGroup;
use App\Models\User;
use App\Models\UserBrandAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PartnerBrandsTeam202608Seeder extends Seeder
{
    private const VIGNESH = 'P320';

    private const HOD = 'P086';

    /** @var list<string> */
    private const TEAM = [
        'P370', 'P380', 'P397', 'P400', 'P419', 'P436', 'P438', 'P456',
        'P473', 'P493', 'P500', 'P506', 'C939', 'C1044', 'C1047',
    ];

    /** @var array<string, list<string>> */
    private const GROUPS = [
        'Unilever International (UI)' => ['Dove', 'Dove Baby', 'Lux', 'Rexona'],
        'Kimberly-Clark (KC)' => ['Huggies', 'Kotex'],
        'Dabur' => ['Ors', 'Dabur', 'Miswak', 'Fem', 'Hobby', 'Vatika', 'Dermoviva'],
        'Union Swiss' => ['Bio Oil'],
        'Duracell' => ['Duracell'],
        'Danone' => ['Aptamil', 'Cow & Gate'],
    ];

    /** Confirmed from department/division/designation data; generic titles remain unallocated. */
    private const ALLOCATIONS = [
        'P419' => ['Danone'],
        'C1047' => ['Danone'],
        'P438' => ['Dabur'],
        'P473' => ['Dabur'],
        'P506' => ['Kimberly-Clark (KC)', 'Union Swiss', 'Duracell'],
        'P456' => ['Unilever International (UI)'],
    ];

    public function run(): void
    {
        $vignesh = User::query()->where('employee_number', self::VIGNESH)->first();
        $anne = User::query()->where('employee_number', self::HOD)->first();
        if (! $vignesh || ! $anne) {
            throw new RuntimeException('Vignesh (P320) and Anne (P086) must exist before Partner Brands setup.');
        }

        $department = Department::query()->firstOrCreate(
            ['slug' => 'partner_brands'],
            ['name' => 'Partner Brands', 'segment' => 'Commercial', 'is_customer_facing' => false, 'sort_order' => 12],
        );
        $hodRole = Role::query()->where('name', 'Partner Brands HOD')->firstOrFail();
        $memberRole = Role::query()->where('name', 'Partner Brands Member')->firstOrFail();

        DB::transaction(function () use ($vignesh, $anne, $department, $hodRole, $memberRole): void {
            $brandsByGroup = $this->seedTaxonomy();

            $anne->forceFill([
                'department_id' => $department->id,
                'department_role' => 'hod',
                'org_level' => 'hod',
                'reports_to_user_id' => $vignesh->id,
                'product_type_scope' => 'both',
                'data_scope_mode' => 'scoped',
            ])->save();
            $this->attachDepartmentAndRole($anne, $department, $hodRole, 'hod');

            $members = User::query()->whereIn('employee_number', self::TEAM)->get();
            foreach ($members as $member) {
                $member->forceFill([
                    'department_id' => $department->id,
                    'department_role' => 'member',
                    'org_level' => 'brandsops',
                    'reports_to_user_id' => $anne->id,
                    'product_type_scope' => 'both',
                    'data_scope_mode' => 'scoped',
                ])->save();
                $this->attachDepartmentAndRole($member, $department, $memberRole, 'member');

                foreach (self::ALLOCATIONS[$member->employee_number] ?? [] as $groupName) {
                    foreach ($brandsByGroup[$groupName] as $brand) {
                        UserBrandAssignment::query()->updateOrCreate(
                            ['user_id' => $member->id, 'brand_id' => $brand->id],
                            ['brand' => $brand->name, 'assigned_by' => null],
                        );
                    }
                }
            }
        });

        $found = User::query()->whereIn('employee_number', self::TEAM)->count();
        $this->command?->info(sprintf(
            'Partner Brands ready: 6 groups, %d brands, Anne -> Vignesh, %d/%d team profiles -> Anne.',
            collect(self::GROUPS)->flatten()->unique()->count(),
            $found,
            count(self::TEAM),
        ));
    }

    /** @return array<string, list<Brand>> */
    private function seedTaxonomy(): array
    {
        $result = [];
        foreach (self::GROUPS as $groupName => $brandNames) {
            $group = TradingGroup::query()->updateOrCreate(
                ['name' => $groupName],
                ['is_active' => true, 'source' => 'manual'],
            );
            foreach ($brandNames as $brandName) {
                $result[$groupName][] = Brand::query()->updateOrCreate(
                    ['name' => $brandName],
                    [
                        'ownership' => 'partner',
                        'partner_brand_group_id' => $group->id,
                        'is_active' => true,
                        'source' => 'manual',
                    ],
                );
            }
        }

        return $result;
    }

    private function attachDepartmentAndRole(User $user, Department $department, Role $role, string $membershipRole): void
    {
        DB::table('department_user')->updateOrInsert(
            ['department_id' => $department->id, 'user_id' => $user->id],
            [
                'membership_role' => $membershipRole,
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        $user->roles()->syncWithoutDetaching([$role->id => ['assigned_by' => null]]);
    }
}
