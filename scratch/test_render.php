<?php
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/dashboard.php';
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
try {
    include 'dashboard.php';
    $output = ob_get_clean();
    echo "SUCCESS: dashboard.php rendered cleanly (" . strlen($output) . " bytes)\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "RENDER ERROR in dashboard.php: " . $e->getMessage() . " on line " . $e->getLine() . " in " . $e->getFile() . "\n";
}
