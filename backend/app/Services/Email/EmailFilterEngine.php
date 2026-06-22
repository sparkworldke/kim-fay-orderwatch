<?php

namespace App\Services\Email;

use App\Models\Email;
use App\Models\EmailFilter;

class EmailFilterEngine
{
    /**
     * Count emails in the DB that match ALL conditions on the filter (AND logic).
     */
    public function countMatching(EmailFilter $filter): int
    {
        $conditions = $this->normalizeConditions($filter);
        if (empty($conditions)) return 0;

        $query = Email::query();

        foreach ($conditions as $condition) {
            $type  = $condition['type']  ?? '';
            $value = $condition['value'] ?? '';

            match ($type) {
                'sender_email' => $query->whereRaw('LOWER(from_email) = ?', [strtolower($value)]),

                'sender_domain' => $query->whereRaw('LOWER(from_email) LIKE ?', ['%@' . strtolower($value)]),

                'subject_keyword' => $query->whereRaw('LOWER(subject) LIKE ?', ['%' . strtolower($value) . '%']),

                'received_date' => $query->whereDate('received_at', $value),

                'date_range' => $this->applyDateRange($query, $value),

                default => null,
            };
        }

        return $query->count();
    }

    /**
     * Test whether a single email array matches ALL conditions (AND logic).
     */
    public function matchesFilter(array $email, EmailFilter $filter): bool
    {
        $conditions = $this->normalizeConditions($filter);
        if (empty($conditions)) return false;

        foreach ($conditions as $condition) {
            if (! $this->matchesCondition($email, $condition['type'] ?? '', $condition['value'] ?? '')) {
                return false;
            }
        }

        return true;
    }

    private function matchesCondition(array $email, string $type, string $value): bool
    {
        $fromEmail  = strtolower($email['from_email'] ?? '');
        $subject    = strtolower($email['subject'] ?? '');
        $receivedAt = $email['received_at'] ?? null;

        return match ($type) {
            'sender_email'    => $fromEmail === strtolower($value),
            'sender_domain'   => str_ends_with($fromEmail, '@' . strtolower($value)),
            'subject_keyword' => str_contains($subject, strtolower($value)),

            'received_date' => $receivedAt !== null &&
                date('Y-m-d', strtotime($receivedAt)) === $value,

            'date_range' => (function () use ($receivedAt, $value) {
                if (! $receivedAt) return false;
                [$from, $to] = array_pad(explode('|', $value, 2), 2, null);
                if (! $from || ! $to) return false;
                $date = date('Y-m-d', strtotime($receivedAt));
                return $date >= $from && $date <= $to;
            })(),

            default => false,
        };
    }

    private function applyDateRange(\Illuminate\Database\Eloquent\Builder $query, string $value): void
    {
        [$from, $to] = array_pad(explode('|', $value, 2), 2, null);
        if ($from && $to) {
            $query->whereDate('received_at', '>=', $from)
                  ->whereDate('received_at', '<=', $to);
        }
    }

    private function normalizeConditions(EmailFilter $filter): array
    {
        $conditions = $filter->conditions ?? [];

        if (! empty($conditions)) {
            return $conditions;
        }

        if (! empty($filter->type)) {
            return [[
                'type' => $filter->type,
                'value' => $filter->value ?? '',
            ]];
        }

        return [];
    }
}
