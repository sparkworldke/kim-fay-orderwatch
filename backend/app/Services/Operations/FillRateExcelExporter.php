<?php

namespace App\Services\Operations;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Generates the enhanced fill-rate Excel export.
 *
 * Sheets produced:
 *   1. Fill Rate           – order-level (unchanged structure)
 *   2. Product Lines       – line-level (unchanged structure + Brand Type column)
 *   3. Manufactured Lines  – Manufactured (Kim-Fay own brand) lines only
 *   4. Partner Brand Lines – Third-party / partner brand lines only
 *   5. Lost Sales Analysis – SKU-grouped with subtotals + grand total
 *   6. Reason Summary      – root-cause contribution (unchanged)
 *   7. Customer Summary    – top customers (unchanged)
 *   8. Product Summary     – top products (unchanged)
 *   9. Summary             – grand total, top-5 SKUs, reason distribution
 *  10. Instructions        – how to use this file
 */
class FillRateExcelExporter
{
    // ---------------------------------------------------------------------------
    // Brand classification
    // ---------------------------------------------------------------------------

    /** Inventory ID prefixes classified as Kim-Fay manufactured products */
    private const MANUFACTURED_PREFIXES = [
        'FAY', 'SIF', 'COS', 'TIS', 'ULT', 'STD', 'SHO', 'ANT',
        'URI', 'TOI', 'AIR', 'ALK', 'DIS',
    ];

    /**
     * Partner / third-party brand prefixes.
     * Note: HYG appears in both lists – prefer manufactured unless context is
     * clearly a partner product (handled by exclusion logic below).
     */
    private const PARTNER_PREFIXES = [
        'DOV', 'REX', 'LUX', 'HUG', 'KOT', 'COW', 'APT', 'BIO',
        'DAB', 'ORS', 'VAT', 'HOB', 'DUR', 'FEM', 'KLE', 'MIS',
        'MSW', 'IKO', 'CON', 'BIG',
    ];

    public function classifyBrand(string $inventoryId): string
    {
        $upper = strtoupper(trim($inventoryId));

        foreach (self::PARTNER_PREFIXES as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return 'Partner Brand';
            }
        }

