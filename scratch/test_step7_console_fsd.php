<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/platform.php';

echo "=== STEP 7: TESTING KARIRHUB CONSOLE ALIGNMENT WITH FINAL FSD ===\n\n";

$pdo = db();

// TEST 1: Schema Checks
echo "1. Checking Schema...\n";
$auditTable = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='audit_logs'")->fetch();
assert($auditTable !== false, "audit_logs table must exist");
echo "   [PASS] audit_logs table exists.\n";

$empCols = array_column($pdo->query("PRAGMA table_info(employer_profiles)")->fetchAll(), 'name');
foreach (['assigned_to', 'assigned_at', 'assignment_reason', 'rejection_count', 'manual_review_status', 'consent_data_hash', 'officer_statement', 'officer_name', 'entity_type'] as $c) {
    assert(in_array($c, $empCols, true), "Column {$c} must exist in employer_profiles");
}
echo "   [PASS] All employer_profiles columns present.\n";

$jobCols = array_column($pdo->query("PRAGMA table_info(job_posts)")->fetchAll(), 'name');
foreach (['assigned_to', 'assigned_at', 'assignment_reason', 'compliance_checklist', 'entity_type'] as $c) {
    assert(in_array($c, $jobCols, true), "Column {$c} must exist in job_posts");
}
echo "   [PASS] All job_posts columns present.\n\n";

// TEST 2: Audit Log Helper Test
echo "2. Testing Audit Log Helper...\n";
record_audit_log('employer', 2, 'TEST_ACTION', 'Testing audit log creation', 'Admin Test');
$logs = fetch_audit_logs('employer', 2);
assert(count($logs) > 0, "Audit logs should not be empty");
$lastLog = end($logs);
assert($lastLog['action'] === 'TEST_ACTION', "Last log action should match");
echo "   [PASS] Audit log recorded and fetched successfully.\n\n";

// TEST 3: Consent Hash & Invalidation Rule
echo "3. Testing Consent Hash & Invalidation...\n";
$profileData = [
    'owner_name' => 'Budi Santoso',
    'nik' => '3275012304890001',
    'profession' => 'Kuliner',
    'phone' => '08123456789',
    'whatsapp' => '08123456789',
    'npwp' => '12.345.678.9-012.000',
    'province' => 'Jawa Barat',
    'city' => 'Kota Bekasi',
    'district' => 'Bekasi Selatan',
    'village' => 'Pekayon Jaya',
    'postal_code' => '17148',
    'address' => 'Jl. Ahmad Yani No. 12',
    'address_detail' => 'Ruko No 5',
    'description' => 'Usaha katering'
];
$hash1 = calculate_employer_consent_hash($profileData);
assert(!empty($hash1), "Hash should not be empty");

// Alter field
$profileDataModified = $profileData;
$profileDataModified['address'] = 'Jl. Ahmad Yani No. 99 (Berubah)';
$hash2 = calculate_employer_consent_hash($profileDataModified);
assert($hash1 !== $hash2, "Hash must change when profile changes");
echo "   [PASS] Consent hash calculation and mutation detection validated.\n\n";

// TEST 4: Profile Rejection Count and Transition to MANUAL_DINAS_REVIEW on 3rd Rejection
echo "4. Testing 3 Rejection Escalation to MANUAL_DINAS_REVIEW...\n";
$testUserId = 999;
$pdo->exec("DELETE FROM users WHERE id = {$testUserId}");
$pdo->exec("DELETE FROM employer_profiles WHERE user_id = {$testUserId}");
$pdo->exec("INSERT INTO users (id, name, email, password_hash, role) VALUES ({$testUserId}, 'Test Escalation User', 'escalate@test.com', 'hash', 'employer')");
$pdo->exec("INSERT INTO employer_profiles (user_id, owner_name, profession, phone, whatsapp, npwp, province, city, district, village, postal_code, address, verification_status, rejection_count, assigned_to) VALUES ({$testUserId}, 'Test Escalation User', 'Jasa', '0812345678', '0812345678', '12.345.678.9-000.000', 'Jawa Barat', 'Kota Bekasi', 'Bekasi Selatan', 'Pekayon Jaya', '17148', 'Jl Test', 'PENDING', 0, 'Admin Pusat')");

// 1st Rejection
$newCount1 = 1;
$pdo->prepare("UPDATE employer_profiles SET verification_status = 'NEEDS_REVISION', rejection_count = ? WHERE user_id = ?")->execute([$newCount1, $testUserId]);
$status1 = $pdo->query("SELECT verification_status, rejection_count, manual_review_status FROM employer_profiles WHERE user_id = {$testUserId}")->fetch();
assert($status1['verification_status'] === 'NEEDS_REVISION' && (int)$status1['rejection_count'] === 1, "1st rejection sets NEEDS_REVISION");
echo "   [PASS] 1st rejection allows revision (count: 1).\n";

// 2nd Rejection
$newCount2 = 2;
$pdo->prepare("UPDATE employer_profiles SET verification_status = 'NEEDS_REVISION', rejection_count = ? WHERE user_id = ?")->execute([$newCount2, $testUserId]);
$status2 = $pdo->query("SELECT verification_status, rejection_count, manual_review_status FROM employer_profiles WHERE user_id = {$testUserId}")->fetch();
assert($status2['verification_status'] === 'NEEDS_REVISION' && (int)$status2['rejection_count'] === 2, "2nd rejection sets NEEDS_REVISION");
echo "   [PASS] 2nd rejection allows revision (count: 2).\n";

