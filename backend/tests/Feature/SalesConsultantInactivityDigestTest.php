<?php

namespace Tests\Feature;

use App\Mail\SalesConsultantInactivityDigestMail;
use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use App\Models\Product;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Sales\SalesConsultantInactivityDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SalesConsultantInactivityDigestTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_inactive_consultant_receives_scoped_digest_once_per_day(): void
    {
        Mail::fake();
        $user = User::factory()->create([
            'role' => 'Sales Consultant', 'rep_code' => 'P100', 'is_active' => true,
            'inactivity_digest_enabled' => true,
        ]);
        UserSession::create(['user_id' => $user->id, 'login_at' => now()->subHours(26), 'login_mode' => 'password']);
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO001', 'order_type' => 'SO', 'customer_acumatica_id' => 'C001',
            'customer_name' => 'Outlet', 'sales_consultant_rep_code' => 'P100', 'status' => 'Shipping',
            'order_date' => now()->subDay(), 'order_total' => 1000,
        ]);
        $inventory = AcumaticaInventoryItem::create(['inventory_id' => 'SKU1', 'description' => 'Product', 'is_stock_item' => true]);
        Product::create(['inventory_id' => 'SKU1', 'acumatica_inventory_item_id' => $inventory->id,
            'name' => 'Product', 'ownership' => 'manufactured', 'source' => 'manual']);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $order->id, 'line_nbr' => 1, 'inventory_id' => 'SKU1',
            'order_qty' => 10, 'shipped_qty' => 4, 'qty_on_shipments' => 4,
            'cancelled_qty' => 0, 'unfilled_reason_code' => 'production_stockout',
        ]);

        $this->artisan('orderwatch:send-consultant-inactivity-digests')->assertSuccessful();

        Mail::assertSent(SalesConsultantInactivityDigestMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email)
                && $mail->digest['orders']['total'] === 1
                && $mail->digest['orders']['shipping'] === 1
                && $mail->digest['undelivered']['manufactured_units'] === 6.0;
        });
        $this->assertNotNull($user->fresh()->last_inactivity_digest_sent_at);

        $this->artisan('orderwatch:send-consultant-inactivity-digests')->assertSuccessful();
        Mail::assertSent(SalesConsultantInactivityDigestMail::class, 1);
    }

    public function test_disabled_recent_or_inactive_accounts_are_not_emailed(): void
    {
        Mail::fake();
        $disabled = User::factory()->create(['role' => 'Sales Consultant', 'is_active' => true, 'inactivity_digest_enabled' => false]);
        $recent = User::factory()->create(['role' => 'Sales Consultant', 'is_active' => true, 'inactivity_digest_enabled' => true]);
        $inactive = User::factory()->create(['role' => 'Sales Consultant', 'is_active' => false, 'inactivity_digest_enabled' => true]);
        UserSession::create(['user_id' => $disabled->id, 'login_at' => now()->subDays(2)]);
        UserSession::create(['user_id' => $recent->id, 'login_at' => now()->subHours(3)]);
        UserSession::create(['user_id' => $inactive->id, 'login_at' => now()->subDays(2)]);

        $this->artisan('orderwatch:send-consultant-inactivity-digests')->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function test_admin_can_toggle_one_or_all_consultants(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_super_admin' => true, 'is_active' => true]);
        $one = User::factory()->create(['role' => 'Sales Consultant', 'inactivity_digest_enabled' => false]);
        $two = User::factory()->create(['role' => 'Sales Consultant', 'inactivity_digest_enabled' => false]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/sales-consultant-digests/{$one->id}", ['enabled' => true])
            ->assertOk()->assertJsonPath('enabled', true);
        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/sales-consultant-digests/bulk', ['enabled' => true])
            ->assertOk();

        $this->assertTrue($one->fresh()->inactivity_digest_enabled);
        $this->assertTrue($two->fresh()->inactivity_digest_enabled);
    }
}
