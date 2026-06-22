<?php

namespace App\Services\Email;

use App\Models\Email;
use App\Models\EmailFilter;

class EmailFilterEngine
{
    public function countMatching(EmailFilter $filter): int
    {
        $query = Email::query();

        return match ($filter->type) {
            'sender_email' => $query
                ->whereRaw('LOWER(from_email) = ?', [strtolower($filter->value)])
                ->count(),

            'sender_domain' => $query
                ->whereRaw('LOWER(from_email) LIKE ?', ['%@' . strtolower($filter->value)])
                ->count(),

            'subject_keyword' => $query
                ->whereRaw('LOWER(subject) LIKE ?', ['%' . strtolower($filter->value) . '%'])
                ->count(),

            default => 0,
        };
    }

    public function matchesFilter(array $email, EmailFilter $filter): bool
    {
        $fromEmail = strtolower($email['from_email'] ?? '');
        $subject   = strtolower($email['subject'] ?? '');
        $value     = strtolower($filter->value);

        return match ($filter->type) {
            'sender_email'    => $fromEmail === $value,
            'sender_domain'   => str_ends_with($fromEmail, '@' . $value),
            'subject_keyword' => str_contains($subject, $value),
            default           => false,
        };
    }
}
