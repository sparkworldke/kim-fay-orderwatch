<?php

namespace App\Services\Commissions;

use App\Models\AcumaticaSalesOrder;
use App\Models\CommissionEntry;
use App\Models\CommissionPeriod;
use App\Models\CommissionRule;
use App\Models\CommissionStatement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CommissionCalculator
{
    public function calculate(CommissionPeriod $period, User $actor): CommissionPeriod
    {
        abort_if($period->status !== 'draft', 409, 'Only draft commission periods can be recalculated.');
        $start = Carbon::parse($period->period_month, 'Africa/Nairobi')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return DB::transaction(function () use ($period, $actor, $start, $end) {
            $period->statements()->delete();
            $targets = DB::table('commission_targets')->whereDate('period_month', $start->toDateString())->get();

            foreach ($targets as $target) {
                $user = User::find($target->user_id);
                if (! $user) continue;

                $orders = AcumaticaSalesOrder::query()
                    ->whereBetween('order_date', [$start, $end])
                    ->where('order_type', AcumaticaSalesOrder::TYPE_SALES_ORDER)
                    ->whereNotNull('approved_at')
                    ->where(function ($q) use ($user) {
                        $q->where('consultant_user_id', $user->id);
                        if ($user->rep_code) $q->orWhere('sales_consultant_rep_code', $user->rep_code);
                    })
                    ->whereNotIn(DB::raw('LOWER(status)'), ['cancelled', 'canceled', 'deleted', 'voided'])
                    ->with('customer:acumatica_id,customer_class')
                    ->get();

                $eligibleSales = (float) $orders->sum(fn ($o) => $this->isCommissionable($o) ? (float) $o->order_total : 0);
                $targetAmount = (float) $target->target_amount;
                $attainment = $targetAmount > 0 ? $eligibleSales / $targetAmount * 100 : 0;
                $statement = CommissionStatement::create([
                    'commission_period_id' => $period->id, 'user_id' => $user->id,
                    'target_amount' => $targetAmount, 'eligible_sales' => $eligibleSales,
                    'attainment_pct' => round($attainment, 2),
                ]);

                $gross = 0.0;
                foreach ($orders as $order) {
                    if (! $this->isCommissionable($order)) continue;
                    $rule = $this->resolveRule($user, $order, $start);
                    if (! $rule) continue;
                    $rate = $this->tierRate($rule, $attainment);
                    $amount = (float) $order->order_total * $rate / 100;
                    $gross += $amount;
                    CommissionEntry::create([
                        'commission_statement_id' => $statement->id,
                        'sales_order_id' => $order->id, 'commission_rule_id' => $rule->id,
                        'order_nbr' => $order->acumatica_order_nbr, 'order_date' => $order->order_date,
                        'sales_value' => $order->order_total, 'rate_pct' => $rate,
                        'commission_amount' => round($amount, 2),
                        'order_snapshot' => [
                            'order_nbr' => $order->acumatica_order_nbr, 'order_type' => $order->order_type,
                            'status' => $order->status, 'approved_at' => $order->approved_at?->toIso8601String(),
                            'customer_id' => $order->customer_acumatica_id, 'customer_name' => $order->customer_name,
                            'consultant_user_id' => $user->id, 'rep_code' => $order->sales_consultant_rep_code,
                            'order_total' => (float) $order->order_total, 'currency' => $order->currency_id,
                        ],
                    ]);
                }

                $bonus = (float) ($this->resolveRule($user, $orders->first(), $start)?->fixed_bonus ?? 0);
                $cap = $this->resolveRule($user, $orders->first(), $start)?->cap_amount;
                $gross += $bonus;
                if ($cap !== null) $gross = min($gross, (float) $cap);
                $statement->update(['gross_commission'=>round($gross, 2), 'net_commission'=>round($gross, 2)]);
            }

            $period->update(['calculated_at'=>now('Africa/Nairobi'), 'calculated_by'=>$actor->id]);
            $period->events()->create(['event_type'=>'calculated','actor_user_id'=>$actor->id,'payload'=>['target_count'=>$targets->count()]]);
            return $period->fresh(['statements.user', 'statements.entries']);
        });
    }

    public function tierRate(CommissionRule $rule, float $attainment): float
    {
        $tier = $rule->tiers->first(fn ($tier) => $attainment >= (float) $tier->attainment_from_pct
            && ($tier->attainment_to_pct === null || $attainment < (float) $tier->attainment_to_pct));
        return (float) ($tier?->commission_rate_pct ?? 0);
    }

    private function resolveRule(User $user, ?AcumaticaSalesOrder $order, Carbon $period): ?CommissionRule
    {
        return CommissionRule::with('tiers')->where('is_active', true)
            ->whereDate('effective_from', '<=', $period->copy()->endOfMonth())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $period))
            ->orderByDesc('effective_from')->get()->first(function (CommissionRule $rule) use ($user, $order) {
                if ($rule->eligible_user_ids && ! in_array($user->id, $rule->eligible_user_ids)) return false;
                if ($rule->eligible_customer_classes && $order && ! in_array($order->customer?->customer_class, $rule->eligible_customer_classes)) return false;
                return ! $order || ! in_array($order->order_type, $rule->excluded_order_types ?? []);
            });
    }

    private function isCommissionable(AcumaticaSalesOrder $order): bool
    {
        $raw = $order->raw_payload ?? [];
        $value = data_get($raw, 'Commissionable.value', data_get($raw, 'Commissionable'));
        return $value === null || filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
    }
}
