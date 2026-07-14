<?php

namespace Tests\Feature;

use App\Models\AcumaticaFillRateSnapshot;
use App\Services\Admin\FillRateCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FillRateSegmentSplitTest extends TestCase
{
    private FillRateCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new FillRateCalculator();
    }

    /**
     * Build an in-memory snapshot (not persisted) with the given attributes.
     */
    private function makeSnapshot(string $customerId, ?float $fillRatePct, string $status, float $ordered, float $shipped, float $revenueNotShipped): AcumaticaFillRateSnapshot
    {
        return new AcumaticaFillRateSnapshot([
            'customer_acumatica_id' => $customerId,
            'fill_rate_pct'         => $fillRatePct,
            'fill_rate_status'      => $status,
            'total_ordered_qty'     => $ordered,
            'total_shipped_qty'     => $shipped,
            'revenue_not_shipped'   => $revenueNotShipped,
        ]);
    }

    #[DataProvider('customerClassProvider')]
    public function test_segment_for_customer_class_classifies_correctly(?string $class, string $expected): void
    {
        $this->assertSame($expected, $this->calculator->segmentForCustomerClass($class));
    }

    public static function customerClassProvider(): array
    {
        return [
            // KP cases — any string starting with "KP" (case-insensitive)
            'uppercase KP'        => ['KP', FillRateCalculator::SEGMENT_KP],
            'lowercase kp'        => ['kp', FillRateCalculator::SEGMENT_KP],
            'mixed case Kp'       => ['Kp', FillRateCalculator::SEGMENT_KP],
            'kp-whitespace'       => ['  kp  ', FillRateCalculator::SEGMENT_KP],
            'kp with suffix'      => ['KP-RETAIL', FillRateCalculator::SEGMENT_KP],
            'kp lowercase suffix' => ['kp modern', FillRateCalculator::SEGMENT_KP],

            // CS cases — everything that does NOT start with KP
            'generic consumer'    => ['CONSUMER', FillRateCalculator::SEGMENT_CS],
            'modern trade'        => ['MODERN TRADE', FillRateCalculator::SEGMENT_CS],
            'whitespace only'     => ['   ', FillRateCalculator::SEGMENT_CS],
            'empty string'        => ['', FillRateCalculator::SEGMENT_CS],
            'null'                => [null, FillRateCalculator::SEGMENT_CS],
            'k-prefix not kp'     => ['KIOSK', FillRateCalculator::SEGMENT_CS],
            'p-prefix'            => ['PARTNER', FillRateCalculator::SEGMENT_CS],
            'contains kp middle'  => ['CONSUMER KP', FillRateCalculator::SEGMENT_CS],
        ];
    }

    public function test_segment_for_customer_class_never_returns_unclassified(): void
    {
        // Exhaustive edge cases — none should produce a value other than KP or CS.
        $inputs = [null, '', '   ', '0', 'A', 'KP', 'CS', 'kp', 'cs', 'unknown class'];

        foreach ($inputs as $input) {
            $result = $this->calculator->segmentForCustomerClass($input);
            $this->assertContains($result, [FillRateCalculator::SEGMENT_KP, FillRateCalculator::SEGMENT_CS]);
        }
    }

    public function test_segment_split_assigns_every_snapshot_to_kp_or_cs(): void
    {
        $kpSnapshot = $this->makeSnapshot('CUST-KP-01', 96.0, 'healthy', 100, 96, 100);
        $csSnapshot = $this->makeSnapshot('CUST-CS-01', 70.0, 'critical', 100, 70, 300);
        $unclassifiedSnapshot = $this->makeSnapshot('CUST-NULL', 90.0, 'at_risk', 50, 45, 50);

        $snapshots = [$kpSnapshot, $csSnapshot, $unclassifiedSnapshot];

        $customerClasses = [
            'CUST-KP-01'   => 'KP Retail',
            'CUST-CS-01'   => 'CONSUMER',
            'CUST-NULL'    => null,
        ];

        $split = $this->calculator->segmentSplit($snapshots, $customerClasses);

        // Both buckets must exist.
        $this->assertArrayHasKey('KP', $split);
        $this->assertArrayHasKey('CS', $split);

        // Every snapshot counted — KP got 1, CS got 2 (CS-01 + the null-class one).
        $this->assertSame(1, $split['KP']['order_count']);
        $this->assertSame(2, $split['CS']['order_count']);

        // No third bucket — keys are exactly KP and CS.
        $this->assertSame(['KP', 'CS'], array_keys($split));
    }

    public function test_segment_split_calculates_fill_rate_and_status_correctly(): void
    {
        $kpSnapshot = $this->makeSnapshot('CUST-KP-01', 96.0, 'healthy', 100, 96, 100);
        $csSnapshot = $this->makeSnapshot('CUST-CS-01', 70.0, 'critical', 100, 70, 300);

        $split = $this->calculator->segmentSplit(
            [$kpSnapshot, $csSnapshot],
            ['CUST-KP-01' => 'KP', 'CUST-CS-01' => 'CONSUMER'],
        );

        // KP: 96/100 shipped => 96% => healthy
        $this->assertSame(96.0, $split['KP']['fill_rate_pct']);
        $this->assertSame('healthy', $split['KP']['status']);
        $this->assertSame(1, $split['KP']['healthy_count']);
        $this->assertSame(0, $split['KP']['critical_count']);

        // CS: 70/100 shipped => 70% => critical
        $this->assertSame(70.0, $split['CS']['fill_rate_pct']);
        $this->assertSame('critical', $split['CS']['status']);
        $this->assertSame(0, $split['CS']['healthy_count']);
        $this->assertSame(1, $split['CS']['critical_count']);
    }

    public function test_segment_split_handles_all_na_snapshots(): void
    {
        $snapshot = $this->makeSnapshot('CUST-KP-01', null, 'na', 0, 0, 0);

        $split = $this->calculator->segmentSplit([$snapshot], ['CUST-KP-01' => 'KP']);

        $this->assertNull($split['KP']['fill_rate_pct']);
        $this->assertSame('na', $split['KP']['status']);
        // Order is still counted even though fill rate is N/A.
        $this->assertSame(1, $split['KP']['order_count']);
    }

    public function test_segment_split_sums_to_total_population(): void
    {
        // Build a mix of KP and CS snapshots — combined counts must equal total.
        $snapshots = [];
        $customerClasses = [];

        $classes = ['KP', 'kp', 'CONSUMER', '', 'KP-RETAIL', 'MODERN TRADE', null, 'kp partner'];

        foreach ($classes as $i => $class) {
            $id = "CUST-{$i}";
            $snapshots[] = $this->makeSnapshot($id, 90.0, 'at_risk', 10, 9, 10);
            $customerClasses[$id] = $class;
        }

        $split = $this->calculator->segmentSplit($snapshots, $customerClasses);

        $totalAssigned = $split['KP']['order_count'] + $split['CS']['order_count'];

        // Every one of the 8 snapshots must be in exactly one bucket.
        $this->assertSame(count($classes), $totalAssigned);

        // KP should get the 4 that start with "kp"/"KP" (indices 0, 1, 4, 7).
        $this->assertSame(4, $split['KP']['order_count']);
        $this->assertSame(4, $split['CS']['order_count']);
    }

    public function test_segment_label_uses_kimfay_professional_naming(): void
    {
        $this->assertSame('KP (Kimfay Professional)', $this->calculator->segmentLabel(FillRateCalculator::SEGMENT_KP));
        $this->assertSame('CS (Consumer Sales)', $this->calculator->segmentLabel(FillRateCalculator::SEGMENT_CS));
    }

    public function test_segment_split_case_insensitive_kp_prefix(): void
    {
        // "kp" lowercase should route to KP segment.
        $snapshot = $this->makeSnapshot('CUST-01', 95.0, 'healthy', 100, 95, 50);

        $split = $this->calculator->segmentSplit([$snapshot], ['CUST-01' => 'kp']);

        $this->assertSame(1, $split['KP']['order_count']);
        $this->assertSame(0, $split['CS']['order_count']);
    }
}
