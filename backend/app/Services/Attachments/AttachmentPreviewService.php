<?php

namespace App\Services\Attachments;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Turn attachment bytes into a dashboard-friendly preview payload.
 *
 * kinds: image | pdf | table | text | binary
 */
class AttachmentPreviewService
{
    /**
     * @return array{
     *   kind: string,
     *   name: string,
     *   mime: string|null,
     *   size: int,
     *   sheets?: list<array{name: string, headers: list<string>, rows: list<list<string>>}>,
     *   text?: string,
     *   message?: string
     * }
     */
    public function preview(
        string $bytes,
        string $name,
        ?string $mime,
        int $size = 0,
    ): array {
        $mime = strtolower((string) ($mime ?: $this->guessMime($name)));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        $base = [
            'kind' => 'binary',
            'name' => $name,
            'mime' => $mime,
            'size' => $size > 0 ? $size : strlen($bytes),
        ];

        if ($this->isImage($mime, $ext)) {
            return [...$base, 'kind' => 'image'];
        }

        if ($this->isPdf($mime, $ext)) {
            return [...$base, 'kind' => 'pdf'];
        }

        if ($this->isCsv($mime, $ext) || $this->isExcel($mime, $ext)) {
            try {
                $sheets = $this->parseTabular($bytes, $name, $mime, $ext);

                return [...$base, 'kind' => 'table', 'sheets' => $sheets];
            } catch (Throwable $e) {
                return [
                    ...$base,
                    'kind' => 'text',
                    'text' => mb_substr($bytes, 0, 20000),
                    'message' => 'Could not parse as table: '.$e->getMessage(),
                ];
            }
        }

        if (str_starts_with($mime, 'text/') || in_array($ext, ['txt', 'json', 'xml', 'log'], true)) {
            return [
                ...$base,
                'kind' => 'text',
                'text' => mb_substr($bytes, 0, 100000),
            ];
        }

        return [
            ...$base,
            'kind' => 'binary',
            'message' => 'Preview not available for this file type. Download to open locally.',
        ];
    }

    /**
     * @return list<array{name: string, headers: list<string>, rows: list<list<string>}>
     */
    private function parseTabular(string $bytes, string $name, string $mime, string $ext): array
    {
        if ($this->isCsv($mime, $ext)) {
            return [$this->parseCsv($bytes, $name)];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ow_att_');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to create temp file for spreadsheet preview.');
        }

        $path = $tmp.'.'.($ext !== '' ? $ext : 'xlsx');
        @rename($tmp, $path);
        file_put_contents($path, $bytes);

        try {
            $spreadsheet = IOFactory::load($path);
            $sheets = [];
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $matrix = $sheet->toArray(null, true, true, false);
                $matrix = array_values(array_filter(
                    $matrix,
                    static fn ($row) => is_array($row) && collect($row)->contains(
                        static fn ($cell) => $cell !== null && trim((string) $cell) !== '',
                    ),
                ));

                if ($matrix === []) {
                    $sheets[] = [
                        'name' => $sheet->getTitle(),
                        'headers' => [],
                        'rows' => [],
                    ];
                    continue;
                }

                $headerRow = array_map(
                    static fn ($v) => trim((string) ($v ?? '')),
                    $matrix[0],
                );
                // If header row is empty, invent columns
                if (! collect($headerRow)->contains(fn ($h) => $h !== '')) {
                    $width = max(array_map('count', $matrix));
                    $headerRow = array_map(static fn ($i) => 'Col '.($i + 1), range(0, $width - 1));
                    $dataRows = $matrix;
                } else {
                    $dataRows = array_slice($matrix, 1);
                }

                $colCount = count($headerRow);
                $rows = [];
                foreach ($dataRows as $row) {
                    $cells = array_map(static fn ($v) => $v === null ? '' : (string) $v, array_values($row));
                    // Pad / trim to header width
                    if (count($cells) < $colCount) {
                        $cells = array_pad($cells, $colCount, '');
                    } elseif (count($cells) > $colCount) {
                        $cells = array_slice($cells, 0, $colCount);
                    }
                    // Skip fully empty
                    if (! collect($cells)->contains(fn ($c) => trim($c) !== '')) {
                        continue;
                    }
                    $rows[] = $cells;
                    if (count($rows) >= 500) {
                        break;
                    }
                }

                $sheets[] = [
                    'name' => $sheet->getTitle(),
                    'headers' => $headerRow,
                    'rows' => $rows,
                ];
            }

            return $sheets !== [] ? $sheets : [[
                'name' => 'Sheet1',
                'headers' => [],
                'rows' => [],
            ]];
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return array{name: string, headers: list<string>, rows: list<list<string>>}
     */
    private function parseCsv(string $bytes, string $name): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $bytes) ?: [];
        $matrix = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $matrix[] = str_getcsv($line);
            if (count($matrix) >= 501) {
                break;
            }
        }

        if ($matrix === []) {
            return ['name' => $name, 'headers' => [], 'rows' => []];
        }

        $headers = array_map(static fn ($v) => trim((string) ($v ?? '')), $matrix[0]);
        $rows = [];
        foreach (array_slice($matrix, 1) as $row) {
            $cells = array_map(static fn ($v) => $v === null ? '' : (string) $v, $row);
            $colCount = count($headers);
            if (count($cells) < $colCount) {
                $cells = array_pad($cells, $colCount, '');
            } elseif (count($cells) > $colCount) {
                $cells = array_slice($cells, 0, $colCount);
            }
            $rows[] = $cells;
        }

        return ['name' => pathinfo($name, PATHINFO_FILENAME) ?: 'Sheet1', 'headers' => $headers, 'rows' => $rows];
    }

    private function isImage(string $mime, string $ext): bool
    {
        return str_starts_with($mime, 'image/')
            || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'], true);
    }

    private function isPdf(string $mime, string $ext): bool
    {
        return $mime === 'application/pdf' || $ext === 'pdf';
    }

    private function isCsv(string $mime, string $ext): bool
    {
        return in_array($mime, ['text/csv', 'application/csv', 'text/plain'], true) && $ext === 'csv'
            || $ext === 'csv';
    }

    private function isExcel(string $mime, string $ext): bool
    {
        return in_array($ext, ['xlsx', 'xls', 'xlsm'], true)
            || str_contains($mime, 'spreadsheet')
            || str_contains($mime, 'excel')
            || $mime === 'application/vnd.ms-excel';
    }

    private function guessMime(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'csv' => 'text/csv',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'txt' => 'text/plain',
            default => 'application/octet-stream',
        };
    }
}
