<?php
namespace App\Services\Sfa;

use App\Models\SfaSyncLog;
use App\Models\SfaSyncState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SfaSyncService
{
    public function status(): array
    {
        $configured=(bool) config('database.connections.sfa_remote.host') && (bool) config('database.connections.sfa_remote.database') && (bool) config('database.connections.sfa_remote.username');
        $connected=false; $error=null;
        if ($configured) try { DB::connection('sfa_remote')->select('SELECT 1'); $connected=true; } catch (Throwable $e) { $error=$e->getMessage(); }
        return ['configured'=>$configured,'connected'=>$connected,'error'=>$error,'visible_team'=>config('sfa_sync.visible_team'),'states'=>SfaSyncState::orderBy('id')->get()];
    }

    public function run(string $table): SfaSyncLog
    {
        abort_unless(in_array($table, config('sfa_sync.manual_tables'), true), 422, 'This SFA table is not enabled for manual pull yet.');
        $batch=(string) Str::uuid(); $started=microtime(true);
        $log=SfaSyncLog::create(['batch_id'=>$batch,'table_name'=>$table,'status'=>'running','started_at'=>now()]);
        SfaSyncState::where('table_name',$table)->update(['last_sync_at'=>now()]);
        try {
            $count=$table==='reps' ? $this->syncReps() : $this->syncCustomers();
            $log->update(['status'=>'success','rows_processed'=>$count,'rows_inserted'=>$count,'duration_seconds'=>microtime(true)-$started,'completed_at'=>now(),'message'=>'SFA pull completed.']);
            SfaSyncState::where('table_name',$table)->update(['last_success_at'=>now()]);
        } catch (Throwable $e) {
            report($e); $log->update(['status'=>'failed','duration_seconds'=>microtime(true)-$started,'completed_at'=>now(),'message'=>'SFA pull failed.','error_details'=>$e->getMessage()]);
        }
        return $log->fresh();
    }

    private function syncReps(): int
    {
        $rows=DB::connection('sfa_remote')->table('bi_users')->whereIn('user_channel',config('sfa_sync.channels'))->get(); $now=now(); $mapped=[];
        foreach ($rows as $source) { $r=$this->lower($source); if (!isset($r['id'])) continue; $channel=(string)($r['user_channel']??''); $mapped[]=['id'=>$r['id'],'user_reference'=>$r['user_reference']??null,'warehouse_code'=>$r['warehouse_code']??null,'user_type'=>$r['user_type']??null,'user_channel'=>$channel,'team'=>$channel==='1'?'GT':'MT','region_id'=>$this->nullableId($r['region_id']??null),'territory_id'=>$this->nullableId($r['territory_id']??null),'rolegroup_id'=>$this->nullableId($r['rolegroup_id']??null),'rep_category'=>$r['rep_category']??null,'name'=>$r['name']??$r['username']??('Rep '.$r['id']),'email'=>$r['email']??null,'phone_number'=>$r['phone_number']??$r['phone']??null,'status'=>$r['status']??null,'created_at'=>$now,'updated_at'=>$now]; }
        foreach (array_chunk($mapped,500) as $chunk) DB::table('sfa_reps')->upsert($chunk,['id'],['user_reference','warehouse_code','user_type','user_channel','team','region_id','territory_id','rolegroup_id','rep_category','name','email','phone_number','status','updated_at']);
        return count($mapped);
    }

    private function syncCustomers(): int
    {
        $valid=DB::table('sfa_reps')->pluck('id')->all(); if ($valid===[]) throw new \RuntimeException('Pull reps before customers.');
        $rows=DB::connection('sfa_remote')->table('bi_customer_master')->whereIn('USERID',$valid)->where('STATUS',1)->get(); $now=now(); $mapped=[];
        foreach ($rows as $source) { $r=$this->lower($source); $id=$r['shopid']??$r['shop_id']??null; if (!$id) continue; $mapped[]=['sfa_shop_id'=>$id,'customer_code'=>$r['customercode']??$r['customer_code']??null,'customer_name'=>$r['customername']??$r['customer_name']??('Outlet '.$id),'region_id'=>$this->nullableId($r['region_id']??null),'supplied_by'=>$this->nullableId($r['userid']??$r['user_id']??null),'channel'=>$r['channel']??null,'is_active'=>true,'created_at'=>$now,'updated_at'=>$now]; }
        foreach (array_chunk($mapped,500) as $chunk) DB::table('sfa_customers')->upsert($chunk,['sfa_shop_id'],['customer_code','customer_name','region_id','supplied_by','channel','is_active','updated_at']);
        return count($mapped);
    }

    private function lower(object $row): array { return array_change_key_case((array)$row, CASE_LOWER); }
    private function nullableId(mixed $id): mixed { return empty($id) ? null : $id; }
}
