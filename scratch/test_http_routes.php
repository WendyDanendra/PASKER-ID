<?php
$pages = [
    'index.php',
    'login.php',
    'admin.php',
    'admin.php?view=directory_individual',
    'admin.php?view=directory_individual&tab=all',
    'admin.php?view=directory_individual&tab=process',
    'admin.php?view=directory_individual&tab=verified',
    'admin.php?view=directory_individual&tab=rejected',
    'admin.php?view=directory_individual&detail_id=1002',
    'admin.php?view=verifikasi_employer',
    'admin.php?view=verifikasi_employer&entity=Individu',
    'admin.php?view=verifikasi_employer&entity=Perusahaan',
    'dashboard.php',
    'employer-type.php',
    'employer-menu.php',
    'register.php',
    'seeker.php',
    'profile-employer.php',
    'profile-seeker.php',
    'settings.php'
];

echo "=== HTTP ROUTE RESPONSE TESTS (localhost:8888) ===\n\n";

foreach ($pages as $p) {
    $url = "http://localhost:8888/" . $p;
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);
    
    echo "URL: /{$p} -> HTTP {$httpCode}";
    if ($httpCode >= 300 && $httpCode < 400) {
        preg_match('/Location:\s*([^\r\n]+)/i', $headers, $matches);
        echo " (Redirect: " . ($matches[1] ?? 'unknown') . ")";
    } elseif ($httpCode === 500) {
        echo " *** HTTP 500 ERROR ***\n";
        echo "BODY:\n" . substr($body, 0, 500) . "\n";
    }
    echo "\n";
}
