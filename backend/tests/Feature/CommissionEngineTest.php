<?php

namespace Tests\Feature;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaSalesOrder;
use App\Models\CommissionRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommissionEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_so_is_snapshotted_calculated_and_locked(): void
    {
        $admin=User::factory()->create(['role'=>'Administrator','is_super_admin'=>true]);
        $consultant=User::factory()->create(['role'=>'Sales Consultant','rep_code'=>'REP-1','is_consultant'=>true]);
        AcumaticaCustomer::create(['acumatica_id'=>'KP-1','name'=>'KP Customer','customer_class'=>'KP-HORECA','status'=>'Active']);
        AcumaticaSalesOrder::create(['acumatica_order_nbr'=>'SO-COM-1','order_type'=>'SO','customer_acumatica_id'=>'KP-1','customer_name'=>'KP Customer','status'=>'Open','order_date'=>'2026-07-10','order_total'=>100000,'currency_id'=>'KES','consultant_user_id'=>$consultant->id,'sales_consultant_rep_code'=>'REP-1','approved_at'=>'2026-07-10 08:00:00']);
        $rule=CommissionRule::create(['name'=>'KP monthly tiers','effective_from'=>'2026-01-01','sales_value_basis'=>'order_total','fixed_bonus'=>0,'is_active'=>true,'created_by'=>$admin->id]);
        $rule->tiers()->createMany([['attainment_from_pct'=>0,'attainment_to_pct'=>100,'commission_rate_pct'=>1],['attainment_from_pct'=>100,'attainment_to_pct'=>null,'commission_rate_pct'=>2]]);
        DB::table('commission_targets')->insert(['user_id'=>$consultant->id,'period_month'=>'2026-07-01','target_amount'=>100000,'created_by'=>$admin->id,'created_at'=>now(),'updated_at'=>now()]);

        Sanctum::actingAs($admin);
        $created=$this->postJson('/api/kp/commissions/periods',['period_month'=>'2026-07','shadow_mode'=>true])->assertCreated()
            ->assertJsonPath('statements.0.eligible_sales',100000)->assertJsonPath('statements.0.net_commission',2000);
        $periodId=$created->json('id'); $statementId=$created->json('statements.0.id');
        $this->assertDatabaseHas('commission_entries',['commission_statement_id'=>$statementId,'order_nbr'=>'SO-COM-1','commission_amount'=>2000]);
        $this->postJson("/api/kp/commissions/periods/{$periodId}/transition",['action'=>'approve'])->assertOk()->assertJsonPath('status','approved');
        $this->postJson("/api/kp/commissions/periods/{$periodId}/transition",['action'=>'lock'])->assertOk()->assertJsonPath('status','locked');
        $this->postJson("/api/kp/commissions/statements/{$statementId}/adjustments",['amount'=>100,'reason'=>'Late bonus'])->assertStatus(409);
    }

    public function test_unapproved_and_non_commissionable_orders_are_excluded(): void
    {
        $admin=User::factory()->create(['role'=>'Administrator','is_super_admin'=>true]);
        $consultant=User::factory()->create(['rep_code'=>'REP-2']);
        AcumaticaCustomer::create(['acumatica_id'=>'KP-2','name'=>'Second Customer','customer_class'=>'KP','status'=>'Active']);
        foreach([['SO-U','approved_at'=>null,'raw_payload'=>null],['SO-N','approved_at'=>'2026-07-02','raw_payload'=>['Commissionable'=>['value'=>false]]]] as $row)
            AcumaticaSalesOrder::create(['acumatica_order_nbr'=>$row[0],'order_type'=>'SO','customer_acumatica_id'=>'KP-2','status'=>'Open','order_date'=>'2026-07-02','order_total'=>50000,'consultant_user_id'=>$consultant->id,'approved_at'=>$row['approved_at'],'raw_payload'=>$row['raw_payload']]);
        $rule=CommissionRule::create(['name'=>'Default','effective_from'=>'2026-01-01','is_active'=>true]); $rule->tiers()->create(['attainment_from_pct'=>0,'commission_rate_pct'=>1]);
        DB::table('commission_targets')->insert(['user_id'=>$consultant->id,'period_month'=>'2026-07-01','target_amount'=>50000,'created_at'=>now(),'updated_at'=>now()]);
        Sanctum::actingAs($admin);
        $this->postJson('/api/kp/commissions/periods',['period_month'=>'2026-07'])->assertCreated()->assertJsonPath('statements.0.eligible_sales',0)->assertJsonCount(0,'statements.0.entries');
    }
}
