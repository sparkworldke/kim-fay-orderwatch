<?php

use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @var list<array{rep_code:string,name:string,email:?string}> $payrollUsers */
$payrollUsers = require __DIR__ . '/data/payroll_users.php';

$role = Role::query()->firstOrCreate(
    ['name' => 'Sales Consultant'],
    ['is_system' => true],
);

$created = 0;
$updated = 0;
$unchanged = 0;
$emailConflicts = [];

foreach ($payrollUsers as $record) {
    $repCode = strtoupper(trim($record['rep_code']));
    $name = trim($record['name']);
    $sheetEmail = cleanEmail($record['email'] ?? null);

    $user = User::query()->where('rep_code', $repCode)->first();

    if ($user === null) {
        $email = $sheetEmail ?? 'consultant+'.Str::slug($repCode, '.').'@orderwatch.local';
        $placeholderEmail = $sheetEmail === null;

        if (User::query()->where('email', $email)->exists()) {
            $emailConflicts[] = ['rep_code' => $repCode, 'name' => $name, 'email' => $email];
            echo "Skipped create (email taken): {$repCode} — {$email}\n";
            continue;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'role' => 'Sales Consultant',
            'rep_code' => $repCode,
            'phone_number' => null,
            'password' => bcrypt(Str::random(40)),
            'email_verified_at' => $placeholderEmail ? null : now(),
            'is_active' => true,
            'is_account_manager' => false,
            'is_super_admin' => false,
        ]);

        UserRole::updateOrCreate(
            ['user_id' => $user->id],
            ['role_id' => $role->id],
        );

        $created++;
        echo sprintf(
            "Created: %s — %s (%s%s)\n",
            $repCode,
            $name,
            $email,
            $placeholderEmail ? ', placeholder email' : ', verified'
        );
        continue;
    }

    $changes = [];

    if ($name !== '' && $user->name !== $name) {
        $changes['name'] = $name;
    }

    if ($sheetEmail !== null) {
        $taken = User::query()
            ->where('email', $sheetEmail)
            ->where('id', '!=', $user->id)
            ->exists();

        if ($taken) {
            $emailConflicts[] = ['rep_code' => $repCode, 'name' => $name, 'email' => $sheetEmail];
            echo "Skipped email update (taken): {$repCode} — {$sheetEmail}\n";
        } elseif ($user->email !== $sheetEmail) {
            $changes['email'] = $sheetEmail;
            if ($user->email_verified_at === null) {
                $changes['email_verified_at'] = now();
            }
        }
    }

    if ($user->role !== 'Sales Consultant') {
        $changes['role'] = 'Sales Consultant';
    }

    if ($changes === []) {
        $unchanged++;
        continue;
    }

    $user->update($changes);

    UserRole::updateOrCreate(
        ['user_id' => $user->id],
        ['role_id' => $role->id],
    );

    $updated++;
    echo sprintf(
        "Updated %s (%s): %s\n",
        $repCode,
        implode(', ', array_keys($changes)),
        $name
    );
}

echo "\n--- Summary ---\n";
echo 'Payroll records: ' . count($payrollUsers) . "\n";
echo "Created: {$created}\n";
echo "Updated: {$updated}\n";
echo "Already up to date: {$unchanged}\n";
echo 'Email conflicts: ' . count($emailConflicts) . "\n";
echo "No emails are sent by this script.\n";

function cleanEmail(?string $value): ?string
{
    $email = strtolower(trim((string) $value));
    if ($email === '' || $email === 'nan') {
        return null;
    }

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}