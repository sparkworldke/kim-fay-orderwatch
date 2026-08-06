<?php

namespace App\Services\Operations;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Enhanced multi-sheet Backorders Excel export (mirrors Fill Rate export structure).
 *
 * Sheets:
 *   1. Instructions          – how to use this file
 *   2. Summary               – KPIs, brand split, reason/top SKUs
 *   3. Backorders            – full line-level detail
 *   4. Manufactured Lines    – Kim-Fay manufactured SKUs only
 *   5. Trading (Partners) Lines – partner brands only
 *   6. Exposure by SKU       – SKU-grouped Revenue at Risk (open qty × unit price)
 *   7. Reason Summary        – root-cause contribution
 *   8. Customer Summary      – top customers by exposure
 *   9. Product Summary       – SKUs by InventoryID
 *  10. Orders with Backorders – SO-level rollup
 *  11. Missing Price Values  – lines with zero/missing unit price
 *  12. Resolved Backorders   – lines that cleared in the period, with first-backordered/resolved dates
 */
class BackorderExcelExporter
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

    /**
     * @param  array<int, array<int, mixed>>  $lineRows  Line-level detail (see build() column map)
     * @param  array<int, array<string, mixed>>  $reasonRows
     * @param  array<int, array<string, mixed>>  $customerRows
     * @param  array<int, array<string, mixed>>  $productSummaryRows
     * @param  array<int, array<string, mixed>>  $businessCategoryRows
     * @param  array{order_value?: float, invoiced_value?: float, backorder_value?: float}|null  $valueSummary
     * @param  array<int, array<int, mixed>>  $resolvedRows  Resolved-backorder history (see writeResolvedBackordersSheet() column map)
     */
    public function build(
        array $lineRows,
        array $reasonRows,
        array $customerRows,
        array $productSummaryRows,
        string $dateFrom,
        string $dateTo,
        array $businessCategoryRows = [],
        ?array $valueSummary = null,
        array $resolvedRows = [],
    ): StreamedResponse {
        $this->raiseMemoryLimit();
        $this->raiseTimeLimit();

        try {
            $spreadsheet = new Spreadsheet();
            $spreadsheet->getProperties()
                ->setCreator('Kim-Fay OrderWatch')
                ->setTitle('Backorders Export');

            $this->writeInstructionsSheet($spreadsheet);
            $this->writeSummarySheet(
                $spreadsheet,
                $lineRows,
                $reasonRows,
                $dateFrom,
                $dateTo,
                $businessCategoryRows,
                $valueSummary,
            );
            $this->writeBackordersSheet($spreadsheet, $lineRows);
            $this->writeBrandSplitSheets($spreadsheet, $lineRows);
            $this->writeExposureBySkuSheet($spreadsheet, $lineRows);
            $this->writeContributionSheet($spreadsheet, 'Reason Summary', $reasonRows, [
                'reason' => 'Reason',
                'line_count' => 'Line Count',
                'back_order_qty' => 'Backorder Qty',
                'back_order_value' => 'Revenue at Risk (KES)',
                'contribution_pct' => 'Contribution %',
            ]);
            $this->writeContributionSheet($spreadsheet, 'Customer Summary', $customerRows, [
                'customer_id' => 'Customer ID',
                'customer_name' => 'Customer Name',
                'order_count' => 'Order Count',
                'line_count' => 'Line Count',
                'back_order_value' => 'Revenue at Risk (KES)',
                'contribution_pct' => 'Contribution %',
            ]);
            $this->writeContributionSheet($spreadsheet, 'Product Summary', $productSummaryRows, [
                'inventory_id' => 'Inventory ID',
                'product_name' => 'Product Name',
                'line_count' => 'Line Count',
                'back_order_qty' => 'Backorder Qty',
                'back_order_value' => 'Revenue at Risk (KES)',
                'contribution_pct' => 'Contribution %',
            ]);
            $this->writeOrdersWithBackordersSheet($spreadsheet, $lineRows);
            $this->writeMissingPriceSheet($spreadsheet, $lineRows);
            $this->writeResolvedBackordersSheet($spreadsheet, $resolvedRows);

            $spreadsheet->setActiveSheetIndex(0);

            $filename = 'backorders-export-'.now()->format('Ymd-Hi').'.xlsx';

            return response()->streamDownload(function () use ($spreadsheet) {
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
                $spreadsheet->disconnectWorksheets();
            }, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        } catch (Throwable $e) {
            if (isset($spreadsheet)) {
                $spreadsheet->disconnectWorksheets();
            }

            throw $e;
        }
    }

    // ---------------------------------------------------------------------------
    // Sheet writers
    // ---------------------------------------------------------------------------

    /**
     * Line row layout (0-indexed):
     *  0 Order | 1 Customer ID | 2 Customer Name | 3 Inventory ID | 4 Product Name |
     *  5 Product Line | 6 Warehouse | 7 Shortfall Kind | 8 Order Status |
     *  9 Order Qty | 10 Shipped Qty | 11 Open Qty | 12 Unit Price | 13 Revenue at Risk (KES) |
     * 14 Reason Code | 15 Reason | 16 Reason Notes | 17 UOM | 18 Currency | 19 Synced At |
     * 20 Brand | 21 Fulfillment Status | 22 Qty On Hand | 23 Qty Available |
     * 24 First Backordered At | 25 Backorder Age (days) | 26 Aging Bucket | 27 Missing Reason Exception
     *
     * "Brand" (20) is the item's actual brand (e.g. "Fay Tissues") — a different, finer axis
     * than "Product Segment" (appended last), which is the coarser Manufactured/Trading split.
     */
    private function writeBackordersSheet(Spreadsheet $ss, array $rows): void
    {
        $headers = [
            'Order', 'Customer ID', 'Customer Name', 'Inventory ID', 'Product Name',
            'Product Line', 'Warehouse', 'Shortfall Kind', 'Order Status',
            'Order Qty', 'Shipped Qty', 'Open Qty', 'Unit Price', 'Revenue at Risk (KES)',
            'Reason Code', 'Reason', 'Reason Notes', 'UOM', 'Currency', 'Synced At',
            'Brand', 'Fulfillment Status', 'Qty On Hand', 'Qty Available',
            'First Backordered At', 'Backorder Age (days)', 'Aging Bucket', 'Missing Reason Exception',
            'Product Segment',
        ];

        $enriched = array_map(function (array $row) {
            $invId = (string) ($row[3] ?? '');
            $row[] = $this->classifyBrand($invId);

            return $row;
        }, $rows);

        $this->writeSheet($ss, 'Backorders', $headers, $enriched);
    }

    private function writeBrandSplitSheets(Spreadsheet $ss, array $rows): void
    {
        $headers = [
            'Order', 'Customer ID', 'Customer Name', 'Inventory ID', 'Product Name',
            'Product Line', 'Warehouse', 'Shortfall Kind', 'Order Status',
            'Order Qty', 'Shipped Qty', 'Open Qty', 'Unit Price', 'Revenue at Risk (KES)',
            'Reason Code', 'Reason', 'Reason Notes', 'UOM',
        ];

        $manufactured = [];
        $trading = [];

        foreach ($rows as $row) {
            $invId = (string) ($row[3] ?? '');
            $trimmed = array_slice($row, 0, 18);
            if ($this->businessCategory->classify($invId) === FillRateBusinessCategory::MANUFACTURED) {
                $manufactured[] = $trimmed;
            } else {
                $trading[] = $trimmed;
            }
        }

        $this->writeSheet($ss, 'Manufactured Lines', $headers, $manufactured);
        $this->writeSheet($ss, 'Trading (Partners) Lines', $headers, $trading);
    }

    private function writeExposureBySkuSheet(Spreadsheet $ss, array $lineRows): void
    {
        $sheet = $ss->createSheet();
        $sheet->setTitle('Exposure by SKU');

        $bySkuRows = [];
        $bySkuTotal = [];
        $bySkuName = [];

        foreach ($lineRows as $row) {
            $invId = (string) ($row[3] ?? '');
            $value = (float) ($row[13] ?? 0);
            if ($value <= 0) {
                continue;
            }
            $bySkuRows[$invId][] = $row;
            $bySkuTotal[$invId] = ($bySkuTotal[$invId] ?? 0) + $value;
            $bySkuName[$invId] = (string) ($row[4] ?? $invId);
        }

        if ($bySkuTotal === []) {
            $sheet->setCellValue('A1', 'No open backorder exposure in this export.');

            return;
        }

        arsort($bySkuTotal);
        $grandTotal = array_sum($bySkuTotal);

        $colHeaders = [
            'Order', 'Customer ID', 'Customer Name', 'Open Qty', 'Unit Price (KES)',
            'Revenue at Risk (KES)', 'Root Cause', 'Brand Type',
        ];
        $lastCol = 'H';

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', '★  GRAND TOTAL REVENUE AT RISK: KES '.number_format($grandTotal, 2).'  (open qty × unit price)');
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E3A5F']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        $mfgTotal = 0.0;
        $partnerTotal = 0.0;
        foreach ($bySkuRows as $invId => $skuRowList) {
            foreach ($skuRowList as $r) {
                $val = (float) ($r[13] ?? 0);
                // Prefer product_type from inventory when available (row may include brand type at end).
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
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2E5E8E']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $currentRow = 4;

        foreach ($bySkuTotal as $invId => $skuTotal) {
            $skuName = $bySkuName[$invId];
            $brand = $this->classifyBrand($invId);
            $skuLines = $bySkuRows[$invId];

            $sheet->mergeCells("A{$currentRow}:{$lastCol}{$currentRow}");
            $headerBg = $this->businessCategory->classify($invId) === FillRateBusinessCategory::MANUFACTURED
                ? 'FF0F4C81'
                : 'FF6B3A7D';
            $sheet->setCellValue("A{$currentRow}", "{$invId} — {$skuName}  |  {$brand}  |  KES ".number_format($skuTotal, 2));
            $sheet->getStyle("A{$currentRow}:{$lastCol}{$currentRow}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $headerBg]],
            ]);
            $currentRow++;

            foreach ($colHeaders as $idx => $header) {
                $col = Coordinate::stringFromColumnIndex($idx + 1);
                $sheet->setCellValue("{$col}{$currentRow}", $header);
            }
            $sheet->getStyle("A{$currentRow}:{$lastCol}{$currentRow}")->getFont()->setBold(true);
            $sheet->getStyle("A{$currentRow}:{$lastCol}{$currentRow}")->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE5E7EB');
            $currentRow++;

            $dataStartRow = $currentRow;
            foreach ($skuLines as $line) {
                $sheet->fromArray([[
                    $line[0] ?? '',
                    $line[1] ?? '',
                    $line[2] ?? '',
                    (float) ($line[11] ?? 0),
                    (float) ($line[12] ?? 0),
                    (float) ($line[13] ?? 0),
                    $line[15] ?? ($line[14] ?? 'Unassigned'),
                    $this->classifyBrand((string) ($line[3] ?? '')),
                ]], null, "A{$currentRow}");
                $sheet->getStyle("E{$currentRow}:F{$currentRow}")->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);
                $currentRow++;
            }
            $dataEndRow = $currentRow - 1;

            if ($dataEndRow >= $dataStartRow) {
                $cf = new Conditional();
                $cf->setConditionType(Conditional::CONDITION_CELLIS);
                $cf->setOperatorType(Conditional::OPERATOR_GREATERTHAN);
                $cf->addCondition('100000');
                $cf->getStyle()->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFFFD966');
                $cf->getStyle()->getFont()->setBold(true);
                $sheet->getStyle("F{$dataStartRow}:F{$dataEndRow}")->setConditionalStyles([$cf]);
            }

            $sheet->mergeCells("A{$currentRow}:D{$currentRow}");
            $sheet->setCellValue("A{$currentRow}", "Subtotal — {$invId}");
            if ($dataEndRow >= $dataStartRow) {
                $sheet->setCellValue("F{$currentRow}", "=SUM(F{$dataStartRow}:F{$dataEndRow})");
            } else {
                $sheet->setCellValue("F{$currentRow}", 0);
            }
            $sheet->getStyle("A{$currentRow}:{$lastCol}{$currentRow}")->applyFromArray([
                'font' => ['bold' => true, 'italic' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF3F4F6']],
            ]);
            $sheet->getStyle("F{$currentRow}")->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);
            $currentRow += 2;
        }

        $this->applyColumnSizing($sheet, range('A', $lastCol), array_sum(array_map('count', $bySkuRows)));
        $sheet->freezePane('A4');
    }

    private function writeOrdersWithBackordersSheet(Spreadsheet $ss, array $lineRows): void
    {
        $byOrder = [];
        foreach ($lineRows as $row) {
            $orderNbr = (string) ($row[0] ?? '');
            if ($orderNbr === '') {
                continue;
            }
            if (! isset($byOrder[$orderNbr])) {
                $byOrder[$orderNbr] = [
                    'order' => $orderNbr,
                    'customer_id' => $row[1] ?? '',
                    'customer_name' => $row[2] ?? '',
                    'status' => $row[8] ?? '',
                    'line_count' => 0,
                    'open_qty' => 0.0,
                    'revenue_at_risk' => 0.0,
                ];
            }
            $byOrder[$orderNbr]['line_count']++;
            $byOrder[$orderNbr]['open_qty'] += (float) ($row[11] ?? 0);
            $byOrder[$orderNbr]['revenue_at_risk'] += (float) ($row[13] ?? 0);
        }

        uasort($byOrder, fn ($a, $b) => $b['revenue_at_risk'] <=> $a['revenue_at_risk']);

        $rows = array_map(fn (array $o) => [
            $o['order'],
            $o['customer_id'],
            $o['customer_name'],
            $o['status'],
            $o['line_count'],
            round($o['open_qty'], 4),
            round($o['revenue_at_risk'], 2),
        ], array_values($byOrder));

        $this->writeSheet($ss, 'Orders with Backorders', [
            'Order', 'Customer ID', 'Customer Name', 'Order Status',
            'Backorder Lines', 'Open Qty', 'Revenue at Risk (KES)',
        ], $rows);
    }

    private function writeMissingPriceSheet(Spreadsheet $ss, array $lineRows): void
    {
        $missingRows = [];
        foreach ($lineRows as $row) {
            $unitPrice = (float) ($row[12] ?? 0);
            if ($unitPrice > 0) {
                continue;
            }
            $missingRows[] = [
                $row[0] ?? '',
                $row[1] ?? '',
                $row[3] ?? '',
                $row[4] ?? '',
                (float) ($row[11] ?? 0),
                $row[14] ?? '',
                'MISSING PRICE',
            ];
        }

        $this->writeSheet($ss, 'Missing Price Values', [
            'Order', 'Customer ID', 'Inventory ID', 'Product Name',
            'Open Qty', 'Reason Code', 'Flag',
        ], $missingRows);
    }

    /**
     * Resolved-backorder history (backorder_resolutions table) — lines that cleared and
     * were archived out of the active list. `first_backordered_at` and `resolved_at` are
     * independent columns; a line spanning two months is not forced into one "owning" month.
     *
     * Row layout (0-indexed): 0 Order | 1 Customer ID | 2 Customer Name | 3 Inventory ID |
     * 4 Product Name | 5 Brand | 6 Reason | 7 Unit Price | 8 Value | 9 First Backordered At |
     * 10 Resolved At | 11 Days To Resolve
     */
    private function writeResolvedBackordersSheet(Spreadsheet $ss, array $rows): void
    {
        $this->writeSheet($ss, 'Resolved Backorders', [
            'Order', 'Customer ID', 'Customer Name', 'Inventory ID', 'Product Name', 'Brand',
            'Reason', 'Unit Price', 'Revenue at Risk (KES)', 'First Backordered At', 'Resolved At', 'Days To Resolve',
        ], $rows);
    }

    private function writeSummarySheet(
        Spreadsheet $ss,
        array $lineRows,
        array $reasonRows,
        string $dateFrom,
        string $dateTo,
        array $businessCategoryRows = [],
        ?array $valueSummary = null,
    ): void {
        $sheet = $ss->getSheetCount() === 1
            && $ss->getActiveSheet()->getHighestRow() === 1
            && $ss->getActiveSheet()->getCell('A1')->getValue() === null
            ? $ss->getActiveSheet()
            : $ss->createSheet();
        $sheet->setTitle('Summary');

        $grandTotal = 0.0;
        $openQty = 0.0;
        $bySkuTotal = [];
        $orderIds = [];
        $mfgTotal = 0.0;
        $partnerTotal = 0.0;
        $missingPrice = 0;
        $unassignedReasons = 0;

        foreach ($lineRows as $row) {
            $invId = (string) ($row[3] ?? '');
            $value = (float) ($row[13] ?? 0);
            $qty = (float) ($row[11] ?? 0);
            $price = (float) ($row[12] ?? 0);
            $reasonCode = trim((string) ($row[14] ?? ''));

            $grandTotal += $value;
            $openQty += $qty;
            $orderIds[(string) ($row[0] ?? '')] = true;
            $bySkuTotal[$invId] = ($bySkuTotal[$invId] ?? 0) + $value;

            if ($this->businessCategory->classify($invId) === FillRateBusinessCategory::MANUFACTURED) {
                $mfgTotal += $value;
            } else {
                $partnerTotal += $value;
            }
            if ($price <= 0) {
                $missingPrice++;
            }
            if ($reasonCode === '' || strcasecmp($reasonCode, 'unassigned') === 0) {
                $unassignedReasons++;
            }
        }

        arsort($bySkuTotal);
        $top5 = array_slice($bySkuTotal, 0, 5, true);
        $orderCount = count(array_filter(array_keys($orderIds)));

        $r = 1;
        $sheet->mergeCells("A{$r}:D{$r}");
        $sheet->setCellValue("A{$r}", 'Backorders — Revenue at Risk Summary');
        $sheet->getStyle("A{$r}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E3A5F']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getRowDimension($r)->setRowHeight(30);
        $r++;

        $sheet->setCellValue("A{$r}", "Period: {$dateFrom} to {$dateTo}  (sales order date)  ·  Revenue at Risk (RaR) = open qty × unit price");
        $sheet->getStyle("A{$r}")->getFont()->setItalic(true)->setColor(new Color('FF555555'));
        $r += 2;

        $kpiRows = [
            ['Total Revenue at Risk (KES)', 'KES '.number_format($grandTotal, 2), 'FFDC2626'],
            ['  Formula', 'open qty × unit price (unshipped remainder)', 'FF6B7280'],
            ['Manufactured Goods — Revenue at Risk', 'KES '.number_format($mfgTotal, 2), 'FF0F4C81'],
            ['Trading (Partners) — Revenue at Risk', 'KES '.number_format($partnerTotal, 2), 'FF6B3A7D'],
            ['Open Lines', (string) count($lineRows), 'FF0369A1'],
            ['Open Orders', (string) $orderCount, 'FF0369A1'],
            ['Open Qty', number_format($openQty, 2), 'FF0369A1'],
            ['SKUs Affected', (string) count($bySkuTotal), 'FF0369A1'],
            ['Lines w/ Missing Price', (string) $missingPrice, 'FFF59E0B'],
            ['Lines w/ Unassigned Reason', (string) $unassignedReasons, 'FFF59E0B'],
        ];

        if ($valueSummary !== null) {
            $kpiRows = array_merge([
                ['Order Value (period)', 'KES '.number_format((float) ($valueSummary['order_value'] ?? 0), 2), 'FF1E3A5F'],
                ['Invoiced Value (period)', 'KES '.number_format((float) ($valueSummary['invoiced_value'] ?? 0), 2), 'FF15803D'],
                ['Backorder Value / Revenue at Risk (period SO lines)', 'KES '.number_format((float) ($valueSummary['backorder_value'] ?? 0), 2), 'FFDC2626'],
            ], $kpiRows);
        }

        foreach ($kpiRows as [$label, $val, $color]) {
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $val);
            $sheet->getStyle("A{$r}")->getFont()->setBold(true);
            $sheet->getStyle("B{$r}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => $color]],
            ]);
            $r++;
        }

        $r++;

        if ($businessCategoryRows !== []) {
            $sheet->mergeCells("A{$r}:D{$r}");
            $sheet->setCellValue("A{$r}", 'Manufactured vs Trading (Partners) — Business Category Split');
            $sheet->getStyle("A{$r}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 12],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE5E7EB']],
            ]);
            $r++;

            $catHeaders = ['Category', 'Lines', 'Orders', 'Open Qty', 'Revenue at Risk (KES)'];
            foreach (['A', 'B', 'C', 'D', 'E'] as $idx => $col) {
                $sheet->setCellValue("{$col}{$r}", $catHeaders[$idx]);
            }
            $sheet->getStyle("A{$r}:E{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}:E{$r}")->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE5E7EB');
            $r++;

            foreach ($businessCategoryRows as $cat) {
                $sheet->setCellValue("A{$r}", $cat['label'] ?? $cat['business_category'] ?? '');
                $sheet->setCellValue("B{$r}", $cat['line_count'] ?? 0);
                $sheet->setCellValue("C{$r}", $cat['order_count'] ?? 0);
                $sheet->setCellValue("D{$r}", $cat['open_qty'] ?? $cat['back_order_qty'] ?? 0);
                $sheet->setCellValue("E{$r}", $cat['back_order_value'] ?? $cat['undershipped_value'] ?? 0);
                $sheet->getStyle("E{$r}")->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);
                $r++;
            }
            $r++;
        }

        // Top 5 reasons
        $sheet->mergeCells("A{$r}:D{$r}");
        $sheet->setCellValue("A{$r}", 'Top Root Causes by Revenue at Risk');
        $sheet->getStyle("A{$r}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE5E7EB']],
        ]);
        $r++;
        foreach (['A', 'B', 'C', 'D'] as $idx => $col) {
            $sheet->setCellValue("{$col}{$r}", ['Reason', 'Lines', 'Revenue at Risk (KES)', 'Contribution %'][$idx]);
        }
        $sheet->getStyle("A{$r}:D{$r}")->getFont()->setBold(true);
        $r++;
        foreach (array_slice($reasonRows, 0, 10) as $reason) {
            $sheet->setCellValue("A{$r}", $reason['reason'] ?? 'Unassigned');
            $sheet->setCellValue("B{$r}", $reason['line_count'] ?? 0);
            $sheet->setCellValue("C{$r}", $reason['back_order_value'] ?? 0);
            $sheet->setCellValue("D{$r}", ($reason['contribution_pct'] ?? 0).'%');
            $sheet->getStyle("C{$r}")->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);
            $r++;
        }
        $r++;

        // Top 5 SKUs
        $sheet->mergeCells("A{$r}:C{$r}");
        $sheet->setCellValue("A{$r}", 'Top 5 SKUs by Revenue at Risk');
        $sheet->getStyle("A{$r}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE5E7EB']],
        ]);
        $r++;
        foreach (['A', 'B', 'C'] as $idx => $col) {
            $sheet->setCellValue("{$col}{$r}", ['Inventory ID', 'Brand Type', 'Revenue at Risk (KES)'][$idx]);
        }
        $sheet->getStyle("A{$r}:C{$r}")->getFont()->setBold(true);
        $r++;
        foreach ($top5 as $invId => $value) {
            $sheet->setCellValue("A{$r}", $invId);
            $sheet->setCellValue("B{$r}", $this->classifyBrand((string) $invId));
            $sheet->setCellValue("C{$r}", $value);
            $sheet->getStyle("C{$r}")->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);
            $r++;
        }

        $this->applyColumnSizing($sheet, range('A', 'E'), 40);
        $sheet->getColumnDimension('A')->setWidth(36);
        $sheet->getColumnDimension('B')->setWidth(22);
    }

    private function writeInstructionsSheet(Spreadsheet $ss): void
    {
        $sheet = $ss->getSheetCount() === 1
            && $ss->getActiveSheet()->getHighestRow() === 1
            && $ss->getActiveSheet()->getCell('A1')->getValue() === null
            ? $ss->getActiveSheet()
            : $ss->createSheet();

        $sheet->setTitle('Instructions');

        $lines = [
            ['Backorders Export — How to Use This File', 'title'],
            ['', ''],
            ['This workbook is structured like the Fill Rate export. Guide to each sheet:', 'heading'],
            ['', ''],
            ['1. Instructions (this sheet)', 'bold'],
            ['   Guide to every sheet, plus Manufactured vs Trading classification rules.', ''],
            ['', ''],
            ['2. Summary', 'bold'],
            ['   Executive dashboard: Total Revenue at Risk (RaR), Manufactured vs Trading split,', ''],
            ['   optional Order / Invoice / Backorder period totals (sales order date),', ''],
            ['   root cause top list, top 5 SKUs by Revenue at Risk, data-quality counts.', ''],
            ['', ''],
            ['3. Backorders', 'bold'],
            ['   Full line-level detail for every open/shortfall line in the filter set.', ''],
            ['   Includes SO status, open qty, unit price, Revenue at Risk (KES), reason, Brand,', ''],
            ['   fulfillment status, live qty on hand/available, backorder age + aging bucket,', ''],
            ['   a missing-reason-exception flag, and Product Segment (Manufactured/Trading).', ''],
            ['   Brand and Product Segment are different axes — see Business Category note below.', ''],
            ['', ''],
            ['4. Manufactured Lines', 'bold'],
            ['   Filtered view of Kim-Fay manufactured product lines only.', ''],
            ['', ''],
            ['5. Trading (Partners) Lines', 'bold'],
            ['   Filtered view of partner / third-party brand lines only.', ''],
            ['', ''],
            ['6. Exposure by SKU', 'bold'],
            ['   SKU-by-SKU breakdown of Revenue at Risk. Each SKU has transaction rows + subtotal.', ''],
            ['   Conditional formatting: gold cells = Revenue at Risk > KES 100,000.', ''],
            ['', ''],
            ['7. Reason Summary', 'bold'],
            ['   Root cause contribution — share of total Revenue at Risk by reason.', ''],
            ['', ''],
            ['8. Customer Summary', 'bold'],
            ['   Customers ranked by Revenue at Risk.', ''],
            ['', ''],
            ['9. Product Summary', 'bold'],
            ['   All SKUs grouped by Inventory ID, sorted by Revenue at Risk.', ''],
            ['', ''],
            ['10. Orders with Backorders', 'bold'],
            ['   Sales-order rollup: line count, open qty, and total Revenue at Risk per SO.', ''],
            ['', ''],
            ['11. Missing Price Values', 'bold'],
            ['   Data-quality check: lines with zero or missing unit price (cannot compute Revenue at Risk).', ''],
            ['', ''],
            ['12. Resolved Backorders', 'bold'],
            ['   Lines that cleared (shipped, or their order completed) in the filter period.', ''],
            ['   First Backordered At and Resolved At are independent dates — a line spanning two', ''],
            ['   months is not forced into a single "owning" month.', ''],
            ['', ''],
            ['How values are calculated', 'heading'],
            ['Revenue at Risk (RaR) — full name for the metric sometimes abbreviated “RaR”.', ''],
            ['Revenue at Risk (line) = open qty × unit price (unshipped remainder).', ''],
            ['Never use order total or invoice total as Revenue at Risk.', ''],
            ['Order / Invoice / Backorder cards (when present) use sales order date for the period:', ''],
            ['   Order value = ordered qty × unit price', ''],
            ['   Invoiced value = delivered qty × unit price (capped at ordered)', ''],
            ['   Backorder value / Revenue at Risk = residual open qty × unit price', ''],
            ['Aging bucket = days between First Backordered At and today: 0-7 / 8-14 / 15-30 / 30+.', ''],
            ['Missing Reason Exception = aging bucket exceeds the configured threshold (default 7 days)', ''],
            ['   with no reason assigned — a data-quality flag, not a new metric.', ''],
            ['', ''],
            ['Business Category Classification', 'heading'],
            ['Brand (e.g. "Fay Tissues") is the item\'s actual brand — the canonical display classification.', ''],
            ['Product Segment (Manufactured vs Trading) is a separate, coarser axis used for value splits:', ''],
            ['Manufactured (Kim-Fay): FAY, SIF, COS, TIS, ULT, STD, SHO, ANT, URI, TOI, AIR, ALK, DIS, …', ''],
            ['Trading (Partners): DOV, REX, LUX, HUG, KOT, COW, APT, and other partner prefixes.', ''],
            ['', ''],
            ['Generated by Kim-Fay OrderWatch', 'italic'],
        ];

        $r = 1;
        foreach ($lines as [$text, $style]) {
            $sheet->setCellValue("A{$r}", $text);
            if ($style === 'title') {
                $sheet->getStyle("A{$r}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 16, 'color' => ['argb' => 'FFFFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E3A5F']],
                ]);
                $sheet->getRowDimension($r)->setRowHeight(28);
            } elseif ($style === 'heading') {
                $sheet->getStyle("A{$r}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 12],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE5E7EB']],
                ]);
            } elseif ($style === 'bold') {
                $sheet->getStyle("A{$r}")->getFont()->setBold(true);
            } elseif ($style === 'italic') {
                $sheet->getStyle("A{$r}")->getFont()->setItalic(true)->setColor(new Color('FF6B7280'));
            }
            $r++;
        }

        $sheet->getColumnDimension('A')->setWidth(110);
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    private function raiseMemoryLimit(): void
    {
        $target = '1024M';
        $current = ini_get('memory_limit');
        if ($current === false || $current === '-1') {
            return;
        }
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

    /** @param  list<string>  $columns */
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
            $rowOffset = 2;
            foreach (array_chunk($rows, 1000) as $chunk) {
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
