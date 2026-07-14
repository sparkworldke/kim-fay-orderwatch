<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Team\UserCapabilitiesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CapabilitiesController extends Controller
{
    public function __invoke(Request $request, UserCapabilitiesService $capabilities): JsonResponse
    {
        return response()->json($capabilities->forUser($request->user()));
    }
}