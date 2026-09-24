<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../includes/bootstrap.php';

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

echo "=== TESTING PHP FILES FOR ERRORS ===\n\n";

foreach ($files as $file) {
    echo "Testing {$file} ... ";
    
    // Test unauthenticated
    logout_user();
    $_GET = [];
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    
    ob_start();
    try {
        include __DIR__ . '/../' . $file;
        $output = ob_get_clean();
        echo "UNAUTH: OK (" . strlen($output) . " bytes)";
    } catch (Throwable $e) {
        ob_end_clean();
        echo "UNAUTH ERROR: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
        echo "Trace:\n" . $e->getTraceAsString() . "\n";
        continue;
    }

    // Test Admin login
    $admin = find_user_by_email('admin@paskerid.test');
    if ($admin) {
        login_user($admin);
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ob_start();
        try {
            include __DIR__ . '/../' . $file;
            $output = ob_get_clean();
            echo " | ADMIN: OK (" . strlen($output) . " bytes)";
        } catch (Throwable $e) {
            ob_end_clean();
            echo "\n  ADMIN ERROR in {$file}: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
            echo "Trace:\n" . $e->getTraceAsString() . "\n";
            continue;
        }
    }

    // Test Employer login (Andi Pratama)
    $emp = find_user_by_email('andi@paskerid.test');
    if ($emp) {
        login_user($emp);
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ob_start();
        try {
            include __DIR__ . '/../' . $file;
            $output = ob_get_clean();
            echo " | EMP: OK (" . strlen($output) . " bytes)";
        } catch (Throwable $e) {
            ob_end_clean();
            echo "\n  EMP ERROR in {$file}: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
            echo "Trace:\n" . $e->getTraceAsString() . "\n";
            continue;
        }
    }

    echo "\n";
}
