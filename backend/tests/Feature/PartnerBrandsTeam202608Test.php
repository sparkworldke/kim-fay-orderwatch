<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\Team\BrandAssignmentScope;
use App\Services\Team\UserCapabilitiesService;
use App\Support\DataScope;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\PartnerBrandsTeam202608Seeder;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerBrandsTeam202608Test extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_builds_taxonomy_hierarchy_roles_and_confirmed_dynamic_allocations(): void
    {
        $this->seed(DepartmentSeeder::class);
        $this->seed(RolesPermissionsSeeder::class);

        $vignesh = $this->staff('P320', 'Vignesh');
        $anne = $this->staff('P086', 'Anne');
        $brenda = $this->staff('P419', 'Brenda');
        $pricillah = $this->staff('P506', 'Pricillah');
        $unallocated = $this->staff('P370', 'Victoria');
        $adan = $this->staff('P456', 'Adan');

        $this->seed(PartnerBrandsTeam202608Seeder::class);

        $this->assertSame($vignesh->id, $anne->fresh()->reports_to_user_id);
        $this->assertSame($anne->id, $brenda->fresh()->reports_to_user_id);
        $this->assertSame($anne->id, $pricillah->fresh()->reports_to_user_id);
        $this->assertSame($anne->id, $unallocated->fresh()->reports_to_user_id);
        $this->assertSame('original-role', $anne->fresh()->role);
        $this->assertSame('original-role', $pricillah->fresh()->role);
        $this->assertTrue($anne->fresh()->hasRole('Partner Brands HOD'));
        $this->assertTrue($pricillah->fresh()->hasRole('Partner Brands Member'));
        $this->assertEqualsCanonicalizing(['Aptamil', 'Cow & Gate'], $brenda->brandAssignments()->pluck('brand')->all());
        $this->assertEqualsCanonicalizing(
            ['Huggies', 'Kotex', 'Bio Oil', 'Duracell'],
            $pricillah->brandAssignments()->pluck('brand')->all(),
        );
        $this->assertSame([], $unallocated->brandAssignments()->pluck('brand')->all());
        $this->assertSame('both', $unallocated->fresh()->product_type_scope);
        $this->assertEqualsCanonicalizing(
            ['Dove', 'Dove Baby', 'Lux', 'Rexona'],
            $adan->brandAssignments()->pluck('brand')->all(),
        );
        $this->assertDatabaseHas('brands', ['name' => 'Dove Baby', 'ownership' => 'partner']);
        $this->assertDatabaseHas('trading_groups', ['name' => 'Unilever International (UI)']);

        $this->seed(PartnerBrandsTeam202608Seeder::class);
        $this->assertCount(4, $pricillah->fresh()->brandAssignments);
    }

    public function test_capabilities_expose_dynamic_brand_and_group_scope(): void
    {
        $this->seed(DepartmentSeeder::class);
        $this->seed(RolesPermissionsSeeder::class);
        $this->staff('P320', 'Vignesh');
        $this->staff('P086', 'Anne');
        $pricillah = $this->staff('P506', 'Pricillah');
        $this->seed(PartnerBrandsTeam202608Seeder::class);

        $scope = app(UserCapabilitiesService::class)->forUser($pricillah->fresh())['partner_brand_scope'];

        $this->assertTrue($scope['applies']);
        $this->assertNull(DataScope::scopedCustomerAcumaticaIds($pricillah->fresh()));
        $this->assertFalse($scope['is_hod']);
        $this->assertEqualsCanonicalizing(['Huggies', 'Kotex', 'Bio Oil', 'Duracell'], $scope['brands']);
        $this->assertEqualsCanonicalizing(
            ['Kimberly-Clark (KC)', 'Union Swiss', 'Duracell'],
            collect($scope['groups'])->pluck('name')->all(),
        );
        $openMember = $this->staff('P370', 'Unconfigured')->forceFill([
            'department_id' => Department::query()->where('slug', 'partner_brands')->value('id'),
            'org_level' => 'brandsops',
            'product_type_scope' => 'both',
        ]);
        $this->assertCount(17, app(BrandAssignmentScope::class)->allowedBrands($openMember));
    }

    public function test_partner_brand_activity_records_the_server_resolved_brand_scope(): void
    {
        $this->seed(DepartmentSeeder::class);
        $this->seed(RolesPermissionsSeeder::class);
        $this->staff('P320', 'Vignesh');
        $this->staff('P086', 'Anne');
        $pricillah = $this->staff('P506', 'Pricillah');
        $this->seed(PartnerBrandsTeam202608Seeder::class);

        $this->actingAs($pricillah->fresh())
            ->postJson('/api/activity/page-view', [
                'path' => '/app/orders',
                'page_title' => 'Orders',
            ])
            ->assertCreated();

        $activity = UserActivityLog::query()->where('user_id', $pricillah->id)->firstOrFail();
        $this->assertFalse($activity->meta['partner_brand_scope']['is_hod']);
        $this->assertEqualsCanonicalizing(
            ['Huggies', 'Kotex', 'Bio Oil', 'Duracell'],
            $activity->meta['partner_brand_scope']['brands'],
        );
    }

    private function staff(string $employeeNumber, string $name): User
    {
        return User::factory()->create([
            'employee_number' => $employeeNumber,
            'name' => $name,
            'role' => 'original-role',
            'designation' => 'Original designation',
            'is_active' => true,
        ]);
    }
}
