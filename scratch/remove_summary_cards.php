<?php
$adminFile = __DIR__ . '/../admin.php';
$content = file_get_contents($adminFile);

$pattern = '/\s*<\?php if \(!\$detailId\): \?>\s*<!-- ADMIN OVERVIEW KPI STATS \(4 CARDS\) -->.*?<\/div>\s*<\?php endif; \?>/s';
$newContent = preg_replace($pattern, '', $content, 1, $count);

if ($count > 0) {
    file_put_contents($adminFile, $newContent);
    echo "SUCCESS: Summary Cards removed!\n";
} else {
    echo "ERROR: Pattern not matched!\n";
}
