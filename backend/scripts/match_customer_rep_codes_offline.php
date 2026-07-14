<?php

/**
 * Offline matcher: Excel + TSV dumps of users/mappings (no live DB required).
 *
 * Usage:
 *   php scripts/match_customer_rep_codes_offline.php
 *
 * Expects:
 *   ../Customers 20260713.xlsx
 *   ../changes/users_dump.tsv        (id\tname\temail\trep_code\tis_active)
 *   ../changes/mappings_dump.tsv     (user_id\tacumatica_rep_code)
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$root = dirname(__DIR__, 2);
$excelPath = $root.'/Customers 20260713.xlsx';
$usersPath = $root.'/changes/users_dump.tsv';
$mappingsPath = $root.'/changes/mappings_dump.tsv';
$outDir = $root.'/changes';

if (! is_file($excelPath) || ! is_file($usersPath)) {
    fwrite(STDERR, "Missing excel or users dump.\n");
    exit(1);
}

function normalizePersonName(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace("/[^A-Z0-9]+/", ' ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    return trim($value);
}

/** @var array<int, array{id:int,name:string,email:string,rep_code:string,is_active:bool}> $usersById */
$usersById = [];
foreach (file($usersPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $parts = preg_split("/\t/", $line) ?: [];
    if (count($parts) < 5) {
        continue;
    }
    $id = (int) $parts[0];
    $usersById[$id] = [
        'id' => $id,
        'name' => $parts[1],
        'email' => $parts[2],
        'rep_code' => strtoupper(trim($parts[3])),
        'is_active' => in_array($parts[4], ['1', 'true', 'TRUE'], true),
    ];
}

/** @var array<string, list<array>> $repToUsers */
$repToUsers = [];
/** @var array<string, list<array>> $inactiveReps */
$inactiveReps = [];

foreach ($usersById as $user) {
    if ($user['rep_code'] === '') {
        continue;
    }
    if ($user['is_active']) {
        $repToUsers[$user['rep_code']][] = $user;
    } else {
        $inactiveReps[$user['rep_code']][] = $user;
    }
}

if (is_file($mappingsPath)) {
    foreach (file($mappingsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = preg_split("/\t/", $line) ?: [];
        if (count($parts) < 2) {
            continue;
        }
        $userId = (int) $parts[0];
        $code = strtoupper(trim($parts[1]));
        if ($code === '' || ! isset($usersById[$userId]) || ! $usersById[$userId]['is_active']) {
            continue;
        }
        $repToUsers[$code][] = $usersById[$userId];
    }
}

// Dedupe by user id per code.
foreach ($repToUsers as $code => $list) {
    $unique = [];
    foreach ($list as $u) {
        $unique[$u['id']] = $u;
    }
    $repToUsers[$code] = array_values($unique);
}

$activeUsers = array_values(array_filter($usersById, fn ($u) => $u['is_active']));

$reader = IOFactory::createReaderForFile($excelPath);
$reader->setReadDataOnly(true);
$spreadsheet = $reader->load($excelPath);
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
    fwrite(STDERR, "Missing columns. Found:\n");
    print_r($colMap);
    exit(1);
}

$byRep = [];
$matchRows = 0;
$unresolvedRows = 0;
$ambiguousRows = 0;
$inactiveRows = 0;
$missingRepRows = 0;

foreach ($rawRows as $i => $row) {
    $rowNo = $i + 2;
    $rep = strtoupper(trim((string) ($row[$repCol] ?? '')));
    $cust = strtoupper(trim((string) ($row[$custCol] ?? '')));
    $name = trim((string) ($row[$nameCol] ?? ''));

    if ($cust === '' && $rep === '') {
        continue;
    }

    if ($rep === '') {
        $missingRepRows++;
        continue;
    }

    if (! isset($byRep[$rep])) {
        $byRep[$rep] = [
            'status' => 'unresolved',
            'count' => 0,
            'user' => '',
            'suggested' => '',
            'examples' => [],
        ];
    }
    $byRep[$rep]['count']++;
    if (count($byRep[$rep]['examples']) < 3) {
        $byRep[$rep]['examples'][] = "row {$rowNo} {$cust} {$name}";
    }

    $users = $repToUsers[$rep] ?? [];
    if (count($users) === 1) {
        $matchRows++;
        $byRep[$rep]['status'] = 'resolved';
        $u = $users[0];
        $byRep[$rep]['user'] = sprintf('%s (#%d) rep_code=%s', $u['name'], $u['id'], $u['rep_code'] ?: '(mapped)');
        continue;
    }
    if (count($users) > 1) {
        $ambiguousRows++;
        $byRep[$rep]['status'] = 'ambiguous';
        $byRep[$rep]['user'] = implode('; ', array_map(
            fn ($u) => sprintf('%s (#%d) rep_code=%s', $u['name'], $u['id'], $u['rep_code'] ?: '(mapped)'),
            $users,
        ));
        continue;
    }
    if (isset($inactiveReps[$rep])) {
        $inactiveRows++;
        $byRep[$rep]['status'] = 'inactive';
    } else {
        $unresolvedRows++;
        $byRep[$rep]['status'] = 'unresolved';
    }
}

// Name suggestions for problem codes.
foreach ($byRep as $code => &$info) {
    if (in_array($info['status'], ['resolved', 'ambiguous'], true)) {
        continue;
    }
    $norm = normalizePersonName($code);
    if ($norm === '') {
        continue;
    }
    $suggestions = [];
    $excelTokens = array_values(array_filter(explode(' ', $norm), fn ($t) => strlen($t) > 2));

    foreach ($activeUsers as $user) {
        $userNorm = normalizePersonName($user['name']);
        if ($userNorm === '') {
            continue;
        }
        if ($userNorm === $norm) {
            $suggestions[$user['id']] = $user;
            continue;
        }
        $userTokens = array_values(array_filter(explode(' ', $userNorm), fn ($t) => strlen($t) > 2));
        if ($excelTokens === [] || $userTokens === []) {
            continue;
        }
        $shared = array_intersect($excelTokens, $userTokens);
        $excelInUser = count(array_diff($excelTokens, $userTokens)) === 0;
        $userInExcel = count(array_diff($userTokens, $excelTokens)) === 0;
        if ($excelInUser || $userInExcel || count($shared) >= 2) {
            $suggestions[$user['id']] = $user;
        }
    }

    if ($suggestions !== []) {
        $info['suggested'] = implode('; ', array_map(
            fn ($u) => sprintf(
                '%s (#%d) rep_code=%s email=%s',
                $u['name'],
                $u['id'],
                $u['rep_code'] !== '' ? $u['rep_code'] : '(none)',
                $u['email'],
            ),
            array_values($suggestions),
        ));
    }
}
unset($info);

ksort($byRep);

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
if ($missingRepRows > 0) {
    fputcsv($fp, ['missing_rep_code', '', 'missing_rep_code', $missingRepRows, '', '', 'rows with empty Rep Code']);
}
fclose($fp);

$summaryPath = $outDir.'/customer-assignment-rep-code-match-summary-20260713.csv';
$fp = fopen($summaryPath, 'w');
fputcsv($fp, ['rep_code', 'status', 'row_count', 'matched_user', 'suggested_user_match']);
foreach ($byRep as $code => $info) {
    fputcsv($fp, [$code, $info['status'], $info['count'], $info['user'], $info['suggested']]);
}
fclose($fp);

$activePath = $outDir.'/orderwatch-active-rep-codes-20260713.csv';
$fp = fopen($activePath, 'w');
fputcsv($fp, ['rep_code', 'user_id', 'user_name', 'email']);
$activeWithRep = array_filter($activeUsers, fn ($u) => $u['rep_code'] !== '');
usort($activeWithRep, fn ($a, $b) => strcmp($a['rep_code'], $b['rep_code']));
foreach ($activeWithRep as $u) {
    fputcsv($fp, [$u['rep_code'], $u['id'], $u['name'], $u['email']]);
}
fclose($fp);

$problem = array_filter($byRep, fn ($i) => $i['status'] !== 'resolved');
$resolved = array_filter($byRep, fn ($i) => $i['status'] === 'resolved');
$withSuggestion = array_filter($problem, fn ($i) => $i['suggested'] !== '');

echo "Rows matched: {$matchRows}\n";
echo "Rows unresolved: {$unresolvedRows}\n";
echo "Rows inactive: {$inactiveRows}\n";
echo "Rows ambiguous: {$ambiguousRows}\n";
echo "Rows missing rep: {$missingRepRows}\n";
echo 'Distinct Excel rep codes: '.count($byRep)."\n";
echo 'Resolved codes: '.count($resolved)."\n";
echo 'Problem codes: '.count($problem)."\n";
echo 'Problem with name suggestion: '.count($withSuggestion)."\n";
echo 'Active users with rep_code: '.count($activeWithRep)."\n";
echo "Wrote {$gapsPath}\n{$summaryPath}\n{$activePath}\n";

echo "\n=== RESOLVED ===\n";
foreach ($resolved as $code => $info) {
    echo sprintf("  %-28s %5d  %s\n", $code, $info['count'], $info['user']);
}
echo "\n=== PROBLEM (missing for assignment upload) ===\n";
foreach ($problem as $code => $info) {
    echo sprintf(
        "  %-28s %-12s %5d  suggest: %s\n",
        $code,
        $info['status'],
        $info['count'],
        $info['suggested'] !== '' ? $info['suggested'] : '(none)',
    );
}
