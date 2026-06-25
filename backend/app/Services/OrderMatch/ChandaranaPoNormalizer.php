<?php

namespace App\Services\OrderMatch;

/**
 * Chandarana PO label: Order No. & Date - 1001120070943 15-Jun-2026
 * Acumatica may store full 13-digit IDs or short forms like PO : 70924.
 */
class ChandaranaPoNormalizer
{
    /**
     * @return array{po_number: string, po_date: ?string}|null
     */
    public function extractFromText(?string $text): ?array
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        if (preg_match(
            '/Order\s+No\.?\s*&\s*Date\s*[-–:]\s*(\d{13})\s+(\d{1,2}-[A-Za-z]{3}-\d{4})/i',
            $text,
            $match,
        )) {
            return [
                'po_number' => $match[1],
                'po_date'   => $this->normaliseDate($match[2]),
            ];
        }

        if (preg_match('/Order\s+No\.?\s*&\s*Date\s*[-–:]\s*(\d{13})/i', $text, $match)) {
            return [
                'po_number' => $match[1],
                'po_date'   => null,
            ];
        }

        if (preg_match('/\b(1\d{12})\b/', $text, $match)) {
            return [
                'po_number' => $match[1],
                'po_date'   => null,
            ];
        }

        return null;
    }

    /** @return list<string> */
    public function matchKeys(string $rawPo): array
    {
        $digits = preg_replace('/\D/', '', $rawPo) ?? '';
        if ($digits === '') {
            return [];
        }

        $keys = [$digits];

        if (strlen($digits) >= 5) {
            $keys[] = substr($digits, -5);
        }
        if (strlen($digits) >= 4) {
            $keys[] = substr($digits, -4);
        }

        return array_values(array_unique($keys));
    }

    public function primaryMatchKey(string $rawPo): ?string
    {
        $keys = $this->matchKeys($rawPo);

        return $keys[0] ?? null;
    }

    public function normaliseStored(?string $stored): ?string
    {
        if ($stored === null || trim($stored) === '') {
            return null;
        }

        $clean = preg_replace('/^PO\s*:\s*/i', '', trim($stored)) ?? trim($stored);
        $digits = preg_replace('/\D/', '', $clean) ?? '';

        if ($digits === '') {
            return strtoupper(trim($clean));
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
            if ($storedKey === $key) {
                return true;
            }

            if (strlen($key) >= 4 && str_ends_with($storedKey, $key)) {
                return true;
            }

            if (strlen($storedKey) >= 4 && str_ends_with($key, $storedKey)) {
                return true;
            }
        }

        return false;
    }

    private function normaliseDate(string $value): ?string
    {
        $value = trim($value);
        $timestamp = strtotime($value);

        return $timestamp ? date('Y-m-d', $timestamp) : $value;
    }
}