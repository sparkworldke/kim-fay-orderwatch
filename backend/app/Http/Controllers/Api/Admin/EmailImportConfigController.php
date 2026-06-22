<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailImportConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailImportConfigController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(EmailImportConfig::orderBy('display_name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sender_pattern'        => ['required', 'string', 'max:500'],
            'is_wildcard'           => ['boolean'],
            'display_name'          => ['required', 'string', 'max:255'],
            'customer_class'        => ['nullable', 'string', 'max:100'],
            'po_patterns'           => ['nullable', 'array'],
            'po_patterns.*'         => ['string'],
            'po_extraction_source'  => ['in:subject,body,pdf,all'],
            'ai_fallback_enabled'   => ['boolean'],
            'is_active'             => ['boolean'],
            'notes'                 => ['nullable', 'string'],
        ]);

        $config = EmailImportConfig::create($validated);

        return response()->json($config, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $config = EmailImportConfig::findOrFail($id);

        $validated = $request->validate([
            'sender_pattern'        => ['string', 'max:500'],
            'is_wildcard'           => ['boolean'],
            'display_name'          => ['string', 'max:255'],
            'customer_class'        => ['nullable', 'string', 'max:100'],
            'po_patterns'           => ['nullable', 'array'],
            'po_patterns.*'         => ['string'],
            'po_extraction_source'  => ['in:subject,body,pdf,all'],
            'ai_fallback_enabled'   => ['boolean'],
            'is_active'             => ['boolean'],
            'notes'                 => ['nullable', 'string'],
        ]);

        $config->update($validated);

        return response()->json($config);
    }

    public function destroy(int $id): JsonResponse
    {
        EmailImportConfig::findOrFail($id)->delete();

        return response()->json(['message' => 'Sender config deleted.']);
    }

    /** Test whether a sender email would match any active config. */
    public function testSender(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);

        $config = EmailImportConfig::findForSender($validated['email']);

        return response()->json([
            'email'   => $validated['email'],
            'matched' => $config !== null,
            'config'  => $config,
        ]);
    }
}
