<?php

namespace App\Services\Admin;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaDeadLetter;
use App\Models\AcumaticaReconciliationResult;
use App\Models\AcumaticaSyncLog;
use Throwable;

class AcumaticaCustomerSyncService
{
    public function __construct(
        private readonly AcumaticaClient $client,
    ) {
    }

    public function run(?int $triggeredByUserId = null): AcumaticaSyncLog
    {
        $run = AcumaticaSyncLog::create([
            'sync_type'            => 'customers',
            'started_at'           => now(),
            'status'               => 'running',
            'record_count'         => 0,
            'success_count'        => 0,
            'failed_count'         => 0,
            'trigger_type'         => $triggeredByUserId ? 'manual' : 'background',
            'triggered_by_user_id' => $triggeredByUserId,
        ]);

        StructuredLogger::write('info', 'acumatica', 'customer_sync_started', [
            'sync_run_id' => $run->id,
            'triggered_by' => $triggeredByUserId,
        ]);

        try {
            $customers = $this->client->fetchAllCustomers();

            $total   = count($customers);
            $success = 0;
            $failed  = 0;

            foreach ($customers as $raw) {
                try {
                    $this->upsertCustomer($raw, $run->id);
                    $success++;
                } catch (Throwable $e) {
                    $failed++;
                    $resourceId = AcumaticaClient::val($raw['CustomerID'] ?? null) ?? 'unknown';

                    AcumaticaDeadLetter::create([
                        'sync_run_id'   => $run->id,
                        'resource_type' => 'customer',
                        'resource_id'   => $resourceId,
                        'attempt_count' => 1,
                        'last_error'    => $e->getMessage(),
                        'raw_payload'   => $raw,
                    ]);

                    StructuredLogger::write('error', 'acumatica', 'customer_sync_record_failed', [
                        'sync_run_id'   => $run->id,
                        'customer_id'   => $resourceId,
                        'error'         => $e->getMessage(),
                    ]);
                }
            }

            $run->update([
                'ended_at'      => now(),
                'status'        => $failed === $total && $total > 0 ? 'failed' : 'completed',
                'record_count'  => $total,
                'success_count' => $success,
                'failed_count'  => $failed,
            ]);

            StructuredLogger::write('info', 'acumatica', 'customer_sync_completed', [
                'sync_run_id'   => $run->id,
                'total'         => $total,
                'success'       => $success,
                'failed'        => $failed,
            ]);
        } catch (Throwable $e) {
            $run->update([
                'ended_at'      => now(),
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            StructuredLogger::write('error', 'acumatica', 'customer_sync_failed', [
                'sync_run_id' => $run->id,
                'error'       => $e->getMessage(),
            ]);
        }

        return $run->fresh();
    }

    private function upsertCustomer(array $raw, int $runId): void
    {
        $acumaticaId = AcumaticaClient::val($raw['CustomerID'] ?? null);

        if (! $acumaticaId) {
            throw new \InvalidArgumentException('Customer record missing CustomerID');
        }

        $lastModified = AcumaticaClient::val($raw['LastModifiedDateTime'] ?? null);

        $billingAddress  = $this->extractAddress($raw['BillingAddress'] ?? null);
        $shippingAddress = $this->extractAddress($raw['ShippingAddress'] ?? null);

        $primaryContact  = $raw['PrimaryContact'] ?? null;
        $email           = is_array($primaryContact) ? AcumaticaClient::val($primaryContact['Email'] ?? null) : null;
        $phone           = is_array($primaryContact) ? AcumaticaClient::val($primaryContact['Phone1'] ?? null) : null;

        $existing = AcumaticaCustomer::where('acumatica_id', $acumaticaId)->first();

        $data = [
            'name'                     => AcumaticaClient::val($raw['CustomerName'] ?? null) ?? '',
            'status'                   => AcumaticaClient::val($raw['Status'] ?? null),
            'email'                    => $email,
            'phone'                    => $phone,
            'customer_class'           => AcumaticaClient::val($raw['CustomerClass'] ?? null),
            'payment_terms'            => AcumaticaClient::val($raw['PaymentTermsID'] ?? null),
            'tax_zone'                 => AcumaticaClient::val($raw['TaxZone'] ?? null),
            'billing_address'          => $billingAddress,
            'shipping_address'         => $shippingAddress,
            'sync_run_id'              => $runId,
            'acumatica_last_modified'  => $lastModified ? new \DateTime($lastModified) : null,
            'synced_at'                => now(),
        ];

        if ($existing) {
            // Flag reconciliation issues for required fields that changed
            $this->checkReconciliation($existing, $data, $runId, $acumaticaId);
            $existing->update($data);
        } else {
            AcumaticaCustomer::create(['acumatica_id' => $acumaticaId] + $data);
        }

        // Validate required fields
        $this->validateRequiredFields($acumaticaId, $data, $runId);
    }

    private function extractAddress(?array $addr): ?array
    {
        if (! $addr) {
            return null;
        }

        return array_filter([
            'address_line1' => AcumaticaClient::val($addr['AddressLine1'] ?? null),
            'address_line2' => AcumaticaClient::val($addr['AddressLine2'] ?? null),
            'city'          => AcumaticaClient::val($addr['City'] ?? null),
            'state'         => AcumaticaClient::val($addr['State'] ?? null),
            'postal_code'   => AcumaticaClient::val($addr['PostalCode'] ?? null),
            'country'       => AcumaticaClient::val($addr['Country'] ?? null),
        ]);
    }

    private function validateRequiredFields(string $acumaticaId, array $data, int $runId): void
    {
        $required = [
            'email'           => 'Primary contact email',
            'billing_address' => 'Billing address',
        ];

        foreach ($required as $field => $label) {
            if (empty($data[$field])) {
                AcumaticaReconciliationResult::create([
                    'sync_run_id'        => $runId,
                    'resource_type'      => 'customer',
                    'resource_id'        => $acumaticaId,
                    'field_name'         => $field,
                    'local_value'        => null,
                    'acumatica_value'    => null,
                    'severity'           => 'warning',
                    'remediation_status' => 'open',
                ]);
            }
        }
    }

    private function checkReconciliation(AcumaticaCustomer $existing, array $incoming, int $runId, string $acumaticaId): void
    {
        $watchFields = ['customer_class', 'payment_terms', 'tax_zone'];

        foreach ($watchFields as $field) {
            $oldVal = $existing->{$field};
            $newVal = $incoming[$field] ?? null;

            if ($oldVal !== null && $newVal !== null && $oldVal !== $newVal) {
                AcumaticaReconciliationResult::create([
                    'sync_run_id'        => $runId,
                    'resource_type'      => 'customer',
                    'resource_id'        => $acumaticaId,
                    'field_name'         => $field,
                    'local_value'        => (string) $oldVal,
                    'acumatica_value'    => (string) $newVal,
                    'severity'           => 'info',
                    'remediation_status' => 'open',
                ]);
            }
        }
    }
}
