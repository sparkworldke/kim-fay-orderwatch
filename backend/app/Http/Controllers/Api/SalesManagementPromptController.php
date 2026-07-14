<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesManagementPrompt;
use App\Services\SalesManagement\SalesManagementPromptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesManagementPromptController extends Controller
{
    public function __construct(private readonly SalesManagementPromptService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->service->listQuery($request->user(), $request->input('view'));

        if ($request->filled('type')) {
            $query->where('prompt_type', $request->input('type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('consultant_user_id')) {
            $query->where('consultant_user_id', $request->integer('consultant_user_id'));
        }
        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($scoped) use ($q) {
                $scoped->where('customer_name', 'like', "%{$q}%")
                    ->orWhere('customer_acumatica_id', 'like', "%{$q}%")
                    ->orWhere('consultant_name', 'like', "%{$q}%")
                    ->orWhere('consultant_rep_code', 'like', "%{$q}%")
                    ->orWhere('reason', 'like', "%{$q}%");
            });
        }

        $page = $query->paginate($request->integer('per_page', 50));
        $page->setCollection($page->getCollection()->map(fn (SalesManagementPrompt $prompt) => $this->service->present($prompt)));

        return response()->json($page);
    }

    public function dashboard(Request $request): JsonResponse
    {
        return response()->json($this->service->dashboard($request->user()));
    }

    public function resolve(Request $request, SalesManagementPrompt $prompt): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'min:2', 'max:5000'],
        ]);

        return response()->json($this->service->present(
            $this->service->resolve($request->user(), $prompt, $validated['note']),
        ));
    }

    public function snooze(Request $request, SalesManagementPrompt $prompt): JsonResponse
    {
        $validated = $request->validate([
            'snoozed_until' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json($this->service->present(
            $this->service->snooze($request->user(), $prompt, $validated['snoozed_until'], $validated['note'] ?? null),
        ));
    }

    public function dismiss(Request $request, SalesManagementPrompt $prompt): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:2', 'max:5000'],
        ]);

        return response()->json($this->service->present(
            $this->service->dismiss($request->user(), $prompt, $validated['reason']),
        ));
    }
}
