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
 * Multi-sheet Backorders Excel — readable for executives and every ops/sales role.
 *
 * Sheets (open in order):
 *   1. Start Here            – who should use which sheet + three questions
 *   2. Summary               – Revenue at Risk, ready to release, blocked, splits
 *   3. Backorders            – line detail + stock coverage, owner, next action
 *   4. Manufactured Lines    – Kim-Fay manufactured only
 *   5. Trading (Partners)    – partner brands only
 *   6. Exposure by SKU       – SKU groups (ops deep-dive)
 *   7. Reason Summary        – root causes
 *   8. Customer Summary      – customers by exposure (sales/CS)
 *   9. Product Summary       – SKUs ranked
 *  10. Orders with Backorders
 *  11. Missing Price Values
 *  12. Resolved Backorders
 *
 * Revenue at Risk (headline) = open qty × unit price on active lines.
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
     * Line row layout (0-indexed) from the API:
     *  0 Order | 1 Customer ID | 2 Customer Name | 3 Inventory ID | 4 Product Name |
     *  5 Product Line | 6 Warehouse | 7 Shortfall Kind | 8 Order Status |
     *  9 Order Qty | 10 Shipped Qty | 11 Open Qty | 12 Unit Price | 13 Revenue at Risk (KES) |
     * 14 Reason Code | 15 Reason | 16 Reason Notes | 17 UOM | 18 Currency | 19 Synced At |
     * 20 Brand | 21 Fulfillment Status | 22 Qty On Hand | 23 Qty Available |
     * 24 First Backordered At | 25 Backorder Age (days) | 26 Aging Bucket | 27 Missing Reason Exception
     *
     * Export appends: Product Segment | Stock Coverage | Owner | Next Action
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
            'Product Segment', 'Stock Coverage', 'Owner (who acts)', 'Next Action',
        ];

        $enriched = array_map(function (array $row) {
            $invId = (string) ($row[3] ?? '');
            $segment = $this->classifyBrand($invId);
            $openQty = (float) ($row[11] ?? 0);
            $qtyAvailable = (float) ($row[23] ?? 0);
            $reasonCode = trim((string) ($row[14] ?? ''));
            $coverage = $this->stockCoverage($openQty, $qtyAvailable);
            [$owner, $action] = $this->ownerAndNextAction($coverage, $invId, $reasonCode);

            $row[] = $segment;
            $row[] = $coverage;
            $row[] = $owner;
            $row[] = $action;

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
        $readyKes = 0.0;
        $blockedKes = 0.0;
        $readyLines = 0;
        $blockedLines = 0;

        foreach ($lineRows as $row) {
            $invId = (string) ($row[3] ?? '');
            $value = (float) ($row[13] ?? 0);
            $qty = (float) ($row[11] ?? 0);
            $price = (float) ($row[12] ?? 0);
            $reasonCode = trim((string) ($row[14] ?? ''));
            $qtyAvailable = (float) ($row[23] ?? 0);

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

            $coverage = $this->stockCoverage($qty, $qtyAvailable);
            if ($coverage === 'None') {
                $blockedKes += $value;
                $blockedLines++;
            } else {
                // Full or partial free stock — can act to release something now.
                $readyKes += $value;
                $readyLines++;
            }
        }

        arsort($bySkuTotal);
        $top5 = array_slice($bySkuTotal, 0, 5, true);
        $orderCount = count(array_filter(array_keys($orderIds)));

        $r = 1;
        $sheet->mergeCells("A{$r}:D{$r}");
        $sheet->setCellValue("A{$r}", 'Backorders — Decision Summary (all roles)');
        $sheet->getStyle("A{$r}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E3A5F']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getRowDimension($r)->setRowHeight(30);
        $r++;

        $sheet->setCellValue("A{$r}", "Period: {$dateFrom} to {$dateTo}  (sales order date)  ·  Filtered to your access + on-screen filters");
        $sheet->getStyle("A{$r}")->getFont()->setItalic(true)->setColor(new Color('FF555555'));
        $r++;
        $sheet->setCellValue("A{$r}", 'Revenue at Risk = open (unshipped) qty × unit price. Not order total. Not cash collected.');
        $sheet->getStyle("A{$r}")->getFont()->setItalic(true)->setColor(new Color('FF555555'));
        $r += 2;

        // Three decision KPIs first (exec + every team).
        $kpiRows = [
            ['1. REVENUE AT RISK (total exposure)', 'KES '.number_format($grandTotal, 2), 'FFDC2626'],
            ['2. READY TO RELEASE (stock available or partial)', 'KES '.number_format($readyKes, 2).'  ·  '.$readyLines.' lines', 'FF15803D'],
            ['3. BLOCKED — NO STOCK', 'KES '.number_format($blockedKes, 2).'  ·  '.$blockedLines.' lines', 'FFB45309'],
            ['', '', 'FF6B7280'],
            ['Manufactured (Kim-Fay) — of total RaR', 'KES '.number_format($mfgTotal, 2), 'FF0F4C81'],
            ['Trading / Partners — of total RaR', 'KES '.number_format($partnerTotal, 2), 'FF6B3A7D'],
            ['Open lines / open orders / SKUs', count($lineRows).' lines · '.$orderCount.' orders · '.count($bySkuTotal).' SKUs', 'FF0369A1'],
            ['Open quantity (units)', number_format($openQty, 2), 'FF0369A1'],
            ['Data quality — missing unit price', (string) $missingPrice.' lines (see Missing Price Values sheet)', 'FFF59E0B'],
            ['Data quality — no root-cause reason', (string) $unassignedReasons.' lines (Sales/CS should set reason)', 'FFF59E0B'],
        ];

        if ($valueSummary !== null) {
            $kpiRows[] = ['', '', 'FF6B7280'];
            $kpiRows[] = ['Context — ordered value in period (not RaR)', 'KES '.number_format((float) ($valueSummary['order_value'] ?? 0), 2), 'FF1E3A5F'];
            $kpiRows[] = ['Context — invoiced / delivered value in period', 'KES '.number_format((float) ($valueSummary['invoiced_value'] ?? 0), 2), 'FF15803D'];
        }

        $sheet->setCellValue("A{$r}", 'What matters (read these three first)');
        $sheet->getStyle("A{$r}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE5E7EB']],
        ]);
        $r++;

        foreach ($kpiRows as [$label, $val, $color]) {
            if ($label === '' && $val === '') {
                $r++;
                continue;
            }
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $val);
            $sheet->getStyle("A{$r}")->getFont()->setBold(true);
            $sheet->getStyle("B{$r}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => $color]],
            ]);
            $r++;
        }

        $r++;
        $sheet->setCellValue("A{$r}", 'Who does what next');
        $sheet->getStyle("A{$r}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE5E7EB']],
        ]);
        $r++;
        $sheet->setCellValue("A{$r}", 'Executives / HODs');
        $sheet->setCellValue("B{$r}", 'Use this Summary + Customer Summary. Drill only if escalating.');
        $r++;
        $sheet->setCellValue("A{$r}", 'Sales / Customer Service');
        $sheet->setCellValue("B{$r}", 'Customer Summary + Backorders (filter your customers). Call customers; set missing reasons.');
        $r++;
        $sheet->setCellValue("A{$r}", 'Warehouse / CS ops');
        $sheet->setCellValue("B{$r}", 'Backorders where Stock Coverage = Full or Partial → release / ship / transfer.');
        $r++;
        $sheet->setCellValue("A{$r}", 'Production');
        $sheet->setCellValue("B{$r}", 'Manufactured Lines where Stock Coverage = None.');
        $r++;
        $sheet->setCellValue("A{$r}", 'Procurement / Partner Brands');
        $sheet->setCellValue("B{$r}", 'Trading (Partners) Lines where Stock Coverage = None.');
        $r += 2;

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

        $sheet->setTitle('Start Here');

        $lines = [
            ['Backorders export — for executives and every team', 'title'],
            ['', ''],
            ['Three questions this file answers', 'heading'],
            ['1. How much money is stuck?  →  Summary: Revenue at Risk', ''],
            ['2. What can we ship or release now?  →  Summary: Ready to release · Backorders where Stock Coverage is Full/Partial', ''],
            ['3. What is blocked and who acts?  →  Stock Coverage = None · Owner and Next Action columns', ''],
            ['', ''],
            ['Who should open which sheet', 'heading'],
            ['Role', 'bold'],
            ['Executives / C-suite / HODs', 'bold'],
            ['   Open: Summary only. Optional: Customer Summary for escalation. Skip line dumps unless needed.', ''],
            ['Sales consultants / Customer Service', 'bold'],
            ['   Open: Customer Summary, then Backorders (your customers). Use Next Action = Call customer / Set reason.', ''],
            ['Warehouse / Dispatch / CS ops', 'bold'],
            ['   Open: Backorders. Filter Stock Coverage = Full or Partial. Next Action = Release / transfer.', ''],
            ['Production', 'bold'],
            ['   Open: Manufactured Lines. Focus Stock Coverage = None. Next Action = Produce.', ''],
            ['Procurement / Partner Brands', 'bold'],
            ['   Open: Trading (Partners) Lines. Focus Stock Coverage = None. Next Action = Procure.', ''],
            ['All teams', 'bold'],
            ['   Missing Price Values and unassigned reasons = data quality — fix so RaR and owners stay honest.', ''],
            ['', ''],
            ['Sheet guide', 'heading'],
            ['1. Start Here (this sheet)', 'bold'],
            ['   Audience map and definitions.', ''],
            ['2. Summary', 'bold'],
            ['   Decision dashboard: total RaR, ready to release, blocked (no stock), Manufactured vs Trading,', ''],
            ['   top root causes, top SKUs. Start here every time.', ''],
            ['3. Backorders', 'bold'],
            ['   Every open line: order, customer, SKU, open qty, RaR, reason, stock on hand/available,', ''],
            ['   Stock Coverage (Full / Partial / None), Owner, Next Action, age.', ''],
            ['4. Manufactured Lines', 'bold'],
            ['   Kim-Fay manufactured products only (Production focus).', ''],
            ['5. Trading (Partners) Lines', 'bold'],
            ['   Partner brands only — Dove, Lux, Rexona, Huggies, etc. (Procurement / Partner Brands).', ''],
            ['6. Exposure by SKU', 'bold'],
            ['   Same RaR rolled up under each SKU with order rows (ops deep-dive). Gold = RaR > KES 100,000.', ''],
            ['7. Reason Summary', 'bold'],
            ['   Why stock is short — share of RaR by root cause.', ''],
            ['8. Customer Summary', 'bold'],
            ['   Which customers are most exposed (sales & CS call list).', ''],
            ['9. Product Summary', 'bold'],
            ['   SKUs ranked by RaR.', ''],
            ['10. Orders with Backorders', 'bold'],
            ['   One row per sales order with open shortfall.', ''],
            ['11. Missing Price Values', 'bold'],
            ['   Lines with no unit price — RaR cannot be trusted until priced.', ''],
            ['12. Resolved Backorders', 'bold'],
            ['   Cleared in the filter period (shipped / completed) — for recovery tracking.', ''],
            ['', ''],
            ['Plain-language definitions', 'heading'],
            ['Revenue at Risk (RaR)', 'bold'],
            ['   Money still committed on the order that has not shipped: open qty × unit price.', ''],
            ['   Do NOT treat full order value or invoice total as RaR.', ''],
            ['Stock Coverage', 'bold'],
            ['   Full = available stock can cover open qty. Partial = some stock. None = nothing free to release.', ''],
            ['Owner / Next Action', 'bold'],
            ['   Suggested function and step so every row has a clear owner (not a blame score).', ''],
            ['Manufactured vs Trading', 'bold'],
            ['   Manufactured = Kim-Fay made (Fay, Sifa, Cosy…). Trading = partner brands (Dove, Lux, Rexona…).', ''],
            ['Aging', 'bold'],
            ['   Days since first backordered: 0-7 / 8-14 / 15-30 / 30+ days.', ''],
            ['', ''],
            ['Scope note', 'heading'],
            ['This file respects the filters you set in Sight and your login access (team / portfolio).', ''],
            ['A sales consultant export is their book only. An executive export can be company-wide.', ''],
            ['', ''],
            ['Generated by Kim-Fay Sight (OrderWatch)', 'italic'],
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
    // Helpers — coverage / ownership (same rules for Summary + line sheet)
    // ---------------------------------------------------------------------------

    private function stockCoverage(float $openQty, float $qtyAvailable): string
    {
        if ($openQty <= 0) {
            return 'N/A';
        }
        if ($qtyAvailable <= 0) {
            return 'None';
        }
        if ($qtyAvailable + 0.0001 >= $openQty) {
            return 'Full';
        }

        return 'Partial';
    }

    /**
     * @return array{0: string, 1: string} [owner, next action]
     */
    private function ownerAndNextAction(string $coverage, string $inventoryId, string $reasonCode): array
    {
        $unassigned = $reasonCode === '' || strcasecmp($reasonCode, 'unassigned') === 0;
        if ($unassigned) {
            return ['Sales / Customer Service', 'Set root-cause reason on the line'];
        }

        $manufactured = $this->businessCategory->classify($inventoryId) === FillRateBusinessCategory::MANUFACTURED;

        return match ($coverage) {
            'Full' => ['Warehouse / Dispatch', 'Release stock — create / confirm shipment'],
            'Partial' => ['Warehouse / Dispatch', 'Partial release or transfer stock from another warehouse'],
            'None' => $manufactured
                ? ['Production', 'Produce / prioritise manufacturing for this SKU']
                : ['Procurement / Partner supply', 'Procure or chase partner supply'],
            default => ['Operations', 'Review line with Supply and Sales'],
        };
    }

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
