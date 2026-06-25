<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailbox_folders', function (Blueprint $table) {
            $table->string('auto_sync_cron', 100)->nullable()->after('last_sync_error');
        });

        Schema::table('emails', function (Blueprint $table) {
            $table->string('canonical_po', 100)->nullable()->after('extracted_po_number');
            $table->string('extraction_status', 30)->default('pending')->after('po_extraction_confidence');
            $table->string('match_status', 30)->default('pending')->after('match_classification');
            $table->string('duplicate_flag', 50)->nullable()->after('match_status');
            $table->unsignedBigInteger('canonical_email_id')->nullable()->after('duplicate_flag');
            $table->index('canonical_po');
            $table->index('match_status');
            $table->index('duplicate_flag');
        });

        Schema::create('match_predictions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('email_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('order_nbr', 50)->nullable();
            $table->decimal('confidence', 5, 4)->default(0);
            $table->string('match_type', 30)->default('no_match');
            $table->text('reasoning')->nullable();
            $table->boolean('is_top_prediction')->default(false);
            $table->unsignedSmallInteger('rank')->default(0);
            $table->timestamps();

            $table->foreign('email_id')->references('id')->on('emails')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('acumatica_sales_orders')->nullOnDelete();
            $table->index(['email_id', 'is_top_prediction']);
        });

        Schema::create('match_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('email_id');
            $table->unsignedBigInteger('prediction_id')->nullable();
            $table->string('order_nbr', 50)->nullable();
            $table->string('status', 40);
            $table->string('canonical_po', 100)->nullable();
            $table->unsignedBigInteger('accepted_by')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('canonical_email_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('email_id')->references('id')->on('emails')->cascadeOnDelete();
            $table->foreign('prediction_id')->references('id')->on('match_predictions')->nullOnDelete();
            $table->foreign('accepted_by')->references('id')->on('users')->nullOnDelete();
            $table->index('status');
            $table->index('canonical_po');
        });

        Schema::create('order_match_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mailbox_folder_id');
            $table->date('sync_from');
            $table->date('sync_to');
            $table->unsignedInteger('emails_found')->default(0);
            $table->unsignedInteger('emails_queued')->default(0);
            $table->string('status', 30)->default('processing');
            $table->unsignedBigInteger('triggered_by_user_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('mailbox_folder_id')->references('id')->on('mailbox_folders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_match_sync_runs');
        Schema::dropIfExists('match_log');
        Schema::dropIfExists('match_predictions');

        Schema::table('emails', function (Blueprint $table) {
            $table->dropColumn(['canonical_po', 'extraction_status', 'match_status', 'duplicate_flag', 'canonical_email_id']);
        });

        Schema::table('mailbox_folders', function (Blueprint $table) {
            $table->dropColumn('auto_sync_cron');
        });
    }
};