<?php

namespace Tests\Unit;

use App\Services\OrderMatch\CarrefourPoNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CarrefourPoNormalizerTest extends TestCase
{
    private CarrefourPoNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new CarrefourPoNormalizer;
    }

    #[DataProvider('acumaticaInputsProvider')]
    public function test_extracts_carrefour_po_from_acumatica_inputs(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->extractCarrefourPo($raw));
    }

    public static function acumaticaInputsProvider(): array
    {
        return [
            ['C4 KEV 2600050', '2600050'],
            ['C4 KEI MALL 26016501', '26016501'],
            ['C4-KEV-2600050', '2600050'],
            ['C4-KEI-MALL-26016501', '26016501'],
            ['C4 KEV-2600050', '2600050'],
            ['C4  KEV  2600050', '2600050'],
            ['C4 #KEV@ 2600050!', '2600050'],
            ['c4 kev 2600050', '2600050'],
            ['C4 Kev 2600050', '2600050'],
            ['C4 2600050', '2600050'],
            ['C4 KEV 02600050', '2600050'],
            ['C4 THIKA 2600050', '2600050'],
            ['C4 KAREN 26016501', '26016501'],
            ['C4 NEWBRANCH 2600050', '2600050'],
            ['C4 GCM XGCM 26021220', '26021220'],
            ['C4 KEV', null],
            ['C4', null],
            ['C4 KEV 1234', null],
            ['C4 KEV ABC', null],
            ['', null],
        ];
    }

    #[DataProvider('subjectInputsProvider')]
    public function test_extracts_carrefour_po_from_subjects(string $subject, ?string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->extractCarrefourPoFromSubject($subject));
    }

    public static function subjectInputsProvider(): array
    {
        return [
            ['C4 KEV 2600050', '2600050'],
            ['C4 KEI MALL 26016501', '26016501'],
            ['  C4 KEV 2600050  ', '2600050'],
            ['C4 KEV 2600050 - KIM-FAY EAST AFRICA', '2600050'],
            ['RE: C4 KEI MALL 26016501', '26016501'],
            ['FWD: C4 KEV 2600050', '2600050'],
            ['RE: FWD: C4 GCM XGCM 26021220', '26021220'],
            ['C4 GCM XGCM     26021220', '26021220'],
            ['', null],
        ];
    }

    public function test_naive_digit_extraction_would_be_wrong_for_c4_prefix(): void
    {
        $canonical = new \App\Services\OrderMatch\PoCanonicalNormalizer;

        $this->assertSame('42600050', $canonical->normalisePo('C4 KEV 2600050'));
        $this->assertSame('2600050', $this->normalizer->extractCarrefourPo('C4 KEV 2600050'));
    }

    public function test_extract_po_for_carrefour_reports_method(): void
    {
        [$canonical, $method] = $this->normalizer->extractPoForCarrefour('C4 KEV 2600050');
        $this->assertSame('2600050', $canonical);
        $this->assertSame('carrefour', $method);

        [$canonical, $method] = $this->normalizer->extractPoForCarrefour('C4 KEV');
        $this->assertNull($canonical);
        $this->assertSame('failed', $method);
    }

    public function test_is_carrefour_format(): void
    {
        $this->assertTrue($this->normalizer->isCarrefourFormat('C4 KEV 2600050'));
        $this->assertTrue($this->normalizer->isCarrefourFormat('C4'));
        $this->assertFalse($this->normalizer->isCarrefourFormat('PO 2600050'));
    }
}