<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ViewOnlyUnlessPrivileged
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        $role = (string) ($request->user()?->role ?? '');
        $isPrivileged = in_array($role, ['Administrator', 'Customer Service Manager', 'Customer Service Agent'], true);

        if (! $isPrivileged && ! in_array($request->method(), self::SAFE_METHODS, true)) {
            $path = $request->path();

            if (in_array($path, ['api/auth/logout'], true)) {
                return $next($request);
            }

            if (str_starts_with($path, 'api/profile')) {
                return $next($request);
            }

            if ($path === 'api/orders/status-refresh' && $request->isMethod('POST')) {
                return $next($request);
            }

            // Routes with controller-level role checks (e.g. Sales Operations backorder reasons).
            if (preg_match('#^api/operations/backorders/\d+$#', $path) && $request->isMethod('PATCH')) {
                return $next($request);
            }

            if (preg_match('#^api/orders/\d+$#', $path) && in_array($request->method(), ['PATCH', 'PUT'], true)) {
                return $next($request);
            }

            return response()->json(['message' => 'Forbidden. Read-only access.'], 403);
        }

        return $next($request);
    }
}
