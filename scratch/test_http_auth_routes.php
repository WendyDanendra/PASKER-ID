<?php
$baseUrl = 'http://localhost:8888/';
$cookieFile = __DIR__ . '/cookie.txt';

function login_and_test($email, $password, $pages) {
    global $baseUrl, $cookieFile;
    if (file_exists($cookieFile)) unlink($cookieFile);
    
    echo "--- TESTING LOGIN AS {$email} ---\n";
    $ch = curl_init($baseUrl . 'login.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['email' => $email, 'password' => $password]));
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_HEADER, true);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "Login POST response: HTTP {$code}\n";
    
    foreach ($pages as $p) {
        $ch = curl_init($baseUrl . $p);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
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
            echo "BODY:\n" . substr($body, 0, 800) . "\n";
        }
        echo "\n";
    }
}

$allPages = [
    'admin.php',
    'admin.php?view=directory_individual',
    'admin.php?view=directory_individual&tab=all',
    'admin.php?view=directory_individual&tab=process',
    'admin.php?view=directory_individual&tab=verified',
    'admin.php?view=directory_individual&tab=rejected',
    'admin.php?view=directory_individual&detail_id=1002',
    'admin.php?view=verifikasi_employer',
    'admin.php?view=verifikasi_employer&entity=Individu',
    'dashboard.php',
    'employer-type.php',
    'employer-menu.php',
    'register.php',
    'seeker.php'
];

login_and_test('admin@paskerid.test', 'Pusatpasarkerj4', $allPages);
echo "\n";
login_and_test('andi@paskerid.test', 'Pusatpasarkerj4', $allPages);
echo "\n";
login_and_test('perorangan@paskerid.test', 'Pusatpasarkerj4', $allPages);
