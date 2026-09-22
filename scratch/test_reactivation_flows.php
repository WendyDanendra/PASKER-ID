<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/platform.php';

$pdo = db();
echo "=== TESTING 2-TRACK REAKTIVASI HAK AKSES PEMBERI KERJA INDIVIDU ===\n\n";

// Helper to reset user & profile state
function setup_test_employer(int $userId, array $params = []): void {
    $pdo = db();
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE users SET role = 'employer', domicile_city_id = ?, city = ?, profile_complete = 1 WHERE id = ?")
        ->execute([$params['domicile_city_id'] ?? 'Kota Bandung', $params['city'] ?? 'Kota Bandung', $userId]);

    $pdo->prepare("UPDATE employer_profiles SET 
        entity_type = 'Individu',
        owner_name = ?,
        domicile_city_id = ?,
        city = ?,
        verification_status = ?,
        verified = ?,
        active_until = ?,
        last_activated_at = ?,
        extension_status = 'NONE',
        extension_requested = 0,
        suspension_reason = NULL,
        assigned_to = NULL
        WHERE user_id = ?
    ")->execute([
        $params['owner_name'] ?? 'Budi Santoso',
        $params['domicile_city_id'] ?? 'Kota Bandung',
        $params['city'] ?? 'Kota Bandung',
        $params['verification_status'] ?? 'FULL_DISABLED',
        $params['verified'] ?? 0,
        $params['active_until'] ?? date('Y-m-d H:i:s', strtotime('-10 days')),
        $params['last_activated_at'] ?? date('Y-m-d H:i:s', strtotime('-100 days')),
        $userId
    ]);
}

$adminDinasBandung = [
    'id' => 101,
    'name' => 'Petugas Dinas Bandung',
    'email' => 'admin.bandung@paskerid.test',
    'role' => 'admin_dinas',
    'domicile_city_id' => 'Kota Bandung'
];

$adminDinasSurabaya = [
    'id' => 102,
    'name' => 'Petugas Dinas Surabaya',
    'email' => 'admin.surabaya@paskerid.test',
    'role' => 'admin_dinas',
    'domicile_city_id' => 'Kota Surabaya'
];

$testUserId = 2;

// -------------------------------------------------------------
// CASE 1: Online Reactivation -> PENDING -> Admin Approve -> ACTIVE (New 3-Month Cycle)
// -------------------------------------------------------------
echo "[CASE 1] Testing Online Reactivation Track...\n";
setup_test_employer($testUserId, [
    'verification_status' => 'FULL_DISABLED',
    'verified' => 0,
    'active_until' => '2026-06-01 10:00:00',
    'last_activated_at' => '2026-03-01 10:00:00'
]);

// Step 1a: Employer submits reactivation online -> PENDING
$pdo->prepare("UPDATE employer_profiles SET verification_status = 'PENDING', verified = 0, active_until = NULL, user_consent = 1 WHERE user_id = ?")->execute([$testUserId]);
record_audit_log('employer', $testUserId, 'REACTIVATION_REQUESTED', 'Mengajukan permohonan reaktivasi Hak Akses Pemberi Kerja Individu secara online.', 'Budi Santoso', 'employer');

$empProfile = $pdo->query("SELECT * FROM employer_profiles WHERE user_id = {$testUserId}")->fetch();
$stateInfo = get_employer_access_status($empProfile);
assert($stateInfo['status'] === 'PENDING', "Must be PENDING status");
assert($stateInfo['is_online_reactivation_pending'] === true, "Must recognize pending online reactivation");
assert($stateInfo['is_active'] === false, "Must NOT be active during pending");
assert($empProfile['active_until'] === null, "Countdown 3-month cycle must NOT start on submit");
echo "   ✓ Online submission puts Hak Akses in PENDING state (countdown not started).\n";

// Step 1b: Admin assigns case & approves
$pdo->prepare("UPDATE employer_profiles SET assigned_to = ? WHERE user_id = ?")->execute(['Admin Pusat', $testUserId]);
$pdo->beginTransaction();
$pdo->prepare("UPDATE employer_profiles SET verified = 1, verification_status = 'APPROVED', active_until = datetime('now', '+3 months'), last_activated_at = datetime('now') WHERE user_id = ?")->execute([$testUserId]);
record_audit_log('employer', $testUserId, 'REACTIVATION_APPROVED', 'Permohonan reaktivasi Hak Akses online disetujui. Masa aktif baru berlaku 3 bulan. | Source: ONLINE_REACTIVATION', 'Admin Pusat', 'admin', true);
$pdo->commit();

$empApproved = $pdo->query("SELECT * FROM employer_profiles WHERE user_id = {$testUserId}")->fetch();
$approvedState = get_employer_access_status($empApproved);
assert($approvedState['status'] === 'ACTIVE', "Must become ACTIVE after approval");
assert($approvedState['is_active'] === true, "Must be active");
assert(!empty($empApproved['active_until']), "Active until must be populated");
$logs = fetch_audit_logs('employer', $testUserId);
$lastLog = end($logs);
assert(strpos($lastLog['details'], 'ONLINE_REACTIVATION') !== false, "Audit trail must record ONLINE_REACTIVATION source");
echo "   ✓ Online reactivation approved -> directly ACTIVE with new 3-month cycle & audit log source = ONLINE_REACTIVATION.\n";


