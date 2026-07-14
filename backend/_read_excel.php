<?php

require __DIR__ . '/vendor/autoload.php';

$filePath = __DIR__ . '/../Customers 20260713.xlsx';
$reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
$reader->setReadDataOnly(true);
$reader->setReadEmptyCells(false);
$spreadsheet = $reader->load($filePath);
$sheet = $spreadsheet->getActiveSheet();
$rawRows = $sheet->toArray(null, true, true, true);
$header = array_shift($rawRows);

echo "HEADERS:\n";
foreach ($header as $col => $label) {
    echo "  $col => $label\n";
}

// Locate key columns
$repCol = null; $custCol = null; $routeCol = null; $zoneCol = null; $routeNameCol = null; $custZoneCol = null;
foreach ($header as $col => $label) {
    $k = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string)$label));
    if ($k === 'repcode') $repCol = $col;
    if (in_array($k, ['customerid', 'customer', 'customercode', 'acumaticacustomerid'])) $custCol = $col;
    if (in_array($k, ['routecode', 'route'])) $routeCol = $col;
    if ($k === 'routename') $routeNameCol = $col;
    if (in_array($k, ['zoneid', 'zone'])) $zoneCol = $col;
    if ($k === 'customerzone') $custZoneCol = $col;
}

echo "\nKey columns: rep=$repCol cust=$custCol route=$routeCol routeName=$routeNameCol zone=$zoneCol custZone=$custZoneCol\n";
echo "Total data rows: " . count($rawRows) . "\n\n";

// Show distinct rep codes (first 30)
$repCodes = [];
foreach ($rawRows as $row) {
    $rep = trim((string)($row[$repCol] ?? ''));
    if ($rep !== '') $repCodes[$rep] = true;
}
echo "Distinct rep codes (" . count($repCodes) . "):\n";
foreach (array_keys($repCodes) as $code) {
    echo "  $code\n";
}

// Show first 8 data rows
echo "\nFirst 8 data rows:\n";
$count = 0;
foreach ($rawRows as $row) {
    if ($count >= 8) break;
    $rep = $row[$repCol] ?? '';
    $cust = $row[$custCol] ?? '';
    $route = $row[$routeCol] ?? '';
    $routeName = $row[$routeNameCol] ?? '';
    $zone = $row[$zoneCol] ?? '';
    $custZone = $row[$custZoneCol] ?? '';
    if (empty($rep) && empty($cust)) continue;
    echo "  rep=$rep | cust=$cust | route=$route ($routeName) | zone=$zone ($custZone)\n";
    $count++;
}
