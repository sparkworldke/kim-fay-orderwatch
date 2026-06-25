<?php

namespace Tests\Unit;

use App\Services\AI\AiPromptShortcutResolver;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AiPromptShortcutResolverTest extends TestCase
{
    public function test_resolves_mtd_and_orders_tags(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-23 10:00:00', 'Africa/Nairobi'));

        $result = app(AiPromptShortcutResolver::class)->resolve(
            '@mtd @orders summary for leadership',
            'Africa/Nairobi',
        );

        $this->assertContains('mtd', $result['tags']);
        $this->assertContains('orders', $result['tags']);
        $this->assertContains('orders', $result['domain_hints']);
        $this->assertSame('MTD', $result['period_label']);
        $this->assertNotNull($result['date_from']);
        $this->assertNotNull($result['date_to']);
        $this->assertNotEmpty($result['context_lines']);

        Carbon::setTestNow();
    }

    public function test_slash_prefix_is_supported(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-23 10:00:00', 'Africa/Nairobi'));

        $result = app(AiPromptShortcutResolver::class)->resolve('/yesterday /completed rate');

        $this->assertContains('yesterday', $result['tags']);
        $this->assertContains('completed', $result['tags']);
        $this->assertSame('Yesterday', $result['period_label']);

        Carbon::setTestNow();
    }
}