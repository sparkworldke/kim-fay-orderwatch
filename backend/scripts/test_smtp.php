<?php

/**
 * Test SMTP settings without writing to .env
 * Usage: php scripts/test_smtp.php [recipient@example.com]
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Mail;

$recipient = $argv[1] ?? 'customercare@kimfay.com';

$config = [
    'mail.default' => 'smtp',
    'mail.mailers.smtp.transport' => 'smtp',
    'mail.mailers.smtp.host' => 'mail.fayshop.co.ke',
    'mail.mailers.smtp.port' => 465,
    'mail.mailers.smtp.scheme' => 'smtps',
    'mail.mailers.smtp.username' => 'do-not-reply@fayshop.co.ke',
    'mail.mailers.smtp.password' => 'I$^KX$rTjUi6',
    'mail.from.address' => 'hello@fayshop.co.ke',
    'mail.from.name' => 'Kim-Fay OrderWatch',
];

foreach ($config as $key => $value) {
    config([$key => $value]);
}

if (method_exists(app('mail.manager'), 'forgetDrivers')) {
    app('mail.manager')->forgetDrivers();
}

echo "=== SMTP test ===\n";
echo "Host: mail.fayshop.co.ke:465 (ssl)\n";
echo "User: do-not-reply@fayshop.co.ke\n";
echo "From: hello@fayshop.co.ke\n";
echo "To: {$recipient}\n\n";

try {
    Mail::raw(
        'OrderWatch SMTP test — '.now()->timezone('Africa/Nairobi')->format('Y-m-d H:i:s T'),
        function ($message) use ($recipient) {
            $message->to($recipient)
                ->subject('OrderWatch SMTP Test — '.now()->format('Y-m-d H:i'));
        },
    );

    echo "SUCCESS: Test email sent to {$recipient}\n";
    exit(0);
} catch (Throwable $e) {
    echo "FAILED: ".$e->getMessage()."\n";
    if ($prev = $e->getPrevious()) {
        echo "Cause: ".$prev->getMessage()."\n";
    }
    exit(1);
}