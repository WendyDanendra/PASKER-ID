<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$user = find_user_by_email('perorangan@paskerid.test');
login_user($user);

$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
try {
    include __DIR__ . '/../dashboard.php';
    $html = ob_get_clean();
    echo "Dashboard loaded successfully (length: " . strlen($html) . ")\n";
    echo "Contains Budi Santoso: " . (strpos($html, 'Budi Santoso') !== false ? 'YES' : 'NO') . "\n";
    echo "Contains Menunggu Verifikasi: " . (strpos($html, 'Menunggu Verifikasi') !== false ? 'YES' : 'NO') . "\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "Dashboard error: " . $e->getMessage() . "\n";
}
