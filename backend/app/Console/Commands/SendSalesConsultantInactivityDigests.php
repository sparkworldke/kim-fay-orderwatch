<?php

namespace App\Console\Commands;

use App\Mail\SalesConsultantInactivityDigestMail;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Sales\SalesConsultantInactivityDigestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Illuminate\Support\Carbon;

class SendSalesConsultantInactivityDigests extends Command
{
    protected $signature = 'orderwatch:send-consultant-inactivity-digests {--dry-run} {--user-id=}';
    protected $description = 'Email enabled sales consultants who have not logged in for more than 25 hours';

    public function handle(SalesConsultantInactivityDigestService $digests): int
    {
        $cutoff = now()->subHours(25);
        $resendCutoff = now()->subHours(24);
        $query = User::query()->where('is_active', true)->where('role', 'Sales Consultant')
            ->where('inactivity_digest_enabled', true)
            ->whereNotNull('email')
            ->where(fn ($q) => $q->whereNull('last_inactivity_digest_sent_at')
                ->orWhere('last_inactivity_digest_sent_at', '<=', $resendCutoff));
        if ($id = $this->option('user-id')) $query->whereKey((int) $id);

        $sent = 0; $skipped = 0; $failed = 0;
        foreach ($query->cursor() as $user) {
            $lastLogin = UserSession::query()->where('user_id', $user->id)->max('login_at');
            $lastLogin = $lastLogin ? Carbon::parse($lastLogin) : null;
            $inactiveSince = $lastLogin ?? $user->created_at;
            if (! $inactiveSince || $inactiveSince->gt($cutoff)) { $skipped++; continue; }
            if ($this->option('dry-run')) { $this->line("Eligible: {$user->email}"); $skipped++; continue; }
            try {
                Mail::to($user->email)->send(new SalesConsultantInactivityDigestMail(
                    $user, $digests->build($user, $lastLogin),
                ));
                $user->forceFill(['last_inactivity_digest_sent_at' => now()])->save();
                $sent++;
            } catch (Throwable $e) {
                $failed++;
                Log::error('sales_consultant_inactivity_digest_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }
        $this->info("Consultant inactivity digest: {$sent} sent, {$skipped} skipped, {$failed} failed.");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
