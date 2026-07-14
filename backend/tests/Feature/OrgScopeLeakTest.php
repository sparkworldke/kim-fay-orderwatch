<?php

namespace Tests\Feature;

use App\Models\AcumaticaInventoryItem;
use App\Models\Department;
use App\Models\User;
use App\Models\UserBrandAssignment;
use App\Services\Team\BrandAssignmentScope;
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
        $policy = app(\App\Services\Team\SharedMailboxPolicy::class);
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

    public function test_org_tree_seed_links_cco_to_hods(): void
    {
        $cco = User::factory()->create(['email' => 'cco@kimfay.com']);
        $mtHod = User::factory()->create(['email' => 'moderntrade@kimfay.com']);
        $kpHod = User::factory()->create(['email' => 'susan@kimfay.com']);

        $result = app(\App\Services\Team\OrgTreeSeedService::class)->seed(false);

        $this->assertGreaterThanOrEqual(2, $result['linked']);
        $this->assertSame($cco->id, $mtHod->fresh()->reports_to_user_id);
        $this->assertSame($cco->id, $kpHod->fresh()->reports_to_user_id);
    }
}