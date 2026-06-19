<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * Return the authenticated user's profile.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id'           => $user->id,
            'name'         => $user->name,
            'email'        => $user->email,
            'role'         => $user->role,
            'phone_number' => $user->phone_number,
            'updated_at'   => $user->updated_at,
        ]);
    }

    /**
     * Update the authenticated user's profile (name and/or phone_number).
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate(
            [
                'name'         => 'sometimes|string|min:2|max:100',
                'phone_number' => ['sometimes', 'nullable', 'regex:/^\+[1-9]\d{6,14}$/'],
            ],
            [
                'name.min'            => 'Name must be between 2 and 100 characters.',
                'name.max'            => 'Name must be between 2 and 100 characters.',
                'phone_number.regex'  => 'Phone number must be in international format (e.g., +254712345678).',
            ]
        );

        $user = $request->user();
        $user->fill($validated);
        $user->save();

        return response()->json([
            'id'           => $user->id,
            'name'         => $user->name,
            'email'        => $user->email,
            'role'         => $user->role,
            'phone_number' => $user->phone_number,
            'updated_at'   => $user->updated_at,
        ]);
    }

    /**
     * Return the authenticated user's sign-in logs, paginated at 20 per page.
     */
    public function signInLogs(Request $request): JsonResponse
    {
        $logs = $request->user()
            ->signInLogs()
            ->orderBy('created_at', 'desc')
            ->paginate(20, ['id', 'created_at', 'ip_address', 'user_agent', 'login_mode', 'status']);

        return response()->json($logs);
    }
}
