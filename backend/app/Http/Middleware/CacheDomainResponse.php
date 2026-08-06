<?php

namespace App\Http\Middleware;

use App\Services\Cache\DomainCache;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

final class CacheDomainResponse
{
    public function __construct(private readonly DomainCache $domains)
    {
    }

    public function handle(Request $request, Closure $next, string $domain, int|string $ttl = 60): Response
    {
        if ($request->user() === null) {
            return $next($request);
        }
        if (! $request->isMethod('GET')) {
            $response = $next($request);
            if ($response->getStatusCode() < 400) {
                $this->domains->bump($domain);
            }

            return $response;
        }

        $query = $request->query();
        $this->sortRecursive($query);
        $key = implode(':', [
            'domain-response',
            $domain,
            $this->domains->generation($domain),
            'user',
            $request->user()->getAuthIdentifier(),
            hash('sha256', $request->path().'|'.json_encode($query, JSON_UNESCAPED_SLASHES)),
        ]);

        $cache = $this->cache();
        $cached = $cache->get($key);
        if (is_array($cached) && isset($cached['body'], $cached['status'])) {
            return response($cached['body'], $cached['status'])
                ->header('Content-Type', $cached['content_type'] ?? 'application/json')
                ->header('X-Dashboard-Cache', 'HIT');
        }

        $response = $next($request);
        if ($response instanceof JsonResponse && $response->getStatusCode() === 200) {
            $cache->put($key, [
                'body' => $response->getContent(),
                'status' => $response->getStatusCode(),
                'content_type' => $response->headers->get('Content-Type', 'application/json'),
            ], now()->addSeconds(max(1, (int) $ttl)));
            $response->headers->set('X-Dashboard-Cache', 'MISS');
        }

        return $response;
    }

    private function cache(): Repository
    {
        try {
            $store = Cache::store((string) config('cache.dashboard_store', 'redis'));
            $store->get('dashboard-cache:healthcheck');

            return $store;
        } catch (\Throwable) {
            try {
                $fallback = Cache::store((string) config('cache.dashboard_fallback_store', 'database'));
                $fallback->get('dashboard-cache:fallback-healthcheck');

                return $fallback;
            } catch (\Throwable) {
                return Cache::store('array');
            }
        }
    }

    private function sortRecursive(array &$values): void
    {
        ksort($values);
        foreach ($values as &$value) {
            if (is_array($value)) {
                $this->sortRecursive($value);
            }
        }
    }
}
