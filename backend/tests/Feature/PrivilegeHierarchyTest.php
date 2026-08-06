<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\AcumaticaCustomer;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCustomerAssignment;
use App\Services\Team\AccessTierService;
use App\Services\Team\KpCrmAccessService;
use App\Services\Team\UserCapabilitiesService;
use App\Services\Sales\SalesIntelligenceService;
use App\Support\DataScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivilegeHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_admin_can_call_user_management_api(): void
    {
        $superAdmin = User::factory()->create([
            'role' => 'Administrator', 'is_super_admin' => true, 'is_active' => true,
        ]);
        $vignesh = User::factory()->create([
            'role' => 'Administrator', 'org_level' => 'executive',
            'is_super_admin' => false, 'is_active' => true,
        ]);

        $this->actingAs($vignesh, 'sanctum')->getJson('/api/admin/users')->assertForbidden();
        $this->actingAs($superAdmin, 'sanctum')->getJson('/api/admin/users')->assertOk();
    }

    public function test_executive_has_all_business_channels_and_kp_but_no_user_management(): void
    {
        $executive = User::factory()->create([
            'role' => 'Administrator', 'org_level' => 'executive',
            'is_super_admin' => false, 'is_active' => true,
        ]);

        $access = app(AccessTierService::class);
        $caps = app(UserCapabilitiesService::class)->forUser($executive);

        $this->assertTrue($access->hasUnrestrictedBusinessAccess($executive));
        $this->assertFalse($access->canManageUsers($executive));
        $this->assertTrue($caps['kp_crm_access']['allowed']);
        $this->assertContains('kp', $caps['sales_intelligence_channels']);
        $this->assertContains('mt1', $caps['sales_intelligence_channels']);
        $this->assertContains('mt2', $caps['sales_intelligence_channels']);
        $this->assertContains('administration', $caps['hidden_menus']);
        $this->assertContains('roles', $caps['hidden_menus']);
        $this->assertContains('team', $caps['hidden_menus']);
    }

    public function test_dual_role_sales_consultant_has_unrestricted_business_access(): void
    {
        $salesConsultant = Role::query()->firstOrCreate(['name' => 'Sales Consultant']);

        $commercialTechLead = User::factory()->create([
            'email' => 'commercialtechlead@kimfay.com',
            'role' => 'Administrator',
            'is_consultant' => true,
            'is_super_admin' => true,
            'is_active' => true,
        ]);
        $commercialTechLead->roles()->sync([$salesConsultant->id]);

        $access = app(AccessTierService::class);

        $this->assertFalse($access->isExclusivelySalesConsultant($commercialTechLead));
        $this->assertTrue($access->hasUnrestrictedBusinessAccess($commercialTechLead));
        $this->assertNull(DataScope::scopedCustomerAcumaticaIds($commercialTechLead));
        $this->assertTrue($access->canManageUsers($commercialTechLead));
    }

    public function test_administrator_legacy_deny_all_flag_cannot_override_business_access(): void
    {
        $user = User::factory()->create([
            'role' => 'Administrator',
            'rep_code' => 'P415',
            'is_consultant' => true,
            'is_active' => true,
            'data_scope_mode' => 'deny_all',
        ]);
        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'ANY-OUTLET',
            'name' => 'Any Outlet',
            'status' => 'Active',
            'sales_channel_code' => 'MT1',
        ]);

        $this->assertTrue(app(AccessTierService::class)->hasUnrestrictedBusinessAccess($user));
        $this->assertNull(DataScope::scopedCustomerAcumaticaIds($user));
        $this->assertTrue(DataScope::customerAccessible($user, 'ANY-OUTLET'));
    }

    public function test_hod_my_portfolio_is_deduplicated_union_of_team_member_outlets(): void
    {
        $hod = User::factory()->create(['role' => 'Sales Consultant', 'is_active' => true]);
        $first = User::factory()->create(['role' => 'Sales Consultant', 'reports_to_user_id' => $hod->id, 'is_active' => true]);
        $second = User::factory()->create(['role' => 'Sales Consultant', 'reports_to_user_id' => $hod->id, 'is_active' => true]);

        foreach (['MT-A', 'MT-B', 'MT-SHARED'] as $id) {
            AcumaticaCustomer::query()->create([
                'acumatica_id' => $id,
                'name' => $id,
                'status' => 'Active',
                'sales_channel_code' => 'MT1',
            ]);
        }
        foreach ([[$first->id, 'MT-A'], [$first->id, 'MT-SHARED'], [$second->id, 'MT-B'], [$second->id, 'MT-SHARED']] as [$userId, $customerId]) {
            UserCustomerAssignment::query()->create([
                'user_id' => $userId,
                'customer_acumatica_id' => $customerId,
                'assignment_type' => UserCustomerAssignment::TYPE_SERVICING,
                'source' => 'test',
                'priority' => 20,
                'is_manual_override' => false,
            ]);
        }

        $ids = app(SalesIntelligenceService::class)->hierarchyPortfolioCustomerIds($hod);
        sort($ids);

        $this->assertSame(['MT-A', 'MT-B', 'MT-SHARED'], $ids);
    }

    public function test_sales_intelligence_returns_zero_row_for_outlet_without_orders(): void
    {
        $admin = User::factory()->create([
            'role' => 'Administrator',
            'is_active' => true,
        ]);
        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'MT-NO-ORDERS',
            'name' => 'MT Outlet Without Orders',
            'status' => 'Active',
            'sales_channel_code' => 'MT1',
        ]);

        $result = app(SalesIntelligenceService::class)->metrics($admin, 'MT1', null, null);

        $this->assertSame(1, $result['scope']['customer_count']);
        $this->assertSame(0, $result['customers'][0]['so_count']);
        $this->assertNull($result['customers'][0]['last_order_date']);
        $this->assertSame('MT-NO-ORDERS', $result['not_ordered_customers'][0]['customer_id']);
    }

    public function test_non_super_admin_with_mixed_roles_sees_all_business_data_but_cannot_manage_users(): void
    {
        $salesConsultant = Role::query()->firstOrCreate(['name' => 'Sales Consultant']);
        $user = User::factory()->create([
            'role' => 'Operations',
            'is_consultant' => true,
            'is_super_admin' => false,
            'is_active' => true,
        ]);
        $user->roles()->sync([$salesConsultant->id]);

        $access = app(AccessTierService::class);

        $this->assertFalse($access->isExclusivelySalesConsultant($user));
        $this->assertTrue($access->hasUnrestrictedBusinessAccess($user));
        $this->assertNull(DataScope::scopedCustomerAcumaticaIds($user));
        $this->assertFalse($access->canManageUsers($user));
    }

    public function test_exclusive_sales_consultant_remains_portfolio_scoped(): void
    {
        $salesConsultant = Role::query()->firstOrCreate(['name' => 'Sales Consultant']);
        $user = User::factory()->create([
            'role' => 'Sales Consultant',
            'is_consultant' => true,
            'is_active' => true,
        ]);
        $user->roles()->sync([$salesConsultant->id]);

        $access = app(AccessTierService::class);

        $this->assertTrue($access->isExclusivelySalesConsultant($user));
        $this->assertFalse($access->hasUnrestrictedBusinessAccess($user));
        $this->assertIsArray(DataScope::scopedCustomerAcumaticaIds($user));
    }

    public function test_customer_care_and_production_have_unrestricted_business_and_kp_access(): void
    {
        foreach (['customer_service', 'production'] as $slug) {
            $department = Department::query()->create([
                'slug' => $slug,
                'name' => $slug,
                'is_customer_facing' => false,
                'sort_order' => 1,
            ]);
            $user = User::factory()->create([
                'role' => 'Customer Service Agent',
                'department_id' => $department->id,
                'is_super_admin' => false,
                'is_active' => true,
            ]);

            $this->assertTrue(app(AccessTierService::class)->hasUnrestrictedBusinessAccess($user));
            $this->assertTrue(app(KpCrmAccessService::class)->canAccess($user));
            $this->assertFalse(app(AccessTierService::class)->canManageUsers($user));
            $this->assertNull(DataScope::scopedCustomerAcumaticaIds($user));
            $this->actingAs($user, 'sanctum')->getJson('/api/kp/fol')->assertOk();
            $this->actingAs($user, 'sanctum')->getJson('/api/kp/commissions')->assertOk();
        }
    }

    public function test_mt_user_is_denied_kp_operations(): void
    {
        $department = Department::query()->create([
            'slug' => 'mt_consumer_sales',
            'name' => 'MT',
            'is_customer_facing' => true,
            'sort_order' => 1,
        ]);
        $user = User::factory()->create([
            'role' => 'Sales Consultant',
            'department_id' => $department->id,
            'is_active' => true,
        ]);

        $caps = app(UserCapabilitiesService::class)->forUser($user);
        $this->assertFalse(app(KpCrmAccessService::class)->canAccess($user));
        $this->assertNotContains('kp', $caps['sales_intelligence_channels']);
        $this->actingAs($user, 'sanctum')->getJson('/api/kp/items-not-ordered')->assertForbidden();
    }

    public function test_executive_sales_intelligence_is_not_limited_to_attached_outlets(): void
    {
        $executive = User::factory()->create([
            'role' => 'Administrator', 'org_level' => 'executive',
            'is_super_admin' => false, 'is_active' => true,
        ]);
        foreach (['MT-ATTACHED', 'MT-UNATTACHED'] as $id) {
            AcumaticaCustomer::query()->create([
                'acumatica_id' => $id,
                'name' => $id,
                'status' => 'Active',
                'sales_channel_code' => 'MT1',
            ]);
        }

        $ids = app(SalesIntelligenceService::class)->channelCustomerIds($executive, 'MT1');

        sort($ids);
        $this->assertSame(['MT-ATTACHED', 'MT-UNATTACHED'], $ids);
    }
}
