<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use App\Models\InventorySkuInsight;
use App\Services\Admin\AcumaticaClient;
use App\Services\Admin\AiConnectorService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

class InventoryInsightService
{
    public function __construct(
        private readonly AiConnectorService $ai,
        private readonly AcumaticaClient $acumatica,
    ) {}

    public function getInsights(string $inventoryId, string $dateFrom, string $dateTo): array
    {
        $dateFrom = Carbon::parse($dateFrom)->toDateString();
        $dateTo = Carbon::parse($dateTo)->toDateString();

        // Check cache first
        $cached = InventorySkuInsight::where('inventory_id', $inventoryId)
            ->where('date_from', $dateFrom)
            ->where('date_to', $dateTo)
            ->where('expires_at', '>', now())
            ->first();

        if ($cached) {
            return $cached->ai_response;
        }

        // Fetch required data
        $item = AcumaticaInventoryItem::where('inventory_id', $inventoryId)->first();
        if (! $item) {
            $response = [
                'insights' => [],
                'data_gaps' => ['inventory_item'],
                'ai_status' => 'unavailable',
            ];
            $this->cacheResponse($inventoryId, $dateFrom, $dateTo, $response, 'unavailable', ['inventory_item']);
            return $response;
        }

        $dataGaps = [];

        // Try to get promotions from Acumatica
        $promotions = [];
        try {
            $promotions = $this->fetchPromotions($inventoryId, $dateFrom, $dateTo);
        } catch (Throwable) {
            $dataGaps[] = 'promotions';
        }

        // Try to get price changes from Acumatica
        $priceChanges = [];
        try {
            $priceChanges = $this->fetchPriceChanges($inventoryId, $dateFrom, $dateTo);
        } catch (Throwable) {
            $dataGaps[] = 'price_history';
        }

        // Get monthly sales data to calculate variances
        $monthlySales = $this->getMonthlySales($inventoryId, $dateFrom, $dateTo);

        // Call AI
        try {
            $aiResponse = $this->callAiForInsights($item, $monthlySales, $promotions, $priceChanges);
            $this->cacheResponse($inventoryId, $dateFrom, $dateTo, $aiResponse, 'success', $dataGaps);
            return $aiResponse;
        } catch (Throwable $e) {
            $response = [
                'insights' => [],
                'data_gaps' => $dataGaps,
                'ai_status' => 'failed',
            ];
            $this->cacheResponse($inventoryId, $dateFrom, $dateTo, $response, 'failed', $dataGaps);
            return $response;
        }
    }

