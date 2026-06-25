<?php

namespace App\Services\OrderMatch;

/**
 * Maps email PO tokens → Acumatica CustomerOrder ID for lookup.
 *
 * Naivas: any P/PO-prefixed or plain numeric input → canonical digits via PoCanonicalNormalizer
 * Carrefour: "C4 KEV 2600050" → trailing digits 2600050 (C4 digit excluded)
 */
class CustomerPoMatchResolver
{
    public const NAIVAS_SENDER = 'notification@naivas.net';

    public const CARREFOUR_SENDER = 'kencarrefourorders@maf.ae';

    public function __construct(
        private readonly PoCanonicalNormalizer $canonical = new PoCanonicalNormalizer,
        private readonly CarrefourPoNormalizer $carrefour = new CarrefourPoNormalizer,
        private readonly QuickmartPoNormalizer $quickmart = new QuickmartPoNormalizer,
        private readonly ChandaranaPoNormalizer $chandarana = new ChandaranaPoNormalizer,
    ) {
    }

    /**
     * Acumatica CustomerOrder value used for equality match against imported SOs.
     */
    public function toCustomerOrderId(
        string $po,
        ?string $senderEmail = null,
        ?string $customerName = null,
        ?string $subject = null,
    ): ?string {
        $po = strtoupper(trim($po));

        if ($this->isNaivas($senderEmail, $customerName)) {
            return $this->naivasCustomerOrderId($po);
        }

        if ($this->isCarrefour($senderEmail, $customerName)) {
            $fromSubject = $this->carrefourDigitsFromSubject($subject);
            if ($fromSubject !== null) {
                return $fromSubject;
            }

            return $this->carrefourCustomerOrderId($po);
        }

        if ($this->isQuickmart($senderEmail, $customerName)) {
            return $this->quickmartCustomerOrderId($po);
        }

        if ($this->isChandarana($senderEmail, $customerName)) {
            return $this->chandaranaCustomerOrderId($po);
        }

        return $po !== '' ? $po : null;
    }

    /** @return list<string> */
    public function acumaticaLookupKeys(
        string $po,
        ?string $senderEmail = null,
        ?string $customerName = null,
        ?string $subject = null,
    ): array {
        $customerOrderId = $this->toCustomerOrderId($po, $senderEmail, $customerName, $subject);
        if ($customerOrderId === null) {
            return [];
        }

        // Naivas & Carrefour: match Acumatica CustomerOrder by numeric ID only.
        if ($this->isNaivas($senderEmail, $customerName) || $this->isCarrefour($senderEmail, $customerName)) {
            return [$customerOrderId];
        }

        if ($this->isQuickmart($senderEmail, $customerName)) {
            return $this->quickmart->matchKeys(strtoupper(trim($po)));
        }

        if ($this->isChandarana($senderEmail, $customerName)) {
            return $this->chandarana->matchKeys(trim($po));
        }

        $raw = strtoupper(trim($po));
        $keys = [$customerOrderId];

        if ($raw !== '' && $raw !== $customerOrderId) {
            $keys[] = $raw;
        }

        return array_values(array_unique($keys));
    }

    public function toCanonicalMatchKey(
        string $po,
        ?string $senderEmail = null,
        ?string $customerName = null,
        ?string $subject = null,
    ): string {
        return $this->toCustomerOrderId($po, $senderEmail, $customerName, $subject)
            ?? strtoupper(trim($po));
    }

    /**
     * @param  list<array<string, mixed>>  $evidence
     * @return list<string>
     */
    public function validateEvidence(
        string $po,
        array $evidence,
        ?string $senderEmail = null,
        ?string $customerName = null,
        ?string $subject = null,
    ): array {
        $reasons = [];

        if ($this->isNaivas($senderEmail, $customerName)) {
            $customerOrderId = $this->naivasCustomerOrderId(strtoupper(trim($po)));
            if ($customerOrderId === null) {
                $reasons[] = 'naivas_po_format_mismatch';
            }

            $fromSubjectOrFile = collect($evidence)->contains(function ($item) {
                $source = (string) ($item['source'] ?? '');

                return $source === 'subject' || str_starts_with($source, 'attachment_filename:');
            });

            if (! $fromSubjectOrFile && $this->naivasCustomerOrderIdFromSubject($subject) === null) {
                $reasons[] = 'naivas_requires_subject_or_attachment';
            }
        }

        if ($this->isCarrefour($senderEmail, $customerName)) {
            $subjectDigits = $this->carrefourDigitsFromSubject($subject);
            if ($subjectDigits === null) {
                $reasons[] = 'carrefour_subject_digits_missing';
            } else {
                $poDigits = $this->carrefourCustomerOrderId($po);
                if ($poDigits !== null && $poDigits !== $subjectDigits) {
                    $reasons[] = 'carrefour_subject_po_mismatch';
                }
            }
        }

        return $reasons;
    }

