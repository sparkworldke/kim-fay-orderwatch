<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::query()->where('role', 'Administrator')->first()
    ?? App\Models\User::query()->first();

$req = Illuminate\Http\Request::create('/api/kp/dormant-customers', 'GET', ['per_page' => 5]);
$req->setUserResolver(fn () => $user);

$ctrl = app(App\Http\Controllers\Api\KpDormantCustomersController::class);
try {
    $res = $ctrl->index($req);
    $json = $res->getData(true);
    echo "total={$json['total']}\n";
    echo "window=" . json_encode($json['window']) . "\n";
    foreach (array_slice($json['data'] ?? [], 0, 3) as $row) {
        echo ($row['acumatica_id'] ?? '') . ' | ' . ($row['name'] ?? '') . ' | last=' . ($row['last_order_date'] ?? 'never') . ' | ' . ($row['assignee']['label'] ?? 'unassigned') . "\n";
    }
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
