<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TeamMemberAccountMail;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Admin\AuditLogger;
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
        'Executive',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $users = User::query()
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'email',
                'role',
                'phone_number',
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
            'is_account_manager' => ['sometimes', 'boolean'],
        ]);

        if ($validated['role'] === 'Administrator' && ! $request->user()?->is_super_admin) {
            return response()->json([
                'message' => 'Only super admins can create Administrator accounts.',
            ], 403);
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'role' => $validated['role'],
            'phone_number' => $validated['phone_number'] ?? null,
            'password' => bcrypt(Str::random(40)),
            'email_verified_at' => now(),
            'is_active' => true,
            'is_account_manager' => $validated['is_account_manager'] ?? false,
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

        Mail::to($user->email)->send(new TeamMemberAccountMail(
            $user->name,
            $user->email,
            $user->role,
            $request->user()?->name ?? 'An administrator',
        ));

        $this->audit->log('team_member_created', 'user', (string) $user->id, [
            'email' => $user->email,
            'role' => $user->role,
        ], $request->user()?->id, $request->ip());

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'phone_number' => $user->phone_number,
            'is_active' => $user->is_active,
            'is_account_manager' => $user->is_account_manager,
            'created_at' => $user->created_at,
        ], 201);
    }
}