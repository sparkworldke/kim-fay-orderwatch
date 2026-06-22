<?php

namespace Tests\Unit;

use App\Models\EmailImportConfig;
use App\Services\Email\PoNumberExtractorService;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests — no database, no Laravel app container.
 * The service gracefully handles DB unavailability via try-catch.
 */
class PoNumberExtractorTest extends TestCase
{
    private PoNumberExtractorService $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new PoNumberExtractorService([]);
    }

    // -------------------------------------------------------------------------
    // Naivas
    // -------------------------------------------------------------------------

    public function test_extracts_naivas_po_from_subject(): void
    {
        $result = $this->extractor->extractFromSubject(
            'notification@naivas.net',
            'Purchase order Confirmation: P042539739 - KIM-FAY EAST AFRICA LIMITED :BRANCH-KASARANI',
        );
        $this->assertNotNull($result);
        $this->assertEquals('P042539739', $result->poNumber);
        $this->assertEquals('subject_pattern', $result->method);
        $this->assertEquals(100, $result->confidence);
    }

    public function test_extracts_naivas_po_second_branch(): void
    {
        $result = $this->extractor->extractFromSubject(
            'notification@naivas.net',
            'Purchase order Confirmation: P042540467 - KIM-FAY EAST AFRICA LIMITED :BRANCH-SOUTH',
        );
        $this->assertNotNull($result);
        $this->assertEquals('P042540467', $result->poNumber);
    }

    // -------------------------------------------------------------------------
    // Carrefour
    // -------------------------------------------------------------------------

    public function test_extracts_carrefour_po_gcm(): void
    {
        $result = $this->extractor->extractFromSubject(
            'KENCarrefourOrders@maf.ae',
            'C4 GCM XGCM     26020742',
        );
        $this->assertNotNull($result);
        $this->assertEquals('26020742', $result->poNumber);
        $this->assertEquals('subject_pattern', $result->method);
    }

    public function test_extracts_carrefour_po_iru(): void
    {
        $result = $this->extractor->extractFromSubject(
            'KENCarrefourOrders@maf.ae',
            'C4 IRU MALL     26025705',
        );
        $this->assertNotNull($result);
        $this->assertEquals('26025705', $result->poNumber);
    }

    // -------------------------------------------------------------------------
    // QuickMart (PDF text)
    // -------------------------------------------------------------------------

    public function test_extracts_quickmart_po_from_pdf_joska(): void
    {
        $pdfText = "QUICK MART LTD.\n67 - QUICK MART JOSKA\nBackoffice PURCHASE ORDER # 067-00027749\nSupplier: K/030 - KIM-FAY EAST AFRICA LIMITED";
        $result = $this->extractor->extractFromPdfText('orders@joska.quickmart.co.ke', $pdfText);
        $this->assertNotNull($result);
        $this->assertEquals('067-00027749', $result->poNumber);
        $this->assertEquals('pdf_pattern', $result->method);
    }

    public function test_extracts_quickmart_po_from_pdf_thome(): void
    {
        $pdfText = "QUICK MART LTD.\n62 - QUICK MART THOME\nBackoffice PURCHASE ORDER # 062-00065075\nSupplier: K/030 - KIM-FAY EAST AFRICA LIMITED";
        $result = $this->extractor->extractFromPdfText('procurement@thome.quickmart.co.ke', $pdfText);
        $this->assertNotNull($result);
        $this->assertEquals('062-00065075', $result->poNumber);
    }

    // -------------------------------------------------------------------------
    // Chandarana (PDF text)
    // -------------------------------------------------------------------------

    public function test_extracts_chandarana_po_from_pdf(): void
    {
        $pdfText = "CHANDARANA SUPERMARKET LTD\nCornerstone Place\nOrder No. & Date - 1001120070938  15-Jun-2026\nType - Outright - Standard";
        $result = $this->extractor->extractFromPdfText(
            'procurement@chandaranasupermarkets.co.ke',
            $pdfText,
        );
        $this->assertNotNull($result);
        $this->assertEquals('1001120070938', $result->poNumber);
        $this->assertEquals('pdf_pattern', $result->method);
    }

    public function test_extracts_chandarana_second_order(): void
    {
        $pdfText = "CHANDARANA SUPERMARKET LTD\nOrder No. & Date - 1001120070924  15-Jun-2026";
        $result = $this->extractor->extractFromPdfText(
            'orders@chandaranasupermarkets.co.ke',
            $pdfText,
        );
        $this->assertNotNull($result);
        $this->assertEquals('1001120070924', $result->poNumber);
    }

    // -------------------------------------------------------------------------
    // Generic / unknown senders
    // -------------------------------------------------------------------------

    public function test_generic_naivas_fallback_unknown_sender(): void
    {
        $result = $this->extractor->extractFromSubject(
            'unknown@example.com',
            'Purchase order Confirmation: P042539999 - Vendor confirmation',
        );
        $this->assertNotNull($result);
        $this->assertEquals('P042539999', $result->poNumber);
    }

    public function test_returns_null_when_no_match(): void
    {
        $result = $this->extractor->extractFromSubject(
            'spam@unknown.com',
            'Hello, please see the attached document for details.',
        );
        $this->assertNull($result);
    }

    public function test_po_normalised_to_uppercase(): void
    {
        // Even if a pattern returns lowercase, normalised PO is uppercase
        $result = $this->extractor->extractFromSubject(
            'notification@naivas.net',
            'Purchase order Confirmation: p042539739 - KIM-FAY',
        );
        // Pattern is case-insensitive, result should be upper
        if ($result) {
            $this->assertEquals(strtoupper($result->poNumber), $result->poNumber);
        }
    }

    // -------------------------------------------------------------------------
    // Sender wildcard matching (EmailImportConfig static helper)
    // -------------------------------------------------------------------------

    public function test_wildcard_sender_matches_quickmart_subdomain(): void
    {
        $this->assertTrue(
            EmailImportConfig::senderMatchesPattern('orders@joska.quickmart.co.ke', '*@quickmart.co.ke', true)
        );
        $this->assertTrue(
            EmailImportConfig::senderMatchesPattern('procurement@thome.quickmart.co.ke', '*@quickmart.co.ke', true)
        );
    }

    public function test_wildcard_sender_does_not_match_different_domain(): void
    {
        $this->assertFalse(
            EmailImportConfig::senderMatchesPattern('orders@naivas.net', '*@quickmart.co.ke', true)
        );
    }

    public function test_exact_sender_matches(): void
    {
        $this->assertTrue(
            EmailImportConfig::senderMatchesPattern('notification@naivas.net', 'notification@naivas.net', false)
        );
    }

    public function test_exact_sender_is_case_insensitive(): void
    {
        $this->assertTrue(
            EmailImportConfig::senderMatchesPattern('KENCarrefourOrders@maf.ae', 'kencarrefourorders@maf.ae', false)
        );
    }

    public function test_chandarana_wildcard_matches(): void
    {
        $this->assertTrue(
            EmailImportConfig::senderMatchesPattern(
                'procurement@chandaranasupermarkets.co.ke',
                '*@chandaranasupermarkets.co.ke',
                true,
            )
        );
    }
}
