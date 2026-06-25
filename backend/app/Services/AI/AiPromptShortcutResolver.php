<?php

namespace App\Services\AI;

use Illuminate\Support\Carbon;

class AiPromptShortcutResolver
{
    private const DOMAIN_TAGS = [
        'orders' => ['orders'],
        'completed' => ['orders'],
        'uncaptured' => ['orders'],
        'revenue' => ['orders'],
        'emails' => ['emails'],
        'matches' => ['emails', 'matches'],
        'customers' => ['customers', 'orders'],
        'cron' => ['cron'],
        'risk' => ['orders', 'emails', 'matches'],
        'compare' => ['orders', 'emails', 'matches'],
        'summary' => ['orders', 'emails', 'matches'],
    ];

    /**
     * @return array{
     *   tags: list<string>,
     *   date_from: string|null,
     *   date_to: string|null,
     *   period_label: string|null,
     *   domain_hints: list<string>,
     *   context_lines: list<string>
     * }
     */
    public function resolve(string $prompt, string $timezone = 'Africa/Nairobi'): array
    {
        $tags = $this->extractTags($prompt);
        $now = now($timezone);
        $dateRange = $this->resolveDateRange($tags, $now);
        $domainHints = $this->resolveDomains($tags);

        $contextLines = [];
        if ($dateRange['label']) {
            $contextLines[] = sprintf(
                'Tagged period: %s (%s to %s)',
                $dateRange['label'],
                Carbon::parse($dateRange['from'])->format('d M Y'),
                Carbon::parse($dateRange['to'])->format('d M Y'),
            );
        }
        if ($domainHints !== []) {
            $contextLines[] = 'Tagged focus: '.implode(', ', $domainHints);
        }
        if ($tags !== []) {
            $contextLines[] = 'Shortcuts detected: '.implode(', ', array_map(fn ($t) => '@'.$t, $tags));
        }

        return [
            'tags' => $tags,
            'date_from' => $dateRange['from'],
            'date_to' => $dateRange['to'],
            'period_label' => $dateRange['label'],
            'domain_hints' => $domainHints,
            'context_lines' => $contextLines,
        ];
    }

    /** @return list<string> */
    private function extractTags(string $prompt): array
    {
        preg_match_all('/(?:^|[\s])[\/@]([a-z][a-z0-9_-]*)/i', $prompt, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $tag) => strtolower($tag))
            ->unique()
            ->values()
            ->all();
    }

    /** @param  list<string>  $tags
     * @return array{from: string|null, to: string|null, label: string|null}
     */
    private function resolveDateRange(array $tags, Carbon $now): array
    {
        $dateTags = ['today', 'yesterday', 'mtd', 'last-week', 'last-month', 'ytd'];
        $active = collect($tags)->first(fn (string $tag) => in_array($tag, $dateTags, true));

        if (! $active) {
            return ['from' => null, 'to' => null, 'label' => null];
        }

        return match ($active) {
            'today' => [
                'from' => $now->copy()->startOfDay()->toDateTimeString(),
                'to' => $now->copy()->endOfDay()->toDateTimeString(),
                'label' => 'Today',
            ],
            'yesterday' => [
                'from' => $now->copy()->subDay()->startOfDay()->toDateTimeString(),
                'to' => $now->copy()->subDay()->endOfDay()->toDateTimeString(),
                'label' => 'Yesterday',
            ],
            'mtd' => [
                'from' => $now->copy()->startOfMonth()->startOfDay()->toDateTimeString(),
                'to' => $now->copy()->endOfDay()->toDateTimeString(),
                'label' => 'MTD',
            ],
            'last-week' => [
                'from' => $now->copy()->subDays(6)->startOfDay()->toDateTimeString(),
                'to' => $now->copy()->endOfDay()->toDateTimeString(),
                'label' => 'Last 7 days',
            ],
            'last-month' => [
                'from' => $now->copy()->subMonth()->startOfMonth()->startOfDay()->toDateTimeString(),
                'to' => $now->copy()->subMonth()->endOfMonth()->endOfDay()->toDateTimeString(),
                'label' => 'Last month',
            ],
            'ytd' => [
                'from' => $now->copy()->startOfYear()->startOfDay()->toDateTimeString(),
                'to' => $now->copy()->endOfDay()->toDateTimeString(),
                'label' => 'YTD',
            ],
            default => ['from' => null, 'to' => null, 'label' => null],
        };
    }

    /** @param  list<string>  $tags
     * @return list<string>
     */
    private function resolveDomains(array $tags): array
    {
        $domains = [];
        foreach ($tags as $tag) {
            foreach (self::DOMAIN_TAGS[$tag] ?? [] as $domain) {
                $domains[$domain] = true;
            }
        }

        return array_keys($domains);
    }
}