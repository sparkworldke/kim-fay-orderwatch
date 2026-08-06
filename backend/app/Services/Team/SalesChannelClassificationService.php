<?php

namespace App\Services\Team;

use App\Services\Cache\DomainCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesChannelClassificationService
{
    public function classifyAll(): array
    {
        $rules = DB::table('sales_channel_category_rules')
            ->where('is_active', true)
            ->orderBy('priority')
            ->get()
            ->groupBy(fn ($rule) => strtoupper(trim((string) $rule->customer_category)));

        $overrides = DB::table('customer_sales_channel_overrides')
            ->where('is_active', true)
            ->pluck('sales_channel_code', 'customer_acumatica_id');

        $stats = ['overrides' => 0, 'category_rules' => 0, 'unchanged' => 0];
        DB::transaction(function () use ($rules, $overrides, &$stats) {
            DB::table('acumatica_customers')
                ->orderBy('id')
                ->chunkById(500, function ($customers) use ($rules, $overrides, &$stats) {
                    foreach ($customers as $customer) {
                        $customerId = (string) $customer->acumatica_id;
                        $channel = $overrides[$customerId] ?? null;
                        $source = $channel ? 'overrides' : null;
                        if (! $channel && str_starts_with(strtoupper(trim((string) $customer->customer_class)), 'KP')) {
                            $channel = 'KP';
                            $source = 'category_rules';
                        }
                        if (! $channel) {
                            $category = strtoupper(trim((string) $customer->customer_class));
                            $matches = $rules->get($category, collect());
                            if ($matches->pluck('sales_channel_code')->unique()->count() > 1) {
                                throw ValidationException::withMessages([
                                    'category' => ["Category {$category} resolves to multiple primary channels."],
                                ]);
                            }
                            $channel = $matches->first()?->sales_channel_code;
                            $source = $channel ? 'category_rules' : null;
                        }
                        if ($channel && $customer->sales_channel_code !== $channel) {
                            DB::table('acumatica_customers')->where('id', $customer->id)->update([
                                'sales_channel_code' => $channel,
                                'updated_at' => now(),
                            ]);
                            $stats[$source]++;
                        } else {
                            $stats['unchanged']++;
                        }
                    }
                });
        });

        app(DomainCache::class)->bump(
            DomainCache::CUSTOMER_ANALYTICS,
            DomainCache::SALES_PORTFOLIO,
            DomainCache::SALES_INTELLIGENCE,
            DomainCache::KP_CRM,
        );

        return $stats;
    }

    public function setCustomerOverride(string $customerId, string $channel, int $actorId, string $reason): void
    {
        abort_unless(DB::table('acumatica_customers')->where('acumatica_id', $customerId)->exists(), 422, 'Unknown Acumatica customer ID.');
        abort_unless(DB::table('sales_channels')->where('code', $channel)->where('is_active', true)->exists(), 422, 'Unknown sales channel.');

        DB::table('customer_sales_channel_overrides')->updateOrInsert(
            ['customer_acumatica_id' => $customerId],
            [
                'sales_channel_code' => $channel,
                'is_active' => true,
                'created_by' => $actorId,
                'change_reason' => $reason,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
        $this->classifyAll();
    }
}