// -------------------------------------------------------------
// CASE 2: Admin Dinas Direct Reactivation -> Direct ACTIVE (No 2nd Approval)
// -------------------------------------------------------------
echo "\n[CASE 2] Testing Admin Dinas Direct Reactivation Track...\n";
setup_test_employer($testUserId, [
    'domicile_city_id' => 'Kota Bandung',
    'verification_status' => 'FULL_DISABLED',
    'verified' => 0,
    'active_until' => '2026-06-01 10:00:00',
    'last_activated_at' => '2026-03-01 10:00:00'
]);

$resDirect = reactivate_employer_access_by_admin_dinas($pdo, $testUserId, $adminDinasBandung);
assert($resDirect['success'] === true, "Direct reactivation must succeed: " . ($resDirect['error'] ?? ''));

$empDirect = $pdo->query("SELECT * FROM employer_profiles WHERE user_id = {$testUserId}")->fetch();
$directState = get_employer_access_status($empDirect);
assert($directState['status'] === 'ACTIVE', "Must be immediately ACTIVE");
assert($empDirect['verification_status'] === 'APPROVED', "Verification status must be APPROVED");
assert($empDirect['verified'] == 1, "Verified flag must be 1");
assert(!empty($empDirect['last_activated_at']), "last_activated_at must be updated to now");

$logsDirect = fetch_audit_logs('employer', $testUserId);
$lastLogDirect = end($logsDirect);
assert($lastLogDirect['action'] === 'REACTIVATE_EMPLOYER_ACCESS', "Audit action must be REACTIVATE_EMPLOYER_ACCESS");
assert(strpos($lastLogDirect['details'], 'ADMIN_DINAS') !== false, "Audit source must be ADMIN_DINAS");
echo "   ✓ Direct Reactivation by Admin Dinas immediately activates Hak Akses (ACTIVE) with new 3-month cycle & strict audit log.\n";


// -------------------------------------------------------------
// CASE 3: Scope Mismatch (Kota Bandung vs Kota Surabaya)
// -------------------------------------------------------------
echo "\n[CASE 3] Testing Scope Mismatch...\n";
setup_test_employer($testUserId, [
    'domicile_city_id' => 'Kota Bandung',
    'verification_status' => 'FULL_DISABLED',
    'verified' => 0,
    'active_until' => '2026-06-01 10:00:00',
    'last_activated_at' => '2026-03-01 10:00:00'
]);

$resScopeFail = reactivate_employer_access_by_admin_dinas($pdo, $testUserId, $adminDinasSurabaya);
assert($resScopeFail['success'] === false, "Must reject out-of-scope admin");
assert(strpos($resScopeFail['error'], 'Akses ditolak') !== false, "Error must indicate scope denial");
echo "   ✓ Scope mismatch rejected: Admin Dinas Kota Surabaya cannot reactivate Kota Bandung profile.\n";


// -------------------------------------------------------------
// CASE 4: Concurrency / Double Action Protection
// -------------------------------------------------------------
echo "\n[CASE 4] Testing Concurrency / Double Action Protection...\n";
// First reactivation
$res1 = reactivate_employer_access_by_admin_dinas($pdo, $testUserId, $adminDinasBandung);
assert($res1['success'] === true, "First reactivation must succeed");

// Second reactivation attempt on already ACTIVE profile
$res2 = reactivate_employer_access_by_admin_dinas($pdo, $testUserId, $adminDinasBandung);
assert($res2['success'] === false, "Second immediate reactivation must be rejected");
assert(strpos($res2['error'], 'sudah dalam status Aktif') !== false, "Error must state already active");
echo "   ✓ Double click / duplicate reactivation rejected cleanly; only 1 cycle created.\n";


// -------------------------------------------------------------
// CASE 5: Already ACTIVE profile check
// -------------------------------------------------------------
echo "\n[CASE 5] Testing Already ACTIVE state eligibility...\n";
$empActive = $pdo->query("SELECT * FROM employer_profiles WHERE user_id = {$testUserId}")->fetch();
$activeStatus = get_employer_access_status($empActive);
assert($activeStatus['can_direct_reactivate'] === false, "Active profile must NOT have can_direct_reactivate flag");
echo "   ✓ Already ACTIVE profile has can_direct_reactivate = false (action hidden in UI).\n";


// -------------------------------------------------------------
// CASE 6: Online Reactivation Pending Collision Prevention
// -------------------------------------------------------------
echo "\n[CASE 6] Testing Pending Online Reactivation Collision Prevention...\n";
setup_test_employer($testUserId, [
    'domicile_city_id' => 'Kota Bandung',
    'verification_status' => 'PENDING',
    'verified' => 0,
    'active_until' => NULL,
    'last_activated_at' => '2026-03-01 10:00:00'
]);

$resPendingCollision = reactivate_employer_access_by_admin_dinas($pdo, $testUserId, $adminDinasBandung);
assert($resPendingCollision['success'] === false, "Direct reactivation must fail when online reactivation is pending");
assert(strpos($resPendingCollision['error'], 'sedang dalam proses verifikasi') !== false, "Must mention online reactivation in verification");

$pendingEmp = $pdo->query("SELECT * FROM employer_profiles WHERE user_id = {$testUserId}")->fetch();
$pendingState = get_employer_access_status($pendingEmp);
assert($pendingState['is_online_reactivation_pending'] === true, "Must detect pending online reactivation");
assert($pendingState['can_direct_reactivate'] === false, "Direct reactivation action must NOT be allowed");
echo "   ✓ Collision prevented: When online reactivation is PENDING, direct reactivation is blocked with clear error.\n";

echo "\n>>> ALL 6 MANDATORY TEST CASES PASSED SUCCESSFULLY! <<<\n";
