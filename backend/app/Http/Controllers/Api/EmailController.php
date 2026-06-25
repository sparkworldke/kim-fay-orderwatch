<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Services\Email\InboxEmailGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailController extends Controller
{
    public function __construct(private readonly InboxEmailGroupService $inboxGroups)
    {
    }

    public function inboxGroups(Request $request): JsonResponse
    {
        return response()->json($this->inboxGroups->build($request));
    }

    public function index(Request $request): JsonResponse
    {
        $query = Email::with(['mailboxFolder.rules'])->orderByDesc('received_at');

        if ($request->filled('mailbox_id')) {
            $query->where('mailbox_account_id', $request->integer('mailbox_id'));
        }

        if ($request->filled('search')) {
            $term = '%' . strtolower($request->string('search')->trim()) . '%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(subject) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(from_email) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(from_name) LIKE ?', [$term]);
            });
        }

        if ($request->filled('is_read')) {
            $query->where('is_read', (bool) $request->string('is_read'));
        }

        $perPage = min((int) $request->input('per_page', 50), 200); $emails = $query->paginate($perPage);

        return response()->json($emails);
    }
}
