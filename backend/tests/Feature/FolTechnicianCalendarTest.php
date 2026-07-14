<?php

namespace Tests\Feature;

use App\Models\FolRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FolTechnicianCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_technician_sees_allocations_accounts_and_resolved_counts(): void
    {
        $this->seedRoles();

        $tech = User::factory()->create([
            'role' => 'Technician',
            'is_active' => true,
            'name' => 'Field Tech',
        ]);
        $techRole = Role::where('name', 'Technician')->firstOrFail();
        $tech->roles()->sync([$techRole->id]);

        $open = FolRequest::query()->create([
            'public_ref' => 'FOL-2026-000001',
            'customer_acumatica_id' => 'C001',
            'customer_name' => 'Account Alpha',
            'request_origin' => 'sales_consultant_visit',
            'requestor_first_name' => 'A',
            'requestor_last_name' => 'B',
            'requestor_phone' => '0700',
            'requestor_email' => 'a@test.com',
            'issue_types' => ['new_dispenser'],
            'reason_text' => str_repeat('Need dispenser install for account. ', 2),
            'debt_explanation' => 'None',
            'status' => 'ready_for_invoicing',
            'assigned_technician_user_id' => $tech->id,
            'technician_assigned_at' => now('Africa/Nairobi'),
            'installation_required' => true,
            'installation_location' => 'Nairobi HQ',
        ]);

        FolRequest::query()->create([
            'public_ref' => 'FOL-2026-000002',
            'customer_acumatica_id' => 'C002',
            'customer_name' => 'Account Beta',
            'request_origin' => 'sales_consultant_visit',
            'requestor_first_name' => 'A',
            'requestor_last_name' => 'B',
            'requestor_phone' => '0700',
            'requestor_email' => 'a@test.com',
            'issue_types' => ['maintenance_parts'],
            'reason_text' => str_repeat('Maintenance completed previously. ', 2),
            'debt_explanation' => 'None',
            'status' => 'fulfilled',
            'assigned_technician_user_id' => $tech->id,
            'technician_assigned_at' => now('Africa/Nairobi')->subDays(2),
            'installation_required' => true,
        ]);

        Sanctum::actingAs($tech);

        $month = now('Africa/Nairobi')->format('Y-m');
        $this->getJson("/api/kp/fol/technician/calendar?month={$month}")
            ->assertOk()
            ->assertJsonPath('summary.allocated_open', 1)
            ->assertJsonPath('summary.resolved', 1)
            ->assertJsonPath('summary.distinct_accounts', 2)
            ->assertJsonFragment(['customer_name' => 'Account Alpha'])
            ->assertJsonFragment(['customer_name' => 'Account Beta']);

        // Mark open allocation resolved
        $this->postJson("/api/kp/fol/{$open->id}/technician/resolve", [
            'comment' => 'Installed on site',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'fulfilled');

        $this->getJson("/api/kp/fol/technician/calendar?month={$month}")
            ->assertOk()
            ->assertJsonPath('summary.allocated_open', 0)
            ->assertJsonPath('summary.resolved', 2);
    }

    public function test_technician_cannot_view_another_technicians_calendar(): void
    {
        $this->seedRoles();

        $techA = User::factory()->create(['role' => 'Technician', 'is_active' => true]);
        $techB = User::factory()->create(['role' => 'Technician', 'is_active' => true]);
        $role = Role::where('name', 'Technician')->firstOrFail();
        $techA->roles()->sync([$role->id]);
        $techB->roles()->sync([$role->id]);

        Sanctum::actingAs($techA);
        $this->getJson('/api/kp/fol/technician/calendar?technician_user_id='.$techB->id)
            ->assertForbidden();
    }

    private function seedRoles(): void
    {
        foreach (['kp.fol.view', 'kp.fol.install.execute', 'kp.fol.install.manage'] as $name) {
            Permission::query()->firstOrCreate(
                ['name' => $name],
                ['description' => $name, 'module' => 'kp'],
            );
        }

        $tech = Role::query()->firstOrCreate(['name' => 'Technician'], ['description' => 'Tech']);
        $tech->permissions()->sync(
            Permission::whereIn('name', ['kp.fol.view', 'kp.fol.install.execute'])->pluck('id')->all()
        );
    }
}
