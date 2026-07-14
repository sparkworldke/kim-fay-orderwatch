<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TeamMemberAccountMail;
use App\Models\Otp;
use App\Models\Role;
use App\Models\User;
use App\Models\Department;
use App\Models\UserAcumaticaRepMapping;
use App\Models\UserRepCodeHistory;
use App\Models\UserRole;
use App\Services\Admin\AuditLogger;
use App\Services\OtpService;
use App\Services\Team\OrgTreeService;
use App\Services\Team\UserOrgService;
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
        'Technician Manager',
        'Technician',
    ];

    private const MANAGER_ASSIGNABLE_ROLES = [
        'Sales Consultant',
    ];

    private const ORG_LEVELS = [
        'executive',
        'c_suite',
        'hod',
        'sales',
        'brandsops',
        'operations',
        'gap',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OtpService $otpService,
        private readonly UserOrgService $userOrg,
        private readonly OrgTreeService $orgTree,
    ) {}

    /**
     * Dynamic reports-to picker: any active user except self and reportee subtree.
     * No role/org-level shortlist — hierarchy is fully free-form.
     */
    public function reportsToOptions(Request $request, ?User $user = null): JsonResponse
    {
        // Route may be /users/reports-to-options (create) or /users/{user}/reports-to-options (edit)
        $forUserId = $user?->id;
        if ($forUserId !== null && ! $this->canManageUser($request->user(), $user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $managers = $this->orgTree->eligibleManagers($forUserId);

        return response()->json([
            'for_user_id' => $forUserId,
            'guardrail' => 'Any active user may be selected as manager except self and their reportees (cycle prevention).',
            'managers' => $managers->map(fn (User $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'email' => $m->email,
                'role' => $m->role,
                'org_level' => $m->org_level,
                'is_active' => (bool) $m->is_active,
            ])->values(),
        ]);
    }

    public function index(): JsonResponse
    {
        $query = User::query()->orderBy('name');

        if ($this->isCustomerServiceManager(request()->user())) {
            $query->where('role', 'Sales Consultant');
        }

        $users = $query
            ->with([
                'department:id,slug,name,is_customer_facing',
                'departments:id,slug,name,is_customer_facing',
                'sectorScopes',
                'reportsTo:id,name,email',
            ])
            ->get([
                'id',
                'name',
                'email',
                'role',
                'phone_number',
                'rep_code',
                'employee_number',
                'department_id',
                'department_role',
                'org_level',
                'reports_to_user_id',
                'product_type_scope',
                'data_scope_mode',
                'is_shared_mailbox',
                'is_consultant',
                'is_active',
                'is_account_manager',
                'is_super_admin',
                'created_at',
            ]);

        return response()->json($users->map(fn (User $user) => $this->teamMemberPayload($user)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(self::ASSIGNABLE_ROLES)],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'rep_code' => [
                'nullable',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9 ._\\-\\/]+$/',
                Rule::unique('users', 'rep_code')->where('role', 'Sales Consultant'),
            ],
            'is_account_manager' => ['sometimes', 'boolean'],
            'employee_number' => ['nullable', 'string', 'max:50'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'department_ids' => ['sometimes', 'array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
            'department_role' => ['nullable', Rule::in(['member', 'hod', 'executive'])],
            'org_level' => ['nullable', Rule::in(self::ORG_LEVELS)],
            'reports_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'product_type_scope' => ['nullable', Rule::in(['manufactured', 'trading', 'both'])],
            'data_scope_mode' => ['nullable', Rule::in(['org_wide', 'scoped', 'deny_all'])],
            'sector_scopes' => ['sometimes', 'array'],
            'sector_scopes.*' => ['string', Rule::in(['GT', 'MT', 'KP', 'ALL'])],
            'is_consultant' => ['sometimes', 'boolean'],
            'is_shared_mailbox' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
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

        $suggestedPassword = $this->generateSuggestedPassword();
        $verifiedAt = now();

        $user = User::create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'role' => $validated['role'],
            'phone_number' => $validated['phone_number'] ?? null,
            'rep_code' => $repCode,
            'employee_number' => $validated['employee_number'] ?? null,
            'department_id' => $validated['department_id'] ?? null,
            'department_role' => $validated['department_role'] ?? 'member',
            'org_level' => $validated['org_level'] ?? $this->inferOrgLevel($validated),
            // Manager is applied in applyOrgConfig after cycle validation.
            'reports_to_user_id' => null,
            'product_type_scope' => $validated['product_type_scope'] ?? 'both',
            'data_scope_mode' => $validated['data_scope_mode']
                ?? $this->userOrg->defaultDataScopeMode($validated['org_level'] ?? $this->inferOrgLevel($validated)),
            'is_shared_mailbox' => (bool) ($validated['is_shared_mailbox'] ?? false),
            'is_consultant' => (bool) ($validated['is_consultant'] ?? ($validated['role'] === 'Sales Consultant')),
            'password' => bcrypt($suggestedPassword),
            'email_verified_at' => $verifiedAt,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'is_account_manager' => $request->user()?->role === 'Administrator'
                ? ($validated['is_account_manager'] ?? false)
                : false,
        ]);

        try {
            $user = $this->userOrg->applyOrgConfig($user, $validated, $request->user());
        } catch (\InvalidArgumentException $exception) {
            $user->delete();

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $this->syncUserRoles($user, $validated['role'], $validated['role_ids'] ?? [], $request->user());

        [$welcomeOtp, $otpRecord] = $this->issueWelcomeLoginOtp($user);

        try {
            Mail::to($user->email)->send(new TeamMemberAccountMail(
                userName: $user->name,
                email: $user->email,
                role: $user->role,
                invitedByName: $request->user()?->name ?? 'An administrator',
                otp: $welcomeOtp,
                suggestedPassword: $suggestedPassword,
                otpExpiresAt: $otpRecord->expires_at,
                accountVerifiedAt: $user->email_verified_at ?? $verifiedAt,
                credentialsIssuedAt: now(),
                isResend: false,
            ));
        } catch (\Throwable $exception) {
            $otpRecord->delete();
            throw $exception;
        }

        $this->audit->log('team_member_welcome_otp_created', 'user', (string) $user->id, [
            'email' => $user->email,
            'purpose' => 'login',
            'expires_in_minutes' => 15,
            'otp_expires_at' => optional($otpRecord->expires_at)?->toIso8601String(),
            'password_issued' => true,
        ], $request->user()?->id, $request->ip());

        $this->audit->log('team_member_created', 'user', (string) $user->id, [
            'email' => $user->email,
            'role' => $user->role,
            'rep_code' => $user->rep_code,
        ], $request->user()?->id, $request->ip());

        return response()->json($this->teamMemberPayload($user), 201);
    }

    public function resendWelcomeEmail(User $user, Request $request): JsonResponse
    {
        if (! $this->canManageUser($request->user(), $user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $suggestedPassword = $this->generateSuggestedPassword();
        $user->password = bcrypt($suggestedPassword);
        if ($user->email_verified_at === null) {
            $user->email_verified_at = now();
        }
        $user->save();

        [$welcomeOtp, $otpRecord] = $this->issueWelcomeLoginOtp($user);

        try {
            Mail::to($user->email)->send(new TeamMemberAccountMail(
                userName: $user->name,
                email: $user->email,
                role: $user->role,
                invitedByName: $request->user()?->name ?? 'An administrator',
                otp: $welcomeOtp,
                suggestedPassword: $suggestedPassword,
                otpExpiresAt: $otpRecord->expires_at,
                accountVerifiedAt: $user->email_verified_at,
                credentialsIssuedAt: now(),
                isResend: true,
            ));
        } catch (\Throwable $exception) {
            $otpRecord->delete();
            throw $exception;
        }

        $this->audit->log('team_member_welcome_resent', 'user', (string) $user->id, [
            'email' => $user->email,
            'purpose' => 'login',
            'expires_in_minutes' => 15,
            'otp_expires_at' => optional($otpRecord->expires_at)?->toIso8601String(),
            'password_rotated' => true,
        ], $request->user()?->id, $request->ip());

        return response()->json(['message' => 'Welcome email sent successfully with a new temporary password.']);
    }

    /**
     * Admin/manager sets a user's password (auto-generated or manual).
     * Optionally emails the new credentials with a fresh OTP.
     */
    public function updatePassword(User $user, Request $request): JsonResponse
    {
        if (! $this->canManageUser($request->user(), $user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'auto_generate' => ['required', 'boolean'],
            'password' => [
                'nullable',
                'string',
                'min:8',
                'max:72',
                Rule::requiredIf(fn () => ! $request->boolean('auto_generate')),
            ],
            'email_user' => ['sometimes', 'boolean'],
        ]);

        $autoGenerate = (bool) $validated['auto_generate'];
        $emailUser = array_key_exists('email_user', $validated)
            ? (bool) $validated['email_user']
            : true;

        $plainPassword = $autoGenerate
            ? $this->generateSuggestedPassword()
            : (string) $validated['password'];

        $user->password = bcrypt($plainPassword);
        if ($user->email_verified_at === null) {
            $user->email_verified_at = now();
        }
        $user->save();

        $otpRecord = null;
        if ($emailUser) {
            [$welcomeOtp, $otpRecord] = $this->issueWelcomeLoginOtp($user);

            try {
                Mail::to($user->email)->send(new TeamMemberAccountMail(
                    userName: $user->name,
                    email: $user->email,
                    role: $user->role,
                    invitedByName: $request->user()?->name ?? 'An administrator',
                    otp: $welcomeOtp,
                    suggestedPassword: $plainPassword,
                    otpExpiresAt: $otpRecord->expires_at,
                    accountVerifiedAt: $user->email_verified_at,
                    credentialsIssuedAt: now(),
                    isResend: true,
                ));
            } catch (\Throwable $exception) {
                $otpRecord->delete();
                throw $exception;
            }
        }

        $this->audit->log('team_member_password_updated', 'user', (string) $user->id, [
            'email' => $user->email,
            'auto_generate' => $autoGenerate,
            'emailed' => $emailUser,
            'otp_expires_at' => optional($otpRecord?->expires_at)?->toIso8601String(),
        ], $request->user()?->id, $request->ip());

        $message = $emailUser
            ? 'Password updated and credentials emailed to the user.'
            : 'Password updated successfully.';

        return response()->json([
            'message' => $message,
            'auto_generate' => $autoGenerate,
            'emailed' => $emailUser,
            // Return plaintext only when auto-generated so admins can copy it.
            'password' => $autoGenerate ? $plainPassword : null,
        ]);
    }

    /**
     * Human-friendly temporary password for welcome / resend emails.
     * Letters + numbers only (no symbols) so it is easy to type from email.
     */
    private function generateSuggestedPassword(int $length = 12): string
    {
        return Str::password($length, letters: true, numbers: true, symbols: false, spaces: false);
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

    /**
     * Bulk-activate multiple users and set their email_verified_at timestamp.
     */
    public function bulkActivate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_ids'          => ['required', 'array', 'min:1', 'max:200'],
            'user_ids.*'        => ['integer', 'exists:users,id'],
            'set_verified_date' => ['sometimes', 'boolean'],
        ]);

        $actor = $request->user();
        $setVerifiedDate = $validated['set_verified_date'] ?? false;

        $users = User::whereIn('id', $validated['user_ids'])->get();
        $activatedCount = 0;

        foreach ($users as $user) {
            if ($user->id === $actor?->id) {
                continue; // never activate self via bulk
            }

            if (! $this->canManageUser($actor, $user)) {
                continue; // skip users the actor can't manage
            }

            $changed = false;

            if (! $user->is_active) {
                $user->is_active = true;
                $changed = true;
            }

            if ($setVerifiedDate && ! $user->email_verified_at) {
                $user->email_verified_at = now();
                $changed = true;
            }

            if ($changed) {
                $user->save();
                $activatedCount++;
            }

            $this->audit->log(
                'team_member_reactivated',
                'user',
                (string) $user->id,
                ['email' => $user->email, 'bulk' => true],
                $actor?->id,
                $request->ip(),
            );
        }

        return response()->json([
            'message'         => $activatedCount . ' user(s) activated successfully.',
            'activated_count' => $activatedCount,
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
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
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
            'employee_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'department_ids' => ['sometimes', 'array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
            'department_role' => ['sometimes', Rule::in(['member', 'hod', 'executive'])],
            'org_level' => ['sometimes', Rule::in(self::ORG_LEVELS)],
            'reports_to_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'product_type_scope' => ['sometimes', Rule::in(['manufactured', 'trading', 'both'])],
            'data_scope_mode' => ['sometimes', Rule::in(['org_wide', 'scoped', 'deny_all'])],
            'sector_scopes' => ['sometimes', 'array'],
            'sector_scopes.*' => ['string', Rule::in(['GT', 'MT', 'KP', 'ALL'])],
            'is_consultant' => ['sometimes', 'boolean'],
            'is_shared_mailbox' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
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
        // reports_to_user_id is applied via UserOrgService (cycle check before write).
        foreach ([
            'name', 'email', 'role', 'phone_number', 'employee_number', 'department_id',
            'department_role', 'org_level', 'product_type_scope',
            'data_scope_mode', 'is_consultant', 'is_shared_mailbox', 'is_active',
        ] as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field] = ['from' => $user->$field, 'to' => $validated[$field]];
                $user->$field = $validated[$field];
            }
        }

        if ($repCodeChanged) {
            $changes['rep_code'] = ['from' => $user->rep_code, 'to' => $newRepCode];
            $user->rep_code = $newRepCode;
        }

        if (array_key_exists('is_account_manager', $validated) && $request->user()?->role === 'Administrator') {
            $changes['is_account_manager'] = ['from' => $user->is_account_manager, 'to' => $validated['is_account_manager']];
            $user->is_account_manager = $validated['is_account_manager'];
        }

        $user->save();

        if (array_key_exists('department_ids', $validated)
            || array_key_exists('sector_scopes', $validated)
            || array_key_exists('org_level', $validated)
            || array_key_exists('reports_to_user_id', $validated)
            || array_key_exists('product_type_scope', $validated)
            || array_key_exists('data_scope_mode', $validated)
            || array_key_exists('department_role', $validated)
            || array_key_exists('is_shared_mailbox', $validated)) {
            try {
                $beforeReportsTo = $user->reports_to_user_id;
                $this->userOrg->applyOrgConfig($user, $validated, $request->user());
                $user->refresh();
                if (array_key_exists('reports_to_user_id', $validated)
                    && $beforeReportsTo !== $user->reports_to_user_id) {
                    $changes['reports_to_user_id'] = [
                        'from' => $beforeReportsTo,
                        'to' => $user->reports_to_user_id,
                    ];
                }
            } catch (\InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }
        } elseif (array_key_exists('department_id', $validated) && $validated['department_id'] !== null) {
            $this->userOrg->syncDepartments(
                $user,
                [(int) $validated['department_id']],
                (int) $validated['department_id'],
                (string) ($validated['department_role'] ?? $user->department_role ?? 'member'),
            );
        }

        // Sync primary + additional role pivot table when role selection changed.
        if (isset($validated['role']) || array_key_exists('role_ids', $validated)) {
            $this->syncUserRoles(
                $user,
                $validated['role'] ?? $user->role,
                $validated['role_ids'] ?? $user->roles()->pluck('roles.id')->all(),
                $request->user(),
            );
        }

        $this->audit->log('team_member_updated', 'user', (string) $user->id, [
            'email'   => $user->email,
            'changes' => $changes,
        ], $request->user()?->id, $request->ip());

        return response()->json($this->teamMemberPayload($user));
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

        return response()->json($this->teamMemberPayload($user));
    }

    public function acumaticaRepMappings(User $user, Request $request): JsonResponse
    {
        if (! $this->canManageUser($request->user(), $user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(
            $user->acumaticaRepMappings()->orderByDesc('is_primary')->get(),
        );
    }

    public function storeAcumaticaRepMapping(User $user, Request $request): JsonResponse
    {
        if ($request->user()?->role !== 'Administrator') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'acumatica_consultant_id' => ['nullable', 'string', 'max:50'],
            'acumatica_rep_code' => ['nullable', 'string', 'max:50'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        if ($validated['is_primary'] ?? true) {
            UserAcumaticaRepMapping::query()
                ->where('user_id', $user->id)
                ->update(['is_primary' => false]);
        }

        $mapping = UserAcumaticaRepMapping::create([
            'user_id' => $user->id,
            'acumatica_consultant_id' => $validated['acumatica_consultant_id'] ?? null,
            'acumatica_rep_code' => isset($validated['acumatica_rep_code'])
                ? strtoupper(trim($validated['acumatica_rep_code']))
                : null,
            'is_primary' => (bool) ($validated['is_primary'] ?? true),
            'created_by' => $request->user()?->id,
        ]);

        return response()->json($mapping, 201);
    }

    /** @return array<string, mixed> */
    private function teamMemberPayload(User $user): array
    {
        $user->loadMissing([
            'department:id,slug,name,is_customer_facing',
            'departments:id,slug,name,is_customer_facing',
            'sectorScopes',
            'reportsTo:id,name,email',
            'brandAssignments',
        ]);
        $user->loadCount('customerAssignments');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'phone_number' => $user->phone_number,
            'rep_code' => $user->rep_code,
            'employee_number' => $user->employee_number,
            'department_id' => $user->department_id,
            'department_role' => $user->department_role,
            'org_level' => $user->org_level,
            'reports_to_user_id' => $user->reports_to_user_id,
            'reports_to' => $user->reportsTo,
            'product_type_scope' => $user->product_type_scope,
            'data_scope_mode' => $user->data_scope_mode,
            'is_shared_mailbox' => $user->is_shared_mailbox,
            'is_consultant' => $user->is_consultant,
            'department' => $user->department,
            'departments' => $user->departments,
            'department_ids' => $user->departments->pluck('id')->values(),
            'sector_scopes' => $user->sectorScopes->pluck('sector')->values(),
            'brand_assignments' => $user->brandAssignments->pluck('brand')->values(),
            'customer_assignment_count' => $user->customer_assignments_count ?? 0,
            'is_active' => $user->is_active,
            'is_account_manager' => $user->is_account_manager,
            'is_super_admin' => $user->is_super_admin,
            'roles' => $user->roles()->orderBy('name')->get(['roles.id', 'roles.name']),
            'role_ids' => $user->roles()->pluck('roles.id')->values(),
            'created_at' => $user->created_at,
        ];
    }

    /** @param array<int, int|string> $roleIds */
    private function syncUserRoles(User $user, string $primaryRoleName, array $roleIds, ?User $actor): void
    {
        $primaryRole = Role::where('name', $primaryRoleName)->first();
        $ids = collect($roleIds)->map(fn ($id) => (int) $id)->filter()->values();
        if ($primaryRole) {
            $ids->push($primaryRole->id);
        }

        $ids = $ids->unique()->values();
        $user->roles()->syncWithPivotValues($ids->all(), ['assigned_by' => $actor?->id]);
    }

    /** @param  array<string, mixed>  $validated */
    private function inferOrgLevel(array $validated): string
    {
        $departmentRole = $validated['department_role'] ?? 'member';
        if ($departmentRole === 'executive') {
            return 'executive';
        }
        if ($departmentRole === 'hod') {
            return 'hod';
        }

        $departmentId = $validated['department_id'] ?? null;
        if ($departmentId !== null) {
            $department = Department::query()->find($departmentId);
            if ($department !== null && ! $department->is_customer_facing) {
                return 'operations';
            }
        }

        return 'sales';
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
