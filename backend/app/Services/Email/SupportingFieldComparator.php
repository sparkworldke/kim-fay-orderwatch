<?php

namespace App\Services\Email;

use App\Models\AcumaticaSalesOrder;

class SupportingFieldComparator
{
    /**
     * Compare only explicitly labelled values. Absence is unknown and never a conflict.
     *
     * @return array<int, array{field:string,email_value:string,acumatica_value:string,reason:string}>
     */
    public function compare(AcumaticaSalesOrder $order, string $text): array
    {
        $conflicts = [];

        if ($currency = $this->capture($text, '/\b(?:CURRENCY|CUR)\s*[:#-]\s*([A-Z]{3})\b/i')) {
            if ($order->currency_id && strcasecmp($currency, $order->currency_id) !== 0) {
                $conflicts[] = $this->conflict('currency', $currency, $order->currency_id);
            }
        }

        if ($total = $this->capture($text, '/\b(?:GRAND\s+TOTAL|ORDER\s+TOTAL|TOTAL)\s*[:#-]?\s*(?:[A-Z]{3}\s*)?([0-9][0-9,]*(?:\.\d{1,4})?)/i')) {
            $emailTotal = (float) str_replace(',', '', $total);
            $orderTotal = (float) $order->order_total;
            if (abs($emailTotal - $orderTotal) > 0.01) {
                $conflicts[] = $this->conflict('total', (string) $emailTotal, (string) $orderTotal);
            }
        }

        if ($branch = $this->capture($text, '/\b(?:BRANCH|LOCATION)\s*[:#-]\s*([A-Z0-9][A-Z0-9 _-]{1,80})/i')) {
            $branch = trim(preg_split('/[\r\n,;]/', $branch)[0]);
            if ($order->location_id && strcasecmp($this->normaliseText($branch), $this->normaliseText($order->location_id)) !== 0) {
                $conflicts[] = $this->conflict('branch', $branch, $order->location_id);
            }
        }

        if ($date = $this->capture($text, '/\b(?:REQUESTED\s+(?:DELIVERY\s+)?DATE|DELIVERY\s+DATE)\s*[:#-]\s*(\d{4}-\d{2}-\d{2})\b/i')) {
            $expected = $order->requested_on?->format('Y-m-d');
            if ($expected && $date !== $expected) {
                $conflicts[] = $this->conflict('delivery_date', $date, $expected);
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

    private function normaliseText(string $value): string
    {
        return strtoupper(trim(preg_replace('/\s+/', ' ', $value)));
    }
}
