<?php
$dir = realpath(__DIR__ . '/..');
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

foreach ($iterator as $file) {
    if ($file->isFile()) {
        $filename = $file->getPathname();
        if (str_contains($filename, 'vendor') || str_contains($filename, '.git')) continue;
        $content = file_get_contents($filename);
        if (
            stripos($content, 'Total Pemberi Kerja') !== false ||
            stripos($content, 'Antrean Verifikasi Profil') !== false ||
            stripos($content, 'Antrean Moderasi Loker') !== false ||
            stripos($content, 'Pencari Kerja Aktif') !== false
        ) {
            echo "FILE: " . $filename . "\n";
            $lines = explode("\n", $content);
            foreach ($lines as $lineNum => $lineText) {
                if (
                    stripos($lineText, 'Total Pemberi Kerja') !== false ||
                    stripos($lineText, 'Antrean Verifikasi Profil') !== false ||
                    stripos($lineText, 'Antrean Moderasi Loker') !== false ||
                    stripos($lineText, 'Pencari Kerja Aktif') !== false
                ) {
                    echo "  Line " . ($lineNum + 1) . ": " . trim($lineText) . "\n";
                }
            }
        }
    }
}
