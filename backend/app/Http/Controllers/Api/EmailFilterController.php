<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailFilter;
use App\Services\Email\EmailFilterEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailFilterController extends Controller
{
    public function __construct(private readonly EmailFilterEngine $engine) {}

    public function index(): JsonResponse
    {
        $filters = EmailFilter::orderBy('name')->get();

        return response()->json($filters->map(fn ($f) => $this->present($f)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'      => 'required|string|max:255',
            'type'      => 'required|in:sender_email,sender_domain,subject_keyword',
            'value'     => 'required|string|max:500',
            'is_active' => 'boolean',
        ]);

        $filter = EmailFilter::create($validated);

        return response()->json($this->present($filter), 201);
    }

    public function update(Request $request, EmailFilter $emailFilter): JsonResponse
    {
        $validated = $request->validate([
            'name'      => 'sometimes|string|max:255',
            'type'      => 'sometimes|in:sender_email,sender_domain,subject_keyword',
            'value'     => 'sometimes|string|max:500',
            'is_active' => 'sometimes|boolean',
        ]);

        $emailFilter->update($validated);

        return response()->json($this->present($emailFilter));
    }

    public function destroy(EmailFilter $emailFilter): JsonResponse
    {
        $emailFilter->delete();

        return response()->json(['message' => 'Filter deleted.']);
    }

    private function present(EmailFilter $filter): array
    {
        return [
            ...$filter->toArray(),
            'match_count' => $this->engine->countMatching($filter),
        ];
    }
}
