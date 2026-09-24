<?php
// Test runner for admin.php views
require_once __DIR__ . '/../includes/bootstrap.php';

function test_render(string $queryString) {
    parse_str($queryString, $_GET);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SESSION['user_id'] = 1; // Admin Pusat
    
    ob_start();
    try {
        include __DIR__ . '/../admin.php';
        $output = ob_get_clean();
        return ['status' => 200, 'length' => strlen($output), 'html' => $output];
    } catch (Throwable $e) {
        ob_end_clean();
        return ['status' => 500, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
    }
}

echo "=== TEST 1: Directory Individual (Semua) ===\n";
$res1 = test_render('view=directory_individual&tab=all');
if ($res1['status'] === 200) {
    echo "OK (length: {$res1['length']})\n";
    echo "Contains Andi Pratama: " . (strpos($res1['html'], 'Andi Pratama') !== false ? 'YES' : 'NO') . "\n";
    echo "Contains 0812-3456-7890: " . (strpos($res1['html'], '0812-3456-7890') !== false ? 'YES' : 'NO') . "\n";
    echo "Contains Jl. Ir. H. Juanda: " . (strpos($res1['html'], 'Jl. Ir. H. Juanda') !== false ? 'YES' : 'NO') . "\n";
    echo "Contains Dikirim badge: " . (strpos($res1['html'], 'Dikirim') !== false ? 'YES' : 'NO') . "\n";
} else {
    echo "ERROR: " . $res1['error'] . "\n";
}

echo "\n=== TEST 2: Directory Individual (Dalam Proses) ===\n";
$res2 = test_render('view=directory_individual&tab=process');
if ($res2['status'] === 200) {
    echo "OK (length: {$res2['length']})\n";
    echo "Contains Andi Pratama: " . (strpos($res2['html'], 'Andi Pratama') !== false ? 'YES' : 'NO') . "\n";
    echo "Contains Dikirim badge: " . (strpos($res2['html'], 'Dikirim') !== false ? 'YES' : 'NO') . "\n";
} else {
    echo "ERROR: " . $res2['error'] . "\n";
}

echo "\n=== TEST 3: Verifikasi Employer (Dalam Proses) ===\n";
$res3 = test_render('view=verifikasi_employer&tab=process');
if ($res3['status'] === 200) {
    echo "OK (length: {$res3['length']})\n";
    echo "Contains Andi Pratama: " . (strpos($res3['html'], 'Andi Pratama') !== false ? 'YES' : 'NO') . "\n";
} else {
    echo "ERROR: " . $res3['error'] . "\n";
}

echo "\n=== TEST 4: Detail View Pemberi Kerja Individu ===\n";
$res4 = test_render('view=directory_individual&detail_id=1002');
if ($res4['status'] === 200) {
    echo "OK (length: {$res4['length']})\n";
    echo "Contains DETAIL PEMBERI KERJA INDIVIDU: " . (strpos($res4['html'], 'DETAIL PEMBERI KERJA INDIVIDU') !== false ? 'YES' : 'NO') . "\n";
    echo "Contains Andi Pratama: " . (strpos($res4['html'], 'Andi Pratama') !== false ? 'YES' : 'NO') . "\n";
    echo "Contains Informasi Umum: " . (strpos($res4['html'], 'Informasi Umum') !== false ? 'YES' : 'NO') . "\n";
    echo "Contains Lokasi: " . (strpos($res4['html'], 'Lokasi') !== false ? 'YES' : 'NO') . "\n";
    echo "Contains Informasi Pemberi Kerja Individu: " . (strpos($res4['html'], 'Informasi Pemberi Kerja Individu') !== false ? 'YES' : 'NO') . "\n";
    echo "Contains Deskripsi: " . (strpos($res4['html'], 'Deskripsi') !== false ? 'YES' : 'NO') . "\n";
    echo "Contains Akun Pemberi Kerja: " . (strpos($res4['html'], 'Akun Pemberi Kerja') !== false ? 'YES' : 'NO') . "\n";
    echo "Contains Aktivitas & Audit Log: " . (strpos($res4['html'], 'Aktivitas & Audit Log') !== false ? 'YES' : 'NO') . "\n";
    echo "Contains Lihat Rincian Verifikasi: " . (strpos($res4['html'], 'Lihat Rincian Verifikasi') !== false ? 'YES' : 'NO') . "\n";
    echo "Contains Tab Lowongan: " . (strpos($res4['html'], 'tab-content-lowongan') !== false ? 'YES' : 'NO') . "\n";
    echo "Contains Tab Lamaran: " . (strpos($res4['html'], 'tab-content-lamaran') !== false ? 'YES' : 'NO') . "\n";
} else {
    echo "ERROR: " . $res4['error'] . "\n";
}
