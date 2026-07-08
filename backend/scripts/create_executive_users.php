<?php

use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$executives = [
    ['name' => 'Hartaj Bains', 'email' => 'hbains@kimfay.com'],
    ['name' => 'Raj Bains', 'email' => 'rbains@kimfay.com'],
    ['name' => 'Miraj Jhankharia', 'email' => 'coo@kimfay.com'],
];

$role = Role::query()->where('name', 'Executive')->first();
if ($role === null) {
    fwrite(STDERR, "Executive role not found. Run: php artisan db:seed --class=RolesPermissionsSeeder\n");
    exit(1);
}

$created = 0;
$skipped = 0;

foreach ($executives as $executive) {
    $email = strtolower(trim($executive['email']));
    $name = trim($executive['name']);

    $existing = User::query()->where('email', $email)->first();
    if ($existing !== null) {
        $skipped++;
        echo "Skipped (exists): {$email} — {$existing->name} / {$existing->role}\n";
        continue;
    }

    $user = User::create([
        'name' => $name,
        'email' => $email,
        'role' => 'Executive',
        'phone_number' => null,
        'rep_code' => null,
        'password' => bcrypt(Str::random(40)),
        'email_verified_at' => now(),
        'is_active' => true,
        'is_account_manager' => false,
        'is_super_admin' => false,
    ]);

    UserRole::updateOrCreate(
        ['user_id' => $user->id],
        ['role_id' => $role->id],
    );

    $created++;
    echo "Created: {$email} — {$name} (Executive, active, email verified)\n";
}

echo "\nCreated: {$created}, Skipped: {$skipped}\n";
echo "No emails are sent by this script.\n";