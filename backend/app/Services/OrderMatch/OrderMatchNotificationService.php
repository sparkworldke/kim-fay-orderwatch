<?php

namespace App\Services\OrderMatch;

use App\Models\Email;
use App\Support\FrontendUrl;
use App\Models\NotificationDispatchLog;
use App\Models\NotificationRule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OrderMatchNotificationService
{
    private const DEDUP_MINUTES = 60;
    private const QUEUE_THRESHOLD = 20;

    public function __construct(
        private readonly OrderMatchQueueService $queue,
        private readonly OrderMatchPoNormalizer $normalizer,
    ) {
    }

    public function evaluateAll(): array
    {
        $results = [];

        try {
            $results['R5'] = $this->evaluateQueueBacklog();
            $results['R6'] = $this->evaluateDuplicatePos();
        } catch (\Throwable $e) {
            Log::error('order_match_notification_evaluation_failed', ['error' => $e->getMessage()]);

            return ['error' => $e->getMessage()];
        }

        return $results;
    }

    private function evaluateQueueBacklog(): array
    {
        $rule = NotificationRule::where('rule_key', 'R5')->first();
        if (! $rule?->is_enabled) {
            return ['skipped' => 'disabled'];
        }

        if ($this->recentlyTriggered($rule)) {
            return ['skipped' => 'dedup_window'];
        }

        $count = $this->queue->pendingCount();
        if ($count <= self::QUEUE_THRESHOLD) {
            $rule->update(['last_evaluated_at' => now()]);

            return ['skipped' => 'below_threshold', 'count' => $count];
        }

        $period = $this->periodLabel();
        $subject = "[Order Match] Review queue: {$count} emails awaiting match — {$period}";
        $mailboxUrl = FrontendUrl::path('/app/mailbox');
        $body = "The Order Match review queue has {$count} pending emails for period {$period}.\n\nRecommended action: Review backorder queue and clear high-confidence auto-match entries.\n\nOpen mailbox: {$mailboxUrl}";

        $this->dispatch($rule, $subject, $body, $this->adminRecipients());

        return ['triggered' => true, 'count' => $count];
    }

    private function evaluateDuplicatePos(): array
    {
        $rule = NotificationRule::where('rule_key', 'R6')->first();
        if (! $rule?->is_enabled) {
            return ['skipped' => 'disabled'];
        }

        if ($this->recentlyTriggered($rule)) {
            return ['skipped' => 'dedup_window'];
        }

        $duplicates = Email::query()
            ->where('duplicate_flag', 'duplicate')
            ->whereNull('reviewer_decision')
            ->where('updated_at', '>=', now()->subDay())
            ->get()
            ->groupBy(fn (Email $e) => $this->normalizer->normalise($e->canonical_po ?? $e->extracted_po_number));

        $triggered = [];
        foreach ($duplicates as $po => $group) {
            if (! $po || $group->count() < 2) {
                continue;
            }

            $period = $this->periodLabel();
            $n = $group->count();
            $subject = "[Order Match] Duplicate PO detected: {$po} — {$n} emails — {$period}";
            $mailboxUrl = FrontendUrl::path('/app/mailbox');
            $body = "Duplicate PO {$po} appears on {$n} emails in period {$period}.\n\nRecommended action: Review backorder queue and nominate canonical email before accepting matches.\n\nOpen mailbox: {$mailboxUrl}";

            $this->dispatch($rule, $subject, $body, $this->opsRecipients());
            $triggered[] = ['po' => $po, 'count' => $n];
        }

        if ($triggered === []) {
            $rule->update(['last_evaluated_at' => now()]);

            return ['skipped' => 'no_duplicates'];
        }

        return ['triggered' => true, 'duplicates' => $triggered];
    }

    private function recentlyTriggered(NotificationRule $rule): bool
    {
        if (! $rule->last_triggered_at) {
            return false;
        }

        return $rule->last_triggered_at->gt(now()->subMinutes(self::DEDUP_MINUTES));
    }

    /** @param  list<string>  $recipients */
    private function dispatch(NotificationRule $rule, string $subject, string $body, array $recipients): void
    {
        if ($recipients === []) {
            Log::warning('order_match_notification_no_recipients', ['rule' => $rule->rule_key]);

            return;
        }

        foreach ($recipients as $email) {
            try {
                Mail::raw($body, function ($message) use ($email, $subject) {
                    $message->to($email)->subject($subject);
                });

                NotificationDispatchLog::create([
                    'rule_id'             => $rule->id,
                    'evaluated_at'        => now(),
                    'channel'             => 'email',
                    'recipient_user_id'   => User::where('email', $email)->value('id'),
                    'delivery_status'     => 'sent',
                ]);
            } catch (\Throwable $e) {
                NotificationDispatchLog::create([
                    'rule_id'           => $rule->id,
                    'evaluated_at'      => now(),
                    'channel'           => 'email',
                    'delivery_status'   => 'failed',
                ]);
                Log::error('order_match_notification_send_failed', ['to' => $email, 'error' => $e->getMessage()]);
            }
        }

        $rule->update(['last_evaluated_at' => now(), 'last_triggered_at' => now()]);
    }

    /** @return list<string> */
    private function adminRecipients(): array
    {
        $fromEnv = env('EMAIL_OPS_LIST', env('EMAIL_EXEC_LIST', ''));
        if ($fromEnv) {
            return array_values(array_filter(array_map('trim', explode(',', $fromEnv))));
        }

        return User::where('role', 'Administrator')->pluck('email')->filter()->all();
    }

    /** @return list<string> */
    private function opsRecipients(): array
    {
        $fromEnv = env('EMAIL_OPS_LIST', '');
        if ($fromEnv) {
            return array_values(array_filter(array_map('trim', explode(',', $fromEnv))));
        }

        return User::whereIn('role', ['Administrator', 'Customer Service Manager'])->pluck('email')->filter()->all();
    }

    private function periodLabel(): string
    {
        $to = Carbon::now('Africa/Nairobi')->toDateString();
        $from = Carbon::now('Africa/Nairobi')->subDays(7)->toDateString();

        return "{$from} to {$to}";
    }
}