<?php

namespace Tests\Feature;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaSalesOrder;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KpAccountsControllerTest extends TestCase
{
    use RefreshDatabase;

    private ?Department $department = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-23 12:00:00', 'Africa/Nairobi'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_mode_self_returns_only_the_managers_own_book(): void
    {
        $manager = $this->makeKpUser(['department_role' => 'hod', 'rep_code' => 'PMGR']);
        $this->makeKpUser([
            'rep_code' => 'PREP',
            'reports_to_user_id' => $manager->id,
        ]);

        $this->makeKpCustomer('CUST-MGR', 'Manager Own Customer');
        $this->makeKpCustomer('CUST-REP', 'Report Customer');
        $this->createOrder('SO-MGR-1', 'PMGR', 'CUST-MGR');
        $this->createOrder('SO-REP-1', 'PREP', 'CUST-REP');

        // "My book" — the manager's own rep-code book only, even though they can see their subtree.
        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/kp/accounts?mode=self')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.acumatica_id', 'CUST-MGR')
            ->assertJsonPath('scope', 'my_portfolio');

        // Default/team scope — HOD subtree includes the report's book too.
        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/kp/accounts')
            ->assertOk()
            ->assertJsonPath('total', 2);
    }

    public function test_rep_code_param_scopes_to_that_team_members_book_and_is_authorized(): void
    {
        $manager = $this->makeKpUser(['department_role' => 'hod', 'rep_code' => 'PMGR2']);
        $this->makeKpUser([
            'rep_code' => 'PREP2',
            'reports_to_user_id' => $manager->id,
        ]);
        $outsider = $this->makeKpUser(['rep_code' => 'POUT']);

        $this->makeKpCustomer('CUST-REP2', 'Report Two Customer');
        $this->createOrder('SO-REP2-1', 'PREP2', 'CUST-REP2');

        // Manager expanding the report's accordion row — scoped to just that rep's book.
        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/kp/accounts?rep_code=PREP2')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.acumatica_id', 'CUST-REP2');

        // An outsider (not in the manager's subtree) cannot borrow rep_code to peek at it —
        // resolves to an authorized-but-empty result, not an error or data leak.
        $this->actingAs($outsider, 'sanctum')
            ->getJson('/api/kp/accounts?rep_code=PREP2')
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_by_rep_groups_team_accounts_with_stats(): void
    {
        $manager = $this->makeKpUser(['department_role' => 'hod', 'rep_code' => null]);
        $report = $this->makeKpUser([
            'rep_code' => 'PREP3',
            'employee_number' => 'EMP-3',
            'name' => 'Titus',
            'reports_to_user_id' => $manager->id,
        ]);

        $this->makeKpCustomer('CUST-ACTIVE', 'Active Customer');
        $this->makeKpCustomer('CUST-HOLD', 'On Hold Customer', 'On Hold');
        $this->createOrder('SO-REP3-1', 'PREP3', 'CUST-ACTIVE', 1000);
        $this->createOrder('SO-REP3-2', 'PREP3', 'CUST-HOLD', 500);

        DB::table('commission_targets')->insert([
            'user_id' => $report->id,
            'period_month' => '2026-07-01',
            'target_amount' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/kp/accounts/by-rep')
            ->assertOk();

        $groups = collect($response->json('groups'))->keyBy('rep_code');
        $this->assertArrayHasKey('PREP3', $groups->toArray());
        $group = $groups['PREP3'];
        $this->assertSame('Titus', $group['rep_name']);
        $this->assertSame('EMP-3', $group['employee_number']);
        $this->assertSame(2, $group['account_count']);
        $this->assertSame(1, $group['on_hold_count']);
        $this->assertEquals(1500, $group['revenue_mtd']);
        $this->assertEquals(10000, $group['target']);
    }

    public function test_by_rep_forbidden_for_non_manager(): void
    {
        $consultant = $this->makeKpUser(['rep_code' => 'PSOLO']);

        $this->actingAs($consultant, 'sanctum')
            ->getJson('/api/kp/accounts/by-rep')
            ->assertForbidden();
    }

    public function test_all_classes_includes_non_kp_customers_in_portfolio(): void
    {
        $user = $this->makeKpUser(['rep_code' => 'PALL']);
        $this->makeKpCustomer('CUST-KP', 'KP Hotel');
        AcumaticaCustomer::create([
            'acumatica_id' => 'CUST-MT',
            'name' => 'Modern Trade Shop',
            'customer_class' => 'MT-CHAIN',
            'status' => 'Active',
        ]);
        $this->createOrder('SO-KP-1', 'PALL', 'CUST-KP');
        $this->createOrder('SO-MT-1', 'PALL', 'CUST-MT');

        // Default remains KP-only (legacy KP CRM).
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/kp/accounts?mode=self')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.acumatica_id', 'CUST-KP');

        // My Portfolio: every class on the book.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/kp/accounts?mode=self&all_classes=1')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('all_classes', true)
            ->assertJsonFragment(['acumatica_id' => 'CUST-KP'])
            ->assertJsonFragment(['acumatica_id' => 'CUST-MT']);
    }

    private function department(): Department
    {
        return $this->department ??= Department::query()->create([
            'slug' => 'kp-accounts-test-dept',
            'name' => 'KP Accounts Test Department',
            'is_customer_facing' => true,
            'sort_order' => 1,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeKpUser(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'role' => 'Sales Consultant',
            'is_consultant' => true,
            'is_active' => true,
            'is_super_admin' => false,
            'data_scope_mode' => 'scoped',
            'department_id' => $this->department()->id,
        ], $overrides));

        $perm = Permission::query()->firstOrCreate(
            ['name' => 'kp.accounts.view'],
            ['label' => 'KP accounts view', 'group' => 'kp'],
        );
        $role = Role::query()->firstOrCreate(
            ['name' => 'kp-accounts-test-role'],
            ['label' => 'KP Accounts Test Role'],
        );
        $role->permissions()->syncWithoutDetaching([$perm->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function makeKpCustomer(string $id, string $name, string $status = 'Active'): AcumaticaCustomer
    {
        return AcumaticaCustomer::create([
            'acumatica_id' => $id,
            'name' => $name,
            'customer_class' => 'KP-HORECA',
            'status' => $status,
        ]);
    }

    private function createOrder(string $orderNbr, string $repCode, string $customerId, float $total = 1000): AcumaticaSalesOrder
    {
        return AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => $orderNbr,
            'order_type' => 'SO',
            'customer_acumatica_id' => $customerId,
            'customer_name' => $customerId,
            'sales_consultant_rep_code' => $repCode,
            'order_date' => '2026-07-10',
            'status' => 'Open',
            'order_total' => $total,
        ]);
    }
}
