<?php

namespace Tests\Feature;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaInventoryItem;
use App\Models\Permission;
use App\Models\PriceChangeApprovalStage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PriceChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_consultant_can_create_and_admin_can_approve_to_erp_queue(): void
    {
        $this->seedPcr();

        $consultant = User::factory()->create([
            'role' => 'Sales Consultant',
            'is_active' => true,
            'is_consultant' => true,
        ]);
        $consultantRole = Role::where('name', 'Sales Consultant')->firstOrFail();
        $consultant->roles()->sync([$consultantRole->id]);

        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'C-PCR-1',
            'name' => 'PCR Test Customer',
            'customer_class' => 'KP-HORECA',
            'status' => 'Active',
            'payment_terms' => '30D',
        ]);

        AcumaticaInventoryItem::query()->create([
            'inventory_id' => 'SKU-PCR-1',
            'description' => 'Test product',
            'sales_price' => 100,
            'last_cost' => 60,
            'item_status' => 'Active',
        ]);

        // Sales Consultant is not a "privileged" CS role — view.only must allow PCR POST.
        Sanctum::actingAs($consultant);

        $create = $this->postJson('/api/operations/price-change-requests', [
            'customer_acumatica_id' => 'C-PCR-1',
            'inventory_id' => 'SKU-PCR-1',
            'proposed_selling_price' => 120,
            'justification' => 'Customer requested volume discount for multi-site contract renewal.',
        ]);

        $create->assertCreated()
            ->assertJsonPath('status', 'submitted')
            ->assertJsonPath('inventory_id', 'SKU-PCR-1')
            ->assertJsonMissingPath('base_price_snapshot'); // consultant must not see margin/base

        $id = $create->json('id');
        $this->assertNotEmpty($id);

        $this->getJson("/api/operations/price-change-requests/{$id}")
            ->assertOk()
            ->assertJsonMissingPath('base_price_snapshot');

        // Admin approves both stages if needed (super-admin bypass)
        Sanctum::actingAs($admin);

        $detail = $this->getJson("/api/operations/price-change-requests/{$id}")
            ->assertOk()
            ->assertJsonPath('can_actor_approve', true);

        // Admin sees margin fields
        $this->assertArrayHasKey('base_price_snapshot', $detail->json());

        // Approve stage 1
        $this->postJson("/api/operations/price-change-requests/{$id}/decisions", [
            'decision' => 'approved',
            'comment' => 'Stage 1 approved for margin review.',
        ])->assertOk();

        // Approve stage 2 if still pending
        $status = $this->getJson("/api/operations/price-change-requests/{$id}")->json('status');
        if (in_array($status, ['submitted', 'in_approval'], true)) {
            $this->postJson("/api/operations/price-change-requests/{$id}/decisions", [
                'decision' => 'approved',
                'comment' => 'Final approval for ERP apply.',
            ])->assertOk()
                ->assertJsonPath('status', 'pending_erp_apply');
        } else {
            $this->assertSame('pending_erp_apply', $status);
        }

        $this->postJson("/api/operations/price-change-requests/{$id}/mark-applied-erp")
            ->assertOk()
            ->assertJsonPath('status', 'applied_erp');

        $this->getJson('/api/operations/price-change-requests/dashboard')
            ->assertOk()
            ->assertJsonPath('applied_erp', 1);
    }

    public function test_resolve_price_returns_current_selling_without_base_for_consultant(): void
    {
        $this->seedPcr();

        $consultant = User::factory()->create(['role' => 'Sales Consultant', 'is_active' => true]);
        $consultant->roles()->sync([Role::where('name', 'Sales Consultant')->firstOrFail()->id]);

        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'C-PCR-2',
            'name' => 'PCR Customer 2',
            'customer_class' => 'KP',
            'status' => 'Active',
        ]);
        AcumaticaInventoryItem::query()->create([
            'inventory_id' => 'SKU-PCR-2',
            'description' => 'Item 2',
            'sales_price' => 55.5,
            'last_cost' => 30,
            'item_status' => 'Active',
        ]);

        Sanctum::actingAs($consultant);
        $this->getJson('/api/operations/price-change-requests/resolve-price?customer_acumatica_id=C-PCR-2&inventory_id=SKU-PCR-2')
            ->assertOk()
            ->assertJsonPath('current_selling_price', 55.5)
            ->assertJsonMissingPath('base_price_snapshot');
    }

    private function seedPcr(): void
    {
        foreach ([
            'pricing.pcr.view',
            'pricing.pcr.create',
            'pricing.pcr.approve',
            'pricing.pcr.approve_escalated',
            'pricing.pcr.view_margin',
            'pricing.pcr.apply_erp',
            'pricing.pcr.config',
        ] as $name) {
            Permission::query()->firstOrCreate(['name' => $name], ['description' => $name, 'module' => 'pricing']);
        }

        $consultant = Role::query()->firstOrCreate(['name' => 'Sales Consultant'], ['description' => 'SC']);
        $consultant->permissions()->sync(
            Permission::whereIn('name', ['pricing.pcr.view', 'pricing.pcr.create'])->pluck('id')->all()
        );

        Role::query()->firstOrCreate(['name' => 'Administrator'], ['description' => 'Admin']);

        PriceChangeApprovalStage::updateOrCreate(
            ['key' => 'hod'],
            [
                'name' => 'HOD',
                'sort_order' => 1,
                'is_active' => true,
                'assignee_mode' => 'role',
                'role_names' => ['Administrator'],
                'user_ids' => [],
                'require_comment_on_reject' => true,
                'sla_hours' => 24,
            ]
        );
        PriceChangeApprovalStage::updateOrCreate(
            ['key' => 'senior'],
            [
                'name' => 'Senior',
                'sort_order' => 2,
                'is_active' => true,
                'assignee_mode' => 'role',
                'role_names' => ['Administrator'],
                'user_ids' => [],
                'require_comment_on_reject' => true,
                'sla_hours' => 24,
            ]
        );
    }
}
