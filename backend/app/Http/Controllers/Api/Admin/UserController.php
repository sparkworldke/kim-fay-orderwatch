<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TeamMemberAccountMail;
use App\Models\Otp;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRepCodeHistory;
use App\Models\UserRole;
use App\Services\Admin\AuditLogger;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private const ASSIGNABLE_ROLES = [
        'Administrator',
        'Customer Service Manager',
        'Customer Service Agent',
        'Sales Operations',
        'Sales Consultant',
        'Executive',
    ];

    private const MANAGER_ASSIGNABLE_ROLES = [
        'Sales Consultant',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OtpService $otpService,
    ) {}

    public function index(): JsonResponse
    {
        $query = User::query()->orderBy('name');

        if ($this->isCustomerServiceManager(request()->user())) {
            $query->where('role', 'Sales Consultant');
        }

        $users = $query->get([
                'id',
                'name',
                'email',
                'role',
                'phone_number',
                'rep_code',
                'is_active',
                'is_account_manager',
                'is_super_admin',
                'created_at',
            ]);

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(self::ASSIGNABLE_ROLES)],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'rep_code' => [
                'nullable',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9 ._\\-\\/]+$/',
                Rule::unique('users', 'rep_code')->where('role', 'Sales Consultant'),
            ],
            'is_account_manager' => ['sometimes', 'boolean'],
        ]);

        if (! $this->canAssignRole($request->user(), $validated['role'])) {
            return response()->json([
                'message' => 'You can only create Sales Consultant accounts.',
            ], 403);
        }

        if ($validated['role'] === 'Sales Consultant' && empty(trim((string) ($validated['rep_code'] ?? '')))) {
            return response()->json([
                'message' => 'Rep Code is required for Sales Consultant accounts.',
                'errors' => ['rep_code' => ['Rep Code is required for Sales Consultant accounts.']],
            ], 422);
        }

        if ($validated['role'] === 'Administrator' && ! $request->user()?->is_super_admin) {
            return response()->json([
                'message' => 'Only super admins can create Administrator accounts.',
            ], 403);
        }

        $repCode = $validated['role'] === 'Sales Consultant'
            ? strtoupper(trim((string) ($validated['rep_code'] ?? '')))
            : null;

        $user = User::create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'role' => $validated['role'],
            'phone_number' => $validated['phone_number'] ?? null,
            'rep_code' => $repCode,
            'password' => bcrypt(Str::random(40)),
            'email_verified_at' => now(),
            'is_active' => true,
            'is_account_manager' => $request->user()?->role === 'Administrator'
                ? ($validated['is_account_manager'] ?? false)
                : false,
        ]);

        $role = Role::where('name', $validated['role'])->first();
        if ($role) {
            UserRole::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'role_id' => $role->id,
                    'assigned_by' => $request->user()?->id,
                ],
            );
        }

        [$welcomeOtp, $otpRecord] = $this->issueWelcomeLoginOtp($user);

        try {
            Mail::to($user->email)->send(new TeamMemberAccountMail(
                $user->name,
                $user->email,
                $user->role,
                $request->user()?->name ?? 'An administrator',
                $welcomeOtp,
            ));
        } catch (\Throwable $exception) {
            $otpRecord->delete();
            throw $exception;
        }

        $this->audit->log('team_member_welcome_otp_created', 'user', (string) $user->id, [
            'email' => $user->email,
            'purpose' => 'login',
            'expires_in_minutes' => 15,
        ], $request->user()?->id, $request->ip());

        $this->audit->log('team_member_created', 'user', (string) $user->id, [
            'email' => $user->email,
            'role' => $user->role,
            'rep_code' => $user->rep_code,
        ], $request->user()?->id, $request->ip());

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'phone_number' => $user->phone_number,
            'rep_code' => $user->rep_code,
            'is_active' => $user->is_active,
            'is_account_manager' => $user->is_account_manager,
            'created_at' => $user->created_at,
        ], 201);
    }

    public function resendWelcomeEmail(User $user, Request $request): JsonResponse
    {
        if (! $this->canManageUser($request->user(), $user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        [$welcomeOtp, $otpRecord] = $this->issueWelcomeLoginOtp($user);

        try {
            Mail::to($user->email)->send(new TeamMemberAccountMail(
                $user->name,
                $user->email,
                $user->role,
                $request->user()?->name ?? 'An administrator',
                $welcomeOtp,
            ));
        } catch (\Throwable $exception) {
            $otpRecord->delete();
            throw $exception;
        }

        $this->audit->log('team_member_welcome_resent', 'user', (string) $user->id, [
            'email' => $user->email,
            'purpose' => 'login',
            'expires_in_minutes' => 15,
        ], $request->user()?->id, $request->ip());

        return response()->json(['message' => 'Welcome email sent successfully.']);
    }

    /**
     * Create a fresh login OTP for welcome emails. The plaintext value is only
     * returned so it can be rendered into the outgoing email.
     *
     * @return array{0: string, 1: Otp}
     */
    private function issueWelcomeLoginOtp(User $user): array
    {
        $email = strtolower($user->email);

        Otp::where('email', $email)
            ->where('purpose', 'login')
            ->delete();

        $otp = $this->otpService->generate();
        $record = Otp::create([
            'user_id' => $user->id,
            'email' => $email,
            'purpose' => 'login',
            'otp_hash' => $this->otpService->hash($otp),
            'expires_at' => now()->addMinutes(15),
            'attempts' => 0,
            'resend_attempts' => 0,
            'resend_window_start' => now(),
        ]);

        return [$otp, $record];
    }

    public function toggleStatus(User $user, Request $request): JsonResponse
    {
        if ($user->id === $request->user()?->id) {
            return response()->json(['message' => 'You cannot suspend your own account.'], 403);
        }

        if (! $this->canManageUser($request->user(), $user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $user->is_active = !$user->is_active;
        $user->save();

        $action = $user->is_active ? 'team_member_reactivated' : 'team_member_suspended';
        
        $this->audit->log($action, 'user', (string) $user->id, [
            'email' => $user->email,
        ], $request->user()?->id, $request->ip());

        return response()->json([
            'message' => $user->is_active ? 'Account reactivated successfully.' : 'Account suspended successfully.',
            'is_active' => $user->is_active
        ]);
    }

    public function destroy(User $user, Request $request): JsonResponse
    {
        if ($user->id === $request->user()?->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 403);
        }

        if (! $this->canManageUser($request->user(), $user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // Before deleting, get data for the audit log
        $userEmail = $user->email;
        $userId = $user->id;

        $user->delete();

        $this->audit->log('team_member_deleted', 'user', (string) $userId, [
            'email' => $userEmail,
        ], $request->user()?->id, $request->ip());

        return response()->json(['message' => 'Account deleted successfully.']);
    }

    public function update(User $user, Request $request): JsonResponse
    {
        if (! $this->canManageUser($request->user(), $user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'name'              => ['sometimes', 'required', 'string', 'max:255'],
            'email'             => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role'              => ['sometimes', 'required', Rule::in(self::ASSIGNABLE_ROLES)],
            'phone_number'      => ['sometimes', 'nullable', 'string', 'max:30'],
            'rep_code'          => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9 ._\\-\\/]+$/',
                Rule::unique('users', 'rep_code')->ignore($user->id)->where('role', 'Sales Consultant'),
            ],
            'is_account_manager' => ['sometimes', 'boolean'],
            'change_reason'      => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if (isset($validated['role']) && ! $this->canAssignRole($request->user(), $validated['role'])) {
            return response()->json(['message' => 'You cannot assign this role.'], 403);
        }

        // Rep code history: track the old value before updating
        $newRepCode = array_key_exists('rep_code', $validated)
            ? (($validated['rep_code'] !== null && $validated['rep_code'] !== '')
                ? strtoupper(trim((string) $validated['rep_code']))
                : null)
            : null;

        $repCodeChanged = array_key_exists('rep_code', $validated)
            && $newRepCode !== $user->rep_code;

        if ($repCodeChanged) {
            UserRepCodeHistory::create([
                'user_id'         => $user->id,
                'rep_code'        => $user->rep_code,
                'changed_by_name' => $request->user()?->name,
                'changed_by'      => $request->user()?->id,
                'change_reason'   => $validated['change_reason'] ?? null,
                'changed_at'      => now(),
            ]);
        }

        $changes = [];
        foreach (['name', 'email', 'role', 'phone_number'] as $field) {
            if (isset($validated[$field])) {
                $changes[$field] = ['from' => $user->$field, 'to' => $validated[$field]];
                $user->$field = $validated[$field];
            }
        }

        if ($repCodeChanged) {
            $changes['rep_code'] = ['from' => $user->rep_code, 'to' => $newRepCode];
            $user->rep_code = $newRepCode;
        }

        if (isset($validated['is_account_manager']) && $request->user()?->role === 'Administrator') {
            $changes['is_account_manager'] = ['from' => $user->is_account_manager, 'to' => $validated['is_account_manager']];
            $user->is_account_manager = $validated['is_account_manager'];
        }

        $user->save();

        // Sync role pivot table if role changed
        if (isset($validated['role'])) {
            $role = Role::where('name', $validated['role'])->first();
            if ($role) {
                UserRole::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'role_id'     => $role->id,
                        'assigned_by' => $request->user()?->id,
                    ],
                );
            }
        }

        $this->audit->log('team_member_updated', 'user', (string) $user->id, [
            'email'   => $user->email,
            'changes' => $changes,
        ], $request->user()?->id, $request->ip());

        return response()->json([
            'id'               => $user->id,
            'name'             => $user->name,
            'email'            => $user->email,
            'role'             => $user->role,
            'phone_number'     => $user->phone_number,
            'rep_code'         => $user->rep_code,
            'is_active'        => $user->is_active,
            'is_account_manager' => $user->is_account_manager,
            'is_super_admin'   => $user->is_super_admin,
            'created_at'       => $user->created_at,
        ]);
    }

    public function repCodeHistory(User $user, Request $request): JsonResponse
    {
        if (! $this->canManageUser($request->user(), $user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $history = UserRepCodeHistory::where('user_id', $user->id)
            ->orderByDesc('changed_at')
            ->get(['id', 'rep_code', 'changed_by_name', 'changed_by', 'change_reason', 'changed_at', 'created_at']);

        return response()->json($history);
    }

    public function restoreRepCode(User $user, UserRepCodeHistory $historyEntry, Request $request): JsonResponse
    {
        if (! $this->canManageUser($request->user(), $user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($historyEntry->user_id !== $user->id) {
            return response()->json(['message' => 'Invalid history entry for this user.'], 422);
        }

        // Save current rep code to history before restoring
        UserRepCodeHistory::create([
            'user_id'         => $user->id,
            'rep_code'        => $user->rep_code,
            'changed_by_name' => $request->user()?->name,
            'changed_by'      => $request->user()?->id,
            'change_reason'   => 'Restored from history',
            'changed_at'      => now(),
        ]);

        $oldRepCode = $user->rep_code;
        $newRepCode = $historyEntry->rep_code;
        $user->rep_code = $newRepCode;
        $user->save();

        $this->audit->log('team_member_updated', 'user', (string) $user->id, [
            'email'   => $user->email,
            'changes' => ['rep_code' => ['from' => $oldRepCode, 'to' => $newRepCode]],
        ], $request->user()?->id, $request->ip());

        return response()->json([
            'id'               => $user->id,
            'name'             => $user->name,
            'email'            => $user->email,
            'role'             => $user->role,
            'phone_number'     => $user->phone_number,
            'rep_code'         => $user->rep_code,
            'is_active'        => $user->is_active,
            'is_account_manager' => $user->is_account_manager,
            'is_super_admin'   => $user->is_super_admin,
            'created_at'       => $user->created_at,
        ]);
    }

    private function canAssignRole(?User $actor, string $role): bool
    {
        if ($actor?->role === 'Administrator') {
            return true;
        }

        if ($this->isCustomerServiceManager($actor)) {
            return in_array($role, self::MANAGER_ASSIGNABLE_ROLES, true);
        }

        return false;
    }

    private function canManageUser(?User $actor, User $target): bool
    {
        if ($actor?->role === 'Administrator') {
            return true;
        }

        return $this->isCustomerServiceManager($actor)
            && $target->role === 'Sales Consultant';
    }

    private function isCustomerServiceManager(?User $user): bool
    {
        return $user?->role === 'Customer Service Manager';
    }
}
