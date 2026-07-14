<?php

/**
 * Live smoke check for Price Change Request APIs against the app database.
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaInventoryItem;
use App\Models\PriceChangeApprovalStage;
use App\Models\PriceChangeRequest;
use App\Models\User;
use App\Services\Pricing\PriceChangeRequestService;
use Illuminate\Support\Facades\Schema;

echo "=== PCR smoke check ===\n";

foreach ([
    'price_change_requests',
    'price_change_approval_stages',
    'price_change_events',
    'price_change_settings',
] as $table) {
    echo Schema::hasTable($table) ? "OK table {$table}\n" : "MISSING table {$table}\n";
}

$stages = PriceChangeApprovalStage::query()->orderBy('sort_order')->get(['key', 'name', 'is_active', 'role_names']);
echo 'Stages: '.$stages->count().PHP_EOL;
foreach ($stages as $s) {
    echo "  - {$s->key}: {$s->name} active=".($s->is_active ? '1' : '0').PHP_EOL;
}

$admin = User::query()->where('role', 'Administrator')->where('is_active', true)->first();
if (! $admin) {
    echo "FAIL: no active Administrator user\n";
    exit(1);
}
echo "Admin: {$admin->email} (id {$admin->id})\n";
echo 'Admin has pricing.pcr.view: '.($admin->hasPermission('pricing.pcr.view') ? 'yes' : 'no').PHP_EOL;
echo 'Admin has pricing.pcr.create: '.($admin->hasPermission('pricing.pcr.create') ? 'yes' : 'no').PHP_EOL;

$service = app(PriceChangeRequestService::class);

try {
    $dashboard = $service->dashboard($admin);
    echo 'Dashboard: '.json_encode($dashboard).PHP_EOL;
} catch (Throwable $e) {
    echo 'FAIL dashboard: '.$e->getMessage().PHP_EOL;
    exit(1);
}

$customer = AcumaticaCustomer::query()->orderBy('id')->first();
$item = AcumaticaInventoryItem::query()
    ->where(function ($q) {
        $q->whereNull('item_status')->orWhere('item_status', 'Active')->orWhere('item_status', 'like', 'A%');
    })
    ->whereNotNull('sales_price')
    ->orderBy('id')
    ->first();

if (! $customer || ! $item) {
    echo "WARN: need at least one customer and inventory item to create PCR sample\n";
    echo 'Customers: '.AcumaticaCustomer::query()->count().' Inventory: '.AcumaticaInventoryItem::query()->count().PHP_EOL;
    exit(0);
}

echo "Sample customer: {$customer->acumatica_id} {$customer->name}\n";
echo "Sample SKU: {$item->inventory_id} price={$item->sales_price}\n";

try {
    $resolved = $service->resolvePrice($admin, (string) $customer->acumatica_id, (string) $item->inventory_id, (float) $item->sales_price + 10);
    echo 'Resolve OK current='.$resolved['current_selling_price'].' source='.$resolved['current_price_source'].PHP_EOL;
} catch (Throwable $e) {
    echo 'FAIL resolve: '.$e->getMessage().PHP_EOL;
    exit(1);
}

// Create a disposable PCR then approve fully if stages exist
try {
    $pcr = $service->create($admin, [
        'customer_acumatica_id' => $customer->acumatica_id,
        'inventory_id' => $item->inventory_id,
        'proposed_selling_price' => round((float) ($item->sales_price ?? 100) + 5, 2),
        'justification' => 'Smoke test PCR created by scripts/smoke_pcr.php — safe to ignore/reject.',
    ]);
    echo "Create OK {$pcr->public_ref} status={$pcr->status} stage={$pcr->current_stage_key}\n";

    $presented = $service->present($admin, $pcr);
    echo 'Present can_approve='.($presented['can_actor_approve'] ? '1' : '0').PHP_EOL;

    // Clean up smoke record so prod data is not polluted with open PCRs
    PriceChangeRequest::query()->whereKey($pcr->id)->delete();
    echo "Cleaned up smoke PCR id={$pcr->id}\n";
} catch (Throwable $e) {
    echo 'FAIL create: '.$e->getMessage().PHP_EOL;
    echo $e->getFile().':'.$e->getLine().PHP_EOL;
    exit(1);
}

echo "=== PCR smoke check PASSED ===\n";
