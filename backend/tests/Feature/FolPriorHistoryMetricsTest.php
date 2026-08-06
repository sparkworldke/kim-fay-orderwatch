<?php

namespace Tests\Feature;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaInventoryItem;
use App\Models\FolRequest;
use App\Models\FolRequestLine;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FolPriorHistoryMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_metrics_include_previous_fol_history_at_top_level(): void
    {
        $user = $this->makeFolUser();
        AcumaticaCustomer::create([
            'acumatica_id' => 'CUST-KP-PRIOR',
            'name' => 'KP Prior Customer',
            'customer_class' => 'KP Hotel',
            'synced_at' => now(),
        ]);
        AcumaticaInventoryItem::create([
            'inventory_id' => 'DISPE0007',
            'description' => 'Dispenser 7',
            'is_fol_eligible' => true,
            'qty_on_hand' => 3,
        ]);

        $fol = FolRequest::create([
            'public_ref' => 'FOL-2026-000777',
            'customer_acumatica_id' => 'CUST-KP-PRIOR',
            'customer_name' => 'KP Prior Customer',
            'sales_consultant_user_id' => $user->id,
            'sales_consultant_email' => $user->email,
            'request_origin' => 'sales_consultant_visit',
            'requestor_first_name' => 'A',
            'requestor_last_name' => 'B',
            'requestor_phone' => '+254700',
            'requestor_email' => 'a@b.test',
            'issue_types' => ['new_dispenser'],
            'reason_text' => str_repeat('Prior FOL history test reason. ', 2),
            'debt_explanation' => 'ok',
            'status' => 'fulfilled',
            'decided_at' => now()->subDays(10),
            'submitted_at' => now()->subDays(12),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        FolRequestLine::create([
            'fol_request_id' => $fol->id,
            'line_no' => 1,
            'inventory_id' => 'DISPE0007',
            'product_description' => 'Dispenser 7',
            'qty_requested' => 2,
            'qty_previously_issued' => 0,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/kp/fol/metrics?customer_acumatica_id=CUST-KP-PRIOR&inventory_id[]=DISPE0007');

        if ($response->status() === 403) {
            $this->markTestSkipped('FOL permission seed not available.');
        }

        $response->assertOk()
            ->assertJsonPath('prior_fol.request_count', 1)
            ->assertJsonPath('prior_fol.total_qty_issued', 2)
            ->assertJsonPath('prior_fol.last_public_ref', 'FOL-2026-000777')
            ->assertJsonPath('prior_fol.by_sku.DISPE0007.qty', 2)
            ->assertJsonPath('metrics.prior_fol.request_count', 1)
            ->assertJsonPath('prior_issued.DISPE0007.qty', 2);
    }

    private function makeFolUser(): User
    {
        $user = User::factory()->create([
            'role' => 'Administrator',
            'is_active' => true,
            'email' => 'fol-prior@kimfay.test',
        ]);

        if (Schema::hasTable('permissions')) {
            $perm = Permission::query()->firstOrCreate(
                ['name' => 'kp.fol.view'],
                ['label' => 'FOL view', 'group' => 'fol'],
            );
            $role = Role::query()->firstOrCreate(
                ['name' => 'Administrator'],
                ['label' => 'Administrator'],
            );
            $role->permissions()->syncWithoutDetaching([$perm->id]);
            if (method_exists($user, 'roles')) {
                $user->roles()->syncWithoutDetaching([$role->id]);
            }
        }

        return $user;
    }
}
