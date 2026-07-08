<?php

namespace App\Services\Email;

use App\Contracts\PoExtractorContract;
use App\Models\EmailImportConfig;
use App\Services\OrderMatch\CarrefourPoNormalizer;
use App\Services\OrderMatch\ChandaranaPoNormalizer;
use App\Services\OrderMatch\PoCanonicalNormalizer;
use App\Services\OrderMatch\QuickmartPoNormalizer;


/**
 * Extracts PO numbers from email subjects, body text, and OCR'd PDF content.
 *
 * Strategy (in order of precedence):
 *  1. Per-sender patterns stored in email_import_configs
 *  2. Built-in patterns for known supermarket partners
 *  3. Generic fallback patterns
 *  4. AI extractor (Claude / OpenAI) when all patterns fail
 */
class PoNumberExtractorService
{
    /**
     * Built-in rules keyed by a recognisable group name.
     * Each entry declares which sender addresses/domains trigger the rule
     * and the ordered list of regex patterns to try.
     */
    private const BUILT_IN_RULES = [
        'naivas' => [
            'emails'   => ['notification@naivas.net'],
            'patterns' => [
                // "Purchase order Confirmation: P042539739 - KIM-FAY..."
                '/Purchase order Confirmation:\s*(P\d{6,12})/i',
                // Attachment filename starts with P042562296...
                '/^(P0\d{7,10})/i',
                // Subject or body token
                '/\bP0\d{7,10}\b/',
            ],
        ],
        'carrefour' => [
            'emails'   => ['kencarrefourorders@maf.ae'],
            'patterns' => [
                // "C4 GCM XGCM     26021220" — digits = Acumatica CustomerOrder
                '/\bC4\b\s+\S+\s+\S+\s+(\d{7,9})\b/i',
            ],
        ],
        'quickmart' => [
            'domains'  => ['quickmart.co.ke'],
            'patterns' => [
                // PDF: "Backoffice PURCHASE ORDER # 067-00027749"
                '/PURCHASE ORDER\s*#\s*(\d{3}-\d{5,10})/i',
                // Generic QuickMart format NNN-NNNNNNNN
                '/\b(\d{3}-\d{8})\b/',
            ],
        ],
        'chandarana' => [
            'domains'  => ['chandaranasupermarkets.co.ke'],
            'patterns' => [
                // PDF: "Order No. & Date -   1001120070938"
                '/Order\s+No[\s.&]+Date\s*[-–]\s*(\d{10,15})/i',
                // Chandarana 13-digit number
                '/\b(1\d{12})\b/',
            ],
        ],
    ];

    /**
     * Patterns tried when no sender rule matches — ordered most-specific first.
     */
    private const GENERIC_PATTERNS = [
        // Explicit labels only. The digit look-ahead prevents matching prose such as "PO attached".
        '/(?<![A-Z0-9-])PO\s*(?:NUMBER|NO\.?|#)?\s*[:#-]?\s*(?=[A-Z0-9-]*\d)([A-Z0-9][A-Z0-9-]{3,99})(?![A-Z0-9-])/i',
        '/(?<![A-Z0-9-])PURCHASE\s+ORDER\s*(?:NUMBER|NO\.?|#)?\s*[:#-]?\s*(?=[A-Z0-9-]*\d)([A-Z0-9][A-Z0-9-]{3,99})(?![A-Z0-9-])/i',
        '/Purchase order Confirmation:\s*(P\d{6,12})/i',
        '/PURCHASE ORDER\s*#\s*(\d{3}-\d{5,10})/i',
        '/Order\s+No[\s.&]+Date\s*[-–]\s*(\d{10,15})/i',
        '/\bP\d{9,10}\b/',        // Naivas style
        '/\b\d{3}-\d{8}\b/',      // QuickMart style
        '/\b\d{8}\b/',             // Carrefour 8-digit
        '/\b1\d{12}\b/',          // Chandarana 13-digit
    ];

