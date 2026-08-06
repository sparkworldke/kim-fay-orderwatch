<?php

/**
 * One-off: invalidate the "Products Not Delivered" domain cache.
 *
 * ItemsNotDeliveredController::cachedRows() caches the computed rows keyed on
 * the DomainCache "not-delivered" generation. That generation is normally only
 * bumped when an Acumatica sync finishes, so a code/data fix can leave a stale
 * (e.g. empty) result cached. Running this bumps the generation so the next
 * request recomputes from the current source table.
 *
 * Usage: php scripts/bust_not_delivered_cache.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Cache\DomainCache;

$cache = app(DomainCache::class);

$before = $cache->generation(DomainCache::NOT_DELIVERED);
$cache->bump(DomainCache::NOT_DELIVERED);
$after = $cache->generation(DomainCache::NOT_DELIVERED);

echo "not-delivered cache generation: {$before} -> {$after}\n";
echo "Stale cached results invalidated. The next request will recompute.\n";
