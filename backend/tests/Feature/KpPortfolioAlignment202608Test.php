<?php

namespace Tests\Feature;

use App\Models\CustomerContact;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCustomerAssignment;
use Database\Seeders\KpCustomerPortfolio202608Seeder;
use Database\Seeders\KpFolTechnicianEligibility202608Seeder;
use Database\Seeders\KpRepCodeAlignment202608Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KpPortfolioAlignment202608Test extends TestCase
{
    use RefreshDatabase;

    public function test_customer_portfolio_is_imported_with_a_default_company_contact(): void
    {
        $berna = User::factory()->create([
            'employee_number' => 'P460',
            'rep_code' => 'C967',
            'is_active' => true,
        ]);

        $this->seed(KpCustomerPortfolio202608Seeder::class);

        $this->assertDatabaseCount('customer_data', 1000);
        $this->assertDatabaseHas('customer_data', [
            'customer_acumatica_id' => 'CUST100003',
            'route_code' => '3C',
            'shipping_zone_id' => 'Z003',
            'customer_group' => 'Kim-Fay Professional',
            'rep_code' => 'C967',
            'sales_rep' => 'Berna Piwang',
            'credit_limit' => 1.00,
        ]);
        $this->assertDatabaseHas('customer_contacts', [
            'customer_acumatica_id' => 'CUST100003',
            'first_name' => '5Th Avenue Management Office',
            'last_name' => '',
            'designation_key' => CustomerContact::DESIGNATION_CUSTOM,
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('user_customer_assignments', [
            'user_id' => $berna->id,
            'customer_acumatica_id' => 'CUST100003',
            'assignment_type' => 'servicing',
            'source' => 'kp_customers_20260805',
        ]);

        $this->seed(KpCustomerPortfolio202608Seeder::class);

        $this->assertDatabaseCount('customer_data', 1000);
        $this->assertSame(1, CustomerContact::query()
            ->where('customer_acumatica_id', 'CUST100003')
            ->count());
    }

    public function test_existing_contacts_are_not_changed_or_duplicated(): void
    {
        $contact = CustomerContact::query()->create([
            'customer_acumatica_id' => 'CUST100003',
            'designation_key' => CustomerContact::DESIGNATION_CFO_FINANCE,
            'designation_label' => 'Finance Director',
            'first_name' => 'Existing',
            'last_name' => 'Contact',
            'is_primary' => true,
            'is_active' => true,
        ]);

        $this->seed(KpCustomerPortfolio202608Seeder::class);

        $this->assertSame(1, CustomerContact::query()
            ->where('customer_acumatica_id', 'CUST100003')
            ->count());
        $this->assertSame('Existing', $contact->fresh()->first_name);
        $this->assertSame(CustomerContact::DESIGNATION_CFO_FINANCE, $contact->fresh()->designation_key);
    }

    public function test_rep_codes_and_fol_technician_roles_are_additive_and_idempotent(): void
    {
        foreach (['P317', 'P460', 'P483', 'P051', 'P163', 'P369'] as $employeeNumber) {
            User::factory()->create([
                'employee_number' => $employeeNumber,
                'rep_code' => null,
                'role' => 'Sales Consultant',
                'designation' => 'Original designation',
                'is_active' => true,
            ]);
        }
        $technicianRole = Role::query()->create(['name' => 'Technician', 'is_system' => true]);

        $this->seed(KpRepCodeAlignment202608Seeder::class);
        $this->seed(KpFolTechnicianEligibility202608Seeder::class);
        $this->seed(KpRepCodeAlignment202608Seeder::class);
        $this->seed(KpFolTechnicianEligibility202608Seeder::class);

        $this->assertSame('YVON', User::query()->where('employee_number', 'P317')->value('rep_code'));
        $this->assertSame('C967', User::query()->where('employee_number', 'P460')->value('rep_code'));
        $this->assertSame('C1262', User::query()->where('employee_number', 'P483')->value('rep_code'));
        $this->assertDatabaseCount('user_rep_code_history', 3);

        foreach (['P051', 'P163', 'P369'] as $employeeNumber) {
            $user = User::query()->where('employee_number', $employeeNumber)->firstOrFail();
            $this->assertTrue($user->roles()->whereKey($technicianRole->id)->exists());
            $this->assertSame('Sales Consultant', $user->role);
            $this->assertSame('Original designation', $user->designation);
        }
        $this->assertDatabaseCount('user_roles', 3);
    }

    public function test_rep_code_owner_change_replaces_previous_assignment_instead_of_duplicating(): void
    {
        $berna = User::factory()->create([
            'employee_number' => 'P460',
            'rep_code' => 'C967',
            'is_active' => true,
        ]);

        $this->seed(KpCustomerPortfolio202608Seeder::class);

        $this->assertDatabaseHas('user_customer_assignments', [
            'user_id' => $berna->id,
            'customer_acumatica_id' => 'CUST100003',
            'assignment_type' => UserCustomerAssignment::TYPE_SERVICING,
        ]);

        $berna->forceFill(['rep_code' => 'ZZZ9'])->save();
        $successor = User::factory()->create([
            'employee_number' => 'P999',
            'rep_code' => 'C967',
            'is_active' => true,
        ]);

        $this->seed(KpCustomerPortfolio202608Seeder::class);

        $this->assertSame(1, UserCustomerAssignment::query()
            ->where('customer_acumatica_id', 'CUST100003')
            ->whereIn('assignment_type', [UserCustomerAssignment::TYPE_SERVICING, UserCustomerAssignment::TYPE_LEGACY_PRIMARY])
            ->count());
        $this->assertDatabaseHas('user_customer_assignments', [
            'user_id' => $successor->id,
            'customer_acumatica_id' => 'CUST100003',
            'assignment_type' => UserCustomerAssignment::TYPE_SERVICING,
        ]);
        $this->assertDatabaseMissing('user_customer_assignments', [
            'user_id' => $berna->id,
            'customer_acumatica_id' => 'CUST100003',
        ]);
    }

    public function test_ambiguous_rep_code_owners_are_skipped_not_arbitrarily_attached(): void
    {
        User::factory()->create([
            'employee_number' => 'P460',
            'rep_code' => 'C967',
            'is_active' => true,
        ]);
        User::factory()->create([
            'employee_number' => 'P999',
            'rep_code' => 'C967',
            'is_active' => true,
        ]);

        $this->seed(KpCustomerPortfolio202608Seeder::class);

        $this->assertSame(0, UserCustomerAssignment::query()
            ->where('customer_acumatica_id', 'CUST100003')
            ->count());
    }
}
