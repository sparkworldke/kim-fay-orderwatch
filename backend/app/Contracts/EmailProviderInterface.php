<?php

namespace App\Contracts;

use App\Models\MailboxAccount;

interface EmailProviderInterface
{
    public function getAuthUrl(string $state): string;

    public function handleCallback(string $code): MailboxAccount;

    public function syncEmails(MailboxAccount $account): int;

    public function refreshAccessToken(MailboxAccount $account): void;
}
