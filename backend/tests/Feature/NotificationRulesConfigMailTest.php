<?php

namespace Tests\Feature;

use App\Mail\NotificationRulesConfigMail;
use App\Models\NotificationRule;
use App\Models\User;
use App\Services\Admin\NotificationRulesConfigMailService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationRulesConfigMailTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        return User::factory()->create([
            'role' => 'Administrator',
            'is_super_admin' => true,
            'is_active' => true,
        ]);
    }

    /** @return array<string, NotificationRule> */
    private function seedRules(): array
    {
        $definitions = [
            ['R1', 'Critical Orders Pending', ['email', 'in_app']],
            ['R2', 'SLA Breach', ['email']],
            ['R3', 'Revenue at Risk', ['email']],
            ['R4', 'AI Cycle Complete', ['in_app']],
            ['R5', 'Order Match Queue Backlog', ['email']],
            ['R6', 'Order Match Duplicate PO', ['email']],
        ];

        $rules = [];
        foreach ($definitions as [$key, $label, $channels]) {
            $rules[$key] = NotificationRule::create([
                'rule_key' => $key,
                'label' => $label,
                'channels' => $channels,
                'is_enabled' => true,
            ]);
        }

        return $rules;
    }

    public function test_build_body_formats_rules_from_source_data(): void
    {
        $this->seedRules();

        NotificationRule::where('rule_key', 'R5')->update([
            'last_evaluated_at' => Carbon::parse('2026-06-30 10:04:40', 'Africa/Nairobi'),
            'last_triggered_at' => Carbon::parse('2026-06-30 10:04:40', 'Africa/Nairobi'),
        ]);
        NotificationRule::where('rule_key', 'R6')->update([
            'last_evaluated_at' => Carbon::parse('2026-06-30 10:05:02', 'Africa/Nairobi'),
            'last_triggered_at' => Carbon::parse('2026-06-30 10:05:02', 'Africa/Nairobi'),
        ]);

        $service = new NotificationRulesConfigMailService;
        $body = $service->buildBody(NotificationRule::orderBy('rule_key')->whereIn('rule_key', ['R1', 'R2', 'R3', 'R4', 'R5', 'R6'])->get());

        $this->assertStringContainsString('1. R1 - Critical Orders Pending', $body);
        $this->assertStringContainsString('   - Alert channels: Email, In-App', $body);
        $this->assertStringContainsString('   - Last evaluated: Never', $body);
        $this->assertStringContainsString('2. R2 - SLA Breach', $body);
        $this->assertStringContainsString('   - Alert channels: Email', $body);
        $this->assertStringContainsString('4. R4 - AI Cycle Complete', $body);
        $this->assertStringContainsString('   - Alert channels: In-App', $body);
        $this->assertStringContainsString('5. R5 - Order Match Queue Backlog', $body);
        $this->assertStringContainsString('   - Last evaluated: 30/06/2026, 10:04:40', $body);
        $this->assertStringContainsString('   - Last triggered: 30/06/2026, 10:04:40', $body);
        $this->assertStringContainsString('6. R6 - Order Match Duplicate PO', $body);
        $this->assertStringContainsString('   - Last evaluated: 30/06/2026, 10:05:02', $body);
        $this->assertStringContainsString('   - Last triggered: 30/06/2026, 10:05:02', $body);
    }

    public function test_send_delivers_only_to_commercial_tech_lead(): void
    {
        Mail::fake();

        $this->seedRules();

        $service = new NotificationRulesConfigMailService;
        $result = $service->send();

        $this->assertSame('commercialtechlead@kimfay.com', $result['recipient']);
        $this->assertSame(6, $result['rule_count']);

        Mail::assertSent(NotificationRulesConfigMail::class, function (NotificationRulesConfigMail $mail): bool {
            return $mail->hasTo('commercialtechlead@kimfay.com')
                && ! $mail->hasCc('commercialtechlead@kimfay.com')
                && ! $mail->hasBcc('commercialtechlead@kimfay.com');
        });

        Mail::assertSent(NotificationRulesConfigMail::class, 1);
    }

    public function test_admin_can_send_config_via_api(): void
    {
        Mail::fake();

        $this->seedRules();

        $this->actingAs($this->adminUser(), 'sanctum')
            ->postJson('/api/admin/notification-rules/send-config')
            ->assertOk()
            ->assertJsonPath('recipient', 'commercialtechlead@kimfay.com')
            ->assertJsonPath('rule_count', 6);

        Mail::assertSent(NotificationRulesConfigMail::class, function (NotificationRulesConfigMail $mail): bool {
            return $mail->hasTo('commercialtechlead@kimfay.com');
        });
    }

    public function test_non_admin_cannot_send_config_via_api(): void
    {
        Mail::fake();

        $user = User::factory()->create(['role' => 'Customer Service Manager', 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/admin/notification-rules/send-config')
            ->assertForbidden();

        Mail::assertNothingSent();
    }
}
