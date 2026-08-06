<?php

namespace Tests\Feature;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaSalesOrder;
use App\Models\CustomerAssignmentRule;
use App\Models\CustomerData;
use App\Models\Role;
use App\Models\User;
use App\Models\UserAcumaticaRepMapping;
use App\Models\UserCustomerAssignment;
use App\Services\Team\CustomerAttributionService;
use App\Services\Team\CustomerAssignmentResolution;
use App\Services\Team\IdentityResolution;
use App\Support\DataScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers PRD §7.1 (identity resolution), §7.2 (customer assignment precedence),
 * §7.3 (mapped-only Sales Consultant gate), and §7.4 (directional hierarchy
 * de-duplication) for the central {@see CustomerAttributionService}.
 */
class CustomerAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Deterministic, cache-free resolution for tests.
        config(['attribution.effective_assignment_cache_ttl' => 0]);
    }

    private function salesConsultantRole(): Role
    {
        return Role::query()->firstOrCreate(
            ['name' => 'Sales Consultant'],
            ['description' => 'Sales Consultant'],
        );
    }

    private function makeConsultant(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'role' => 'Sales Consultant',
            'is_active' => true,
        ], $attributes));

        $user->roles()->sync([$this->salesConsultantRole()->id]);

        return $user;
    }

    private function service(): CustomerAttributionService
    {
        return app(CustomerAttributionService::class);
    }

    /*
    |--------------------------------------------------------------------------
    | §7.1 Identity resolution
    |--------------------------------------------------------------------------
    */

    public function test_identity_resolves_by_employee_number_with_priority(): void
    {
        $rep = User::factory()->create([
            'employee_number' => 'E100',
            'rep_code' => 'RC-100',
            'is_active' => true,
        ]);

        $result = $this->service()->resolveIdentity(' e100 ');

        $this->assertTrue($result->resolved());
        $this->assertSame($rep->id, $result->user->id);
        $this->assertSame('employee_number', $result->matchedVia);
        $this->assertSame('E100', $result->normalizedAlias);
    }

    public function test_identity_resolves_by_rep_code_when_no_employee_number_match(): void
    {
        $rep = User::factory()->create([
            'employee_number' => 'E200',
            'rep_code' => 'RC-200',
            'is_active' => true,
        ]);

        $result = $this->service()->resolveIdentity('RC-200');

        $this->assertTrue($result->resolved());
        $this->assertSame('rep_code', $result->matchedVia);
        $this->assertSame($rep->id, $result->user->id);
    }

    public function test_identity_resolves_by_rep_mapping_as_lowest_priority(): void
    {
        $rep = User::factory()->create([
            'employee_number' => 'E300',
            'rep_code' => 'RC-300',
            'is_active' => true,
        ]);

        UserAcumaticaRepMapping::query()->create([
            'user_id' => $rep->id,
            'acumatica_rep_code' => 'ALIAS-300',
            'is_primary' => true,
        ]);

        $result = $this->service()->resolveIdentity('alias-300');

        $this->assertTrue($result->resolved());
        $this->assertSame('rep_mapping', $result->matchedVia);
        $this->assertSame($rep->id, $result->user->id);
    }

    public function test_identity_reports_unresolved_for_unknown_code(): void
    {
        $result = $this->service()->resolveIdentity('DOES-NOT-EXIST');

        $this->assertSame(IdentityResolution::STATUS_UNRESOLVED, $result->status);
        $this->assertNull($result->user);
    }

    public function test_identity_reports_inactive_only_matches_separately(): void
    {
        User::factory()->create([
            'employee_number' => 'E400',
            'is_active' => false,
        ]);

        $result = $this->service()->resolveIdentity('E400');

        $this->assertSame(IdentityResolution::STATUS_INACTIVE, $result->status);
        $this->assertNull($result->user);
    }

    public function test_identity_flags_duplicate_active_matches_at_same_priority(): void
    {
        User::factory()->create(['employee_number' => 'DUP', 'is_active' => true]);
        User::factory()->create(['employee_number' => 'DUP', 'is_active' => true]);

        $result = $this->service()->resolveIdentity('DUP');

        $this->assertSame(IdentityResolution::STATUS_AMBIGUOUS, $result->status);
        $this->assertTrue($result->isAmbiguous());
        $this->assertNull($result->user);
    }

    public function test_identity_blocks_cross_priority_conflict(): void
    {
        // employee_number "SHARED" → user A; rep_code "SHARED" → user B.
        User::factory()->create(['employee_number' => 'SHARED', 'is_active' => true]);
        User::factory()->create(['rep_code' => 'SHARED', 'is_active' => true]);

        $result = $this->service()->resolveIdentity('SHARED');

        $this->assertSame(IdentityResolution::STATUS_CONFLICT, $result->status);
        $this->assertTrue($result->isAmbiguous());
        $this->assertNull($result->user);
    }

    /*
    |--------------------------------------------------------------------------
    | §7.2 Customer assignment precedence
    |--------------------------------------------------------------------------
    */

    public function test_manual_override_beats_workbook_assignment(): void
    {
        $owner = $this->makeConsultant();
        $manual = $this->makeConsultant();

        $this->createCustomer('CUST-1');

        UserCustomerAssignment::query()->create([
            'user_id' => $owner->id,
            'customer_acumatica_id' => 'CUST-1',
            'assignment_type' => UserCustomerAssignment::TYPE_SERVICING,
            'priority' => 2,
        ]);
        UserCustomerAssignment::query()->create([
            'user_id' => $manual->id,
            'customer_acumatica_id' => 'CUST-1',
            'assignment_type' => UserCustomerAssignment::TYPE_SERVICING,
            'is_manual_override' => true,
            'priority' => 1,
        ]);

        $resolution = $this->service()->resolveCustomerAssignment('CUST-1');

        $this->assertTrue($resolution->resolved());
        $this->assertSame($manual->id, $resolution->userId);
        $this->assertSame(CustomerAssignmentResolution::SOURCE_MANUAL_OVERRIDE, $resolution->winningSource);
    }

    public function test_explicit_workbook_beats_main_account_rule(): void
    {
        $explicit = $this->makeConsultant();
        $ruleUser = $this->makeConsultant();

        $this->createCustomer('CUST-2', ['main_account_name' => 'Naivas']);

        UserCustomerAssignment::query()->create([
            'user_id' => $explicit->id,
            'customer_acumatica_id' => 'CUST-2',
            'assignment_type' => UserCustomerAssignment::TYPE_SERVICING,
            'priority' => 2,
        ]);
        CustomerAssignmentRule::query()->create([
            'user_id' => $ruleUser->id,
            'rule_type' => CustomerAssignmentRule::TYPE_MAIN_ACCOUNT,
            'match_value' => 'Naivas',
            'priority' => 3,
            'source' => CustomerAssignmentRule::SOURCE_MANUAL,
            'is_active' => true,
        ]);

        $resolution = $this->service()->resolveCustomerAssignment('CUST-2');

        $this->assertTrue($resolution->resolved());
        $this->assertSame($explicit->id, $resolution->userId);
        $this->assertSame(CustomerAssignmentResolution::SOURCE_WORKBOOK_CUSTOMER, $resolution->winningSource);
        // The lower-precedence rule is retained for audit.
        $this->assertNotEmpty($resolution->candidates);
    }

    public function test_main_account_rule_beats_region_rule(): void
    {
        $mainUser = $this->makeConsultant();
        $regionUser = $this->makeConsultant();

        $this->createCustomer('CUST-3', ['main_account_name' => 'Quick Mart']);
        CustomerData::query()->create([
            'customer_acumatica_id' => 'CUST-3',
            'customer_region' => 'Mountain',
        ]);

        CustomerAssignmentRule::query()->create([
            'user_id' => $mainUser->id,
            'rule_type' => CustomerAssignmentRule::TYPE_MAIN_ACCOUNT,
            'match_value' => 'Quick Mart',
            'priority' => 3,
            'source' => CustomerAssignmentRule::SOURCE_MANUAL,
            'is_active' => true,
        ]);
        CustomerAssignmentRule::query()->create([
            'user_id' => $regionUser->id,
            'rule_type' => CustomerAssignmentRule::TYPE_REGION,
            'match_value' => 'Mountain',
            'priority' => 4,
            'source' => CustomerAssignmentRule::SOURCE_MANUAL,
            'is_active' => true,
        ]);

        $resolution = $this->service()->resolveCustomerAssignment('CUST-3');

        $this->assertTrue($resolution->resolved());
        $this->assertSame($mainUser->id, $resolution->userId);
        $this->assertSame(CustomerAssignmentResolution::SOURCE_MAIN_ACCOUNT, $resolution->winningSource);
    }

    public function test_unresolved_when_no_candidates_exist(): void
    {
        $this->createCustomer('CUST-4');

        $resolution = $this->service()->resolveCustomerAssignment('CUST-4');

        $this->assertFalse($resolution->resolved());
        $this->assertSame(CustomerAssignmentResolution::SOURCE_UNRESOLVED, $resolution->winningSource);
        $this->assertNull($resolution->userId);
    }

    /*
    |--------------------------------------------------------------------------
    | §7.3 Mapped-only Sales Consultant gate
    |--------------------------------------------------------------------------
    */

    public function test_mapped_only_gate_true_for_consultant_with_servicing_assignment(): void
    {
        $consultant = $this->makeConsultant();
        $this->createCustomer('CUST-10');
        UserCustomerAssignment::query()->create([
            'user_id' => $consultant->id,
            'customer_acumatica_id' => 'CUST-10',
            'assignment_type' => UserCustomerAssignment::TYPE_SERVICING,
        ]);

        $this->assertTrue($this->service()->isMappedOnlyConsultant($consultant));
    }

    public function test_mapped_only_gate_false_without_sales_consultant_role(): void
    {
        $user = User::factory()->create(['role' => 'Sales Operations', 'is_active' => true]);
        $this->createCustomer('CUST-11');
        UserCustomerAssignment::query()->create([
            'user_id' => $user->id,
            'customer_acumatica_id' => 'CUST-11',
            'assignment_type' => UserCustomerAssignment::TYPE_SERVICING,
        ]);

        $this->assertFalse($this->service()->isMappedOnlyConsultant($user));
    }

    public function test_mapped_only_gate_false_without_active_assignment(): void
    {
        $consultant = $this->makeConsultant();

        $this->assertFalse($this->service()->isMappedOnlyConsultant($consultant));
    }

    public function test_direct_portfolio_excludes_rep_code_sales_order_matches(): void
    {
        $consultant = $this->makeConsultant(['rep_code' => 'REP-X']);
        $this->createCustomer('MAPPED');
        $this->createCustomer('UNMAPPED');

        UserCustomerAssignment::query()->create([
            'user_id' => $consultant->id,
            'customer_acumatica_id' => 'MAPPED',
            'assignment_type' => UserCustomerAssignment::TYPE_SERVICING,
        ]);

        // An SO carrying the consultant's rep code for an UNMAPPED customer must
        // NOT leak into the mapped portfolio (§7.3).
        AcumaticaSalesOrder::query()->create([
            'acumatica_order_nbr' => 'SO-0001',
            'order_type' => AcumaticaSalesOrder::TYPE_SALES_ORDER,
            'customer_acumatica_id' => 'UNMAPPED',
            'sales_consultant_rep_code' => 'REP-X',
            'order_date' => now()->toDateString(),
        ]);

        $direct = $this->service()->directCustomerIds($consultant->id);

        $this->assertSame(['MAPPED'], $direct);
    }

    /*
    |--------------------------------------------------------------------------
    | §7.4 Directional hierarchy de-duplication
    |--------------------------------------------------------------------------
    */

    public function test_visible_customers_are_de_duped_union_of_subtree(): void
    {
        $manager = User::factory()->create([
            'role' => 'HOD',
            'org_level' => 'hod',
            'is_active' => true,
        ]);
        $reportee = $this->makeConsultant(['reports_to_user_id' => $manager->id]);

        $this->createCustomer('OWN-1');
        $this->createCustomer('REPORT-1');
        $this->createCustomer('REPORT-2');

        UserCustomerAssignment::query()->create([
            'user_id' => $manager->id,
            'customer_acumatica_id' => 'OWN-1',
            'assignment_type' => UserCustomerAssignment::TYPE_SERVICING,
        ]);
        foreach (['REPORT-1', 'REPORT-2'] as $id) {
            UserCustomerAssignment::query()->create([
                'user_id' => $reportee->id,
                'customer_acumatica_id' => $id,
                'assignment_type' => UserCustomerAssignment::TYPE_SERVICING,
            ]);
        }

        $visible = $this->service()->visibleCustomerIds($manager->id);

        sort($visible);

        $this->assertSame(['OWN-1', 'REPORT-1', 'REPORT-2'], $visible);
        // Self + reportee descendants.
        $this->assertSame([$manager->id, $reportee->id], $this->service()->visibleUserIds($manager->id));
    }

    public function test_reportee_cannot_see_sibling_or_manager_portfolio(): void
    {
        $manager = User::factory()->create(['role' => 'HOD', 'org_level' => 'hod', 'is_active' => true]);
        $sibling = $this->makeConsultant(['reports_to_user_id' => $manager->id]);
        $consultant = $this->makeConsultant(['reports_to_user_id' => $manager->id]);

        $this->createCustomer('SIBLING-1');
        $this->createCustomer('MINE-1');

        UserCustomerAssignment::query()->create([
            'user_id' => $sibling->id,
            'customer_acumatica_id' => 'SIBLING-1',
            'assignment_type' => UserCustomerAssignment::TYPE_SERVICING,
        ]);
        UserCustomerAssignment::query()->create([
            'user_id' => $consultant->id,
            'customer_acumatica_id' => 'MINE-1',
            'assignment_type' => UserCustomerAssignment::TYPE_SERVICING,
        ]);

        $visible = $this->service()->visibleCustomerIds($consultant->id);

        // A leaf consultant sees only their own mapped customers.
        $this->assertSame(['MINE-1'], $visible);
        $this->assertNotContains('SIBLING-1', $visible);
    }

    public function test_executive_business_access_overrides_attached_outlet_gate(): void
    {
        $manager = User::factory()->create([
            'role' => 'Administrator',
            'org_level' => 'executive',
            'data_scope_mode' => 'org_wide',
            'is_super_admin' => false,
            'is_active' => true,
        ]);
        $rep = $this->makeConsultant(['reports_to_user_id' => $manager->id]);
        $this->createCustomer('ATTACHED-MT1', ['sales_channel_code' => 'MT1']);
        $this->createCustomer('UNATTACHED-KP', ['sales_channel_code' => 'KP']);
        UserCustomerAssignment::query()->create([
            'user_id' => $rep->id,
            'customer_acumatica_id' => 'ATTACHED-MT1',
            'assignment_type' => UserCustomerAssignment::TYPE_SERVICING,
        ]);

        $visible = DataScope::scopedCustomerAcumaticaIds($manager);

        $this->assertNull($visible);
        $this->assertTrue(DataScope::customerAccessible($manager, 'ATTACHED-MT1'));
        $this->assertTrue(DataScope::customerAccessible($manager, 'UNATTACHED-KP'));
    }

    public function test_true_super_admin_keeps_emergency_org_wide_scope(): void
    {
        $admin = User::factory()->create([
            'role' => 'Administrator',
            'data_scope_mode' => 'org_wide',
            'is_super_admin' => true,
            'is_active' => true,
        ]);
        $this->createCustomer('ANY-CUSTOMER');
        UserCustomerAssignment::query()->create([
            'user_id' => $admin->id,
            'customer_acumatica_id' => 'ANY-CUSTOMER',
            'assignment_type' => UserCustomerAssignment::TYPE_SERVICING,
        ]);

        $this->assertNull(DataScope::scopedCustomerAcumaticaIds($admin));
    }

    private function createCustomer(string $acumaticaId, array $extra = []): AcumaticaCustomer
    {
        return AcumaticaCustomer::query()->create(array_merge([
            'acumatica_id' => $acumaticaId,
            'name' => $acumaticaId . ' Name',
            'customer_class' => 'MT-CHAIN',
            'status' => 'Active',
        ], $extra));
    }
}
