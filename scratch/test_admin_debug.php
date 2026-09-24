<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../includes/bootstrap.php';

$testCases = [
    ['user' => 'admin@paskerid.test', 'query' => []],
    ['user' => 'admin@paskerid.test', 'query' => ['view' => 'directory_individual']],
    ['user' => 'admin@paskerid.test', 'query' => ['view' => 'directory_individual', 'tab' => 'all']],
    ['user' => 'admin@paskerid.test', 'query' => ['view' => 'directory_individual', 'tab' => 'process']],
    ['user' => 'admin@paskerid.test', 'query' => ['view' => 'directory_individual', 'tab' => 'verified']],
    ['user' => 'admin@paskerid.test', 'query' => ['view' => 'directory_individual', 'tab' => 'rejected']],
    ['user' => 'admin@paskerid.test', 'query' => ['view' => 'directory_individual', 'detail_id' => '1002']],
    ['user' => 'admin@paskerid.test', 'query' => ['view' => 'verifikasi_employer']],
    ['user' => 'admin@paskerid.test', 'query' => ['view' => 'verifikasi_employer', 'entity' => 'Individu']],
    ['user' => 'admin@paskerid.test', 'query' => ['view' => 'verifikasi_employer', 'entity' => 'Perusahaan']],
    ['user' => 'admin.bandung@paskerid.test', 'query' => ['view' => 'directory_individual', 'detail_id' => '1002']],
    ['user' => 'andi@paskerid.test', 'query' => ['view' => 'directory_individual', 'detail_id' => '1002']],
    ['user' => 'perorangan@paskerid.test', 'query' => ['view' => 'directory_individual', 'detail_id' => '1002']],
];

foreach ($testCases as $idx => $case) {
    echo "--- Case $idx: User={$case['user']}, GET=" . json_encode($case['query']) . " ---\n";
    $u = find_user_by_email($case['user']);
    $_SESSION['user_id'] = (int)$u['id'];
    $_GET = $case['query'];
    
    ob_start();
    try {
        include __DIR__ . '/../admin.php';
        $out = ob_get_clean();
        echo "[SUCCESS] Content length: " . strlen($out) . "\n";
    } catch (Throwable $e) {
        ob_end_clean();
        echo "[FATAL EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "]\n";
    }
}
