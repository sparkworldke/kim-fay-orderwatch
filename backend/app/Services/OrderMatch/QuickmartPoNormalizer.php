<?php

namespace App\Services\OrderMatch;

/**
 * QuickMart PO formats: Backoffice PURCHASE ORDER # 074-00002048
 * Acumatica may store 009-00082814, 82814, 48014, or PO : 70924 style suffixes.
 */
class QuickmartPoNormalizer
{
    public function extractRawPo(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        if (preg_match('/Backoffice\s+PURCHASE\s+ORDER\s*#\s*(\d{3}-\d{5,10})/i', $text, $match)) {
            return strtoupper($match[1]);
        }

        if (preg_match('/PURCHASE\s+ORDER\s*#\s*(\d{3}-\d{5,10})/i', $text, $match)) {
            return strtoupper($match[1]);
        }

        if (preg_match('/\b(\d{3}-\d{5,10})\b/', $text, $match)) {
            return strtoupper($match[1]);
        }

        return null;
    }

    /** @return list<string> */
    public function matchKeys(string $raw): array
    {
        $raw = strtoupper(trim($raw));
        $keys = [];

        if ($raw !== '') {
            $keys[] = $raw;
        }

        if (preg_match('/^(\d{3})-(\d{5,10})$/', $raw, $match)) {
            $suffix = ltrim($match[2], '0') ?: $match[2];
            $keys[] = $match[1].'-'.$match[2];

            if (strlen($suffix) >= 5) {
                $keys[] = substr($suffix, -5);
            }
            if (strlen($suffix) >= 4) {
                $keys[] = substr($suffix, -4);
            }
            if ($suffix !== '') {
                $keys[] = $suffix;
            }
        } elseif (preg_match('/^\d{4,6}$/', $raw)) {
            $trimmed = ltrim($raw, '0') ?: $raw;
            $keys[] = $trimmed;
            if (strlen($trimmed) >= 5) {
                $keys[] = substr($trimmed, -5);
            }
            if (strlen($trimmed) >= 4) {
                $keys[] = substr($trimmed, -4);
            }
        }

        return array_values(array_unique(array_filter($keys)));
    }

    public function primaryMatchKey(string $raw): ?string
    {
        $keys = $this->matchKeys($raw);

        return $keys[0] ?? null;
    }

    public function normaliseStored(?string $stored): ?string
    {
        if ($stored === null || trim($stored) === '') {
            return null;
        }

        $clean = preg_replace('/^PO\s*:\s*/i', '', trim($stored)) ?? trim($stored);
        $clean = strtoupper($clean);

        if (preg_match('/^(\d{3})-(\d{5,10})$/', $clean, $match)) {
            return $this->primaryMatchKey($match[0]);
        }

        $digits = preg_replace('/\D/', '', $clean) ?? '';
        if ($digits === '') {
            return $clean;
        }

        return ltrim($digits, '0') ?: $digits;
    }

    public function matchesStored(?string $stored, string $extractedRaw): bool
    {
        $storedKey = $this->normaliseStored($stored);
        if ($storedKey === null) {
            return false;
        }

        foreach ($this->matchKeys($extractedRaw) as $key) {
            $candidate = $this->normaliseStored($key);
            if ($candidate === null) {
                continue;
            }

            if (strcasecmp($storedKey, $candidate) === 0) {
                return true;
            }

            if (strlen($candidate) >= 4 && str_ends_with($storedKey, $candidate)) {
                return true;
            }

            if (strlen($storedKey) >= 4 && str_ends_with($candidate, $storedKey)) {
                return true;
            }
        }

        return false;
    }
}