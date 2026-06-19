<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    /**
     * Return sign-in logs for any user (Administrator only).
     */
    public function userSignInLogs(Request $request, User $user): JsonResponse
    {
        if ($request->user()->role !== 'Administrator') {
            abort(403, 'Forbidden.');
        }

        $logs = $user
            ->signInLogs()
            ->orderBy('created_at', 'desc')
            ->paginate(20, ['id', 'created_at', 'ip_address', 'user_agent', 'login_mode', 'status']);

        return response()->json($logs);
    }
}
