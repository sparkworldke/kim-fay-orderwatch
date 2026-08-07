<?php

namespace Tests\Feature;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaInventoryItem;
use App\Models\FolApprovalStage;
use App\Models\FolRequest;
use App\Models\FolRequestLine;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\AcumaticaClient;
use App\Services\Fol\FolAcumaticaSalesOrderService;
use App\Services\Fol\FolRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class FolCcoSalesOrderCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_final_cco_approval_creates_acumatica_so_and_links_it(): void
    {
        Mail::fake();
        config(['fol.create_so_on_final_approval' => true]);

        $this->seedFolPermissionsAndStages();

        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
            'email' => 'admin-fol@test.local',
        ]);

        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'CUST-FOL-1',
            'name' => 'KP Test Hotel',
            'customer_class' => 'KPHOTEL',
            'status' => 'Active',
        ]);

        AcumaticaInventoryItem::query()->create([
            'inventory_id' => 'FOLSKU001',
            'description' => 'Dispenser unit',
            'is_fol_eligible' => true,
            'item_status' => 'Active',
            'default_warehouse_id' => 'MAIN',
        ]);

        $fol = FolRequest::query()->create([
            'public_ref' => 'FOL-2026-009001',
            'customer_acumatica_id' => 'CUST-FOL-1',
            'customer_name' => 'KP Test Hotel',
            'sales_consultant_user_id' => $admin->id,
            'sales_consultant_email' => 'sales@test.local',
            'request_origin' => 'sales_consultant_visit',
            'requestor_first_name' => 'Jane',
            'requestor_last_name' => 'Doe',
            'requestor_phone' => '0700000000',
            'requestor_email' => 'jane@test.local',
            'issue_types' => ['new_dispenser'],
            'reason_text' => 'Customer needs a free-on-loan dispenser for trial.',
            'debt_explanation' => 'No outstanding debt.',
            'status' => 'in_approval',
            'current_stage_key' => 'cco',
            'submitted_at' => now()->subDay(),
        ]);

        FolRequestLine::query()->create([
            'fol_request_id' => $fol->id,
            'line_no' => 1,
            'inventory_id' => 'FOLSKU001',
            'product_description' => 'Dispenser unit',
            'qty_requested' => 2,
            'qty_previously_issued' => 0,
        ]);

        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('createSalesOrder')
            ->once()
            ->withArgs(function (string $customerId, array $lines, ?string $customerOrder, ?string $description, string $orderType, bool $zeroPrice) {
                return $customerId === 'CUST-FOL-1'
                    && $customerOrder === null
                    && $description === null
                    && $orderType === 'SO'
                    && $zeroPrice === false
                    && count($lines) === 1
                    && $lines[0]['inventory_id'] === 'FOLSKU001'
                    && (float) $lines[0]['qty'] === 2.0
                    && array_keys($lines[0]) === ['inventory_id', 'qty'];
            })
            ->andReturn([
                'order_nbr' => 'SO999001',
                'order_id' => 'guid-1',
                'raw' => [
                    'OrderNbr' => ['value' => 'SO999001'],
                    'Status' => ['value' => 'Open'],
                    'OrderTotal' => ['value' => 0],
                ],
            ]);
        $client->shouldReceive('fetchSalesOrdersByNumbers')
            ->once()
            ->with(['SO999001'])
            ->andReturn([[
                'OrderNbr' => ['value' => 'SO999001'],
                'Status' => ['value' => 'Open'],
                'OrderTotal' => ['value' => 0],
                'CustomerOrder' => ['value' => 'FOL-2026-009001'],
                'Date' => ['value' => '2026-07-16'],
            ]]);

        $this->app->instance(AcumaticaClient::class, $client);
        $this->app->instance(
            FolAcumaticaSalesOrderService::class,
            new FolAcumaticaSalesOrderService($client),
        );

        /** @var FolRequestService $service */
        $service = $this->app->make(FolRequestService::class);
        $updated = $service->decide($admin, $fol, 'approved', 'CCO final approval for FOL SO create.');

        $this->assertSame('so_linked', $updated->status);
        $this->assertContains('SO999001', $updated->linked_so_order_nbrs ?? []);
        $this->assertSame('Open', $updated->linked_so_status_summary);

        $presented = $service->present($updated);
        $this->assertSame('SO999001', $presented['so_number']);
        $this->assertSame('SO999001', $presented['acumatica_so_number']);
        $this->assertSame(['SO999001'], $presented['so_numbers']);

        $this->assertDatabaseHas('fol_so_links', [
            'fol_request_id' => $fol->id,
            'acumatica_order_nbr' => 'SO999001',
            'link_type' => 'auto_cco_approve',
        ]);
        $this->assertDatabaseHas('acumatica_sales_orders', [
            'acumatica_order_nbr' => 'SO999001',
            'customer_acumatica_id' => 'CUST-FOL-1',
            'customer_order' => 'FOL-2026-009001',
            'import_source' => 'fol_auto_cco',
        ]);
        $this->assertDatabaseHas('fol_requests', [
            'id' => $fol->id,
            'status' => 'so_linked',
        ]);
    }

    public function test_failed_so_create_writes_log_row(): void
    {
        Mail::fake();
        config(['fol.create_so_on_final_approval' => true]);
        $this->seedFolPermissionsAndStages();

        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        $fol = FolRequest::query()->create([
            'public_ref' => 'FOL-2026-009003',
            'customer_acumatica_id' => 'CUST-FOL-3',
            'customer_name' => 'KP Fail Hotel',
            'sales_consultant_user_id' => $admin->id,
            'sales_consultant_email' => 'sales@test.local',
            'request_origin' => 'sales_consultant_visit',
            'requestor_first_name' => 'A',
            'requestor_last_name' => 'B',
            'requestor_phone' => '0700',
            'requestor_email' => 'a@test.local',
            'issue_types' => ['new_dispenser'],
            'reason_text' => 'Customer needs free-on-loan unit for trial period.',
            'debt_explanation' => 'None',
            'status' => 'in_approval',
            'current_stage_key' => 'cco',
            'submitted_at' => now()->subDay(),
        ]);

        FolRequestLine::query()->create([
            'fol_request_id' => $fol->id,
            'line_no' => 1,
            'inventory_id' => 'FOLSKU003',
            'product_description' => 'Unit',
            'qty_requested' => 1,
        ]);

        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('createSalesOrder')
            ->once()
            ->andThrow(new \RuntimeException('Acumatica PUT SalesOrder failed: 500 ERP down'));
        $this->app->instance(AcumaticaClient::class, $client);
        $this->app->instance(
            FolAcumaticaSalesOrderService::class,
            new FolAcumaticaSalesOrderService($client),
        );

        $service = $this->app->make(FolRequestService::class);
        $updated = $service->decide($admin, $fol, 'approved', 'CCO approve despite ERP failure path.');

        $this->assertSame('ready_for_invoicing', $updated->status);
        $this->assertDatabaseHas('fol_so_create_logs', [
            'fol_request_id' => $fol->id,
            'public_ref' => 'FOL-2026-009003',
            'attempt_source' => 'cco_approve',
            'status' => 'failed',
        ]);
        $this->assertDatabaseMissing('fol_so_links', ['fol_request_id' => $fol->id]);
    }

    public function test_cron_retry_creates_so_for_missing_attachment(): void
    {
        config(['fol.create_so_on_final_approval' => true]);

        $fol = FolRequest::query()->create([
            'public_ref' => 'FOL-2026-009004',
            'customer_acumatica_id' => 'CUST-FOL-4',
            'customer_name' => 'KP Retry Hotel',
            'request_origin' => 'sales_consultant_visit',
            'requestor_first_name' => 'A',
            'requestor_last_name' => 'B',
            'requestor_phone' => '0700',
            'requestor_email' => 'a@test.local',
            'issue_types' => ['new_dispenser'],
            'reason_text' => 'Retry path for missing sales order after CCO approve.',
            'debt_explanation' => 'None',
            'status' => 'ready_for_invoicing',
            'current_stage_key' => 'done',
            'decided_at' => now()->subHour(),
            'submitted_at' => now()->subHours(2),
        ]);

        FolRequestLine::query()->create([
            'fol_request_id' => $fol->id,
            'line_no' => 1,
            'inventory_id' => 'FOLSKU004',
            'product_description' => 'Unit',
            'qty_requested' => 3,
        ]);

        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('createSalesOrder')
            ->once()
            ->andReturn([
                'order_nbr' => 'SO888004',
                'order_id' => 'guid-retry',
                'raw' => ['OrderNbr' => ['value' => 'SO888004'], 'Status' => ['value' => 'Open']],
            ]);
        $client->shouldReceive('fetchSalesOrdersByNumbers')
            ->once()
            ->with(['SO888004'])
            ->andReturn([[
                'OrderNbr' => ['value' => 'SO888004'],
                'Status' => ['value' => 'Open'],
            ]]);

        $service = new FolAcumaticaSalesOrderService($client);
        $summary = $service->retryMissing(limit: 10, attemptSource: 'cron_retry', cronRunLogId: 99);

        $this->assertSame(1, $summary['checked']);
        $this->assertSame(1, $summary['created']);
        $this->assertSame(0, $summary['failed']);
        $this->assertDatabaseHas('fol_requests', [
            'id' => $fol->id,
            'status' => 'so_linked',
        ]);
        $this->assertDatabaseHas('fol_so_create_logs', [
            'fol_request_id' => $fol->id,
            'status' => 'success',
            'attempt_source' => 'cron_retry',
            'acumatica_order_nbr' => 'SO888004',
            'cron_run_log_id' => 99,
        ]);
    }

    public function test_hod_stage_approval_does_not_create_sales_order(): void
    {
        Mail::fake();
        config(['fol.create_so_on_final_approval' => true]);
        $this->seedFolPermissionsAndStages();

        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        $fol = FolRequest::query()->create([
            'public_ref' => 'FOL-2026-009002',
            'customer_acumatica_id' => 'CUST-FOL-2',
            'customer_name' => 'KP Two',
            'sales_consultant_user_id' => $admin->id,
            'sales_consultant_email' => 'sales@test.local',
            'request_origin' => 'sales_consultant_visit',
            'requestor_first_name' => 'A',
            'requestor_last_name' => 'B',
            'requestor_phone' => '0700',
            'requestor_email' => 'a@test.local',
            'issue_types' => ['new_dispenser'],
            'reason_text' => 'Need unit for customer site installation plan.',
            'debt_explanation' => 'None',
            'status' => 'submitted',
            'current_stage_key' => 'hod',
            'submitted_at' => now(),
        ]);

        FolRequestLine::query()->create([
            'fol_request_id' => $fol->id,
            'line_no' => 1,
            'inventory_id' => 'FOLSKU002',
            'product_description' => 'Item',
            'qty_requested' => 1,
        ]);

        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldNotReceive('createSalesOrder');
        $this->app->instance(AcumaticaClient::class, $client);
        $this->app->instance(
            FolAcumaticaSalesOrderService::class,
            new FolAcumaticaSalesOrderService($client),
        );

        $service = $this->app->make(FolRequestService::class);
        $updated = $service->decide($admin, $fol, 'approved', 'HOD stage only.');

        $this->assertSame('in_approval', $updated->status);
        $this->assertSame('cco', $updated->current_stage_key);
        $this->assertDatabaseMissing('fol_so_links', ['fol_request_id' => $fol->id]);
    }

    public function test_invoicing_user_can_manually_create_and_link_sales_order(): void
    {
        config(['fol.create_so_on_final_approval' => false]);
        $this->seedFolPermissionsAndStages();

        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);
        $fol = FolRequest::query()->create([
            'public_ref' => 'FOL-2026-009005',
            'customer_acumatica_id' => 'CUST-FOL-5',
            'customer_name' => 'KP Manual Hotel',
            'sales_consultant_user_id' => $admin->id,
            'request_origin' => 'sales_consultant_visit',
            'requestor_first_name' => 'Manual',
            'requestor_last_name' => 'Tester',
            'requestor_phone' => '0700',
            'requestor_email' => 'manual@test.local',
            'issue_types' => ['new_dispenser'],
            'reason_text' => 'Manually create the approved FOL sales order.',
            'debt_explanation' => 'None',
            'status' => 'ready_for_invoicing',
            'current_stage_key' => 'done',
            'decided_at' => now(),
        ]);
        FolRequestLine::query()->create([
            'fol_request_id' => $fol->id,
            'line_no' => 1,
            'inventory_id' => 'FOLSKU005',
            'product_description' => 'Manual unit',
            'qty_requested' => 1,
        ]);

        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('createSalesOrder')->once()->andReturn([
            'order_nbr' => 'SO999005',
            'order_id' => 'guid-manual',
            'raw' => ['OrderNbr' => ['value' => 'SO999005'], 'Status' => ['value' => 'Open']],
        ]);
        $client->shouldReceive('fetchSalesOrdersByNumbers')->once()->with(['SO999005'])->andReturn([[
            'OrderNbr' => ['value' => 'SO999005'],
            'Status' => ['value' => 'Open'],
        ]]);
        $this->app->instance(AcumaticaClient::class, $client);
        $this->app->instance(FolAcumaticaSalesOrderService::class, new FolAcumaticaSalesOrderService($client));

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/kp/fol/{$fol->id}/sales-order")
            ->assertOk()
            ->assertJsonPath('result.order_nbr', 'SO999005')
            ->assertJsonPath('fol.so_number', 'SO999005');

        $this->assertDatabaseHas('fol_so_links', [
            'fol_request_id' => $fol->id,
            'acumatica_order_nbr' => 'SO999005',
            'link_type' => 'manual_create',
        ]);
        $this->assertDatabaseHas('fol_so_create_logs', [
            'fol_request_id' => $fol->id,
            'attempt_source' => 'manual_ui',
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('fol_request_events', [
            'fol_request_id' => $fol->id,
            'event_type' => 'so_created',
            'actor_user_id' => $admin->id,
        ]);
    }

    private function seedFolPermissionsAndStages(): void
    {
        foreach (['kp.fol.view', 'kp.fol.request', 'kp.fol.approve', 'kp.fol.invoice'] as $name) {
            Permission::query()->firstOrCreate(['name' => $name], ['description' => $name, 'module' => 'kp']);
        }

        $adminRole = Role::query()->firstOrCreate(['name' => 'Administrator'], ['description' => 'Admin']);
        $adminRole->permissions()->syncWithoutDetaching(
            Permission::whereIn('name', ['kp.fol.view', 'kp.fol.request', 'kp.fol.approve', 'kp.fol.invoice'])->pluck('id')->all()
        );

        FolApprovalStage::updateOrCreate(
            ['key' => 'hod'],
            [
                'name' => 'HOD Approval',
                'sort_order' => 1,
                'is_active' => true,
                'assignee_mode' => 'role',
                'role_names' => ['Administrator'],
                'user_ids' => [],
                'require_comment' => true,
                'sla_hours' => 48,
            ]
        );
        FolApprovalStage::updateOrCreate(
            ['key' => 'cco'],
            [
                'name' => 'CCO / COO Final Approval',
                'sort_order' => 2,
                'is_active' => true,
                'assignee_mode' => 'role',
                'role_names' => ['Administrator'],
                'user_ids' => [],
                'require_comment' => true,
                'sla_hours' => 48,
            ]
        );
    }
}
