<?php

namespace App\Services\Team;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;

class UserSessionService
{
    public function open(User $user, Request $request, string $loginMode = 'otp'): UserSession
    {
        return UserSession::create([
            'user_id' => $user->id,
            'login_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
            'login_mode' => $loginMode,
        ]);
    }

    public function closeActiveForUser(User $user, string $reason = 'manual'): void
    {
        UserSession::query()
            ->where('user_id', $user->id)
            ->whereNull('logout_at')
            ->orderByDesc('login_at')
            ->get()
            ->each(fn (UserSession $session) => $session->close($reason));
    }
}