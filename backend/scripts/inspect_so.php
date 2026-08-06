<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$nbr = $argv[1] ?? 'SO359099';
$o = App\Models\AcumaticaSalesOrder::where('acumatica_order_nbr', $nbr)->first();
if (!$o) {
    echo "ORDER NOT FOUND: {$nbr}\n";
    exit(1);
}

echo "order_date={$o->order_date} status={$o->status} customer={$o->customer_name}\n";
echo "order_total=" . ($o->order_total ?? $o->order_amount ?? 'n/a') . "\n";

$lines = App\Models\AcumaticaSalesOrderLine::where('sales_order_id', $o->id)->get();
echo "lines={$lines->count()}\n";
foreach ($lines as $l) {
    $risk = (float) ($l->open_qty ?? 0) * (float) ($l->unit_price ?? 0);
    echo sprintf(
        "  inv=%s order_qty=%s open_qty=%s shipped=%s unit_price=%s bo_qty=%s open×price=%.2f\n",
        $l->inventory_id,
        $l->order_qty,
        $l->open_qty,
        $l->shipped_qty,
        $l->unit_price,
        $l->backorder_qty,
        $risk
    );
}

$bos = App\Models\AcumaticaBackorderLine::where('order_nbr', $nbr)->get();
echo "backorder_lines={$bos->count()}\n";
foreach ($bos as $b) {
    $attrs = $b->getAttributes();
    $open = $attrs['open_qty'] ?? $attrs['missing_qty'] ?? $attrs['qty_on_backorder'] ?? null;
    $price = $attrs['unit_price'] ?? null;
    $risk = $attrs['revenue_at_risk'] ?? $attrs['value'] ?? null;
    echo sprintf(
        "  inv=%s open=%s unit_price=%s risk=%s\n",
        $attrs['inventory_id'] ?? '?',
        $open ?? 'n/a',
        $price ?? 'n/a',
        $risk ?? 'n/a'
    );
}
