<?php

namespace App\Services\Email;

use App\Models\AcumaticaSalesOrder;
use App\Services\OrderMatch\CustomerPoMatchResolver;

class SupportingFieldComparator
{
    /** Kenya standard VAT — Naivas email totals are typically ex-VAT. */
    public const NAIVAS_VAT_RATE = 0.16;

    /** Allow minor rounding / line-level tax differences after VAT uplift. */
    public const NAIVAS_VAT_TOLERANCE_PCT = 0.02;

    public function __construct(
        private readonly CustomerPoMatchResolver $poResolver = new CustomerPoMatchResolver,
    ) {
    }

    /**
     * Compare only explicitly labelled values. Absence is unknown and never a conflict.
     *
     * @return array<int, array<string, string>>
     */
    public function compare(
        AcumaticaSalesOrder $order,
        string $text,
        ?string $senderEmail = null,
        ?string $customerName = null,
    ): array {
        $conflicts = [];
        $isNaivas = $this->poResolver->isNaivas($senderEmail, $customerName ?? $order->customer_name);

        if ($currency = $this->capture($text, '/\b(?:CURRENCY|CUR)\s*[:#-]\s*([A-Z]{3})\b/i')) {
            if ($order->currency_id && strcasecmp($currency, $order->currency_id) !== 0) {
                $conflicts[] = $this->conflict('currency', $currency, $order->currency_id);
            }
        }

        $totalConflict = $this->compareOrderTotals($text, (float) $order->order_total, $isNaivas);
        if ($totalConflict !== null) {
            $conflicts[] = $totalConflict;
        }

        if ($branch = $this->capture($text, '/\b(?:BRANCH|LOCATION)\s*[:#-]\s*([A-Z0-9][A-Z0-9 _-]{1,80})/i')) {
            $branch = trim(preg_split('/[\r\n,;]/', $branch)[0]);
            if ($order->location_id && strcasecmp($this->normaliseText($branch), $this->normaliseText($order->location_id)) !== 0) {
                $conflicts[] = $this->conflict('branch', $branch, $order->location_id);
            }
        }

        $deliveryDate = $this->capture($text, '/\b(?:REQUESTED\s+(?:DELIVERY\s+)?DATE|DELIVERY\s+DATE)\s*[:#-]\s*(\d{4}-\d{2}-\d{2})\b/i')
            ?? $this->captureHumanDateAfterLabel($text, 'Delivery\s+Date');
        if ($deliveryDate !== null) {
            $expected = $order->requested_on?->format('Y-m-d');
            if ($expected && $deliveryDate !== $expected) {
                $conflicts[] = $this->conflict('delivery_date', $deliveryDate, $expected);
            }
        }

        $order->loadMissing('lines');
        preg_match_all('/\b(?:SKU|ITEM)\s*[:#-]\s*([A-Z0-9._-]+)(?:[^\r\n]{0,80}?\bQTY\s*[:#-]\s*([0-9]+(?:\.\d+)?))?(?:[^\r\n]{0,80}?\b(?:UNIT\s+PRICE|PRICE)\s*[:#-]\s*([0-9,]+(?:\.\d+)?))?/i', $text, $rows, PREG_SET_ORDER);
        foreach ($rows as $row) {
            $sku = strtoupper($row[1]);
            $line = $order->lines->first(fn ($item) => strtoupper((string) $item->inventory_id) === $sku);
            if (! $line) {
                $conflicts[] = $this->conflict('sku', $sku, 'not present');
                continue;
            }
            if (($row[2] ?? '') !== '' && abs((float) $row[2] - (float) $line->order_qty) > 0.0001) {
                $conflicts[] = $this->conflict("quantity:{$sku}", $row[2], (string) $line->order_qty);
            }
            if (($row[3] ?? '') !== '' && abs((float) str_replace(',', '', $row[3]) - (float) $line->unit_price) > 0.0001) {
                $conflicts[] = $this->conflict("unit_price:{$sku}", $row[3], (string) $line->unit_price);
            }
        }

        return $conflicts;
    }

    private function capture(string $text, string $pattern): ?string
    {
        return preg_match($pattern, $text, $matches) ? trim($matches[1]) : null;
    }

    private function conflict(string $field, string $email, string $acumatica): array
    {
        return ['field' => $field, 'email_value' => $email, 'acumatica_value' => $acumatica, 'reason' => 'explicit_value_conflict'];
    }

