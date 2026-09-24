<?php
require_once __DIR__ . '/../includes/bootstrap.php';

// Test 1: Login as admin@paskerid.test
$adm = find_user_by_email('admin@paskerid.test');
echo "Admin User Found: " . ($adm ? 'YES' : 'NO') . "\n";
echo "Password verify 'password': " . (password_verify('password', $adm['password_hash']) ? 'YES' : 'NO') . "\n";

login_user($adm);
$_GET = ['view' => 'directory_individual', 'tab' => 'all'];
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
try {
    include __DIR__ . '/../admin.php';
    $out = ob_get_clean();
    echo "Admin.php Directory loaded successfully (length: " . strlen($out) . ")\n";
    echo "Contains Andi Pratama: " . (strpos($out, 'Andi Pratama') !== false ? 'YES' : 'NO') . "\n";
    echo "Contains Dikirim: " . (strpos($out, 'Dikirim') !== false ? 'YES' : 'NO') . "\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "Admin.php ERROR: " . $e->getMessage() . "\n";
}

// Test 2: Admin Dinas login
$dinas = find_user_by_email('admin.bandung@paskerid.test');
echo "\nAdmin Dinas User Found: " . ($dinas ? 'YES' : 'NO') . "\n";
echo "Password verify 'password': " . (password_verify('password', $dinas['password_hash']) ? 'YES' : 'NO') . "\n";

login_user($dinas);
$_GET = ['view' => 'directory_individual', 'tab' => 'process'];
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
try {
    include __DIR__ . '/../admin.php';
    $out = ob_get_clean();
    echo "Admin Dinas Directory loaded successfully (length: " . strlen($out) . ")\n";
    echo "Contains Andi Pratama: " . (strpos($out, 'Andi Pratama') !== false ? 'YES' : 'NO') . "\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "Admin Dinas ERROR: " . $e->getMessage() . "\n";
}

// Test 3: Employer Dashboard as Andi Pratama
$emp = find_user_by_email('perorangan@paskerid.test');
echo "\nEmployer User Found: " . ($emp ? 'YES' : 'NO') . "\n";
echo "Password verify 'password': " . (password_verify('password', $emp['password_hash']) ? 'YES' : 'NO') . "\n";

login_user($emp);
$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
try {
    include __DIR__ . '/../dashboard.php';
    $out = ob_get_clean();
    echo "Dashboard loaded successfully for Andi Pratama (length: " . strlen($out) . ")\n";
    echo "Contains Andi Pratama: " . (strpos($out, 'Andi Pratama') !== false ? 'YES' : 'NO') . "\n";
    echo "Contains Menunggu Verifikasi: " . (strpos($out, 'Menunggu Verifikasi') !== false ? 'YES' : 'NO') . "\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "Dashboard ERROR: " . $e->getMessage() . "\n";
}
