<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../includes/bootstrap.php';

$admin = find_user_by_email('admin@paskerid.test');
$_SESSION['user_id'] = (int)$admin['id'];

// Test POST: assign_employer_case
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'admin_action' => 'assign_employer_case',
    'user_id' => 1002,
    'verifier_name' => 'Admin Pusat',
    'assignment_reason' => 'Penugasan verifikasi untuk pengujian sistem',
    'redirect_url' => 'admin.php?view=directory_individual&detail_id=1002',
];

echo "Testing POST assign_employer_case...\n";
ob_start();
try {
    include __DIR__ . '/../admin.php';
    echo "[POST ASSIGN SUCCESS]\n";
} catch (Throwable $e) {
    echo "[POST ASSIGN EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "]\n";
}
ob_end_clean();

// Check assigned_to in DB
$stmt = db()->prepare('SELECT assigned_to, assigned_at, assignment_reason FROM employer_profiles WHERE user_id = 1002');
$stmt->execute();
$row = $stmt->fetch();
echo "Employer 1002 assigned_to: " . json_encode($row) . "\n";

// Test POST: verify_employer (decision: approve)
$_POST = [
    'admin_action' => 'verify_employer',
    'user_id' => 1002,
    'decision' => 'approve',
    'verifier_notes' => 'Profil disetujui untuk pengujian sistem',
    'checklist' => ['KTP Valid', 'Selfie Valid'],
    'redirect_url' => 'admin.php?view=directory_individual&detail_id=1002',
];

echo "Testing POST verify_employer...\n";
ob_start();
try {
    include __DIR__ . '/../admin.php';
    echo "[POST VERIFY SUCCESS]\n";
} catch (Throwable $e) {
    echo "[POST VERIFY EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "]\n";
}
ob_end_clean();

// Check status in DB
$stmt = db()->prepare('SELECT verification_status, verified, active_until FROM employer_profiles WHERE user_id = 1002');
$stmt->execute();
$row = $stmt->fetch();
echo "Employer 1002 status after verify: " . json_encode($row) . "\n";

// Reset Andi status back to PENDING for demo requirement consistency
db()->prepare('UPDATE employer_profiles SET verified = 0, verification_status = "PENDING", assigned_to = "Admin Pusat" WHERE user_id = 1002')->execute();
echo "Reset Andi 1002 status back to PENDING.\n";