    /** @param PoExtractorContract[] $aiExtractors Ordered list to try if patterns fail */
    public function __construct(
        private readonly array $aiExtractors = [],
        private readonly PoCanonicalNormalizer $canonical = new PoCanonicalNormalizer,
        private readonly CarrefourPoNormalizer $carrefour = new CarrefourPoNormalizer,
        private readonly QuickmartPoNormalizer $quickmart = new QuickmartPoNormalizer,
        private readonly ChandaranaPoNormalizer $chandarana = new ChandaranaPoNormalizer,
        private readonly PartnerPoPdfContextService $partnerPdf = new PartnerPoPdfContextService,
    ) {
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Main entry point — tries every source in priority order and returns the
     * best (highest confidence) result.
     */
    public function extract(
        string  $senderEmail,
        string  $subject,
        ?string $bodyText = null,
        ?string $pdfText  = null,
    ): ?ExtractionResult {
        $candidates = $this->extractAll($senderEmail, [
            'subject' => $subject,
            'body' => $bodyText,
            'attachment_content' => $pdfText,
        ]);

        if ($candidates === []) {
            return null;
        }

        $candidate = $candidates[0];
        return new ExtractionResult(
            $candidate['po_number'],
            $candidate['method'],
            $candidate['confidence'],
            $candidate['raw_match'],
        );
    }

    /**
     * Return every candidate occurrence with its provenance. Deterministic candidates
     * are always returned before AI suggestions; punctuation and leading zeroes are preserved.
     *
     * @param array<string, string|null> $sources
     * @return array<int, array{po_number:string,source:string,method:string,confidence:int,raw_match:string,deterministic:bool}>
     */
    /**
     * Check if two PO numbers are numerically close (within 10 of each other)
     */
    private function areNumericallyClose(string $a, string $b): bool
    {
        $numA = filter_var($a, FILTER_VALIDATE_INT);
        $numB = filter_var($b, FILTER_VALIDATE_INT);
        if ($numA === false || $numB === false) {
            return false;
        }
        return abs($numA - $numB) <= 10;
    }

    public function extractAll(string $senderEmail, array $sources): array
    {
        $found = $this->extractDeterministicAll($senderEmail, $sources);

        // Separate PDF ground truth candidates
        $pdfGroundTruth = array_filter($found, fn ($c) => $c['method'] === 'pdf_ground_truth');
        if (!empty($pdfGroundTruth)) {
            $pdfPoNumbers = array_column($pdfGroundTruth, 'po_number');

            // Boost all candidates that match PDF ground truth
            foreach ($found as &$candidate) {
                if (in_array($candidate['po_number'], $pdfPoNumbers, true)) {
                    $candidate['confidence'] = 100;
                    $candidate['method'] = 'verified_by_pdf';
                }
                // Also boost candidates that are numerically close to PDF ground truth
                else {
                    foreach ($pdfPoNumbers as $pdfPo) {
                        if ($this->areNumericallyClose($candidate['po_number'], $pdfPo)) {
                            $candidate['confidence'] = min($candidate['confidence'] + 20, 95);
                            $candidate['method'] = 'close_match_to_pdf';
                            break;
                        }
                    }
                }
            }
            unset($candidate);
        }

        // Sort by confidence descending
        usort($found, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

        if ($found !== []) {
            return $found;
        }

        $ai = $this->tryAi(
            $senderEmail,
            (string) ($sources['subject'] ?? ''),
            $sources['body'] ?? null,
            $sources['attachment_content'] ?? null,
        );

        return $ai ? [[
            'po_number' => $this->normalise($ai->poNumber),
            'source' => 'ai_context',
            'method' => $ai->method,
            'confidence' => min($ai->confidence, 79),
            'raw_match' => (string) $ai->rawMatch,
            'deterministic' => false,
        ]] : [];
    }

    /** Deterministic-only scan used by ingestion; never invokes an AI provider. */
    /**
     * Extract all numeric sequences of length >3 from text, normalize them to core digits.
     * @return array<int, array{po_number:string, source:string, method:string, confidence:int, raw_match:string, deterministic:bool}>
     */
    private function extractAllNumericSequences(string $senderEmail, array $sources): array
    {
        $found = [];

        foreach ($sources as $source => $text) {
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            // First extract ground truth POs from PDF attachments using PartnerPoPdfContextService
            if (str_starts_with($source, 'attachment_content:')) {
                $structured = $this->partnerPdf->parseStructured($text, $senderEmail);
                if ($structured['canonical_po'] !== null) {
                    $key = $structured['canonical_po'].'|'.$source.'|pdf_ground_truth';
                    $found[$key] = [
                        'po_number' => $this->normalise($structured['canonical_po']),
                        'source' => $source,
                        'method' => 'pdf_ground_truth',
                        'confidence' => 100,
                        'raw_match' => $structured['po_number'] ?? $structured['canonical_po'],
                        'deterministic' => true,
                    ];
                }
            }

            // Extract all numeric sequences >3 digits
            if (preg_match_all('/\d{4,}/', $text, $matches) !== false) {
                foreach ($matches[0] as $match) {
                    // Normalize: trim to core digits (remove leading zeros, etc.)
                    $po = $this->canonical->normalisePo($match) ?? $this->normalise($match);
                    if ($po === null || strlen($po) < 4) {
                        continue;
                    }
                    $key = $po.'|'.$source.'|numeric_sequence';
                    if (!isset($found[$key])) {
                        $found[$key] = [
                            'po_number' => $po,
                            'source' => $source,
                            'method' => 'numeric_sequence',
                            'confidence' => 80,
                            'raw_match' => $match,
                            'deterministic' => true,
                        ];
                    }
                }
            }

            // Extract sequences that look like PO numbers with prefixes/suffixes
            if (preg_match_all('/(?:PO|PO#|Order|Order#|No)[^\d]*(\d{4,})/i', $text, $prefixedMatches) !== false) {
                foreach ($prefixedMatches[1] as $prefixedMatch) {
                    $po = $this->canonical->normalisePo($prefixedMatch) ?? $this->normalise($prefixedMatch);
                    if ($po === null || strlen($po) < 4) {
                        continue;
                    }
                    $key = $po.'|'.$source.'|prefixed_po';
                    if (!isset($found[$key])) {
                        $found[$key] = [
                            'po_number' => $po,
                            'source' => $source,
                            'method' => 'prefixed_po',
                            'confidence' => 90,
                            'raw_match' => $prefixedMatch,
                            'deterministic' => true,
                        ];
                    }
                }
            }

            // Extract sequences with non-digit separators (like 036-00081125)
            if (preg_match_all('/(\d+(?:[-\d]*\d)?)/', $text, $separatorMatches) !== false) {
                foreach ($separatorMatches[1] as $sepMatch) {
                    $po = $this->canonical->normalisePo($sepMatch);
                    if ($po === null || strlen($po) < 4) {
                        continue;
                    }
                    $key = $po.'|'.$source.'|separator_sequence';
                    if (!isset($found[$key])) {
                        $found[$key] = [
                            'po_number' => $po,
                            'source' => $source,
                            'method' => 'separator_sequence',
                            'confidence' => 85,
                            'raw_match' => $sepMatch,
                            'deterministic' => true,
                        ];
                    }
                }
            }
        }

        return array_values($found);
    }

    public function extractDeterministicAll(string $senderEmail, array $sources): array
    {
        $naivasCandidates = $this->extractNaivasCanonicalCandidates($senderEmail, $sources);
        if ($naivasCandidates !== []) {
            return $naivasCandidates;
        }

        $carrefourCandidates = $this->extractCarrefourCanonicalCandidates($senderEmail, $sources);
        if ($carrefourCandidates !== []) {
            return $carrefourCandidates;
        }

        $chandaranaCandidates = $this->extractChandaranaCanonicalCandidates($senderEmail, $sources);
        if ($chandaranaCandidates !== []) {
            return $chandaranaCandidates;
        }

        $quickmartCandidates = $this->extractQuickmartCanonicalCandidates($senderEmail, $sources);
        if ($quickmartCandidates !== []) {
            return $quickmartCandidates;
        }

        $patterns = $this->resolvePatterns($senderEmail);
        $found = [];

        foreach ($sources as $source => $text) {
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
                    continue;
                }

                foreach ($matches as $match) {
                    $capture = isset($match[1]) && ($match[1][0] ?? '') !== '' ? $match[1][0] : $match[0][0];
                    $raw = $match[0][0];
                    $po = $this->normalise($capture);
                    $key = $po.'|'.$source.'|'.($match[0][1] ?? 0);
                    $found[$key] = [
                        'po_number' => $po,
                        'source' => $source,
                        'method' => $source.'_pattern',
                        'confidence' => 100,
                        'raw_match' => $raw,
                        'deterministic' => true,
                    ];
                }
            }
        }

        // Also add all numeric sequence candidates and PDF ground truth candidates
        $numericCandidates = $this->extractAllNumericSequences($senderEmail, $sources);
        foreach ($numericCandidates as $candidate) {
            $key = $candidate['po_number'].'|'.$candidate['source'].'|'.$candidate['method'];
            if (!isset($found[$key])) {
                $found[$key] = $candidate;
            }
        }

        return array_values($found);
    }