    public function naivasCustomerOrderId(string $po): ?string
    {
        return $this->canonical->normalisePo($po);
    }

    public function naivasCustomerOrderIdFromSubject(?string $subject): ?string
    {
        return $this->canonical->extractPoFromSubject($subject);
    }

    public function customerOrderMatchesCanonical(
        ?string $storedCustomerOrder,
        string $canonicalKey,
        ?string $senderEmail = null,
        ?string $customerName = null,
    ): bool {
        if ($this->isQuickmart($senderEmail, $customerName)) {
            return $this->quickmart->matchesStored($storedCustomerOrder, $canonicalKey);
        }

        if ($this->isChandarana($senderEmail, $customerName)) {
            return $this->chandarana->matchesStored($storedCustomerOrder, $canonicalKey);
        }

        $storedCanonical = $this->canonicalKeyFromStoredCustomerOrder(
            $storedCustomerOrder,
            $senderEmail,
            $customerName,
        );

        return $storedCanonical !== null && $storedCanonical === $canonicalKey;
    }

    public function carrefourCustomerOrderId(string $po): ?string
    {
        [$canonical] = $this->carrefour->extractPoForCarrefour($po);
        if ($canonical !== null) {
            return $canonical;
        }

        // Plain numeric or Acumatica-style "po : 26013623" entries (no C4 prefix).
        return $this->canonical->normalisePo($po);
    }

    public function carrefourDigitsFromSubject(?string $subject): ?string
    {
        return $this->carrefour->extractCarrefourPoFromSubject($subject);
    }

    public function canonicalKeyFromStoredCustomerOrder(
        ?string $storedCustomerOrder,
        ?string $senderEmail = null,
        ?string $customerName = null,
    ): ?string {
        if ($storedCustomerOrder === null || trim($storedCustomerOrder) === '') {
            return null;
        }

        if ($this->isCarrefour($senderEmail, $customerName)) {
            return $this->carrefourCustomerOrderId($storedCustomerOrder);
        }

        if ($this->isNaivas($senderEmail, $customerName)) {
            return $this->canonical->normalisePo($storedCustomerOrder);
        }

        if ($this->isQuickmart($senderEmail, $customerName)) {
            return $this->quickmart->normaliseStored($storedCustomerOrder);
        }

        if ($this->isChandarana($senderEmail, $customerName)) {
            return $this->chandarana->normaliseStored($storedCustomerOrder);
        }

        return $this->canonical->normalisePo($storedCustomerOrder)
            ?? strtoupper(trim($storedCustomerOrder));
    }

    public function quickmartCustomerOrderId(string $po): ?string
    {
        $raw = $this->quickmart->extractRawPo($po) ?? strtoupper(trim($po));

        return $this->quickmart->primaryMatchKey($raw);
    }

    public function chandaranaCustomerOrderId(string $po): ?string
    {
        $parsed = $this->chandarana->extractFromText($po);
        $raw = $parsed['po_number'] ?? trim($po);

        return $this->chandarana->primaryMatchKey($raw);
    }

    public function isNaivas(?string $senderEmail, ?string $customerName = null): bool
    {
        if ($senderEmail && strcasecmp($senderEmail, self::NAIVAS_SENDER) === 0) {
            return true;
        }

        return $customerName !== null && stripos($customerName, 'naivas') !== false;
    }

    public function isCarrefour(?string $senderEmail, ?string $customerName = null): bool
    {
        if ($senderEmail && strcasecmp($senderEmail, self::CARREFOUR_SENDER) === 0) {
            return true;
        }

        return $customerName !== null && stripos($customerName, 'carrefour') !== false;
    }

    public function isQuickmart(?string $senderEmail, ?string $customerName = null): bool
    {
        if ($senderEmail && str_contains(strtolower($senderEmail), 'quickmart.co.ke')) {
            return true;
        }

        return $customerName !== null && stripos($customerName, 'quickmart') !== false;
    }

    public function isChandarana(?string $senderEmail, ?string $customerName = null): bool
    {
        if ($senderEmail && str_contains(strtolower($senderEmail), 'chandaranasupermarkets.co.ke')) {
            return true;
        }

        return $customerName !== null && stripos($customerName, 'chandarana') !== false;
    }
}