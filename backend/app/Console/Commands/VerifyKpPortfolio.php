<?php

namespace App\Console\Commands;

use App\Models\CustomerData;
use App\Models\User;
use App\Models\UserCustomerAssignment;
use Illuminate\Console\Command;

class VerifyKpPortfolio extends Command
{
    protected $signature = 'orderwatch:verify-kp-portfolio';

    protected $description = 'Verify KP Professional rep-code coverage and servicing assignment alignment';

    public function handle(): int
    {
        $excluded = ['', 'LITIGATION', 'BADDEBT', 'BADDEBTPRO'];
        $professional = CustomerData::query()->where('customer_group', 'Kim-Fay Professional');
        $eligible = (clone $professional)
            ->whereNotNull('rep_code')
            ->whereNotIn('rep_code', $excluded);
        $total = $eligible->count();

        $activeUsers = User::query()->where('is_active', true)->whereNotNull('rep_code')->get()
            ->keyBy(fn (User $user) => strtoupper(trim((string) $user->rep_code)));
        $assignments = UserCustomerAssignment::query()
            ->where('source', 'kp_customers_20260805')
            ->whereIn('assignment_type', [UserCustomerAssignment::TYPE_SERVICING, UserCustomerAssignment::TYPE_LEGACY_PRIMARY])
            ->with('user:id,name,rep_code,is_active')
            ->get()
            ->groupBy('customer_acumatica_id');

        $covered = 0;
        $unresolved = [];
        $drift = [];
        foreach ($eligible->get(['customer_acumatica_id', 'rep_code', 'sales_rep']) as $customer) {
            $repCode = strtoupper(trim((string) $customer->rep_code));
            $owner = $activeUsers->get($repCode);
            if (! $owner) {
                $unresolved[$repCode] = ($unresolved[$repCode] ?? 0) + 1;
                continue;
            }

            $assignment = $assignments->get($customer->customer_acumatica_id)?->first();
            if ($assignment && strtoupper(trim((string) $assignment->user?->rep_code)) === $repCode) {
                $covered++;
            } elseif ($assignment) {
                $drift[] = [
                    $customer->customer_acumatica_id,
                    $repCode,
                    (string) $assignment->user?->rep_code,
                    (string) $assignment->user?->name,
                ];
            }
        }

        $coverage = $total > 0 ? round(($covered / $total) * 100, 2) : 100.0;
        $this->table(['Metric', 'Value'], [
            ['Professional accounts', (clone $professional)->count()],
            ['Eligible accounts', $total],
            ['Correctly assigned', $covered],
            ['Coverage', $coverage.'%'],
            ['Unresolved rep codes', count($unresolved)],
            ['Assignment drift', count($drift)],
        ]);

        if ($unresolved !== []) {
            ksort($unresolved);
            $this->warn('Unresolved active-user rep codes:');
            $this->table(['Rep code', 'Customers'], collect($unresolved)->map(fn ($count, $code) => [$code, $count])->values()->all());
        }
        if ($drift !== []) {
            $this->warn('Assignment rep-code drift:');
            $this->table(['Customer', 'CSV rep', 'Assigned rep', 'Assigned user'], array_slice($drift, 0, 50));
        }

        if ($coverage < 95 || $drift !== []) {
            $this->error('KP portfolio verification failed. Required coverage is at least 95% with no assignment drift.');
            return self::FAILURE;
        }

        $this->info('KP portfolio verification passed.');
        return self::SUCCESS;
    }
}
