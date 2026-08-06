<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up():void{Schema::table('price_change_requests',function(Blueprint $t){$t->decimal('revised_price',15,4)->nullable()->after('proposed_selling_price');$t->timestamp('countered_at')->nullable();$t->foreignId('countered_by')->nullable()->constrained('users')->nullOnDelete();});} public function down():void{Schema::table('price_change_requests',function(Blueprint $t){$t->dropConstrainedForeignId('countered_by');$t->dropColumn(['revised_price','countered_at']);});} };
