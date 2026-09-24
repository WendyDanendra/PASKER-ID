<?php
$ch = curl_init('http://localhost:8888/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['email' => 'andi@paskerid.test', 'password' => 'Pusatpasarkerj4']));
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, ""); // enable cookie engine in memory
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);

echo "Final HTTP Status: $code\n";
echo "Final URL: $effectiveUrl\n";
if (strpos($res, 'Andi Pratama') !== false) {
    echo "SUCCESS: Andi Pratama found in dashboard HTML!\n";
} else {
    echo "FAILED: HTML preview:\n" . substr($res, 0, 500) . "\n";
}