    private function cacheResponse(string $inventoryId, string $dateFrom, string $dateTo, array $response, string $aiStatus, array $dataGaps): void
    {
        InventorySkuInsight::updateOrCreate(
            [
                'inventory_id' => $inventoryId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            [
                'ai_response' => $response,
                'ai_status' => $aiStatus,
                'data_gaps' => $dataGaps,
                'generated_at' => now(),
                'expires_at' => now()->addHours(4),
            ],
        );
    }

    private function fetchPromotions(string $inventoryId, string $dateFrom, string $dateTo): array
    {
        // Since we don't have a specific promotions endpoint yet, return empty array
        // In a real implementation, you would query Acumatica's Promotions or Price Schedule endpoints
        return [];
    }

    private function fetchPriceChanges(string $inventoryId, string $dateFrom, string $dateTo): array
    {
        // Since we don't have a specific price history endpoint yet, return empty array
        return [];
    }

    private function getMonthlySales(string $inventoryId, string $dateFrom, string $dateTo): array
    {
        $from = Carbon::parse($dateFrom)->startOfDay();
        $to = Carbon::parse($dateTo)->endOfDay();

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
            ->whereBetween('acumatica_sales_orders.order_date', [$from, $to])
            ->select([
                'acumatica_sales_order_lines.shipped_qty',
                'acumatica_sales_orders.order_date',
            ])
            ->get();

        $monthly = [];
        foreach ($lines as $line) {
            $month = Carbon::parse($line->order_date)->format('Y-m');
            $monthly[$month] = ($monthly[$month] ?? 0) + (float) ($line->shipped_qty ?? 0);
        }

        return $monthly;
    }

    private function callAiForInsights(
        AcumaticaInventoryItem $item,
        array $monthlySales,
        array $promotions,
        array $priceChanges,
    ): array {
        [$provider, $apiKey] = $this->ai->resolveKey();

        if ($apiKey === null) {
            return [
                'insights' => [],
                'data_gaps' => ['ai_api_key'],
                'ai_status' => 'unavailable',
            ];
        }

        // Build prompt
        $systemPrompt = <<<'PROMPT'
You are a sales insight generator for Kim-Fay OrderWatch. Your job is to analyze sales data for a single inventory item and generate insights about performance variances.

Input:
- Monthly sales data (actual units sold per month)
- Promotions active during the period (if any)
- Price changes during the period (if any)

Output format (JSON only, no markdown):
{
  "insights": [
    {
      "type": "promotion_impact" | "price_change_impact" | "unexplained_variance",
      "month": "YYYY-MM",
      "text": "Human-readable explanation of what happened and why",
      "promotion_name": "Name of promotion (only for promotion_impact type)",
      "variance_pct": 15.5, // percentage variance from expected
      "variance_abs": 25, // absolute unit variance
      "price_direction": "upward" | "downward", // only for price_change_impact
      "price_magnitude": 5.0 // only for price_change_impact
    }
  ],
  "data_gaps": [],
  "ai_status": "success"
}

If you don't have promotions or price change data, focus on unexplained variances.
PROMPT;

        $userPrompt = <<<TEXT
SKU: {$item->inventory_id}
Description: {$item->description}
Item Class: {$item->item_class}

Monthly Sales Data:
{$this->formatMonthlySalesForPrompt($monthlySales)}

Promotions:
{$this->formatArrayForPrompt($promotions)}

Price Changes:
{$this->formatArrayForPrompt($priceChanges)}
TEXT;

        $rawResponse = $provider === 'anthropic'
            ? $this->callAnthropic($apiKey, $systemPrompt, $userPrompt)
            : $this->callOpenAi($apiKey, $systemPrompt, $userPrompt);

        // Parse and validate
        $decoded = json_decode($rawResponse, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Invalid AI response');
        }

        // Ensure required fields exist
        $decoded['insights'] = $decoded['insights'] ?? [];
        $decoded['data_gaps'] = $decoded['data_gaps'] ?? [];
        $decoded['ai_status'] = $decoded['ai_status'] ?? 'success';

        // Enforce finding type contract (Property 13) — normalise unknown types.
        $decoded['insights'] = $this->validateFindingTypes($decoded['insights']);

        return $decoded;
    }

    private function formatMonthlySalesForPrompt(array $monthlySales): string
    {
        if (empty($monthlySales)) {
            return '(no sales data)';
        }

        $lines = [];
        foreach ($monthlySales as $month => $qty) {
            $lines[] = "- {$month}: {$qty} units";
        }
        return implode("\n", $lines);
    }

    private function formatArrayForPrompt(array $arr): string
    {
        if (empty($arr)) {
            return '(no data)';
        }
        return json_encode($arr, JSON_PRETTY_PRINT);
    }

    private function callOpenAi(string $apiKey, string $systemPrompt, string $userPrompt): string
    {
        $response = Http::withToken($apiKey)
            ->timeout(30)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'           => 'gpt-4o-mini',
                'messages'        => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userPrompt],
                ],
                'max_tokens'      => 1000,
                'response_format' => ['type' => 'json_object'],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('OpenAI error: ' . ($response->json('error.message') ?? $response->body()));
        }

        return $response->json('choices.0.message.content') ?? '';
    }

    private function callAnthropic(string $apiKey, string $systemPrompt, string $userPrompt): string
    {
        $response = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout(30)
            ->post('https://api.anthropic.com/v1/messages', [
                'model'      => 'claude-haiku-4-5-20251001',
                'system'     => $systemPrompt,
                'messages'   => [['role' => 'user', 'content' => $userPrompt]],
                'max_tokens' => 1000,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Anthropic error: ' . ($response->json('error.message') ?? $response->body()));
        }

        return $response->json('content.0.text') ?? '';
    }

    /**
     * Classify the demand-pressure effect of a price change (Property 12).
     *
     * @return string|null "upward pressure on demand", "downward pressure on demand", or null when unchanged.
     */
    public function classifyPriceChange(float $oldPrice, float $newPrice): ?string
    {
        if ($newPrice < $oldPrice) {
            return 'upward pressure on demand';
        } elseif ($newPrice > $oldPrice) {
            return 'downward pressure on demand';
        }

        return null;
    }

    /**
     * Map a classified pressure phrase to the short direction used in the response type.
     */
    public function priceDirection(float $oldPrice, float $newPrice): ?string
    {
        if ($newPrice < $oldPrice) {
            return 'upward';
        } elseif ($newPrice > $oldPrice) {
            return 'downward';
        }

        return null;
    }

    /**
     * Build the combined context string sent to the LLM (Property 12 & 13 helpers).
     *
     * @param  array<string, float>                    $monthlyActuals  YYYY-MM => shipped qty
     * @param  array<string, float>                    $monthlyPredicted YYYY-MM => predicted qty
     * @param  list<array<string, mixed>>              $promotions
     * @param  list<array<string, mixed>>              $priceChanges
     */
    public function buildInsightContext(array $monthlyActuals, array $monthlyPredicted, array $promotions, array $priceChanges): string
    {
        // Compute per-month variances for months where a prediction exists.
        $varianceLines = [];
        foreach ($monthlyPredicted as $month => $predicted) {
            $actual = $monthlyActuals[$month] ?? 0.0;
            if ($predicted > 0) {
                $variancePct = (($actual - $predicted) / $predicted) * 100;
            } else {
                $variancePct = 0.0;
            }
            $varianceLines[] = sprintf(
                '- %s: actual=%.1f, predicted=%.1f, variance_pct=%.1f%%, variance_abs=%.1f',
                $month,
                $actual,
                $predicted,
                $variancePct,
                $actual - $predicted,
            );
        }

        $sections = [
            'Monthly Variance Data:' => implode("\n", $varianceLines) ?: '(no variance data)',
            'Promotions:'            => $this->formatArrayForPrompt($promotions),
            'Price Changes:'         => $this->formatArrayForPrompt($priceChanges),
        ];

        $out = [];
        foreach ($sections as $heading => $body) {
            $out[] = $heading;
            $out[] = $body;
            $out[] = '';
        }

        return implode("\n", $out);
    }

    /**
     * Validate that every finding has a legal type (Property 13). Unknown types
     * are normalised to "unexplained_variance" so the contract always holds.
     *
     * @param  list<array<string, mixed>>  $insights
     * @return list<array<string, mixed>>
     */
    private function validateFindingTypes(array $insights): array
    {
        $valid = ['promotion_impact', 'price_change_impact', 'unexplained_variance'];

        return array_values(array_map(function (array $finding) use ($valid): array {
            $type = $finding['type'] ?? null;
            if (! is_string($type) || ! in_array($type, $valid, true)) {
                $finding['type'] = 'unexplained_variance';
            }

            return $finding;
        }, $insights));
    }
}
