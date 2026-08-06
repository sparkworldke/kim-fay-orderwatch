<?php

namespace Tests\Unit;

use App\Http\Middleware\CacheDomainResponse;
use App\Models\User;
use App\Services\Cache\DomainCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DomainResponseCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear();
    }

    public function test_it_caches_normalized_get_queries_per_user_and_generation(): void
    {
        $user = new User();
        $user->id = 42;
        $calls = 0;
        $next = function () use (&$calls) {
            $calls++;
            return response()->json(['calls' => $calls]);
        };
        $middleware = app(CacheDomainResponse::class);

        $first = Request::create('/api/orders', 'GET', ['status' => ['Open', 'Shipping'], 'page' => 1]);
        $first->setUserResolver(fn () => $user);
        $second = Request::create('/api/orders', 'GET', ['page' => 1, 'status' => ['Open', 'Shipping']]);
        $second->setUserResolver(fn () => $user);

        $this->assertSame('MISS', $middleware->handle($first, $next, DomainCache::ORDERS, 60)->headers->get('X-Dashboard-Cache'));
        $this->assertSame('HIT', $middleware->handle($second, $next, DomainCache::ORDERS, 60)->headers->get('X-Dashboard-Cache'));
        $this->assertSame(1, $calls);

        app(DomainCache::class)->bump(DomainCache::ORDERS);
        $this->assertSame('MISS', $middleware->handle($second, $next, DomainCache::ORDERS, 60)->headers->get('X-Dashboard-Cache'));
        $this->assertSame(2, $calls);
    }

    public function test_it_does_not_share_responses_between_users(): void
    {
        $calls = 0;
        $next = function () use (&$calls) {
            $calls++;
            return response()->json(['calls' => $calls]);
        };
        $middleware = app(CacheDomainResponse::class);

        foreach ([10, 11] as $userId) {
            $user = new User();
            $user->id = $userId;
            $request = Request::create('/api/operations/backorders', 'GET');
            $request->setUserResolver(fn () => $user);
            $middleware->handle($request, $next, DomainCache::BACKORDERS, 120);
        }

        $this->assertSame(2, $calls);
    }
}
