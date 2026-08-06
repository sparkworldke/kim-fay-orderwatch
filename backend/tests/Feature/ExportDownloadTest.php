<?php

namespace Tests\Feature;

use App\Jobs\ProcessExportDownloadJob;
use App\Models\ExportDownload;
use App\Models\User;
use App\Services\Exports\ExportDownloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExportDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_queue_and_list_background_exports(): void
    {
        Bus::fake([ProcessExportDownloadJob::class]);

        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/downloads', [
                'type' => 'backorders',
                'filters' => [
                    'date_from' => '2026-07-01',
                    'date_to' => '2026-07-27',
                ],
            ])
            ->assertStatus(202)
            ->assertJsonPath('download.type', 'backorders')
            ->assertJsonPath('download.status', 'queued');

        Bus::assertDispatched(ProcessExportDownloadJob::class);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/downloads')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'backorders')
            ->assertJsonPath('data.0.status', 'queued');

        $this->assertDatabaseHas('export_downloads', [
            'user_id' => $user->id,
            'type' => 'backorders',
            'status' => ExportDownload::STATUS_QUEUED,
        ]);
    }

    public function test_user_cannot_access_another_users_download(): void
    {
        $owner = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        $other = User::factory()->create(['role' => 'Sales Consultant', 'is_active' => true]);

        $download = ExportDownload::query()->create([
            'user_id' => $owner->id,
            'type' => ExportDownload::TYPE_INVENTORY,
            'status' => ExportDownload::STATUS_READY,
            'filename' => 'test.xlsx',
            'path' => 'export-downloads/1/test.xlsx',
        ]);

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/downloads/{$download->id}")
            ->assertForbidden();
    }

    public function test_queue_rejects_invalid_type(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/downloads', ['type' => 'not-a-real-export'])
            ->assertUnprocessable();
    }

    public function test_service_queues_with_sanitized_filters(): void
    {
        Bus::fake([ProcessExportDownloadJob::class]);
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        $download = app(ExportDownloadService::class)->queue($user, 'fill_rate', [
            'date_from' => '2026-07-01',
            'date_to' => '',
            'brand' => 'Vatika',
            'empty' => null,
        ]);

        $this->assertSame(ExportDownload::STATUS_QUEUED, $download->status);
        $this->assertSame('2026-07-01', $download->filters['date_from'] ?? null);
        $this->assertSame('Vatika', $download->filters['brand'] ?? null);
        $this->assertArrayNotHasKey('date_to', $download->filters ?? []);
    }

    public function test_user_can_queue_report_for_an_email_recipient(): void
    {
        Bus::fake([ProcessExportDownloadJob::class]);
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/downloads', [
                'type' => 'items_not_delivered',
                'recipient_email' => 'operations@example.com',
                'delivery_mode' => 'link',
            ])
            ->assertStatus(202)
            ->assertJsonPath('download.recipient_email', 'operations@example.com')
            ->assertJsonPath('download.delivery_mode', 'link');

        $this->assertDatabaseHas('export_downloads', [
            'recipient_email' => 'operations@example.com',
            'delivery_mode' => 'link',
        ]);
    }

    public function test_public_token_download_does_not_require_login_and_expires(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        $token = Str::random(64);
        Storage::disk('local')->put('export-downloads/report.xlsx', 'excel-content');

        $download = ExportDownload::query()->create([
            'user_id' => $user->id,
            'type' => ExportDownload::TYPE_INVENTORY,
            'status' => ExportDownload::STATUS_READY,
            'filename' => 'report.xlsx',
            'path' => 'export-downloads/report.xlsx',
            'public_token' => $token,
            'expires_at' => now()->addHour(),
        ]);

        $this->get("/api/public/downloads/{$token}")->assertOk();

        $download->update(['expires_at' => now()->subMinute()]);
        $this->getJson("/api/public/downloads/{$token}")->assertStatus(410);
    }
}
