<?php

namespace App\Services\Admin;

use App\Mail\NotificationRulesConfigMail;
use App\Models\NotificationRule;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationRulesConfigMailService
{
    public const RECIPIENT = 'commercialtechlead@kimfay.com';

    private const RULE_KEYS = ['R1', 'R2', 'R3', 'R4', 'R5', 'R6'];

    /** @return array{recipient: string, body: string, rule_count: int} */
    public function send(): array
    {
        $rules = NotificationRule::query()
            ->whereIn('rule_key', self::RULE_KEYS)
            ->orderBy('rule_key')
            ->get();

        $this->assertExpectedRulesPresent($rules);

        $body = $this->buildBody($rules);

        Mail::to(self::RECIPIENT)->send(new NotificationRulesConfigMail(
            'OrderWatch Notification Rules Configuration',
            $body,
        ));

        Log::info('notification_rules_config_sent', [
            'to' => self::RECIPIENT,
            'rule_count' => $rules->count(),
        ]);

        return [
            'recipient' => self::RECIPIENT,
            'body' => $body,
            'rule_count' => $rules->count(),
        ];
    }

    /** @param  Collection<int, NotificationRule>  $rules */
    public function buildBody(Collection $rules): string
    {
        $lines = ['## Notification Rules'];

        foreach ($rules->values() as $index => $rule) {
            $number = $index + 1;
            $lines[] = "{$number}. {$rule->rule_key} - {$rule->label}";
            $lines[] = '   - Alert channels: '.$this->formatChannels($rule->channels ?? []);
            $lines[] = '   - Last evaluated: '.$this->formatTimestamp($rule->last_evaluated_at);
            $lines[] = '   - Last triggered: '.$this->formatTimestamp($rule->last_triggered_at);
        }

        return implode("\n", $lines);
    }

    /** @param  list<string>  $channels */
    public function formatChannels(array $channels): string
    {
        $labels = array_map(fn (string $channel): string => match ($channel) {
            'email' => 'Email',
            'in_app' => 'In-App',
            default => ucfirst(str_replace('_', ' ', $channel)),
        }, $channels);

        return implode(', ', $labels);
    }

    public function formatTimestamp(?CarbonInterface $value): string
    {
        if ($value === null) {
            return 'Never';
        }

        return $value->copy()->timezone('Africa/Nairobi')->format('d/m/Y, H:i:s');
    }

    /** @param  Collection<int, NotificationRule>  $rules */
    private function assertExpectedRulesPresent(Collection $rules): void
    {
        $found = $rules->pluck('rule_key')->all();
        $missing = array_values(array_diff(self::RULE_KEYS, $found));

        if ($missing !== []) {
            throw new \RuntimeException('Missing notification rules: '.implode(', ', $missing));
        }
    }
}
