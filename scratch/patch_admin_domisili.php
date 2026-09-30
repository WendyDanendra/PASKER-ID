<?php
$file = 'c:/Users/M Wendy Danendra P/Downloads/PASKER ID/admin.php';
$content = file_get_contents($file);

$target = '<tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">Lokasi Domisili</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer[\'city\'] ?: \'Dago, Coblong, Kota Bandung, Jawa Barat\'); ?></td></tr>';

$replacement = '<tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">Lokasi Domisili</td><td style="padding:10px 14px; color:#0f172a;"><?php 
                                                     $empLocParts = array_filter([
                                                         $selectedEmployer[\'village\'] ?? \'\',
                                                         $selectedEmployer[\'district\'] ?? \'\',
                                                         $selectedEmployer[\'city\'] ?? \'\',
                                                         $selectedEmployer[\'province\'] ?? \'\'
                                                     ]);
                                                     echo e(!empty($empLocParts) ? implode(\', \', $empLocParts) : ($selectedEmployer[\'city\'] ?: \'Dago, Coblong, Kota Bandung, Jawa Barat\'));
                                                 ?></td></tr>';

if (strpos($content, $target) !== false) {
    $content = str_replace($target, $replacement, $content);
    file_put_contents($file, $content);
    echo "SUCCESS";
} else {
    echo "TARGET NOT FOUND";
}
