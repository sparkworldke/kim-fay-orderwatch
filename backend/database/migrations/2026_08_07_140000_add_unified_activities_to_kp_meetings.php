<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kp_meeting_purposes', function (Blueprint $table) {
            $table->json('activity_types')->nullable()->after('name');
            $table->boolean('customer_required')->default(true)->after('allows_internal');
        });

        Schema::create('kp_activity_questionnaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purpose_id')->nullable()->constrained('kp_meeting_purposes')->nullOnDelete();
            $table->string('activity_type', 20);
            $table->unsignedInteger('version')->default(1);
            $table->json('questions');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['purpose_id', 'activity_type', 'version'], 'kp_activity_questionnaire_version_unique');
            $table->index(['activity_type', 'is_active']);
        });

        Schema::table('kp_meetings', function (Blueprint $table) {
            $table->string('activity_type', 20)->default('meeting')->after('title');
            $table->foreignId('questionnaire_id')->nullable()->after('purpose_id')->constrained('kp_activity_questionnaires')->nullOnDelete();
            $table->unsignedInteger('questionnaire_version')->nullable()->after('questionnaire_id');
            $table->json('questionnaire_answers')->nullable()->after('b2b_details');
            $table->index(['activity_type', 'starts_at'], 'kp_activities_type_date_index');
            $table->index(['owner_user_id', 'status', 'starts_at'], 'kp_activities_owner_status_date_index');
            $table->index(['customer_acumatica_id', 'starts_at'], 'kp_activities_customer_date_index');
            $table->index(['follow_up_date', 'status'], 'kp_activities_follow_up_status_index');
        });

        DB::table('kp_meetings')->whereNull('activity_type')->update(['activity_type' => 'meeting']);
        DB::table('kp_meeting_purposes')->whereNull('activity_types')->update([
            'activity_types' => json_encode(['meeting', 'visit']),
        ]);

        foreach ([
            ['Phone follow-up', ['phone']],
            ['Customer email', ['email']],
            ['Customer visit', ['visit', 'meeting']],
        ] as $index => [$name, $types]) {
            DB::table('kp_meeting_purposes')->updateOrInsert(['name' => $name], [
                'activity_types' => json_encode($types), 'allows_internal' => false,
                'customer_required' => true, 'is_active' => true, 'sort_order' => 100 + $index,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $questions = [
            ['key'=>'contact_person','label'=>'Who was engaged?','type'=>'text','required'=>true],
            ['key'=>'customer_need','label'=>'Customer need or discussion focus','type'=>'text','required'=>true],
            ['key'=>'commitment','label'=>'Agreed next commitment','type'=>'text','required'=>true],
        ];
        foreach (['phone', 'email', 'meeting', 'visit'] as $type) {
            DB::table('kp_activity_questionnaires')->insert([
                'purpose_id'=>null, 'activity_type'=>$type, 'version'=>1,
                'questions'=>json_encode($questions), 'is_active'=>true,
                'created_by'=>null, 'created_at'=>now(), 'updated_at'=>now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('kp_meetings', function (Blueprint $table) {
            $table->dropForeign(['questionnaire_id']);
            $table->dropIndex('kp_activities_type_date_index');
            $table->dropIndex('kp_activities_owner_status_date_index');
            $table->dropIndex('kp_activities_customer_date_index');
            $table->dropIndex('kp_activities_follow_up_status_index');
            $table->dropColumn(['activity_type', 'questionnaire_id', 'questionnaire_version', 'questionnaire_answers']);
        });
        Schema::dropIfExists('kp_activity_questionnaires');
        Schema::table('kp_meeting_purposes', fn (Blueprint $table) => $table->dropColumn(['activity_types', 'customer_required']));
    }
};
