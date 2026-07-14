<?php

namespace Tests\Feature;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaFillRateSnapshot;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaBackorderLine;
use App\Models\ConsultantAssignmentAudit;
use App\Models\Department;
use App\Models\User;
use App\Models\UserCustomerAssignment;
use App\Models\UserSession;
use App\Services\Team\ConsultantGuard;
use App\Services\Team\DepartmentResolver;
use App\Support\DataScope;
use App\Support\DepartmentScope;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DepartmentSeeder::class);
    }

    public function test_department_resolver_maps_customer_class_prefix_to_department(): void
    {
        $resolver = app(DepartmentResolver::class);
        $kpDept = Department::query()->where('slug', 'kp')->firstOrFail();

        $this->assertSame('kp', $resolver->resolveSlugFromCustomerClass('KP-RETAIL'));
        $this->assertSame(
            $kpDept->id,
            $resolver->resolveDepartmentIdForCustomer('CUST-KP-001', 'KP-RETAIL'),
        );
    }

    public function test_kp_department_user_only_sees_kp_customers(): void
    {
        $kpDept = Department::query()->where('slug', 'kp')->firstOrFail();
        $user = User::factory()->create([
            'role' => 'Sales Operations',
            'department_id' => $kpDept->id,
            'department_role' => 'member',
            'is_active' => true,
        ]);

        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'KP-CUST-1',
            'name' => 'KP Customer',
            'customer_class' => 'KP-RETAIL',
            'status' => 'Active',
        ]);
        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'GT-CUST-1',
            'name' => 'GT Customer',
            'customer_class' => 'GT-CHAIN',
            'status' => 'Active',
        ]);

        $visibleIds = AcumaticaCustomer::query()
            ->tap(fn ($q) => DataScope::applyCustomerScope($q, $user))
            ->pluck('acumatica_id')
            ->all();

        $this->assertSame(['KP-CUST-1'], $visibleIds);
        $this->assertTrue(DepartmentScope::customerAccessible($user, 'KP-CUST-1', 'KP-RETAIL'));
        $this->assertFalse(DepartmentScope::customerAccessible($user, 'GT-CUST-1', 'GT-CHAIN'));
    }

    public function test_production_department_user_gets_hidden_admin_menus_via_capabilities(): void
    {
        $production = Department::query()->where('slug', 'production')->firstOrFail();
        $user = User::factory()->create([
            'role' => 'Sales Operations',
            'department_id' => $production->id,
            'department_role' => 'member',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/capabilities');

        $response->assertOk()
            ->assertJsonPath('department.slug', 'production');

        $hidden = $response->json('hidden_menus');
        $this->assertContains('administration', $hidden);
        $this->assertContains('mailbox', $hidden);
    }

    public function test_cross_role_consultant_assignment_creates_audit_record(): void
    {
        $order = AcumaticaSalesOrder::query()->create([
            'acumatica_order_nbr' => 'SO-CONS-1',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST-1',
            'customer_name' => 'Test Customer',
            'status' => 'Open',
            'order_total' => 1000,
            'sales_consultant_rep_code' => 'P100',
            'sales_consultant_name' => 'Legacy Name',
        ]);

        $consultant = User::factory()->create([
            'role' => 'Customer Service Manager',
            'is_consultant' => true,
            'is_active' => true,
            'rep_code' => 'P200',
        ]);

        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        $updated = app(ConsultantGuard::class)->assignToOrder($order, $consultant, $admin, 'manual');

        $this->assertSame($consultant->id, $updated->consultant_user_id);
        $this->assertSame('P200', $updated->sales_consultant_rep_code);

        $audit = ConsultantAssignmentAudit::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($audit);
        $this->assertTrue($audit->is_non_traditional_role);
        $this->assertSame('Customer Service Manager', $audit->consultant_role);
    }

    public function test_assign_consultant_endpoint_validates_consultant_designation(): void
    {
        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        $order = AcumaticaSalesOrder::query()->create([
            'acumatica_order_nbr' => 'SO-CONS-2',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST-2',
            'customer_name' => 'Another Customer',
            'status' => 'Open',
            'order_total' => 500,
        ]);

        $nonConsultant = User::factory()->create([
            'role' => 'Sales Operations',
            'is_consultant' => false,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/orders/{$order->id}/consultant", [
                'consultant_user_id' => $nonConsultant->id,
            ])
            ->assertStatus(422);
    }

    public function test_profile_sessions_endpoint_returns_user_sessions(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        UserSession::query()->create([
            'user_id' => $user->id,
            'login_at' => now()->subHour(),
            'logout_at' => now()->subMinutes(10),
            'logout_reason' => 'manual',
            'duration_seconds' => 3000,
            'ip_address' => '127.0.0.1',
            'login_mode' => 'otp',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/profile/sessions')
            ->assertOk()
            ->assertJsonPath('data.0.logout_reason', 'manual')
            ->assertJsonPath('data.0.duration_seconds', 3000);
    }

    public function test_brand_filter_options_endpoint_returns_hierarchy(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/operations/brand-filter-options')
            ->assertOk()
            ->assertJsonStructure(['hierarchy' => [['key', 'label', 'brands']]]);
    }

    public function test_kp_user_customer_feed_only_lists_kp_portfolio(): void
    {
        $kpDept = Department::query()->where('slug', 'kp')->firstOrFail();
        $user = User::factory()->create([
            'role' => 'Sales Operations',
            'department_id' => $kpDept->id,
            'department_role' => 'member',
            'is_active' => true,
        ]);

        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'KP-FEED-1',
            'name' => 'KP Feed Customer',
            'customer_class' => 'KP-RETAIL',
            'status' => 'Active',
            'is_main_account' => true,
        ]);
        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'GT-FEED-1',
            'name' => 'GT Feed Customer',
            'customer_class' => 'GT-CHAIN',
            'status' => 'Active',
            'is_main_account' => true,
        ]);

        AcumaticaSalesOrder::query()->create([
            'acumatica_order_nbr' => 'SO-KP-FEED',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'KP-FEED-1',
            'customer_name' => 'KP Feed Customer',
            'status' => 'Open',
            'order_date' => now()->startOfMonth(),
            'order_total' => 1000,
        ]);
        AcumaticaSalesOrder::query()->create([
            'acumatica_order_nbr' => 'SO-GT-FEED',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'GT-FEED-1',
            'customer_name' => 'GT Feed Customer',
            'status' => 'Open',
            'order_date' => now()->startOfMonth(),
            'order_total' => 2000,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/customer-feed?date_from=' . now()->startOfMonth()->toDateString() . '&date_to=' . now()->toDateString());

        $response->assertOk();
        $groupIds = collect($response->json('groups'))->flatMap(fn ($g) => $g['acumatica_ids'])->all();
        $this->assertContains('KP-FEED-1', $groupIds);
        $this->assertNotContains('GT-FEED-1', $groupIds);
    }

    public function test_kp_user_business_optimization_scopes_revenue_metrics(): void
    {
        $kpDept = Department::query()->where('slug', 'kp')->firstOrFail();
        $user = User::factory()->create([
            'role' => 'Sales Operations',
            'department_id' => $kpDept->id,
            'department_role' => 'member',
            'is_active' => true,
        ]);

        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'KP-BO-1',
            'name' => 'KP BO Customer',
            'customer_class' => 'KP-RETAIL',
            'status' => 'Active',
            'is_main_account' => true,
        ]);
        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'GT-BO-1',
            'name' => 'GT BO Customer',
            'customer_class' => 'GT-CHAIN',
            'status' => 'Active',
            'is_main_account' => true,
        ]);

        $kpOrder = AcumaticaSalesOrder::query()->create([
            'acumatica_order_nbr' => 'SO-KP-BO',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'KP-BO-1',
            'customer_name' => 'KP BO Customer',
            'status' => 'Open',
            'order_date' => now()->startOfMonth(),
            'order_total' => 5000,
        ]);
        AcumaticaSalesOrder::query()->create([
            'acumatica_order_nbr' => 'SO-GT-BO',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'GT-BO-1',
            'customer_name' => 'GT BO Customer',
            'status' => 'Open',
            'order_date' => now()->startOfMonth(),
            'order_total' => 9000,
        ]);

        AcumaticaBackorderLine::query()->create([
            'order_nbr' => 'SO-KP-BO',
            'customer_acumatica_id' => 'KP-BO-1',
            'inventory_id' => 'SKU-KP',
            'open_qty' => 10,
            'revenue_at_risk' => 1500,
        ]);
        AcumaticaBackorderLine::query()->create([
            'order_nbr' => 'SO-GT-BO',
            'customer_acumatica_id' => 'GT-BO-1',
            'inventory_id' => 'SKU-GT',
            'open_qty' => 20,
            'revenue_at_risk' => 4500,
        ]);

        AcumaticaFillRateSnapshot::query()->create([
            'sales_order_id' => $kpOrder->id,
            'order_nbr' => 'SO-KP-BO',
            'customer_acumatica_id' => 'KP-BO-1',
            'fill_rate_status' => 'critical',
            'fill_rate_pct' => 50,
            'total_ordered_qty' => 100,
            'total_shipped_qty' => 50,
            'computed_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/operations/business-optimization?date_from=' . now()->startOfMonth()->toDateString() . '&date_to=' . now()->toDateString());

        $response->assertOk();
        $this->assertSame(1500.0, (float) $response->json('revenue_bleeding.backorder_revenue_at_risk'));
    }

    public function test_operations_org_level_sees_all_sectors(): void
    {
        $dispatch = Department::query()->where('slug', 'dispatch')->firstOrFail();
        $user = User::factory()->create([
            'role' => 'Sales Operations',
            'department_id' => $dispatch->id,
            'department_role' => 'member',
            'org_level' => 'operations',
            'data_scope_mode' => 'org_wide',
            'is_active' => true,
        ]);

        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'KP-OPS-1',
            'name' => 'KP Customer',
            'customer_class' => 'KP-RETAIL',
            'status' => 'Active',
        ]);
        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'GT-OPS-1',
            'name' => 'GT Customer',
            'customer_class' => 'GT-CHAIN',
            'status' => 'Active',
        ]);

        $visibleIds = AcumaticaCustomer::query()
            ->tap(fn ($q) => DataScope::applyCustomerScope($q, $user))
            ->pluck('acumatica_id')
            ->all();

        $this->assertContains('KP-OPS-1', $visibleIds);
        $this->assertContains('GT-OPS-1', $visibleIds);
    }

    public function test_hod_sees_reportee_customer_data(): void
    {
        $mtDept = Department::query()->where('slug', 'mt_consumer_sales')->firstOrFail();
        $hod = User::factory()->create([
            'role' => 'Sales Operations',
            'department_id' => $mtDept->id,
            'department_role' => 'hod',
            'org_level' => 'hod',
            'data_scope_mode' => 'scoped',
            'is_active' => true,
        ]);
        $consultant = User::factory()->create([
            'role' => 'Sales Consultant',
            'department_id' => $mtDept->id,
            'department_role' => 'member',
            'org_level' => 'sales',
            'reports_to_user_id' => $hod->id,
            'rep_code' => 'P999',
            'is_consultant' => true,
            'is_active' => true,
        ]);

        \App\Models\UserCustomerAssignment::create([
            'user_id' => $consultant->id,
            'customer_acumatica_id' => 'MT-HOD-RPT-1',
            'assignment_type' => 'primary',
        ]);

        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'MT-HOD-RPT-1',
            'name' => 'Reportee Customer',
            'customer_class' => 'MT-CHAIN',
            'status' => 'Active',
        ]);
        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'MT-OTHER-1',
            'name' => 'Other MT Customer',
            'customer_class' => 'MT-CHAIN',
            'status' => 'Active',
        ]);

        $visibleIds = AcumaticaCustomer::query()
            ->tap(fn ($q) => DataScope::applyCustomerScope($q, $hod))
            ->pluck('acumatica_id')
            ->all();

        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'GT-HOD-BLOCK-1',
            'name' => 'GT Customer',
            'customer_class' => 'GT-CHAIN',
            'status' => 'Active',
        ]);

        $this->assertContains('MT-HOD-RPT-1', $visibleIds);
        $this->assertContains('MT-OTHER-1', $visibleIds);
        $this->assertNotContains('GT-HOD-BLOCK-1', $visibleIds);
    }

    public function test_admin_can_create_team_member_with_department_fields(): void
    {
        Mail::fake();

        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);
        $kpDept = Department::query()->where('slug', 'kp')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/users', [
                'name' => 'KP Member',
                'email' => 'kp.member@kimfay.test',
                'role' => 'Sales Operations',
                'employee_number' => 'EMP-KP-01',
                'department_id' => $kpDept->id,
                'department_role' => 'hod',
                'is_consultant' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('employee_number', 'EMP-KP-01')
            ->assertJsonPath('department_id', $kpDept->id)
            ->assertJsonPath('is_consultant', true);

        $this->assertDatabaseHas('users', [
            'email' => 'kp.member@kimfay.test',
            'department_id' => $kpDept->id,
            'department_role' => 'hod',
            'is_consultant' => true,
        ]);
    }

    public function test_admin_can_assign_reports_to_manager_without_false_cycle(): void
    {
        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);
        $manager = User::factory()->create([
            'name' => 'Partner Brands',
            'email' => 'partnerbrands@kimfay.test',
            'role' => 'Sales Operations',
            'org_level' => 'hod',
            'is_active' => true,
        ]);
        $member = User::factory()->create([
            'name' => 'Adan Brand Ops',
            'email' => 'brandoperations.unilever@kimfay.test',
            'role' => 'Sales Operations',
            'org_level' => 'brandsops',
            'reports_to_user_id' => null,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/users/{$member->id}", [
                'reports_to_user_id' => $manager->id,
                'org_level' => 'brandsops',
            ])
            ->assertOk()
            ->assertJsonPath('reports_to_user_id', $manager->id);

        $this->assertSame($manager->id, $member->fresh()->reports_to_user_id);

        // Re-save same manager must not false-positive as a cycle
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/users/{$member->id}", [
                'reports_to_user_id' => $manager->id,
            ])
            ->assertOk()
            ->assertJsonPath('reports_to_user_id', $manager->id);
    }

    public function test_admin_cannot_create_reports_to_cycle(): void
    {
        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);
        $top = User::factory()->create(['is_active' => true]);
        $reportee = User::factory()->create([
            'reports_to_user_id' => $top->id,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/users/{$top->id}", [
                'reports_to_user_id' => $reportee->id,
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Reports-to assignment would create a cycle. Pick a manager who is not under this user in the org tree.']);

        $this->assertNull($top->fresh()->reports_to_user_id);
    }

    public function test_reports_to_options_are_fully_dynamic(): void
    {
        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);
        $hod = User::factory()->create([
            'email' => 'partnerbrands@kimfay.test',
            'role' => 'Sales Operations',
            'org_level' => 'hod',
            'is_active' => true,
        ]);
        $brandsOps = User::factory()->create([
            'email' => 'brandoperations.unilever@kimfay.test',
            'role' => 'Sales Operations',
            'org_level' => 'brandsops',
            'is_active' => true,
        ]);
        $consultant = User::factory()->create([
            'role' => 'Sales Consultant',
            'org_level' => 'sales',
            'is_active' => true,
        ]);

        // Create form: any active user available
        $createOptions = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users/reports-to-options')
            ->assertOk()
            ->json('managers');

        $createIds = collect($createOptions)->pluck('id')->all();
        $this->assertContains($hod->id, $createIds);
        $this->assertContains($brandsOps->id, $createIds);
        $this->assertContains($consultant->id, $createIds);

        // Edit brandsOps: HOD and consultant OK; self excluded
        $editOptions = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/users/{$brandsOps->id}/reports-to-options")
            ->assertOk()
            ->json('managers');

        $editIds = collect($editOptions)->pluck('id')->all();
        $this->assertContains($hod->id, $editIds);
        $this->assertContains($consultant->id, $editIds);
        $this->assertNotContains($brandsOps->id, $editIds);
    }

    public function test_staff_import_dry_run_reads_matched_json(): void
    {
        $path = base_path('../agent-tools/staff_email_match.json');
        if (! is_file($path)) {
            $this->markTestSkipped('staff_email_match.json not present');
        }

        $this->artisan('team:import-staff', [
            '--path' => $path,
            '--dry-run' => true,
            '--min-confidence' => 'high',
        ])->assertSuccessful();
    }

    public function test_backfill_customers_from_sales_orders(): void
    {
        $user = User::factory()->create([
            'role' => 'Sales Consultant',
            'rep_code' => 'P777',
            'is_consultant' => true,
            'is_active' => true,
        ]);
        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'CUST-BF-1',
            'name' => 'Backfill Customer',
            'customer_class' => 'KPREST',
            'status' => 'Active',
        ]);

        AcumaticaSalesOrder::query()->create([
            'acumatica_order_nbr' => 'SO-BF-1',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST-BF-1',
            'customer_name' => 'Backfill Customer',
            'sales_consultant_rep_code' => 'P777',
            'order_date' => now(),
            'order_total' => 1000,
            'status' => 'Open',
        ]);

        $this->artisan('team:backfill-customers', ['user' => (string) $user->id])
            ->assertSuccessful();

        $this->assertDatabaseHas('user_customer_assignments', [
            'user_id' => $user->id,
            'customer_acumatica_id' => 'CUST-BF-1',
        ]);
    }

    public function test_admin_can_sync_customer_assignments(): void
    {
        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'role' => 'Sales Consultant',
            'is_consultant' => true,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$user->id}/customer-assignments", [
                'customer_acumatica_ids' => ['CARREFOUR-01', 'CARREFOUR-02'],
            ])
            ->assertOk();

        $this->assertDatabaseHas('user_customer_assignments', [
            'user_id' => $user->id,
            'customer_acumatica_id' => 'CARREFOUR-01',
        ]);
    }

    public function test_customer_assignment_upload_accepts_excel_defined_rep_codes_and_reports_row_errors(): void
    {
        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);
        $p505 = User::factory()->create([
            'role' => 'Sales Consultant',
            'rep_code' => 'P505',
            'is_consultant' => true,
            'is_active' => true,
        ]);
        $yvon = User::factory()->create([
            'role' => 'Sales Consultant',
            'rep_code' => 'YVON',
            'is_consultant' => true,
            'is_active' => true,
        ]);
        $inactive = User::factory()->create([
            'role' => 'Sales Consultant',
            'rep_code' => 'INACTIVE',
            'is_consultant' => true,
            'is_active' => false,
        ]);

        foreach (['CUST-UP-1', 'CUST-UP-2', 'CUST-UP-3', 'CUST-UP-4', 'CUST-UP-5'] as $customerId) {
            AcumaticaCustomer::query()->create([
                'acumatica_id' => $customerId,
                'name' => "{$customerId} Customer",
                'customer_class' => 'KPREST',
                'status' => 'Active',
            ]);
        }

        $file = UploadedFile::fake()->createWithContent('customer_assignments.csv', implode("\n", [
            'rep_code,customer_id,customer_name,route_code,route_name,zone_id,customer_zone,customer_class,customer_group,credit_limit,currency_id',
            'P505,CUST-UP-1,Customer One,R01,Nairobi North,Z001,Westlands,KPREST,Retail,50000,KES',
            'YVON,CUST-UP-2,Customer Two,R02,Nairobi East,Z002,CBD,KPHO,Hospital,120000,KES',
            'UNKNOWN,CUST-UP-3,Customer Three,R01,Nairobi North,Z001,Westlands,KPREST,Retail,30000,KES',
            ',CUST-UP-4,Customer Four,R03,Mombasa,Z012,Mombasa,KPREST,Retail,20000,KES',
            'P505,CUST-MISSING,Missing Customer,R04,Kisumu,Z003,Ngong,KPREST,Retail,10000,KES',
            'INACTIVE,CUST-UP-5,Customer Five,R01,Nairobi North,Z001,Westlands,KPREST,Retail,80000,KES',
        ]));

        $response = $this->actingAs($admin, 'sanctum')
            ->post('/api/admin/customer-assignments/upload', ['file' => $file]);

        $response->assertCreated()
            ->assertJsonPath('stats_json.rows', 6)
            ->assertJsonPath('stats_json.valid', 2)
            ->assertJsonPath('stats_json.errors', 4);

        $rows = collect($response->json('rows'));
        $this->assertSame('valid', $rows->firstWhere('customer_acumatica_id', 'CUST-UP-1')['status']);
        $this->assertSame('valid', $rows->firstWhere('rep_code', 'YVON')['status']);
        $this->assertSame('error', $rows->firstWhere('customer_acumatica_id', 'CUST-MISSING')['status']);
        $this->assertStringContainsString('was not found in the Acumatica customer master', $rows->firstWhere('customer_acumatica_id', 'CUST-MISSING')['message']);
        $this->assertSame('error', $rows->firstWhere('rep_code', 'INACTIVE')['status']);
        $this->assertStringContainsString('matched only inactive users', $rows->firstWhere('rep_code', 'INACTIVE')['message']);
        $this->assertStringContainsString('Rep code UNKNOWN did not resolve to one active user.', $rows->firstWhere('rep_code', 'UNKNOWN')['message']);
        $this->assertStringContainsString('Rep code is required.', $rows->firstWhere('customer_acumatica_id', 'CUST-UP-4')['message']);

        $apply = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/customer-assignments/batches/{$response->json('id')}/apply")
            ->assertOk()
            ->assertJsonPath('status', 'applied')
            ->assertJsonPath('stats_json.created', 2);

        $this->assertDatabaseHas('user_customer_assignments', [
            'user_id' => $p505->id,
            'customer_acumatica_id' => 'CUST-UP-1',
            'source' => 'upload',
            'source_batch_id' => $apply->json('uuid'),
        ]);
        $this->assertDatabaseHas('user_customer_assignments', [
            'user_id' => $yvon->id,
            'customer_acumatica_id' => 'CUST-UP-2',
            'source' => 'upload',
            'source_batch_id' => $apply->json('uuid'),
        ]);
        $this->assertDatabaseMissing('acumatica_customers', ['acumatica_id' => 'CUST-MISSING']);
        $this->assertDatabaseMissing('user_customer_assignments', [
            'user_id' => $p505->id,
            'customer_acumatica_id' => 'CUST-MISSING',
        ]);
        $this->assertDatabaseMissing('user_customer_assignments', [
            'user_id' => $inactive->id,
            'customer_acumatica_id' => 'CUST-UP-5',
        ]);

        // Routes created from the Route Code / Route Name columns, each linked
        // to its shipping zone (Zone ID / Customer Zone).
        $this->assertDatabaseHas('acumatica_routes', [
            'route_code' => 'R01',
            'route_name' => 'Nairobi North',
            'shipping_zone_id' => 'Z001',
            'customer_zone' => 'Westlands',
        ]);
        $this->assertDatabaseHas('acumatica_routes', [
            'route_code' => 'R02',
            'route_name' => 'Nairobi East',
            'shipping_zone_id' => 'Z002',
            'customer_zone' => 'CBD',
        ]);

        // Shipping zones created from the Zone ID column when not already present.
        $this->assertDatabaseHas('acumatica_shipping_zones', ['acumatica_id' => 'Z002']);

        // The extended customer attributes are persisted to customer_data.
        $this->assertDatabaseHas('customer_data', [
            'customer_acumatica_id' => 'CUST-UP-1',
            'route_code' => 'R01',
            'shipping_zone_id' => 'Z001',
            'customer_group' => 'Retail',
            'currency_id' => 'KES',
        ]);
        $this->assertDatabaseMissing('customer_data', ['customer_acumatica_id' => 'CUST-MISSING']);

        // The route code and shipping zone are mirrored onto the customer master row.
        $this->assertDatabaseHas('acumatica_customers', [
            'acumatica_id' => 'CUST-UP-1',
            'route_code' => 'R01',
            'shipping_zone_id' => 'Z001',
        ]);

        $this->assertSame(2, UserCustomerAssignment::query()->count());
    }

    public function test_customer_assignment_upload_is_idempotent_when_same_mapping_is_applied_again(): void
    {
        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);
        $consultant = User::factory()->create([
            'role' => 'Sales Consultant',
            'rep_code' => 'YVON',
            'is_consultant' => true,
            'is_active' => true,
        ]);

        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'CUST-IDEMP-1',
            'name' => 'Idempotent Customer',
            'customer_class' => 'KPREST',
            'status' => 'Active',
        ]);

        for ($i = 0; $i < 2; $i++) {
            $file = UploadedFile::fake()->createWithContent('customer_assignments.csv', implode("\n", [
                'rep_code,customer_id',
                'YVON,CUST-IDEMP-1',
            ]));

            $batch = $this->actingAs($admin, 'sanctum')
                ->post('/api/admin/customer-assignments/upload', ['file' => $file])
                ->assertCreated();

            $this->actingAs($admin, 'sanctum')
                ->postJson("/api/admin/customer-assignments/batches/{$batch->json('id')}/apply")
                ->assertOk();
        }

        $this->assertSame(1, UserCustomerAssignment::query()
            ->where('user_id', $consultant->id)
            ->where('customer_acumatica_id', 'CUST-IDEMP-1')
            ->count());
    }

    public function test_admin_can_sync_brand_assignments(): void
    {
        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);
        $partnerDept = Department::query()->where('slug', 'partner_brands')->firstOrFail();
        $user = User::factory()->create([
            'role' => 'Sales Operations',
            'department_id' => $partnerDept->id,
            'org_level' => 'brandsops',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$user->id}/brand-assignments", [
                'brands' => ['Unilever', 'Nestlé'],
            ])
            ->assertOk()
            ->assertJsonPath('brands', ['Unilever', 'Nestlé']);

        $this->assertDatabaseHas('user_brand_assignments', [
            'user_id' => $user->id,
            'brand' => 'Unilever',
        ]);
    }

    public function test_admin_can_invite_user_to_multiple_departments(): void
    {
        Mail::fake();

        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);
        $mtDept = Department::query()->where('slug', 'mt_consumer_sales')->firstOrFail();
        $gtDept = Department::query()->where('slug', 'gt')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/users', [
                'name' => 'Cross Team Member',
                'email' => 'cross.team@kimfay.test',
                'role' => 'Sales Operations',
                'department_id' => $mtDept->id,
                'department_ids' => [$mtDept->id, $gtDept->id],
                'org_level' => 'operations',
                'sector_scopes' => ['ALL'],
            ])
            ->assertCreated()
            ->assertJsonPath('org_level', 'operations')
            ->assertJsonPath('data_scope_mode', 'org_wide');

        $user = User::query()->where('email', 'cross.team@kimfay.test')->firstOrFail();
        $this->assertSame(
            [$mtDept->id, $gtDept->id],
            $user->departments()->pluck('departments.id')->sort()->values()->all(),
        );
    }
}
