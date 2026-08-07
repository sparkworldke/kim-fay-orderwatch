<?php

namespace Tests\Feature;

use App\Models\AcumaticaCustomer;
use App\Models\KpMeeting;
use App\Models\KpMeetingPurpose;
use App\Models\KpActivityQuestionnaire;
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

    public function test_unified_activity_api_keeps_legacy_meeting_list_filtered(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_super_admin' => true]);
        $purpose = KpMeetingPurpose::query()->whereJsonContains('activity_types', 'phone')->firstOrFail();
        AcumaticaCustomer::query()->create(['acumatica_id' => 'KP-002', 'name' => 'Hotel Beta', 'customer_class' => 'KP']);
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/kp/activities', [
            'title' => 'Payment check-in', 'activity_type' => 'phone', 'purpose_id' => $purpose->id,
            'meeting_mode' => 'virtual', 'is_internal' => false, 'is_planned' => false,
            'customer_acumatica_id' => 'KP-002', 'starts_at' => now('Africa/Nairobi')->toIso8601String(),
        ])->assertCreated()->assertJsonPath('activity_type', 'phone');

        $this->getJson('/api/kp/activities?month='.now('Africa/Nairobi')->format('Y-m'))
            ->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/kp/meetings?month='.now('Africa/Nairobi')->format('Y-m'))
            ->assertOk()->assertJsonPath('total', 0);
        $this->assertNotNull($created->json('id'));
    }

    public function test_questionnaire_is_required_when_an_activity_is_completed(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_super_admin' => true]);
        $purpose = KpMeetingPurpose::query()->whereJsonContains('activity_types', 'phone')->firstOrFail();
        $questionnaire = KpActivityQuestionnaire::query()->where('activity_type', 'phone')->whereNull('purpose_id')->firstOrFail();
        Sanctum::actingAs($admin);

        $activity = $this->postJson('/api/kp/activities', [
            'title'=>'Call','activity_type'=>'phone','purpose_id'=>$purpose->id,'questionnaire_id'=>$questionnaire->id,
            'meeting_mode'=>'virtual','is_internal'=>true,'is_planned'=>false,'starts_at'=>now()->toIso8601String(),
        ])->assertCreated()->json();

        $completion = ['status'=>'completed','current_notes'=>'Called the customer.','outcome'=>'Follow-up agreed.','no_follow_up_reason'=>'Action captured separately.'];
        $this->putJson('/api/kp/activities/'.$activity['id'], $completion)->assertStatus(422);
        $answers = collect($questionnaire->questions)->mapWithKeys(fn($question)=>[$question['key']=>'Recorded'])->all();
        $this->putJson('/api/kp/activities/'.$activity['id'], [...$completion,'questionnaire_answers'=>$answers])
            ->assertOk()->assertJsonPath('status','completed')->assertJsonPath('questionnaire_version',1);
    }

    public function test_executive_has_organization_visibility_but_cannot_write(): void
    {
        $admin = User::factory()->create(['role'=>'Administrator','is_super_admin'=>true]);
        $executive = User::factory()->create(['role'=>'Executive','org_level'=>'executive','is_active'=>true]);
        $consultant = User::factory()->create(['role'=>'Sales Consultant','is_consultant'=>true,'is_active'=>true]);
        $purpose = KpMeetingPurpose::query()->firstOrFail();
        KpMeeting::query()->create(['title'=>'Visible activity','activity_type'=>'meeting','purpose_id'=>$purpose->id,'meeting_mode'=>'physical','is_internal'=>true,'is_planned'=>true,'starts_at'=>now(),'status'=>'scheduled','owner_user_id'=>$consultant->id,'created_by'=>$admin->id]);

        Sanctum::actingAs($executive);
        $this->getJson('/api/kp/activities/dashboard?scope=organization&month='.now()->format('Y-m'))
            ->assertOk()->assertJsonPath('metrics.total',1)->assertJsonPath('scope','organization');
        $this->postJson('/api/kp/activities', [])->assertForbidden();
    }
}
