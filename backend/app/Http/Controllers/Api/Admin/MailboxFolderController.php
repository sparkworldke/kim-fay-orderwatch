<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Models\MailboxAccount;
use App\Models\MailboxFolder;
use App\Models\MailboxRuleMapping;
use App\Services\Email\OutlookEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class MailboxFolderController extends Controller
{
    public function __construct(private readonly OutlookEmailService $outlook) {}

    public function index(MailboxAccount $mailbox): JsonResponse
    {
        return response()->json($mailbox->folders()->with(['customer:id,acumatica_id,name', 'rules.customer:id,acumatica_id,name'])
            ->orderBy('sync_priority')->orderBy('display_name')->get()->map(fn ($folder) => $this->present($folder)));
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

    private function present(MailboxFolder $folder): array
    {
        $data = $folder->toArray();
        $data['suggested_order_folder'] = (bool) preg_match('/\b(PO|ORDER|PURCHASE)S?\b/i', $folder->display_name);
        return $data;
    }
}
