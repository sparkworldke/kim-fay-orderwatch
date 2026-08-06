<?php

namespace Tests\Feature;

use App\Models\AcumaticaInventoryItem;
use App\Models\Brand;
use App\Models\Department;
use App\Models\User;
use App\Models\UserBrandAssignment;
use App\Services\Team\BrandAssignmentScope;
use App\Services\Team\OrgTreeSeedService;
use App\Services\Team\SharedMailboxPolicy;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrgScopeLeakTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DepartmentSeeder::class);
    }

    public function test_brand_ops_only_sees_assigned_trading_brands_in_inventory(): void
    {
        $dept = Department::query()->where('slug', 'partner_brands')->firstOrFail();
        $user = User::factory()->create([
            'department_id' => $dept->id,
            'org_level' => 'brandsops',
            'product_type_scope' => 'trading',
            'data_scope_mode' => 'scoped',
            'is_active' => true,
        ]);

        UserBrandAssignment::create(['user_id' => $user->id, 'brand' => 'Unilever']);

        AcumaticaInventoryItem::query()->create([
            'inventory_id' => 'SKU-U',
            'description' => 'Unilever SKU',
            'brand' => 'Unilever',
            'product_type' => 'trading',
            'qty_on_hand' => 10,
        ]);
        AcumaticaInventoryItem::query()->create([
            'inventory_id' => 'SKU-N',
            'description' => 'Nestle SKU',
            'brand' => 'Nestlé',
            'product_type' => 'trading',
            'qty_on_hand' => 10,
        ]);
        AcumaticaInventoryItem::query()->create([
            'inventory_id' => 'SKU-M',
            'description' => 'Kimfay Mfg',
            'brand' => 'Kimfay',
            'product_type' => 'manufactured',
            'qty_on_hand' => 10,
        ]);

        $scope = app(BrandAssignmentScope::class);
        $visible = AcumaticaInventoryItem::query()
            ->tap(fn ($q) => $scope->applyInventoryScope($q, $user))
            ->pluck('inventory_id')
            ->all();

        $this->assertSame(['SKU-U'], $visible);
    }

    public function test_shared_mailbox_gets_deny_all_policy(): void
    {
        $policy = app(SharedMailboxPolicy::class);
        $user = User::factory()->create([
            'email' => 'orders@kimfay.com',
            'is_active' => true,
            'data_scope_mode' => 'scoped',
        ]);

        $policy->applyToUser($user);

        $user->refresh();
        $this->assertTrue($user->is_shared_mailbox);
        $this->assertSame('deny_all', $user->data_scope_mode);
        $this->assertFalse($user->is_active);
    }

    public function test_unassigned_partner_brand_member_sees_all_active_partner_brands(): void
    {
        $dept = Department::query()->where('slug', 'partner_brands')->firstOrFail();
        $user = User::factory()->create([
            'department_id' => $dept->id,
            'org_level' => 'brandsops',
            'product_type_scope' => 'trading',
            'is_active' => true,
        ]);
        AcumaticaInventoryItem::query()->create([
            'inventory_id' => 'SKU-DOVE', 'description' => 'Dove',
            'brand' => 'Dove', 'product_type' => 'trading',
        ]);

        Brand::query()->create(['name' => 'Dove', 'ownership' => 'partner', 'is_active' => true]);

        $this->assertSame(['SKU-DOVE'], app(BrandAssignmentScope::class)->inventoryIdsForUser($user));
    }

    public function test_partner_brand_hod_sees_all_active_partner_brands_only(): void
    {
        $dept = Department::query()->where('slug', 'partner_brands')->firstOrFail();
        $hod = User::factory()->create([
            'department_id' => $dept->id, 'department_role' => 'hod',
            'org_level' => 'hod', 'product_type_scope' => 'trading', 'is_active' => true,
        ]);
        Brand::query()->create(['name' => 'Dove', 'ownership' => 'partner', 'is_active' => true]);
        Brand::query()->create(['name' => 'Kimfay', 'ownership' => 'manufactured', 'is_active' => true]);
        AcumaticaInventoryItem::query()->create([
            'inventory_id' => 'SKU-DOVE', 'description' => 'Dove',
            'brand' => 'Dove', 'product_type' => 'trading',
        ]);
        AcumaticaInventoryItem::query()->create([
            'inventory_id' => 'SKU-KF', 'description' => 'Kimfay',
            'brand' => 'Kimfay', 'product_type' => 'manufactured',
        ]);

        $this->assertSame(['SKU-DOVE'], app(BrandAssignmentScope::class)->inventoryIdsForUser($hod));
    }

    public function test_partner_brand_setup_creates_groups_assignments_and_hierarchy(): void
    {
        $vignesh = User::factory()->create(['email' => 'cco@kimfay.com', 'is_active' => true]);
        $anne = User::factory()->create(['email' => 'partnerbrands@kimfay.com', 'is_active' => true]);
        $pricillah = User::factory()->create(['email' => 'brandoperations@kimfay.com', 'is_active' => true]);

        $this->artisan('partner-brands:setup')->assertSuccessful();

        $this->assertSame($vignesh->id, $anne->fresh()->reports_to_user_id);
        $this->assertSame($anne->id, $pricillah->fresh()->reports_to_user_id);
        $this->assertEqualsCanonicalizing(
            ['Huggies', 'Kotex', 'Bio Oil', 'Duracell'],
            $pricillah->brandAssignments()->pluck('brand')->all(),
        );
        $this->assertDatabaseHas('trading_groups', ['name' => 'Danone']);
        $this->assertDatabaseHas('brands', ['name' => 'Aptamil', 'ownership' => 'partner']);
    }

    public function test_org_tree_seed_links_cco_to_hods(): void
    {
        $cco = User::factory()->create(['email' => 'cco@kimfay.com']);
        $mtHod = User::factory()->create(['email' => 'moderntrade@kimfay.com']);
        $kpHod = User::factory()->create(['email' => 'susan@kimfay.com']);

        $result = app(OrgTreeSeedService::class)->seed(false);

        $this->assertGreaterThanOrEqual(2, $result['linked']);
        $this->assertSame($cco->id, $mtHod->fresh()->reports_to_user_id);
        $this->assertSame($cco->id, $kpHod->fresh()->reports_to_user_id);
    }
}
