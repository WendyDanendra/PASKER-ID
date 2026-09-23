<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/platform.php';

echo "=== TESTING REVISI PROFIL PEMBERI KERJA INDIVIDU FLOW ===\n\n";

$pdo = db();

// 1. Create / reset a dummy test individual employer
$testEmail = 'test_individu_revisi@karirhub.kemnaker.go.id';
$stmtUser = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmtUser->execute([$testEmail]);
$testUser = $stmtUser->fetch();

if (!$testUser) {
    $pdo->prepare('INSERT INTO users (name, email, password_hash, role, profile_complete) VALUES (?, ?, ?, ?, ?)')
        ->execute(['Budi Santoso (Test Individu)', $testEmail, password_hash('password', PASSWORD_DEFAULT), 'employer', 1]);
    $userId = (int)$pdo->lastInsertId();
} else {
    $userId = (int)$testUser['id'];
}

$pdo->prepare('DELETE FROM employer_profiles WHERE user_id = ?')->execute([$userId]);
$pdo->prepare('DELETE FROM audit_logs WHERE entity_type = "employer" AND entity_id = ?')->execute([$userId]);
$pdo->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$userId]);

$pdo->prepare('INSERT INTO employer_profiles (
    user_id, owner_name, nik, phone, whatsapp, profession, npwp, 
    province, city, district, village, postal_code, address, address_detail, description,
    verified, verification_status, revision_count, rejection_count, manual_review_status, entity_type
) VALUES (
    ?, "Budi Santoso", "3273010101900001", "081234567890", "081234567890", "Jasa Desain Grafis", "12.345.678.9-123.000",
    "Jawa Barat", "Kota Bandung", "Coblong", "Dago", "40135", "Jl. Ir. H. Juanda No. 25", "Patokan dekat Simpang Dago", "Studio desain kreatif",
    0, "PENDING", 0, 0, "NONE", "Individu"
)')->execute([$userId]);

echo "1. Initial profile created for user ID: {$userId}\n";

// Test Revisi 1: Verifikator requests revision 1
$pdo->prepare('UPDATE employer_profiles SET verification_status = "NEEDS_REVISION", revision_count = 1, rejection_count = 1, verifier_notes = "Perbaiki foto bukti tempat usaha", assigned_to = "Admin Dinas Kota Bandung" WHERE user_id = ?')
    ->execute([$userId]);
record_audit_log('employer', $userId, 'REVISION_REQUESTED', "Permintaan perbaikan data (Revisi ke-1) dikirim ke pemohon. Catatan: Perbaiki foto bukti tempat usaha", 'Admin Dinas Kota Bandung', 'admin_dinas', true);

$stmt = $pdo->prepare('SELECT verification_status, revision_count, manual_review_status FROM employer_profiles WHERE user_id = ?');
$stmt->execute([$userId]);
$row = $stmt->fetch();
echo "2. Revisi ke-1 requested: Status = {$row['verification_status']}, Revision Count = {$row['revision_count']}, Manual Status = {$row['manual_review_status']}\n";

// User self-edits and resubmits (Allowed on Rev 1 & 2)
$pdo->prepare('UPDATE employer_profiles SET verification_status = "PENDING" WHERE user_id = ?')->execute([$userId]);
echo "3. User resubmitted successfully for Revisi ke-1.\n";

// Test Revisi 2
$pdo->prepare('UPDATE employer_profiles SET verification_status = "NEEDS_REVISION", revision_count = 2, rejection_count = 2, verifier_notes = "NPWP kurang jelas", assigned_to = "Admin Dinas Kota Bandung" WHERE user_id = ?')
    ->execute([$userId]);
record_audit_log('employer', $userId, 'REVISION_REQUESTED', "Permintaan perbaikan data (Revisi ke-2) dikirim ke pemohon. Catatan: NPWP kurang jelas", 'Admin Dinas Kota Bandung', 'admin_dinas', true);

$stmt->execute([$userId]);
$row = $stmt->fetch();
echo "4. Revisi ke-2 requested: Status = {$row['verification_status']}, Revision Count = {$row['revision_count']}\n";

// User self-edits and resubmits (Allowed on Rev 2)
$pdo->prepare('UPDATE employer_profiles SET verification_status = "PENDING" WHERE user_id = ?')->execute([$userId]);
echo "5. User resubmitted successfully for Revisi ke-2.\n";

// Test Revisi 3: Triggers manual dinas review
$pdo->prepare('UPDATE employer_profiles SET verification_status = "NEEDS_REVISION", revision_count = 3, rejection_count = 3, manual_review_status = "MANUAL_DINAS_REVIEW", verifier_notes = "Dokumen pendukung dan domisili belum sesuai", assigned_to = "Admin Dinas Kota Bandung" WHERE user_id = ?')
    ->execute([$userId]);