    /** Extract from subject only (fast path used for bulk runs). */
    public function extractFromSubject(string $senderEmail, string $subject): ?ExtractionResult
    {
        $patterns = $this->resolvePatterns($senderEmail);
        return $this->tryPatterns($subject, $patterns, 'subject_pattern');
    }

    /** Extract from PDF OCR text. */
    public function extractFromPdfText(string $senderEmail, string $pdfText): ?ExtractionResult
    {
        $patterns = $this->resolvePatterns($senderEmail);
        return $this->tryPatterns($pdfText, $patterns, 'pdf_pattern');
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Build ordered pattern list for a sender.
     * Checks: DB config patterns → built-in rules → generic fallback.
     */
    private function resolvePatterns(string $senderEmail): array
    {
        // 1. DB-configured patterns for this sender (gracefully skip if DB is unavailable)
        try {
            $config = EmailImportConfig::findForSender($senderEmail);
            if ($config && ! empty($config->po_patterns)) {
                return array_merge($config->po_patterns, self::GENERIC_PATTERNS);
            }
        } catch (\Throwable) {
            // DB unavailable (e.g. unit test environment) — fall through to built-in rules
        }

        // 2. Built-in rules
        foreach (self::BUILT_IN_RULES as $rule) {
            if ($this->senderMatchesRule($senderEmail, $rule)) {
                return array_merge($rule['patterns'], self::GENERIC_PATTERNS);
            }
        }

        // 3. Generic
        return self::GENERIC_PATTERNS;
    }

    private function senderMatchesRule(string $senderEmail, array $rule): bool
    {
        $lower = strtolower($senderEmail);

        foreach ($rule['emails'] ?? [] as $email) {
            if ($lower === strtolower($email)) {
                return true;
            }
        }

        foreach ($rule['domains'] ?? [] as $domain) {
            if (str_ends_with($lower, '@' . strtolower($domain))) {
                return true;
            }
        }

        return false;
    }

    /** Try each pattern against $text and return the first match. */
    private function tryPatterns(string $text, array $patterns, string $method): ?ExtractionResult
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $poNumber = isset($matches[1]) ? trim($matches[1]) : trim($matches[0]);
                return new ExtractionResult(
                    poNumber:   $this->normalise($poNumber),
                    method:     $method,
                    confidence: 100,
                    rawMatch:   $matches[0],
                );
            }
        }
        return null;
    }

    /** Run all available AI extractors in order until one succeeds. */
    private function tryAi(string $sender, string $subject, ?string $body, ?string $pdf): ?ExtractionResult
    {
        $text = implode("\n\n", array_filter([
            "From: {$sender}",
            "Subject: {$subject}",
            $body ? "Body: {$body}" : null,
            $pdf  ? "Document text:\n{$pdf}" : null,
        ]));

        foreach ($this->aiExtractors as $extractor) {
            if (! $extractor->isAvailable()) {
                continue;
            }
            $result = $extractor->extractFromText($text, ['sender' => $sender]);
            if ($result) {
                return $result;
            }
        }

        return null;
    }

    /** Normalise a raw PO string: uppercase, strip surrounding whitespace. */
    private function normalise(string $po): string
    {
        return strtoupper(trim($po));
    }

    /**
     * Naivas edge-case path: unified sanitise/normalise for subjects and attachment names.
     *
     * @param  array<string, string|null>  $sources
     * @return array<int, array{po_number:string,source:string,method:string,confidence:int,raw_match:string,deterministic:bool}>
     */
    private function extractNaivasCanonicalCandidates(string $senderEmail, array $sources): array
    {
        if (! $this->senderMatchesRule($senderEmail, self::BUILT_IN_RULES['naivas'])) {
            return [];
        }

        $found = [];
        foreach ($sources as $source => $text) {
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            $scanText = str_starts_with($source, 'attachment_filename:')
                ? pathinfo($text, PATHINFO_FILENAME)
                : $text;

            if ($this->carrefour->isCarrefourFormat($scanText)) {
                continue;
            }

            if (str_starts_with($source, 'attachment_content:')) {
                $structured = $this->partnerPdf->parseStructured($scanText, $senderEmail);
                $canonical = $structured['canonical_po']
                    ?? $this->canonical->normalisePo($structured['po_number'] ?? '');
            } elseif (str_starts_with($source, 'attachment_filename:')) {
                $canonical = $this->canonical->normalisePo($scanText);
            } else {
                $canonical = $this->canonical->extractPoFromSubject($scanText);
            }

            if ($canonical === null && preg_match('/\*(P0\d{7,10})\*/i', $scanText, $starMatch)) {
                $canonical = $this->canonical->normalisePo($starMatch[1]);
            }

            if ($canonical === null) {
                continue;
            }

            $raw = $this->canonical->extractNaivasRawToken($scanText) ?? ('P0'.$canonical);
            $key = $raw.'|'.$source;
            $found[$key] = [
                'po_number' => $this->normalise($raw),
                'source' => $source,
                'method' => 'naivas_canonical',
                'confidence' => 100,
                'raw_match' => $scanText,
                'deterministic' => true,
            ];
        }

        return array_values($found);
    }

    /**
     * Carrefour edge-case path: C4 prefix with trailing numeric PO segment.
     *
     * @param  array<string, string|null>  $sources
     * @return array<int, array{po_number:string,source:string,method:string,confidence:int,raw_match:string,deterministic:bool}>
     */
    private function extractCarrefourCanonicalCandidates(string $senderEmail, array $sources): array
    {
        if (! $this->senderMatchesRule($senderEmail, self::BUILT_IN_RULES['carrefour'])) {
            return [];
        }

        $found = [];
        foreach ($sources as $source => $text) {
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            $scanText = $source === 'subject'
                ? $this->carrefour->stripThreadPrefix($text)
                : $text;

            if (str_starts_with($source, 'attachment_content:') && preg_match('/Subject:\s*(.+)/i', $scanText, $metaMatch)) {
                $scanText = trim($metaMatch[1]);
            }

            [$canonical, $extractionMethod] = $this->carrefour->extractPoForCarrefour($scanText);
            if ($canonical === null) {
                continue;
            }

            $method = $extractionMethod === 'carrefour' ? 'carrefour_canonical' : 'carrefour_fallback';
            $key = $canonical.'|'.$source;
            $found[$key] = [
                'po_number' => $canonical,
                'source' => $source,
                'method' => $method,
                'confidence' => $extractionMethod === 'carrefour' ? 100 : 85,
                'raw_match' => $text,
                'deterministic' => true,
            ];
        }

        return array_values($found);
    }

    /**
     * @param  array<string, string|null>  $sources
     * @return array<int, array{po_number:string,source:string,method:string,confidence:int,raw_match:string,deterministic:bool}>
     */
    private function extractChandaranaCanonicalCandidates(string $senderEmail, array $sources): array
    {
        if (! $this->senderMatchesRule($senderEmail, self::BUILT_IN_RULES['chandarana'])
            && ! $this->textLooksChandarana($sources)) {
            return [];
        }

        $found = [];
        foreach ($sources as $source => $text) {
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            $structured = str_starts_with($source, 'attachment_content:')
                ? $this->partnerPdf->parseStructured($text, $senderEmail)
                : null;

            $rawPo = $structured['po_number']
                ?? $this->chandarana->extractFromText($text)['po_number']
                ?? null;

            if ($rawPo === null) {
                continue;
            }

            $key = $rawPo.'|'.$source;
            $found[$key] = [
                'po_number'     => $this->normalise($rawPo),
                'source'        => $source,
                'method'        => 'chandarana_canonical',
                'confidence'    => 100,
                'raw_match'     => $text,
                'deterministic' => true,
            ];
        }

        return array_values($found);
    }

    /**
     * @param  array<string, string|null>  $sources
     * @return array<int, array{po_number:string,source:string,method:string,confidence:int,raw_match:string,deterministic:bool}>
     */
    private function extractQuickmartCanonicalCandidates(string $senderEmail, array $sources): array
    {
        if (! $this->senderMatchesRule($senderEmail, self::BUILT_IN_RULES['quickmart'])
            && ! $this->textLooksQuickmart($sources)) {
            return [];
        }

        $found = [];
        foreach ($sources as $source => $text) {
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            $structured = str_starts_with($source, 'attachment_content:')
                ? $this->partnerPdf->parseStructured($text, $senderEmail)
                : null;

            $rawPo = $structured['po_number']
                ?? $this->quickmart->extractRawPo($text);

            if ($rawPo === null) {
                continue;
            }

            $matchKey = $this->quickmart->primaryMatchKey($rawPo) ?? $rawPo;
            $key = $matchKey.'|'.$source;
            $found[$key] = [
                'po_number'     => $this->normalise($matchKey),
                'source'        => $source,
                'method'        => 'quickmart_canonical',
                'confidence'    => 100,
                'raw_match'     => $text,
                'deterministic' => true,
            ];
        }

        return array_values($found);
    }

    /** @param  array<string, string|null>  $sources */
    private function textLooksChandarana(array $sources): bool
    {
        foreach ($sources as $text) {
            if (! is_string($text)) {
                continue;
            }

            if (preg_match('/\bCHANDARANA\b/i', $text)
                || preg_match('/Order\s+No\.?\s*&\s*Date\s*[-–:]/i', $text)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, string|null>  $sources */
    private function textLooksQuickmart(array $sources): bool
    {
        foreach ($sources as $text) {
            if (is_string($text) && preg_match('/\bQUICK\s*MART\b|Backoffice\s+PURCHASE\s+ORDER/i', $text)) {
                return true;
            }
        }

        return false;
    }
}
