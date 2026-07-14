<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaCustomer;
use App\Models\CustomerDepartmentOverride;
use App\Models\Department;
use App\Services\Team\DepartmentResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerDepartmentController extends Controller
{
    public function show(string $customerId, DepartmentResolver $resolver): JsonResponse
    {
        $customer = AcumaticaCustomer::query()->where('acumatica_id', $customerId)->firstOrFail();

        $override = CustomerDepartmentOverride::query()
            ->with('department:id,slug,name')
            ->where('customer_acumatica_id', $customerId)
            ->first();

        $resolvedSlug = $resolver->resolveSlugFromCustomerClass($customer->customer_class);
        $resolvedDepartment = $resolvedSlug
            ? Department::query()->where('slug', $resolvedSlug)->first(['id', 'slug', 'name'])
            : null;

        return response()->json([
            'customer_id' => $customer->acumatica_id,
            'customer_class' => $customer->customer_class,
            'override' => $override ? [
                'department_id' => $override->department_id,
                'department' => $override->department,
                'notes' => $override->notes,
                'updated_at' => $override->updated_at,
            ] : null,
            'resolved_department' => $resolvedDepartment,
        ]);
    }

    public function update(Request $request, string $customerId, DepartmentResolver $resolver): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        AcumaticaCustomer::query()->where('acumatica_id', $customerId)->firstOrFail();

        if ($validated['department_id'] === null) {
            CustomerDepartmentOverride::query()
                ->where('customer_acumatica_id', $customerId)
                ->delete();
            $resolver->forgetCustomerCache($customerId);

            return response()->json(['message' => 'Department override cleared.']);
        }

        CustomerDepartmentOverride::updateOrCreate(
            ['customer_acumatica_id' => $customerId],
            [
                'department_id' => $validated['department_id'],
                'updated_by' => $request->user()?->id,
                'notes' => $validated['notes'] ?? null,
            ],
        );

        $resolver->forgetCustomerCache($customerId);

        return response()->json(['message' => 'Department override saved.']);
    }
}