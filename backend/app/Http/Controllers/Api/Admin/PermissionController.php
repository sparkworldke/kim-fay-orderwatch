<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Permission::orderBy('name')->get(['id', 'name', 'description']));
    }
}
