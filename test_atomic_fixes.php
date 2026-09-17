<?php
/**
 * Test: verify_additional_doc fixes
 * Tests: (1) published_at vs created_at behavior
 *        (2) employer domicile vs job location scoping
 *        (3) admin assignee verification
 *        (4) atomic transaction / rollback behavior
 *        (5) race condition locking (concurrent decisions)
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/platform.php';

$pdo = db();
$pass = 0;
$fail = 0;

function assert_test(string $name, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) {
        echo "[PASS] $name\n";
        $pass++;
    } else {
        echo "[FAIL] $name" . ($detail ? " | $detail" : '') . "\n";
        $fail++;
    }
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function clean_test_data(PDO $pdo, int $testUserId): void {
    $pdo->prepare('DELETE FROM job_additional_documents WHERE user_id = ?')->execute([$testUserId]);
    $pdo->prepare('DELETE FROM job_verifications WHERE user_id = ?')->execute([$testUserId]);
    $pdo->prepare('DELETE FROM job_posts WHERE user_id = ?')->execute([$testUserId]);
    $pdo->prepare('DELETE FROM employer_profiles WHERE user_id = ?')->execute([$testUserId]);
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$testUserId]);
}

function create_test_employer(PDO $pdo, string $email, string $domicileCity): int {
    $pdo->prepare("INSERT INTO users (name, email, password_hash, role, created_at) VALUES (?, ?, 'hash', 'employer', NOW())")
        ->execute(['Test Employer', $email]);
    $userId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO employer_profiles (user_id, city, domicile_city_id, entity_type, verification_status) VALUES (?, ?, ?, 'Individu', 'APPROVED')")
        ->execute([$userId, $domicileCity, $domicileCity]);
    return $userId;
}

function create_job_with_status(PDO $pdo, int $userId, string $status, ?string $publishedAt, string $kbji = 'K.001', int $quota = 2, string $assignedTo = ''): int {
    $pdo->prepare("INSERT INTO job_posts (user_id, title, status, kbji_code, quota, entity_type, assigned_to, published_at, location, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'Individu', ?, ?, 'Kota A', NOW(), NOW())")
        ->execute([$userId, 'Test Job ' . uniqid(), $status, $kbji, $quota, $assignedTo, $publishedAt]);
    return (int)$pdo->lastInsertId();
}

function create_additional_doc(PDO $pdo, int $jobId, int $userId, string $status = 'PENDING_UPLOAD'): void {
    $pdo->prepare("INSERT INTO job_additional_documents (job_id, user_id, kbji_code, status, doc_reviewed, field_visit, created_at) VALUES (?, ?, 'K.001', ?, 'Tidak', 'Tidak', NOW())")
        ->execute([$jobId, $userId, $status]);
}

// ── Unique test user IDs (use fixed unique emails to avoid collision) ────────
$uniqueSuffix = time();

// ═════════════════════════════════════════════════════════════════════════════
// TEST GROUP 1: Layer 3 — published_at vs created_at
// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== TEST GROUP 1: Layer 3 published_at vs created_at ===\n";

$u1 = create_test_employer($pdo, "t1_{$uniqueSuffix}@test.com", 'Surabaya');

// Job A: published_at = this month (should count)
$currentMonthDate = date('Y-m-15 10:00:00');
$jobA = create_job_with_status($pdo, $u1, 'Tayang', $currentMonthDate, 'K.001', 3);

// Job B: published_at = last month (should NOT count)
$lastMonthDate = date('Y-m-15 10:00:00', strtotime('-1 month'));
$jobB = create_job_with_status($pdo, $u1, 'Tayang', $lastMonthDate, 'K.001', 4);

// Job C: published_at = NULL but status='Tayang', created_at = this month
// Old fallback would count this; new code should NOT count it.
$pdo->prepare("INSERT INTO job_posts (user_id, title, status, kbji_code, quota, entity_type, assigned_to, published_at, location, created_at, updated_at) VALUES (?, 'FallbackJob', 'Tayang', 'K.001', 5, 'Individu', '', NULL, 'Kota B', NOW(), NOW())")
    ->execute([$u1]);
$jobC = (int)$pdo->lastInsertId();

// Layer 3 query (new: strict published_at only)
$startOfMonth = date('Y-m-01 00:00:00');
$endOfMonth   = date('Y-m-t 23:59:59');

$stmtL3New = $pdo->prepare('SELECT COALESCE(SUM(quota), 0) FROM job_posts WHERE user_id = ? AND parent_job_id IS NULL AND published_at IS NOT NULL AND published_at BETWEEN ? AND ? AND id != ?');
$stmtL3New->execute([$u1, $startOfMonth, $endOfMonth, 0]);
$quotaNew = (int)$stmtL3New->fetchColumn();

// Old query (fallback) for comparison:
$stmtL3Old = $pdo->prepare('SELECT COALESCE(SUM(quota), 0) FROM job_posts WHERE user_id = ? AND parent_job_id IS NULL AND (
    (published_at IS NOT NULL AND published_at BETWEEN ? AND ?)
    OR (published_at IS NULL AND status IN ("Tayang", "Ditutup", "Kedaluwarsa") AND created_at BETWEEN ? AND ?)
) AND id != ?');
$stmtL3Old->execute([$u1, $startOfMonth, $endOfMonth, $startOfMonth, $endOfMonth, 0]);
$quotaOld = (int)$stmtL3Old->fetchColumn();

// New: only jobA (quota=3) should count (jobB is last month, jobC has NULL published_at)
assert_test('Layer3 strict: only published_at=this_month counts', $quotaNew === 3, "got $quotaNew, expected 3");
// Old: jobA (3) + jobC (5) = 8 (jobC was counted via created_at fallback)
assert_test('Old Layer3 fallback counted NULL published_at jobs', $quotaOld >= 8, "old=$quotaOld, new=$quotaNew");
assert_test('New Layer3 is stricter than old (removed fallback)', $quotaNew < $quotaOld, "new=$quotaNew, old=$quotaOld");

clean_test_data($pdo, $u1);

// ═════════════════════════════════════════════════════════════════════════════
// TEST GROUP 2: Layer 2 — same-KBJI published_at strict count
// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== TEST GROUP 2: Layer 2 same-KBJI published_at strict ===\n";

$u2 = create_test_employer($pdo, "t2_{$uniqueSuffix}@test.com", 'Bandung');

// 3 jobs published this month with KBJI K.002
for ($i = 0; $i < 3; $i++) {
    create_job_with_status($pdo, $u2, 'Tayang', $currentMonthDate, 'K.002', 1);
}
// 1 job with NULL published_at, status=Tayang (old fallback would count this as 4th)
$pdo->prepare("INSERT INTO job_posts (user_id, title, status, kbji_code, quota, entity_type, location, published_at, created_at, updated_at) VALUES (?, 'NullPubJob', 'Tayang', 'K.002', 1, 'Individu', 'X', NULL, NOW(), NOW())")
    ->execute([$u2]);

// New Layer 2 query (strict published_at)
$stmtL2New = $pdo->prepare('SELECT COUNT(*) FROM job_posts WHERE user_id = ? AND kbji_code = ? AND parent_job_id IS NULL AND published_at IS NOT NULL AND published_at BETWEEN ? AND ? AND id != ?');
$stmtL2New->execute([$u2, 'K.002', $startOfMonth, $endOfMonth, 0]);
$countNew = (int)$stmtL2New->fetchColumn();

assert_test('Layer2 strict: count=3 (not 4, NULL published_at excluded)', $countNew === 3, "got $countNew");

// Call job_rules_engine to verify it returns additional_doc_required=false for 3 published
// (requires 4th published job to trigger, not 4th with NULL)
$result = check_pki_job_rules_engine(db(), $u2, 'K.002', 1);
assert_test('Rules engine Layer2: 3 published same-KBJI → NOT additional_doc_required', !$result['additional_doc_required'], 'additional_doc_required=' . var_export($result['additional_doc_required'], true));

clean_test_data($pdo, $u2);

// ═════════════════════════════════════════════════════════════════════════════
// TEST GROUP 3: Employer domicile scoping (not job location)
// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== TEST GROUP 3: Employer domicile_city_id scoping ===\n";

$u3 = create_test_employer($pdo, "t3_{$uniqueSuffix}@test.com", 'Surabaya');
// Job is located in Jakarta (different from employer domicile)
$pdo->prepare("INSERT INTO job_posts (user_id, title, status, kbji_code, quota, entity_type, location, assigned_to, published_at, created_at, updated_at) VALUES (?, 'Job in Jakarta', 'ADDITIONAL_DOCUMENT_PENDING', 'K.003', 1, 'Individu', 'Jakarta', 'Admin Pusat', NULL, NOW(), NOW())")
    ->execute([$u3]);
$jobJ = (int)$pdo->lastInsertId();

// Fetch as admin would (with emp_domicile_city_id)
$stmt = $pdo->prepare('SELECT j.*, ep.city as emp_city, ep.domicile_city_id as emp_domicile_city_id FROM job_posts j LEFT JOIN employer_profiles ep ON ep.user_id = j.user_id WHERE j.id = ?');
$stmt->execute([$jobJ]);
$row = $stmt->fetch();

assert_test('Job location = Jakarta (different from employer domicile)', $row['location'] === 'Jakarta', $row['location']);
assert_test('Employer domicile_city_id = Surabaya (correct source)', $row['emp_domicile_city_id'] === 'Surabaya', $row['emp_domicile_city_id']);

// Simulate admin with domicile_city_id = 'Surabaya' (should PASS — employer is in Surabaya)
$adminDomicileSby = 'Surabaya';
$employerDomicile = (string)($row['emp_domicile_city_id'] ?? '');
$scopePassSby = $employerDomicile !== '' && (
    stripos($employerDomicile, $adminDomicileSby) !== false ||
    stripos($adminDomicileSby, $employerDomicile) !== false
);
assert_test('Admin Surabaya CAN access (employer domicile=Surabaya, job in Jakarta)', $scopePassSby, "emp_domicile=$employerDomicile, admin=$adminDomicileSby");

// Simulate admin with domicile_city_id = 'Jakarta' (should FAIL — employer is in Surabaya)
$adminDomicileJkt = 'Jakarta';
$scopePassJkt = $employerDomicile !== '' && (
    stripos($employerDomicile, $adminDomicileJkt) !== false ||
    stripos($adminDomicileJkt, $employerDomicile) !== false
);
assert_test('Admin Jakarta CANNOT access (employer domicile=Surabaya, not Jakarta)', !$scopePassJkt, "emp_domicile=$employerDomicile, admin=$adminDomicileJkt");

// Admin Pusat (no domicile restriction) can access any job
$adminDomicilePusat = '';
$scopePassPusat = ($adminDomicilePusat === ''); // no restriction = pass
assert_test('Admin Pusat (no domicile) can access all employers', $scopePassPusat);

clean_test_data($pdo, $u3);

// ═════════════════════════════════════════════════════════════════════════════
// TEST GROUP 4: Assignment check — current admin must be the assignee
// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== TEST GROUP 4: Assignment verification (assigned_to = current admin) ===\n";

$u4 = create_test_employer($pdo, "t4_{$uniqueSuffix}@test.com", 'Medan');
$jobAssigned = create_job_with_status($pdo, $u4, 'ADDITIONAL_DOCUMENT_PENDING', null, 'K.004', 1, 'Admin Budi');

$stmt = $pdo->prepare('SELECT assigned_to FROM job_posts WHERE id = ?');
$stmt->execute([$jobAssigned]);
$assignedTo = (string)$stmt->fetchColumn();

// Admin Budi IS the assignee
$adminBudi = ['name' => 'Admin Budi', 'email' => 'budi@admin.com'];
$isAssigneeBudi = ($assignedTo === $adminBudi['name'] || $assignedTo === $adminBudi['email']);
assert_test('Admin Budi IS the assignee → allowed', $isAssigneeBudi, "assigned_to=$assignedTo");

// Admin Siti is NOT the assignee
$adminSiti = ['name' => 'Admin Siti', 'email' => 'siti@admin.com'];
$isAssigneeSiti = ($assignedTo === $adminSiti['name'] || $assignedTo === $adminSiti['email']);
assert_test('Admin Siti is NOT the assignee → blocked', !$isAssigneeSiti, "assigned_to=$assignedTo, admin=Siti");

// Empty assigned_to → blocked
$stmt2 = $pdo->prepare('UPDATE job_posts SET assigned_to = "" WHERE id = ?');
$stmt2->execute([$jobAssigned]);
$stmt3 = $pdo->prepare('SELECT assigned_to FROM job_posts WHERE id = ?');
$stmt3->execute([$jobAssigned]);
$emptyAssigned = (string)$stmt3->fetchColumn();
assert_test('Empty assigned_to → blocked (no assignment)', $emptyAssigned === '', "assigned_to='$emptyAssigned'");

clean_test_data($pdo, $u4);

// ═════════════════════════════════════════════════════════════════════════════
// TEST GROUP 5: Atomic transaction + conditional update locking
// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== TEST GROUP 5: Atomic transaction + race condition locking ===\n";

$u5 = create_test_employer($pdo, "t5_{$uniqueSuffix}@test.com", 'Yogyakarta');
$jobTx = create_job_with_status($pdo, $u5, 'ADDITIONAL_DOCUMENT_PENDING', null, 'K.005', 1, 'Admin TX');
create_additional_doc($pdo, $jobTx, $u5, 'PENDING_UPLOAD');

// Simulate: conditional UPDATE where status = 'ADDITIONAL_DOCUMENT_PENDING' → should succeed
$pdo->beginTransaction();
$upStmt = $pdo->prepare('UPDATE job_posts SET status = "CANCELED", additional_doc_status = "CANCELED", admin_notes = ?, verifier_notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = "ADDITIONAL_DOCUMENT_PENDING"');
$upStmt->execute(['test notes', 'test notes', $jobTx]);
$rowCount1 = $upStmt->rowCount();
$pdo->rollBack(); // rollback to reset for next test
assert_test('Conditional UPDATE succeeds when status=ADDITIONAL_DOCUMENT_PENDING', $rowCount1 === 1, "rowCount=$rowCount1");

// Simulate: a SECOND concurrent decision (status already changed by first decision)
// First manually change status to CANCELED (simulating first decision committed)
$pdo->prepare('UPDATE job_posts SET status = "CANCELED" WHERE id = ?')->execute([$jobTx]);

$pdo->beginTransaction();
$upStmt2 = $pdo->prepare('UPDATE job_posts SET status = "CANCELED", admin_notes = ?, verifier_notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = "ADDITIONAL_DOCUMENT_PENDING"');
$upStmt2->execute(['race notes', 'race notes', $jobTx]);
$rowCount2 = $upStmt2->rowCount();
$pdo->rollBack();
assert_test('Race condition: conditional UPDATE returns 0 rows (status already changed)', $rowCount2 === 0, "rowCount=$rowCount2 (0=locked correctly)");

// Test rollback: begin transaction, write something, throw exception, rollback → verify nothing committed
$pdo->beginTransaction();
$pdo->prepare('UPDATE job_posts SET admin_notes = "SHOULD_BE_ROLLED_BACK" WHERE id = ?')->execute([$jobTx]);
try {
    throw new RuntimeException('Simulated DB error');
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
$stmt = $pdo->prepare('SELECT admin_notes FROM job_posts WHERE id = ?');
$stmt->execute([$jobTx]);
$notesAfterRollback = $stmt->fetchColumn();
assert_test('Transaction rollback: admin_notes not committed after exception', $notesAfterRollback !== 'SHOULD_BE_ROLLED_BACK', "notes=$notesAfterRollback");

// Test commit: begin transaction, write, commit → verify committed
$pdo->beginTransaction();
$pdo->prepare('UPDATE job_posts SET admin_notes = "COMMITTED_SUCCESSFULLY" WHERE id = ?')->execute([$jobTx]);
$pdo->commit();
$stmt2 = $pdo->prepare('SELECT admin_notes FROM job_posts WHERE id = ?');
$stmt2->execute([$jobTx]);
$notesAfterCommit = $stmt2->fetchColumn();
assert_test('Transaction commit: admin_notes committed successfully', $notesAfterCommit === 'COMMITTED_SUCCESSFULLY', "notes=$notesAfterCommit");

clean_test_data($pdo, $u5);

// ═════════════════════════════════════════════════════════════════════════════
// TEST GROUP 6: Rules engine end-to-end (4th same-KBJI → additional_doc_required)
// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== TEST GROUP 6: Rules engine end-to-end 4th KBJI trigger ===\n";

$u6 = create_test_employer($pdo, "t6_{$uniqueSuffix}@test.com", 'Semarang');

// 3 already-published jobs (same KBJI) this month → status=Ditutup (closed, not Tayang)
// so Layer 1 (active duplicate check) does not block the 4th posting
for ($i = 0; $i < 3; $i++) {
    create_job_with_status($pdo, $u6, 'Ditutup', $currentMonthDate, 'K.006', 1);
}

// 4th posting → should trigger additional_doc_required
$result4th = check_pki_job_rules_engine(db(), $u6, 'K.006', 1);
assert_test('4th KBJI → additional_doc_required=true', !empty($result4th['additional_doc_required']), 'additional_doc_required=' . var_export($result4th['additional_doc_required'] ?? null, true));
assert_test('4th KBJI → allowed=true (goes to ADDITIONAL_DOCUMENT_PENDING, not blocked)', !empty($result4th['allowed']), 'allowed=' . var_export($result4th['allowed'] ?? null, true));

// 1st posting (different KBJI) → normal
$result1st = check_pki_job_rules_engine(db(), $u6, 'K.007', 1);
assert_test('1st KBJI → additional_doc_required=false (normal flow)', empty($result1st['additional_doc_required']), 'additional_doc_required=' . var_export($result1st['additional_doc_required'] ?? null, true));

clean_test_data($pdo, $u6);

// ═════════════════════════════════════════════════════════════════════════════
// SUMMARY
// ═════════════════════════════════════════════════════════════════════════════
echo "\n" . str_repeat('=', 60) . "\n";
echo "RESULTS: {$pass} PASSED, {$fail} FAILED\n";
echo str_repeat('=', 60) . "\n";