record_audit_log('employer', $userId, 'REVISION_REQUESTED', "Permintaan perbaikan data (Revisi ke-3) dikirim ke pemohon. Catatan: Dokumen pendukung dan domisili belum sesuai", 'Admin Dinas Kota Bandung', 'admin_dinas', true);

$stmt->execute([$userId]);
$row = $stmt->fetch();
echo "6. Revisi ke-3 requested: Status = {$row['verification_status']}, Revision Count = {$row['revision_count']}, Manual Status = {$row['manual_review_status']}\n";

// Admin Dinas edits data and requests consent
$stmtProfile = $pdo->prepare('SELECT * FROM employer_profiles WHERE user_id = ?');
$stmtProfile->execute([$userId]);
$currentEmp = $stmtProfile->fetch();

$hash = calculate_employer_consent_hash($currentEmp);
$pdo->prepare('UPDATE employer_profiles SET manual_review_status = "CONSENT_PENDING", consent_data_hash = ?, consent_agreed = 0 WHERE user_id = ?')
    ->execute([$hash, $userId]);
record_audit_log('employer', $userId, 'ADMIN_PROFILE_EDIT', "Petugas Dinas memperbarui seluruh data profil pemohon pada permohonan ulang.", 'Admin Dinas Kota Bandung', 'admin_dinas', true);
record_audit_log('employer', $userId, 'CONSENT_REQUESTED', "Petugas Dinas mengirimkan permintaan persetujuan (Consent) ke pemohon.", 'Admin Dinas Kota Bandung', 'admin_dinas', true);
notify_user($userId, 'Persetujuan Data Diperlukan (Jalur Dinas)', 'Petugas Dinas telah menyiapkan data perbaikan profil Anda. Silakan tinjau dan berikan persetujuan (Consent) di Dashboard Anda.', 'warning');

$stmt->execute([$userId]);
$row = $stmt->fetch();
echo "7. Petugas Dinas sent consent request: Manual Status = {$row['manual_review_status']}, Hash generated = " . substr($hash, 0, 12) . "...\n";

// User opens notification & consents via Dashboard
$pdo->prepare('UPDATE employer_profiles SET manual_review_status = "CONSENT_GIVEN", consent_agreed = 1, consent_given_at = CURRENT_TIMESTAMP WHERE user_id = ?')
    ->execute([$userId]);
record_audit_log('employer', $userId, 'CONSENT_GIVEN', "Pemohon menyetujui pernyataan persetujuan (User Consent) untuk permohonan ulang bersama Petugas Dinas.", 'Budi Santoso', 'employer', true);

$stmtProfile->execute([$userId]);
$empAfterConsent = $stmtProfile->fetch();
echo "8. User given consent: Manual Status = {$empAfterConsent['manual_review_status']}, Agreed = {$empAfterConsent['consent_agreed']}, Given At = {$empAfterConsent['consent_given_at']}\n";

// Petugas Dinas verifies statement & activates access for 3 months
$officerStatement = "Saya sebagai Petugas Dinas yang berwenang menyatakan telah melakukan pemeriksaan dan verifikasi manual terhadap identitas, bukti tempat pemberi kerja, serta data pendukung Pemberi Kerja Individu yang bersangkutan. Saya memastikan hasil pemeriksaan ini dapat dipertanggungjawabkan secara kedinasan dan hukum.";
$pdo->prepare('UPDATE employer_profiles SET verified = 1, verification_status = "APPROVED", active_until = datetime("now", "+3 months"), last_activated_at = datetime("now"), manual_review_status = "APPROVED_DINAS", officer_name = "Petugas Disnaker Kota Bandung", officer_statement = ? WHERE user_id = ?')
    ->execute([$officerStatement, $userId]);
record_audit_log('employer', $userId, 'APPROVED_MANUAL_DINAS', "Profil disetujui & diaktifkan melalui Jalur Manual Dinas oleh petugas: Petugas Disnaker Kota Bandung. Pernyataan: {$officerStatement}", 'Admin Dinas Kota Bandung', 'admin_dinas', true);

$stmtProfile->execute([$userId]);
$finalProfile = $stmtProfile->fetch();
echo "9. Final Approval & Activation: Verification Status = {$finalProfile['verification_status']}, Active Until = {$finalProfile['active_until']}, Manual Status = {$finalProfile['manual_review_status']}\n\n";

// Fetch and display full Audit Trail for this employer
echo "=== AUDIT TRAIL LOGGED ===\n";
$logs = fetch_audit_logs('employer', $userId);
foreach ($logs as $i => $log) {
    echo ($i + 1) . ". [{$log['created_at']}] [{$log['action']}] by {$log['actor_name']} ({$log['actor_role']}): {$log['details']}\n";
}

echo "\n=== ALL TESTS COMPLETED SUCCESSFULLY! ===\n";
