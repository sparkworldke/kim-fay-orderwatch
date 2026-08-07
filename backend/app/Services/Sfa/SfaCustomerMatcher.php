<?php
namespace App\Services\Sfa;

use App\Models\AcumaticaCustomer;
use App\Models\SfaCustomer;
use App\Models\SfaCustomerMatchAudit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SfaCustomerMatcher
{
    public function suggest(bool $onlyUnmatched = true): array
    {
        $targets = SfaCustomer::query()->when($onlyUnmatched, fn ($q) => $q->whereIn('match_status', ['unmatched','suggested','conflict']))->get();
        // Search the full synced Acumatica population inside the approved pilot classes.
        // Status is shown during review rather than silently excluding valid legacy accounts.
        $candidates = AcumaticaCustomer::query()->whereIn('customer_class', config('sfa_matching.pilot_customer_classes'))->get(['acumatica_id','name','customer_class']);
        $updated = ['suggested'=>0,'conflict'=>0,'unmatched'=>0];

        DB::transaction(function () use ($targets, $candidates, &$updated) {
            foreach ($targets as $sfa) {
                $result = $this->bestCandidate($sfa, $candidates);
                $status = $result['status']; $updated[$status]++;
                $sfa->forceFill([
                    'suggested_acumatica_customer_id' => $result['candidate']?->acumatica_id,
                    'match_status' => $status,
                    'match_score' => $result['score'],
                    'match_method' => $result['method'],
                    'pilot_segment' => $result['candidate']?->customer_class === 'CSBSTREET' ? 'bishara' : ($result['candidate'] ? 'wholesale' : null),
                ])->save();
            }
        });
        return ['processed'=>$targets->count(), ...$updated];
    }

    public function confirm(SfaCustomer $customer, string $acumaticaId, User $actor, ?string $notes = null): SfaCustomer
    {
        $candidate = AcumaticaCustomer::query()->where('acumatica_id',$acumaticaId)->whereIn('customer_class',config('sfa_matching.pilot_customer_classes'))->firstOrFail();
        DB::transaction(function () use ($customer,$candidate,$actor,$notes) {
            $previous=$customer->acumatica_customer_id;
            $customer->forceFill(['acumatica_customer_id'=>$candidate->acumatica_id,'suggested_acumatica_customer_id'=>$candidate->acumatica_id,'match_status'=>'matched','match_method'=>'manual_confirm','matched_at'=>now(),'matched_by'=>$actor->id,'pilot_segment'=>$candidate->customer_class==='CSBSTREET'?'bishara':'wholesale'])->save();
            SfaCustomerMatchAudit::create(['sfa_customer_id'=>$customer->id,'previous_acumatica_customer_id'=>$previous,'acumatica_customer_id'=>$candidate->acumatica_id,'action'=>'confirmed','method'=>'manual_confirm','score'=>$customer->match_score,'actor_id'=>$actor->id,'notes'=>$notes]);
        });
        return $customer->fresh();
    }

    public function setStatus(SfaCustomer $customer, string $status, User $actor): SfaCustomer
    {
        $previous=$customer->acumatica_customer_id;
        $customer->forceFill(['acumatica_customer_id'=>null,'suggested_acumatica_customer_id'=>$status==='ignored'?null:$customer->suggested_acumatica_customer_id,'match_status'=>$status,'match_method'=>$status,'match_score'=>$status==='unmatched'?null:$customer->match_score,'matched_at'=>null,'matched_by'=>$actor->id])->save();
        SfaCustomerMatchAudit::create(['sfa_customer_id'=>$customer->id,'previous_acumatica_customer_id'=>$previous,'action'=>$status,'method'=>'manual_'.$status,'actor_id'=>$actor->id]);
        return $customer->fresh();
    }

    public function bestCandidate(SfaCustomer $sfa, Collection $candidates): array
    {
        $code=strtoupper(trim((string)$sfa->customer_code));
        $byCode=$code===''?collect():$candidates->filter(fn($c)=>strtoupper($c->acumatica_id)===$code);
        if ($byCode->count()===1) return ['status'=>'suggested','candidate'=>$byCode->first(),'score'=>100.0,'method'=>'exact_code'];
        if ($byCode->count()>1) return ['status'=>'conflict','candidate'=>$byCode->first(),'score'=>100.0,'method'=>'exact_code_conflict'];

        $name=$this->normalizeName($sfa->customer_name);
        if ($name==='') return ['status'=>'unmatched','candidate'=>null,'score'=>null,'method'=>null];
        $ranked=$candidates->map(function($candidate)use($name){$candidate->match_score=$this->similarity($name,$this->normalizeName($candidate->name));return $candidate;})->sortByDesc('match_score')->values();
        $best=$ranked->first(); $score=(float)($best?->match_score??0); $second=(float)($ranked->get(1)?->match_score??0);
        if ($score < config('sfa_matching.fuzzy_suggestion_threshold')) return ['status'=>'unmatched','candidate'=>null,'score'=>round($score,2),'method'=>'name_fuzzy'];
        if ($second >= $score-2) return ['status'=>'conflict','candidate'=>$best,'score'=>round($score,2),'method'=>'name_ambiguous'];
        return ['status'=>'suggested','candidate'=>$best,'score'=>round($score,2),'method'=>$score>=99.9?'exact_name':'name_fuzzy'];
    }

    public function normalizeName(?string $value): string { $value=strtoupper((string)$value); $value=preg_replace('/[^A-Z0-9]+/',' ',$value)??''; return trim(preg_replace('/\s+/',' ',$value)??''); }
    private function similarity(string $a,string $b): float { if($a===''||$b==='')return 0; if($a===$b)return 100; $max=max(strlen($a),strlen($b)); return max(0,(1-levenshtein($a,$b)/$max)*100); }
}
