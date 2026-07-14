<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\StaffImportGap;
use App\Models\User;
use App\Services\Team\StaffImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamImportController extends Controller
{
    public function import(Request $request, StaffImportService $importService): JsonResponse
    {
        $validated = $request->validate([
            'dry_run' => ['sometimes', 'boolean'],
            'preserve_manual' => ['sometimes', 'boolean'],
            'min_confidence' => ['sometimes', 'string', 'in:low,medium,high'],
            'path' => ['sometimes', 'string'],
        ]);

        $path = $validated['path'] ?? base_path('../agent-tools/staff_email_match.json');
        if (! is_file($path)) {
            foreach ([
                base_path('../docs/data/staff_email_match.xlsx'),
                base_path('../docs/data/staff_email_match.json'),
                base_path('../agent-tools/staff_email_match.xlsx'),
            ] as $candidate) {
                if (is_file($candidate)) {
                    $path = $candidate;
                    break;
                }
            }
        }

        if (! is_file($path)) {
            return response()->json(['message' => 'Import file not found.'], 404);
        }

        $stats = $importService->import(
            $path,
            (bool) ($validated['dry_run'] ?? false),
            (bool) ($validated['preserve_manual'] ?? true),
            (string) ($validated['min_confidence'] ?? 'high'),
        );

        return response()->json([
            'message' => ($validated['dry_run'] ?? false) ? 'Dry run completed.' : 'Staff import completed.',
            'stats' => $stats,
        ]);
    }

    public function gaps(): JsonResponse
    {
        $gaps = StaffImportGap::query()
            ->where('resolution_status', 'open')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json($gaps);
    }

    public function resolveGap(StaffImportGap $gap, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'resolution_status' => ['required', 'in:linked,ignored'],
            'resolved_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $gap->update([
            'resolution_status' => $validated['resolution_status'],
            'resolved_user_id' => $validated['resolved_user_id'] ?? null,
        ]);

        return response()->json($gap->fresh());
    }

    public function createUserFromGap(StaffImportGap $gap, StaffImportService $importService): JsonResponse
    {
        try {
            $user = $importService->createUserFromGap($gap, request()->user()?->id);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'User created from gap (inactive until you activate).',
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
            ],
            'gap' => $gap->fresh(),
        ], 201);
    }

    public function seedOrgTree(Request $request, \App\Services\Team\OrgTreeSeedService $service): JsonResponse
    {
        $result = $service->seed((bool) $request->boolean('dry_run'));

        return response()->json([
            'message' => $request->boolean('dry_run') ? 'Org tree dry run completed.' : 'Org tree seeded.',
            'result' => $result,
        ]);
    }
}