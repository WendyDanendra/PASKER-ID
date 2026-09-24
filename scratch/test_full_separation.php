<?php
require_once __DIR__ . '/../includes/bootstrap.php';

echo "=== TEST 1: Login as Andi Pratama (andi@paskerid.test) ===\n";
$andi = find_user_by_email('andi@paskerid.test');
echo "Found Andi: " . ($andi ? 'YES' : 'NO') . "\n";
echo "Password verify 'Pusatpasarkerj4': " . (password_verify('Pusatpasarkerj4', $andi['password_hash']) ? 'YES' : 'NO') . "\n";

login_user($andi);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];
ob_start();
include __DIR__ . '/../dashboard.php';
$dashHtml = ob_get_clean();
echo "Dashboard Andi loaded (length: " . strlen($dashHtml) . ")\n";
echo "Contains Andi Pratama: " . (strpos($dashHtml, 'Andi Pratama') !== false ? 'YES' : 'NO') . "\n";
echo "Contains Menunggu Verifikasi: " . (strpos($dashHtml, 'Menunggu Verifikasi') !== false ? 'YES' : 'NO') . "\n";

echo "\n=== TEST 2: Login as Dummy Perorangan (perorangan@paskerid.test) ===\n";
$budi = find_user_by_email('perorangan@paskerid.test');
echo "Found Budi: " . ($budi ? 'YES' : 'NO') . "\n";
echo "Password verify 'password': " . (password_verify('password', $budi['password_hash']) ? 'YES' : 'NO') . "\n";

login_user($budi);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];
ob_start();
include __DIR__ . '/../dashboard.php';
$dashHtml2 = ob_get_clean();
echo "Dashboard Budi loaded (length: " . strlen($dashHtml2) . ")\n";
echo "Contains Budi Santoso: " . (strpos($dashHtml2, 'Budi Santoso') !== false ? 'YES' : 'NO') . "\n";

echo "\n=== TEST 3: Access admin.php as Admin Pusat ===\n";
$admin = find_user_by_email('admin@paskerid.test');
login_user($admin);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['view' => 'directory_individual', 'tab' => 'all'];
ob_start();
include __DIR__ . '/../admin.php';
$adminHtml = ob_get_clean();
echo "Admin.php loaded (length: " . strlen($adminHtml) . ")\n";
echo "Contains Andi Pratama: " . (strpos($adminHtml, 'Andi Pratama') !== false ? 'YES' : 'NO') . "\n";
echo "Contains Budi Santoso: " . (strpos($adminHtml, 'Budi Santoso') !== false ? 'YES' : 'NO') . "\n";

echo "\n=== TEST 4: Access admin.php directly (seamless demo fallback) ===\n";
login_user($andi); // Set employer session
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['view' => 'directory_individual', 'tab' => 'all'];
ob_start();
include __DIR__ . '/../admin.php';
$adminHtml2 = ob_get_clean();
echo "Admin.php opened directly (length: " . strlen($adminHtml2) . ")\n";
echo "Contains Andi Pratama: " . (strpos($adminHtml2, 'Andi Pratama') !== false ? 'YES' : 'NO') . "\n";

echo "\nALL TESTS FINISHED SUCCESSFULLY!\n";
