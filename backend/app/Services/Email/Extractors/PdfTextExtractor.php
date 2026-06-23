<?php

namespace App\Services\Email\Extractors;

use Throwable;

class PdfTextExtractor
{
    /**
     * Extract plain text from raw PDF bytes.
     * Uses smalot/pdfparser; returns null if extraction fails or yields nothing.
     */
    public function extract(string $pdfBytes): ?string
    {
        if (! class_exists(\Smalot\PdfParser\Parser::class)) {
            return null;
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf    = $parser->parseContent($pdfBytes);
            $text   = $pdf->getText();

            return mb_strlen(trim($text)) > 0 ? $text : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function supports(string $contentType): bool
    {
        return str_contains(strtolower($contentType), 'pdf');
    }
}
