<?php

namespace Tests\Unit;

use App\Services\Email\PartnerPoPdfContextService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PartnerPoPdfContextTest extends TestCase
{
    private PartnerPoPdfContextService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        ini_set('memory_limit', '1024M');
        $this->parser = new PartnerPoPdfContextService;
    }

    protected function tearDown(): void
    {
        unset($this->parser);
        gc_collect_cycles();
        parent::tearDown();
    }

    public function test_carrefour_single_page_pdf_exposes_po_from_fax_metadata(): void
    {
        $bytes = file_get_contents(base_path('../changes/c4-po.PDF'));
        $text = $this->parser->buildSearchableText($bytes);
        $parsed = $this->parser->parseStructured($text, 'kencarrefourorders@maf.ae');

        $this->assertStringContainsString('Subject: C4 KEV', $text);
        $this->assertSame('carrefour', $parsed['partner']);
        $this->assertSame('26000506', $parsed['canonical_po']);
        $this->assertNotNull($parsed['po_date']);
    }

    public function test_carrefour_multi_page_pdf_exposes_po_from_fax_metadata(): void
    {
        $bytes = file_get_contents(base_path('../changes/c4-multi-po.PDF'));
        $text = $this->parser->buildSearchableText($bytes);
        $parsed = $this->parser->parseStructured($text, 'kencarrefourorders@maf.ae');

        $this->assertStringContainsString('Subject: C4 KEI MALL', $text);
        $this->assertSame('26016501', $parsed['canonical_po']);
    }

    public function test_naivas_pdf_extracts_po_dates_and_totals(): void
    {
        $bytes = file_get_contents(base_path('../changes/naivas-po.pdf'));
        $text = $this->parser->buildSearchableText($bytes);
        $parsed = $this->parser->parseStructured($text, 'notification@naivas.net');

        $this->assertStringContainsString('P042568464', $text);
        $this->assertSame('naivas', $parsed['partner']);
        $this->assertSame('42568464', $parsed['canonical_po']);
        $this->assertSame('2026-06-24', $parsed['po_date']);
        $this->assertSame('2026-07-08', $parsed['delivery_date']);
        $this->assertSame('153566.66', $parsed['sub_total']);
        $this->assertSame('18021.05', $parsed['vat']);
        $this->assertSame('171587.71', $parsed['order_total']);
    }

    /** @return array<string, array{string, string, string}> */
    public static function chandaranaPdfProvider(): array
    {
        return [
            'chandarana-po-1' => ['chandarana-po-1', '1001120070943', '2026-06-15'],
            'chandarana-po-2' => ['chandarana-po-2', '1001120070924', '2026-06-15'],
            'chandarana-po-3' => ['chandarana-po-3', '1001120070938', '2026-06-15'],
            'chandarana-po-4' => ['chandarana-po-4', '1001120074291', '2026-06-24'],
        ];
    }

    #[DataProvider('chandaranaPdfProvider')]
    public function test_chandarana_pdf_extracts_order_number_and_date(string $file, string $po, string $date): void
    {
        $bytes = file_get_contents(base_path('../changes/'.$file.'.pdf'));
        $text = $this->parser->buildSearchableText($bytes);
        unset($bytes);
        $parsed = $this->parser->parseStructured($text, 'orders@chandaranasupermarkets.co.ke');
        unset($text);

        $this->assertSame('chandarana', $parsed['partner'], $file);
        $this->assertSame($po, $parsed['po_number'], $file);
        $this->assertSame($po, $parsed['canonical_po'], $file);
        $this->assertSame($date, $parsed['po_date'], $file);
    }

    public function test_quickmart_scanned_pdfs_embed_jpeg_for_ocr(): void
    {
        foreach (['qm-po-1', 'qm-po-2'] as $file) {
            $bytes = file_get_contents(base_path('../changes/'.$file.'.pdf'));
            $text = $this->parser->buildSearchableText($bytes);
            $jpeg = $this->parser->extractFirstEmbeddedJpeg($bytes);

            $this->assertLessThan(200, strlen($text ?? ''), $file.' should have minimal text layer');
            $this->assertNotNull($jpeg, $file.' should embed a JPEG for OCR');
            $this->assertGreaterThan(100000, strlen($jpeg), $file.' JPEG should be substantial');
        }
    }
}