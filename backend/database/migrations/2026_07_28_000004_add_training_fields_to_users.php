<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up():void{Schema::table('users',function(Blueprint $t){$t->timestamp('trained_at')->nullable()->after('is_shared_mailbox')->index();$t->foreignId('trained_by')->nullable()->constrained('users')->nullOnDelete();});} public function down():void{Schema::table('users',function(Blueprint $t){$t->dropConstrainedForeignId('trained_by');$t->dropColumn('trained_at');});} };
