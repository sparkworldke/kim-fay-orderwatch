<?php

namespace App\Contracts;

use App\Services\Email\ExtractionResult;

interface PoExtractorContract
{
    /**
     * Attempt to extract a PO number from arbitrary text using AI.
     *
     * @param  string  $text   The text to analyse (subject, body, or OCR output)
     * @param  array   $hints  Optional context hints (sender, known format names, etc.)
     */
    public function extractFromText(string $text, array $hints = []): ?ExtractionResult;

    /**
     * Whether this extractor is configured and ready to use.
     */
    public function isAvailable(): bool;

    /**
     * Machine-readable name used in po_extraction_method column.
     */
    public function getName(): string;
}
