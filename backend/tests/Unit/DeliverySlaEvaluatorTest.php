<?php

namespace Tests\Unit;

use App\Services\Operations\DeliverySlaEvaluator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliverySlaEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private DeliverySlaEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new DeliverySlaEvaluator;
    }

    public function test_nairobi_zone_breaches_after_24_hours(): void
    {
        $result = $this->evaluator->evaluate(
            Carbon::parse('2026-07-01 08:00:00'),
            null,
            null,
            null,
            'Z005',
            'Mombasa Rd (Nairobi)',
            Carbon::parse('2026-07-02 09:30:00'),
            'Nairobi',
        );

        $this->assertTrue($result['is_metro_zone']);
        $this->assertSame('breach', $result['delivery_sla_status']);
        $this->assertSame(24, $result['sla_hours']);
    }

    public function test_mombasa_zone_is_within_24_hour_sla(): void
    {
        $result = $this->evaluator->evaluate(
            Carbon::parse('2026-07-01 08:00:00'),
            null,
            Carbon::parse('2026-07-02 06:00:00'),
            null,
            'Z012',
            'Mombasa (Coast)',
            null,
            'Coast',
        );

        $this->assertTrue($result['is_metro_zone']);
        $this->assertSame('ok', $result['delivery_sla_status']);
    }

    public function test_nairobi_region_is_metro_even_without_description_match(): void
    {
        $result = $this->evaluator->evaluate(
            Carbon::parse('2026-07-01 08:00:00'),
            null,
            null,
            null,
            'Z001',
            'Westlands',
            Carbon::parse('2026-07-02 09:30:00'),
            'Nairobi',
        );

        $this->assertTrue($result['is_metro_zone']);
        $this->assertSame('breach', $result['delivery_sla_status']);
    }

    public function test_other_regions_warn_after_48_hours_and_breach_after_72(): void
    {
        $warning = $this->evaluator->evaluate(
            Carbon::parse('2026-07-01 08:00:00'),
            null,
            null,
            null,
            'Z099',
            'Eldoret Zone',
            Carbon::parse('2026-07-03 10:00:00'),
            'Other',
        );

        $this->assertFalse($warning['is_metro_zone']);
        $this->assertSame('warning', $warning['delivery_sla_status']);

        $breach = $this->evaluator->evaluate(
            Carbon::parse('2026-07-01 08:00:00'),
            null,
            null,
            null,
            'Z099',
            'Eldoret Zone',
            Carbon::parse('2026-07-05 10:00:00'),
            'Other',
        );

        $this->assertSame('breach', $breach['delivery_sla_status']);
        $this->assertSame(72, $breach['sla_hours']);
    }
}