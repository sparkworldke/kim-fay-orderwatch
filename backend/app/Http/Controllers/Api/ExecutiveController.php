<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reports\ExecutiveDashboardService;
use App\Services\Team\UserCapabilitiesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExecutiveController extends Controller
{
    public function metrics(Request $request, ExecutiveDashboardService $service, UserCapabilitiesService $capabilities): JsonResponse
    {
        abort_unless($capabilities->forUser($request->user())['executive_view'] ?? false, 403);
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date']]);
        return response()->json($service->metrics($request->user(), $data['from'], $data['to']));
    }
}
