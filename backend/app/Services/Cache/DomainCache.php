<?php

namespace App\Services\Cache;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

final class DomainCache
{
    public const ORDERS = 'orders';
    public const INVENTORY = 'inventory';
    public const BACKORDERS = 'backorders';
    public const FILL_RATE = 'fill-rate';
    public const BUSINESS_OPTIMIZATION = 'business-optimization';
    public const REFERENCES = 'references';
    public const NOT_DELIVERED = 'not-delivered';
    public const CUSTOMER_ANALYTICS = 'customer-analytics';
    public const SALES_PORTFOLIO = 'sales-portfolio';
    public const SALES_INTELLIGENCE = 'sales-intelligence';
    public const KP_CRM = 'kp-crm';
    public const KP_OPERATIONS = 'kp-operations';

    public function generation(string $domain): int
    {
        return (int) $this->cache()->get($this->generationKey($domain), 1);
    }

    public function bump(string ...$domains): void
    {
        foreach (array_unique($domains) as $domain) {
            $key = $this->generationKey($domain);
            $this->cache()->forever($key, $this->generation($domain) + 1);
        }
    }

    private function generationKey(string $domain): string
    {
        return "domain-cache:generation:{$domain}";
    }

    private function cache(): Repository
    {
        try {
            $store = Cache::store((string) config('cache.dashboard_store', 'redis'));
            $store->get('domain-cache:healthcheck');

            return $store;
        } catch (\Throwable) {
            return Cache::store((string) config('cache.dashboard_fallback_store', 'database'));
        }
    }
}
