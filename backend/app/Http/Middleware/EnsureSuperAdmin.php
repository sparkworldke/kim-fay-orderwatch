<?php

namespace App\Http\Middleware;

use App\Services\Team\AccessTierService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function __construct(private readonly AccessTierService $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            $this->access->canManageUsers($request->user()),
            403,
            'User management is restricted to super administrators.',
        );

        return $next($request);
    }
}
