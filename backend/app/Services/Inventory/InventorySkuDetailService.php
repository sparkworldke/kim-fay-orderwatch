<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use App\Services\Admin\AiConnectorService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

class InventorySkuDetailService
{
    public function __construct(
        private readonly AiConnectorService $ai,
    ) {}

    /**
     * Return the full SKU detail payload for the given inventory ID and date range.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException  when the item is not found
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException          when the date range is invalid (422)
     */
    public function getDetail(string $inventoryId, string $dateFrom, string $dateTo): array
    {
        // 1. Resolve the inventory item — 404 if absent.
        $item = AcumaticaInventoryItem::where('inventory_id', $inventoryId)->first();
        if ($item === null) {
            abort(404, "Inventory item '{$inventoryId}' not found.");
        }

        // 2. Validate date range (7–730 days inclusive).
        $from = Carbon::parse($dateFrom)->startOfDay();
        $to   = Carbon::parse($dateTo)->startOfDay();
        $days = (int) $from->diffInDays($to);

        if ($days < 7 || $days > 730) {
            abort(422, 'Date range must be between 7 and 730 days');
        }

        // 3. Query completed SO lines for this SKU within the date range.
        $lines = AcumaticaSalesOrderLine::query()
            ->join(
                'acumatica_sales_orders',
                'acumatica_sales_orders.id',
                '=',
                'acumatica_sales_order_lines.sales_order_id',
            )
            ->where('acumatica_sales_orders.status', 'Completed')
            ->where('acumatica_sales_orders.order_type', AcumaticaSalesOrder::TYPE_SALES_ORDER)
            ->where('acumatica_sales_order_lines.inventory_id', $inventoryId)
            ->whereBetween('acumatica_sales_orders.order_date', [
                $from->toDateString(),
                $to->toDateString(),
            ])
            ->select([
                'acumatica_sales_order_lines.inventory_id',
                'acumatica_sales_order_lines.shipped_qty',
                'acumatica_sales_orders.order_date',
            ])
            ->get()
            ->toArray();

        // 4. Build the prediction period.
        $predPeriod = $this->predictionPeriod($from, $to);

        // 5. Fetch LLM predictions.
        [$llmPredictions, $aiStatus] = $this->fetchLlmPredictions(
            item: $item,
            historicalLines: $lines,
            predPeriod: $predPeriod,
        );

        // 6. Build the monthly sales array (historical + prediction).
        $monthlySalesData = $this->monthlySales(
            lines: $lines,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            predictionPeriod: [
                'from' => $predPeriod['from']->toDateString(),
                'to'   => $predPeriod['to']->toDateString(),
            ],
            llmPredictions: $llmPredictions,
        );

        return [
            'item'              => $item->toArray(),
            'monthly_sales'     => $monthlySalesData,
            'prediction_period' => [
                'from' => $predPeriod['from']->toDateString(),
                'to'   => $predPeriod['to']->toDateString(),
            ],
            'ai_status'         => $aiStatus,
        ];
    }

    /**
     * Compute prediction period: starts the day after `$to`, same duration as historical.
     *
     * @return array{from: Carbon, to: Carbon}
     */
    public function predictionPeriod(Carbon $from, Carbon $to): array
    {
        $lengthDays = (int) $from->diffInDays($to);

        return [
            'from' => $to->copy()->addDay(),
            'to'   => $to->copy()->addDays(1 + $lengthDays),
        ];
    }

