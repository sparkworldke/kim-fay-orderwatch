<?php

/**
 * Match Customers 20260713.xlsx rep codes against active OrderWatch users.
 *
 * Resolution order (same as CustomerAssignmentService):
 *   1) UPPER(TRIM(users.rep_code)) for active users
 *   2) UPPER(TRIM(user_acumatica_rep_mappings.acumatica_rep_code)) for active users
 *
 * Also emits suggested name-based matches for unresolved Excel rep codes
 * (Excel often stores person names in the Rep Code column).
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\UserAcumaticaRepMapping;
use PhpOffice\PhpSpreadsheet\IOFactory;

$filePath = dirname(__DIR__, 2) . '/Customers 20260713.xlsx';
if (! is_file($filePath)) {
    fwrite(STDERR, "Excel not found: {$filePath}\n");
    exit(1);
}

$reader = IOFactory::createReaderForFile($filePath);
$reader->setReadDataOnly(true);
$spreadsheet = $reader->load($filePath);
$sheet = $spreadsheet->getActiveSheet();
$rawRows = $sheet->toArray(null, true, true, true);
$header = array_shift($rawRows);

$colMap = [];
foreach ($header as $col => $label) {
    $k = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $label));
    $colMap[$k] = $col;
}

$repCol = $colMap['repcode'] ?? null;
$custCol = $colMap['customerid'] ?? $colMap['customer'] ?? $colMap['customercode'] ?? null;
$nameCol = $colMap['customername'] ?? $colMap['name'] ?? null;

if ($repCol === null || $custCol === null) {
    fwrite(STDERR, "Could not locate Rep Code / Customer ID columns.\n");
    print_r($colMap);
    exit(1);
}

/**
 * Normalize a person name for fuzzy comparison.
 */
