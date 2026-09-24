<?php
$dir = __DIR__ . '/..';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
foreach ($iterator as $file) {
    if ($file->isFile() && in_array(pathinfo($file->getFilename(), PATHINFO_EXTENSION), ['php', 'html', 'js'])) {
        $content = file_get_contents($file->getPathname());
        if (
            strpos($content, 'Total Pemberi Kerja') !== false || 
            strpos($content, 'Antrean Verifikasi') !== false || 
            strpos($content, 'Antrean Moderasi') !== false ||
            strpos($content, 'Pencari Kerja Aktif') !== false ||
            strpos($content, 'Menunggu pemeriksaan') !== false ||
            strpos($content, 'Talenta siap dilamar') !== false
        ) {
            echo "MATCH: " . $file->getPathname() . "\n";
        }
    }
}
