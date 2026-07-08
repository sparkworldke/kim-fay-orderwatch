<?php

namespace App\Services\Admin;

use App\Models\AcumaticaDeadLetter;
use App\Models\AcumaticaShippingZone;
use App\Models\AcumaticaSyncLog;
use App\Support\ShippingZoneDescription;
use Throwable;

class AcumaticaShippingZoneSyncService
{
    public function __construct(private readonly AcumaticaClient $client)
    {
    }

    public function run(
        ?int $triggeredByUserId = null,
        bool $fromCustomersOnly = false,
        bool $allowCustomerFallback = true,
    ): AcumaticaSyncLog {
        $run = AcumaticaSyncLog::create([
            'sync_type' => 'shipping_zones',
            'started_at' => now(),
            'status' => 'running',
            'record_count' => 0,
            'success_count' => 0,
            'failed_count' => 0,
            'trigger_type' => $triggeredByUserId ? 'manual' : 'background',
            'triggered_by_user_id' => $triggeredByUserId,
        ]);

        StructuredLogger::write('info', 'acumatica', 'shipping_zone_sync_started', [
            'sync_run_id' => $run->id,
            'triggered_by' => $triggeredByUserId,
            'from_customers_only' => $fromCustomersOnly,
        ]);

        try {
            [$zones, $source, $masterUnavailable] = $this->resolveZoneRecords($fromCustomersOnly, $allowCustomerFallback);
            $total = count($zones);
            $success = 0;
            $failed = 0;

            foreach ($zones as $raw) {
                $zoneId = $this->extractZoneId($raw);

                if (! $zoneId) {
                    $failed++;
                    continue;
                }

                try {
                    $this->upsertZone($zoneId, $this->extractZoneDescription($raw), $run->id);
                    $success++;
                } catch (Throwable $e) {
                    $failed++;

                    AcumaticaDeadLetter::create([
                        'sync_run_id' => $run->id,
                        'resource_type' => 'shipping_zone',
                        'resource_id' => $zoneId,
                        'attempt_count' => 1,
                        'last_error' => $e->getMessage(),
                        'raw_payload' => $raw,
                    ]);

                    StructuredLogger::write('error', 'acumatica', 'shipping_zone_sync_record_failed', [
                        'sync_run_id' => $run->id,
                        'zone_id' => $zoneId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $filters = [
                'source' => $source,
                'master_unavailable' => $masterUnavailable,
            ];

            $run->update([
                'ended_at' => now(),
                'status' => $failed === $total && $total > 0 ? 'failed' : 'completed',
                'record_count' => $total,
                'success_count' => $success,
                'failed_count' => $failed,
                'filters' => $filters,
                'error_message' => $masterUnavailable
                    ? 'Zone master entity not exposed on Acumatica endpoint; synced from Customer.ShippingZoneID.'
                    : null,
            ]);

            StructuredLogger::write('info', 'acumatica', 'shipping_zone_sync_completed', [
                'sync_run_id' => $run->id,
                'total' => $total,
                'success' => $success,
                'failed' => $failed,
                'source' => $source,
                'master_unavailable' => $masterUnavailable,
            ]);
        } catch (Throwable $e) {
            $run->update([
                'ended_at' => now(),
                'status' => 'failed',
                'record_count' => 0,
                'success_count' => 0,
                'failed_count' => 1,
                'error_message' => $e->getMessage(),
            ]);

            StructuredLogger::write('error', 'acumatica', 'shipping_zone_sync_failed', [
                'sync_run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $run->fresh();
    }

    public function ensureZoneExists(?string $zoneId, ?int $syncRunId = null): void
    {
        $normalized = strtoupper(trim((string) ($zoneId ?? '')));
        if ($normalized === '') {
            return;
        }

        $existing = AcumaticaShippingZone::where('acumatica_id', $normalized)->first();

        if ($existing) {
            $metadata = ShippingZoneDescription::metadataForId($normalized);
            $updates = [];

            if ($existing->description === null) {
                $description = ShippingZoneDescription::forId($normalized);
                if ($description !== null) {
                    $updates['description'] = $description;
                }
            }

            if ($existing->name === null && $metadata['name'] !== null) {
                $updates['name'] = $metadata['name'];
            }

            if ($existing->region === null && $metadata['region'] !== null) {
                $updates['region'] = $metadata['region'];
            }

            if ($updates !== []) {
                $existing->update([
                    ...$updates,
                    'sync_run_id' => $syncRunId ?? $existing->sync_run_id,
                    'synced_at' => now(),
                ]);
            }

            return;
        }

        $metadata = ShippingZoneDescription::metadataForId($normalized);

        AcumaticaShippingZone::create([
            'acumatica_id' => $normalized,
            'description' => ShippingZoneDescription::forId($normalized),
            'name' => $metadata['name'],
            'region' => $metadata['region'],
            'sync_run_id' => $syncRunId,
            'synced_at' => now(),
        ]);
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: string, 2: bool}
     */
    private function resolveZoneRecords(bool $fromCustomersOnly, bool $allowCustomerFallback): array
    {
        if ($fromCustomersOnly) {
            return [$this->collectZonesFromCustomers(), 'customers', true];
        }

        $zones = $this->client->fetchAllShippingZones();
        if ($zones !== []) {
            return [$zones, 'master', false];
        }

        if (! $allowCustomerFallback) {
            StructuredLogger::write('warning', 'acumatica', 'shipping_zone_master_unavailable', [
                'message' => 'Zone entity not exposed; zones will be created during customer sync.',
            ]);

            return [[], 'none', true];
        }

        return [$this->collectZonesFromCustomers(), 'customers', true];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectZonesFromCustomers(): array
    {
        $customers = $this->client->fetchAllCustomers();
        $seen = [];
        $zones = [];

        foreach ($customers as $raw) {
            $zoneId = $this->extractZoneIdFromCustomer($raw);
            if ($zoneId === null || isset($seen[$zoneId])) {
                continue;
            }

            $seen[$zoneId] = true;
            $zones[] = ['ZoneID' => ['value' => $zoneId]];
        }

        return $zones;
    }

    private function upsertZone(string $zoneId, ?string $acumaticaDescription, int $runId): void
    {
        $resolved = ShippingZoneDescription::resolveRecord($zoneId, $acumaticaDescription);

        AcumaticaShippingZone::updateOrCreate(
            ['acumatica_id' => $zoneId],
            [
                'description' => $resolved['description'],
                'name' => $resolved['name'],
                'region' => $resolved['region'],
                'sync_run_id' => $runId,
                'synced_at' => now(),
            ],
        );
    }

    private function extractZoneIdFromCustomer(array $raw): ?string
    {
        return $this->normalizeZoneId(AcumaticaClient::scalarVal($raw['ShippingZoneID'] ?? null));
    }

    private function extractZoneId(array $raw): ?string
    {
        return $this->normalizeZoneId(
            AcumaticaClient::scalarVal($raw['ZoneID'] ?? null)
            ?? AcumaticaClient::scalarVal($raw['ShippingZoneID'] ?? null),
        );
    }

    private function extractZoneDescription(array $raw): ?string
    {
        return AcumaticaClient::scalarVal($raw['Description'] ?? null);
    }

    private function normalizeZoneId(mixed $zoneId): ?string
    {
        if ($zoneId === null || is_array($zoneId) || is_object($zoneId)) {
            return null;
        }

        $normalized = strtoupper(trim((string) $zoneId));

        return $normalized !== '' ? $normalized : null;
    }
}