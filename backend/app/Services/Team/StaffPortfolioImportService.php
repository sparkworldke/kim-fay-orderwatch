<?php

namespace App\Services\Team;

use App\Models\StaffImportGap;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class StaffPortfolioImportService
{
    // Magunas is deliberately absent here — its Thika-vs-rest split is handled explicitly
    // in ownerForOutlet() since "Nairobi" appears in the Region column for both the Thika
    // exception and the genuine Nairobi branches alike, so Region can't disambiguate them.
    private const MAIN_ACCOUNT_RULES = [
        'quick mart' => 'Georgina Kiilu',
        'naivas' => 'Lucy Wanjiru',
        'majid al futtaim' => 'Jane Kuria',
        'khetia' => 'Lawrence Amukhono', // real account name is "Khetia Drapers Ltd" (singular)
        'chandarana' => 'Kevin Werunga',
        'china village' => 'Kevin Werunga',
        'onn the way' => 'Kevin Werunga', // real account name is "Onn The Way Ltd" (double n)
        'kassmatt' => 'Dennis Mutwiri', // real account name is "Kassmatt Supermarket Ltd"
        'leestar' => 'Dennis Mutwiri',
        'jaza' => 'Dennis Mutwiri',
        'eastleigh' => 'Dennis Mutwiri',
        'kamindi' => 'Dennis Mutwiri',
        'kikuyu selfridges' => 'Dennis Mutwiri',
    ];

    private const REGION_RULES = [
        'coast' => 'Beryl Muga',
        'nyanza' => 'George Amenya',
        'mountain' => 'Lilian Kimeu',
        'rift' => 'Lawrence Amukhono',
    ];

    public function __construct(private readonly CustomerAssignmentService $assignments) {}

    public function preview(
        string $staffPath,
        string $outletsPath,
        string $customersPath,
        ?int $actorId = null,
    ) {
        $staffNames = $this->staffDirectory($staffPath);
        $outlets = $this->sheetRows($outletsPath, 'Outlets');
        $rollup = $this->sheetRows($outletsPath, 'Main Account By Rep');
        $customers = $this->sheetRows($customersPath, 'Data');

        // Include the rule-constant owner names (e.g. Beryl Muga / George Amenya), not just
        // the sheets' literal values — a region-wide rule owner may never appear literally
        // as a Sales Rep value in any row (the whole point of "they have no main accounts
        // attached"), so without this, resolveNames() would never even attempt them.
        $distinctNames = collect($outlets)->pluck('sales_rep')
            ->merge(collect($rollup)->pluck('rep'))
            ->merge(array_values(self::MAIN_ACCOUNT_RULES))
            ->merge(array_values(self::REGION_RULES))
            ->merge(['Zipporah Wangeci'])
            ->filter()->unique()->values()->all();
        $nameUsers = $this->resolveNames($distinctNames, $staffNames);

        $resolved = [];
        foreach ($customers as $row) {
            $customerId = $this->value($row, 'customer_id');
            $repCode = $this->value($row, 'rep_code');
            if ($customerId === '' || $repCode === '') {
                continue;
            }
            $user = $this->userByRepCode($repCode);
            if ($user) {
                $resolved[$customerId] = $this->assignment($row, $user, 'customers_export_2026_07_13');
            }
        }

        $zipporahAccounts = collect($rollup)
            ->filter(fn ($row) => $this->canonical($this->value($row, 'rep')) === $this->canonical('Zipporah Wangeci'))
            ->map(fn ($row) => $this->canonical($this->value($row, 'main_account_name')))
            ->filter()->values()->all();

        foreach ($outlets as $row) {
            $customerId = $this->value($row, 'customer_id');
            if ($customerId === '') {
                continue;
            }
            [$repName, $source] = $this->ownerForOutlet($row, $zipporahAccounts);
            $user = $nameUsers[$this->canonical($repName)] ?? null;
            if (! $user) {
                // Every name ownerForOutlet() can return was already attempted (with a real
                // match score) in the resolveNames() pass above via the expanded
                // $distinctNames list — no need to re-record here with a worse payload.
                continue;
            }
            $assignment = $this->assignment($row, $user, $source);
            if (isset($resolved[$customerId]) && $resolved[$customerId]['user_id'] !== $user->id) {
                $assignment['details']['superseded'] = [
                    'user_id' => $resolved[$customerId]['user_id'],
                    'source' => $resolved[$customerId]['source'],
                ];
            }
            $resolved[$customerId] = $assignment;
        }

        return $this->assignments->previewResolvedRows(
            array_values($resolved),
            $actorId,
            basename($outletsPath).' + '.basename($customersPath),
        );
    }

    /** @return array{0:string,1:string} */
    private function ownerForOutlet(array $row, array $zipporahAccounts): array
    {
        $account = $this->canonical($this->value($row, 'main_account_name'));
        $region = $this->canonical($this->value($row, 'region'));
        // "Thika" is not a Region value in this dataset (Thika branches are tagged with the
        // same Region as every other Nairobi-area branch of the same account) — the only
        // reliable signal is the branch/customer name itself, e.g. "... - Thika Town".
        $name = $this->canonical($this->value($row, 'customer_name'));
        $literal = $this->value($row, 'sales_rep');
        $isThikaBranch = str_contains($name, 'thika') || str_contains($region, 'thika');

        if (str_contains($account, 'naivas') && $isThikaBranch) {
            return ['Lilian Kimeu', 'mt_outlets_carveout_thika'];
        }
        if (str_contains($account, 'magunas') && $isThikaBranch) {
            // Same carve-out, different account: Lilian's Magunas exception is also Thika-specific.
            return ['Lilian Kimeu', 'mt_outlets_carveout_thika'];
        }
        if (str_contains($account, 'magunas')) {
            // Every other Magunas branch is Dennis's per the business rule ("all Magunas in
            // Nairobi") — Region can't disambiguate (see above), so anything not caught by
            // the Thika carve-out above is, by elimination, the Nairobi case.
            return ['Dennis Mutwiri', 'mt_outlets_carveout_magunas_nairobi'];
        }
        if ((str_contains($account, 'powerstar') || str_contains($account, 'cleanshelf'))
            && $this->canonical($literal) === $this->canonical('Dennis Mutwiri')) {
            return [$literal, 'mt_outlets_literal_powerstar_cleanshelf'];
        }
        foreach (self::MAIN_ACCOUNT_RULES as $needle => $owner) {
            if (str_contains($account, $needle)) {
                return [$owner, 'mt_outlets_main_account_'.Str::slug($needle, '_')];
            }
        }
        if (in_array($account, $zipporahAccounts, true)) {
            return ['Zipporah Wangeci', 'mt_outlets_main_account_zipporah'];
        }
        foreach (self::REGION_RULES as $needle => $owner) {
            if (str_contains($region, $needle)) {
                return [$owner, 'mt_outlets_region_'.$needle];
            }
        }
        return [$literal, 'mt_outlets_literal'];
    }

    private function assignment(array $row, User $user, string $source): array
    {
        $repCode = strtoupper(trim((string) ($user->rep_code ?: $user->employee_number)));
        if ($repCode === '') {
            throw ValidationException::withMessages(['rep' => ["{$user->name} has no usable rep code."]]);
        }
        return [
            'customer_id' => $this->value($row, 'customer_id'),
            'customer_name' => $this->value($row, 'customer_name'),
            'user_id' => $user->id,
            'rep_code' => $repCode,
            'source' => $source,
            'details' => ['raw' => $row],
        ];
    }

    private function userByRepCode(string $code): ?User
    {
        $code = strtoupper(trim($code));
        $ids = User::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereRaw('UPPER(TRIM(rep_code)) = ?', [$code])
                ->orWhereRaw('UPPER(TRIM(employee_number)) = ?', [$code])
                ->orWhereHas('acumaticaRepMappings', fn ($m) => $m->whereRaw('UPPER(TRIM(acumatica_rep_code)) = ?', [$code])))
            ->pluck('id')->unique();
        return $ids->count() === 1 ? User::find($ids->first()) : null;
    }

    private function staffDirectory(string $path): array
    {
        $directory = [];
        foreach ($this->sheetRows($path, 'Matched Staff') as $row) {
            $name = $this->value($row, 'employee_name');
            $email = strtolower($this->value($row, 'email_address'));
            if ($name !== '' && $email !== '') {
                $directory[$this->canonical($name)] = $email;
            }
        }
        return $directory;
    }

    /** @return array<string,User> */
    private function resolveNames(array $names, array $staffDirectory): array
    {
        $resolved = [];
        foreach ($names as $name) {
            $canonical = $this->canonical($name);
            $bestKey = null;
            $bestScore = 0.0;
            foreach (array_keys($staffDirectory) as $candidate) {
                $score = $this->nameSimilarity($canonical, $candidate);
                if ($score > $bestScore) {
                    [$bestKey, $bestScore] = [$candidate, $score];
                }
            }
            if ($bestKey !== null && ($bestKey === $canonical || $bestScore >= .90)) {
                $user = User::query()->whereRaw('LOWER(email) = ?', [$staffDirectory[$bestKey]])->first();
                if ($user) {
                    $resolved[$canonical] = $user;
                    continue;
                }
            }
            $this->recordUnresolvedName((string) $name, ['match_score' => $bestScore]);
        }
        return $resolved;
    }

    /**
     * The Outlets/Main-Account-By-Rep sheets consistently use a short display name
     * ("George Amenya") while the HR staff sheet carries the full legal name ("George
     * Amenya Moranga"). similar_text()'s raw percentage score structurally can't clear a
     * 90% bar in that situation (extra tokens on one side always dilute it) even though
     * it's unambiguously the same person — so a full token-subset match (every word in the
     * shorter name appears in the longer one) is treated as high-confidence on its own,
     * mirroring the token-subset shortcut already used by agent-tools/match_staff_emails.py
     * for this exact problem.
     */
    private function nameSimilarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        $tokensA = array_values(array_filter(explode(' ', $a)));
        $tokensB = array_values(array_filter(explode(' ', $b)));
        [$shorter, $longer] = count($tokensA) <= count($tokensB) ? [$tokensA, $tokensB] : [$tokensB, $tokensA];

        if ($shorter !== [] && array_diff($shorter, $longer) === []) {
            return 0.95;
        }

        similar_text($a, $b, $percent);

        return $percent / 100;
    }

    private function recordUnresolvedName(string $name, array $payload): void
    {
        if (trim($name) === '') {
            return;
        }
        StaffImportGap::query()->updateOrCreate(
            ['display_name' => $name, 'gap_reason' => 'no_staff_match', 'resolution_status' => 'open'],
            ['match_score' => $payload['match_score'] ?? null, 'source_payload' => $payload],
        );
    }

    /** @return list<array<string,string>> */
    private function sheetRows(string $path, string $sheetName): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName($sheetName);
        if (! $sheet) {
            // Excel exports sometimes carry a stray leading/trailing space in the tab name
            // (e.g. "Outlets " instead of "Outlets") — match on the trimmed name instead
            // of failing outright.
            foreach ($spreadsheet->getSheetNames() as $actualName) {
                if (trim($actualName) === trim($sheetName)) {
                    $sheet = $spreadsheet->getSheetByName($actualName);
                    break;
                }
            }
        }
        if (! $sheet) {
            throw new \InvalidArgumentException("Sheet {$sheetName} not found in {$path}.");
        }
        $raw = $sheet->toArray(null, true, true, false);

        // Some exports (e.g. "Matched Staff") lead with a title/subtitle row before the
        // real header row — a real header row has more than one populated cell.
        while ($raw !== [] && count(array_filter(reset($raw), static fn ($v) => trim((string) $v) !== '')) <= 1) {
            array_shift($raw);
        }

        $headers = array_map(fn ($v) => $this->header((string) $v), array_shift($raw) ?: []);
        $rows = [];
        foreach ($raw as $values) {
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $row[$header] = trim((string) ($values[$index] ?? ''));
                }
            }
            if (array_filter($row, fn ($v) => $v !== '') !== []) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private function header(string $value): string
    {
        return trim(preg_replace('/_+/', '_', strtolower(preg_replace('/[^a-z0-9]+/i', '_', $value))), '_');
    }

    private function value(array $row, string $key): string
    {
        return trim((string) ($row[$key] ?? ''));
    }

    private function canonical(string $value): string
    {
        // Strip apostrophes before tokenizing so a possessive ("Maguna's") joins into one
        // word ("magunas") instead of splitting into two ("maguna s") and silently breaking
        // every substring match against it.
        $value = str_replace("'", '', $value);

        return trim(preg_replace('/\s+/', ' ', strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $value))));
    }
}
