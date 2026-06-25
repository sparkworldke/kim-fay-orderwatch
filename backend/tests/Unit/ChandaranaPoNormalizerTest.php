<?php

namespace Tests\Unit;

use App\Services\OrderMatch\ChandaranaPoNormalizer;
use PHPUnit\Framework\TestCase;

class ChandaranaPoNormalizerTest extends TestCase
{
    private ChandaranaPoNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new ChandaranaPoNormalizer;
    }

    public function test_extracts_order_number_and_date_from_label(): void
    {
        $parsed = $this->normalizer->extractFromText(
            "CHANDARANA SUPERMARKET LTD\nOrder No. & Date - 1001120070943 15-Jun-2026-SK0023",
        );

        $this->assertSame('1001120070943', $parsed['po_number']);
        $this->assertSame('2026-06-15', $parsed['po_date']);
    }

    public function test_match_keys_include_full_and_suffix_digits(): void
    {
        $keys = $this->normalizer->matchKeys('1001120070924');
        $this->assertContains('1001120070924', $keys);
        $this->assertContains('70924', $keys);
        $this->assertContains('0924', $keys);
    }

    public function test_matches_stored_acumatica_formats(): void
    {
        $this->assertTrue($this->normalizer->matchesStored('1001120070924', '1001120070924'));
        $this->assertTrue($this->normalizer->matchesStored('PO : 70924', '1001120070924'));
        $this->assertTrue($this->normalizer->matchesStored('71195', '1001120071195'));
        $this->assertFalse($this->normalizer->matchesStored('PO : 70924', '1001120070943'));
    }
}