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

            if (in_array($path, ['api/auth/logout', 'api/auth/impersonate/stop', 'api/auth/onboarding/complete'], true)) {
                return $next($request);
            }

            if (str_starts_with($path, 'api/profile')) {
                return $next($request);
            }

            // Page-navigation tracking for Login + Activity export (all roles).
            if ($path === 'api/activity/page-view' && $request->isMethod('POST')) {
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

            // KP FOL workflow actions (consultant submit, tech resolve, manager assign) — controller enforces permissions.
            if (str_starts_with($path, 'api/kp/fol') && in_array($request->method(), ['POST', 'PATCH', 'PUT'], true)) {
                return $next($request);
            }

            // Price Change Requests (consultant create/submit; manager decide; ops mark ERP) — controller enforces permissions.
            if (str_starts_with($path, 'api/operations/price-change-requests')
                && in_array($request->method(), ['POST', 'PATCH', 'PUT'], true)) {
                return $next($request);
            }

            // KP CRM and commission writes have granular controller permissions and audit trails.
            if ((str_starts_with($path, 'api/kp/commissions') || str_starts_with($path, 'api/kp/dormant-customers'))
                && in_array($request->method(), ['POST', 'PATCH', 'PUT'], true)) {
                return $next($request);
            }
            if (str_starts_with($path, 'api/admin/adoption-report') && $request->isMethod('POST')) {
                return $next($request);
            }

            // Production planning writes use granular permission/department checks in the controller.
            if (str_starts_with($path, 'api/operations/production/plans')
                && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                return $next($request);
            }

            // FGS transfer request notifications — any authenticated production user may email the list.
            if ($path === 'api/operations/production/transfer-requests/email' && $request->isMethod('POST')) {
                return $next($request);
            }

            // Kimfay Genius + AI Intelligence + Ask Genius chat — every authenticated role may use them.
            // Controllers still enforce weekly locks / access rules.
            if (str_starts_with($path, 'api/ai/') && in_array($request->method(), ['POST', 'PATCH', 'PUT'], true)) {
                return $next($request);
            }

            // DTC/DTB Calltronix (price upload, product sync, QT/POS) — controller enforces dtc.* permissions.
            if (str_starts_with($path, 'api/kp/dtc-calltronix')
                && in_array($request->method(), ['POST', 'PATCH', 'PUT'], true)) {
                return $next($request);
            }

            return response()->json(['message' => 'Forbidden. Read-only access.'], 403);
        }

        return $next($request);
    }
}
