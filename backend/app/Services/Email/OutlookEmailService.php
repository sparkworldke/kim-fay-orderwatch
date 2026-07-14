<?php

namespace App\Services\Email;

use App\Contracts\EmailProviderInterface;
use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\EmailFilter;
use App\Models\EmailImportConfig;
use App\Models\MailboxAccount;
use App\Models\MailboxFolder;
use App\Models\MailboxSyncItemLog;
use App\Models\MailboxSyncLog;
use App\Services\Admin\EncryptionService;
use App\Services\Email\AttachmentTextExtractorService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OutlookEmailService implements EmailProviderInterface
{
    private string $clientId;

    private string $clientSecret;

    private string $tenantId;

    private string $redirectUri;

    public function __construct(
        private readonly EncryptionService              $encryption,
        private readonly EmailFilterEngine              $filterEngine,
        private readonly AttachmentTextExtractorService $attachmentExtractor,
    ) {
        $this->clientId = config('services.microsoft.client_id', '');
        $this->clientSecret = config('services.microsoft.client_secret', '');
        $this->tenantId = config('services.microsoft.tenant_id', 'common');
        $this->redirectUri = config('services.microsoft.redirect_uri', '');
    }

    public function getAuthUrl(string $state): string
    {
        $params = http_build_query([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'scope' => 'Mail.Read offline_access',
            'response_mode' => 'query',
            'state' => $state,
        ]);

        return "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/authorize?{$params}";
    }

    public function handleCallback(string $code): MailboxAccount
    {
        $response = Http::asForm()->post(
            "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token",
            [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code' => $code,
                'redirect_uri' => $this->redirectUri,
                'grant_type' => 'authorization_code',
            ]
        );

        if (! $response->successful()) {
            throw new RuntimeException('OAuth code exchange failed: '.$response->body());
        }

        $tokenData = $response->json();

        $accessToken = $tokenData['access_token'] ?? null;
        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('OAuth code exchange succeeded without an access token.');
        }

        $profileResponse = $this->graphHttp($accessToken)
            ->timeout(10)
            ->get('https://graph.microsoft.com/v1.0/me', [
                '$select' => 'displayName,mail,userPrincipalName,otherMails',
            ]);

        if (! $profileResponse->successful()) {
            throw new RuntimeException(
                "Microsoft Graph profile request failed with HTTP {$profileResponse->status()}: ".
                ($profileResponse->json('error.message') ?? $profileResponse->body())
            );
        }

        $profile = $profileResponse->json();
        $email = $this->resolveProfileEmail($profile, $accessToken);

        if ($email === null) {
            throw new RuntimeException('Microsoft did not return an email address for the connected account.');
        }

        return MailboxAccount::updateOrCreate(
            ['email' => $email],
            [
                'display_name' => $profile['displayName'] ?? $email,
                'access_token_encrypted' => $this->encryption->encrypt($accessToken),
                'refresh_token_encrypted' => $this->encryption->encrypt($tokenData['refresh_token'] ?? ''),
                'token_expires_at' => now()->addSeconds((int) ($tokenData['expires_in'] ?? 3600)),
                'status' => 'connected',
                'delta_token' => null,
            ]
        );
    }

    public function syncEmails(
        MailboxAccount $account,
        ?int $emailFilterId = null,
        ?MailboxSyncLog $syncLog = null,
        ?string $syncFrom = null,
        ?string $syncTo = null,
    ): int {
        $accessToken = $this->getValidAccessToken($account);
        if (! $account->folders()->exists()) {
            $this->discoverFolders($account, $accessToken);
        }
        $folders = $account->folders()->where('is_active', true)->where('is_sync_enabled', true)
            ->orderBy('sync_priority')->orderBy('display_name')->get();
        $count = 0;
        $successes = 0;
        $manualRange = $syncFrom !== null && $syncFrom !== '' && $syncTo !== null && $syncTo !== '';

        if ($manualRange) {
            $from = Carbon::parse($syncFrom)->startOfDay();
            $to = Carbon::parse($syncTo)->endOfDay();

            foreach ($folders as $folder) {
                try {
                    $result = $this->syncFolderDateRange($account, $folder, $from, $to, $syncLog);
                    $count += $result['fetched'];
                    $successes++;
                } catch (Throwable $exception) {
                    $folder->update(['last_sync_error' => mb_substr($exception->getMessage(), 0, 2000)]);
                    $this->recordDatabaseOutcome($syncLog, null, 'failed', 'folder_sync_failed', 1, 0, $exception->getMessage(), $folder);
                    Log::channel('mailbox_sync')->error('folder_sync_failed', [
                        'sync_run_id' => $syncLog?->id, 'mailbox_id' => $account->id,
                        'folder_id' => $folder->id, 'exception_class' => $exception::class,
                    ]);
                }
            }
        } else {
            // Scheduled / automatic sync: same calendar day only.
            // Resume from last successful check within that day (never re-scan full history
            // or even the full day when a later watermark exists).
            $dayLogged = false;
            foreach ($folders as $folder) {
                try {
                    $window = $this->sameDaySyncWindow($folder->last_synced_at);
                    if (! $dayLogged && $syncLog) {
                        $syncLog->update([
                            'sync_from' => $window['day'],
                            'sync_to' => $window['day'],
                        ]);
                        $dayLogged = true;
                    }

                    Log::channel('mailbox_sync')->info('scheduled_same_day_window', [
                        'sync_run_id' => $syncLog?->id,
                        'mailbox_id' => $account->id,
                        'folder_id' => $folder->id,
                        'folder' => $folder->display_name,
                        'day' => $window['day'],
                        'from' => $window['from']->toIso8601String(),
                        'to' => $window['to']->toIso8601String(),
                        'last_check_at' => $window['last_check_at'],
                        'resumed_from_watermark' => $window['resumed_from_watermark'],
                    ]);

                    $result = $this->syncFolderDateRange(
                        $account,
                        $folder,
                        $window['from'],
                        $window['to'],
                        $syncLog,
                    );
                    $count += $result['fetched'];
                    $successes++;

                    Log::channel('mailbox_sync')->info('scheduled_same_day_check_complete', [
                        'sync_run_id' => $syncLog?->id,
                        'mailbox_id' => $account->id,
                        'folder_id' => $folder->id,
                        'folder' => $folder->display_name,
                        'day' => $window['day'],
                        'emails_fetched' => $result['fetched'],
                        'emails_created' => $result['created'],
                        'emails_updated' => $result['updated'],
                        'last_check_at' => now()->toIso8601String(),
                    ]);
                } catch (Throwable $exception) {
                    $folder->update(['last_sync_error' => mb_substr($exception->getMessage(), 0, 2000)]);
                    $this->recordDatabaseOutcome($syncLog, null, 'failed', 'folder_sync_failed', 1, 0, $exception->getMessage(), $folder);
                    Log::channel('mailbox_sync')->error('folder_sync_failed', [
                        'sync_run_id' => $syncLog?->id, 'mailbox_id' => $account->id,
                        'folder_id' => $folder->id, 'exception_class' => $exception::class,
                    ]);
                }
            }
        }

        if ($folders->isNotEmpty() && $successes === 0) {
            throw new RuntimeException('Every enabled mailbox folder failed to sync.');
        }
        $account->update(['last_synced_at' => now(), 'status' => 'connected']);

        return $count;
    }

    /**
     * Build the incremental sync window for a scheduled run.
     *
     * Guardrails:
     * - Only the current calendar day (app/cron timezone) is eligible.
     * - If last_check is still on that day, resume from it (with a small overlap).
     * - Never falls back to mailbox history or "created" timestamps.
     *
     * @return array{
     *   from: Carbon,
     *   to: Carbon,
     *   day: string,
     *   last_check_at: ?string,
     *   resumed_from_watermark: bool
     * }
     */
    public function sameDaySyncWindow(?Carbon $lastCheckAt = null, ?Carbon $now = null): array
    {
        $tz = (string) config('cron.timezone', config('app.timezone', 'Africa/Nairobi'));
        $now = ($now ?? Carbon::now($tz))->copy()->timezone($tz);
        $dayStart = $now->copy()->startOfDay();
        $from = $dayStart->copy();
        $resumed = false;

        if ($lastCheckAt !== null) {
            $last = $lastCheckAt->copy()->timezone($tz);
            if ($last->isSameDay($now) && $last->greaterThan($dayStart)) {
                // Small overlap so messages arriving at the watermark boundary are not missed.
                $from = $last->copy()->subMinutes(2);
                if ($from->lessThan($dayStart)) {
                    $from = $dayStart->copy();
                }
                $resumed = true;
            }
        }

        return [
            'from' => $from,
            'to' => $now->copy(),
            'day' => $dayStart->toDateString(),
            'last_check_at' => $lastCheckAt?->copy()->timezone($tz)->toIso8601String(),
            'resumed_from_watermark' => $resumed,
        ];
    }

    /**
     * On-demand folder sync scoped to a date/time range (max 90 days — enforced by caller).
     * Fetches every message in the folder whose receivedDateTime falls within the bounds
     * (read and unread — not delta/new-only), paginating until @odata.nextLink is exhausted.
     * Existing database rows are re-attached to the folder and counted in the run.
     */
    /**
     * @return array{
     *   fetched:int,
     *   created:int,
     *   updated:int,
     *   stored:int,
     *   skipped:int,
     *   failed:int,
     *   email_records:array<int, array{email_id:int, outcome:string}>
     * }
     */
    public function syncFolderDateRange(
        MailboxAccount $account,
        MailboxFolder $folder,
        Carbon $from,
        Carbon $to,
        ?MailboxSyncLog $syncLog = null,
    ): array {
        $senderConfigs = EmailImportConfig::where('is_active', true)->get();
        $fromIso = $from->copy()->utc()->format('Y-m-d\TH:i:s\Z');
        $toIso   = $to->copy()->utc()->format('Y-m-d\TH:i:s\Z');

        $url = 'https://graph.microsoft.com/v1.0/me/mailFolders/'.rawurlencode($folder->external_folder_id).'/messages';
        $params = [
            '$select' => 'id,conversationId,internetMessageId,subject,from,toRecipients,bodyPreview,isRead,receivedDateTime,hasAttachments',
            '$filter' => "receivedDateTime ge {$fromIso} and receivedDateTime le {$toIso}",
            '$orderby' => 'receivedDateTime asc',
            '$top' => 100,
        ];

        $stats = [
            'fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'stored' => 0,
            'skipped' => 0,
            'failed' => 0,
            'email_records' => [],
        ];
        $page = 0;
        $seenMessageIds = [];
        $seenInternetMessageIds = [];
        $seenEmailIds = [];

        do {
            $accessToken = $this->getValidAccessToken($account);
            $request = $this->graphHttp($accessToken, $params, [
                'Prefer' => 'IdType="ImmutableId", odata.maxpagesize=100',
            ])->timeout(120);

            $response = $page === 0
                ? $request->get($url, $params)
                : $request->get($url);

            if ($response->status() === 429) {
                sleep((int) ($response->header('Retry-After') ?? 5));
                continue;
            }

            if (! $response->successful()) {
                throw new RuntimeException($this->graphErrorMessage($response, $folder->display_name));
            }

            $data = $response->json();
            $batch = $data['value'] ?? [];
            $newInBatch = 0;

            foreach ($batch as $message) {
                if (! is_array($message)) {
                    continue;
                }

                $messageId = (string) ($message['id'] ?? '');
                $internetMessageId = trim((string) ($message['internetMessageId'] ?? ''));

                if ($messageId !== '' && isset($seenMessageIds[$messageId])) {
                    continue;
                }

                if ($internetMessageId !== '' && isset($seenInternetMessageIds[$internetMessageId])) {
                    continue;
                }

                if ($messageId !== '') {
                    $seenMessageIds[$messageId] = true;
                    $newInBatch++;
                }

                if ($internetMessageId !== '') {
                    $seenInternetMessageIds[$internetMessageId] = true;
                }

                $stats['fetched']++;
                $result = $this->processMessageOneByOne(
                    $account,
                    $message,
                    $senderConfigs,
                    collect(),
                    $syncLog,
                    $accessToken,
                    $folder,
                    forcePersist: true,
                );

                if ($result === null) {
                    $stats['failed']++;
                    continue;
                }

                $emailId = isset($result['email_id']) ? (int) $result['email_id'] : null;
                if ($emailId !== null && isset($seenEmailIds[$emailId])) {
                    Log::channel('mailbox_sync')->warning('folder_date_range_sync_duplicate_email_id', [
                        'mailbox_id' => $account->id,
                        'folder' => $folder->display_name,
                        'email_id' => $emailId,
                        'message_id' => $messageId !== '' ? $messageId : null,
                        'internet_message_id' => $internetMessageId !== '' ? $internetMessageId : null,
                    ]);

                    continue;
                }

                match ($result['outcome']) {
                    'created' => $stats['created']++,
                    'updated', 'synced' => $stats['updated']++,
                    'skipped' => $stats['skipped']++,
                    default => null,
                };

                if ($emailId !== null) {
                    $seenEmailIds[$emailId] = true;
                    $stats['stored']++;
                    $stats['email_records'][] = [
                        'email_id' => $emailId,
                        'outcome' => (string) $result['outcome'],
                    ];
                }
            }

            $page++;

            if (count($batch) > 0 && $newInBatch === 0) {
                Log::channel('mailbox_sync')->warning('folder_date_range_sync_duplicate_page', [
                    'mailbox_id' => $account->id,
                    'folder' => $folder->display_name,
                    'page' => $page,
                    'batch_count' => count($batch),
                    'total_fetched' => $stats['fetched'],
                    'from' => $fromIso,
                    'to' => $toIso,
                ]);
                break;
            }

            Log::channel('mailbox_sync')->info('folder_date_range_sync_page', [
                'mailbox_id' => $account->id,
                'folder' => $folder->display_name,
                'page' => $page,
                'batch_count' => count($batch),
                'new_in_batch' => $newInBatch,
                'total_fetched' => $stats['fetched'],
                'total_stored' => $stats['stored'],
                'from' => $fromIso,
                'to' => $toIso,
            ]);

            $url = $data['@odata.nextLink'] ?? null;
        } while ($url);

        $folder->update(['last_synced_at' => now(), 'last_sync_error' => null]);

        if ($stats['fetched'] === 0) {
            Log::channel('mailbox_sync')->info('folder_date_range_sync_empty', [
                'mailbox_id' => $account->id,
                'folder' => $folder->display_name,
                'from' => $fromIso,
                'to' => $toIso,
            ]);
        }

        return $stats;
    }

    public function discoverFolders(MailboxAccount $account, ?string $accessToken = null): array
    {
        $token = $accessToken ?: $this->getValidAccessToken($account);
        $inboxResponse = $this->graphHttp($token)
            ->timeout(30)->get('https://graph.microsoft.com/v1.0/me/mailFolders/inbox', [
                '$select' => 'id,displayName,parentFolderId,childFolderCount,totalItemCount,unreadItemCount',
            ]);
        $inboxId = $inboxResponse->successful() ? $inboxResponse->json('id') : 'Inbox';
        $seen = [];
        $walk = function (string $url, ?string $parentName = null) use (&$walk, &$seen, $account, $token, $inboxId): void {
            do {
                $response = $this->graphHttp($token)
                    ->timeout(30)->get($url, ['$top' => 100, '$select' => 'id,displayName,parentFolderId,childFolderCount,totalItemCount,unreadItemCount']);
                if (! $response->successful()) {
                    throw new RuntimeException($this->graphErrorMessage($response, 'folder discovery'));
                }
                $data = $response->json();
                foreach ($data['value'] ?? [] as $item) {
                    if (! isset($item['id'])) continue;
                    $isInbox = $item['id'] === $inboxId || strcasecmp((string) ($item['displayName'] ?? ''), 'Inbox') === 0;
                    $folder = MailboxFolder::firstOrNew(['mailbox_account_id' => $account->id, 'external_folder_id' => $item['id']]);
                    if (! $folder->exists) {
                        $folder->is_sync_enabled = $isInbox;
                        $folder->trust_level = $isInbox ? 'standard' : 'untrusted';
                        $folder->sync_priority = $isInbox ? 0 : 100;
                        if ($isInbox && $account->delta_token) $folder->delta_token = $account->delta_token;
                    }
                    $folder->fill([
                        'display_name' => $item['displayName'] ?? '(unnamed)',
                        'parent_external_folder_id' => $item['parentFolderId'] ?? null,
                        'parent_display_name' => $parentName,
                        'total_item_count' => (int) ($item['totalItemCount'] ?? 0),
                        'unread_item_count' => (int) ($item['unreadItemCount'] ?? 0),
                        'is_active' => true, 'last_discovered_at' => now(),
                    ])->save();
                    $seen[] = $folder->id;
                    if ((int) ($item['childFolderCount'] ?? 0) > 0) {
                        $walk('https://graph.microsoft.com/v1.0/me/mailFolders/'.rawurlencode($item['id']).'/childFolders', $folder->display_name);
                    }
                }
                $url = $data['@odata.nextLink'] ?? null;
            } while ($url);
        };
        $walk('https://graph.microsoft.com/v1.0/me/mailFolders');
        if ($seen === []) {
            $folder = MailboxFolder::firstOrCreate(
                ['mailbox_account_id' => $account->id, 'external_folder_id' => 'Inbox'],
                ['display_name' => 'Inbox', 'is_sync_enabled' => true, 'trust_level' => 'standard',
                    'sync_priority' => 0, 'delta_token' => $account->delta_token, 'last_discovered_at' => now()],
            );
            $seen[] = $folder->id;
        }
        $account->folders()->whereNotIn('id', $seen)->update(['is_active' => false]);
        if ($account->delta_token && $account->folders()->whereIn('id', $seen)->where('display_name', 'Inbox')->exists()) {
            $account->update(['delta_token' => null]);
        }
        return $account->folders()->with(['customer', 'rules.customer'])->orderBy('sync_priority')->get()->toArray();
    }

    private function syncFolder(MailboxAccount $account, MailboxFolder $folder, string $accessToken, $senderConfigs, $activeRules, ?MailboxSyncLog $syncLog): int
    {
        $deltaBase = 'https://graph.microsoft.com/v1.0/me/mailFolders/'.rawurlencode($folder->external_folder_id).'/messages/delta';
        $url = $folder->delta_token ?: $deltaBase;
        // 'body' is intentionally excluded — requesting it via Graph API automatically
        // marks the message as read in Outlook. bodyPreview (255 chars) is sufficient
        // for PO extraction; full body can be fetched separately if ever needed.
        $params = $folder->delta_token ? [] : [
            '$select' => 'id,conversationId,internetMessageId,subject,from,toRecipients,bodyPreview,isRead,receivedDateTime,hasAttachments',
            '$filter' => 'receivedDateTime ge '.($account->sync_from_date?->format('Y-m-d') ?? now()->subDay()->format('Y-m-d')).'T00:00:00Z',
        ];
        $count = 0;
        $resetDelta = false;
        do {
            // Check for a stop signal between page fetches
            if ($syncLog && Cache::pull("mailbox_sync_cancel:{$syncLog->id}")) {
                Log::channel('mailbox_sync')->info('Sync cancelled by user request', [
                    'mailbox_id' => $account->id,
                    'log_id'     => $syncLog->id,
                    'folder'     => $folder->display_name,
                ]);
                break;
            }

            $accessToken = $this->getValidAccessToken($account);
            $response = $this->graphHttp($accessToken, $params)
                ->timeout(60)
                ->get($url, $params);

            if ($response->status() === 410 && $folder->delta_token) {
                $folder->update(['delta_token' => null]);
                $url = $deltaBase;
                $params = [
                    '$select' => 'id,conversationId,internetMessageId,subject,from,toRecipients,bodyPreview,isRead,receivedDateTime,hasAttachments',
                    '$filter' => 'receivedDateTime ge '.($account->sync_from_date?->format('Y-m-d') ?? now()->subDay()->format('Y-m-d')).'T00:00:00Z',
                ];
                $resetDelta = true;
                continue;
            }

            if (! $response->successful()) {
                throw new RuntimeException($this->graphErrorMessage($response, $folder->display_name));
            }

            if ($resetDelta) {
                Log::channel('mailbox_sync')->info('folder_delta_token_reset', [
                    'mailbox_id' => $account->id,
                    'folder_id' => $folder->id,
                    'folder' => $folder->display_name,
                ]);
                $resetDelta = false;
            }

            $data = $response->json();
            foreach ($data['value'] ?? [] as $message) {
                $count++;
                $this->processMessageOneByOne($account, is_array($message) ? $message : [], $senderConfigs, $activeRules, $syncLog, $accessToken, $folder);
            }
            if (isset($data['@odata.deltaLink'])) {
                $folder->update(['delta_token' => $data['@odata.deltaLink'], 'last_synced_at' => now(), 'last_sync_error' => null]);
            }
            $url = $data['@odata.nextLink'] ?? null;
            $params = [];
        } while ($url);
        return $count;
    }

    /** Legacy implementation retained only as a compatibility reference during migration. */
    private function syncLegacyInbox(
        MailboxAccount $account,
        ?int $emailFilterId = null,
        ?MailboxSyncLog $syncLog = null,
    ): int {
        $accessToken = $this->getValidAccessToken($account);
        $senderConfigs = EmailImportConfig::where('is_active', true)->get();

        // A normal mailbox sync imports every message. A rule-specific sync,
        // triggered from an individual rule card, intentionally imports only
        // messages matching that rule.
        $activeRules = $emailFilterId
            ? EmailFilter::where('id', $emailFilterId)->get()
            : collect();

        $url = 'https://graph.microsoft.com/v1.0/me/mailFolders/Inbox/messages/delta';
        // 'body' excluded — see syncFolder() for explanation.
        $params = ['$select' => 'id,conversationId,internetMessageId,subject,from,toRecipients,bodyPreview,isRead,receivedDateTime,hasAttachments'];

        if ($account->delta_token) {
            // Subsequent sync — delta token already encodes any date filter from initial sync
            $url = $account->delta_token;
            $params = [];
        } else {
            // Initial sync: scope by configured date, or default to 90 days ago.
            // Without a date filter Graph returns the entire mailbox history which
            // causes multi-minute responses and cURL timeouts on constrained servers.
            $fromDate = $account->sync_from_date
                ? $account->sync_from_date->format('Y-m-d')
                : now()->subDay()->format('Y-m-d');

            $params['$filter'] = 'receivedDateTime ge '.$fromDate.'T00:00:00Z';
        }

        $count = 0;

        do {
            // Retry up to 3 times with 5-second back-off for transient cURL/network
            // errors (e.g. cURL 28 timeout). Each attempt has a 60-second deadline
            // — generous enough for Graph's paginated delta responses.
            $response = $this->graphHttp($accessToken, $params)
                ->timeout(60)
                ->get($url, $params);

            if (! $response->successful()) {
                throw new RuntimeException($this->graphErrorMessage($response, 'Inbox'));
            }

            $data = $response->json();

            foreach ($data['value'] ?? [] as $message) {
                $count++;
                $this->processMessageOneByOne(
                    $account,
                    is_array($message) ? $message : [],
                    $senderConfigs,
                    $activeRules,
                    $syncLog,
                    $accessToken,
                );

                unset($message);
            }

            if (isset($data['@odata.deltaLink'])) {
                $account->update([
                    'delta_token' => $data['@odata.deltaLink'],
                    'last_synced_at' => now(),
                    'status' => 'connected',
                ]);
            }

            $url = $data['@odata.nextLink'] ?? null;
            $params = [];

        } while ($url);

        return $count;
    }

    /**
     * Commit one Graph message independently. A poison message is retried five
     * times, recorded as failed, and never stops the surrounding page loop.
     */
    private function processMessageOneByOne(
        MailboxAccount $account,
        array $message,
        $senderConfigs,
        $activeRules,
        ?MailboxSyncLog $syncLog,
        string $accessToken,
        ?MailboxFolder $folder = null,
        bool $forcePersist = false,
    ): ?array {
        $startedAt = hrtime(true);
        $messageId = isset($message['id']) && is_string($message['id']) && $message['id'] !== ''
            ? $message['id']
            : null;
        $lastException = null;

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $result = DB::transaction(function () use (
                    $account,
                    $message,
                    $messageId,
                    $senderConfigs,
                    $activeRules,
                    $syncLog,
                    $attempt,
                    $startedAt,
                    $folder,
                    $forcePersist,
                ): array {
                    if ($messageId === null) {
                        throw new RuntimeException('Graph message is missing a valid id.');
                    }

                    $result = $this->persistSingleMessage(
                        $account,
                        $message,
                        $messageId,
                        $senderConfigs,
                        $activeRules,
                        $folder,
                        $forcePersist,
                    );

                    $durationMs = $this->elapsedMilliseconds($startedAt);
                    $itemLog = $this->recordDatabaseOutcome(
                        $syncLog,
                        $messageId,
                        $result['outcome'],
                        $result['reason'],
                        $attempt,
                        $durationMs,
                        null,
                        $folder,
                        $result['email_id'] ?? null,
                    );

                    return $result + ['attempts' => $attempt, 'duration_ms' => $durationMs, 'item_log_id' => $itemLog?->id, 'folder_id' => $folder?->id];
                }, 1);

                $this->writeItemFileLog($syncLog, $account, $messageId, $result);

                if (($message['hasAttachments'] ?? false) && isset($result['email_id'])) {
                    $this->syncAttachments($accessToken, (int) $result['email_id'], $messageId);
                }

                if (isset($result['email_id']) && in_array($result['outcome'], ['created', 'updated', 'synced', 'skipped'], true)) {
                    $email = Email::find($result['email_id']);
                    if ($email && ($forcePersist || $result['outcome'] !== 'skipped' || ! $email->ingestion_classification)) {
                        $decision = app(EmailIngestionDecisionService::class)->evaluate($email);
                        if (isset($result['item_log_id'])) {
                            MailboxSyncItemLog::whereKey($result['item_log_id'])->update([
                                'decision_source' => implode(',', $decision['sources']),
                                'po_number_detected' => $decision['po_detected'],
                                'po_number_source' => $decision['po_source'],
                                'decision_context' => ['classification' => $decision['classification'], 'reason_codes' => $decision['reasons']],
                            ]);
                        }
                    }
                }

                return $result;
            } catch (Throwable $exception) {
                $lastException = $exception;
            }
        }

        $durationMs = $this->elapsedMilliseconds($startedAt);

        try {
            DB::transaction(function () use ($syncLog, $messageId, $lastException, $durationMs, $folder): void {
                $this->recordDatabaseOutcome(
                    $syncLog,
                    $messageId,
                    'failed',
                    'processing_failed',
                    5,
                    $durationMs,
                    $lastException?->getMessage(),
                    $folder,
                );
            }, 1);
        } catch (Throwable) {
            // The file event below remains available when DB logging fails.
        }

        $this->writeItemFileLog($syncLog, $account, $messageId, [
            'outcome' => 'failed',
            'reason' => 'processing_failed',
            'attempts' => 5,
            'duration_ms' => $durationMs,
            'exception_class' => $lastException ? $lastException::class : null,
        ]);

        return null;
    }

    private function persistSingleMessage(
        MailboxAccount $account,
        array $message,
        string $messageId,
        $senderConfigs,
        $activeRules,
        ?MailboxFolder $folder = null,
        bool $forcePersist = false,
    ): array {
        if (isset($message['@removed'])) {
            $query = Email::where('mailbox_account_id', $account->id)->where('message_id', $messageId);
            if ($folder) $query->where(fn ($builder) => $builder->where('mailbox_folder_id', $folder->id)->orWhereNull('mailbox_folder_id'));
            $deleted = $query->delete();

            return $deleted > 0
                ? ['outcome' => 'deleted', 'reason' => 'removed_by_graph']
                : ['outcome' => 'skipped', 'reason' => $folder ? 'removed_from_previous_folder' : 'already_deleted'];
        }

        $fromEmail = $message['from']['emailAddress']['address'] ?? '';

        // Part 2: Trusted order folders bypass filter rules entirely.
        // A folder marked as an order folder with a linked customer is trusted
        // at the folder level — no EmailFilter condition needs to match.
        $folderIsTrusted = $folder !== null
            && $folder->is_order_folder
            && $folder->customer_id !== null;

        if (! $forcePersist && ! $folderIsTrusted && $activeRules->isNotEmpty()) {
            $filterData = [
                'from_email' => $fromEmail,
                'subject' => $message['subject'] ?? '',
                'received_at' => $message['receivedDateTime'] ?? null,
            ];

            if (! $activeRules->contains(fn ($rule) => $this->filterEngine->matchesFilter($filterData, $rule))) {
                return ['outcome' => 'skipped', 'reason' => 'filter_not_matched'];
            }
        }

        $attributes = [
            'mailbox_account_id' => $account->id,
            'mailbox_folder_id' => $folder?->id,
            'external_folder_id' => $folder?->external_folder_id,
            'subject' => $message['subject'] ?? '(no subject)',
            'from_email' => $fromEmail ?: null,
            'from_name' => $message['from']['emailAddress']['name'] ?? null,
            'to_recipients' => array_map(
                fn ($recipient) => $recipient['emailAddress'],
                $message['toRecipients'] ?? [],
            ),
            'body_preview' => substr($message['bodyPreview'] ?? '', 0, 500),
            'body_content' => null, // body not fetched — see select comment; bodyPreview is used for PO extraction
            'conversation_id' => $message['conversationId'] ?? null,
            'internet_message_id' => $message['internetMessageId'] ?? null,
            'is_read' => $message['isRead'] ?? false,
            'received_at' => isset($message['receivedDateTime'])
                ? new Carbon($message['receivedDateTime'])
                : null,
            'folder' => $folder?->display_name ?? 'Inbox',
            'has_attachments' => $message['hasAttachments'] ?? false,
        ];

        $importDecision = $this->resolveSenderImportConfig($fromEmail, $senderConfigs);
        $attributes['email_import_config_id'] = $importDecision['config']?->id;
        $attributes['matched_customer_id'] = $importDecision['customer_id'];
        $attributes['matched_branch_tag'] = $importDecision['branch_tag'];
        $attributes['import_match_strategy'] = $importDecision['strategy'];
        $attributes['import_guardrail_status'] = $importDecision['status'];
        $attributes['import_guardrail_reason'] = $importDecision['reason'];

        $email = Email::firstOrNew(['mailbox_account_id' => $account->id, 'message_id' => $messageId]);
        $email->fill($attributes);

        if (! $email->exists) {
            $email->save();

            if ($importDecision['config']) {
                $this->touchImportConfigUsage($importDecision['config'], $importDecision['status']);
            }

            return ['outcome' => 'created', 'reason' => null, 'email_id' => $email->id];
        }

        if ($forcePersist) {
            $hadFieldChanges = $email->isDirty();
            if (! $hadFieldChanges) {
                $email->touch();
            }
            $email->save();

            if ($importDecision['config']) {
                $this->touchImportConfigUsage($importDecision['config'], $importDecision['status']);
            }

            return [
                'outcome' => $hadFieldChanges ? 'updated' : 'synced',
                'reason' => $hadFieldChanges ? 'fields_changed' : 'date_range_reimport',
                'email_id' => $email->id,
            ];
        }

        if (! $email->isDirty()) {
            return ['outcome' => 'skipped', 'reason' => 'unchanged', 'email_id' => $email->id];
        }

        $email->save();

        if ($importDecision['config']) {
            $this->touchImportConfigUsage($importDecision['config'], $importDecision['status']);
        }

        return ['outcome' => 'updated', 'reason' => 'fields_changed', 'email_id' => $email->id];
    }

    /**
     * @param \Illuminate\Support\Collection<int, EmailImportConfig> $senderConfigs
     * @return array{config:?EmailImportConfig,customer_id:?int,branch_tag:?string,strategy:?string,status:string,reason:?string}
     */
    private function resolveSenderImportConfig(string $fromEmail, $senderConfigs): array
    {
        $senderEmail = strtolower(trim($fromEmail));
        if ($senderEmail === '') {
            return [
                'config' => null,
                'customer_id' => null,
                'branch_tag' => null,
                'strategy' => null,
                'status' => 'unrecognized',
                'reason' => 'sender_email_missing',
            ];
        }

        EmailImportConfig::autoDeactivateDormantExactConfigs();

        /** @var ?EmailImportConfig $config */
        $config = $senderConfigs
            ->filter(fn (EmailImportConfig $candidate) => $candidate->is_active)
            ->sortBy(fn (EmailImportConfig $candidate) => match ($candidate->match_mode) {
                EmailImportConfig::MATCH_MODE_EXACT => 0,
                EmailImportConfig::MATCH_MODE_WILDCARD => 1,
                EmailImportConfig::MATCH_MODE_REGEX => 2,
                default => 3,
            })
            ->first(fn (EmailImportConfig $candidate) => $candidate->matchesSender($senderEmail));

        if (! $config) {
            return [
                'config' => null,
                'customer_id' => null,
                'branch_tag' => null,
                'strategy' => null,
                'status' => 'unrecognized',
                'reason' => 'sender_not_preapproved',
            ];
        }

        if (! $config->isApproved()) {
            return [
                'config' => $config,
                'customer_id' => null,
                'branch_tag' => null,
                'strategy' => $config->match_mode,
                'status' => 'pending_approval',
                'reason' => 'sender_config_pending_dual_admin_approval',
            ];
        }

        if ($config->auto_deactivated_at !== null && ! $config->is_active) {
            return [
                'config' => $config,
                'customer_id' => null,
                'branch_tag' => null,
                'strategy' => $config->match_mode,
                'status' => 'expired',
                'reason' => 'sender_config_auto_deactivated_after_90_days',
            ];
        }

        if ($config->match_mode !== EmailImportConfig::MATCH_MODE_EXACT && ! $this->canImportWildcardSender()) {
            return [
                'config' => $config,
                'customer_id' => $config->customer_id,
                'branch_tag' => $config->extractBranchTag($senderEmail),
                'strategy' => $config->match_mode,
                'status' => 'rate_limited',
                'reason' => 'wildcard_import_hourly_limit_reached',
            ];
        }

        return [
            'config' => $config,
            'customer_id' => $config->customer_id,
            'branch_tag' => $config->extractBranchTag($senderEmail),
            'strategy' => $config->match_mode,
            'status' => 'matched',
            'reason' => null,
        ];
    }

    private function touchImportConfigUsage(EmailImportConfig $config, string $status): void
    {
        $config->forceFill(['last_matched_at' => now()]);

        if ($status === 'matched') {
            $config->forceFill(['last_imported_at' => now()]);
            if ($config->match_mode !== EmailImportConfig::MATCH_MODE_EXACT) {
                $this->incrementWildcardImportCounter();
            }
        }

        $config->save();
    }

    private function canImportWildcardSender(): bool
    {
        return (int) Cache::get($this->wildcardImportCacheKey(), 0) < 500;
    }

    private function incrementWildcardImportCounter(): void
    {
        $key = $this->wildcardImportCacheKey();
        Cache::add($key, 0, now()->endOfHour());
        Cache::increment($key);
    }

    private function wildcardImportCacheKey(): string
    {
        return 'email_import:wildcard:' . now()->format('YmdH');
    }

    private function plainTextBody(mixed $content, mixed $contentType): ?string
    {
        if (! is_string($content) || $content === '') return null;
        $text = strcasecmp((string) $contentType, 'html') === 0
            ? html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div)>/i', "\n", $content)), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : $content;
        return mb_substr(trim($text), 0, 100000);
    }

    /** Attachment failures are evidence, not mailbox-sync failures. */
    private function syncAttachments(string $accessToken, int $emailId, string $messageId): void
    {
        try {
            $list = $this->graphHttp($accessToken)->timeout(30)->get(
                'https://graph.microsoft.com/v1.0/me/messages/'.rawurlencode($messageId).'/attachments',
                ['$select' => 'id,name,contentType,size,isInline'],
            );
            if (! $list->successful()) throw new RuntimeException('Attachment metadata request failed with HTTP '.$list->status());

            foreach ($list->json('value', []) as $item) {
                if (! isset($item['id'])) continue;
                $attachment = EmailAttachment::updateOrCreate(
                    ['email_id' => $emailId, 'graph_attachment_id' => $item['id']],
                    ['name' => $item['name'] ?? null, 'content_type' => $item['contentType'] ?? null,
                        'size' => (int) ($item['size'] ?? 0), 'is_inline' => (bool) ($item['isInline'] ?? false)],
                );
                if ($attachment->extraction_status === 'parsed') continue;
                if ($attachment->size > 10 * 1024 * 1024) {
                    $attachment->update(['extraction_status' => 'unsupported', 'extraction_error' => 'attachment_oversized']);
                    continue;
                }

                $mime = strtolower((string) $attachment->content_type);

                // Plain-text types are decoded directly; structured types go through extractors.
                $plainTextTypes = ['text/plain', 'text/csv', 'application/csv', 'application/json', 'application/xml', 'text/xml'];
                $isPlainText    = in_array($mime, $plainTextTypes, true);
                $isExtractable  = $this->attachmentExtractor->isExtractable($mime);

                if (! $isPlainText && ! $isExtractable) {
                    $attachment->update(['extraction_status' => 'unsupported', 'extraction_error' => 'unsupported_type']);
                    continue;
                }

                // Download raw bytes from Graph API
                $content = $this->graphHttp($accessToken)->timeout(60)->get(
                    'https://graph.microsoft.com/v1.0/me/messages/'.rawurlencode($messageId).'/attachments/'.rawurlencode($item['id'])
                );

                if (! $content->successful()) {
                    $attachment->update(['extraction_status' => 'failed', 'extraction_error' => 'graph_download_failed']);
                    continue;
                }

                $rawBytes = base64_decode((string) $content->json('contentBytes'), true);

                if ($rawBytes === false || $rawBytes === '') {
                    $attachment->update(['extraction_status' => 'failed', 'extraction_error' => 'base64_decode_failed']);
                    continue;
                }

                if ($isPlainText) {
                    // Plain text: store directly (existing behaviour)
                    $attachment->update([
                        'extracted_text'        => mb_substr($rawBytes, 0, 100000),
                        'extraction_status'     => 'parsed',
                        'extraction_confidence' => 100,
                        'extraction_method'     => 'graph_text_decode',
                        'extraction_error'      => null,
                    ]);
                } else {
                    // PDF / Excel / Image — delegate to type-specific extractor
                    $this->attachmentExtractor->extract($attachment, $rawBytes);
                }
            }
        } catch (Throwable $exception) {
            Log::warning('Attachment ingestion failed safely', ['email_id' => $emailId, 'error' => $exception->getMessage()]);
            EmailAttachment::where('email_id', $emailId)->where('extraction_status', 'pending')->update([
                'extraction_status' => 'failed', 'extraction_error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
            if (! EmailAttachment::where('email_id', $emailId)->exists()) {
                EmailAttachment::create([
                    'email_id' => $emailId, 'graph_attachment_id' => 'sync-failure-'.sha1($messageId),
                    'extraction_status' => 'failed', 'extraction_error' => mb_substr($exception->getMessage(), 0, 1000),
                ]);
            }
        }
    }

    private function recordDatabaseOutcome(
        ?MailboxSyncLog $syncLog,
        ?string $messageId,
        string $outcome,
        ?string $reason,
        int $attempts,
        int $durationMs,
        ?string $errorMessage = null,
        ?MailboxFolder $folder = null,
        ?int $emailId = null,
    ): ?MailboxSyncItemLog {
        if ($syncLog === null) {
            return null;
        }

        $itemLog = MailboxSyncItemLog::create([
            'mailbox_sync_log_id' => $syncLog->id,
            'mailbox_folder_id' => $folder?->id,
            'email_id' => $emailId,
            'message_id' => $messageId,
            'outcome' => $outcome,
            'reason' => $reason,
            'attempts' => $attempts,
            'duration_ms' => $durationMs,
            'error_message' => $errorMessage,
            'processed_at' => now(),
        ]);

        $counter = match ($outcome) {
            'created' => 'emails_created',
            'updated', 'synced' => 'emails_updated',
            'deleted' => 'emails_deleted',
            'failed' => 'emails_failed',
            default => 'emails_skipped',
        };

        MailboxSyncLog::whereKey($syncLog->id)->incrementEach([
            'emails_fetched' => 1,
            $counter => 1,
        ]);

        return $itemLog;
    }

    private function writeItemFileLog(
        ?MailboxSyncLog $syncLog,
        MailboxAccount $account,
        ?string $messageId,
        array $result,
    ): void {
        $outcome = $result['outcome'];
        $level = $outcome === 'failed' ? 'error' : 'info';

        try {
            Log::channel('mailbox_sync')->{$level}('email_'.$outcome, array_filter([
                'sync_run_id' => $syncLog?->id,
                'mailbox_id' => $account->id,
                'message_id' => $messageId,
                'folder_id' => $result['folder_id'] ?? null,
                'outcome' => $outcome,
                'reason' => $result['reason'] ?? null,
                'attempts' => $result['attempts'] ?? 1,
                'duration_ms' => $result['duration_ms'] ?? 0,
                'exception_class' => $result['exception_class'] ?? null,
            ], fn ($value) => $value !== null));
        } catch (Throwable) {
            // A file-system logging outage must not replay or abort a committed email.
        }
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return max(0, (int) ((hrtime(true) - $startedAt) / 1_000_000));
    }

    public function refreshAccessToken(MailboxAccount $account): void
    {
        $refreshToken = $this->encryption->decrypt($account->refresh_token_encrypted);

        if (! is_string($refreshToken) || $refreshToken === '') {
            $account->update(['status' => 'error']);
            throw new RuntimeException('The stored Outlook refresh token could not be decrypted. Reconnect this mailbox.');
        }

        $response = Http::asForm()
            ->retry(2, 3000)
            ->timeout(30)
            ->post(
                "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token",
                [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'refresh_token' => $refreshToken,
                    'grant_type' => 'refresh_token',
                    'scope' => 'Mail.Read offline_access',
                ]
            );

        if (! $response->successful()) {
            $account->update(['status' => 'error']);
            throw new RuntimeException('Token refresh failed: '.$response->body());
        }

        $tokenData = $response->json();

        $account->update([
            'access_token_encrypted' => $this->encryption->encrypt($tokenData['access_token']),
            'refresh_token_encrypted' => isset($tokenData['refresh_token'])
                ? $this->encryption->encrypt($tokenData['refresh_token'])
                : $account->refresh_token_encrypted,
            'token_expires_at' => now()->addSeconds((int) ($tokenData['expires_in'] ?? 3600)),
            'status' => 'connected',
        ]);
    }

    /** Public accessor so controllers can get a (possibly refreshed) token for diagnostics. */
    public function getDecryptedToken(MailboxAccount $account): string
    {
        return $this->getValidAccessToken($account);
    }

    private function getValidAccessToken(MailboxAccount $account): string
    {
        if ($account->token_expires_at && $account->token_expires_at->isBefore(now()->addMinutes(5))) {
            $this->refreshAccessToken($account);
            $account->refresh();
        }

        $token = $this->encryption->decrypt($account->access_token_encrypted);
        if (! is_string($token) || $token === '') {
            $account->update(['status' => 'error']);
            throw new RuntimeException('The stored Outlook access token could not be decrypted. Reconnect this mailbox.');
        }

        return $token;
    }

    /** @param  array<string, string>  $extraHeaders */
    private function graphHttp(string $accessToken, array $params = [], array $extraHeaders = []): PendingRequest
    {
        return Http::withToken($accessToken)
            ->withHeaders(array_merge($this->graphHeaders($params), $extraHeaders))
            ->withUserAgent($this->graphUserAgent())
            ->retry(3, 5000)
            ->connectTimeout(10);
    }

    private function graphUserAgent(): string
    {
        return (string) config(
            'services.microsoft.graph_user_agent',
            'OrderWatch/1.0 (Kim-Fay OrderWatch; +https://orderwatch.fayshop.co.ke)',
        );
    }

    /** @param  array<string, mixed>  $params */
    private function graphHeaders(array $params = []): array
    {
        $headers = ['Prefer' => 'IdType="ImmutableId"'];

        if (isset($params['$filter'])) {
            $headers['ConsistencyLevel'] = 'eventual';
        }

        return $headers;
    }

    private function graphErrorMessage(Response $response, string $folderLabel): string
    {
        $detail = $response->json('error.message') ?? trim($response->body());
        $detail = is_string($detail) ? mb_substr($detail, 0, 500) : '';

        return trim("Microsoft Graph folder sync failed for {$folderLabel} with HTTP {$response->status()}. {$detail}");
    }

    /** Resolve the mailbox identity from Graph first, then from standard JWT claims. */
    private function resolveProfileEmail(array $profile, string $accessToken): ?string
    {
        $candidates = [
            $profile['mail'] ?? null,
            $profile['userPrincipalName'] ?? null,
            $profile['otherMails'][0] ?? null,
        ];

        $parts = explode('.', $accessToken);
        if (count($parts) >= 2) {
            $payload = strtr($parts[1], '-_', '+/');
            $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
            $claims = json_decode((string) base64_decode($payload, true), true);
            if (is_array($claims)) {
                $candidates[] = $claims['preferred_username'] ?? null;
                $candidates[] = $claims['upn'] ?? null;
                $candidates[] = $claims['email'] ?? null;
            }
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && filter_var(trim($candidate), FILTER_VALIDATE_EMAIL)) {
                return strtolower(trim($candidate));
            }
        }

        return null;
    }
}