function normalizePersonName(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace("/[^A-Z0-9]+/", ' ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    return trim($value);
}

/** @var array<string, list<User>> $repToUser */
$repToUser = [];

$activeUsers = User::query()
    ->where('is_active', true)
    ->get(['id', 'name', 'email', 'rep_code']);

$activeWithRep = $activeUsers->filter(fn (User $u) => filled(trim((string) $u->rep_code)));

foreach ($activeWithRep as $user) {
    $code = strtoupper(trim((string) $user->rep_code));
    $repToUser[$code][] = $user;
}

$mappings = UserAcumaticaRepMapping::query()
    ->whereNotNull('acumatica_rep_code')
    ->get(['user_id', 'acumatica_rep_code']);

foreach ($mappings as $mapping) {
    $code = strtoupper(trim((string) $mapping->acumatica_rep_code));
    if ($code === '') {
        continue;
    }
    $user = $activeUsers->firstWhere('id', $mapping->user_id);
    if ($user) {
        $repToUser[$code][] = $user;
    }
}

foreach ($repToUser as $code => $users) {
    $unique = [];
    foreach ($users as $user) {
        $unique[$user->id] = $user;
    }
    $repToUser[$code] = array_values($unique);
}

// Name index for suggestions only (not used for official resolution).
/** @var array<string, list<User>> $nameIndex */
$nameIndex = [];
foreach ($activeUsers as $user) {
    $norm = normalizePersonName((string) $user->name);
    if ($norm === '') {
        continue;
    }
    $nameIndex[$norm][] = $user;
    // Also index last-token-first variants lightly: "BERNA PIWANG" vs "Berna Piwang"
    $parts = explode(' ', $norm);
    if (count($parts) >= 2) {
        $nameIndex[implode(' ', $parts)][] = $user;
    }
}

$byRep = [];
$matchCount = 0;
$unresolvedRowCount = 0;
$ambiguousRowCount = 0;
$inactiveRowCount = 0;
$missingRepCount = 0;

foreach ($rawRows as $i => $row) {
    $rowNo = $i + 2;
    $rep = strtoupper(trim((string) ($row[$repCol] ?? '')));
    $cust = strtoupper(trim((string) ($row[$custCol] ?? '')));
    $name = trim((string) ($row[$nameCol] ?? ''));

    if ($cust === '' && $rep === '') {
        continue;
    }

    if ($rep === '') {
        $missingRepCount++;
        continue;
    }

    if (! isset($byRep[$rep])) {
        $byRep[$rep] = [
            'status' => 'unresolved',
            'count' => 0,
            'user' => '',
            'examples' => [],
            'suggested' => '',
        ];
    }

    $byRep[$rep]['count']++;
    if (count($byRep[$rep]['examples']) < 3) {
        $byRep[$rep]['examples'][] = "row {$rowNo} {$cust} {$name}";
    }

    $users = $repToUser[$rep] ?? [];

    if (count($users) === 1) {
        $matchCount++;
        $byRep[$rep]['status'] = 'resolved';
        $byRep[$rep]['user'] = sprintf(
            '%s (#%d) rep_code=%s',
            $users[0]->name,
            $users[0]->id,
            strtoupper(trim((string) $users[0]->rep_code)),
        );
        continue;
    }

    if (count($users) > 1) {
        $ambiguousRowCount++;
        $byRep[$rep]['status'] = 'ambiguous';
        $byRep[$rep]['user'] = implode('; ', array_map(
            fn (User $u) => sprintf('%s (#%d) rep_code=%s', $u->name, $u->id, strtoupper(trim((string) $u->rep_code))),
            $users,
        ));
        continue;
    }

    $inactiveExists = User::query()
        ->whereRaw('UPPER(TRIM(rep_code)) = ?', [$rep])
        ->where('is_active', false)
        ->exists();

    if ($inactiveExists) {
        $inactiveRowCount++;
        $byRep[$rep]['status'] = 'inactive';
    } else {
        $unresolvedRowCount++;
        $byRep[$rep]['status'] = 'unresolved';
    }
    $byRep[$rep]['user'] = '';
}

// Suggestions for problem codes (exact normalized name, then partial contains).
foreach ($byRep as $code => &$info) {
    if ($info['status'] === 'resolved' || $info['status'] === 'ambiguous') {
        continue;
    }

    $norm = normalizePersonName($code);
    $suggestions = [];

    if (isset($nameIndex[$norm])) {
        foreach ($nameIndex[$norm] as $user) {
            $suggestions[$user->id] = $user;
        }
    }

    if ($suggestions === [] && $norm !== '') {
        foreach ($activeUsers as $user) {
            $userNorm = normalizePersonName((string) $user->name);
            if ($userNorm === '') {
                continue;
            }
            // All tokens from Excel name appear in user name (or vice versa).
            $excelTokens = array_values(array_filter(explode(' ', $norm), fn ($t) => strlen($t) > 2));
            $userTokens = array_values(array_filter(explode(' ', $userNorm), fn ($t) => strlen($t) > 2));
            if ($excelTokens === [] || $userTokens === []) {
                continue;
            }
            $excelInUser = count(array_diff($excelTokens, $userTokens)) === 0;
            $userInExcel = count(array_diff($userTokens, $excelTokens)) === 0;
            // At least 2 shared tokens, or first+last match.
            $shared = array_intersect($excelTokens, $userTokens);
            if ($excelInUser || $userInExcel || count($shared) >= 2) {
                $suggestions[$user->id] = $user;
            }
        }
    }

    if ($suggestions !== []) {
        $info['suggested'] = implode('; ', array_map(
            fn (User $u) => sprintf(
                '%s (#%d) rep_code=%s email=%s',
                $u->name,
                $u->id,
                strtoupper(trim((string) $u->rep_code)) ?: '(none)',
                $u->email,
            ),
            array_values($suggestions),
        ));
    }
}
unset($info);

ksort($byRep);

$outDir = dirname(__DIR__, 2).'/changes';
if (! is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

$gapsPath = $outDir.'/customer-assignment-rep-code-gaps-20260713.csv';
$fp = fopen($gapsPath, 'w');
fputcsv($fp, ['type', 'rep_code', 'status', 'row_count', 'matched_user', 'suggested_user_match', 'example_rows']);
foreach ($byRep as $code => $info) {
    if ($info['status'] === 'resolved') {
        continue;
    }
    fputcsv($fp, [
        'problem_rep_code',
        $code,
        $info['status'],
        $info['count'],
        $info['user'],
        $info['suggested'],
        implode(' | ', $info['examples']),
    ]);
}
if ($missingRepCount > 0) {
    fputcsv($fp, [
        'missing_rep_code',
        '',
        'missing_rep_code',
        $missingRepCount,
        '',
        '',
        'rows with empty Rep Code',
    ]);
}
fclose($fp);

$summaryPath = $outDir.'/customer-assignment-rep-code-match-summary-20260713.csv';
$fp = fopen($summaryPath, 'w');
fputcsv($fp, ['rep_code', 'status', 'row_count', 'matched_user', 'suggested_user_match']);
foreach ($byRep as $code => $info) {
    fputcsv($fp, [$code, $info['status'], $info['count'], $info['user'], $info['suggested'] ?? '']);
}
fclose($fp);

$activePath = $outDir.'/orderwatch-active-rep-codes-20260713.csv';
$fp = fopen($activePath, 'w');
fputcsv($fp, ['rep_code', 'user_id', 'user_name', 'email']);
foreach ($activeWithRep->sortBy(fn (User $u) => strtoupper(trim((string) $u->rep_code))) as $user) {
    fputcsv($fp, [
        strtoupper(trim((string) $user->rep_code)),
        $user->id,
        $user->name,
        $user->email,
    ]);
}
fclose($fp);

$problemCodes = array_filter($byRep, fn ($i) => $i['status'] !== 'resolved');
$resolvedCodes = array_filter($byRep, fn ($i) => $i['status'] === 'resolved');
$withSuggestion = array_filter($problemCodes, fn ($i) => ($i['suggested'] ?? '') !== '');

echo "Excel file: {$filePath}\n";
echo "Rows matched (resolved by rep_code/mapping): {$matchCount}\n";
echo "Rows unresolved: {$unresolvedRowCount}\n";
echo "Rows inactive-only: {$inactiveRowCount}\n";
echo "Rows ambiguous: {$ambiguousRowCount}\n";
echo "Rows missing rep code: {$missingRepCount}\n";
echo 'Distinct Excel rep codes: '.count($byRep)."\n";
echo 'Distinct resolved: '.count($resolvedCodes)."\n";
echo 'Distinct problem: '.count($problemCodes)."\n";
echo 'Problem codes with name suggestion: '.count($withSuggestion)."\n";
echo 'Active OW users with rep_code: '.$activeWithRep->count()."\n";
echo "Wrote:\n  {$gapsPath}\n  {$summaryPath}\n  {$activePath}\n";

echo "\n=== RESOLVED rep codes ===\n";
foreach ($resolvedCodes as $code => $info) {
    echo sprintf("  %-30s %5d  %s\n", $code, $info['count'], $info['user']);
}

echo "\n=== PROBLEM rep codes (missing / inactive / ambiguous) ===\n";
foreach ($problemCodes as $code => $info) {
    echo sprintf(
        "  %-30s %-12s %5d rows  suggest: %s\n",
        $code,
        $info['status'],
        $info['count'],
        $info['suggested'] !== '' ? $info['suggested'] : '(none)',
    );
}
