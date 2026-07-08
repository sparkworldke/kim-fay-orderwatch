<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminOrCustomerService
{
    public function handle(Request $request, Closure $next): Response
    {
        $role = (string) ($request->user()?->role ?? '');
        $allowed = in_array($role, ['Administrator', 'Customer Service Manager', 'Customer Service Agent'], true);

        if (! $allowed) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}

