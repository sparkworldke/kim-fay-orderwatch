<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AuditLogger;
use App\Services\Team\UserCapabilitiesService;
use App\Services\Team\UserSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Admin-only user impersonation for support and testing.
 * Start requires Administrator; stop works while impersonating (token ability).
 */
class ImpersonationController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly UserCapabilitiesService $capabilities,
        private readonly UserSessionService $sessions,
    ) {}

    /** List active users Admin can switch into. */
    public function candidates(Request $request): JsonResponse
    {
        $this->ensureAdmin($request->user());

        $q = trim((string) $request->input('q', ''));

        $users = User::query()
            ->where('is_active', true)
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('role', 'like', "%{$q}%")
                        ->orWhere('rep_code', 'like', "%{$q}%")
                        ->orWhere('employee_number', 'like', "%{$q}%");
                });
            })
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'email', 'role', 'rep_code', 'employee_number', 'is_consultant']);

        return response()->json([
            'items' => $users->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'rep_code' => $u->rep_code,
                'employee_number' => $u->employee_number,
                'is_consultant' => (bool) $u->is_consultant,
            ])->values(),
        ]);
    }

    /** Start acting as another user (new Sanctum token for target). */
    public function start(Request $request): JsonResponse
    {
        $admin = $request->user();
        $this->ensureAdmin($admin);

        if ($this->impersonatorIdFromToken($admin->currentAccessToken()) !== null) {
            return response()->json([
                'message' => 'Already impersonating. Stop the current session first.',
            ], 422);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $target = User::query()->findOrFail($validated['user_id']);

        if (! $target->is_active) {
            return response()->json(['message' => 'Cannot impersonate an inactive user.'], 422);
        }

        if ((int) $target->id === (int) $admin->id) {
            return response()->json(['message' => 'You are already that user.'], 422);
        }

        // Issue token for target; abilities encode real admin id for safe stop.
        $token = $target->createToken(
            'impersonation',
            ['impersonated', 'impersonator:'.$admin->id],
            now()->addHours(4),
        );

        $this->sessions->open($target, $request, 'impersonation');

        $this->audit->log('impersonation_started', 'user', (string) $target->id, [
            'admin_id' => $admin->id,
            'admin_email' => $admin->email,
            'target_id' => $target->id,
            'target_email' => $target->email,
            'target_role' => $target->role,
        ], $admin->id, $request->ip());

        $target->loadMissing('department');

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => $this->formatUser($target),
            'capabilities' => $this->capabilities->forUser($target),
            'impersonation' => [
                'active' => true,
                'impersonator' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'role' => $admin->role,
                ],
                'expires_in_hours' => 4,
            ],
        ]);
    }

    /**
     * Stop impersonation and return a fresh admin token.
     * Callable while impersonating (not admin.only).
     */
    public function stop(Request $request): JsonResponse
    {
        $current = $request->user();
        $accessToken = $current->currentAccessToken();
        $adminId = $this->impersonatorIdFromToken($accessToken);

        if ($adminId === null) {
            return response()->json(['message' => 'Not currently impersonating.'], 422);
        }

        $admin = User::query()->find($adminId);
        if (! $admin || ! $admin->is_active) {
            $accessToken?->delete();

            return response()->json(['message' => 'Original admin account is unavailable. Please log in again.'], 403);
        }

        if ($admin->role !== 'Administrator' && ! $admin->is_super_admin) {
            $accessToken?->delete();

            return response()->json(['message' => 'Original account is no longer an administrator.'], 403);
        }

        // End impersonated session token
        $accessToken?->delete();
        $this->sessions->closeActiveForUser($current, 'impersonation_end');

        // Fresh admin token (do not wipe all admin tokens if they have others)
        $token = $admin->createToken('api-token', ['*'], now()->addHours(8));
        $this->sessions->open($admin, $request, 'impersonation_return');

        $this->audit->log('impersonation_stopped', 'user', (string) $current->id, [
            'admin_id' => $admin->id,
            'admin_email' => $admin->email,
            'was_user_id' => $current->id,
            'was_user_email' => $current->email,
        ], $admin->id, $request->ip());

        $admin->loadMissing('department');

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => $this->formatUser($admin),
            'capabilities' => $this->capabilities->forUser($admin),
            'impersonation' => [
                'active' => false,
                'impersonator' => null,
            ],
        ]);
    }

    private function ensureAdmin(?User $user): void
    {
        if (! $user || ($user->role !== 'Administrator' && ! $user->is_super_admin)) {
            abort(403, 'Only administrators can impersonate users.');
        }
    }

    private function impersonatorIdFromToken(mixed $token): ?int
    {
        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        foreach ($token->abilities ?? [] as $ability) {
            if (is_string($ability) && str_starts_with($ability, 'impersonator:')) {
                $id = (int) substr($ability, strlen('impersonator:'));

                return $id > 0 ? $id : null;
            }
        }

        return null;
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
