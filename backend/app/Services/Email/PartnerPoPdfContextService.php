<?php

namespace App\Services\Email;

use App\Services\OrderMatch\CarrefourPoNormalizer;
use App\Services\OrderMatch\ChandaranaPoNormalizer;
use App\Services\OrderMatch\PoCanonicalNormalizer;
use App\Services\OrderMatch\QuickmartPoNormalizer;
use Smalot\PdfParser\XObject\Image;
use Throwable;

/**
 * Enriches partner PO PDFs (Naivas text layer, Carrefour fax metadata) for extraction and matching.
 */
class PartnerPoPdfContextService
{
    public function __construct(
        private readonly PoCanonicalNormalizer $canonical = new PoCanonicalNormalizer,
        private readonly CarrefourPoNormalizer $carrefour = new CarrefourPoNormalizer,
        private readonly QuickmartPoNormalizer $quickmart = new QuickmartPoNormalizer,
        private readonly ChandaranaPoNormalizer $chandarana = new ChandaranaPoNormalizer,
    ) {
    }

    /**
     * Build searchable text from raw PDF bytes (body text + fax metadata block).
     */
    public function buildSearchableText(string $pdfBytes): ?string
    {
        if (! class_exists(\Smalot\PdfParser\Parser::class)) {
            return null;
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseContent($pdfBytes);
            $body = trim($pdf->getText());
            $meta = $this->formatMetadataBlock($pdf->getDetails());

            $parts = array_filter([$meta, $body !== '' ? $body : null]);

            return $parts === [] ? null : implode("\n\n", $parts);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *   partner:?string,
     *   po_number:?string,
     *   canonical_po:?string,
     *   po_date:?string,
     *   delivery_date:?string,
     *   sub_total:?string,
     *   vat:?string,
     *   order_total:?string,
     *   source:string
     * }
     */
    public function parseStructured(string $searchableText, ?string $senderEmail = null): array
    {
        $sender = strtolower(trim((string) $senderEmail));
        $isCarrefour = str_contains($sender, 'carrefour') || str_contains($sender, 'maf.ae');
        $isNaivas = str_contains($sender, 'naivas.net');

        if ($isCarrefour || $this->extractMetadataSubject($searchableText) !== null) {
            return $this->parseCarrefour($searchableText);
        }

        if ($isNaivas || preg_match('/\bNAIVAS\b/i', $searchableText)) {
            return $this->parseNaivas($searchableText);
        }

        $isQuickmart = str_contains($sender, 'quickmart')
            || preg_match('/\bQUICK\s*MART\b/i', $searchableText);
        if ($isQuickmart) {
            return $this->parseQuickmart($searchableText);
        }

        $isChandarana = str_contains($sender, 'chandarana')
            || preg_match('/\bCHANDARANA\b/i', $searchableText);
        if ($isChandarana) {
            return $this->parseChandarana($searchableText);
        }

        return [
            'partner' => null,
            'po_number' => null,
            'canonical_po' => null,
            'po_date' => null,
            'delivery_date' => null,
            'sub_total' => null,
            'vat' => null,
            'order_total' => null,
            'source' => 'unknown',
        ];
    }

    /** @param  array<string, mixed>  $details */
    private function formatMetadataBlock(array $details): ?string
    {
        if ($details === []) {
            return null;
        }

        $lines = ['[PDF-META]'];
        foreach (['Subject', 'Author', 'Title', 'CreationDate', 'ModDate', 'Producer'] as $key) {
            if (! empty($details[$key]) && is_string($details[$key])) {
                $lines[] = "{$key}: {$details[$key]}";
            }
        }
        $lines[] = '[/PDF-META]';

        return count($lines) > 2 ? implode("\n", $lines) : null;
    }

    /**
     * @return array{
     *   partner:string,
     *   po_number:?string,
     *   canonical_po:?string,
     *   po_date:?string,
     *   delivery_date:?string,
     *   sub_total:?string,
     *   vat:?string,
     *   order_total:?string,
     *   source:string
     * }
     */
    private function parseCarrefour(string $text): array
    {
        $subject = $this->extractMetadataSubject($text);
        $canonical = $subject !== null ? $this->carrefour->extractCarrefourPo($subject) : null;
        $creationDate = null;

        if (preg_match('/CreationDate:\s*([^\n]+)/i', $text, $match)) {
            $creationDate = trim($match[1]);
        }

        return [
            'partner' => 'carrefour',
            'po_number' => $canonical,
            'canonical_po' => $canonical,
            'po_date' => $creationDate,
            'delivery_date' => null,
            'sub_total' => null,
            'vat' => null,
            'order_total' => null,
            'source' => 'carrefour_pdf_meta',
        ];
    }

    /**
     * @return array{
     *   partner:string,
     *   po_number:?string,
     *   canonical_po:?string,
     *   po_date:?string,
     *   delivery_date:?string,
     *   sub_total:?string,
     *   vat:?string,
     *   order_total:?string,
     *   source:string
     * }
     */
    /**
     * Scanned PDFs (QuickMart) often embed a JPEG per page with no text layer.
     */
    public function extractFirstEmbeddedJpeg(string $pdfBytes): ?string
    {
        if (! class_exists(\Smalot\PdfParser\Parser::class)) {
            return null;
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseContent($pdfBytes);
            foreach ($pdf->getPages() as $page) {
                foreach ($page->getXObjects() as $xObject) {
                    if (! $xObject instanceof Image) {
                        continue;
                    }

                    $content = $xObject->getContent();
                    if (! is_string($content) || strlen($content) < 4) {
                        continue;
                    }

                    if (str_starts_with($content, "\xFF\xD8\xFF")) {
                        return $content;
                    }
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @return array{
     *   partner:string,
     *   po_number:?string,
     *   canonical_po:?string,
     *   po_date:?string,
     *   delivery_date:?string,
     *   sub_total:?string,
     *   vat:?string,
     *   order_total:?string,
     *   source:string
     * }
     */
    private function parseQuickmart(string $text): array
    {
        $rawPo = $this->quickmart->extractRawPo($text);
        $keys = $rawPo !== null ? $this->quickmart->matchKeys($rawPo) : [];

        return [
            'partner'        => 'quickmart',
            'po_number'      => $rawPo,
            'canonical_po'   => $keys[0] ?? $rawPo,
            'po_date'        => $this->captureLooseDates($text)[0] ?? null,
            'delivery_date'  => null,
            'sub_total'      => null,
            'vat'            => null,
            'order_total'    => null,
            'source'         => 'quickmart_pdf_text',
        ];
    }

    /**
     * @return array{
     *   partner:string,
     *   po_number:?string,
     *   canonical_po:?string,
     *   po_date:?string,
     *   delivery_date:?string,
     *   sub_total:?string,
     *   vat:?string,
     *   order_total:?string,
     *   source:string
     * }
     */
    private function parseChandarana(string $text): array
    {
        $parsed = $this->chandarana->extractFromText($text);
        $rawPo = $parsed['po_number'] ?? null;
        $keys = $rawPo !== null ? $this->chandarana->matchKeys($rawPo) : [];

        return [
            'partner'        => 'chandarana',
            'po_number'      => $rawPo,
            'canonical_po'   => $keys[0] ?? $rawPo,
            'po_date'        => $parsed['po_date'] ?? null,
            'delivery_date'  => null,
            'sub_total'      => null,
            'vat'            => null,
            'order_total'    => null,
            'source'         => 'chandarana_pdf_text',
        ];
    }

    private function parseNaivas(string $text): array
    {
        $rawPo = null;
        if (preg_match('/\*(P0\d{7,10})\*/i', $text, $match)) {
            $rawPo = strtoupper($match[1]);
        } elseif (preg_match('/\b(P0\d{7,10})(?:-\d+)?\b/i', $text, $match)) {
            $rawPo = strtoupper($match[1]);
        }

        $canonical = $rawPo !== null ? $this->canonical->normalisePo($rawPo) : null;

        $looseDates = $this->captureLooseDates($text);
        $poDate = $looseDates[0] ?? null;
        $deliveryDate = $looseDates[1] ?? null;

        return [
            'partner' => 'naivas',
            'po_number' => $rawPo,
            'canonical_po' => $canonical,
            'po_date' => $poDate,
            'delivery_date' => $deliveryDate,
            'sub_total' => $this->captureMoney($text, 'Sub\s+total'),
            'vat' => $this->captureMoney($text, 'VAT'),
            'order_total' => $this->captureMoney($text, 'Order\s+total'),
            'source' => 'naivas_pdf_text',
        ];
    }

    private function extractMetadataSubject(string $text): ?string
    {
        if (preg_match('/Subject:\s*(.+)/i', $text, $match)) {
            return trim($match[1]);
        }

        return null;
    }

    private function captureMoney(string $text, string $label): ?string
    {
        if (preg_match('/'.$label.'\s*([0-9][0-9,]*(?:\.\d{1,4})?)/i', $text, $match)) {
            return number_format((float) str_replace(',', '', $match[1]), 2, '.', '');
        }

        return null;
    }

    private function captureDateAfterLabel(string $text, string $label): ?string
    {
        if (preg_match('/'.$label.':\s*([^\n]+)/i', $text, $match)) {
            return $this->normaliseHumanDate(trim($match[1]));
        }

        return null;
    }

    /** @return list<string> */
    private function captureLooseDates(string $text): array
    {
        $dates = [];

        if (preg_match_all('/\b(\d{1,2}-[A-Za-z]{3}-\d{4})\b/', $text, $dashMatches)) {
            foreach ($dashMatches[1] as $candidate) {
                $normalised = $this->normaliseHumanDate($candidate);
                if ($normalised !== null && ! in_array($normalised, $dates, true)) {
                    $dates[] = $normalised;
                }
            }
        }

        if (preg_match_all('/\b(\d{1,2}\s+[A-Za-z]+,?\s+\d{4})\b/', $text, $matches)) {
            foreach ($matches[1] as $candidate) {
                $normalised = $this->normaliseHumanDate($candidate);
                if ($normalised !== null && ! in_array($normalised, $dates, true)) {
                    $dates[] = $normalised;
                }
            }
        }

        return $dates;
    }

    private function normaliseHumanDate(string $value): ?string
    {
        $value = preg_replace('/\bKEN\b/i', '', $value) ?? $value;
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp ? date('Y-m-d', $timestamp) : $value;
    }
}