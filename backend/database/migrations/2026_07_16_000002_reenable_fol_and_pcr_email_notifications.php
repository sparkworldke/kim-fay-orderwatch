<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-enable FOL (N1–N6) and PCR (P1–P6) workflow emails.
 * Other bulk notification rules remain paused (R1–R3, R5–R6, SM-*).
 * Also still active outside this table: System Health [CRITICAL], Daily report.
 */
return new class extends Migration
{
    private const FOL_RULES = [
        'FOL-N1' => 'FOL Submitted - HOD Approval',
        'FOL-N2' => 'FOL Stage Approved - Consultant',
        'FOL-N3' => 'FOL Pending Final Approval',
        'FOL-N4' => 'FOL Fully Approved - Consultant',
        'FOL-N5' => 'FOL Approved for Invoicing',
        'FOL-N6' => 'FOL Rejected',
    ];

    private const PCR_RULES = [
        'PCR-P1' => 'PCR Submitted',
        'PCR-P2' => 'PCR Stage Approved',
        'PCR-P3' => 'PCR Final Approved - Pending ERP Apply',
        'PCR-P4' => 'PCR Rejected',
        'PCR-P5' => 'PCR Marked Applied in ERP',
        'PCR-P6' => 'PCR SLA Breach',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('notification_rules')) {
            return;
        }

        $now = now();
        $channels = json_encode(['email', 'in_app']);

        foreach ([...self::FOL_RULES, ...self::PCR_RULES] as $ruleKey => $label) {
            $exists = DB::table('notification_rules')->where('rule_key', $ruleKey)->exists();
            if ($exists) {
                DB::table('notification_rules')
                    ->where('rule_key', $ruleKey)
                    ->update([
                        'is_enabled' => true,
                        'label' => $label,
                        'updated_at' => $now,
                    ]);
            } else {
                DB::table('notification_rules')->insert([
                    'rule_key' => $ruleKey,
                    'label' => $label,
                    'channels' => $channels,
                    'is_enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_rules')) {
            return;
        }

        $keys = [...array_keys(self::FOL_RULES), ...array_keys(self::PCR_RULES)];
        DB::table('notification_rules')
            ->whereIn('rule_key', $keys)
            ->update(['is_enabled' => false, 'updated_at' => now()]);
    }
};