    /**
     * Bucket shipped_qty by calendar month, merging historical actuals with LLM predictions.
     *
     * @param  array<int, array<string, mixed>>    $lines            Raw sales order line rows
     * @param  array{from: string, to: string}     $predictionPeriod
     * @param  array<string, float>                $llmPredictions   Map of YYYY-MM => predicted_qty
     * @return list<array{month: string, month_label: string, shipped_qty: float, predicted_qty: float, is_future: bool}>
     */
    public function monthlySales(
        array $lines,
        string $dateFrom,
        string $dateTo,
        array $predictionPeriod,
        array $llmPredictions,
    ): array {
        // --- Sum shipped_qty by month for historical lines ---
        $historicalBuckets = [];
        foreach ($lines as $line) {
            $orderDate = $line['order_date'] ?? null;
            if ($orderDate === null) {
                continue;
            }
            $month = Carbon::parse($orderDate)->format('Y-m');
            $historicalBuckets[$month] = ($historicalBuckets[$month] ?? 0.0) + (float) ($line['shipped_qty'] ?? 0);
        }

        // --- Build the full ordered month list: historical + prediction ---
        $months = [];

        // Historical months
        $cursor  = Carbon::parse($dateFrom)->startOfMonth();
        $histEnd = Carbon::parse($dateTo)->startOfMonth();
        while ($cursor->lte($histEnd)) {
            $months[$cursor->format('Y-m')] = false; // is_future = false
            $cursor->addMonth();
        }

        // Prediction months (skip any already in historical range)
        $cursor  = Carbon::parse($predictionPeriod['from'])->startOfMonth();
        $predEnd = Carbon::parse($predictionPeriod['to'])->startOfMonth();
        while ($cursor->lte($predEnd)) {
            $key = $cursor->format('Y-m');
            if (! array_key_exists($key, $months)) {
                $months[$key] = true; // is_future = true
            }
            $cursor->addMonth();
        }

        // --- Build result rows ---
        $rows = [];
        foreach ($months as $month => $isFuture) {
            $carbonMonth = Carbon::createFromFormat('Y-m', $month);
            $rows[] = [
                'month'         => $month,
                'month_label'   => $carbonMonth->format('M Y'), // e.g. "Jan 2024"
                'shipped_qty'   => $isFuture ? 0.0 : (float) ($historicalBuckets[$month] ?? 0.0),
                'predicted_qty' => (float) ($llmPredictions[$month] ?? 0.0),
                'is_future'     => $isFuture,
            ];
        }

        return $rows;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Request LLM predictions and return [predictions map, ai_status].
     *
     * @param  array<int, array<string, mixed>>  $historicalLines
     * @param  array{from: Carbon, to: Carbon}   $predPeriod
     * @return array{array<string, float>, string}
     */
    private function fetchLlmPredictions(
        AcumaticaInventoryItem $item,
        array $historicalLines,
        array $predPeriod,
    ): array {
        [$provider, $apiKey] = $this->ai->resolveKey();

        if ($apiKey === null) {
            return [[], 'unavailable'];
        }

        // Build historical monthly summary for the prompt.
        $historicalBuckets = [];
        foreach ($historicalLines as $line) {
            $orderDate = $line['order_date'] ?? null;
            if ($orderDate === null) {
                continue;
            }
            $month = Carbon::parse($orderDate)->format('Y-m');
            $historicalBuckets[$month] = ($historicalBuckets[$month] ?? 0.0) + (float) ($line['shipped_qty'] ?? 0);
        }
        ksort($historicalBuckets);

        // Build the list of prediction months to ask about.
        $predMonths = [];
        $cursor     = $predPeriod['from']->copy()->startOfMonth();
        $predEnd    = $predPeriod['to']->copy()->startOfMonth();
        while ($cursor->lte($predEnd)) {
            $predMonths[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }

        if (empty($predMonths)) {
            return [[], 'unavailable'];
        }

        $system = $this->predictionSystemPrompt();
        $user   = $this->predictionUserPrompt($item, $historicalBuckets, $predMonths);

        try {
            $raw = $provider === 'anthropic'
                ? $this->callAnthropic($apiKey, $system, $user)
                : $this->callOpenAi($apiKey, $system, $user);

            $predictions = $this->parsePredictions($raw, $predMonths);

            return [$predictions, 'success'];
        } catch (Throwable) {
            return [[], 'failed'];
        }
    }

    private function predictionSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a sales forecasting assistant for Kim-Fay OrderWatch (Kenya food distribution).
You receive historical monthly shipped quantity data for a SKU and must predict future monthly quantities.
Return ONLY a valid JSON object mapping "YYYY-MM" month keys to predicted shipped_qty float values.
Example: {"2024-04": 120.5, "2024-05": 135.0}
Base your predictions on the historical trend. Do not include any explanation or markdown — only the JSON object.
PROMPT;
    }

    private function predictionUserPrompt(
        AcumaticaInventoryItem $item,
        array $historicalBuckets,
        array $predMonths,
    ): string {
        $historicalJson = json_encode($historicalBuckets, JSON_PRETTY_PRINT);
        $predMonthsJson = json_encode($predMonths);

        return <<<TEXT
SKU Details:
- inventory_id: {$item->inventory_id}
- description: {$item->description}
- item_class: {$item->item_class}

Historical monthly shipped quantities (YYYY-MM => units):
{$historicalJson}

Please predict the monthly shipped_qty for these future months: {$predMonthsJson}

Return ONLY a JSON object with those month keys mapped to float predicted quantities.
TEXT;
    }

    /**
     * @param  list<string>  $expectedMonths
     * @return array<string, float>
     */
    private function parsePredictions(string $raw, array $expectedMonths): array
    {
        $clean = trim($raw);
        if (str_starts_with($clean, '```')) {
            $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean) ?? $clean;
            $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;
        }

        $decoded = json_decode(trim($clean), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('LLM prediction response was not valid JSON.');
        }

        $result = [];
        foreach ($expectedMonths as $month) {
            if (isset($decoded[$month])) {
                $result[$month] = (float) $decoded[$month];
            }
        }

        return $result;
    }

    private function callOpenAi(string $key, string $system, string $user): string
    {
        $response = Http::withToken($key)
            ->timeout(30)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'           => 'gpt-4o-mini',
                'messages'        => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $user],
                ],
                'max_tokens'      => 800,
                'response_format' => ['type' => 'json_object'],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('OpenAI error: ' . ($response->json('error.message') ?? $response->body()));
        }

        return $response->json('choices.0.message.content') ?? '';
    }

    private function callAnthropic(string $key, string $system, string $user): string
    {
        $response = Http::withHeaders([
            'x-api-key'         => $key,
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout(30)
            ->post('https://api.anthropic.com/v1/messages', [
                'model'      => 'claude-haiku-4-5-20251001',
                'system'     => $system,
                'messages'   => [['role' => 'user', 'content' => $user]],
                'max_tokens' => 800,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Anthropic error: ' . ($response->json('error.message') ?? $response->body()));
        }

        return $response->json('content.0.text') ?? '';
    }
}
