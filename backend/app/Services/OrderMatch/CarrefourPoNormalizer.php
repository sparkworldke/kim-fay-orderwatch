<?php

namespace App\Services\OrderMatch;

/**
 * Carrefour C4-format PO extraction — trailing numeric segment only.
 *
 * The digit in "C4" must not be merged with the PO number (e.g. C4 KEV 2600050 → 2600050).
 *
 * @see c4-po-sanitize.md
 */
class CarrefourPoNormalizer
{
    /** @var array<string, true> */
    public const BRANCH_TOKENS = [
        'KEV' => true,
        'KEI' => true,
        'MALL' => true,
        'THIKA' => true,
        'KAREN' => true,
        'WESTGATE' => true,
        'JUNCTION' => true,
        'HIGHWAY' => true,
        'SARIT' => true,
        'GARDEN' => true,
        'CITY' => true,
        'PRESTIGE' => true,
        'NEXTGEN' => true,
        'MOMBASA' => true,
        'KISUMU' => true,
        'GCM' => true,
        'XGCM' => true,
        'IRU' => true,
    ];

    private const THREAD_PREFIX = '/^(RE|FWD|FW|RES|TR|AW|SV|VS|إعادة(?:\s+توجيه)?)[\s:\-]+/iu';

    public function __construct(
        private readonly PoCanonicalNormalizer $canonical = new PoCanonicalNormalizer,
    ) {
    }

    public function stripThreadPrefix(string $subject): string
    {
        $text = $subject;
        while (true) {
            $trimmed = trim($text);
            $cleaned = preg_replace(self::THREAD_PREFIX, '', $trimmed, 1);
            if (! is_string($cleaned) || $cleaned === $trimmed) {
                break;
            }
            $text = $cleaned;
        }

        return trim($text);
    }

    public function isCarrefourFormat(string $raw): bool
    {
        $clean = $this->canonical->sanitisePo($raw);
        if ($clean === '') {
            return false;
        }

        $upper = strtoupper($clean);

        return str_starts_with($upper, 'C4-') || $upper === 'C4';
    }

    public function extractCarrefourPo(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $clean = $this->canonical->sanitisePo($raw);
        if ($clean === '') {
            return null;
        }

        $tokens = explode('-', strtoupper($clean));
        if ($tokens === [] || $tokens[0] !== 'C4') {
            return null;
        }

        foreach (array_slice($tokens, 1) as $token) {
            if ($token === '') {
                continue;
            }

            if (isset(self::BRANCH_TOKENS[$token])) {
                continue;
            }

            if (preg_match('/^\d+$/', $token) === 1) {
                $canonical = ltrim($token, '0');
                if ($canonical === '' || strlen($canonical) < PoCanonicalNormalizer::MIN_CANONICAL_LENGTH) {
                    return null;
                }

                return $canonical;
            }

            // Unknown branch tokens are skipped; audit logging is handled upstream.
        }

        return null;
    }

    public function extractCarrefourPoFromSubject(?string $subject): ?string
    {
        if ($subject === null || trim($subject) === '') {
            return null;
        }

        return $this->extractCarrefourPo($this->stripThreadPrefix($subject));
    }

    /**
     * @return array{0: ?string, 1: string} [canonical_key, extraction_method]
     */
    public function extractPoForCarrefour(string $raw): array
    {
        $result = $this->extractCarrefourPo($raw);
        if ($result !== null) {
            return [$result, 'carrefour'];
        }

        if ($this->isCarrefourFormat($raw)) {
            $fallback = $this->canonical->normalisePo($raw);
            if ($fallback !== null) {
                return [$fallback, 'fallback_general'];
            }

            return [null, 'failed'];
        }

        return [null, 'failed'];
    }
}