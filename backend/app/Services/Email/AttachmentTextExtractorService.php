<?php

namespace App\Services\Email;

use App\Models\EmailAttachment;
use App\Services\Email\Extractors\ExcelTextExtractor;
use App\Services\Email\Extractors\ImageTextExtractor;
use App\Services\Email\Extractors\PdfTextExtractor;

class AttachmentTextExtractorService
{
    private ?PdfTextExtractor $pdf = null;

    public function __construct(
        private readonly ExcelTextExtractor $excel,
        private readonly ImageTextExtractor $image,
    ) {
    }

    private function pdf(): PdfTextExtractor
    {
        return $this->pdf ??= new PdfTextExtractor(
            partnerPdf: new PartnerPoPdfContextService,
            imageText: $this->image,
        );
    }

    /**
     * Extract text from raw attachment bytes based on content type.
     * Updates the EmailAttachment record in place.
     * Returns extracted text or null.
     */
    public function extract(EmailAttachment $attachment, string $bytes): ?string
    {
        $mime = strtolower((string) $attachment->content_type);

        [$text, $method] = match (true) {
            $this->pdf()->supports($mime)   => [$this->pdf()->extract($bytes),                          'pdf_parser'],
            $this->excel->supports($mime) => [$this->excel->extract($bytes, $mime),                 'excel_parser'],
            $this->image->supports($mime) => [$this->image->extract($bytes, $mime),                 'ai_vision'],
            default                       => [null,                                                  'unsupported'],
        };

        if ($text !== null && mb_strlen(trim($text)) > 0) {
            $attachment->update([
                'extracted_text'       => mb_substr($text, 0, 100000),
                'extraction_status'    => 'parsed',
                'extraction_method'    => $method,
                'extraction_confidence'=> $this->confidenceFor($method),
                'extraction_error'     => null,
            ]);

            return $text;
        }

        $attachment->update([
            'extraction_status' => $method === 'unsupported' ? 'unsupported' : 'failed',
            'extraction_error'  => $method === 'unsupported' ? 'unsupported_type' : 'no_text_extracted',
        ]);

        return null;
    }

    public function isExtractable(string $contentType): bool
    {
        $mime = strtolower($contentType);

        return $this->pdf()->supports($mime)
            || $this->excel->supports($mime)
            || $this->image->supports($mime);
    }

    private function confidenceFor(string $method): int
    {
        return match ($method) {
            'pdf_parser'   => 90,
            'excel_parser' => 95,
            'ai_vision'    => 75,
            default        => 50,
        };
    }
}
