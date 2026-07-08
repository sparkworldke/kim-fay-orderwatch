<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminOrCustomerServiceManager
{
    public function handle(Request $request, Closure $next): Response
    {
        $role = (string) ($request->user()?->role ?? '');

        if (! in_array($role, ['Administrator', 'Customer Service Manager'], true)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
