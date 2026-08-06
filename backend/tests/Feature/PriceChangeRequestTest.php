<?php

namespace Tests\Feature;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaInventoryItem;
use App\Models\Permission;
use App\Models\PriceChangeApprovalStage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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

    public function test_pcr_emails_use_dynamic_mail_recipients_not_stage_list(): void
    {
        Mail::fake();
        $this->seedPcr();

        $service = app(\App\Services\Pricing\PriceChangeRequestService::class);
        $service->saveSettings([
            'mail_recipients' => ['commercialtechlead@kimfay.com'],
        ]);
        $service->attachMailRecipients(['pricing.ops@kimfay.com']);

        $consultant = User::factory()->create([
            'role' => 'Sales Consultant',
            'is_active' => true,
            'email' => 'consultant-pcr@example.com',
        ]);
        $consultant->roles()->sync([Role::where('name', 'Sales Consultant')->firstOrFail()->id]);

        // Stage recipient who would normally get PCR-P1 — must not be auto-mailed.
        User::factory()->create([
            'role' => 'Administrator',
            'is_active' => true,
            'email' => 'approver-pcr@example.com',
        ]);

        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'C-PCR-MAIL',
            'name' => 'PCR Mail Customer',
            'customer_class' => 'KP',
            'status' => 'Active',
        ]);
        AcumaticaInventoryItem::query()->create([
            'inventory_id' => 'SKU-PCR-MAIL',
            'description' => 'Mail test product',
            'sales_price' => 100,
            'last_cost' => 60,
            'item_status' => 'Active',
        ]);

        Sanctum::actingAs($consultant);
        $create = $this->postJson('/api/operations/price-change-requests', [
            'customer_acumatica_id' => 'C-PCR-MAIL',
            'inventory_id' => 'SKU-PCR-MAIL',
            'proposed_selling_price' => 120,
            'justification' => 'Volume discount request for multi-site renewal contract.',
        ])->assertCreated();

        $event = \App\Models\PriceChangeEvent::query()
            ->where('price_change_request_id', $create->json('id'))
            ->where('event_type', 'notification_sent')
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $payload = $event->payload_json ?? [];
        $to = $payload['to'] ?? [];
        sort($to);
        $this->assertSame(
            ['commercialtechlead@kimfay.com', 'pricing.ops@kimfay.com'],
            $to,
        );
        $this->assertContains('approver-pcr@example.com', $payload['intended_to'] ?? []);
        $this->assertNotContains('approver-pcr@example.com', $payload['to'] ?? []);
        $this->assertNotContains('consultant-pcr@example.com', $payload['to'] ?? []);
    }

    public function test_pcr_settings_can_attach_and_detach_mail_recipients(): void
    {
        $this->seedPcr();

        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);
        $adminRole = Role::where('name', 'Administrator')->firstOrFail();
        $adminRole->permissions()->syncWithoutDetaching(
            Permission::whereIn('name', ['pricing.pcr.config', 'pricing.pcr.view'])->pluck('id')->all()
        );
        $admin->roles()->sync([$adminRole->id]);

        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/pricing/pcr-settings', [
            'mail_recipients' => ['commercialtechlead@kimfay.com'],
        ])->assertOk()
            ->assertJsonPath('settings.mail_recipients', ['commercialtechlead@kimfay.com']);

        $this->putJson('/api/admin/pricing/pcr-settings', [
            'attach_mail_recipients' => ['ops.lead@kimfay.com', 'finance.pricing@kimfay.com'],
        ])->assertOk();

        $recipients = $this->getJson('/api/admin/pricing/pcr-settings')
            ->assertOk()
            ->json('settings.mail_recipients');
        sort($recipients);
        $this->assertSame(
            ['commercialtechlead@kimfay.com', 'finance.pricing@kimfay.com', 'ops.lead@kimfay.com'],
            $recipients,
        );

        $this->putJson('/api/admin/pricing/pcr-settings', [
            'detach_mail_recipients' => ['ops.lead@kimfay.com'],
        ])->assertOk();

        $recipients = $this->getJson('/api/admin/pricing/pcr-settings')->json('settings.mail_recipients');
        sort($recipients);
        $this->assertSame(
            ['commercialtechlead@kimfay.com', 'finance.pricing@kimfay.com'],
            $recipients,
        );
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

    public function test_resolve_price_uses_raw_payload_cost_when_columns_empty(): void
    {
        $this->seedPcr();

        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);
        $adminRole = Role::where('name', 'Administrator')->firstOrFail();
        $adminRole->permissions()->syncWithoutDetaching(
            Permission::whereIn('name', [
                'pricing.pcr.view',
                'pricing.pcr.create',
                'pricing.pcr.view_margin',
            ])->pluck('id')->all()
        );
        $admin->roles()->sync([$adminRole->id]);

        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'C-PCR-3',
            'name' => 'PCR Customer 3',
            'customer_class' => 'KPREST',
            'status' => 'Active',
        ]);
        \App\Models\CustomerData::query()->create([
            'customer_acumatica_id' => 'C-PCR-3',
            'price_class_id' => 'ARTCAFECOF',
            'price_class_name' => 'Artcafe Coffee & Bakery Ltd',
            'source' => 'test',
        ]);
        AcumaticaInventoryItem::query()->create([
            'inventory_id' => 'SKU-PCR-3',
            'description' => 'Item with raw cost only',
            'sales_price' => 0,
            'last_cost' => null,
            'average_cost' => null,
            'item_status' => 'Active',
            'raw_payload' => json_encode([
                'LastCost' => ['value' => 412.5],
                'AverageCost' => ['value' => 400.0],
                'DefaultPrice' => ['value' => 0],
            ]),
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/operations/price-change-requests/resolve-price?customer_acumatica_id=C-PCR-3&inventory_id=SKU-PCR-3&proposed_selling_price=500')
            ->assertOk()
            ->assertJsonPath('base_price_snapshot', 412.5)
            ->assertJsonPath('base_price_source', 'raw_last_cost')
            ->assertJsonPath('customer_price_class', 'ARTCAFECOF — Artcafe Coffee & Bakery Ltd');
    }

    public function test_resolve_price_falls_back_to_so_line_average_cost(): void
    {
        $this->seedPcr();

        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);
        $adminRole = Role::where('name', 'Administrator')->firstOrFail();
        $adminRole->permissions()->syncWithoutDetaching(
            Permission::whereIn('name', ['pricing.pcr.create', 'pricing.pcr.view_margin'])->pluck('id')->all()
        );
        $admin->roles()->sync([$adminRole->id]);

        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'C-PCR-4',
            'name' => 'PCR Customer 4',
            'customer_class' => 'KP',
            'status' => 'Active',
        ]);
        AcumaticaInventoryItem::query()->create([
            'inventory_id' => 'SKU-PCR-4',
            'description' => 'No inventory cost',
            'sales_price' => 0,
            'item_status' => 'Active',
        ]);

        $order = \App\Models\AcumaticaSalesOrder::query()->create([
            'acumatica_order_nbr' => 'SO-PCR-4',
            'order_type' => \App\Models\AcumaticaSalesOrder::TYPE_SALES_ORDER,
            'customer_acumatica_id' => 'C-PCR-4',
            'order_date' => now()->subDay(),
            'status' => 'Open',
            // Model casts raw_payload as array — pass array, not json_encode().
            'raw_payload' => [
                'Details' => [[
                    'InventoryID' => ['value' => 'SKU-PCR-4'],
                    'UnitPrice' => ['value' => 500],
                    'AverageCost' => ['value' => 337.34583],
                    'UnitCost' => ['value' => 337.34583],
                ]],
            ],
        ]);
        \App\Models\AcumaticaSalesOrderLine::query()->create([
            'sales_order_id' => $order->id,
            'line_nbr' => 1,
            'inventory_id' => 'SKU-PCR-4',
            'order_qty' => 1,
            'unit_price' => 500,
        ]);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/operations/price-change-requests/resolve-price?customer_acumatica_id=C-PCR-4&inventory_id=SKU-PCR-4&proposed_selling_price=500');
        $response->assertOk()
            ->assertJsonPath('base_price_source', 'so_average_cost')
            ->assertJsonPath('current_selling_price', 500)
            ->assertJsonPath('current_price_source', 'latest_so_line');
        $this->assertEqualsWithDelta(337.3458, (float) $response->json('base_price_snapshot'), 0.0001);
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
