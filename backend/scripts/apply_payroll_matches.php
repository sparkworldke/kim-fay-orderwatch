<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$path = dirname(__DIR__, 2) . '/payroll_email_matches.xlsx';
if (! is_file($path)) {
    fwrite(STDERR, "File not found: {$path}\n");
    exit(1);
}

$rows = [];
foreach (['Matched Payroll Emails', 'Review Needed'] as $sheetName) {
    foreach (readPayrollSheet($path, $sheetName) as $row) {
        $code = normalizeRepCode($row['payroll_number']);
        if ($code === null) {
            continue;
        }
        if (! isset($rows[$code]) || rowPriority($row) > rowPriority($rows[$code])) {
            $rows[$code] = $row;
        }
    }
}

$updated = 0;
$nameOnly = 0;
$unchanged = 0;
$notFound = [];
$emailConflicts = [];

foreach ($rows as $code => $row) {
    $user = User::query()->where('rep_code', $code)->first();
    if ($user === null) {
        $notFound[] = $code;
        continue;
    }

    $newName = trim((string) $row['staff_name']);
    $newEmail = cleanEmail($row['email'] ?? null);
    $changes = [];

    if ($newName !== '' && $user->name !== $newName) {
        $changes['name'] = $newName;
    }

    if ($newEmail !== null) {
        $taken = User::query()
            ->where('email', $newEmail)
            ->where('id', '!=', $user->id)
            ->exists();

        if ($taken) {
            $emailConflicts[] = [
                'rep_code' => $code,
                'staff_name' => $newName,
                'email' => $newEmail,
            ];
        } elseif ($user->email !== $newEmail) {
            $changes['email'] = $newEmail;
        }
    } elseif (isset($changes['name'])) {
        $nameOnly++;
    }

    if ($changes === []) {
        $unchanged++;
        continue;
    }

    $user->update($changes);
    $updated++;

    $fields = array_keys($changes);
    echo sprintf(
        "Updated %s (%s): %s\n",
        $code,
        implode(', ', $fields),
        $newName !== '' ? $newName : $user->name
    );
}

echo "\n--- Summary ---\n";
echo 'Processed payroll rows: ' . count($rows) . "\n";
echo "Users updated: {$updated}\n";
echo "Name-only updates (no email in sheet): {$nameOnly}\n";
echo "Already up to date: {$unchanged}\n";
echo 'No matching user: ' . count($notFound) . "\n";
if ($notFound !== []) {
    echo '  ' . implode(', ', $notFound) . "\n";
}
echo 'Email conflicts: ' . count($emailConflicts) . "\n";
foreach ($emailConflicts as $conflict) {
    echo sprintf(
        "  %s (%s) — email %s already taken\n",
        $conflict['rep_code'],
        $conflict['staff_name'],
        $conflict['email']
    );
}

/**
 * @return list<array{payroll_number:string,staff_name:string,email:string,match_status:string}>
 */
function readPayrollSheet(string $path, string $sheetName): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException("Cannot open {$path}");
    }

    $sharedStrings = readSharedStrings($zip);
    $sheetPath = resolveSheetPath($zip, $sheetName);
    if ($sheetPath === null) {
        $zip->close();

        return [];
    }

    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();

    $sheet = simplexml_load_string($sheetXml);
    if ($sheet === false) {
        return [];
    }

    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $sheetData = $sheet->children($ns)->sheetData ?? null;
    if ($sheetData === null) {
        return [];
    }

    $grid = [];
    $rowNum = 0;
    foreach ($sheetData->children($ns) as $row) {
        if ($row->getName() !== 'row') {
            continue;
        }

        $rowNum++;
        $col = 0;
        foreach ($row->children($ns) as $cell) {
            if ($cell->getName() !== 'c') {
                continue;
            }

            $ref = (string) ($cell->attributes($ns)['r'] ?? $cell['r'] ?? '');
            if ($ref !== '' && preg_match('/^([A-Z]+)\d+$/', $ref, $matches)) {
                $col = columnIndex($matches[1]);
            } else {
                $col++;
            }

            $grid[$rowNum][$col] = cellValue($cell, $sharedStrings, $ns);
        }
    }

    $headerRow = null;
    foreach ($grid as $rowNum => $cols) {
        $values = array_map(static fn ($value) => trim((string) $value), $cols);
        if (in_array('Payroll Number', $values, true)) {
            $headerRow = $rowNum;
            break;
        }
    }

    if ($headerRow === null) {
        return [];
    }

    $headers = [];
    foreach ($grid[$headerRow] as $col => $value) {
        $headers[$col] = normalizeHeader((string) $value);
    }

    $rows = [];
    foreach ($grid as $rowNum => $cols) {
        if ($rowNum <= $headerRow) {
            continue;
        }

        $record = [
            'payroll_number' => '',
            'staff_name' => '',
            'email' => '',
            'match_status' => '',
        ];

        foreach ($cols as $col => $value) {
            $key = $headers[$col] ?? null;
            if ($key !== null && array_key_exists($key, $record)) {
                $record[$key] = trim((string) $value);
            }
        }

        if ($record['payroll_number'] === '') {
            continue;
        }

        $rows[] = $record;
    }

    return $rows;
}