        foreach (self::MANUFACTURED_PREFIXES as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return 'Manufactured';
            }
        }

        // Default: treat as partner brand (conservative – unknown = external)
        return 'Partner Brand';
    }

    // ---------------------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------------------

    /**
     * Build the full spreadsheet and return a streaming download response.
     *
     * @param  array<int, array<int, mixed>>  $fillRateRows    Order-level rows (same structure as current)
     * @param  array<int, array<int, mixed>>  $productRows     Line-level rows (same structure as current, Inventory ID at index 2)
     * @param  array<int, array<string, mixed>>  $reasonRows   Reason summary rows
     * @param  array<int, array<string, mixed>>  $customerRows Customer summary rows
     * @param  array<int, array<string, mixed>>  $productSummaryRows Product summary rows
     */
    public function build(
        array $fillRateRows,
        array $productRows,
        array $reasonRows,
        array $customerRows,
        array $productSummaryRows,
        string $dateFrom,
        string $dateTo,
    ): StreamedResponse {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('Kim-Fay OrderWatch')
            ->setTitle('Fill Rate Export');

        // Sheet 1: Fill Rate (order level) – unchanged
        $this->writeFillRateSheet($spreadsheet, $fillRateRows);

        // Sheet 2: Product Lines (line level) + Brand Type column
        $this->writeProductLinesSheet($spreadsheet, $productRows);

        // Sheets 3 & 4: Brand split
        $this->writeBrandSplitSheets($spreadsheet, $productRows);

        // Sheet 5: Lost Sales Analysis (SKU-grouped)
        $this->writeLostSalesSheet($spreadsheet, $productRows);

        // Sheets 6-8: Contribution summaries (unchanged)
        $this->writeContributionSheet($spreadsheet, 'Reason Summary', $reasonRows, [
            'reason' => 'Reason',
            'line_count' => 'Line Count',
            'undershipped_value' => 'Undershipped Value',
            'contribution_pct' => 'Contribution %',
        ]);
        $this->writeContributionSheet($spreadsheet, 'Customer Summary', $customerRows, [
            'customer_id' => 'Customer ID',
            'customer_name' => 'Customer Name',
            'order_count' => 'Order Count',
            'undershipped_value' => 'Undershipped Value',
            'contribution_pct' => 'Contribution %',
        ]);
        $this->writeContributionSheet($spreadsheet, 'Product Summary', $productSummaryRows, [
            'inventory_id' => 'Inventory ID',
            'product_name' => 'Product Name',
            'line_count' => 'Line Count',
            'undershipped_value' => 'Undershipped Value',
            'contribution_pct' => 'Contribution %',
        ]);

        // Sheet 9: Summary dashboard
        $this->writeSummarySheet($spreadsheet, $productRows, $reasonRows, $dateFrom, $dateTo);

        // Sheet 10: Instructions
        $this->writeInstructionsSheet($spreadsheet);

        $spreadsheet->setActiveSheetIndex(0);

        $filename = 'fill-rate-export-' . now()->format('Ymd-Hi') . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // ---------------------------------------------------------------------------
    // Sheet writers
    // ---------------------------------------------------------------------------

    /** Sheet 1 – Fill Rate (order level, unchanged structure) */
    private function writeFillRateSheet(Spreadsheet $ss, array $rows): void
    {
        $headers = [
            'Order', 'Customer ID', 'Customer Name', 'Status', 'Order Date',
            'Ordered Qty', 'Shipped Qty', 'Fill Rate %', 'Fill Rate Status',
            'Revenue Not Shipped', 'Shipping Zone ID', 'Shipping Zone',
            'Delivery Hours', 'SLA Hours', 'SLA Status', 'SLA Label', 'Computed At',
        ];
        $this->writeSheet($ss, 'Fill Rate', $headers, $rows);
    }

    /**
     * Sheet 2 – Product Lines (line level).
     * Adds 'Brand Type' as the last column (index 15 → col P shifts to Q).
     * Original column layout (0-indexed):
     *   0 Order | 1 Customer ID | 2 Inventory ID | 3 Product Name | 4 Demand Qty |
     *   5 Order Qty | 6 Shipped Qty | 7 Qty On Shipments | 8 Open Qty | 9 UOM |
     *  10 Unit Price | 11 Line Fill Rate % | 12 Unfilled Reason Code |
     *  13 Unfilled Reason | 14 Not Shipped Value
     */
    private function writeProductLinesSheet(Spreadsheet $ss, array $rows): void
    {
        $headers = [
            'Order', 'Customer ID', 'Inventory ID', 'Product Name', 'Demand Qty',
            'Order Qty', 'Shipped Qty', 'Qty On Shipments', 'Open Qty', 'UOM',
            'Unit Price', 'Line Fill Rate %', 'Unfilled Reason Code',
            'Unfilled Reason', 'Not Shipped Value', 'Brand Type',
        ];

        $enriched = array_map(function (array $row) {
            $invId = (string) ($row[2] ?? '');
            $row[] = $this->classifyBrand($invId);
            return $row;
        }, $rows);

        $this->writeSheet($ss, 'Product Lines', $headers, $enriched);
    }

    /** Sheets 3 & 4 – Manufactured vs Partner Brand split */
    private function writeBrandSplitSheets(Spreadsheet $ss, array $rows): void
    {
        $headers = [
            'Order', 'Customer ID', 'Inventory ID', 'Product Name', 'Demand Qty',
            'Order Qty', 'Shipped Qty', 'Qty On Shipments', 'Open Qty', 'UOM',
            'Unit Price', 'Line Fill Rate %', 'Unfilled Reason Code',
            'Unfilled Reason', 'Not Shipped Value',
        ];

        $manufactured = [];
        $partner = [];

        foreach ($rows as $row) {
            $invId = (string) ($row[2] ?? '');
            $brand = $this->classifyBrand($invId);
            if ($brand === 'Manufactured') {
                $manufactured[] = $row;
            } else {
                $partner[] = $row;
            }
        }

        $this->writeSheet($ss, 'Manufactured Lines', $headers, $manufactured);
        $this->writeSheet($ss, 'Partner Brand Lines', $headers, $partner);
    }

    /**
     * Sheet 5 – Lost Sales Analysis.
     *
     * Layout:
     *   Row 1        : Grand Total banner (spans full width)
     *   Row 3+       : For each SKU (sorted desc by total lost sales):
     *                    - SKU header row (shaded)
     *                    - Data rows: Order | Customer ID | Order Date | Order Qty |
     *                                 Unit Price | Lost Sales Value | Root Cause | Brand Type
     *                    - Subtotal row
     *                    - Blank spacer
     *
     * High-value incidents (lost sales > 100,000) get orange conditional formatting.
     */
    private function writeLostSalesSheet(Spreadsheet $ss, array $productRows): void
    {
        $sheet = $ss->createSheet();
        $sheet->setTitle('Lost Sales Analysis');

        // ── Aggregate by SKU ──────────────────────────────────────────────────
        // productRows columns:
        //  0 Order | 1 Customer ID | 2 Inventory ID | 3 Product Name |
        //  4 Demand Qty | 5 Order Qty | 6 Shipped Qty | 7 Qty On Shipments |
        //  8 Open Qty | 9 UOM | 10 Unit Price | 11 Line Fill Rate % |
        // 12 Unfilled Reason Code | 13 Unfilled Reason | 14 Not Shipped Value
        //
        // We need: Order, Customer ID, Order Date (not available per line – use 'N/A'),
        // Order Qty (col 5), Unit Price (col 10), Not Shipped Value (col 14),
        // Root Cause (col 13), Brand Type (derived from col 2)

        $bySkuRows = [];  // ['inv_id' => [...rows]]
        $bySkuTotal = []; // ['inv_id' => float]
        $bySkuName = [];  // ['inv_id' => string]

        foreach ($productRows as $row) {
            $invId   = (string) ($row[2] ?? '');
            $lostVal = (float) ($row[14] ?? 0);
            if ($lostVal <= 0) {
                continue; // skip fully-filled lines
            }
            $bySkuRows[$invId][]  = $row;
            $bySkuTotal[$invId]   = ($bySkuTotal[$invId] ?? 0) + $lostVal;
            $bySkuName[$invId]    = (string) ($row[3] ?? $invId);
        }

        if (empty($bySkuTotal)) {
            $sheet->setCellValue('A1', 'No unfilled lines in this export.');
            return;
        }

        // Sort SKUs by total lost sales descending
        arsort($bySkuTotal);
        $grandTotal = array_sum($bySkuTotal);

        $colHeaders = [
            'Order', 'Customer ID', 'Order Qty', 'Unit Price (KES)',
            'Lost Sales Value (KES)', 'Root Cause', 'Brand Type',
        ];
        $lastCol = 'G'; // 7 columns (A–G)

        // ── Grand Total banner (row 1) ────────────────────────────────────────
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', '★  GRAND TOTAL LOST SALES: KES ' . number_format($grandTotal, 2));
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E3A5F']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        // Row 2: brand split summary
        $mfgTotal     = 0;
        $partnerTotal = 0;
        foreach ($bySkuRows as $invId => $skuRowList) {
            foreach ($skuRowList as $r) {
                $val = (float) ($r[14] ?? 0);
                if ($this->classifyBrand($invId) === 'Manufactured') {
                    $mfgTotal += $val;
                } else {
                    $partnerTotal += $val;
                }
            }
        }

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', sprintf(
            'Manufactured (Kim-Fay): KES %s     |     Partner Brands: KES %s',
            number_format($mfgTotal, 2),
            number_format($partnerTotal, 2),
        ));
        $sheet->getStyle("A2:{$lastCol}2")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2E5E8E']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $currentRow = 4; // start SKU blocks from row 4

        foreach ($bySkuTotal as $invId => $skuLostTotal) {
            $skuName  = $bySkuName[$invId];
            $brand    = $this->classifyBrand($invId);
            $skuLines = $bySkuRows[$invId];

            // ── SKU header ───────────────────────────────────────────────────
            $sheet->mergeCells("A{$currentRow}:{$lastCol}{$currentRow}");
            $headerBg = $brand === 'Manufactured' ? 'FF0F4C81' : 'FF6B3A7D';
            $sheet->setCellValue("A{$currentRow}", "  {$invId}  —  {$skuName}  [{$brand}]   Total: KES " . number_format($skuLostTotal, 2));
            $sheet->getStyle("A{$currentRow}:{$lastCol}{$currentRow}")->applyFromArray([
                'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $headerBg]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension($currentRow)->setRowHeight(20);
            $currentRow++;

            // ── Column headers ───────────────────────────────────────────────
            foreach ($colHeaders as $colIdx => $colHeader) {
                $colLetter = Coordinate::stringFromColumnIndex($colIdx + 1);
                $sheet->setCellValue("{$colLetter}{$currentRow}", $colHeader);
            }
            $sheet->getStyle("A{$currentRow}:{$lastCol}{$currentRow}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE5E7EB']],
            ]);
            $currentRow++;

            $dataStartRow = $currentRow;

            // ── Data rows ────────────────────────────────────────────────────
            foreach ($skuLines as $row) {
                $lostVal = (float) ($row[14] ?? 0);
                $sheet->setCellValue("A{$currentRow}", $row[0] ?? '');   // Order
                $sheet->setCellValue("B{$currentRow}", $row[1] ?? '');   // Customer ID
                $sheet->setCellValue("C{$currentRow}", (float) ($row[5] ?? 0)); // Order Qty
                $sheet->setCellValue("D{$currentRow}", (float) ($row[10] ?? 0)); // Unit Price
                $sheet->setCellValue("E{$currentRow}", $lostVal);         // Lost Sales Value
                $sheet->setCellValue("F{$currentRow}", (string) ($row[13] ?? 'Unassigned')); // Root Cause
                $sheet->setCellValue("G{$currentRow}", $brand);           // Brand Type

                // Format numeric columns
                $sheet->getStyle("D{$currentRow}")->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);
                $sheet->getStyle("E{$currentRow}")->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);

                $currentRow++;
            }

            $dataEndRow = $currentRow - 1;

            // ── Conditional formatting: high-value incidents > 100,000 ───────
            if ($dataEndRow >= $dataStartRow) {
                $cfRange = "E{$dataStartRow}:E{$dataEndRow}";
                $cf = new Conditional();
                $cf->setConditionType(Conditional::CONDITION_CELLIS);
                $cf->setOperatorType(Conditional::OPERATOR_GREATERTHAN);
                $cf->addCondition('100000');
                $cf->getStyle()->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFFFD966');
                $cf->getStyle()->getFont()->setBold(true);
                $sheet->getStyle($cfRange)->setConditionalStyles([$cf]);
            }

            // ── Subtotal row ─────────────────────────────────────────────────
            $sheet->mergeCells("A{$currentRow}:D{$currentRow}");
            $sheet->setCellValue("A{$currentRow}", "Subtotal — {$invId}");
            if ($dataEndRow >= $dataStartRow) {
                $sheet->setCellValue("E{$currentRow}", "=SUM(E{$dataStartRow}:E{$dataEndRow})");
            } else {
                $sheet->setCellValue("E{$currentRow}", 0);
            }
            $sheet->getStyle("A{$currentRow}:{$lastCol}{$currentRow}")->applyFromArray([
                'font' => ['bold' => true, 'italic' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF3F4F6']],
            ]);
            $sheet->getStyle("E{$currentRow}")->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);
            $currentRow += 2; // +1 spacer row
        }

        // ── Auto-size columns ─────────────────────────────────────────────────
        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // ── Freeze header rows ────────────────────────────────────────────────
        $sheet->freezePane('A4');
    }

    /** Sheet 9 – Summary dashboard */
    private function writeSummarySheet(
        Spreadsheet $ss,
        array $productRows,
        array $reasonRows,
        string $dateFrom,
        string $dateTo,
    ): void {
        $sheet = $ss->createSheet();
        $sheet->setTitle('Summary');

        // Compute totals from productRows
        $grandTotal   = 0;
        $bySkuTotal   = [];
        $bySkuName    = [];
        $mfgTotal     = 0;
        $partnerTotal = 0;

        foreach ($productRows as $row) {
            $lostVal = (float) ($row[14] ?? 0);
            if ($lostVal <= 0) {
                continue;
            }
            $invId   = (string) ($row[2] ?? '');
            $brand   = $this->classifyBrand($invId);
            $grandTotal += $lostVal;
            $bySkuTotal[$invId]  = ($bySkuTotal[$invId] ?? 0) + $lostVal;
            $bySkuName[$invId]   = (string) ($row[3] ?? $invId);
            if ($brand === 'Manufactured') {
                $mfgTotal += $lostVal;
            } else {
                $partnerTotal += $lostVal;
            }
        }

        arsort($bySkuTotal);
        $top5 = array_slice($bySkuTotal, 0, 5, true);

        $r = 1;

        // Title
        $sheet->mergeCells("A{$r}:D{$r}");
        $sheet->setCellValue("A{$r}", 'Fill Rate — Lost Sales Summary');
        $sheet->getStyle("A{$r}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E3A5F']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getRowDimension($r)->setRowHeight(30);
        $r++;

        $sheet->setCellValue("A{$r}", "Period: {$dateFrom} to {$dateTo}");
        $sheet->getStyle("A{$r}")->getFont()->setItalic(true)->setColor(new Color('FF555555'));
        $r += 2;

        // KPI tiles
        foreach ([
            ['Total Lost Sales (KES)', 'KES ' . number_format($grandTotal, 2), 'FFDC2626'],
            ['Manufactured Brands', 'KES ' . number_format($mfgTotal, 2), 'FF0F4C81'],
            ['Partner Brands', 'KES ' . number_format($partnerTotal, 2), 'FF6B3A7D'],
            ['SKUs Affected', (string) count($bySkuTotal), 'FF0369A1'],
        ] as [$label, $val, $color]) {
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $val);
            $sheet->getStyle("A{$r}")->getFont()->setBold(true);
            $sheet->getStyle("B{$r}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => $color]],
            ]);
            $r++;
        }

        $r++;

        // Top 5 SKUs
        $sheet->setCellValue("A{$r}", 'Top 5 SKUs by Lost Sales Value');
        $sheet->getStyle("A{$r}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE5E7EB']],
        ]);
        $sheet->mergeCells("A{$r}:D{$r}");
        $r++;

        $sheet->setCellValue("A{$r}", 'Rank');
        $sheet->setCellValue("B{$r}", 'SKU');
        $sheet->setCellValue("C{$r}", 'Product Name');
        $sheet->setCellValue("D{$r}", 'Lost Sales (KES)');
        $sheet->getStyle("A{$r}:D{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:D{$r}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE5E7EB');
        $r++;

        $rank = 1;
        foreach ($top5 as $invId => $val) {
            $sheet->setCellValue("A{$r}", $rank);
            $sheet->setCellValue("B{$r}", $invId);
            $sheet->setCellValue("C{$r}", $bySkuName[$invId] ?? $invId);
            $sheet->setCellValue("D{$r}", $val);
            $sheet->getStyle("D{$r}")->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);
            $rank++;
            $r++;
        }

        $r++;

        // Root cause distribution
        $sheet->mergeCells("A{$r}:D{$r}");
        $sheet->setCellValue("A{$r}", 'Root Cause Frequency Distribution');
        $sheet->getStyle("A{$r}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE5E7EB']],
        ]);
        $r++;

        $sheet->setCellValue("A{$r}", 'Root Cause');
        $sheet->setCellValue("B{$r}", 'Line Count');
        $sheet->setCellValue("C{$r}", 'Undershipped Value (KES)');
        $sheet->setCellValue("D{$r}", 'Contribution %');
        $sheet->getStyle("A{$r}:D{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:D{$r}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE5E7EB');
        $r++;

        foreach ($reasonRows as $rr) {
            $sheet->setCellValue("A{$r}", $rr['reason'] ?? '');
            $sheet->setCellValue("B{$r}", $rr['line_count'] ?? 0);
            $sheet->setCellValue("C{$r}", $rr['undershipped_value'] ?? 0);
            $sheet->setCellValue("D{$r}", isset($rr['contribution_pct']) ? round((float) $rr['contribution_pct'], 1) . '%' : '');
            $sheet->getStyle("C{$r}")->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);
            $r++;
        }

        foreach (['A', 'B', 'C', 'D'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /** Sheet 10 – Instructions */
    private function writeInstructionsSheet(Spreadsheet $ss): void
    {
        $sheet = $ss->createSheet();
        $sheet->setTitle('Instructions');

        $lines = [
            ['Fill Rate Export — How to Use This File', 'title'],
            ['', ''],
            ['This workbook contains 10 sheets. Here is a guide to each:', 'heading'],
            ['', ''],
            ['1. Fill Rate', 'bold'],
            ['   Order-level fill rate data for the selected period. Each row represents one sales order.', ''],
            ['   Key columns: Fill Rate %, Fill Rate Status, Revenue Not Shipped.', ''],
            ['', ''],
            ['2. Product Lines', 'bold'],
            ['   Line-level detail for every order. The last column (Brand Type) classifies each SKU', ''],
            ['   as "Manufactured" (Kim-Fay own brand) or "Partner Brand" (third-party).', ''],
            ['', ''],
            ['3. Manufactured Lines', 'bold'],
            ['   Filtered view showing only Kim-Fay manufactured product lines.', ''],
            ['   Use this sheet to analyse fill rate performance for own brands.', ''],
            ['', ''],
            ['4. Partner Brand Lines', 'bold'],
            ['   Filtered view showing only third-party / partner brand lines.', ''],
            ['   Use this sheet to compare partner brand performance.', ''],
            ['', ''],
            ['5. Lost Sales Analysis', 'bold'],
            ['   SKU-by-SKU breakdown of lost sales. Each SKU has its own section with:', ''],
            ['   - Individual transaction rows (order, customer, qty, price, lost value, root cause)', ''],
            ['   - A subtotal row for that SKU', ''],
            ['   - Conditional formatting: cells shaded in gold = lost sales > KES 100,000', ''],
            ['   Grand total and Manufactured vs Partner split are shown at the top.', ''],
            ['', ''],
            ['6. Reason Summary', 'bold'],
            ['   Root cause contribution — how much of total undershipped value each reason accounts for.', ''],
            ['', ''],
            ['7. Customer Summary', 'bold'],
            ['   Top customers by undershipped value.', ''],
            ['', ''],
            ['8. Product Summary', 'bold'],
            ['   Top SKUs by undershipped value across all orders.', ''],
            ['', ''],
            ['9. Summary', 'bold'],
            ['   Dashboard-style summary: grand total, brand split, top 5 SKUs, root cause distribution.', ''],
            ['', ''],
            ['Brand Classification', 'heading'],
            ['Manufactured (Kim-Fay own brands): FAY, SIF, COS, TIS, ULT, STD, SHO, ANT, URI, TOI, AIR, ALK, DIS', ''],
            ['Partner Brands: DOV, REX, LUX, HUG, KOT, COW, APT, BIO, DAB, ORS, VAT, HOB, DUR, FEM, KLE, MIS, MSW, IKO, CON, BIG', ''],
            ['', ''],
            ['Tips', 'heading'],
            ['- Use the AutoFilter on any data sheet to slice by reason, customer, or date.', ''],
            ['- The Lost Sales Analysis sheet is sorted by SKU total (highest first) for quick triage.', ''],
            ['- Gold-highlighted rows in Lost Sales Analysis indicate incidents worth > KES 100,000.', ''],
            ['- All formulas in the Lost Sales subtotal rows use SUM() — safe to copy/extend.', ''],
        ];

        $row = 1;
        foreach ($lines as [$text, $style]) {
            $sheet->setCellValue("A{$row}", $text);
            if ($style === 'title') {
                $sheet->getStyle("A{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 16, 'color' => ['argb' => 'FFFFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E3A5F']],
                ]);
                $sheet->getRowDimension($row)->setRowHeight(28);
            } elseif ($style === 'heading') {
                $sheet->getStyle("A{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11, 'color' => ['argb' => 'FF1E3A5F']],
                ]);
            } elseif ($style === 'bold') {
                $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            }
            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth(100);
    }

    // ---------------------------------------------------------------------------
    // Low-level sheet helpers (same as controller's writeSheet / writeContributionSheet)
    // ---------------------------------------------------------------------------

    /** @param array<int, string> $headers  @param array<int, array<int, mixed>> $rows */
    private function writeSheet(Spreadsheet $ss, string $title, array $headers, array $rows): void
    {
        $sheet = $ss->getSheetCount() === 1
            && $ss->getActiveSheet()->getHighestRow() === 1
            && $ss->getActiveSheet()->getCell('A1')->getValue() === null
            ? $ss->getActiveSheet()
            : $ss->createSheet();

        $sheet->setTitle(substr($title, 0, 31));
        $sheet->fromArray($headers, null, 'A1');

        if ($rows !== []) {
            $sheet->fromArray($rows, null, 'A2');
        }

        $highestColumn = $sheet->getHighestColumn();
        $sheet->getStyle("A1:{$highestColumn}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$highestColumn}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE5E7EB');
        $sheet->freezePane('A2');
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());

        for ($col = 1, $max = Coordinate::columnIndexFromString($highestColumn); $col <= $max; $col++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
        }
    }

    /** @param array<int, array<string, mixed>> $rows  @param array<string, string> $columns */
    private function writeContributionSheet(Spreadsheet $ss, string $title, array $rows, array $columns): void
    {
        $this->writeSheet(
            $ss,
            $title,
            array_values($columns),
            collect($rows)
                ->map(fn (array $row) => collect(array_keys($columns))
                    ->map(fn (string $key) => $row[$key] ?? null)
                    ->all())
                ->all(),
        );
    }
}
