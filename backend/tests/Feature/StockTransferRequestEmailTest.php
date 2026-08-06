<?php

namespace Tests\Feature;

use App\Mail\StockTransferRequestMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StockTransferRequestEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_send_transfer_request_email(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'role' => 'Sales Consultant',
            'name' => 'Warehouse Planner',
            'is_active' => true,
        ]);

        $payload = [
            'recipients' => ['stores@kimfay.test', 'logistics@kimfay.test'],
            'note' => 'Please prioritize consumer brands.',
            'requests' => [
                [
                    'inventory_id' => 'SKU-001',
                    'product_name' => 'Test Product',
                    'brand' => 'Test Brand',
                    'source_warehouse' => 'TPFGS',
                    'quantity' => 120,
                    'sources' => [
                        [
                            'warehouse_name' => 'TPFGS',
                            'qty_on_hand' => 200,
                            'qty_available' => 150,
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/operations/production/transfer-requests/email', $payload);

        $response->assertOk()
            ->assertJsonPath('message', 'Transfer request notification sent.')
            ->assertJsonPath('request_count', 1)
            ->assertJsonPath('recipients.0', 'stores@kimfay.test');

        Mail::assertSent(StockTransferRequestMail::class, function (StockTransferRequestMail $mail) {
            $html = $mail->render();

            return str_contains($html, 'SKU-001')
                && str_contains($html, 'Test Product')
                && str_contains($html, 'TPFGS')
                && str_contains($html, 'Please prioritize consumer brands.')
                && str_contains($html, 'Warehouse Planner');
        });
    }

    public function test_rejects_invalid_recipients(): void
    {
        Mail::fake();

        $user = User::factory()->create(['role' => 'User', 'is_active' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/operations/production/transfer-requests/email', [
                'recipients' => ['not-an-email'],
                'requests' => [
                    [
                        'inventory_id' => 'SKU-001',
                        'product_name' => 'Test Product',
                        'brand' => 'Brand',
                        'source_warehouse' => 'WH1',
                        'quantity' => 10,
                    ],
                ],
            ]);

        $response->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_requires_at_least_one_request(): void
    {
        Mail::fake();

        $user = User::factory()->create(['role' => 'User', 'is_active' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/operations/production/transfer-requests/email', [
                'recipients' => ['ok@kimfay.test'],
                'requests' => [],
            ]);

        $response->assertStatus(422);
        Mail::assertNothingSent();
    }
}
