<?php

namespace App\Services\Email\Extractors;

use Throwable;
use ZipArchive;

class ExcelTextExtractor
{
    private const SUPPORTED = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'application/excel',
        'application/x-excel',
        'application/x-msexcel',
    ];

    /**
     * Extract cell text from XLSX/XLS bytes.
     * XLSX is a ZIP containing XML — parse it without PhpSpreadsheet.
     */
    public function extract(string $bytes, string $contentType): ?string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'ow_excel_') . '.xlsx';

        try {
            file_put_contents($tmpFile, $bytes);

            $zip = new ZipArchive();
            if ($zip->open($tmpFile) !== true) {
                return $this->extractFromCsv($bytes);
            }

            // Read shared strings (required for text cells in XLSX)
            $sharedStrings = [];
            $ssXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($ssXml !== false) {
                $xml = @simplexml_load_string($ssXml);
                if ($xml) {
                    foreach ($xml->si as $si) {
                        $sharedStrings[] = (string) $si->t;
                    }
                }
            }

            $lines = [];

            // Parse all sheets
            $i = 0;
            while (true) {
                $sheetPath = 'xl/worksheets/sheet' . (++$i) . '.xml';
                $sheetXml  = $zip->getFromName($sheetPath);
                if ($sheetXml === false) break;

                $xml = @simplexml_load_string($sheetXml);
                if (! $xml) continue;

                foreach ($xml->sheetData->row ?? [] as $row) {
                    $rowValues = [];
                    foreach ($row->c as $cell) {
                        $type = (string) ($cell['t'] ?? '');
                        $v    = (string) ($cell->v ?? '');

                        $value = $type === 's' && isset($sharedStrings[(int) $v])
                            ? $sharedStrings[(int) $v]
                            : $v;

                        if ($value !== '') {
                            $rowValues[] = $value;
                        }
                    }
                    if ($rowValues) {
                        $lines[] = implode(' | ', $rowValues);
                    }
                }
            }

            $zip->close();

            return $lines ? implode("\n", $lines) : null;
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($tmpFile);
        }
    }

    public function supports(string $contentType): bool
    {
        return in_array(strtolower($contentType), self::SUPPORTED, true)
            || str_contains(strtolower($contentType), 'excel')
            || str_contains(strtolower($contentType), 'spreadsheet');
    }

    private function extractFromCsv(string $bytes): ?string
    {
        $lines = array_filter(array_map('trim', explode("\n", $bytes)));
        return $lines ? implode("\n", array_slice($lines, 0, 200)) : null;
    }
}
