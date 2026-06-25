<?php

require __DIR__ . '/../vendor/autoload.php';

$parser = new Smalot\PdfParser\Parser();
$files = [
    'qm-po-1' => __DIR__ . '/../../changes/qm-po-1.pdf',
    'qm-po-2' => __DIR__ . '/../../changes/qm-po-2.pdf',
    'ch-1' => __DIR__ . '/../../changes/chandarana-po-1.pdf',
    'ch-2' => __DIR__ . '/../../changes/chandarana-po-2.pdf',
    'ch-3' => __DIR__ . '/../../changes/chandarana-po-3.pdf',
    'ch-4' => __DIR__ . '/../../changes/chandarana-po-4.pdf',
];

foreach ($files as $label => $path) {
    echo "=== {$label} ===\n";
    try {
        $pdf = $parser->parseFile($path);
        $text = str_replace("\r", '', $pdf->getText());
        echo 'TEXT_LEN=' . strlen($text) . "\n";
        echo substr($text, 0, 1500) . "\n";
        $pages = $pdf->getPages();
        echo 'PAGES=' . count($pages) . "\n";
        if (isset($pages[0])) {
            $xobj = $pages[0]->getXObjects();
            echo 'XOBJECTS=' . count($xobj) . "\n";
            foreach ($xobj as $name => $obj) {
                echo "  xobj {$name} " . get_class($obj) . "\n";
                if (method_exists($obj, 'getDetails')) {
                    $d = $obj->getDetails();
                    echo '    details ' . json_encode($d) . "\n";
                }
                if (method_exists($obj, 'getContent')) {
                    $c = $obj->getContent();
                    echo '    content_len=' . strlen((string) $c) . ' head=' . bin2hex(substr((string) $c, 0, 8)) . "\n";
                }
            }
        }
        echo "\n";
    } catch (Throwable $e) {
        echo 'ERR: ' . $e->getMessage() . "\n\n";
    }
}