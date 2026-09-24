<?php
$accounts = [
    'andi@paskerid.test' => 'dashboard.php',
    'perorangan@paskerid.test' => 'dashboard.php',
    'admin@paskerid.test' => 'admin.php',
    'admin.bandung@paskerid.test' => 'admin.php',
    'seeker@paskerid.test' => 'seeker.php',
];

foreach ($accounts as $email => $expectedUrlPart) {
    $ch = curl_init('http://localhost:8888/login.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['email' => $email, 'password' => 'Pusatpasarkerj4']));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_COOKIEFILE, "");
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    echo "Account: $email\n";
    echo "  HTTP Status: $code\n";
    echo "  Effective URL: $effectiveUrl\n";
    if ($code === 200 && str_contains($effectiveUrl, $expectedUrlPart)) {
        echo "  RESULT: OK\n";
    } else {
        echo "  RESULT: FAIL (Expected $expectedUrlPart)\n";
    }
    echo "----------------------------------------\n";
}
