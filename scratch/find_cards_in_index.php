<?php
$content = file_get_contents(__DIR__ . '/../Index.html');
$lines = explode("\n", $content);
foreach ($lines as $i => $line) {
    if (strpos($line, 'cards4') !== false || strpos($line, 'Total Pemberi Kerja') !== false) {
        echo "Line " . ($i + 1) . ": " . trim($line) . "\n";
    }
}
