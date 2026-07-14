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
use Throwable;

/**
 * Generates the enhanced fill-rate Excel export.
 *
 * Sheets produced (reordered — executive-focused first):
 *   1.  Instructions           – how to use this file
 *   2.  Summary                – grand total, brand split, KP/CS sector split, root cause
 *                                 breakdown, top-5 SKUs, SO shortfall counts, missing-price impact
 *   3.  Fill Rate              – order-level (unchanged structure)
 *   4.  Product Lines          – line-level (unchanged structure + Brand Type column)
 *   5.  Manufactured Lines     – Manufactured goods lines only
 *   6.  Trading (Partners) Lines – Trading (Partners) goods lines only
 *   7.  Lost Sales Analysis    – SKU-grouped with subtotals + grand total
 *   8.  Reason Summary         – root-cause contribution (unchanged)
 *   9.  Customer Summary       – top customers (unchanged)
 *  10.  Product Summary        – top products (unchanged)
 *  11.  SOs Not Fully Delivered – incomplete orders with quantities and values
 *  12.  Missing Price Values   – items with missing unit price flagged explicitly
 */
class FillRateExcelExporter
{
    public function __construct(
        private readonly FillRateBusinessCategory $businessCategory,
    ) {
    }

    public function classifyBrand(string $inventoryId): string
    {
        return $this->businessCategory->label(
            $this->businessCategory->classify($inventoryId),
        );
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
        array $segmentRows = [],
        array $segmentReasonRows = [],
        array $businessCategoryRows = [],
        array $reasonCaptureReport = [],
    ): StreamedResponse {
        // Large multi-sheet workbooks can exceed gateway timeouts (504) if
        // autosize/filter work runs on tens of thousands of rows.
        $this->raiseMemoryLimit();
        $this->raiseTimeLimit();

        try {
            $spreadsheet = new Spreadsheet();
            $spreadsheet->getProperties()
                ->setCreator('Kim-Fay OrderWatch')
                ->setTitle('Fill Rate Export');

            // Sheet 1: Instructions (how to use this file) — first so the
            // reader immediately sees guidance and the KP/CS categorisation rules.
            $this->writeInstructionsSheet($spreadsheet);

            // Sheet 2: Summary dashboard — executive-focused, with brand split,
            // KP/CS sector split, root cause breakdown, top-5 SKUs and shortfall counts.
            $this->writeSummarySheet(
                $spreadsheet,
                $productRows,
                $reasonRows,
                $dateFrom,
                $dateTo,
                $fillRateRows,
                $segmentRows,
                $segmentReasonRows,
                $businessCategoryRows,
                $reasonCaptureReport,
            );

            // Sheet 3: Fill Rate (order level) – unchanged
            $this->writeFillRateSheet($spreadsheet, $fillRateRows);

            // Sheet 4: Product Lines (line level) + Brand Type column
            $this->writeProductLinesSheet($spreadsheet, $productRows);

            // Sheets 5 & 6: Brand split
            $this->writeBrandSplitSheets($spreadsheet, $productRows);

            // Sheet 7: Lost Sales Analysis (SKU-grouped)
            $this->writeLostSalesSheet($spreadsheet, $productRows);

            // Sheets 8-10: Contribution summaries (unchanged)
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

            // Sheet 11: SOs Not Fully Delivered (incomplete orders)
            $this->writeSosNotFullyDeliveredSheet($spreadsheet, $fillRateRows, $productRows);

            // Sheet 12: Missing Price Values (items with missing unit price flagged)
            $this->writeMissingPriceSheet($spreadsheet, $productRows);

            // Sheet 13: Reason Capture Report (validation + breakdown by business category)
            if ($reasonCaptureReport !== []) {
                $this->writeReasonCaptureSheet($spreadsheet, $reasonCaptureReport, $dateFrom, $dateTo);
            }

            $spreadsheet->setActiveSheetIndex(0);

            $filename = 'fill-rate-export-' . now()->format('Ymd-Hi') . '.xlsx';

            return response()->streamDownload(function () use ($spreadsheet) {
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
                $spreadsheet->disconnectWorksheets();
            }, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        } catch (Throwable $e) {
            // Ensure worksheets are cleaned up even on failure to prevent
            // memory leaks, and rethrow so the framework can surface the error.
            if (isset($spreadsheet)) {
                $spreadsheet->disconnectWorksheets();
            }

            throw $e;
        }
    }

    /**
     * Raise the PHP memory limit to a safe ceiling for spreadsheet generation.
     * Large exports (tens of thousands of SO records) can exceed the default
     * limit, which would truncate the output stream and produce a corrupt file.
     */
    private function raiseMemoryLimit(): void
    {
        $target = '1024M';
        $current = ini_get('memory_limit');

        if ($current === false || $current === '-1') {
            return; // unlimited already
        }

        // Convert current to bytes and compare against target.
        $currentBytes = $this->toBytes($current);
        $targetBytes = $this->toBytes($target);

        if ($currentBytes < $targetBytes) {
            @ini_set('memory_limit', $target);
        }
    }

    private function raiseTimeLimit(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        @ini_set('max_execution_time', '300');
    }

    private function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower($value[strlen($value) - 1]);
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
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

    /** Sheets 5 & 6 – Manufactured vs Trading (Partners) split */
    private function writeBrandSplitSheets(Spreadsheet $ss, array $rows): void
    {
        $headers = [
            'Order', 'Customer ID', 'Inventory ID', 'Product Name', 'Demand Qty',
            'Order Qty', 'Shipped Qty', 'Qty On Shipments', 'Open Qty', 'UOM',
            'Unit Price', 'Line Fill Rate %', 'Unfilled Reason Code',
            'Unfilled Reason', 'Not Shipped Value',
        ];

        $manufactured = [];
        $trading = [];

        foreach ($rows as $row) {
            $invId = (string) ($row[2] ?? '');
            $category = $this->businessCategory->classify($invId);
            if ($category === FillRateBusinessCategory::MANUFACTURED) {
                $manufactured[] = $row;
            } else {
                $trading[] = $row;
            }
        }

        $this->writeSheet($ss, 'Manufactured Lines', $headers, $manufactured);
        $this->writeSheet($ss, 'Trading (Partners) Lines', $headers, $trading);
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
                if ($this->businessCategory->classify($invId) === FillRateBusinessCategory::MANUFACTURED) {
                    $mfgTotal += $val;
                } else {
                    $partnerTotal += $val;
                }
            }
        }

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', sprintf(
            'Manufactured: KES %s     |     Trading (Partners): KES %s',
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
            $headerBg = $this->businessCategory->classify($invId) === FillRateBusinessCategory::MANUFACTURED
                ? 'FF0F4C81'
                : 'FF6B3A7D';
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

