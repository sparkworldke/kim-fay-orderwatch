<?php

namespace Tests\Feature;

use App\Models\AcumaticaCustomer;
use App\Models\KpMeeting;
use App\Models\KpMeetingPurpose;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KpMeetingModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_month_dashboard_counts_completed_meetings_once(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_super_admin' => true]);
        $consultant = User::factory()->create(['role' => 'Sales Consultant', 'is_consultant' => true, 'is_active' => true]);
        $purpose = KpMeetingPurpose::query()->firstOrFail();

        KpMeeting::query()->create([
            'title' => 'Customer review', 'purpose_id' => $purpose->id, 'meeting_mode' => 'physical',
            'is_internal' => true, 'is_planned' => true, 'starts_at' => now('Africa/Nairobi'),
            'status' => 'completed', 'current_notes' => 'Reviewed the account.', 'outcome' => 'Proposal requested.',
            'no_follow_up_reason' => 'Customer will revert.', 'owner_user_id' => $consultant->id, 'created_by' => $consultant->id,
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/kp/meetings-dashboard?month='.now('Africa/Nairobi')->format('Y-m').'&owner_user_id='.$consultant->id)
            ->assertOk()->assertJsonPath('metrics.achieved', 1)->assertJsonPath('metrics.completed_planned', 1);
    }

    public function test_completion_requires_notes_outcome_and_follow_up_disposition(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_super_admin' => true]);
        $purpose = KpMeetingPurpose::query()->firstOrFail();
        $meeting = KpMeeting::query()->create([
            'title' => 'Internal review', 'purpose_id' => $purpose->id, 'meeting_mode' => 'virtual',
            'is_internal' => true, 'is_planned' => true, 'starts_at' => now('Africa/Nairobi'),
            'status' => 'scheduled', 'owner_user_id' => $admin->id, 'created_by' => $admin->id,
        ]);

        Sanctum::actingAs($admin);
        $this->putJson('/api/kp/meetings/'.$meeting->id, ['status' => 'completed'])
            ->assertStatus(422);
        $response = $this->putJson('/api/kp/meetings/'.$meeting->id, [
            'status' => 'completed', 'current_notes' => 'Met the team.',
            'outcome' => 'Agreed the next steps.', 'follow_up_date' => now()->addWeek()->toDateString(),
        ]);
        $response->assertOk()->assertJsonPath('status', 'completed');
    }

    public function test_customer_name_is_snapshotted_from_acumatica(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_super_admin' => true]);
        $purpose = KpMeetingPurpose::query()->firstOrFail();
        AcumaticaCustomer::query()->create(['acumatica_id' => 'KP-001', 'name' => 'Hotel Alpha', 'customer_class' => 'KP']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/kp/meetings', [
            'title' => 'Hotel pitch', 'purpose_id' => $purpose->id, 'meeting_mode' => 'physical',
            'is_internal' => false, 'is_planned' => true, 'customer_acumatica_id' => 'KP-001',
            'customer_name' => 'Spoofed name', 'starts_at' => now('Africa/Nairobi')->toIso8601String(),
        ])->assertCreated()->assertJsonPath('customer_name', 'Hotel Alpha');
    }
}
