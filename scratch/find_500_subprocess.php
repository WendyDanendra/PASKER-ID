<?php
$files = [
    'index.php',
    'login.php',
    'admin.php',
    'dashboard.php',
    'employer-type.php',
    'employer-menu.php',
    'register.php',
    'seeker.php',
    'profile-employer.php',
    'profile-seeker.php',
    'settings.php',
    'notif-read.php'
];

foreach ($files as $file) {
    echo "=== TESTING {$file} ===\n";
    $cmd = 'php -r ' . escapeshellarg('
        error_reporting(E_ALL);
        ini_set("display_errors", "1");
        require_once "includes/bootstrap.php";
        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["HTTP_HOST"] = "localhost";
        $_SERVER["REQUEST_URI"] = "/' . $file . '";
        try {
            ob_start();
            include "' . $file . '";
            $out = ob_get_clean();
            echo "SUCCESS: " . strlen($out) . " bytes\n";
        } catch (Throwable $e) {
            ob_end_clean();
            echo "FATAL ERROR: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
            echo $e->getTraceAsString() . "\n";
        }
    ');
    
    exec($cmd, $output, $returnCode);
    echo "Return code: {$returnCode}\n";
    echo implode("\n", $output) . "\n\n";
    $output = [];
}
