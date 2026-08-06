<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kp_meeting_purposes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->boolean('allows_internal')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('kp_meeting_action_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->boolean('is_main')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('kp_meetings', function (Blueprint $table) {
            $table->foreignId('purpose_id')->nullable()->after('title')->constrained('kp_meeting_purposes')->nullOnDelete();
            $table->string('meeting_mode', 20)->default('physical')->after('purpose_id');
            $table->boolean('is_internal')->default(false)->after('meeting_mode');
            $table->boolean('is_planned')->default(true)->after('is_internal');
            $table->text('previous_notes')->nullable()->after('notes');
            $table->text('current_notes')->nullable()->after('previous_notes');
            $table->text('outcome')->nullable()->after('current_notes');
            $table->date('follow_up_date')->nullable()->after('outcome');
            $table->string('no_follow_up_reason', 500)->nullable()->after('follow_up_date');
            $table->json('b2b_details')->nullable()->after('no_follow_up_reason');
            $table->timestamp('completed_at')->nullable()->after('status');
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            $table->index(['purpose_id', 'starts_at']);
        });

        Schema::create('kp_meeting_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('kp_meetings')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('kp_meeting_action_categories')->nullOnDelete();
            $table->string('category_snapshot', 120);
            $table->text('description');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['owner_user_id', 'status', 'due_date']);
        });

        Schema::create('kp_meeting_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('kp_meetings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20)->default('accompanier');
            $table->string('status', 20)->default('pending');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['meeting_id', 'user_id']);
        });

        Schema::create('kp_meeting_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('month');
            $table->unsignedInteger('target');
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'month']);
        });

        $purposes = [
            ['Caltronix Calibration', false], ['Caltronix End of Week (Updates)', true],
            ['Collection/ Follow up on Payment', false], ['Customer meeting', false],
            ['Debtors Meeting', true], ['Directors Review', true],
            ['Dispenser Site Survey/ FOL Contract Signing', false], ['Goal Sheet Review', true],
            ['Monthly Reports', true], ['New Client pitch', false], ['One on One', true],
            ['Order Delivery Facilitation/Quotation Sharing', false], ['Proposal', false],
            ['Rich Call', false], ['Statutory Reports', true], ['Technician Meetings', true],
            ['Items Not Ordered Follow-up', false],
        ];
        foreach ($purposes as $index => [$name, $internal]) {
            DB::table('kp_meeting_purposes')->insert([
                'name' => $name, 'allows_internal' => $internal, 'is_active' => true,
                'sort_order' => $index + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (['Next commitment', 'Proposal / quotation', 'Site follow-up', 'Payment follow-up'] as $index => $name) {
            DB::table('kp_meeting_action_categories')->insert([
                'name' => $name, 'is_main' => true, 'is_active' => true,
                'sort_order' => $index + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('kp_meetings')->update([
            'current_notes' => DB::raw('notes'),
            'is_planned' => true,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('kp_meeting_targets');
        Schema::dropIfExists('kp_meeting_participants');
        Schema::dropIfExists('kp_meeting_actions');
        Schema::table('kp_meetings', function (Blueprint $table) {
            $table->dropForeign(['purpose_id']);
            $table->dropIndex(['purpose_id', 'starts_at']);
            $table->dropColumn(['purpose_id', 'meeting_mode', 'is_internal', 'is_planned', 'previous_notes',
                'current_notes', 'outcome', 'follow_up_date', 'no_follow_up_reason', 'b2b_details',
                'completed_at', 'cancelled_at']);
        });
        Schema::dropIfExists('kp_meeting_action_categories');
        Schema::dropIfExists('kp_meeting_purposes');
    }
};
