<?php

namespace App\Services\Email\Extractors;

use App\Services\Email\PartnerPoPdfContextService;
use Throwable;

class PdfTextExtractor
{
    public function __construct(
        private readonly PartnerPoPdfContextService $partnerPdf = new PartnerPoPdfContextService,
        private readonly ?ImageTextExtractor $imageText = null,
    ) {
    }

    /**
     * Extract plain text from raw PDF bytes.
     * Includes Carrefour fax metadata when the body text layer is empty.
     * Scanned PDFs (QuickMart) fall back to embedded JPEG + AI vision when configured.
     */
    public function extract(string $pdfBytes): ?string
    {
        $text = $this->partnerPdf->buildSearchableText($pdfBytes);
        if ($text !== null && trim($text) !== '') {
            return $text;
        }

        $jpeg = $this->partnerPdf->extractFirstEmbeddedJpeg($pdfBytes);
        if ($jpeg === null || $this->imageText === null) {
            return $text;
        }

        $ocr = $this->imageText->extract($jpeg, 'image/jpeg');

        return $ocr !== null && trim($ocr) !== '' ? trim($ocr) : $text;
    }

    public function supports(string $contentType): bool
    {
        return str_contains(strtolower($contentType), 'pdf');
    }
}
