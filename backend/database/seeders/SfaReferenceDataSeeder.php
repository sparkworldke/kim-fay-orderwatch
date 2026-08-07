<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
class SfaReferenceDataSeeder extends Seeder {
    public function run(): void {
        $now=now();
        DB::table('sfa_regions')->upsert(array_map(fn($r)=>['id'=>$r[0],'name'=>$r[1],'is_active'=>true,'created_at'=>$now,'updated_at'=>$now], [[1,'NAIROBI'],[2,'COAST'],[3,'WESTERN'],[4,'RIFT VALLEY'],[5,'MOUNT KENYA']]), ['id'], ['name','is_active','updated_at']);
        DB::table('sfa_channels')->upsert(array_map(fn($name)=>['name'=>$name,'created_at'=>$now,'updated_at'=>$now], ['General Trade','Modern Trade','Wholesale','Key Account']), ['name'], ['updated_at']);
        DB::table('sfa_rolegroups')->upsert(array_map(fn($r)=>['id'=>$r[0],'name'=>$r[1],'is_active'=>true,'created_at'=>$now,'updated_at'=>$now], [[1,'Sales Rep'],[2,'Merchandiser'],[3,'Supervisor'],[4,'Van Sales']]), ['id'], ['name','updated_at']);
    }
}
