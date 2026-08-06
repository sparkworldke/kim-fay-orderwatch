<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{
  Schema::create('kp_dormant_settings',function(Blueprint $t){$t->id();$t->unsignedSmallInteger('dormant_months')->default(3);$t->json('excluded_customer_classes')->nullable();$t->unsignedSmallInteger('escalation_months')->default(3);$t->timestamps();});
  DB::table('kp_dormant_settings')->insert(['dormant_months'=>3,'excluded_customer_classes'=>json_encode(['SCHOOL','SCHOOLS']),'escalation_months'=>3,'created_at'=>now(),'updated_at'=>now()]);
  Schema::create('kp_dormant_contact_attempts',function(Blueprint $t){$t->id();$t->string('customer_acumatica_id',50)->index();$t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();$t->foreignId('customer_contact_id')->nullable()->constrained('customer_contacts')->nullOnDelete();$t->boolean('contacted');$t->string('outcome',120);$t->text('comments')->nullable();$t->timestamp('attempted_at');$t->timestamps();});
  Schema::create('kp_dormant_handoffs',function(Blueprint $t){$t->id();$t->string('customer_acumatica_id',50)->index();$t->foreignId('primary_contact_id')->constrained('customer_contacts')->restrictOnDelete();$t->string('destination',50)->default('calltronix');$t->foreignId('handed_off_by')->nullable()->constrained('users')->nullOnDelete();$t->timestamp('handed_off_at');$t->json('contact_snapshot');$t->timestamps();});
  if(Schema::hasTable('kp_meeting_purposes')) DB::table('kp_meeting_purposes')->where('name','White Spot Closure')->update(['name'=>'Items Not Ordered Follow-up','updated_at'=>now()]);
  if(Schema::hasTable('system_settings')) {
   DB::table('system_settings')->updateOrInsert(['key'=>'fol.consumables_months'],['value'=>'3','created_at'=>now(),'updated_at'=>now()]);
   DB::table('system_settings')->updateOrInsert(['key'=>'fol.require_attachment'],['value'=>'false','created_at'=>now(),'updated_at'=>now()]);
  }
 }
 public function down():void{Schema::dropIfExists('kp_dormant_handoffs');Schema::dropIfExists('kp_dormant_contact_attempts');Schema::dropIfExists('kp_dormant_settings');}
};
