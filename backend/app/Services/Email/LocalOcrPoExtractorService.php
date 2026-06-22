<?php

namespace App\Services\Email;

use App\Contracts\PoExtractorContract;
use Illuminate\Support\Facades\Log;

/**
 * Local OCR extractor using Tesseract (or a Python script) for air-gapped environments.
 *
 * Configuration in .env:
 *   LOCAL_OCR_ENABLED=true
 *   LOCAL_OCR_DRIVER=tesseract          # tesseract | python
 *   LOCAL_OCR_TESSERACT_PATH=/usr/bin/tesseract
 *   LOCAL_OCR_PYTHON_SCRIPT=/opt/ocr/extract_po.py
 *
 * For the Python option, the script should accept a text argument via stdin
 * and print the extracted PO number (or "NONE") to stdout.
 */
class LocalOcrPoExtractorService implements PoExtractorContract
{
    public function getName(): string
    {
        return 'local_ocr';
    }

    public function isAvailable(): bool
    {
        return (bool) env('LOCAL_OCR_ENABLED', false);
    }

    public function extractFromText(string $text, array $hints = []): ?ExtractionResult
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $driver = env('LOCAL_OCR_DRIVER', 'tesseract');

        try {
            $raw = match ($driver) {
                'python'     => $this->runPython($text),
                'tesseract'  => $this->runTesseract($text),
                default      => null,
            };

            if (! $raw || strtoupper(trim($raw)) === 'NONE') {
                return null;
            }

            $poNumber = strtoupper(trim($raw));
            return new ExtractionResult(
                poNumber:   $poNumber,
                method:     'local_ocr',
                confidence: 80,
                rawMatch:   $raw,
            );
        } catch (\Throwable $e) {
            Log::error('Local OCR extraction failed', ['driver' => $driver, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Extract PO via a Python script.
     * The script reads from stdin and writes the PO number to stdout.
     *
     * Installation:
     *   pip install pytesseract pillow pdf2image
     *   (see /docs/local-ocr-setup.md for full instructions)
     */
    private function runPython(string $text): ?string
    {
        $scriptPath = env('LOCAL_OCR_PYTHON_SCRIPT', '/opt/ocr/extract_po.py');

        if (! file_exists($scriptPath)) {
            Log::warning("Local OCR Python script not found: {$scriptPath}");
            return null;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open("python3 {$scriptPath}", $descriptors, $pipes);
        if (! is_resource($process)) {
            return null;
        }

        fwrite($pipes[0], $text);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return trim($output) ?: null;
    }

    /**
     * Extract PO via Tesseract CLI (for image-based PDFs that have been converted to TIFF).
     * In practice, this would receive a file path rather than text — kept as text-passthrough
     * for interface consistency. Extend as needed for actual image processing.
     */
    private function runTesseract(string $text): ?string
    {
        // If $text is a file path to an image, OCR it
        if (file_exists($text)) {
            $tesseract = env('LOCAL_OCR_TESSERACT_PATH', '/usr/bin/tesseract');
            $output    = shell_exec("{$tesseract} {$text} stdout 2>/dev/null");
            return $output ? trim($output) : null;
        }

        // Otherwise treat as already-extracted text — hand back for pattern matching
        return $text;
    }
}
