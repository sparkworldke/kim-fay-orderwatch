<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncMailboxJob;
use App\Models\EmailFilter;
use App\Models\MailboxAccount;
use App\Services\Email\EmailFilterEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EmailFilterController extends Controller
{
    private const FILTER_TYPES = 'sender_email,sender_domain,subject_keyword,received_date,date_range';

    public function __construct(private readonly EmailFilterEngine $engine) {}

    public function index(): JsonResponse
    {
        $filters = EmailFilter::orderBy('name')->get();

        return response()->json($filters->map(fn ($f) => $this->present($f)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $filter = EmailFilter::create($validated);

        return response()->json($this->present($filter), 201);
    }

    public function update(Request $request, EmailFilter $emailFilter): JsonResponse
    {
        $validated = $this->validatePayload($request, partial: true);

        $emailFilter->update($validated);

        return response()->json($this->present($emailFilter));
    }

    public function destroy(EmailFilter $emailFilter): JsonResponse
    {
        $emailFilter->delete();

        return response()->json(['message' => 'Filter deleted.']);
    }

    public function sync(EmailFilter $emailFilter): JsonResponse
    {
        // Include 'error' accounts — a previous failed sync sets status to 'error'
        // but the OAuth credentials are still valid. Syncing will reset to 'connected'
        // on success, or leave as 'error' if the underlying problem persists.
        $mailboxes = MailboxAccount::whereIn('status', ['connected', 'error'])->get();

        if ($mailboxes->isEmpty()) {
            return response()->json(['message' => 'No mailbox accounts found. Connect an Outlook account in the Accounts tab first.'], 422);
        }

        $filterId = $emailFilter->id;
        $ids      = $mailboxes->pluck('id')->all();

        defer(function () use ($ids, $filterId) {
            foreach ($ids as $id) {
                SyncMailboxJob::dispatchSync($id, $filterId);
            }
        });

        Log::channel('mailbox_sync')->info('Filter sync dispatched', [
            'filter_id'     => $emailFilter->id,
            'filter_name'   => $emailFilter->name,
            'mailbox_count' => $mailboxes->count(),
            'triggered_at'  => now()->toISOString(),
        ]);

        return response()->json([
            'message' => "Sync queued for \"{$emailFilter->name}\" across {$mailboxes->count()} mailbox(es). Emails will import in the background.",
        ]);
    }

    private function present(EmailFilter $filter): array
    {
        $conditions = $filter->conditions ?? [];
        $primaryCondition = $conditions[0] ?? null;

        return [
            'id'          => $filter->id,
            'name'        => $filter->name,
            'conditions'  => $conditions,
            'type'        => $primaryCondition['type'] ?? null,
            'value'       => $primaryCondition['value'] ?? null,
            'is_active'   => $filter->is_active,
            'match_count' => $this->engine->countMatching($filter),
            'created_at'  => $filter->created_at,
            'updated_at'  => $filter->updated_at,
        ];
    }

    private function validatePayload(Request $request, bool $partial = false): array
    {
        $this->mergeLegacyConditionPayload($request);

        return $request->validate([
            'name'                  => ($partial ? 'sometimes' : 'required') . '|string|max:255',
            'conditions'            => ($partial ? 'sometimes' : 'required') . '|array|min:1',
            'conditions.*.type'     => 'required_with:conditions|in:' . self::FILTER_TYPES,
            'conditions.*.value'    => 'required_with:conditions|string|max:500',
            'is_active'             => ($partial ? 'sometimes' : 'nullable') . '|boolean',
        ]);
    }

    private function mergeLegacyConditionPayload(Request $request): void
    {
        if ($request->has('conditions')) {
            return;
        }

        $type = $request->input('type');
        $value = $request->input('value');

        if ($type === null && $value === null) {
            return;
        }

        $request->merge([
            'conditions' => [[
                'type'  => $type,
                'value' => $value,
            ]],
        ]);
    }
}
