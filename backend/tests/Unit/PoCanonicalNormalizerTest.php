<?php

namespace Tests\Unit;

use App\Services\OrderMatch\PoCanonicalNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PoCanonicalNormalizerTest extends TestCase
{
    private PoCanonicalNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new PoCanonicalNormalizer;
    }

    #[DataProvider('acumaticaInputsProvider')]
    public function test_normalises_acumatica_style_inputs(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalisePo($raw));
    }

    public static function acumaticaInputsProvider(): array
    {
        return [
            ['P042574206', '42574206'],
            ['PO : 42566300', '42566300'],
            ['42561702', '42561702'],
            ['P042571378', '42571378'],
            ['po : 042566300', '42566300'],
            ['P-042566300', '42566300'],
            ['PO42566300', '42566300'],
            ['  P042566300 ', '42566300'],
            ['#42566300', '42566300'],
            ['@PO42566300!', '42566300'],
            ['PO : #42566300!', '42566300'],
            ['"P042566300"', '42566300'],
            ['P042566300/A', '42566300'],
            ['P0‑042566300', '42566300'],
            ['PO  -  42566300', '42566300'],
            ['P042566300-A', '42566300'],
            ['PO', null],
            ['', null],
            ['P0000001', null],
            ['!!!###@@@', null],
        ];
    }

    #[DataProvider('subjectInputsProvider')]
    public function test_extracts_po_from_naivas_subjects(string $subject, ?string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->extractPoFromSubject($subject));
    }

    public static function subjectInputsProvider(): array
    {
        return [
            [
                'Purchase order Confirmation: P042574206 - KIM-FAY EAST AFRICA LIMITED :BRANCH-KAKAMEGA',
                '42574206',
            ],
            ['PO#042574206 :: KIM-FAY @BRANCH-KAKAMEGA!!!', '42574206'],
            ['  P042574206  ', '42574206'],
            ['po:042574206-branch-kakamega', '42574206'],
            ['P042574206 — KIM-FAY', '42574206'],
            ['RE: FWD: PO : #P042574206!!!', '42574206'],
            ['Order Confirmation 2026 P042574206', '42574206'],
            ['P042574206@kimfay.co.ke', '42574206'],
            ['Invoice 3456 from KIM-FAY', null],
            ['No PO number here', null],
            ['', null],
        ];
    }
}