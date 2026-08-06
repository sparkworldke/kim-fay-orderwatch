<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesConsultantDigestController extends Controller
{
    public function index(): JsonResponse
    {
        $lastLogins = UserSession::query()->selectRaw('user_id, MAX(login_at) as last_login_at')->groupBy('user_id');
        $users = User::query()->leftJoinSub($lastLogins, 'last_logins', 'last_logins.user_id', '=', 'users.id')
            ->where('users.role', 'Sales Consultant')->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email', 'users.is_active', 'users.inactivity_digest_enabled',
                'users.last_inactivity_digest_sent_at', 'last_logins.last_login_at']);
        return response()->json(['data' => $users]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless($user->role === 'Sales Consultant', 422, 'Selected user is not a sales consultant.');
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $user->update(['inactivity_digest_enabled' => $data['enabled']]);
        return response()->json(['message' => 'Consultant reminder preference updated.', 'enabled' => $user->inactivity_digest_enabled]);
    }

    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $count = User::query()->where('role', 'Sales Consultant')->update(['inactivity_digest_enabled' => $data['enabled']]);
        return response()->json(['message' => ($data['enabled'] ? 'Activated' : 'Deactivated')." reminders for {$count} sales consultants.", 'updated' => $count]);
    }
}
