<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Team\OrgTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrgTreeCycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigning_to_manager_is_allowed(): void
    {
        $manager = User::factory()->create(['email' => 'partnerbrands@example.com']);
        $member = User::factory()->create([
            'email' => 'brandoperations.unilever@example.com',
            'reports_to_user_id' => null,
        ]);

        $svc = app(OrgTreeService::class);

        $this->assertFalse($svc->wouldCreateCycle($member->id, $manager->id));
    }

    public function test_reassigning_to_same_manager_is_allowed(): void
    {
        $manager = User::factory()->create();
        $member = User::factory()->create(['reports_to_user_id' => $manager->id]);

        $svc = app(OrgTreeService::class);

        $this->assertFalse($svc->wouldCreateCycle($member->id, $manager->id));
    }

    public function test_self_report_is_cycle(): void
    {
        $user = User::factory()->create();
        $svc = app(OrgTreeService::class);

        $this->assertTrue($svc->wouldCreateCycle($user->id, $user->id));
    }

    public function test_manager_under_user_is_cycle(): void
    {
        $top = User::factory()->create();
        $middle = User::factory()->create(['reports_to_user_id' => $top->id]);
        $leaf = User::factory()->create(['reports_to_user_id' => $middle->id]);

        $svc = app(OrgTreeService::class);

        // top cannot report to middle or leaf (they report up into top)
        $this->assertTrue($svc->wouldCreateCycle($top->id, $middle->id));
        $this->assertTrue($svc->wouldCreateCycle($top->id, $leaf->id));
        // middle cannot report to leaf
        $this->assertTrue($svc->wouldCreateCycle($middle->id, $leaf->id));
        // leaf reporting to top is fine
        $this->assertFalse($svc->wouldCreateCycle($leaf->id, $top->id));
        // clearing manager is fine
        $this->assertFalse($svc->wouldCreateCycle($top->id, null));
    }

    public function test_assert_valid_reports_to_allows_any_active_user(): void
    {
        $manager = User::factory()->create([
            'role' => 'Sales Consultant',
            'org_level' => 'sales',
            'is_active' => true,
        ]);
        $member = User::factory()->create([
            'role' => 'Sales Operations',
            'org_level' => 'brandsops',
            'is_active' => true,
        ]);

        $svc = app(OrgTreeService::class);
        $svc->assertValidReportsTo($member->id, $manager->id);

        $this->expectException(\InvalidArgumentException::class);
        $svc->assertValidReportsTo($member->id, $member->id);
    }

    public function test_eligible_managers_are_dynamic_and_exclude_subtree(): void
    {
        $top = User::factory()->create(['name' => 'Top', 'is_active' => true]);
        $mid = User::factory()->create(['name' => 'Mid', 'reports_to_user_id' => $top->id, 'is_active' => true]);
        $other = User::factory()->create(['name' => 'Other', 'is_active' => true]);
        $inactive = User::factory()->create(['name' => 'Inactive', 'is_active' => false]);

        $svc = app(OrgTreeService::class);
        $eligible = $svc->eligibleManagers($top->id)->pluck('id')->all();

        $this->assertContains($other->id, $eligible);
        $this->assertContains($inactive->id, $eligible, 'Inactive managers remain assignable');
        $this->assertNotContains($top->id, $eligible);
        $this->assertNotContains($mid->id, $eligible);
    }

    public function test_can_report_to_inactive_manager(): void
    {
        $inactiveManager = User::factory()->create(['is_active' => false]);
        $member = User::factory()->create(['is_active' => true]);
        $svc = app(OrgTreeService::class);

        $svc->assertValidReportsTo($member->id, $inactiveManager->id);
        $this->assertFalse($svc->wouldCreateCycle($member->id, $inactiveManager->id));
    }
}
