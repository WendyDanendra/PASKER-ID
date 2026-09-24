<?php
$files = ['admin.php', 'dashboard.php', 'Index.html', 'employer-type.php'];

foreach ($files as $f) {
    $path = __DIR__ . '/../' . $f;
    if (file_exists($path)) {
        $content = file_get_contents($path);
        preg_match_all('/<div[^>]*class=["\'][^"\']*(card|stat|summary|kpi|metric)[^"\']*["\'][^>]*>/i', $content, $matches);
        echo "=== $f ===\n";
        foreach ($matches[0] as $m) {
            echo "  " . trim($m) . "\n";
        }
    }
}
