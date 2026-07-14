<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

foreach ([
    'price_change_requests',
    'price_change_approval_stages',
    'price_change_approval_actions',
    'price_change_events',
    'price_change_settings',
    'fol_requests',
    'fol_approval_stages',
    'user_roles',
] as $t) {
    echo $t.': '.(Illuminate\Support\Facades\Schema::hasTable($t) ? 'yes' : 'no').PHP_EOL;
}

$indexes = Illuminate\Support\Facades\DB::select('SHOW INDEX FROM user_roles');
foreach ($indexes as $idx) {
    echo "idx {$idx->Key_name} col {$idx->Column_name} unique=".($idx->Non_unique ? '0' : '1').PHP_EOL;
}
