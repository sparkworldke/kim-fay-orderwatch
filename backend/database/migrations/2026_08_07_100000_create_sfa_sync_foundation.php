<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sfa_regions', fn (Blueprint $t) => $this->reference($t));
        Schema::create('sfa_territories', function (Blueprint $t) { $this->reference($t); $t->unsignedBigInteger('region_id')->nullable()->index(); });
        Schema::create('sfa_channels', function (Blueprint $t) { $t->id(); $t->string('name')->unique(); $t->timestamps(); });
        Schema::create('sfa_rolegroups', fn (Blueprint $t) => $this->reference($t));
        Schema::create('sfa_reps', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary(); $t->string('user_reference')->nullable(); $t->string('warehouse_code')->nullable(); $t->string('user_type')->nullable();
            $t->string('user_channel', 10)->index(); $t->string('team', 10)->index(); $t->unsignedBigInteger('region_id')->nullable()->index();
            $t->unsignedBigInteger('territory_id')->nullable()->index(); $t->unsignedBigInteger('rolegroup_id')->nullable(); $t->string('rep_category')->nullable();
            $t->string('name'); $t->string('email')->nullable(); $t->string('phone_number')->nullable(); $t->string('status')->nullable()->index();
            $t->string('acumatica_employee_id')->nullable(); $t->unsignedBigInteger('supervisor_id')->nullable(); $t->unsignedBigInteger('asm_id')->nullable();
            $t->string('match_status')->default('unmatched'); $t->timestamp('matched_at')->nullable(); $t->timestamps();
        });
        Schema::create('sfa_customers', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('sfa_shop_id')->unique(); $t->string('customer_code')->nullable()->index(); $t->string('customer_name');
            $t->unsignedBigInteger('region_id')->nullable()->index(); $t->unsignedBigInteger('supplied_by')->nullable()->index(); $t->string('channel')->nullable();
            $t->boolean('is_active')->default(true); $t->string('acumatica_customer_id')->nullable(); $t->string('match_status')->default('unmatched'); $t->timestamp('matched_at')->nullable(); $t->timestamps();
        });
        Schema::create('sfa_customer_visits', function (Blueprint $t) {
            $t->id(); $t->string('sfa_visit_id')->unique(); $t->unsignedBigInteger('shop_id')->nullable()->index(); $t->unsignedBigInteger('user_id')->index(); $t->string('user_name')->nullable();
            $t->dateTime('checkin_time')->index(); $t->dateTime('checkout_time')->nullable(); $t->decimal('time_spent',10,2)->nullable(); $t->string('outlet_status')->nullable();
            $t->string('region_name')->nullable(); $t->string('route_name')->nullable(); $t->unsignedBigInteger('route_id')->nullable(); $t->timestamps(); $t->index(['user_id','checkin_time']);
        });
        Schema::create('sfa_start_end_days', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('user_id')->index(); $t->string('user_name')->nullable(); $t->dateTime('start_day_time')->index(); $t->dateTime('close_day_time')->nullable(); $t->string('day_status')->nullable(); $t->timestamps(); $t->unique(['user_id','start_day_time']); });
        Schema::create('sfa_sales_entries', function (Blueprint $t) { $t->id(); $t->string('entry_id')->nullable(); $t->unsignedBigInteger('sales_rep_id')->index(); $t->string('sales_rep_name')->nullable(); $t->unsignedBigInteger('customer_id')->nullable()->index(); $t->string('customer_name')->nullable(); $t->string('product_id')->nullable(); $t->string('product_name')->nullable(); $t->decimal('quantity',14,4)->default(0); $t->decimal('value_sold',16,2)->default(0); $t->dateTime('entry_time')->index(); $t->timestamps(); $t->unique(['entry_id','product_id']); $t->index(['sales_rep_id','entry_time']); });
        Schema::create('sfa_daily_performances', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('rep_id'); $t->date('date'); $t->string('rep_name'); $t->string('region')->nullable(); $t->decimal('sales_achieved',16,2)->default(0); $t->decimal('volume_achieved',14,4)->default(0); $t->unsignedInteger('customers_in_route')->default(0); $t->unsignedInteger('actual_visits')->default(0); $t->unsignedInteger('unique_visits')->default(0); $t->decimal('coverage',7,2)->default(0); $t->unsignedInteger('successful_visits')->default(0); $t->dateTime('first_checkin')->nullable(); $t->dateTime('last_checkout')->nullable(); $t->timestamps(); $t->unique(['rep_id','date']); });
        Schema::create('sfa_sync_logs', function (Blueprint $t) { $t->id(); $t->string('batch_id',50)->index(); $t->string('table_name')->index(); $t->date('data_date')->nullable(); $t->string('status')->index(); $t->unsignedInteger('rows_processed')->default(0); $t->unsignedInteger('rows_inserted')->default(0); $t->unsignedInteger('rows_updated')->default(0); $t->unsignedInteger('rows_failed')->default(0); $t->decimal('duration_seconds',12,3)->nullable(); $t->text('message')->nullable(); $t->text('error_details')->nullable(); $t->timestamp('started_at')->nullable(); $t->timestamp('completed_at')->nullable(); $t->timestamps(); });
        Schema::create('sfa_sync_states', function (Blueprint $t) { $t->id(); $t->string('table_name')->unique(); $t->timestamp('last_sync_at')->nullable(); $t->timestamp('last_success_at')->nullable(); $t->date('last_data_date')->nullable(); $t->string('sync_mode')->default('full'); $t->boolean('is_enabled')->default(true); $t->timestamps(); });
    }

    private function reference(Blueprint $t): void { $t->unsignedBigInteger('id')->primary(); $t->string('name'); $t->boolean('is_active')->default(true); $t->timestamps(); }

    public function down(): void
    {
        foreach (['sfa_sync_states','sfa_sync_logs','sfa_daily_performances','sfa_sales_entries','sfa_start_end_days','sfa_customer_visits','sfa_customers','sfa_reps','sfa_rolegroups','sfa_channels','sfa_territories','sfa_regions'] as $table) Schema::dropIfExists($table);
    }
};