    /** @return array<string, string>|null */
    private function compareOrderTotals(string $text, float $orderTotal, bool $isNaivas): ?array
    {
        $bases = $this->extractEmailTotalBases($text);
        if ($bases === []) {
            return null;
        }

        foreach ($bases as $basis) {
            if ($this->totalsMatchBasis($basis, $orderTotal, $isNaivas)) {
                return null;
            }
        }

        $basis = $bases[0];
        $emailDisplay = $this->formatMoney($basis['email']);
        $compareValue = $this->naivasCompareValue($basis, $isNaivas);
        $payload = $this->conflict('total', $emailDisplay, $this->formatMoney($orderTotal));
        $payload['amount_delta'] = $this->formatMoney($orderTotal - $compareValue);

        if ($isNaivas) {
            $payload['email_value_inc_vat'] = $this->formatMoney($compareValue);
            $payload['vat_rate'] = (string) (int) (self::NAIVAS_VAT_RATE * 100);
            $payload['reason'] = 'naivas_vat_adjusted_conflict';
        }

        return $payload;
    }

    /**
     * @return list<array{email: float, compare_value: float}>
     */
    private function extractEmailTotalBases(string $text): array
    {
        $bases = [];

        if ($orderTotal = $this->capture($text, '/Order\s+total\s*([0-9][0-9,]*(?:\.\d{1,4})?)/i')) {
            $value = (float) str_replace(',', '', $orderTotal);
            $bases[] = ['email' => $value, 'compare_value' => $value];
        }

        $subTotal = $this->capture($text, '/Sub\s+total\s*([0-9][0-9,]*(?:\.\d{1,4})?)/i');
        $vat = $this->capture($text, '/\bVAT\s*([0-9][0-9,]*(?:\.\d{1,4})?)/i');
        if ($subTotal !== null) {
            $subValue = (float) str_replace(',', '', $subTotal);
            $vatValue = $vat !== null ? (float) str_replace(',', '', $vat) : 0.0;
            $bases[] = [
                'email' => $subValue,
                'compare_value' => round($subValue + $vatValue, 2),
            ];
        }

        if ($generic = $this->capture($text, '/\b(?:GRAND\s+TOTAL|ORDER\s+TOTAL|TOTAL)\s*[:#-]?\s*(?:[A-Z]{3}\s*)?([0-9][0-9,]*(?:\.\d{1,4})?)/i')) {
            $value = (float) str_replace(',', '', $generic);
            $bases[] = ['email' => $value, 'compare_value' => $value];
        }

        return $bases;
    }

    /** @param  array{email: float, compare_value: float}  $basis */
    private function totalsMatchBasis(array $basis, float $orderTotal, bool $applyNaivasVat): bool
    {
        if (abs($basis['compare_value'] - $orderTotal) <= 0.01) {
            return true;
        }

        if (abs($basis['email'] - $orderTotal) <= 0.01) {
            return true;
        }

        if (! $applyNaivasVat) {
            return false;
        }

        $emailIncVat = round($basis['email'] * (1 + self::NAIVAS_VAT_RATE), 2);
        $tolerance = max(1.0, $orderTotal * self::NAIVAS_VAT_TOLERANCE_PCT);

        return abs($emailIncVat - $orderTotal) <= $tolerance
            || abs($basis['compare_value'] - $orderTotal) <= $tolerance;
    }

    /** @param  array{email: float, compare_value: float}  $basis */
    private function naivasCompareValue(array $basis, bool $isNaivas): float
    {
        if (! $isNaivas) {
            return $basis['compare_value'];
        }

        if (abs($basis['compare_value'] - $basis['email']) > 0.01) {
            return $basis['compare_value'];
        }

        return round($basis['email'] * (1 + self::NAIVAS_VAT_RATE), 2);
    }

    private function captureHumanDateAfterLabel(string $text, string $label): ?string
    {
        if (preg_match('/'.$label.':?\s*(?:KEN\s*)?(\d{1,2}\s+[A-Za-z]+,?\s+\d{4})/i', $text, $match)) {
            $timestamp = strtotime(trim($match[1]));

            return $timestamp ? date('Y-m-d', $timestamp) : null;
        }

        return null;
    }

    private function formatMoney(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function normaliseText(string $value): string
    {
        return strtoupper(trim(preg_replace('/\s+/', ' ', $value)));
    }
}
