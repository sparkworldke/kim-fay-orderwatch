<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions:id,name')
            ->withCount('userRoles as users_count')
            ->orderBy('name')
            ->get();

        return response()->json($roles);
    }
}
