<?php

namespace Tests\Feature;

use App\Models\AcumaticaCustomer;
use App\Models\CustomerData;
use App\Models\User;
use App\Models\UserCustomerAssignment;
use App\Services\Team\KpReportingHierarchyService;
use App\Support\DataScope;
use Database\Seeders\KpReportingHierarchy202608Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KpReportingHierarchyScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_hierarchy_seeder_only_changes_reporting_lines(): void
    {
        $vignesh = $this->user('P320', 'Executive', 'Original CCO designation');
        $susan = $this->user('P025', 'HOD', 'Original HOD designation');
        $member = $this->user('P317', 'Sales Consultant', 'Original consultant designation');

        $this->seed(KpReportingHierarchy202608Seeder::class);

        $this->assertSame($vignesh->id, $susan->fresh()->reports_to_user_id);
        $this->assertSame($susan->id, $member->fresh()->reports_to_user_id);
        $this->assertSame('HOD', $susan->fresh()->role);
        $this->assertSame('Original HOD designation', $susan->fresh()->designation);
        $this->assertSame('Executive', $vignesh->fresh()->role);
        $this->assertSame('Original CCO designation', $vignesh->fresh()->designation);
    }

    public function test_susan_gets_own_and_team_kp_portfolios_without_losing_non_kp_access(): void
    {
        $vignesh = $this->user('P320', 'Executive');
        $susan = $this->user('P025', 'HOD', reportsTo: $vignesh);
        $member = $this->user('P317', 'Sales Consultant', reportsTo: $susan);
        $outsideRep = $this->user('P999', 'Sales Consultant');

        $this->customer('KP-SUSAN', true, $susan);
        $this->customer('KP-MEMBER', true, $member);
        $this->customer('KP-OUTSIDE', true, $outsideRep);
        $this->customer('NON-KP', false, $outsideRep);

        $ids = DataScope::scopedCustomerAcumaticaIds($susan);

        $this->assertEqualsCanonicalizing(['KP-SUSAN', 'KP-MEMBER', 'NON-KP'], $ids);
        $this->assertNull(DataScope::scopedCustomerAcumaticaIds($vignesh));
        $this->assertEqualsCanonicalizing(['KP-MEMBER'], DataScope::scopedCustomerAcumaticaIds($member));
    }

    public function test_genius_visibility_follows_the_kp_reporting_tree(): void
    {
        $vignesh = $this->user('P320', 'Executive');
        $susan = $this->user('P025', 'HOD', reportsTo: $vignesh);
        $member = $this->user('P317', 'Sales Consultant', reportsTo: $susan);
        $otherMember = $this->user('P460', 'Sales Consultant', reportsTo: $susan);
        $nonKp = $this->user('P999', 'Sales Consultant');
        $books = collect([$vignesh, $susan, $member, $otherMember, $nonKp]);
        $scope = app(KpReportingHierarchyService::class);

        $this->assertEqualsCanonicalizing(
            [$vignesh->id, $susan->id, $member->id, $otherMember->id, $nonKp->id],
            $scope->filterVisibleSalesBooks($vignesh, $books)->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$susan->id, $member->id, $otherMember->id, $nonKp->id],
            $scope->filterVisibleSalesBooks($susan, $books)->pluck('id')->all(),
        );
        $this->assertSame(
            [$member->id],
            $scope->filterVisibleSalesBooks($member, $books)->pluck('id')->all(),
        );
    }

    private function user(string $employeeNumber, string $role, string $designation = 'Unchanged', ?User $reportsTo = null): User
    {
        return User::factory()->create([
            'employee_number' => $employeeNumber,
            'role' => $role,
            'designation' => $designation,
            'reports_to_user_id' => $reportsTo?->id,
            'is_active' => true,
        ]);
    }

    private function customer(string $id, bool $isKp, User $owner): void
    {
        AcumaticaCustomer::factory()->create([
            'acumatica_id' => $id,
            'customer_class' => $isKp ? 'KPBASE' : 'CSSTOCKPTS',
        ]);
        CustomerData::query()->create([
            'customer_acumatica_id' => $id,
            'customer_group' => $isKp ? 'Kim-Fay Professional' : 'Consumer sales',
        ]);
        UserCustomerAssignment::query()->create([
            'user_id' => $owner->id,
            'customer_acumatica_id' => $id,
            'assignment_type' => UserCustomerAssignment::TYPE_SERVICING,
            'source' => 'test',
        ]);
    }
}
