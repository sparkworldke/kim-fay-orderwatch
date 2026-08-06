<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Dtc\DtcQuoteImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportDtcQuotes extends Command
{
    // date option kept for CLI; use --from/--to for ranges when needed.

    protected $signature = 'orderwatch:import-dtc-quotes
        {--date= : Import quotes for one date (YYYY-MM-DD)}
        {--all : Import all available quotes}
        {--user= : User ID recorded as the importer; defaults to an active administrator}';

    protected $description = 'Import CUST101470 Acumatica QT documents into the DTC/DTB Calltronix module';

    public function handle(DtcQuoteImportService $service): int
    {
        $date = $this->option('date');
        $all = (bool) $this->option('all');

        if (($date && $all) || (! $date && ! $all)) {
            $this->error('Choose exactly one scope: --date=YYYY-MM-DD or --all.');
            return self::INVALID;
        }

        if ($date && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            $this->error('--date must use YYYY-MM-DD format.');
            return self::INVALID;
        }

        $actor = $this->actor();
        if (! $actor) {
            $this->error('No importer user was found. Pass an existing user ID with --user=ID.');
            return self::FAILURE;
        }

        try {
            $result = $service->import(
                $all ? null : (string) $date,
                $all ? null : (string) $date,
                $actor,
            );
            $this->info(sprintf(
                'Imported %d quotes for CUST101470: %d created, %d updated.',
                $result['fetched'],
                $result['created'],
                $result['updated'],
            ));
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('DTC quote import failed: '.$e->getMessage());
            return self::FAILURE;
        }
    }

    private function actor(): ?User
    {
        if ($id = $this->option('user')) {
            return User::find($id);
        }

        return User::query()
            ->where(function ($query) {
                $query->where('role', 'Administrator')->orWhere('is_super_admin', true);
            })
            ->orderByDesc('is_super_admin')
            ->orderBy('id')
            ->first();
    }
}