        // ── Column sizing (skip autosize on large lost-sales sheets) ──────────
        $this->applyColumnSizing($sheet, range('A', $lastCol), array_sum(array_map('count', $bySkuRows)));

        // ── Freeze header rows ────────────────────────────────────────────────
        $sheet->freezePane('A4');
    }

    /**
     * Sheet 9 – SOs Not Fully Delivered.
     *
     * Lists every sales order whose fill rate is below 100% (incomplete
     * delivery), together with ordered qty, shipped qty, unfilled qty and
     * the monetary value of the shortfall.
     *
     * fillRateRows layout (0-indexed):
     *   0 Order | 1 Customer ID | 2 Customer Name | 3 Status | 4 Order Date |
     *   5 Ordered Qty | 6 Shipped Qty | 7 Fill Rate % | 8 Fill Rate Status |
     *   9 Revenue Not Shipped | …
     */
    private function writeSosNotFullyDeliveredSheet(
        Spreadsheet $ss,
        array $fillRateRows,
        array $productRows,
    ): void {
        $sheet = $ss->createSheet();
        $sheet->setTitle('SOs Not Fully Delivered');

        // Aggregate line-level unfilled quantities per order from productRows.
        // productRows: 0 Order | … | 5 Order Qty | 7 Qty On Shipments | 14 Not Shipped Value
        $unfilledQtyByOrder = [];
        foreach ($productRows as $row) {
            $orderNbr = (string) ($row[0] ?? '');
            if ($orderNbr === '') {
                continue;
            }
            $demandQty = (float) ($row[5] ?? 0);
            $qtyOnShipments = (float) ($row[7] ?? 0);
            $unfilledQtyByOrder[$orderNbr]
                = ($unfilledQtyByOrder[$orderNbr] ?? 0) + max($demandQty - $qtyOnShipments, 0);
        }

        // Filter to orders that are not fully delivered (fill rate < 100 or
        // revenue not shipped > 0). Skip NA rows.
        $incompleteRows = [];
        foreach ($fillRateRows as $row) {
            $fillRatePct = $row[7] ?? null;
            $fillRateStatus = (string) ($row[8] ?? '');
            $revenueNotShipped = (float) ($row[9] ?? 0);

            if ($fillRateStatus === 'na') {
                continue;
            }
            if ($fillRatePct !== null && (float) $fillRatePct >= 100.0 && $revenueNotShipped <= 0) {
                continue;
            }

            $orderNbr = (string) ($row[0] ?? '');
            $incompleteRows[] = [
                $orderNbr,
                $row[1] ?? '',        // Customer ID
                $row[2] ?? '',        // Customer Name
                $row[3] ?? '',        // Status
                $row[4] ?? '',        // Order Date
                (float) ($row[5] ?? 0), // Ordered Qty
                (float) ($row[6] ?? 0), // Shipped Qty
                $unfilledQtyByOrder[$orderNbr] ?? 0, // Unfilled Qty
                $fillRatePct !== null ? (float) $fillRatePct : null, // Fill Rate %
                $revenueNotShipped,   // Value Shortfall (KES)
            ];
        }

        // Sort by value shortfall descending so the worst offenders are on top.
        usort($incompleteRows, fn ($a, $b) => $b[9] <=> $a[9]);

        $headers = [
            'Order', 'Customer ID', 'Customer Name', 'Status', 'Order Date',
            'Ordered Qty', 'Shipped Qty', 'Unfilled Qty', 'Fill Rate %', 'Value Shortfall (KES)',
        ];

        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE5E7EB');

        $summaryRow = 2;

        if ($incompleteRows !== []) {
            $sheet->fromArray($incompleteRows, null, 'A2');
            $summaryRow = count($incompleteRows) + 2;
        }

        // Grand total row
        $totalOrdered = array_sum(array_column($incompleteRows, 5));
        $totalShipped = array_sum(array_column($incompleteRows, 6));
        $totalUnfilled = array_sum(array_column($incompleteRows, 7));
        $totalValue = array_sum(array_column($incompleteRows, 9));

        if ($incompleteRows !== []) {
            $sheet->setCellValue("A{$summaryRow}", 'GRAND TOTAL (' . count($incompleteRows) . ' orders)');
        } else {
            $sheet->setCellValue("A{$summaryRow}", 'All sales orders fully delivered for this period.');
        }

        $sheet->setCellValue("F{$summaryRow}", round($totalOrdered, 4));
        $sheet->setCellValue("G{$summaryRow}", round($totalShipped, 4));
        $sheet->setCellValue("H{$summaryRow}", round($totalUnfilled, 4));
        $sheet->setCellValue("J{$summaryRow}", round($totalValue, 2));

        $sheet->getStyle("A{$summaryRow}:J{$summaryRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E3A5F']],
        ]);
        $sheet->getStyle("J{$summaryRow}")->getNumberFormat()
            ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);

        // Format currency column for data rows
        if ($incompleteRows !== []) {
            $dataEnd = $summaryRow - 1;
            $sheet->getStyle("J2:J{$dataEnd}")->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);
        }

        $this->applyColumnSizing($sheet, range('A', 'J'), count($incompleteRows));
        $sheet->freezePane('A2');
        if (count($incompleteRows) <= 500) {
            $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        }
    }

    /**
     * Sheet 10 – Missing Price Values.
     *
     * Flags every product line whose unit price is missing or zero. Lines
     * without a price cannot contribute to revenue-loss calculations, so they
     * are surfaced here as a data-quality issue that distorts fill-rate
     * financial analysis.
     *
     * productRows: 0 Order | 1 Customer ID | 2 Inventory ID | 3 Product Name |
     *              10 Unit Price | 5 Order Qty | 7 Qty On Shipments | 12 Reason Code
     */
    private function writeMissingPriceSheet(Spreadsheet $ss, array $productRows): void
    {
        $sheet = $ss->createSheet();
        $sheet->setTitle('Missing Price Values');

        $missingRows = [];
        foreach ($productRows as $row) {
            $unitPrice = (float) ($row[10] ?? 0);
            if ($unitPrice > 0) {
                continue;
            }

            $orderQty = (float) ($row[5] ?? 0);
            $qtyOnShipments = (float) ($row[7] ?? 0);
            $unfilledQty = max($orderQty - $qtyOnShipments, 0);

            $missingRows[] = [
                $row[0] ?? '',  // Order
                $row[1] ?? '',  // Customer ID
                $row[2] ?? '',  // Inventory ID
                $row[3] ?? '',  // Product Name
                $orderQty,
                $qtyOnShipments,
                $unfilledQty,
                $row[12] ?? '', // Unfilled Reason Code
                'MISSING PRICE', // Flag
            ];
        }

        $headers = [
            'Order', 'Customer ID', 'Inventory ID', 'Product Name',
            'Order Qty', 'Shipped Qty', 'Unfilled Qty',
            'Unfilled Reason Code', 'Flag',
        ];

        $sheet->fromArray($headers, null, 'A1');
        $lastCol = $sheet->getHighestColumn();
        $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastCol}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE5E7EB');

        $r = 2;

        if ($missingRows === []) {
            $sheet->mergeCells("A2:I2");
            $sheet->setCellValue('A2', 'No lines with missing price values found. All products have unit prices assigned.');
            $sheet->getStyle('A2')->getFont()->setItalic(true);
        } else {
            $sheet->fromArray($missingRows, null, 'A2');
            $r = count($missingRows) + 2;

            // Highlight the Flag column in red for every data row
            $dataEnd = $r - 1;
            $sheet->getStyle("I2:I{$dataEnd}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFF0000']],
            ]);

            // Conditional formatting on Unfilled Qty > 0 to emphasise items
            // that are both missing price AND undershipped.
            $cf = new Conditional();
            $cf->setConditionType(Conditional::CONDITION_CELLIS);
            $cf->setOperatorType(Conditional::OPERATOR_GREATERTHAN);
            $cf->addCondition('0');
            $cf->getStyle()->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFFFC7CE');
            $sheet->getStyle("G2:G{$dataEnd}")->setConditionalStyles([$cf]);
        }

        // Summary KPI row
        $totalLinesMissing = count($missingRows);
        $totalUnfilledQty = array_sum(array_column($missingRows, 6));

        $sheet->setCellValue("A{$r}", 'Summary');
        $sheet->getStyle("A{$r}")->getFont()->setBold(true);
        $r++;
        $sheet->setCellValue("A{$r}", 'Lines with Missing Price');
        $sheet->setCellValue("B{$r}", $totalLinesMissing);
        $sheet->getStyle("A{$r}")->getFont()->setBold(true);
        $r++;
        $sheet->setCellValue("A{$r}", 'Total Unfilled Qty (Missing Price Lines)');
        $sheet->setCellValue("B{$r}", round($totalUnfilledQty, 4));
        $sheet->getStyle("A{$r}")->getFont()->setBold(true);

        $this->applyColumnSizing($sheet, range('A', 'I'), count($missingRows));
        $sheet->freezePane('A2');
    }

    /** Sheet 2 – Summary dashboard */
    private function writeSummarySheet(
        Spreadsheet $ss,
        array $productRows,
        array $reasonRows,
        string $dateFrom,
        string $dateTo,
        array $fillRateRows = [],
        array $segmentRows = [],
        array $segmentReasonRows = [],
        array $businessCategoryRows = [],
        array $reasonCaptureReport = [],
    ): void {
        // Reuse the blank default sheet (index 0) if this is the first sheet
        // being written, otherwise create a new one.
        $sheet = $ss->getSheetCount() === 1
            && $ss->getActiveSheet()->getHighestRow() === 1
            && $ss->getActiveSheet()->getCell('A1')->getValue() === null
            ? $ss->getActiveSheet()
            : $ss->createSheet();
        $sheet->setTitle('Summary');

        // Compute totals from productRows
        $grandTotal   = 0;
        $bySkuTotal   = [];
        $bySkuName    = [];
        $mfgTotal     = 0;
        $partnerTotal = 0;
        $missingPriceLineCount = 0;

        foreach ($productRows as $row) {
            $invId    = (string) ($row[2] ?? '');
            $brand    = $this->classifyBrand($invId);
            $unitPrice = (float) ($row[10] ?? 0);
            $lostVal   = (float) ($row[14] ?? 0);

            // Track lines with missing/zero price regardless of whether they
            // have a lost-sales value — this is a data-quality metric.
            if ($unitPrice <= 0) {
                $missingPriceLineCount++;
            }

            if ($lostVal <= 0) {
                continue;
            }
            $grandTotal += $lostVal;
            $bySkuTotal[$invId]  = ($bySkuTotal[$invId] ?? 0) + $lostVal;
            $bySkuName[$invId]   = (string) ($row[3] ?? $invId);
            if ($this->businessCategory->classify($invId) === FillRateBusinessCategory::MANUFACTURED) {
                $mfgTotal += $lostVal;
            } else {
                $partnerTotal += $lostVal;
            }
        }

        // Compute SO shortfall metrics from fillRateRows.
        // fillRateRows: 0 Order | 7 Fill Rate % | 8 Fill Rate Status | 9 Revenue Not Shipped
        $totalOrders        = count($fillRateRows);
        $incompleteOrders   = 0;
        $totalRevenueShort  = 0.0;

        foreach ($fillRateRows as $row) {
            $fillRateStatus = (string) ($row[8] ?? '');
            if ($fillRateStatus === 'na') {
                continue;
            }
            $fillRatePct       = $row[7] ?? null;
            $revenueNotShipped = (float) ($row[9] ?? 0);

            if ($fillRatePct === null || (float) $fillRatePct < 100.0 || $revenueNotShipped > 0) {
                $incompleteOrders++;
                $totalRevenueShort += $revenueNotShipped;
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
        $shortfallPct = $totalOrders > 0
            ? round(($incompleteOrders / $totalOrders) * 100, 1) . '%'
            : '0%';

        foreach ([
            ['Total Lost Sales (KES)', 'KES ' . number_format($grandTotal, 2), 'FFDC2626'],
            ['Manufactured Goods', 'KES ' . number_format($mfgTotal, 2), 'FF0F4C81'],
            ['Trading (Partners) Goods', 'KES ' . number_format($partnerTotal, 2), 'FF6B3A7D'],
            ['SOs Not Fully Delivered', "{$incompleteOrders} / {$totalOrders} ({$shortfallPct})", 'FFB91C1C'],
            ['Revenue Shortfall (KES)', 'KES ' . number_format($totalRevenueShort, 2), 'FFE11D48'],
            ['Lines w/ Missing Price', (string) $missingPriceLineCount, 'FFF59E0B'],
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

        // -------------------------------------------------------------------
        // Manufactured vs Trading (Partners) business category split
        // -------------------------------------------------------------------
        if ($businessCategoryRows !== []) {
            $sheet->mergeCells("A{$r}:D{$r}");
            $sheet->setCellValue("A{$r}", 'Manufactured vs Trading (Partners) — Business Category Split');
            $sheet->getStyle("A{$r}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 12],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE5E7EB']],
            ]);
            $r++;

            $catHeaders = ['Category', 'Fill Rate %', 'Lines', 'Undershipped Value (KES)'];
            foreach (range('A', 'D') as $idx => $col) {
                $sheet->setCellValue("{$col}{$r}", $catHeaders[$idx]);
            }
            $sheet->getStyle("A{$r}:D{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}:D{$r}")->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE5E7EB');
            $r++;

            foreach ($businessCategoryRows as $cat) {
                $fillPct = $cat['fill_rate_pct'] !== null
                    ? round((float) $cat['fill_rate_pct'], 1) . '%'
                    : 'N/A';
                $sheet->setCellValue("A{$r}", $cat['label'] ?? $cat['business_category'] ?? '');
                $sheet->setCellValue("B{$r}", $fillPct);
                $sheet->setCellValue("C{$r}", $cat['line_count'] ?? 0);
                $sheet->setCellValue("D{$r}", $cat['undershipped_value'] ?? 0);
                $sheet->getStyle("D{$r}")->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);
                $r++;
            }

            $r++;
        }

        // Reason capture summary KPIs
        if (($reasonCaptureReport['summary'] ?? []) !== []) {
            $summary = $reasonCaptureReport['summary'];
            $sheet->mergeCells("A{$r}:D{$r}");
            $sheet->setCellValue("A{$r}", 'Root Cause Capture Status');
            $sheet->getStyle("A{$r}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 12],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE5E7EB']],
            ]);
            $r++;

            foreach ([
                ['Shortfall Lines', (string) ($summary['total_shortfall_lines'] ?? 0)],
                ['Valid Reasons', (string) ($summary['valid_reason_lines'] ?? 0)],
                ['Missing Reasons', (string) ($summary['missing_reason_lines'] ?? 0)],
                ['Unclassified Reasons', (string) ($summary['unclassified_reason_lines'] ?? 0)],
                ['Capture Rate', isset($summary['capture_rate_pct']) ? $summary['capture_rate_pct'] . '%' : 'N/A'],
            ] as [$label, $val]) {
                $sheet->setCellValue("A{$r}", $label);
                $sheet->setCellValue("B{$r}", $val);
                $sheet->getStyle("A{$r}")->getFont()->setBold(true);
                $r++;
            }

            $r++;
        }

        // -------------------------------------------------------------------
        // KP (Kimfay Professional) vs CS (Consumer Sales) sector split
        // -------------------------------------------------------------------
        if ($segmentRows !== []) {
            $sheet->mergeCells("A{$r}:D{$r}");
            $sheet->setCellValue("A{$r}", 'KP (Kimfay Professional) vs CS (Consumer Sales) Sector Split');
            $sheet->getStyle("A{$r}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 12],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE5E7EB']],
            ]);
            $r++;

            $segHeaders = ['Segment', 'Fill Rate %', 'Orders', 'Revenue Not Shipped (KES)'];
            foreach (range('A', 'D') as $idx => $col) {
                $sheet->setCellValue("{$col}{$r}", $segHeaders[$idx]);
            }
            $sheet->getStyle("A{$r}:D{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}:D{$r}")->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE5E7EB');
            $r++;

            foreach ($segmentRows as $seg) {
                $label = $seg['label'] ?? $seg['segment'] ?? '';
                $fillPct = $seg['fill_rate_pct'] !== null
                    ? round((float) $seg['fill_rate_pct'], 1) . '%'
                    : 'N/A';
                $sheet->setCellValue("A{$r}", $label);
                $sheet->setCellValue("B{$r}", $fillPct);
                $sheet->setCellValue("C{$r}", $seg['order_count'] ?? 0);
                $sheet->setCellValue("D{$r}", $seg['revenue_not_shipped'] ?? 0);
                $sheet->getStyle("D{$r}")->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);
                $r++;
            }

            $r++;
        }

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

        // -------------------------------------------------------------------
        // Root Cause Breakdown by KP/CS Segment
        // -------------------------------------------------------------------
        if ($segmentReasonRows !== []) {
            $r++;

            $sheet->mergeCells("A{$r}:D{$r}");
            $sheet->setCellValue("A{$r}", 'Root Cause Breakdown by KP/CS Segment');
            $sheet->getStyle("A{$r}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 12],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE5E7EB']],
            ]);
            $r++;

            $srHeaders = ['Segment', 'Root Cause', 'Undershipped Value (KES)', 'Contribution %'];
            foreach (range('A', 'D') as $idx => $col) {
                $sheet->setCellValue("{$col}{$r}", $srHeaders[$idx]);
            }
            $sheet->getStyle("A{$r}:D{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}:D{$r}")->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE5E7EB');
            $r++;

            foreach ($segmentReasonRows as $srr) {
                $segLabel = ($srr['segment'] ?? '') === 'KP'
                    ? 'KP (Kimfay Professional)'
                    : 'CS (Consumer Sales)';
                $sheet->setCellValue("A{$r}", $segLabel);
                $sheet->setCellValue("B{$r}", $srr['reason'] ?? '');
                $sheet->setCellValue("C{$r}", $srr['undershipped_value'] ?? 0);
                $sheet->setCellValue("D{$r}", isset($srr['contribution_pct']) ? round((float) $srr['contribution_pct'], 1) . '%' : '');
                $sheet->getStyle("C{$r}")->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);
                $r++;
            }
        }

        $this->applyColumnSizing($sheet, ['A', 'B', 'C', 'D'], 50);
    }

    /** Sheet 1 – Instructions */
    private function writeInstructionsSheet(Spreadsheet $ss): void
    {
        // Reuse the blank default sheet (index 0) if this is the first sheet
        // being written, otherwise create a new one.
        $sheet = $ss->getSheetCount() === 1
            && $ss->getActiveSheet()->getHighestRow() === 1
            && $ss->getActiveSheet()->getCell('A1')->getValue() === null
            ? $ss->getActiveSheet()
            : $ss->createSheet();

        $sheet->setTitle('Instructions');

        $lines = [
            ['Fill Rate Export — How to Use This File', 'title'],
            ['', ''],
            ['This workbook contains 12 sheets. Here is a guide to each:', 'heading'],
            ['', ''],
            ['1. Instructions (this sheet)', 'bold'],
            ['   Guide to every sheet in this workbook, plus brand and KP/CS classification rules.', ''],
            ['', ''],
            ['2. Summary', 'bold'],
            ['   Executive dashboard: grand total, business category split (Manufactured vs Trading Partners),', ''],
            ['   KP (Kimfay Professional)/CS sector split, root cause capture status,', ''],
            ['   root cause frequency distribution,', ''],
            ['   root cause breakdown mapped to KP/CS segments, top 5 SKUs by lost sales,', ''],
            ['   SOs Not Fully Delivered count and percentage, Revenue Shortfall,', ''],
            ['   and Lines with Missing Price count.', ''],
            ['', ''],
            ['3. Fill Rate', 'bold'],
            ['   Order-level fill rate data for the selected period. Each row represents one sales order.', ''],
            ['   Key columns: Fill Rate %, Fill Rate Status, Revenue Not Shipped.', ''],
            ['', ''],
            ['4. Product Lines', 'bold'],
            ['   Line-level detail for every order. The last column (Brand Type) classifies each SKU', ''],
            ['   as "Manufactured" or "Trading (Partners)" business categories.', ''],
            ['', ''],
            ['5. Manufactured Lines', 'bold'],
            ['   Filtered view showing only Kim-Fay manufactured product lines.', ''],
            ['   Use this sheet to analyse fill rate performance for own brands.', ''],
            ['', ''],
            ['6. Trading (Partners) Lines', 'bold'],
            ['   Filtered view showing only Trading (Partners) goods lines.', ''],
            ['   Use this sheet to compare trading partner performance.', ''],
            ['', ''],
            ['7. Lost Sales Analysis', 'bold'],
            ['   SKU-by-SKU breakdown of lost sales. Each SKU has its own section with:', ''],
            ['   - Individual transaction rows (order, customer, qty, price, lost value, root cause)', ''],
            ['   - A subtotal row for that SKU', ''],
            ['   - Conditional formatting: cells shaded in gold = lost sales > KES 100,000', ''],
            ['   Grand total and Manufactured vs Trading (Partners) split are shown at the top.', ''],
            ['', ''],
            ['8. Reason Summary', 'bold'],
            ['   Root cause contribution — how much of total undershipped value each reason accounts for.', ''],
            ['', ''],
            ['9. Customer Summary', 'bold'],
            ['   Top customers by undershipped value.', ''],
            ['', ''],
            ['10. Product Summary', 'bold'],
            ['   Top SKUs by undershipped value across all orders.', ''],
            ['', ''],
            ['11. SOs Not Fully Delivered', 'bold'],
            ['   Lists every sales order whose fill rate is below 100% (incomplete delivery).', ''],
            ['   Columns: Ordered Qty, Shipped Qty, Unfilled Qty, Fill Rate %, Value Shortfall (KES).', ''],
            ['   Sorted by Value Shortfall descending — worst offenders at the top.', ''],
            ['   Grand total row at the bottom aggregates all quantities and value.', ''],
            ['', ''],
            ['12. Missing Price Values', 'bold'],
            ['   Data-quality check: flags every product line whose unit price is missing or zero.', ''],
            ['   Lines without a price cannot contribute to revenue-loss calculations.', ''],
            ['   Conditional formatting highlights items that are both missing price AND undershipped.', ''],
            ['   Summary KPIs at the bottom: count of affected lines and total unfilled quantity.', ''],
            ['', ''],
            ['Business Category Classification', 'heading'],
            ['Manufactured goods (Kim-Fay own brands): FAY, SIF, COS, TIS, ULT, STD, SHO, ANT, URI, TOI, AIR, ALK, DIS', ''],
            ['Trading (Partners) goods: DOV, REX, LUX, HUG, KOT, COW, APT, BIO, DAB, ORS, VAT, HOB, DUR, FEM, KLE, MIS, MSW, IKO, CON, BIG', ''],
            ['', ''],
            ['KP (Kimfay Professional) vs CS (Consumer Sales) Classification', 'heading'],
            ['Every customer is classified into exactly one segment based on their customer class:', ''],
            ['   KP (Kimfay Professional) — customer_class starts with "KP" (case-insensitive)', ''],
            ['   CS (Consumer Sales)      — ALL other customer classes (no unclassified bucket)', ''],
            ['The Summary sheet shows fill rate metrics per segment and per business category.', ''],
            ['Root causes are broken down by parent reason, sub-reason, and business category.', ''],
            ['Sheet 13 (Reason Capture Report) flags lines with missing or unclassified reasons.', ''],
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

    /** Sheet 13 – Reason capture validation and structured breakdown. */
    private function writeReasonCaptureSheet(
        Spreadsheet $ss,
        array $report,
        string $dateFrom,
        string $dateTo,
    ): void {
        $sheet = $ss->createSheet();
        $sheet->setTitle('Reason Capture Report');

        $r = 1;
        $sheet->mergeCells("A{$r}:H{$r}");
        $sheet->setCellValue("A{$r}", 'Root Cause Capture — Consolidated Report');
        $sheet->getStyle("A{$r}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E3A5F']],
        ]);
        $r++;
        $sheet->setCellValue("A{$r}", "Period: {$dateFrom} to {$dateTo}");
        $r += 2;

        $summary = $report['summary'] ?? [];
        foreach ([
            'Shortfall Lines' => $summary['total_shortfall_lines'] ?? 0,
            'Shortfall Orders' => $summary['total_shortfall_orders'] ?? 0,
            'Valid Reasons' => $summary['valid_reason_lines'] ?? 0,
            'Missing Reasons' => $summary['missing_reason_lines'] ?? 0,
            'Unclassified Reasons' => $summary['unclassified_reason_lines'] ?? 0,
            'Capture Rate' => isset($summary['capture_rate_pct']) ? $summary['capture_rate_pct'] . '%' : 'N/A',
        ] as $label => $value) {
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $value);
            $sheet->getStyle("A{$r}")->getFont()->setBold(true);
            $r++;
        }

        $r++;
        $sheet->setCellValue("A{$r}", 'Breakdown by Business Category, Parent Reason & Sub-Reason');
        $sheet->getStyle("A{$r}")->getFont()->setBold(true);
        $sheet->mergeCells("A{$r}:H{$r}");
        $r++;

        $headers = [
            'Business Category', 'Parent Reason', 'Sub-Reason', 'Line Count',
            'Order Count', 'Undershipped Value (KES)',
        ];
        foreach (range('A', 'F') as $idx => $col) {
            $sheet->setCellValue("{$col}{$r}", $headers[$idx]);
        }
        $sheet->getStyle("A{$r}:F{$r}")->getFont()->setBold(true);
        $r++;

        foreach ($report['breakdown'] ?? [] as $row) {
            $sheet->setCellValue("A{$r}", $row['business_category'] ?? '');
            $sheet->setCellValue("B{$r}", $row['parent_reason'] ?? '');
            $sheet->setCellValue("C{$r}", $row['sub_reason_label'] ?? $row['sub_reason'] ?? '');
            $sheet->setCellValue("D{$r}", $row['line_count'] ?? 0);
            $sheet->setCellValue("E{$r}", $row['order_count'] ?? 0);
            $sheet->setCellValue("F{$r}", $row['undershipped_value'] ?? 0);
            $sheet->getStyle("F{$r}")->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);
            $r++;
        }

        $r++;
        $sheet->setCellValue("A{$r}", 'Flagged Records (Missing or Unclassified Reasons)');
        $sheet->getStyle("A{$r}")->getFont()->setBold(true);
        $sheet->mergeCells("A{$r}:H{$r}");
        $r++;

        $flagHeaders = ['Order', 'Inventory ID', 'Reason Code', 'Issue', 'Business Category', 'Undershipped Value (KES)'];
        foreach (range('A', 'F') as $idx => $col) {
            $sheet->setCellValue("{$col}{$r}", $flagHeaders[$idx]);
        }
        $sheet->getStyle("A{$r}:F{$r}")->getFont()->setBold(true);
        $r++;

        foreach ($report['flagged_records'] ?? [] as $flag) {
            $sheet->setCellValue("A{$r}", $flag['order_nbr'] ?? '');
            $sheet->setCellValue("B{$r}", $flag['inventory_id'] ?? '');
            $sheet->setCellValue("C{$r}", $flag['reason_code'] ?? '—');
            $sheet->setCellValue("D{$r}", $flag['issue'] ?? '');
            $sheet->setCellValue("E{$r}", $flag['business_category'] ?? '');
            $sheet->setCellValue("F{$r}", $flag['undershipped_value'] ?? 0);
            $sheet->getStyle("F{$r}")->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);
            $r++;
        }

        $this->applyColumnSizing(
            $sheet,
            range('A', 'F'),
            count($report['flagged_records'] ?? []),
        );
    }

    // ---------------------------------------------------------------------------
    // Low-level sheet helpers (same as controller's writeSheet / writeContributionSheet)
    // ---------------------------------------------------------------------------

    /**
     * AutoSize is O(rows × cols) and is the main timeout driver on large exports.
     * Use fixed widths past a modest threshold.
     *
     * @param  list<string>  $columns
     */
    private function applyColumnSizing(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $columns, int $rowCount): void
    {
        if ($rowCount <= 500) {
            foreach ($columns as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            return;
        }

        foreach ($columns as $col) {
            $sheet->getColumnDimension($col)->setWidth(14);
        }
    }

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
            // Chunk writes — large single fromArray calls are slower and peak-memory heavy.
            $chunkSize = 1000;
            $rowOffset = 2;
            foreach (array_chunk($rows, $chunkSize) as $chunk) {
                $sheet->fromArray($chunk, null, 'A'.$rowOffset);
                $rowOffset += count($chunk);
            }
        }

        $highestColumn = $sheet->getHighestColumn();
        $sheet->getStyle("A1:{$highestColumn}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$highestColumn}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE5E7EB');
        $sheet->freezePane('A2');

        $rowCount = count($rows);
        // AutoFilter + autoSize on tens of thousands of rows is the main cause of
        // 60–100s gateway 504s. Skip both for large sheets; keep for small ones.
        if ($rowCount <= 500) {
            $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
            for ($col = 1, $max = Coordinate::columnIndexFromString($highestColumn); $col <= $max; $col++) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
            }
        } else {
            for ($col = 1, $max = Coordinate::columnIndexFromString($highestColumn); $col <= $max; $col++) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setWidth(14);
            }
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
