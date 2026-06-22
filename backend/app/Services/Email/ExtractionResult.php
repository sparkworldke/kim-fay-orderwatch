<?php

namespace App\Services\Email;

readonly class ExtractionResult
{
    public function __construct(
        public string $poNumber,
        public string $method,       // subject_pattern | body_pattern | pdf_pattern | ai_claude | ai_openai | local_ocr | manual
        public int    $confidence,   // 0-100 (100 = exact pattern match, <100 = AI estimate)
        public ?string $rawMatch = null,
    ) {
    }

    public function isHighConfidence(): bool
    {
        return $this->confidence >= 80;
    }
}
