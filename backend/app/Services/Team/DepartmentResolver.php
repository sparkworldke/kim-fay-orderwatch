<?php

namespace App\Services\Team;

use App\Models\CustomerDepartmentOverride;
use App\Models\Department;
use Illuminate\Support\Facades\Cache;

class DepartmentResolver
{
    public function resolveDepartmentIdForCustomer(string $customerAcumaticaId, ?string $customerClass): ?int
    {
        $cacheKey = 'customer_dept:' . $customerAcumaticaId;

        return Cache::remember($cacheKey, 300, function () use ($customerAcumaticaId, $customerClass) {
            $override = CustomerDepartmentOverride::query()
                ->where('customer_acumatica_id', $customerAcumaticaId)
                ->value('department_id');

            if ($override !== null) {
                return (int) $override;
            }

            $slug = $this->resolveSlugFromCustomerClass($customerClass);
            if ($slug === null) {
                return null;
            }

            return Department::query()->where('slug', $slug)->value('id');
        });
    }

    public function resolveSlugFromCustomerClass(?string $customerClass): ?string
    {
        $class = strtoupper(trim((string) $customerClass));
        if ($class === '') {
            return null;
        }

        $map = config('departments.class_prefix_map', []);
        $bestSlug = null;
        $bestLen = 0;

        foreach ($map as $prefix => $slug) {
            $prefix = strtoupper($prefix);
            if (str_starts_with($class, $prefix) && strlen($prefix) > $bestLen) {
                $bestSlug = $slug;
                $bestLen = strlen($prefix);
            }
        }

        return $bestSlug;
    }

    public function forgetCustomerCache(string $customerAcumaticaId): void
    {
        Cache::forget('customer_dept:' . $customerAcumaticaId);
    }
}