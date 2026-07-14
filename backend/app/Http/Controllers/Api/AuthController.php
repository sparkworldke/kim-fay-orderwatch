<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Team\UserCapabilitiesService;
use App\Services\Team\UserSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Login and issue a Sanctum token.
     */
    public function login(
        Request $request,
        UserSessionService $sessions,
        UserCapabilitiesService $capabilities,
    ): JsonResponse {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials. Please check your email and password.',
            ], 422);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account is not active. Please contact an administrator.',
            ], 403);
        }

        // Revoke all previous tokens so only one active session exists
        $user->tokens()->delete();

        $token = $user->createToken('api-token')->plainTextToken;
        $sessions->open($user, $request, 'password');

        return response()->json([
            'token' => $token,
            'user'  => $this->formatUser($user),
            'capabilities' => $capabilities->forUser($user),
        ]);
    }

    /**
     * Return the authenticated user.
     */
    public function me(Request $request, UserCapabilitiesService $capabilities): JsonResponse
    {
        $user = $request->user()->loadMissing('department');
        $token = $user->currentAccessToken();
        $impersonatorId = null;
        foreach ($token?->abilities ?? [] as $ability) {
            if (is_string($ability) && str_starts_with($ability, 'impersonator:')) {
                $impersonatorId = (int) substr($ability, strlen('impersonator:'));
                break;
            }
        }

        $impersonator = null;
        if ($impersonatorId > 0) {
            $admin = User::query()->find($impersonatorId);
            if ($admin) {
                $impersonator = [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'role' => $admin->role,
                ];
            }
        }

        return response()->json([
            ...$this->formatUser($user),
            'capabilities' => $capabilities->forUser($user),
            'impersonation' => [
                'active' => $impersonator !== null,
                'impersonator' => $impersonator,
            ],
        ]);
    }

    /**
     * Revoke the current token (logout).
     */
    public function logout(Request $request, UserSessionService $sessions): JsonResponse
    {
        $user = $request->user();
        $sessions->closeActiveForUser($user, 'manual');
        $user->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /** @return array<string, mixed> */
    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role ?? 'Administrator',
            'rep_code' => $user->rep_code,
            'employee_number' => $user->employee_number,
            'department_id' => $user->department_id,
            'department_role' => $user->department_role,
            'is_consultant' => (bool) $user->is_consultant,
            'department' => $user->relationLoaded('department') && $user->department
                ? [
                    'id' => $user->department->id,
                    'slug' => $user->department->slug,
                    'name' => $user->department->name,
                ]
                : null,
        ];
    }
}
