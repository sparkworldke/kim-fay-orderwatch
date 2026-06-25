<?php

namespace App\Services\OrderMatch;

/**
 * Unified sanitise → normalise pipeline for PO/SO numbers (Naivas edge cases).
 *
 * Canonical key = all digits concatenated, leading zeros stripped, min length 5.
 *
 * @see naivas-matching.md
 */
class PoCanonicalNormalizer
{
    public const MIN_CANONICAL_LENGTH = 5;

    public function sanitisePo(string $raw): string
    {
        if (trim($raw) === '') {
            return '';
        }

        $text = $raw;
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($raw, \Normalizer::FORM_KC);
            if (is_string($normalized)) {
                $text = $normalized;
            }
        }

        $text = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{202F}\x{3000}]/u', ' ', $text) ?? $text;
        $text = preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $text) ?? $text;
        $text = trim($text);
        // Keep spaces until collapse so tokens like "P042574206" stay separated from prose.
        $text = preg_replace('/[^A-Za-z0-9\-\s]/', '', $text) ?? '';
        $text = preg_replace('/[\-\s]+/', '-', $text) ?? $text;

        return trim($text, '-');
    }

    public function normalisePo(?string $raw): ?string
    {
        if ($raw === null || ! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $clean = $this->sanitisePo($raw);
        if ($clean === '') {
            return null;
        }

        if (preg_match_all('/\d+/', $clean, $matches) === false || $matches[0] === []) {
            return null;
        }

        $combined = implode('', $matches[0]);
        $canonical = ltrim($combined, '0');

        if ($canonical === '' || strlen($canonical) < self::MIN_CANONICAL_LENGTH) {
            return null;
        }

        return $canonical;
    }

    public function extractPoFromSubject(?string $subject): ?string
    {
        if ($subject === null || trim($subject) === '') {
            return null;
        }

        $clean = $this->sanitisePo($subject);
        if ($clean === '' || str_starts_with(strtoupper($clean), 'C4-') || strtoupper($clean) === 'C4') {
            return null;
        }

        if (preg_match('/(?:^|[^A-Za-z])(P(?:O)?)-?(\d{6,})(?!\d)/i', $clean, $match)) {
            return $this->normalisePo($match[2]);
        }

        if (preg_match_all('/(?:^|[^0-9])(\d{6,})(?!\d)/', $clean, $candidates) !== false && $candidates[1] !== []) {
            $best = '';
            foreach ($candidates[1] as $candidate) {
                if (strlen($candidate) > strlen($best)) {
                    $best = $candidate;
                }
            }

            return $this->normalisePo($best);
        }

        return null;
    }

    /** Raw P0-style token for storage/audit when present in text. */
    public function extractNaivasRawToken(string $text): ?string
    {
        $clean = $this->sanitisePo($text);
        if ($clean !== '' && preg_match('/(?:^|[^A-Za-z])(P0\d{7,10})(?!\d)/i', $clean, $match)) {
            return strtoupper($match[1]);
        }

        if (preg_match('/(?:^|[^A-Za-z])(P0\d{7,10})(?!\d)/i', $text, $match)) {
            return strtoupper($match[1]);
        }

        $canonical = $this->normalisePo($text);

        return $canonical !== null ? 'P0'.$canonical : null;
    }
}