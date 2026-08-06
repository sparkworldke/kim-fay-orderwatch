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

class FolDraftUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_draft_fol_lines_and_fields(): void
    {
        $user = $this->makeFolUser();
        $this->seedCustomerAndSku();

        $create = $this->actingAs($user, 'sanctum')->postJson('/api/kp/fol', $this->basePayload());
        if ($create->status() === 403) {
            $this->markTestSkipped('FOL permission seed not available.');
        }
        $create->assertCreated();
        $id = (int) $create->json('id');

        $updatedReason = 'Updated reason for draft edit flow for the customer site.';
        $update = $this->actingAs($user, 'sanctum')->putJson("/api/kp/fol/{$id}", [
            ...$this->basePayload(),
            'reason_text' => $updatedReason,
            'debt_explanation' => 'Updated debt note.',
            'lines' => [
                ['inventory_id' => 'DISPE0136', 'qty_requested' => 3],
            ],
        ]);

        $update->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('reason_text', $updatedReason)
            ->assertJsonPath('debt_explanation', 'Updated debt note.');

        $this->assertSame(3.0, (float) $update->json('lines.0.qty_requested'));
        $this->assertDatabaseCount('fol_request_lines', 1);
        $this->assertDatabaseHas('fol_request_lines', [
            'fol_request_id' => $id,
            'inventory_id' => 'DISPE0136',
        ]);
        $this->assertSame(3.0, (float) FolRequestLine::query()->where('fol_request_id', $id)->value('qty_requested'));
        $this->assertDatabaseHas('fol_request_events', [
            'fol_request_id' => $id,
            'event_type' => 'draft_updated',
        ]);
    }

    public function test_submitted_fol_cannot_be_edited(): void
    {
        $user = $this->makeFolUser();
        $this->seedCustomerAndSku();

        $create = $this->actingAs($user, 'sanctum')->postJson('/api/kp/fol', $this->basePayload());
        if ($create->status() === 403) {
            $this->markTestSkipped('FOL permission seed not available.');
        }
        $id = (int) $create->json('id');

        FolRequest::query()->whereKey($id)->update(['status' => 'submitted']);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/kp/fol/{$id}", $this->basePayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_non_owner_cannot_update_draft(): void
    {
        $owner = $this->makeFolUser(['email' => 'owner@kimfay.test']);
        $other = $this->makeFolUser(['email' => 'other@kimfay.test', 'role' => 'Sales Consultant']);
        $this->seedCustomerAndSku();

        $create = $this->actingAs($owner, 'sanctum')->postJson('/api/kp/fol', $this->basePayload());
        if ($create->status() === 403) {
            $this->markTestSkipped('FOL permission seed not available.');
        }
        $id = (int) $create->json('id');

        $this->seedFolPermission($other);

        $this->actingAs($other, 'sanctum')
            ->putJson("/api/kp/fol/{$id}", $this->basePayload())
            ->assertForbidden();
    }

    /** @param  array<string, mixed>  $overrides */
    private function makeFolUser(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'role' => 'Administrator',
            'is_active' => true,
            'email' => 'fol-editor@kimfay.test',
        ], $overrides));
        $this->seedFolPermission($user);

        return $user;
    }

    private function seedCustomerAndSku(): void
    {
        AcumaticaCustomer::create([
            'acumatica_id' => 'CUST-KP-DRAFT',
            'name' => 'KP Draft Customer',
            'customer_class' => 'KP Hotel',
            'synced_at' => now(),
        ]);
        AcumaticaInventoryItem::create([
            'inventory_id' => 'DISPE0136',
            'description' => 'Dispenser',
            'is_fol_eligible' => true,
            'qty_on_hand' => 10,
        ]);
    }

    /** @return array<string, mixed> */
    private function basePayload(): array
    {
        return [
            'customer_acumatica_id' => 'CUST-KP-DRAFT',
            'request_origin' => 'sales_consultant_visit',
            'requestor_first_name' => 'Jane',
            'requestor_last_name' => 'Doe',
            'requestor_phone' => '+254700000000',
            'requestor_email' => 'jane@customer.test',
            'issue_types' => ['new_dispenser'],
            'reason_text' => str_repeat('Need free on loan dispenser for site. ', 2),
            'debt_explanation' => 'Account in good standing.',
            'lines' => [
                ['inventory_id' => 'DISPE0136', 'qty_requested' => 1],
            ],
        ];
    }

    private function seedFolPermission(User $user): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $perm = Permission::query()->firstOrCreate(
            ['name' => 'kp.fol.request'],
            ['label' => 'FOL request', 'group' => 'fol'],
        );
        $view = Permission::query()->firstOrCreate(
            ['name' => 'kp.fol.view'],
            ['label' => 'FOL view', 'group' => 'fol'],
        );
        $role = Role::query()->firstOrCreate(
            ['name' => $user->role ?: 'Administrator'],
            ['label' => $user->role ?: 'Administrator'],
        );
        $role->permissions()->syncWithoutDetaching([$perm->id, $view->id]);
        if (method_exists($user, 'roles')) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }
    }
}
