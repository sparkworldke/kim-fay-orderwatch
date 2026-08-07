<?php
namespace Database\Seeders;
use App\Models\SfaSyncState;
use Illuminate\Database\Seeder;
class SfaSyncStateSeeder extends Seeder { public function run(): void { foreach (config('sfa_sync.tables') as $table) SfaSyncState::firstOrCreate(['table_name'=>$table], ['sync_mode'=>'full','is_enabled'=>true]); } }
