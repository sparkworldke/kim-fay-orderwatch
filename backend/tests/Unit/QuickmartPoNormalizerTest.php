<?php

namespace Tests\Unit;

use App\Services\OrderMatch\QuickmartPoNormalizer;
use PHPUnit\Framework\TestCase;

class QuickmartPoNormalizerTest extends TestCase
{
    private QuickmartPoNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new QuickmartPoNormalizer;
    }

    public function test_extracts_po_from_backoffice_label(): void
    {
        $text = "QUICK MART LTD.\nBackoffice PURCHASE ORDER # 074-00002048\nSupplier: KIM-FAY";
        $this->assertSame('074-00002048', $this->normalizer->extractRawPo($text));
    }

    public function test_match_keys_include_suffix_digits(): void
    {
        $keys = $this->normalizer->matchKeys('074-00002048');
        $this->assertContains('074-00002048', $keys);
        $this->assertContains('2048', $keys);
    }

    public function test_acumatica_hyphenated_format_yields_five_digit_suffix(): void
    {
        $keys = $this->normalizer->matchKeys('009-00082814');
        $this->assertContains('009-00082814', $keys);
        $this->assertContains('82814', $keys);
        $this->assertContains('2814', $keys);
    }

    public function test_matches_stored_short_po_suffix(): void
    {
        $this->assertTrue($this->normalizer->matchesStored('PO : 70924', '074-000070924'));
        $this->assertTrue($this->normalizer->matchesStored('48014', '009-00048014'));
        $this->assertTrue($this->normalizer->matchesStored('82814', '009-00082814'));
        $this->assertFalse($this->normalizer->matchesStored('82814', '074-00002048'));
    }
}