<?php

namespace App\Services\OrderMatch;

use App\Models\AcumaticaSalesOrder;
use App\Models\AiPromptLog;
use App\Models\Email;
use App\Models\MatchPrediction;
use App\Services\Admin\AiConnectorService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class OrderMatchAiMatchingService
{
    private const MAX_CANDIDATES = 20;
    private const MAX_AI_RETRIES = 2;
    private const RATE_LIMIT_KEY = 'order-match-ai';

    public function __construct(
        private readonly AiConnectorService $ai,
        private readonly OrderMatchPoNormalizer $normalizer,
        private readonly CustomerPoMatchResolver $poResolver,
    ) {
    }

    public function score(Email $email): ?MatchPrediction
    {
        if (! RateLimiter::attempt(self::RATE_LIMIT_KEY, 60, fn () => true)) {
            Log::warning('order_match_ai_rate_limited', ['email_id' => $email->id]);

            return null;
        }

        $po = $this->normalizer->normalise($email->canonical_po ?? $email->extracted_po_number);
        if (! $po) {
            return $this->storeNoMatch($email, 'No canonical PO for scoring');
        }

        $lookupKeys = $this->poResolver->acumaticaLookupKeys(
            $po,
            $email->from_email,
            $email->mailboxFolder?->customer?->name,
            $email->subject,
        );

        $customerName = $email->mailboxFolder?->customer?->name;
        $isNaivas = $this->poResolver->isNaivas($email->from_email, $customerName);
        $isCarrefour = $this->poResolver->isCarrefour($email->from_email, $customerName);
        $isQuickmart = $this->poResolver->isQuickmart($email->from_email, $customerName);
        $isChandarana = $this->poResolver->isChandarana($email->from_email, $customerName);

        $exact = null;
        if (($isNaivas || $isCarrefour || $isQuickmart || $isChandarana) && $lookupKeys !== []) {
            $exact = AcumaticaSalesOrder::salesOrdersOnly()
                ->whereNotNull('customer_order')
                ->orderByDesc('order_date')
                ->get()
                ->first(function ($order) use ($lookupKeys, $email) {
                    foreach ($lookupKeys as $key) {
                        if ($this->poResolver->customerOrderMatchesCanonical(
                            $order->customer_order,
                            $key,
                            $email->from_email,
                            $email->mailboxFolder?->customer?->name,
                        )) {
                            return true;
                        }
                    }

                    return false;
                });
        } else {
            foreach ($lookupKeys as $key) {
                $exact = AcumaticaSalesOrder::salesOrdersOnly()
                    ->whereRaw('UPPER(TRIM(customer_order)) = ?', [strtoupper(trim($key))])
                    ->orderByDesc('order_date')
                    ->first();
                if ($exact) {
                    break;
                }
            }
        }

        if ($exact) {
            $reason = $lookupKeys[0] !== $po
                ? "Exact CustomerOrder match via customer guardrail ({$po} → {$lookupKeys[0]})"
                : 'Exact CustomerPONbr match';

            return $this->storePrediction($email, $exact, 1.0, 'exact', $reason);
        }

        $excludedOnly = null;
        foreach ($lookupKeys as $key) {
            $excludedOnly = AcumaticaSalesOrder::query()
                ->whereIn('order_type', [AcumaticaSalesOrder::TYPE_QUOTE, 'QO', AcumaticaSalesOrder::TYPE_CREDIT_NOTE])
                ->whereRaw('UPPER(TRIM(customer_order)) = ?', [strtoupper(trim($key))])
                ->orderByDesc('order_date')
                ->first();
            if ($excludedOnly) {
                break;
            }
        }

        if ($excludedOnly) {
            return $this->storeNoMatch($email, "excluded_order_type: {$excludedOnly->order_type}");
        }

        $candidates = $this->fetchCandidates($email);
        if ($candidates->isEmpty()) {
            return $this->storeNoMatch($email, 'No open sales order candidates');
        }

        $aiResult = $this->callAi($email, $po, $candidates);
        if (! $aiResult) {
            return $this->storeNoMatch($email, 'AI scoring unavailable or parse failure');
        }

        $order = $candidates->firstWhere('acumatica_order_nbr', $aiResult['order_nbr'] ?? '');
        if (! $order) {
            return $this->storeNoMatch($email, 'AI returned unknown order');
        }

        $confidence = min(0.94, (float) ($aiResult['confidence'] ?? 0));
        $matchType = ($aiResult['match_type'] ?? 'semantic') === 'fuzzy' ? 'fuzzy' : 'semantic';

        return $this->storePrediction($email, $order, $confidence, $matchType, $aiResult['reasoning'] ?? null, $candidates);
    }

    /** @return Collection<int, AcumaticaSalesOrder> */
    private function fetchCandidates(Email $email): Collection
    {
        $query = AcumaticaSalesOrder::query()
            ->salesOrdersOnly()
            ->whereNotIn('status', ['Completed', 'Cancelled'])
            ->orderByDesc('order_date');

        $customerId = $email->mailboxFolder?->customer?->acumatica_id;
        if ($customerId) {
            $query->where('customer_acumatica_id', $customerId);
        }

        return $query->limit(self::MAX_CANDIDATES)->get();
    }

    /** @param  Collection<int, AcumaticaSalesOrder>  $candidates */
    private function callAi(Email $email, string $po, Collection $candidates): ?array
    {
        [$provider, $key] = $this->ai->resolveKey(['anthropic', 'openai']);
        if (! $key) {
            return null;
        }

        $bodyPreview = mb_substr($email->body_preview ?? '', 0, 255);
        $candidateLines = $candidates->map(fn ($o) => sprintf(
            '- %s | Customer: %s | PO: %s | Total: %s %s | Date: %s',
            $o->acumatica_order_nbr,
            $o->customer_name ?? $o->customer_acumatica_id,
            $o->customer_order,
            $o->order_total,
            $o->currency_id,
            $o->order_date?->format('Y-m-d'),
        ))->implode("\n");

        $system = 'You score email-to-sales-order matches. Respond ONLY with valid JSON: {"order_nbr":"SO123","confidence":0.0-1.0,"match_type":"fuzzy|semantic|no_match","reasoning":"one sentence"}.';
        $user = "Email PO: {$po}\nSubject: {$email->subject}\nPreview: {$bodyPreview}\n\nCandidates:\n{$candidateLines}";

        $started = microtime(true);
        $attempts = 0;
        $parsed = null;

        while ($attempts < self::MAX_AI_RETRIES && $parsed === null) {
            $attempts++;
            try {
                $raw = $provider === 'anthropic'
                    ? $this->callAnthropic($key, $system, $user)
                    : $this->callOpenAi($key, $system, $user);

                $parsed = $this->parseJson($raw);
            } catch (\Throwable $e) {
                Log::warning('order_match_ai_attempt_failed', ['attempt' => $attempts, 'error' => $e->getMessage()]);
            }
        }

        AiPromptLog::create([
            'provider'         => $provider,
            'intent'           => 'order_match_score',
            'prompt'           => mb_substr($user, 0, 2000),
            'ai_message'       => mb_substr(json_encode($parsed) ?: 'null', 0, 500),
            'response_time_ms' => (int) ((microtime(true) - $started) * 1000),
            'status'           => $parsed ? 'success' : 'failed',
            'error_message'    => $parsed ? null : 'parse_failure_or_retries_exhausted',
        ]);

        return $parsed;
    }

    private function callAnthropic(string $key, string $system, string $user): string
    {
        $response = Http::withHeaders([
            'x-api-key'         => $key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(45)->post('https://api.anthropic.com/v1/messages', [
            'model'      => 'claude-sonnet-4-20250514',
            'max_tokens' => 300,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $user]],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Anthropic error '.$response->status());
        }

        return collect($response->json('content'))->where('type', 'text')->pluck('text')->implode('');
    }

    private function callOpenAi(string $key, string $system, string $user): string
    {
        $response = Http::withToken($key)->timeout(45)->post('https://api.openai.com/v1/chat/completions', [
            'model'    => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'max_tokens' => 300,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('OpenAI error '.$response->status());
        }

        return (string) $response->json('choices.0.message.content', '');
    }

    private function parseJson(string $raw): ?array
    {
        $trimmed = trim($raw);
        if (preg_match('/\{.*\}/s', $trimmed, $m)) {
            $trimmed = $m[0];
        }
        $data = json_decode($trimmed, true);

        return is_array($data) ? $data : null;
    }

    /** @param  Collection<int, AcumaticaSalesOrder>|null  $all */
    private function storePrediction(
        Email $email,
        ?AcumaticaSalesOrder $order,
        float $confidence,
        string $matchType,
        ?string $reasoning,
        ?Collection $all = null,
    ): MatchPrediction {
        MatchPrediction::where('email_id', $email->id)->delete();

        $top = null;
        if ($all) {
            $rank = 0;
            foreach ($all as $candidate) {
                $isTop = $candidate->id === $order?->id;
                $row = MatchPrediction::create([
                    'email_id'          => $email->id,
                    'order_id'          => $candidate->id,
                    'order_nbr'         => $candidate->acumatica_order_nbr,
                    'confidence'        => $isTop ? $confidence : 0,
                    'match_type'        => $isTop ? $matchType : 'no_match',
                    'reasoning'         => $isTop ? $reasoning : null,
                    'is_top_prediction' => $isTop,
                    'rank'              => $rank++,
                ]);
                if ($isTop) {
                    $top = $row;
                }
            }
        } else {
            $top = MatchPrediction::create([
                'email_id'          => $email->id,
                'order_id'          => $order?->id,
                'order_nbr'         => $order?->acumatica_order_nbr,
                'confidence'        => $confidence,
                'match_type'        => $matchType,
                'reasoning'         => $reasoning,
                'is_top_prediction' => true,
                'rank'              => 0,
            ]);
        }

        $email->update(['match_status' => $this->statusLabel($confidence, $matchType, $email)]);

        return $top ?? MatchPrediction::where('email_id', $email->id)->where('is_top_prediction', true)->first();
    }

    private function storeNoMatch(Email $email, string $reason): MatchPrediction
    {
        MatchPrediction::where('email_id', $email->id)->delete();
        $email->update(['match_status' => 'no_match']);

        return MatchPrediction::create([
            'email_id'          => $email->id,
            'confidence'        => 0,
            'match_type'        => 'no_match',
            'reasoning'         => $reason,
            'is_top_prediction' => true,
        ]);
    }

    private function statusLabel(float $confidence, string $matchType, Email $email): string
    {
        $extraction = ($email->po_extraction_confidence ?? 0) / 100;
        if ($matchType === 'exact' && $confidence >= 0.95 && $extraction >= 0.95) {
            return 'auto_matched';
        }

        if ($confidence >= 0.95) {
            return 'pending';
        }

        return 'pending';
    }
}