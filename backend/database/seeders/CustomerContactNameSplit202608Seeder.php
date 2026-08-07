<?php

namespace Database\Seeders;

use App\Models\CustomerContact;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Default company contacts (created by the KP customer portfolio import) were
 * seeded with the full company name in first_name and an empty last_name
 * (see KpCustomerPortfolio202608Seeder). This splits that single string on
 * its last whitespace-separated word into first_name/last_name, so existing
 * displayName() output ("{$first_name} {$last_name}") is unchanged.
 *
 * Only touches contacts with an empty/null last_name and at least two words
 * in first_name — real person contacts with a last_name already set are
 * left untouched.
 */
class CustomerContactNameSplit202608Seeder extends Seeder
{
    public function run(): void
    {
        $candidates = CustomerContact::query()
            ->where(function ($query) {
                $query->whereNull('last_name')->orWhere('last_name', '');
            })
            ->whereNotNull('first_name')
            ->where('first_name', '!=', '')
            ->get();

        $updated = 0;
        $skippedSingleWord = 0;

        DB::transaction(function () use ($candidates, &$updated, &$skippedSingleWord): void {
            foreach ($candidates as $contact) {
                $words = preg_split('/\s+/', trim((string) $contact->first_name));
                if (count($words) < 2) {
                    $skippedSingleWord++;

                    continue;
                }

                $lastName = array_pop($words);
                $firstName = implode(' ', $words);

                $contact->forceFill([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                ])->save();
                $updated++;
            }
        });

        $this->command?->info("Customer contact name split: {$updated} contact(s) updated.");
        if ($skippedSingleWord > 0) {
            $this->command?->warn("{$skippedSingleWord} contact(s) skipped — first_name was a single word, nothing to split.");
        }
    }
}