function resolveSheetPath(ZipArchive $zip, string $sheetName): ?string
{
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml === false || $relsXml === false) {
        return null;
    }

    $workbook = simplexml_load_string($workbookXml);
    $rels = simplexml_load_string($relsXml);
    if ($workbook === false || $rels === false) {
        return null;
    }

    $workbook->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

    $relMap = [];
    foreach ($rels->Relationship as $rel) {
        $relMap[(string) $rel['Id']] = (string) $rel['Target'];
    }

    foreach ($workbook->xpath('//m:sheet') ?: [] as $sheet) {
        if ((string) ($sheet['name'] ?? '') !== $sheetName) {
            continue;
        }

        $relId = (string) ($sheet->attributes('r', true)['id'] ?? '');
        $target = ltrim(str_replace('../', '', (string) ($relMap[$relId] ?? '')), '/');
        if ($target === '') {
            return null;
        }

        return $target;
    }

    return null;
}

/** @return list<string> */
function readSharedStrings(ZipArchive $zip): array
{
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml === false) {
        return [];
    }

    $shared = simplexml_load_string($sharedXml);
    if ($shared === false) {
        return [];
    }

    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $strings = [];

    foreach ($shared->children($ns) as $si) {
        if ($si->getName() !== 'si') {
            continue;
        }

        $children = $si->children($ns);
        if (isset($children->t)) {
            $strings[] = (string) $children->t;
            continue;
        }

        $text = '';
        foreach ($children->r as $run) {
            $text .= (string) ($run->children($ns)->t ?? '');
        }
        $strings[] = $text;
    }

    return $strings;
}

function normalizeHeader(string $value): ?string
{
    return match (trim($value)) {
        'Payroll Number' => 'payroll_number',
        'Staff Name' => 'staff_name',
        'Email' => 'email',
        'Match Status' => 'match_status',
        default => null,
    };
}

function columnIndex(string $letters): int
{
    $index = 0;
    foreach (str_split($letters) as $letter) {
        $index = $index * 26 + (ord($letter) - 64);
    }

    return $index;
}

function cellValue(SimpleXMLElement $cell, array $sharedStrings, string $ns): string
{
    $type = (string) ($cell['t'] ?? '');
    $value = (string) ($cell->children($ns)->v ?? '');

    if ($type === 's') {
        return $sharedStrings[(int) $value] ?? '';
    }

    return $value;
}

function normalizeRepCode(?string $value): ?string
{
    $code = strtoupper(trim((string) $value));
    if ($code === '' || $code === 'NAN') {
        return null;
    }

    return $code;
}

function cleanEmail(?string $value): ?string
{
    $email = strtolower(trim((string) $value));
    if ($email === '' || $email === 'nan') {
        return null;
    }

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

/** @param array{match_status?:string} $row */
function rowPriority(array $row): int
{
    $status = strtolower(trim((string) ($row['match_status'] ?? '')));
    if ($status === 'high') {
        return 3;
    }
    if ($status === 'no reliable match') {
        return 1;
    }

    return 2;
}