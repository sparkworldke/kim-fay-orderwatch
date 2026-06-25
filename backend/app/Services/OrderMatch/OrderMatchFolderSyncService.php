<?php

namespace App\Services\OrderMatch;

use App\Models\MailboxFolder;
use App\Models\OrderMatchSyncRun;
use App\Models\OrderMatchSyncRunEmail;
use App\Services\Email\OutlookEmailService;
use Carbon\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrderMatchFolderSyncService
{
    public const MAX_DAYS = 90;

    public const SYNC_TIMEZONE = 'Africa/Nairobi';

    public function __construct(private readonly OutlookEmailService $outlook)
    {
    }

    public function sync(MailboxFolder $folder, string $from, string $to, ?int $userId = null): OrderMatchSyncRun
    {
        $run = $this->start($folder, $from, $to, $userId);
        $this->execute($run, $from, $to);

        return $run->fresh(['folder']);
    }

    public function start(MailboxFolder $folder, string $from, string $to, ?int $userId = null): OrderMatchSyncRun
    {
        [$fromDate, $toDate] = $this->resolveSyncBounds($from, $to);

        return OrderMatchSyncRun::create([
            'mailbox_folder_id'    => $folder->id,
            'sync_from'            => $fromDate->toDateString(),
            'sync_to'              => $toDate->toDateString(),
            'status'               => 'processing',
            'triggered_by_user_id' => $userId,
            'started_at'           => now(),
        ]);
    }

    public function execute(OrderMatchSyncRun|int $run, ?string $from = null, ?string $to = null): void
    {
        @set_time_limit(600);

        $run = $run instanceof OrderMatchSyncRun
            ? $run->fresh(['folder.mailboxAccount'])
            : OrderMatchSyncRun::with(['folder.mailboxAccount'])->findOrFail($run);

        if ($run->status !== 'processing') {
            return;
        }

        $folder = $run->folder;
        if (! $folder) {
            $run->update([
                'status'        => 'failed',
                'error_message' => 'Mailbox folder no longer exists.',
                'ended_at'      => now(),
            ]);

            return;
        }

        if ($from !== null && $to !== null) {
            [$fromDate, $toDate] = $this->resolveSyncBounds($from, $to);
        } else {
            $fromDate = Carbon::parse($run->sync_from)->startOfDay();
            $toDate   = Carbon::parse($run->sync_to)->endOfDay();
        }

        try {
            $stats = $this->outlook->syncFolderDateRange($folder->mailboxAccount, $folder, $fromDate, $toDate);

            foreach ($stats['email_records'] as $record) {
                OrderMatchSyncRunEmail::updateOrCreate(
                    [
                        'order_match_sync_run_id' => $run->id,
                        'email_id' => $record['email_id'],
                    ],
                    [
                        'outcome' => $record['outcome'],
                        'created_at' => now(),
                    ],
                );
            }

            $run->update([
                'emails_found'   => $stats['fetched'],
                'emails_created' => $stats['created'],
                'emails_updated' => $stats['updated'],
                'emails_queued'  => $stats['stored'],
                'status'         => 'completed',
                'ended_at'       => now(),
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'ended_at'      => now(),
            ]);
        }
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveSyncBounds(string $from, string $to): array
    {
        $fromDate = $this->parseSyncBound($from, 'start');
        $toDate   = $this->parseSyncBound($to, 'end');

        if ($fromDate->gt($toDate)) {
            throw new HttpException(422, 'The sync start must be before or equal to the sync end.');
        }

        if ($fromDate->diffInDays($toDate) > self::MAX_DAYS) {
            throw new HttpException(422, 'Maximum sync window is 90 days.');
        }

        return [$fromDate, $toDate];
    }

    /**
     * Date-only values (YYYY-MM-DD) expand to full calendar days.
     * Date-time values (ISO 8601) are used exactly as provided.
     */
    private function parseSyncBound(string $value, string $edge): Carbon
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new HttpException(422, 'Sync range bounds are required.');
        }

        try {
            $parsed = Carbon::parse($trimmed);
        } catch (\Throwable) {
            throw new HttpException(422, 'Invalid sync date or date-time value.');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
            $local = Carbon::parse($trimmed, self::SYNC_TIMEZONE);

            return $edge === 'start' ? $local->startOfDay() : $local->endOfDay();
        }

        return $parsed->timezone(self::SYNC_TIMEZONE);
    }
}