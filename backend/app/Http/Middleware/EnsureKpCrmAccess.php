<?php

namespace App\Http\Middleware;

use App\Services\Team\KpCrmAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureKpCrmAccess
{
    public function __construct(private readonly KpCrmAccessService $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user && $this->access->canAccess($user), 403, 'KP CRM access is not authorized.');

        return $next($request);
    }
}
