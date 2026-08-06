<?php

namespace App\Console\Commands;

use App\Models\AcumaticaCustomer;
use App\Models\User;
use App\Models\UserCustomerAssignment;
use App\Services\Cache\DomainCache;
use App\Services\Team\CustomerAttributionService;
use App\Services\Team\OrgTreeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SplFileObject;
use Symfony\Component\Console\Command\Command as CommandAlias;

class ImportModernTradeOutlets extends Command
{
    protected $signature = 'customers:import-modern-trade
                            {file? : CSV containing Customer ID, Channel, and RepCode}
                            {--purity=moderntrade@kimfay.com : Purity\'s email address}
                            {--vignesh=cco@kimfay.com : Vignesh\'s email address}
                            {--actor= : Admin user id recorded against changes}
                            {--dry-run : Validate and preview without writing}';

    protected $description = 'Classify MT outlets as MT1/MT2, assign them by rep code, and establish the rep -> Purity -> Vignesh reporting tree.';

    public function handle(CustomerAttributionService $attribution, OrgTreeService $orgTree): int
    {
        try {
            $file = $this->resolveFile();
            $rows = $this->readRows($file);
            [$purity, $vignesh, $resolvedRows, $reps] = $this->validateImport($rows, $attribution, $orgTree);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return CommandAlias::FAILURE;
        }

        $counts = collect($resolvedRows)->countBy('channel');
        $this->info(sprintf(
            'Validated %d outlet(s): MT1=%d, MT2=%d, reps=%d.',
            count($resolvedRows),
            $counts->get('MT1', 0),
            $counts->get('MT2', 0),
            count($reps),
        ));
        $this->line("Reporting tree: reps -> {$purity->name} -> {$vignesh->name}.");

        if ((bool) $this->option('dry-run')) {
            $this->comment('Dry run complete; no changes were written.');

            return CommandAlias::SUCCESS;
        }

        try {
            $actorId = $this->resolveActorId();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return CommandAlias::FAILURE;
        }
        $now = now();

        DB::transaction(function () use ($resolvedRows, $reps, $purity, $vignesh, $actorId, $now) {
            foreach ($resolvedRows as $row) {
                AcumaticaCustomer::query()
                    ->where('acumatica_id', $row['customer_id'])
                    ->update([
                        'sales_channel_code' => $row['channel'],
                        'sales_region' => $row['region'],
                        'updated_at' => $now,
                    ]);

                DB::table('customer_sales_channel_overrides')->updateOrInsert(
                    ['customer_acumatica_id' => $row['customer_id']],
                    [
                        'sales_channel_code' => $row['channel'],
                        'sales_region' => $row['region'],
                        'is_active' => true,
                        'created_by' => $actorId,
                        'change_reason' => 'Modern Trade outlet CSV import',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );

                UserCustomerAssignment::query()
                    ->where('customer_acumatica_id', $row['customer_id'])
                    ->whereIn('assignment_type', [
                        UserCustomerAssignment::TYPE_SERVICING,
                        UserCustomerAssignment::TYPE_LEGACY_PRIMARY,
                    ])
                    ->where('user_id', '!=', $row['user_id'])
                    ->delete();

                UserCustomerAssignment::query()->updateOrCreate(
                    [
                        'user_id' => $row['user_id'],
                        'customer_acumatica_id' => $row['customer_id'],
                        'assignment_type' => UserCustomerAssignment::TYPE_SERVICING,
                    ],
                    [
                        'assigned_by' => $actorId,
                        'notes' => "Assigned from Modern Trade {$row['channel']} outlet list for {$row['region']} using rep code {$row['rep_code']}",
                        'source' => 'modern_trade_csv',
                        'priority' => 20,
                        'is_manual_override' => false,
                    ],
                );
            }

            User::query()->whereIn('id', array_keys($reps))->update([
                'reports_to_user_id' => $purity->id,
                'updated_at' => $now,
            ]);
            $purity->forceFill(['reports_to_user_id' => $vignesh->id])->save();
        });

        foreach (array_unique(array_merge(array_keys($reps), [$purity->id, $vignesh->id])) as $userId) {
            Cache::forget('attribution:direct:'.$userId.':'.now()->toDateString());
        }
        app(DomainCache::class)->bump(
            DomainCache::CUSTOMER_ANALYTICS,
            DomainCache::SALES_PORTFOLIO,
            DomainCache::SALES_INTELLIGENCE,
        );

        $this->info('Modern Trade outlet mapping and reporting hierarchy applied successfully.');

        return CommandAlias::SUCCESS;
    }

    private function resolveFile(): string
    {
        $file = (string) ($this->argument('file') ?: base_path('../mt-outlets/MT_Outlets_with_Channel(1).csv'));
        $path = realpath($file);
        if ($path === false || ! is_file($path)) {
            throw new RuntimeException("CSV file not found: {$file}");
        }

        return $path;
    }

    /** @return list<array{row:int, customer_id:string, channel:string, rep_code:string}> */
    private function readRows(string $path): array
    {
        $csv = new SplFileObject($path, 'r');
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $header = $csv->fgetcsv();
        $keys = array_map(fn ($value) => strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $value)), $header ?: []);
        $columns = array_flip($keys);
        foreach (['customerid', 'channel', 'repcode', 'region'] as $required) {
            if (! array_key_exists($required, $columns)) {
                throw new RuntimeException("CSV is missing required column: {$required}.");
            }
        }

        $rows = [];
        $rowNumber = 1;
        while (! $csv->eof()) {
            $values = $csv->fgetcsv();
            $rowNumber++;
            if (! is_array($values) || $values === [null]) {
                continue;
            }
            $customerId = strtoupper(trim((string) ($values[$columns['customerid']] ?? '')));
            $repCode = strtoupper(trim((string) ($values[$columns['repcode']] ?? '')));
            $rawChannel = strtoupper(trim((string) ($values[$columns['channel']] ?? '')));
            $region = trim((string) ($values[$columns['region']] ?? ''));
            $channel = match ($rawChannel) {
                'MT1', 'MODERN TRADE 1', 'MODERN TRADE T1' => 'MT1',
                'MT2', 'MODERN TRADE 2', 'MODERN TRADE T2' => 'MT2',
                default => null,
            };
            if ($customerId === '' && $repCode === '' && $rawChannel === '') {
                continue;
            }
            if ($customerId === '' || $repCode === '' || $channel === null || $region === '') {
                throw new RuntimeException("Invalid Customer ID, Channel, RepCode, or Region on CSV row {$rowNumber}.");
            }
            $rows[] = compact('customerId', 'repCode', 'channel', 'region') + ['row' => $rowNumber];
        }
        if ($rows === []) {
            throw new RuntimeException('CSV contains no Modern Trade outlet rows.');
        }

        return $rows;
    }

    private function validateImport(array $rows, CustomerAttributionService $attribution, OrgTreeService $orgTree): array
    {
        $purity = $this->activeUserByEmail((string) $this->option('purity'), 'Purity');
        $vignesh = $this->activeUserByEmail((string) $this->option('vignesh'), 'Vignesh');
        $orgTree->assertValidReportsTo($purity->id, $vignesh->id);

        $resolved = [];
        $reps = [];
        $seen = [];
        $errors = [];
        foreach ($rows as $row) {
            $customerId = $row['customerId'];
            if (isset($seen[$customerId]) && $seen[$customerId] !== [$row['channel'], $row['repCode'], $row['region']]) {
                $errors[] = "row {$row['row']}: {$customerId} has conflicting channel, rep, or region values";
                continue;
            }
            $seen[$customerId] = [$row['channel'], $row['repCode'], $row['region']];
            if (! AcumaticaCustomer::query()->where('acumatica_id', $customerId)->exists()) {
                $errors[] = "row {$row['row']}: customer {$customerId} is absent from the Acumatica master";
                continue;
            }
            $identity = $attribution->resolveIdentity($row['repCode']);
            if (! $identity->resolved()) {
                $errors[] = "row {$row['row']}: rep code {$row['repCode']} is {$identity->status}";
                continue;
            }
            $rep = $identity->user;
            try {
                $orgTree->assertValidReportsTo($rep->id, $purity->id);
            } catch (\Throwable $e) {
                $errors[] = "row {$row['row']}: {$e->getMessage()}";
                continue;
            }
            $reps[$rep->id] = $rep->name;
            $resolved[$customerId] = [
                'customer_id' => $customerId,
                'channel' => $row['channel'],
                'rep_code' => $row['repCode'],
                'user_id' => $rep->id,
                'region' => $row['region'],
            ];
        }
        if ($errors !== []) {
            throw new RuntimeException("Import validation failed; no changes were written:\n - ".implode("\n - ", $errors));
        }

        return [$purity, $vignesh, array_values($resolved), $reps];
    }

    private function activeUserByEmail(string $email, string $label): User
    {
        $users = User::query()->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->where('is_active', true)->get();
        if ($users->count() !== 1) {
            throw new RuntimeException("{$label} must resolve to exactly one active user by email ({$email}).");
        }

        return $users->first();
    }

    private function resolveActorId(): ?int
    {
        $actor = $this->option('actor');
        if ($actor !== null && $actor !== '') {
            if (! User::query()->whereKey((int) $actor)->exists()) {
                throw new RuntimeException("Actor user {$actor} does not exist.");
            }

            return (int) $actor;
        }

        return User::query()->where('is_active', true)->where('is_super_admin', true)->orderBy('id')->value('id');
    }
}
