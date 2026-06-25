<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncMailboxFolderJob;
use App\Models\Email;
use App\Models\MailboxFolder;
use App\Services\OrderMatch\OrderMatchAcceptService;
use App\Services\OrderMatch\OrderMatchAiMatchingService;
use App\Services\OrderMatch\OrderMatchFolderSyncService;
use App\Services\OrderMatch\OrderMatchPipelineService;
use App\Services\OrderMatch\OrderMatchQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderMatchController extends Controller
{
    public function __construct(
        private readonly OrderMatchFolderSyncService $folderSync,
        private readonly OrderMatchQueueService $queue,
        private readonly OrderMatchAcceptService $acceptService,
        private readonly OrderMatchAiMatchingService $aiMatching,
        private readonly OrderMatchPipelineService $pipeline,
    ) {
    }

    public function listFolders(): JsonResponse
    {
        $folders = MailboxFolder::with('mailboxAccount:id,email')
            ->where('is_active', true)
            ->orderBy('display_name')
            ->get()
            ->map(fn (MailboxFolder $f) => [
                'id'              => $f->id,
                'folder_name'     => $f->display_name,
                'mailbox_email'   => $f->mailboxAccount?->email,
                'sync_enabled'    => $f->is_sync_enabled,
                'is_order_folder' => $f->is_order_folder,
                'auto_sync_cron'  => $f->auto_sync_cron,
                'last_synced_at'  => $f->last_synced_at,
            ]);

        return response()->json(['folders' => $folders]);
    }

    public function registerFolder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'folder_id'       => ['required', 'integer', 'exists:mailbox_folders,id'],
            'sync_enabled'    => ['sometimes', 'boolean'],
            'is_order_folder' => ['sometimes', 'boolean'],
            'auto_sync_cron'  => ['nullable', 'string', 'max:100'],
        ]);

        $folder = MailboxFolder::findOrFail($validated['folder_id']);
        $folder->update(collect($validated)->except('folder_id')->filter()->all());

        return response()->json(['folder' => $folder->fresh()]);
    }

    public function syncFolder(Request $request, int $folderId): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'string', 'max:35'],
            'to'   => ['required', 'string', 'max:35'],
        ]);

        $folder = MailboxFolder::findOrFail($folderId);
        $run    = $this->folderSync->start($folder, $validated['from'], $validated['to'], $request->user()?->id);
        $runId  = $run->id;
        $from   = $validated['from'];
        $to     = $validated['to'];

        defer(function () use ($runId, $from, $to) {
            SyncMailboxFolderJob::dispatchSync($runId, $from, $to);
        });

        return response()->json([
            'sync_id'                     => $run->id,
            'folder_name'                 => $folder->display_name,
            'emails_found'                => 0,
            'emails_queued'               => 0,
            'status'                      => 'processing',
            'estimated_completion_seconds' => 120,
            'message'                     => 'Sync started. Emails will be imported in the background.',
        ], 202);
    }

    public function runPipeline(Request $request): JsonResponse
    {
        @set_time_limit(300);

        $result = $this->pipeline->runExtractionAndMatching($request->user()?->id);

        return response()->json($result);
    }

    public function queue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status'    => ['sometimes', 'string', 'in:pending,accepted,rejected,all'],
            'accountId' => ['sometimes', 'string', 'max:50'],
            'page'      => ['sometimes', 'integer', 'min:1'],
            'pageSize'  => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->queue->queue(
            $validated['status'] ?? 'pending',
            $validated['accountId'] ?? null,
            (int) ($validated['page'] ?? 1),
            (int) ($validated['pageSize'] ?? 50),
        ));
    }

    public function accept(Request $request, Email $email): JsonResponse
    {
        $top = $email->predictions()->where('is_top_prediction', true)->first();
        if ($top && (float) $top->confidence < 0.75) {
            $request->validate(['confirm_low_confidence' => ['required', 'boolean', 'accepted']]);
        }

        return response()->json($this->acceptService->accept($email, $request->user()->id));
    }

    public function reject(Request $request, Email $email): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        return response()->json($this->acceptService->reject($email, $validated['reason'], $request->user()->id));
    }

    public function markDuplicate(Request $request, Email $email): JsonResponse
    {
        $validated = $request->validate([
            'canonical_email_id' => ['required', 'integer', 'exists:emails,id'],
        ]);

        return response()->json($this->acceptService->markDuplicate(
            $email,
            $validated['canonical_email_id'],
            $request->user()->id,
        ));
    }

    public function rerun(Email $email): JsonResponse
    {
        $prediction = $this->aiMatching->score($email->load('mailboxFolder.customer'));

        return response()->json(['top_prediction' => $prediction]);
    }

    public function auditLog(Request $request): JsonResponse
    {
        $page = (int) $request->input('page', 1);
        $size = (int) $request->input('pageSize', 50);

        return response()->json($this->queue->auditLog($page, $size));
    }

    public function exportAuditLog(): StreamedResponse
    {
        $rows = \App\Models\MatchLog::orderByDesc('created_at')->limit(5000)->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'email_id', 'order_nbr', 'status', 'canonical_po', 'accepted_by', 'accepted_at', 'rejection_reason']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->id, $row->email_id, $row->order_nbr, $row->status,
                    $row->canonical_po, $row->accepted_by, $row->accepted_at, $row->rejection_reason,
                ]);
            }
            fclose($out);
        }, 'order-match-audit-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }
}