<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncMailboxFolderJob;
use App\Models\Email;
use App\Models\MailboxAccount;
use App\Models\MailboxFolder;
use App\Models\MailboxRuleMapping;
use App\Models\OrderMatchSyncRun;
use App\Services\Email\OutlookEmailService;
use App\Services\OrderMatch\OrderMatchFolderSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MailboxFolderController extends Controller
{
    public function __construct(
        private readonly OutlookEmailService $outlook,
        private readonly OrderMatchFolderSyncService $folderSync,
    ) {}

    public function index(MailboxAccount $mailbox): JsonResponse
    {
        $folders = $mailbox->folders()->with(['customer:id,acumatica_id,name', 'rules.customer:id,acumatica_id,name'])
            ->orderBy('sync_priority')->orderBy('display_name')->get();

        $folderIds = $folders->pluck('id');

        $emailCounts = Email::query()
            ->whereIn('mailbox_folder_id', $folderIds)
            ->selectRaw('mailbox_folder_id, COUNT(*) as cnt')
            ->groupBy('mailbox_folder_id')
            ->pluck('cnt', 'mailbox_folder_id');

        $lastSyncs = OrderMatchSyncRun::query()
            ->whereIn('mailbox_folder_id', $folderIds)
            ->where('status', 'completed')
            ->orderByDesc('started_at')
            ->get()
            ->unique('mailbox_folder_id')
            ->keyBy('mailbox_folder_id');

        return response()->json(
            $folders->map(fn ($folder) => $this->present($folder, $emailCounts, $lastSyncs))
        );
    }

    public function discover(MailboxAccount $mailbox): JsonResponse
    {
        $this->outlook->discoverFolders($mailbox);
        return $this->index($mailbox);
    }

    public function update(Request $request, MailboxFolder $folder): JsonResponse
    {
        $validated = $request->validate([
            'is_sync_enabled' => ['sometimes', 'boolean'],
            'is_order_folder' => ['sometimes', 'boolean'],
            'customer_id' => ['sometimes', 'nullable', 'integer', 'exists:acumatica_customers,id'],
            'trust_level' => ['sometimes', 'string', 'in:untrusted,standard,trusted_order'],
            'sync_priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);
        if (strcasecmp($folder->display_name, 'Inbox') === 0 && array_key_exists('is_sync_enabled', $validated) && ! $validated['is_sync_enabled']) {
            return response()->json(['message' => 'Inbox is always included in normal mailbox sync.'], 422);
        }
        if (($validated['trust_level'] ?? null) === 'trusted_order') $validated['is_order_folder'] = true;
        $folder->update($validated);
        return response()->json($this->present($folder->fresh(['customer', 'rules.customer'])));
    }

    public function sync(Request $request, MailboxFolder $folder): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'string', 'max:35'],
            'to'   => ['required', 'string', 'max:35'],
        ]);

        $isInbox = strcasecmp($folder->display_name, 'Inbox') === 0;
        if (! $folder->is_sync_enabled && ! $isInbox) {
            throw new HttpException(422, 'Enable sync for this folder before running a manual sync.');
        }

        $run = $this->folderSync->start($folder, $validated['from'], $validated['to'], $request->user()?->id);
        $runId = $run->id;
        $from = $validated['from'];
        $to = $validated['to'];

        defer(function () use ($runId, $from, $to) {
            SyncMailboxFolderJob::dispatchSync($runId, $from, $to);
        });

        return response()->json([
            'sync_id'        => $run->id,
            'folder_name'    => $folder->display_name,
            'emails_found'   => 0,
            'emails_stored'  => 0,
            'emails_created' => 0,
            'emails_updated' => 0,
            'status'         => 'processing',
            'message'        => 'Sync started. Emails will be imported in the background.',
        ], 202);
    }

    public function syncRun(OrderMatchSyncRun $run): JsonResponse
    {
        $run->load('folder:id,display_name');

        if ($run->status === 'failed') {
            throw new HttpException(422, $run->error_message ?: 'Folder sync failed.');
        }

        return response()->json([
            'sync_id'        => $run->id,
            'folder_name'    => $run->folder?->display_name,
            'sync_from'      => $run->sync_from,
            'sync_to'        => $run->sync_to,
            'emails_found'   => $run->emails_found,
            'emails_stored'  => $run->emails_queued,
            'emails_created' => $run->emails_created,
            'emails_updated' => $run->emails_updated,
            'status'         => $run->status,
            'error_message'  => $run->error_message,
            'started_at'     => $run->started_at,
            'ended_at'       => $run->ended_at,
        ]);
    }

    public function syncRunEmails(OrderMatchSyncRun $run): JsonResponse
    {
        $run->load('folder:id,display_name');
        $records = $run->storedEmails()
            ->with(['email.mailboxFolder.customer:id,acumatica_id,name'])
            ->latest('id')
            ->get();

        $emails = $records->map(function ($record) {
            $email = $record->email;
            if (! $email) {
                return null;
            }

            return [
                'id' => $email->id,
                'subject' => $email->subject,
                'from_email' => $email->from_email,
                'from_name' => $email->from_name,
                'received_at' => $email->received_at,
                'folder' => $email->folder,
                'ingestion_classification' => $email->ingestion_classification,
                'extracted_po_number' => $email->extracted_po_number,
                'canonical_po' => $email->canonical_po,
                'outcome' => $record->outcome,
            ];
        })->filter()->values();

        return response()->json([
            'sync_run' => [
                'id' => $run->id,
                'folder_name' => $run->folder?->display_name,
                'sync_from' => $run->sync_from,
                'sync_to' => $run->sync_to,
                'emails_stored' => $run->emails_queued,
                'emails_created' => $run->emails_created,
                'emails_updated' => $run->emails_updated,
                'started_at' => $run->started_at,
            ],
            'emails' => $emails,
        ]);
    }

    public function test(MailboxFolder $folder): JsonResponse
    {
        $token = $this->outlook->getDecryptedToken($folder->mailboxAccount);
        $response = Http::withToken($token)->withHeaders(['Prefer' => 'IdType="ImmutableId"'])->timeout(20)->get(
            'https://graph.microsoft.com/v1.0/me/mailFolders/'.rawurlencode($folder->external_folder_id).'/messages',
            ['$top' => 5, '$select' => 'id'],
        );
        return response()->json([
            'ok' => $response->successful(),
            'folder_id' => $folder->id,
            'recent_message_count' => $response->successful() ? count($response->json('value', [])) : 0,
            'message' => $response->successful() ? 'Folder is reachable.' : 'Microsoft Graph returned HTTP '.$response->status(),
        ], $response->successful() ? 200 : 422);
    }

    public function storeRule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mailbox_folder_id' => ['required', 'integer', 'exists:mailbox_folders,id'],
            'existing_rule_name' => ['required', 'string', 'max:255'],
            'customer_id' => ['nullable', 'integer', 'exists:acumatica_customers,id'],
            'is_enabled' => ['sometimes', 'boolean'],
            'is_trusted' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $folder = MailboxFolder::findOrFail($validated['mailbox_folder_id']);
        $rule = MailboxRuleMapping::create($validated + [
            'mailbox_account_id' => $folder->mailbox_account_id,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);
        return response()->json($rule->load('customer'), 201);
    }

    public function updateRule(Request $request, MailboxRuleMapping $rule): JsonResponse
    {
        $validated = $request->validate([
            'existing_rule_name' => ['sometimes', 'string', 'max:255'],
            'customer_id' => ['sometimes', 'nullable', 'integer', 'exists:acumatica_customers,id'],
            'is_enabled' => ['sometimes', 'boolean'], 'is_trusted' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $rule->update($validated + ['updated_by' => $request->user()->id]);
        return response()->json($rule->fresh('customer'));
    }

    public function destroyRule(MailboxRuleMapping $rule): JsonResponse
    {
        $rule->delete();
        return response()->json(['message' => 'Rule mapping removed.']);
    }

    public function reviews(): JsonResponse
    {
        return response()->json(Email::with(['mailboxFolder.rules', 'attachments'])
            ->where('ingestion_classification', 'needs_review')->whereNull('ingestion_review_status')
            ->orderByDesc('received_at')->paginate(50));
    }

    public function review(Request $request, Email $email): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:approved,rejected'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        $email->update([
            'ingestion_review_status' => $validated['decision'],
            'ingestion_review_reason' => $validated['reason'],
            'ingestion_reviewed_by' => $request->user()->id,
            'ingestion_reviewed_at' => now(),
            'ingestion_classification' => $validated['decision'] === 'approved' ? 'po_processing' : 'stored_non_order',
            'ingestion_reason_codes' => array_values(array_unique(array_merge($email->ingestion_reason_codes ?? [], ['manual_ingestion_'.$validated['decision']]))),
        ]);
        return response()->json($email->fresh(['mailboxFolder.rules', 'attachments']));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>|null  $emailCounts
     * @param  \Illuminate\Support\Collection<int, OrderMatchSyncRun>|null  $lastSyncs
     */
    private function present(MailboxFolder $folder, $emailCounts = null, $lastSyncs = null): array
    {
        $data = $folder->toArray();
        $data['suggested_order_folder'] = (bool) preg_match('/\b(PO|ORDER|PURCHASE)S?\b/i', $folder->display_name);

        $data['emails_synced_all_time'] = (int) (
            $emailCounts !== null
                ? ($emailCounts[$folder->id] ?? 0)
                : Email::where('mailbox_folder_id', $folder->id)->count()
        );

        $lastRun = $lastSyncs !== null
            ? ($lastSyncs[$folder->id] ?? null)
            : OrderMatchSyncRun::query()
                ->where('mailbox_folder_id', $folder->id)
                ->where('status', 'completed')
                ->orderByDesc('started_at')
                ->first();

        $data['last_manual_sync_at']    = $lastRun?->ended_at ?? $lastRun?->started_at;
        $data['last_manual_sync_count'] = (int) ($lastRun?->emails_queued ?? 0);

        return $data;
    }
}
