<?php

namespace App\Services\OrderMatch;

class OrderMatchPoNormalizer
{
    public function normalise(?string $po): ?string
    {
        if ($po === null || trim($po) === '') {
            return null;
        }

        $clean = strtoupper(trim($po));
        $clean = preg_replace('/[^A-Z0-9-]/', '', $clean) ?? '';

        return mb_substr($clean, 0, 30) ?: null;
    }
}