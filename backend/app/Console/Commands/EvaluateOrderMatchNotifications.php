<?php

namespace App\Console\Commands;

use App\Services\OrderMatch\OrderMatchNotificationService;
use Illuminate\Console\Command;

class EvaluateOrderMatchNotifications extends Command
{
    protected $signature = 'orderwatch:evaluate-order-match-notifications';

    protected $description = 'Evaluate Order Match notification rules R5/R6 with guardrails';

    public function handle(OrderMatchNotificationService $notifications): int
    {
        $results = $notifications->evaluateAll();
        $this->info(json_encode($results, JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}