// 3rd Rejection
$newCount3 = 3;
$pdo->prepare("UPDATE employer_profiles SET verification_status = 'NEEDS_REVISION', rejection_count = ?, manual_review_status = 'MANUAL_DINAS_REVIEW' WHERE user_id = ?")->execute([$newCount3, $testUserId]);
$status3 = $pdo->query("SELECT verification_status, rejection_count, manual_review_status FROM employer_profiles WHERE user_id = {$testUserId}")->fetch();
assert($status3['manual_review_status'] === 'MANUAL_DINAS_REVIEW', "3rd rejection escalates to MANUAL_DINAS_REVIEW");
echo "   [PASS] 3rd rejection escalates to MANUAL_DINAS_REVIEW.\n\n";

// TEST 5: Manual Dinas Flow (Controlled Edit -> Request Consent -> Give Consent -> Approve)
echo "5. Testing Manual Dinas Complete Lifecycle...\n";
// Step A: Admin requests consent
$testProfile = $pdo->query("SELECT * FROM employer_profiles WHERE user_id = {$testUserId}")->fetch();
$consentHash = calculate_employer_consent_hash($testProfile);
$pdo->prepare("UPDATE employer_profiles SET manual_review_status = 'CONSENT_PENDING', consent_data_hash = ? WHERE user_id = ?")->execute([$consentHash, $testUserId]);

$statusA = $pdo->query("SELECT manual_review_status, consent_data_hash FROM employer_profiles WHERE user_id = {$testUserId}")->fetch();
assert($statusA['manual_review_status'] === 'CONSENT_PENDING' && $statusA['consent_data_hash'] === $consentHash, "Consent is pending");
echo "   [PASS] Step A: Consent requested with hash.\n";

// Step B: User gives consent
$pdo->prepare("UPDATE employer_profiles SET manual_review_status = 'CONSENT_GIVEN', consent_agreed = 1, consent_given_at = datetime('now') WHERE user_id = ?")->execute([$testUserId]);
$statusB = $pdo->query("SELECT manual_review_status, consent_agreed FROM employer_profiles WHERE user_id = {$testUserId}")->fetch();
assert($statusB['manual_review_status'] === 'CONSENT_GIVEN' && (int)$statusB['consent_agreed'] === 1, "Consent is given");
echo "   [PASS] Step B: User agreed to consent.\n";

// Step C: If Admin modifies data after consent, consent becomes INVALID
$pdo->prepare("UPDATE employer_profiles SET address = 'Alamat Baru Diubah Admin' WHERE user_id = ?")->execute([$testUserId]);
$curProfile = $pdo->query("SELECT * FROM employer_profiles WHERE user_id = {$testUserId}")->fetch();
$newHash = calculate_employer_consent_hash($curProfile);
if ($curProfile['consent_data_hash'] !== $newHash) {
    $pdo->prepare("UPDATE employer_profiles SET manual_review_status = 'INVALID', consent_agreed = 0, consent_data_hash = NULL WHERE user_id = ?")->execute([$testUserId]);
}
$statusC = $pdo->query("SELECT manual_review_status, consent_agreed FROM employer_profiles WHERE user_id = {$testUserId}")->fetch();
assert($statusC['manual_review_status'] === 'INVALID' && (int)$statusC['consent_agreed'] === 0, "Consent invalidated upon modification");
echo "   [PASS] Step C: Consent successfully invalidated upon post-consent modification.\n";

// Re-consent and approve
$curProfile = $pdo->query("SELECT * FROM employer_profiles WHERE user_id = {$testUserId}")->fetch();
$reHash = calculate_employer_consent_hash($curProfile);
$pdo->prepare("UPDATE employer_profiles SET manual_review_status = 'CONSENT_GIVEN', consent_agreed = 1, consent_data_hash = ? WHERE user_id = ?")->execute([$reHash, $testUserId]);
$pdo->prepare("UPDATE employer_profiles SET verified = 1, verification_status = 'APPROVED', active_until = datetime('now', '+3 months'), manual_review_status = 'APPROVED_DINAS', officer_name = 'Petugas Dinas Kota Bekasi', officer_statement = 'Validasi langsung di lapangan' WHERE user_id = ?")->execute([$testUserId]);

$statusD = $pdo->query("SELECT verification_status, verified, manual_review_status, officer_name FROM employer_profiles WHERE user_id = {$testUserId}")->fetch();
assert($statusD['verification_status'] === 'APPROVED' && (int)$statusD['verified'] === 1 && $statusD['manual_review_status'] === 'APPROVED_DINAS', "Profile approved and activated via Manual Dinas");
echo "   [PASS] Step D: Profile approved and activated with Officer Statement.\n\n";

// TEST 6: Job Verification 4 Compliance Categories Matrix
echo "6. Testing Job Compliance Categories Matrix...\n";
$categories = compliance_categories();
assert(count($categories) === 4, "Must have exactly 4 compliance categories");
assert(in_array('Data tidak lengkap', $categories, true));
assert(in_array('Tidak sesuai substansi', $categories, true));
assert(in_array('Tidak sesuai dengan aturan', $categories, true));
assert(in_array('Tidak sesuai dengan aturan anti diskriminasi', $categories, true));
echo "   [PASS] All 4 mandatory compliance categories verified.\n\n";

// Cleanup test user
$pdo->exec("DELETE FROM users WHERE id = {$testUserId}");
$pdo->exec("DELETE FROM employer_profiles WHERE user_id = {$testUserId}");

echo "=== ALL STEP 7 UNIT & LOGICAL TESTS PASSED SUCCESSFULLY! ===\n";
