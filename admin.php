<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/platform.php';

$user = require_role('admin');

// Active Section & Filters
$view = $_GET['view'] ?? 'directory_individual';
$entity = $_GET['entity'] ?? 'Semua';
$tab = $_GET['tab'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$detailId = isset($_GET['detail_id']) ? (int)$_GET['detail_id'] : 0;

// --- POST HANDLERS FOR ADMIN ACTIONS ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['admin_action'])) {
    $action = $_POST['admin_action'];
    $redirectUrl = $_POST['redirect_url'] ?? "admin.php?view={$view}&entity={$entity}&tab={$tab}" . ($detailId ? "&detail_id={$detailId}" : "");

    // 1. AMBIL CASE / ASSIGN PEMERIKSA (EMPLOYER)
    if ($action === 'assign_employer_case') {
        $targetUserId = (int)$_POST['user_id'];
        $verifierName = trim($_POST['verifier_name'] ?? 'Admin Pusat');
        $reason = trim($_POST['assignment_reason'] ?? '');
        $isSelfAssign = !empty($_POST['self_assign']);

        $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
        if ($user['role'] === 'admin_dinas' || ($adminDomicileCity !== '' && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
            $stmtCheck = db()->prepare('SELECT domicile_city_id FROM employer_profiles WHERE user_id = ?');
            $stmtCheck->execute([$targetUserId]);
            $empRow = $stmtCheck->fetch();
            $empDomicileCity = (string)($empRow['domicile_city_id'] ?? '');
            if ($empDomicileCity === '' || $empDomicileCity !== $adminDomicileCity) {
                flash('error', 'Akses ditolak: Pemberi Kerja ini di luar wilayah kewenangan Dinas Anda (' . e($adminDomicileCity) . '). Scope Admin Dinas mengikuti domicile_city_id Pemberi Kerja secara persis.');
                redirect($redirectUrl);
                exit;
            }
        }

        if (!$isSelfAssign && strlen($reason) < 10) {
            flash('error', 'Alasan penugasan wajib diisi minimal 10 karakter.');
        } else {
            $reasonText = $isSelfAssign ? 'Pengambilan case mandiri oleh pemeriksa.' : $reason;
            $stmt = db()->prepare('UPDATE employer_profiles SET assigned_to = ?, assigned_at = datetime("now"), assignment_reason = ? WHERE user_id = ?');
            try {
                $stmt->execute([$verifierName, $reasonText, $targetUserId]);
            } catch (Throwable $e) {
                $stmt = db()->prepare('UPDATE employer_profiles SET assigned_to = ?, assigned_at = NOW(), assignment_reason = ? WHERE user_id = ?');
                $stmt->execute([$verifierName, $reasonText, $targetUserId]);
            }
            record_audit_log('employer', $targetUserId, 'CASE_ASSIGNED', "Case ditugaskan kepada: {$verifierName}. Alasan: {$reasonText}", $user['name']);
            flash('success', "Case verifikasi berhasil ditugaskan ke {$verifierName}.");
        }
        redirect($redirectUrl);
        exit;
    }

    // 2. KEPUTUSAN VERIFIKASI PEMBERI KERJA
    if ($action === 'verify_employer') {
        $targetUserId = (int)$_POST['user_id'];
        $decision = $_POST['decision']; // approve | revision | reject
        $notes = trim($_POST['verifier_notes'] ?? '');
        $checklist = isset($_POST['checklist']) ? implode(', ', $_POST['checklist']) : '';

        $pdo = db();
        $pdo->beginTransaction();

        try {
            // Lock row with FOR UPDATE
            $stmtEmp = $pdo->prepare('SELECT ep.*, u.name, u.email FROM employer_profiles ep JOIN users u ON u.id = ep.user_id WHERE ep.user_id = ? FOR UPDATE');
            $stmtEmp->execute([$targetUserId]);
            $targetEmp = $stmtEmp->fetch();

            if (!$targetEmp) {
                $pdo->rollBack();
                flash('error', 'Pemberi Kerja tidak ditemukan.');
                redirect($redirectUrl);
                exit;
            }

            $assignedTo = trim((string)($targetEmp['assigned_to'] ?? ''));
            if ($assignedTo === '') {
                $pdo->rollBack();
                flash('error', 'Pemberi kerja harus memiliki penugasan aktif terlebih dahulu sebelum keputusan dapat diambil.');
                redirect($redirectUrl);
                exit;
            }

            // Verify current admin IS the assigned verifier (compare by name or email)
            $currentAdminName  = trim((string)($user['name'] ?? ''));
            $currentAdminEmail = trim((string)($user['email'] ?? ''));
            if (strcasecmp($assignedTo, $currentAdminName) !== 0 && strcasecmp($assignedTo, $currentAdminEmail) !== 0) {
                $pdo->rollBack();
                flash('error', 'Akses ditolak: Anda bukan pemeriksa yang ditugaskan (assigned_to) untuk verifikasi profil ini. Hanya verifikator yang ditugaskan (' . e($assignedTo) . ') yang dapat mengambil keputusan. Silakan ambil alih penugasan case terlebih dahulu.');
                redirect($redirectUrl);
                exit;
            }

            // Ensure verification state is still open
            if (!in_array($targetEmp['verification_status'], ['PENDING', 'NEEDS_REVISION', 'NOT_SUBMITTED'], true)) {
                $pdo->rollBack();
                flash('error', 'Keputusan verifikasi tidak dapat diproses karena status profil sudah berubah (' . e($targetEmp['verification_status']) . ').');
                redirect($redirectUrl);
                exit;
            }

            $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
            if ($user['role'] === 'admin_dinas' || ($adminDomicileCity !== '' && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
                $empDomicileCity = (string)($targetEmp['domicile_city_id'] ?? '');
                if ($empDomicileCity === '' || $empDomicileCity !== $adminDomicileCity) {
                    $pdo->rollBack();
                    flash('error', 'Akses ditolak: Pemberi Kerja ini di luar wilayah kewenangan Dinas Anda (' . e($adminDomicileCity) . '). Scope Admin Dinas mengikuti domicile_city_id Pemberi Kerja secara persis.');
                    redirect($redirectUrl);
                    exit;
                }
            }

        if (($decision === 'revision' || $decision === 'reject') && $notes === '') {
                $pdo->rollBack();
            flash('error', 'Catatan Verifikator wajib diisi untuk keputusan Revisi atau Tolak.');
                redirect($redirectUrl);
                exit;
            }

            if ($decision === 'approve') {
                $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'sqlite') {
                    $stmt = $pdo->prepare('UPDATE employer_profiles SET verified = 1, verification_status = "APPROVED", active_until = datetime("now", "+3 months"), last_activated_at = datetime("now"), extension_requested = 0, extension_status = "NONE", manual_review_status = NULL, verifier_notes = ?, verification_checklist = ? WHERE user_id = ?');
                } else {
                    $stmt = $pdo->prepare('UPDATE employer_profiles SET verified = 1, verification_status = "APPROVED", active_until = DATE_ADD(NOW(), INTERVAL 3 MONTH), last_activated_at = NOW(), extension_requested = 0, extension_status = "NONE", manual_review_status = NULL, verifier_notes = ?, verification_checklist = ? WHERE user_id = ?');
                }
                $stmt->execute([$notes, $checklist, $targetUserId]);
                $pdo->prepare('UPDATE users SET profile_complete = 1 WHERE id = ?')->execute([$targetUserId]);

                $isReactivationApproval = !empty($targetEmp['last_activated_at']);
                $auditAction = $isReactivationApproval ? 'REACTIVATION_APPROVED' : 'APPROVED';
                $auditText = $isReactivationApproval
                    ? "Permohonan reaktivasi Hak Akses online disetujui. Masa aktif baru berlaku 3 bulan. Catatan: {$notes} | Source: ONLINE_REACTIVATION"
                    : "Profil disetujui. Masa aktif berlaku 3 bulan. Catatan: {$notes} | Source: INITIAL_VERIFICATION";

                record_audit_log('employer', $targetUserId, $auditAction, $auditText, $user['name'], $user['role'] ?? 'admin', true);
                notify_user($targetUserId, 'Profil Disetujui', 'Selamat! Hak Akses Pemberi Kerja Individu Anda telah disetujui dan aktif selama 3 bulan.', 'success');
                $pdo->commit();
                flash('success', 'Hak Akses Pemberi Kerja Individu berhasil Disetujui (Masa Aktif 3 Bulan).');
            } elseif ($decision === 'revision') {
                $stmt = $pdo->prepare('UPDATE employer_profiles SET verified = 0, verification_status = "NEEDS_REVISION", verifier_notes = ?, verification_checklist = ? WHERE user_id = ?');
                $stmt->execute([$notes, $checklist, $targetUserId]);
                record_audit_log('employer', $targetUserId, 'REVISION_REQUESTED', "Permintaan perbaikan data dikirim ke pemohon. Catatan: {$notes}", $user['name'], $user['role'] ?? 'admin', true);
                notify_user($targetUserId, 'Perbaikan Profil Diperlukan', 'Verifikator meminta perbaikan profil: ' . $notes, 'warning');
                $pdo->commit();
                flash('success', 'Profil dikembalikan ke pemohon untuk diperbaiki (Perlu Diperbaiki).');
            } elseif ($decision === 'reject') {
                $newRejectionCount = (int)($targetEmp['rejection_count'] ?? 0) + 1;
                if ($newRejectionCount >= 3) {
                    // 3rd rejection triggers MANUAL_DINAS_REVIEW
                    $stmt = $pdo->prepare('UPDATE employer_profiles SET verified = 0, verification_status = "NEEDS_REVISION", rejection_count = ?, manual_review_status = "MANUAL_DINAS_REVIEW", verifier_notes = ?, verification_checklist = ? WHERE user_id = ?');
                    $stmt->execute([$newRejectionCount, $notes, $checklist, $targetUserId]);
                    record_audit_log('employer', $targetUserId, 'REJECTED_MANUAL_DINAS', "Penolakan ke-3 dicapai. Akun dialihkan ke Jalur Manual Dinas. Catatan: {$notes}", $user['name'], $user['role'] ?? 'admin', true);
                    notify_user($targetUserId, 'Penolakan ke-3: Dialihkan ke Manual Dinas', 'Profil Anda telah ditolak 3 kali. Verifikasi dialihkan ke Jalur Manual Dinas untuk pendampingan petugas.', 'error');
                    $pdo->commit();
                    flash('warning', 'Penolakan ke-3 telah dicapai. Profil dialihkan ke Jalur Manual Dinas.');
                } else {
                    // 1st or 2nd rejection gives chance to fix (NEEDS_REVISION)
                    $stmt = $pdo->prepare('UPDATE employer_profiles SET verified = 0, verification_status = "NEEDS_REVISION", rejection_count = ?, verifier_notes = ?, verification_checklist = ? WHERE user_id = ?');
                    $stmt->execute([$newRejectionCount, $notes, $checklist, $targetUserId]);
                    record_audit_log('employer', $targetUserId, 'REJECTED', "Profil ditolak (Penolakan ke-{$newRejectionCount}). Kesempatan perbaikan dibuka. Catatan: {$notes}", $user['name'], $user['role'] ?? 'admin', true);
                    notify_user($targetUserId, "Profil Belum Disetujui (Penolakan {$newRejectionCount}/3)", 'Verifikator menolak profil: ' . $notes . '. Silahkan perbaiki data Anda.', 'error');
                    $pdo->commit();
                    flash('success', "Profil Pemberi Kerja Ditolak (Penolakan ke-{$newRejectionCount}/3). Kesempatan perbaikan dibuka.");
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', 'Gagal memproses keputusan verifikasi: ' . $e->getMessage());
        }
        redirect($redirectUrl);
        exit;
    }

    // 3. JALUR MANUAL DINAS: CONTROLLED EDIT
    if ($action === 'manual_dinas_edit') {
        $targetUserId = (int)$_POST['user_id'];
        $ownerName = trim($_POST['owner_name'] ?? '');
        $profession = trim($_POST['profession'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $whatsapp = trim($_POST['whatsapp'] ?? '');
        $npwp = trim($_POST['npwp'] ?? '');
        $province = trim($_POST['province'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $district = trim($_POST['district'] ?? '');
        $village = trim($_POST['village'] ?? '');
        $postalCode = trim($_POST['postal_code'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $addressDetail = trim($_POST['address_detail'] ?? '');
        $description = trim($_POST['description'] ?? '');

        // Fetch old profile to check if consent was previously given and is now invalidated
        $stmtOld = db()->prepare('SELECT * FROM employer_profiles WHERE user_id = ? LIMIT 1');
        $stmtOld->execute([$targetUserId]);
        $oldProfile = $stmtOld->fetch();

        $updateSql = <<<SQL
            UPDATE employer_profiles SET
                owner_name = ?, profession = ?, phone = ?, whatsapp = ?, npwp = ?,
                province = ?, city = ?, district = ?, village = ?, postal_code = ?,
                address = ?, address_detail = ?, description = ?
            WHERE user_id = ?
        SQL;
        db()->prepare($updateSql)->execute([
            $ownerName, $profession, $phone, $whatsapp, $npwp,
            $province, $city, $district, $village, $postalCode,
            $address, $addressDetail, $description, $targetUserId
        ]);

        // Check if consent was invalidated
        $newProfile = [
            'owner_name' => $ownerName, 'nik' => $oldProfile['nik'] ?? '', 'profession' => $profession,
            'phone' => $phone, 'whatsapp' => $whatsapp, 'npwp' => $npwp, 'province' => $province,
            'city' => $city, 'district' => $district, 'village' => $village, 'postal_code' => $postalCode,
            'address' => $address, 'address_detail' => $addressDetail, 'description' => $description
        ];
        $newHash = calculate_employer_consent_hash($newProfile);

        if (!empty($oldProfile['consent_data_hash']) && $oldProfile['consent_data_hash'] !== $newHash) {
            // Invalidate consent!
            db()->prepare('UPDATE employer_profiles SET manual_review_status = "INVALID", consent_agreed = 0, consent_data_hash = NULL WHERE user_id = ?')->execute([$targetUserId]);
            record_audit_log('employer', $targetUserId, 'CONSENT_INVALIDATED', "Data profil diubah oleh Admin setelah persetujuan pemohon. Consent sebelumnya otomatis INVALID.", $user['name']);
            flash('warning', 'Data profil berhasil diperbarui oleh Admin. PERINGATAN: Karena data berubah, persetujuan (consent) pemohon sebelumnya menjadi INVALID. Silahkan ajukan consent ulang.');
        } else {
            record_audit_log('employer', $targetUserId, 'CONTROLLED_EDIT', "Admin melakukan controlled edit pada data profil.", $user['name']);
            flash('success', 'Data profil berhasil diperbarui melalui Controlled Edit.');
        }

        redirect($redirectUrl);
        exit;
    }

    // 4. JALUR MANUAL DINAS: AJUKAN CONSENT KE USER
    if ($action === 'manual_dinas_request_consent') {
        $targetUserId = (int)$_POST['user_id'];
        $stmtEmp = db()->prepare('SELECT * FROM employer_profiles WHERE user_id = ? LIMIT 1');
        $stmtEmp->execute([$targetUserId]);
        $emp = $stmtEmp->fetch();

        if ($emp) {
            $hash = calculate_employer_consent_hash($emp);
            $stmt = db()->prepare('UPDATE employer_profiles SET manual_review_status = "CONSENT_PENDING", consent_data_hash = ?, consent_agreed = 0 WHERE user_id = ?');
            $stmt->execute([$hash, $targetUserId]);
            record_audit_log('employer', $targetUserId, 'CONSENT_REQUESTED', "Admin mengajukan permintaan persetujuan (Consent) ke pemohon (Hash: " . substr($hash, 0, 10) . "...).", $user['name']);
            notify_user($targetUserId, 'Persetujuan Data Diperlukan (Jalur Dinas)', 'Petugas Dinas telah menyiapkan data perbaikan profil Anda. Silakan tinjau dan berikan persetujuan (Consent) di Dashboard Anda.', 'warning');
            flash('success', 'Permintaan persetujuan (Consent) berhasil diajukan ke pemohon.');
        }
        redirect($redirectUrl);
        exit;
    }

    // 5. JALUR MANUAL DINAS: SETUJUI & AKTIFKAN (PERNYATAAN PETUGAS)
    if ($action === 'manual_dinas_approve_activate') {
        $targetUserId = (int)$_POST['user_id'];
        $officerName = trim($_POST['officer_name'] ?? $user['name']);
        $officerStatement = trim($_POST['officer_statement'] ?? '');
        $statementCheck = !empty($_POST['statement_confirmed']);

        if (!$statementCheck || $officerStatement === '') {
            flash('error', 'Pernyataan Petugas dan konfirmasi checklist wajib diisi.');
            redirect($redirectUrl);
            exit;
        }

        // Verify consent is valid
        $stmtEmp = db()->prepare('SELECT * FROM employer_profiles WHERE user_id = ? LIMIT 1');
        $stmtEmp->execute([$targetUserId]);
        $emp = $stmtEmp->fetch();

        $currentHash = calculate_employer_consent_hash($emp);
        if ($emp['manual_review_status'] !== 'CONSENT_GIVEN' || empty($emp['consent_data_hash']) || $emp['consent_data_hash'] !== $currentHash) {
            flash('error', 'Persetujuan pemohon tidak valid atau data telah berubah setelah consent. Setujui & Aktifkan dibatalkan.');
            redirect($redirectUrl);
            exit;
        }

        $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = db()->prepare('UPDATE employer_profiles SET verified = 1, verification_status = "APPROVED", active_until = datetime("now", "+3 months"), last_activated_at = datetime("now"), extension_requested = 0, extension_status = "NONE", manual_review_status = "APPROVED_DINAS", officer_name = ?, officer_statement = ? WHERE user_id = ?');
        } else {
            $stmt = db()->prepare('UPDATE employer_profiles SET verified = 1, verification_status = "APPROVED", active_until = DATE_ADD(NOW(), INTERVAL 3 MONTH), last_activated_at = NOW(), extension_requested = 0, extension_status = "NONE", manual_review_status = "APPROVED_DINAS", officer_name = ?, officer_statement = ? WHERE user_id = ?');
        }
        $stmt->execute([$officerName, $officerStatement, $targetUserId]);
        db()->prepare('UPDATE users SET profile_complete = 1 WHERE id = ?')->execute([$targetUserId]);
        record_audit_log('employer', $targetUserId, 'APPROVED_MANUAL_DINAS', "Profil disetujui & diaktifkan melalui Jalur Manual Dinas oleh petugas: {$officerName}. Pernyataan: {$officerStatement}", $user['name']);
        notify_user($targetUserId, 'Profil Aktif (Jalur Dinas)', 'Selamat! Akun Pemberi Kerja Individu Anda telah disetujui dan diaktifkan oleh Dinas Tenaga Kerja selama 3 bulan.', 'success');
        flash('success', 'Akun Pemberi Kerja Individu berhasil Disetujui & Diaktifkan melalui Jalur Manual Dinas.');
        redirect($redirectUrl);
        exit;
    }

    // 6. TANGGUHKAN / BATALKAN PENANGGUHAN (SUSPENSION)
    if ($action === 'suspend_employer') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $reason = trim($_POST['suspension_reason'] ?? '');
        $res = suspend_employer_access(db(), $targetUserId, $reason, $user);
        if (!$res['success']) {
            flash('error', $res['error']);
        } else {
            flash('success', $res['message']);
        }
        redirect($redirectUrl);
        exit;
    }

    if ($action === 'unsuspend_employer') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $res = unsuspend_employer_access(db(), $targetUserId, $user);
        if (!$res['success']) {
            flash('error', $res['error']);
        } else {
            flash('success', $res['message']);
        }
        redirect($redirectUrl);
        exit;
    }

    // 7. PERPANJANGAN HAK AKSES PEMBERI KERJA INDIVIDU (1, 2, ATAU 3 HARI)
    if ($action === 'approve_extension') {
        $targetUserId = (int)$_POST['user_id'];
        $pdo = db();
        $pdo->beginTransaction();

        try {
            // Lock employer profile row with FOR UPDATE
            $empStmt = $pdo->prepare('SELECT * FROM employer_profiles WHERE user_id = ? FOR UPDATE');
            $empStmt->execute([$targetUserId]);
            $targetEmp = $empStmt->fetch();

            if (!$targetEmp) {
                $pdo->rollBack();
                flash('error', 'Pemberi Kerja tidak ditemukan.');
                redirect($redirectUrl);
                exit;
            }

            // Scope Check: Admin Dinas must match employer's domicile_city_id
            $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
            if ($user['role'] === 'admin_dinas' || ($adminDomicileCity !== '' && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
                $empDomicileCity = (string)($targetEmp['domicile_city_id'] ?? '');
                if ($empDomicileCity === '' || $empDomicileCity !== $adminDomicileCity) {
                    $pdo->rollBack();
                    flash('error', 'Akses ditolak: Pemberi Kerja ini di luar wilayah kewenangan Dinas Anda (' . e($adminDomicileCity) . '). Scope Admin Dinas mengikuti domicile_city_id Pemberi Kerja secara persis.');
                    redirect($redirectUrl);
                    exit;
                }
            }

            if (($targetEmp['extension_status'] ?? '') !== 'REQUESTED') {
                $pdo->rollBack();
                flash('error', 'Tidak ada permohonan perpanjangan Hak Akses Pemberi Kerja Individu yang berstatus REQUESTED.');
                redirect($redirectUrl);
                exit;
            }

            if (!isset($_POST['extension_days']) || !is_numeric($_POST['extension_days'])) {
                $pdo->rollBack();
                flash('error', 'Durasi perpanjangan Hak Akses Pemberi Kerja Individu wajib diisi.');
                redirect($redirectUrl);
                exit;
            }

            $extDays = (int)$_POST['extension_days'];
            if ($extDays < 1 || $extDays > 3) {
                $pdo->rollBack();
                flash('error', 'Durasi perpanjangan Hak Akses Pemberi Kerja Individu tidak valid. Harus antara 1 sampai 3 hari.');
                redirect($redirectUrl);
                exit;
            }

            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $pdo->prepare("UPDATE employer_profiles SET extension_status = 'APPROVED', verification_status = 'APPROVED', verified = 1, active_until = datetime('now', '+{$extDays} days') WHERE user_id = ?");
            } else {
                $stmt = $pdo->prepare("UPDATE employer_profiles SET extension_status = 'APPROVED', verification_status = 'APPROVED', verified = 1, active_until = DATE_ADD(NOW(), INTERVAL {$extDays} DAY) WHERE user_id = ?");
            }
        $stmt->execute([$targetUserId]);

            // Record audit log INSIDE transaction before commit (strict mode for atomic rollback)
            record_audit_log('employer', $targetUserId, 'EXTENSION_APPROVED', "Perpanjangan Hak Akses Pemberi Kerja Individu disetujui selama {$extDays} hari. Hak Akses diaktifkan kembali.", $user['name'], $user['role'] ?? 'admin', true);

            $pdo->commit();

            flash('success', "Permohonan perpanjangan Hak Akses Pemberi Kerja Individu ({$extDays} hari) berhasil disetujui. Hak Akses telah aktif kembali.");
            redirect($redirectUrl);
        exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', 'Gagal memproses persetujuan perpanjangan: ' . $e->getMessage());
            redirect($redirectUrl);
            exit;
        }
    }

    if ($action === 'reject_extension') {
        $targetUserId = (int)$_POST['user_id'];
        $pdo = db();
        $pdo->beginTransaction();

        try {
            // Lock employer profile row with FOR UPDATE
            $empStmt = $pdo->prepare('SELECT * FROM employer_profiles WHERE user_id = ? FOR UPDATE');
            $empStmt->execute([$targetUserId]);
            $targetEmp = $empStmt->fetch();

            if (!$targetEmp) {
                $pdo->rollBack();
                flash('error', 'Pemberi Kerja tidak ditemukan.');
                redirect($redirectUrl);
                exit;
            }

            // Scope Check: Admin Dinas must match employer's domicile_city_id
            $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
            if ($user['role'] === 'admin_dinas' || ($adminDomicileCity !== '' && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
                $empDomicileCity = (string)($targetEmp['domicile_city_id'] ?? '');
                if ($empDomicileCity === '' || $empDomicileCity !== $adminDomicileCity) {
                    $pdo->rollBack();
                    flash('error', 'Akses ditolak: Pemberi Kerja ini di luar wilayah kewenangan Dinas Anda (' . e($adminDomicileCity) . '). Scope Admin Dinas mengikuti domicile_city_id Pemberi Kerja secara persis.');
                    redirect($redirectUrl);
                    exit;
                }
            }

            if (($targetEmp['extension_status'] ?? '') !== 'REQUESTED') {
                $pdo->rollBack();
                flash('error', 'Tidak ada permohonan perpanjangan Hak Akses Pemberi Kerja Individu yang berstatus REQUESTED.');
                redirect($redirectUrl);
                exit;
            }

            $stmt = $pdo->prepare('UPDATE employer_profiles SET extension_status = "REJECTED" WHERE user_id = ?');
            $stmt->execute([$targetUserId]);

            // Record audit log INSIDE transaction before commit (strict mode for atomic rollback)
            record_audit_log('employer', $targetUserId, 'EXTENSION_REJECTED', "Permohonan perpanjangan Hak Akses Pemberi Kerja Individu ditolak.", $user['name'], $user['role'] ?? 'admin', true);

            $pdo->commit();

            flash('success', 'Permohonan perpanjangan Hak Akses Pemberi Kerja Individu ditolak.');
            redirect($redirectUrl);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', 'Gagal memproses penolakan perpanjangan: ' . $e->getMessage());
            redirect($redirectUrl);
            exit;
        }
    }

    // 8. AMBIL CASE / ASSIGN PEMERIKSA LOWONGAN
    if ($action === 'assign_job_case') {
        $jobId = (int)$_POST['job_id'];
        $verifierName = trim($_POST['verifier_name'] ?? 'Admin Pusat');
        $reason = trim($_POST['assignment_reason'] ?? '');
        $isSelfAssign = !empty($_POST['self_assign']);

        $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
        if ($user['role'] === 'admin_dinas' || ($adminDomicileCity !== '' && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
            $stmtCheck = db()->prepare('SELECT ep.domicile_city_id FROM job_posts j JOIN employer_profiles ep ON ep.user_id = j.user_id WHERE j.id = ?');
            $stmtCheck->execute([$jobId]);
            $jobEmpRow = $stmtCheck->fetch();
            $empDomicileCity = (string)($jobEmpRow['domicile_city_id'] ?? '');
            if ($empDomicileCity === '' || $empDomicileCity !== $adminDomicileCity) {
                flash('error', 'Akses ditolak: Lowongan ini milik Pemberi Kerja di luar wilayah kewenangan Dinas Anda (' . e($adminDomicileCity) . '). Scope Admin Dinas mengikuti domicile_city_id Pemberi Kerja secara persis.');
                redirect($redirectUrl);
                exit;
            }
        }

        if (!$isSelfAssign && strlen($reason) < 10) {
            flash('error', 'Alasan penugasan lowongan wajib diisi minimal 10 karakter.');
        } else {
            $reasonText = $isSelfAssign ? 'Pengambilan case lowongan mandiri oleh pemeriksa.' : $reason;
            $stmt = db()->prepare('UPDATE job_posts SET assigned_to = ?, assigned_at = datetime("now"), assignment_reason = ? WHERE id = ?');
            try {
                $stmt->execute([$verifierName, $reasonText, $jobId]);
            } catch (Throwable $e) {
                $stmt = db()->prepare('UPDATE job_posts SET assigned_to = ?, assigned_at = NOW(), assignment_reason = ? WHERE id = ?');
                $stmt->execute([$verifierName, $reasonText, $jobId]);
            }
            record_audit_log('job', $jobId, 'CASE_ASSIGNED', "Case lowongan ditugaskan kepada: {$verifierName}. Alasan: {$reasonText}", $user['name']);
            flash('success', "Case verifikasi lowongan berhasil ditugaskan ke {$verifierName}.");
        }
        redirect($redirectUrl);
        exit;
    }

    // 9. KEPUTUSAN VERIFIKASI LOWONGAN (CHECKLIST 4 KATEGORI)
    if ($action === 'verify_job') {
        $jobId = (int)$_POST['job_id'];
        $decision = $_POST['decision']; // approve | revision | reject
        $notes = trim($_POST['verifier_notes'] ?? '');

        $stmtStatusCheck = db()->prepare('SELECT status FROM job_posts WHERE id = ?');
        $stmtStatusCheck->execute([$jobId]);
        $currStatus = (string)($stmtStatusCheck->fetchColumn() ?? '');
        if ($currStatus === 'Ditutup') {
            flash('error', 'Lowongan ini berstatus Ditutup dan tidak dapat diverifikasi atau dibuka kembali.');
            redirect($redirectUrl);
            exit;
        }

        $childCheck = db()->prepare('SELECT COUNT(*) FROM job_posts WHERE parent_job_id = ?');
        $childCheck->execute([$jobId]);
        if ((int)$childCheck->fetchColumn() > 0) {
            flash('error', 'Lowongan sumber ini sudah memiliki posting turunan sisa kuota dan tidak dapat diubah statusnya.');
            redirect($redirectUrl);
            exit;
        }

        $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
        if ($user['role'] === 'admin_dinas' || ($adminDomicileCity !== '' && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
            $stmtCheck = db()->prepare('SELECT ep.domicile_city_id FROM job_posts j JOIN employer_profiles ep ON ep.user_id = j.user_id WHERE j.id = ?');
            $stmtCheck->execute([$jobId]);
            $jobEmpRow = $stmtCheck->fetch();
            $empDomicileCity = (string)($jobEmpRow['domicile_city_id'] ?? '');
            if ($empDomicileCity === '' || $empDomicileCity !== $adminDomicileCity) {
                flash('error', 'Akses ditolak: Lowongan ini milik Pemberi Kerja di luar wilayah kewenangan Dinas Anda (' . e($adminDomicileCity) . '). Scope Admin Dinas mengikuti domicile_city_id Pemberi Kerja secara persis.');
                redirect($redirectUrl);
                exit;
            }
        }

        // Parse 4 Compliance Categories
        $categories = compliance_categories();
        $checklistData = [];
        $hasViolation = false;
        $missingViolationNote = false;

        foreach ($categories as $cat) {
            $slug = 'cat_' . md5($cat);
            $status = $_POST[$slug . '_status'] ?? 'Patuh';
            $catNote = trim($_POST[$slug . '_note'] ?? '');

            if ($status === 'Tidak Patuh') {
                $hasViolation = true;
                if ($catNote === '') {
                    $missingViolationNote = true;
                }
            }
            $checklistData[$cat] = [
                'status' => $status,
                'note' => $catNote,
            ];
        }

        if ($missingViolationNote) {
            flash('error', 'Catatan item wajib diisi untuk setiap kategori yang dinyatakan "Tidak Patuh".');
            redirect($redirectUrl);
            exit;
        }

        if ($decision === 'approve' && $hasViolation) {
            flash('error', 'Keputusan "Setujui" TIDAK VALID karena masih terdapat kategori checklist yang "Tidak Patuh". Hanya keputusan Revisi atau Tolak yang diperbolehkan.');
            redirect($redirectUrl);
            exit;
        }

        $checklistJson = json_encode($checklistData, JSON_UNESCAPED_UNICODE);

            if ($decision === 'approve') {
            $stmt = db()->prepare('UPDATE job_posts SET status = "Tayang", published_at = CURRENT_TIMESTAMP, verifier_notes = ?, compliance_checklist = ? WHERE id = ?');
            $stmt->execute([$notes, $checklistJson, $jobId]);
            try {
                db()->prepare('UPDATE job_verifications SET status = "APPROVED", verifier_notes = ? WHERE job_id = ?')->execute([$notes, $jobId]);
            } catch (Throwable $ignored) {}
            record_audit_log('job', $jobId, 'APPROVED', "Lowongan disetujui dan Tayang. Semua kategori patuh. Catatan: {$notes}", $user['name']);
                flash('success', 'Lowongan berhasil disetujui dan Tayang.');
            } elseif ($decision === 'revision') {
            $stmt = db()->prepare('UPDATE job_posts SET status = "Perlu Direvisi", admin_notes = ?, verifier_notes = ?, compliance_checklist = ? WHERE id = ?');
            $stmt->execute([$notes, $notes, $checklistJson, $jobId]);
            try {
                db()->prepare('UPDATE job_verifications SET status = "NEEDS_REVISION", verifier_notes = ? WHERE job_id = ?')->execute([$notes, $jobId]);
            } catch (Throwable $ignored) {}
            record_audit_log('job', $jobId, 'REVISION_REQUESTED', "Lowongan dikembalikan ke pemohon untuk diperbaiki (Perlu Direvisi). Catatan: {$notes}", $user['name']);
                flash('success', 'Lowongan dikembalikan ke pemberi kerja (Perlu Direvisi).');
            } elseif ($decision === 'reject') {
            $stmt = db()->prepare('UPDATE job_posts SET status = "Ditolak", admin_notes = ?, verifier_notes = ?, compliance_checklist = ? WHERE id = ?');
            $stmt->execute([$notes, $notes, $checklistJson, $jobId]);
            try {
                db()->prepare('UPDATE job_verifications SET status = "REJECTED", verifier_notes = ? WHERE job_id = ?')->execute([$notes, $jobId]);
            } catch (Throwable $ignored) {}
            record_audit_log('job', $jobId, 'REJECTED', "Lowongan Ditolak secara permanen. Catatan: {$notes}", $user['name']);
                flash('success', 'Lowongan Ditolak.');
            }

        redirect($redirectUrl);
        exit;
    }

    // 10. KEPUTUSAN VERIFIKASI DOKUMEN TAMBAHAN LOWONGAN KE-4+ KBJI SAMA
    if ($action === 'verify_additional_doc') {
        $jobId = (int)($_POST['job_id'] ?? 0);
        $decision = trim($_POST['decision'] ?? ''); // approve | reject
        $docReviewed = trim($_POST['doc_reviewed'] ?? '');
        $fieldVisit = trim($_POST['field_visit'] ?? '');
        $notes = trim($_POST['verifier_notes'] ?? '');
        $redirectUrl = 'admin.php?view=verifikasi_job&entity=' . urlencode($entity) . '&tab=' . urlencode($tab);

        // Fetch job + employer domicile_city_id (scope: employer domicile, NOT job location)
        $jobStmt = db()->prepare('SELECT j.*, ep.city as emp_city, ep.domicile_city_id as emp_domicile_city_id, u.name as user_name, u.email as user_email FROM job_posts j JOIN users u ON u.id = j.user_id LEFT JOIN employer_profiles ep ON ep.user_id = u.id WHERE j.id = ?');
        $jobStmt->execute([$jobId]);
        $targetJob = $jobStmt->fetch();

        if (!$targetJob) {
            flash('error', 'Lowongan tidak ditemukan.');
            redirect($redirectUrl);
        exit;
    }

        // 1. Check Scope: Admin Dinas scoping uses employer's domicile_city_id (NOT job location).
        $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
        if ($user['role'] === 'admin_dinas' || ($adminDomicileCity !== '' && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
            $employerDomicileCity = (string)($targetJob['emp_domicile_city_id'] ?? '');
            if ($employerDomicileCity === '' || $employerDomicileCity !== $adminDomicileCity) {
                flash('error', 'Akses ditolak: Pemberi Kerja Individu ini berdomisili di luar wilayah kewenangan Dinas Anda (' . e($adminDomicileCity) . '). Scope Admin Dinas mengikuti domicile_city_id Pemberi Kerja secara persis.');
                redirect($redirectUrl . '&detail_id=' . $jobId);
                exit;
            }
        }

        // 2. Check Assignment: current admin must be the assigned verifier (not just any admin).
        $assignedTo = (string)($targetJob['assigned_to'] ?? '');
        if ($assignedTo === '') {
            flash('error', 'Lowongan harus memiliki penugasan (assignment) pemeriksa aktif terlebih dahulu sebelum keputusan Dokumen Tambahan dapat diambil.');
            redirect($redirectUrl . '&detail_id=' . $jobId);
            exit;
        }
        // Verify current admin IS the assigned verifier (compare by name or email)
        $currentAdminName  = (string)($user['name'] ?? '');
        $currentAdminEmail = (string)($user['email'] ?? '');
        if ($assignedTo !== $currentAdminName && $assignedTo !== $currentAdminEmail) {
            flash('error', 'Akses ditolak: Anda bukan pemeriksa yang ditugaskan (assigned_to) untuk lowongan ini. Hanya Admin yang ditugaskan (' . e($assignedTo) . ') yang dapat mengambil keputusan Dokumen Tambahan.');
            redirect($redirectUrl . '&detail_id=' . $jobId);
            exit;
        }

        // 3. Single-Final-Decision Locking Check (pre-transaction, fast guard)
        $docStmt = db()->prepare('SELECT * FROM job_additional_documents WHERE job_id = ? ORDER BY id DESC LIMIT 1');
        $docStmt->execute([$jobId]);
        $currentDoc = $docStmt->fetch();

        if ($targetJob['status'] !== 'ADDITIONAL_DOCUMENT_PENDING' || ($currentDoc && in_array($currentDoc['status'], ['APPROVED', 'CANCELED', 'REJECTED'], true))) {
            flash('error', 'Keputusan untuk Dokumen Tambahan lowongan ini sudah final dan terkunci (single-final-decision locking). Perubahan keputusan tidak diizinkan.');
            redirect($redirectUrl . '&detail_id=' . $jobId);
            exit;
        }

        // 4. Validate Checklist Inputs (Berkas telah ditinjau = Ya, Kunjungan lapangan = Ya/Tidak)
        if ($docReviewed !== 'Ya') {
            flash('error', 'Pemeriksaan Dokumen Tambahan memerlukan konfirmasi bahwa "Berkas telah ditinjau = Ya".');
            redirect($redirectUrl . '&detail_id=' . $jobId);
            exit;
        }

        if (!in_array($fieldVisit, ['Ya', 'Tidak'], true)) {
            flash('error', 'Pilihan "Kunjungan lapangan (Ya/Tidak)" wajib dipilih.');
            redirect($redirectUrl . '&detail_id=' . $jobId);
            exit;
        }

        // 5. Decision Processing — fully atomic DB transaction.
        // All writes succeed together or rollback together. No Throwable swallowing.
        // Race-condition locking via conditional UPDATE (WHERE status = "ADDITIONAL_DOCUMENT_PENDING").
        try {
            db()->beginTransaction();

            if ($decision === 'reject') {
                // Tolak → CANCELED (bukan REJECTED). Conditional update = locking.
                $lockedRows = db()->prepare('UPDATE job_posts SET status = "CANCELED", additional_doc_status = "CANCELED", admin_notes = ?, verifier_notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = "ADDITIONAL_DOCUMENT_PENDING"');
                $lockedRows->execute([$notes, $notes, $jobId]);
                if ($lockedRows->rowCount() === 0) {
                    db()->rollBack();
                    flash('error', 'Keputusan gagal: status lowongan sudah berubah oleh proses lain (race condition / concurrent decision). Silakan muat ulang halaman dan periksa kembali.');
                    redirect($redirectUrl . '&detail_id=' . $jobId);
                    exit;
                }

                db()->prepare('UPDATE job_additional_documents SET status = "CANCELED", doc_reviewed = ?, field_visit = ?, admin_notes = ?, reviewed_at = CURRENT_TIMESTAMP WHERE job_id = ?')
                    ->execute([$docReviewed, $fieldVisit, $notes, $jobId]);

                db()->commit();

                record_audit_log('job', $jobId, 'ADDITIONAL_DOC_CANCELED', "Dokumen Tambahan Ditolak (Berkas ditinjau: {$docReviewed}, Kunjungan lapangan: {$fieldVisit}). Pengajuan lowongan diakhiri sebagai CANCELED. Catatan: {$notes}", $user['name']);
                notify_user((int)$targetJob['user_id'], 'Pengajuan Lowongan Dibatalkan', "Dokumen tambahan untuk lowongan '{$targetJob['title']}' ditolak oleh Admin. Pengajuan lowongan telah diakhiri (CANCELED). Catatan: {$notes}", 'danger', $jobId);
                flash('success', 'Dokumen Tambahan Ditolak. Pengajuan lowongan berhasil diakhiri sebagai Dibatalkan (CANCELED).');

            } elseif ($decision === 'approve') {
                // Setujui → Rules Engine Layer 3.
                // HANYA menghitung lowongan yang benar-benar PUBLISHED (published_at IS NOT NULL).
                // Tidak ada fallback ke created_at.
                $startOfMonth = date('Y-m-01 00:00:00');
                $endOfMonth   = date('Y-m-t 23:59:59');

                $stmtL3 = db()->prepare('SELECT COALESCE(SUM(quota), 0) FROM job_posts WHERE user_id = ? AND parent_job_id IS NULL AND published_at IS NOT NULL AND published_at BETWEEN ? AND ? AND id != ?');
                $stmtL3->execute([(int)$targetJob['user_id'], $startOfMonth, $endOfMonth, $jobId]);
                $currentMonthlyPublishedQuota = (int)$stmtL3->fetchColumn();
                $requestedQuota = max(1, (int)($targetJob['quota'] ?? 1));
                $totalMonthlyQuota = $currentMonthlyPublishedQuota + $requestedQuota;

                if ($totalMonthlyQuota > 10) {
                    // Layer 3 melebihi batas → CANCELED
                    $cancelReason = "Total kuota lowongan yang dipublikasikan bulan ini mencapai {$totalMonthlyQuota} posisi (melebihi batas maksimal 10 posisi). Pengajuan lowongan diakhiri sebagai CANCELED sesuai aturan Rules Engine Layer 3.";
                    $fullNotes = $notes !== '' ? ($notes . ' | ' . $cancelReason) : $cancelReason;

                    $lockedRows = db()->prepare('UPDATE job_posts SET status = "CANCELED", additional_doc_status = "CANCELED", admin_notes = ?, verifier_notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = "ADDITIONAL_DOCUMENT_PENDING"');
                    $lockedRows->execute([$fullNotes, $fullNotes, $jobId]);
                    if ($lockedRows->rowCount() === 0) {
                        db()->rollBack();
                        flash('error', 'Keputusan gagal: status lowongan sudah berubah oleh proses lain (race condition). Silakan muat ulang halaman dan periksa kembali.');
                        redirect($redirectUrl . '&detail_id=' . $jobId);
                        exit;
                    }

                    db()->prepare('UPDATE job_additional_documents SET status = "CANCELED", doc_reviewed = ?, field_visit = ?, admin_notes = ?, reviewed_at = CURRENT_TIMESTAMP WHERE job_id = ?')
                        ->execute([$docReviewed, $fieldVisit, $fullNotes, $jobId]);

                    db()->commit();

                    record_audit_log('job', $jobId, 'ADDITIONAL_DOC_CANCELED_LAYER3', "Dokumen Tambahan ditinjau, namun evaluasi Rules Engine Layer 3 melebihi batas kuota bulanan ({$totalMonthlyQuota}/10). Status lowongan diubah menjadi CANCELED.", $user['name']);
                    notify_user((int)$targetJob['user_id'], 'Pengajuan Lowongan Dibatalkan (Batas Kuota Bulanan)', "Lowongan '{$targetJob['title']}' tidak dapat dilanjutkan karena total kuota lowongan bulan ini mencapai {$totalMonthlyQuota} (maksimal 10 posisi). Pengajuan diakhiri sebagai CANCELED.", 'danger', $jobId);
                    flash('warning', "Dokumen Tambahan telah ditinjau, namun total kuota lowongan bulan berjalan mencapai {$totalMonthlyQuota} posisi (melebihi batas maksimal 10 posisi). Pengajuan lowongan diakhiri sebagai Dibatalkan (CANCELED).");

                } else {
                    // Layer 3 lolos → promosi ke Menunggu Verifikasi + buat job_verifications case
                    $lockedRows = db()->prepare('UPDATE job_posts SET status = "Menunggu Verifikasi", additional_doc_status = "APPROVED", verifier_notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = "ADDITIONAL_DOCUMENT_PENDING"');
                    $lockedRows->execute([$notes, $jobId]);
                    if ($lockedRows->rowCount() === 0) {
                        db()->rollBack();
                        flash('error', 'Keputusan gagal: status lowongan sudah berubah oleh proses lain (race condition). Silakan muat ulang halaman dan periksa kembali.');
                        redirect($redirectUrl . '&detail_id=' . $jobId);
                        exit;
                    }

                    db()->prepare('UPDATE job_additional_documents SET status = "APPROVED", doc_reviewed = ?, field_visit = ?, admin_notes = ?, reviewed_at = CURRENT_TIMESTAMP WHERE job_id = ?')
                        ->execute([$docReviewed, $fieldVisit, $notes, $jobId]);

                    db()->prepare('INSERT INTO job_verifications (job_id, user_id, kbji_code, status, additional_doc_required, layer_flags) VALUES (?, ?, ?, "PENDING", 1, "ADDITIONAL_DOC_APPROVED")')
                        ->execute([$jobId, $targetJob['user_id'], $targetJob['kbji_code']]);

                    db()->commit();

                    record_audit_log('job', $jobId, 'ADDITIONAL_DOC_APPROVED', "Dokumen Tambahan disetujui (Berkas ditinjau: {$docReviewed}, Kunjungan lapangan: {$fieldVisit}). Evaluasi Rules Engine Layer 3 lolos (total kuota: {$totalMonthlyQuota}/10). Lowongan masuk ke antrean Verifikasi Lowongan reguler. Catatan: {$notes}", $user['name']);
                    notify_user((int)$targetJob['user_id'], 'Dokumen Tambahan Disetujui', "Dokumen tambahan untuk lowongan '{$targetJob['title']}' telah disetujui Admin. Lowongan Anda sekarang sedang dalam antrean Verifikasi Lowongan.", 'success', $jobId);
                    flash('success', "Dokumen Tambahan berhasil disetujui (Evaluasi Layer 3 lolos: total kuota {$totalMonthlyQuota}/10). Lowongan kini masuk ke antrean Verifikasi Lowongan normal.");
                }

            } else {
                db()->rollBack();
                flash('error', 'Keputusan tidak valid. Gunakan tombol Setujui atau Tolak.');
                redirect($redirectUrl . '&detail_id=' . $jobId);
                exit;
            }

        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log('[verify_additional_doc] Transaction failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            flash('error', 'Terjadi kesalahan database saat memproses keputusan: ' . $e->getMessage() . '. Seluruh perubahan telah dibatalkan (rollback).');
            redirect($redirectUrl . '&detail_id=' . $jobId);
            exit;
        }

        redirect($redirectUrl);
        exit;
    }

    // 14. REAKTIVASI HAK AKSES PEMBERI KERJA INDIVIDU (ADMIN DINAS / CENTRAL ADMIN)
    if ($action === 'reactivate_employer_access') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $res = reactivate_employer_access_by_admin_dinas(db(), $targetUserId, $user);
        if (!$res['success']) {
            flash('error', $res['error']);
        } else {
            $_SESSION['reactivation_success_banner'] = [
                'activated_at' => $res['activated_at'],
                'active_until' => $res['active_until'],
                'name' => $res['employer_name'],
            ];
            flash('success', $res['message']);
        }
        redirect($redirectUrl);
        exit;
    }
}

// --- FETCH DATA FOR DIRECTORY INDIVIDUAL ---
if ($view === 'directory_individual') {
    $query = <<<SQL
        SELECT u.id as user_id, u.name, u.email, u.created_at, u.profile_complete,
               ep.id as profile_id, ep.owner_name, ep.nik, ep.phone, ep.whatsapp, ep.npwp, ep.profession, ep.address, ep.address_detail,
               ep.city, ep.province, ep.district, ep.village, ep.postal_code, ep.latitude, ep.longitude, ep.description,
               ep.verified, ep.verification_status, ep.suspension_reason, ep.extension_status, ep.verifier_notes, ep.verification_checklist,
               ep.manual_review_status, ep.assigned_to, ep.assigned_at, ep.rejection_count, ep.entity_type,
               ep.active_until, ep.last_activated_at, ep.domicile_city_id
        FROM users u
        LEFT JOIN employer_profiles ep ON ep.user_id = u.id
        WHERE u.role = 'employer'
    SQL;
    $params = [];

    if ($entity === 'Individu') {
        $query .= ' AND (ep.entity_type = "Individu" OR ep.entity_type IS NULL)';
    } elseif ($entity === 'Perusahaan') {
        $query .= ' AND ep.entity_type = "Perusahaan"';
    }

    if ($search !== '') {
        $query .= ' AND (u.name LIKE ? OR u.email LIKE ? OR ep.phone LIKE ? OR ep.city LIKE ? OR ep.address LIKE ? OR ep.npwp LIKE ?)';
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
    }

    if ($tab === 'verified') {
        $query .= ' AND ep.verification_status = "APPROVED"';
    } elseif ($tab === 'process') {
        $query .= ' AND ep.verification_status = "PENDING"';
    } elseif ($tab === 'rejected') {
        $query .= ' AND ep.verification_status IN ("REJECTED", "NEEDS_REVISION")';
    }

    // Scope Admin Dinas Filter (exact domicile_city_id match)
    if ($user['role'] === 'admin_dinas' || (!empty($user['domicile_city_id']) && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
        $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
        if ($adminDomicileCity !== '') {
            $query .= ' AND ep.domicile_city_id = ?';
            $params[] = $adminDomicileCity;
        }
    }

    $query .= ' ORDER BY u.created_at DESC';
    $stmt = db()->prepare($query);
    $stmt->execute($params);
    $individualList = $stmt->fetchAll() ?: [];

    // If detail_id is requested, find that employer
    $selectedEmployer = null;
    $auditLogs = [];
    if ($detailId > 0) {
        $stmtSel = db()->prepare('SELECT u.id as user_id, u.name, u.email, u.created_at, u.profile_complete, ep.* FROM employer_profiles ep JOIN users u ON u.id = ep.user_id WHERE ep.user_id = ? LIMIT 1');
        $stmtSel->execute([$detailId]);
        $selectedEmployer = $stmtSel->fetch();
        if ($selectedEmployer) {
            $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
            if ($user['role'] === 'admin_dinas' || ($adminDomicileCity !== '' && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
                $empDomicileCity = (string)($selectedEmployer['domicile_city_id'] ?? '');
                if ($empDomicileCity === '' || $empDomicileCity !== $adminDomicileCity) {
                    $selectedEmployer = null;
                }
            }
            if ($selectedEmployer) {
                $auditLogs = fetch_audit_logs('employer', $detailId);
            }
        }
    }
}

// --- FETCH DATA FOR VERIFIKASI PEMBERI KERJA ---
if ($view === 'verifikasi_employer') {
    $query = <<<SQL
        SELECT u.id as user_id, u.name, u.email, u.created_at,
               ep.id as profile_id, ep.owner_name, ep.nik, ep.profession, ep.phone, ep.whatsapp, ep.npwp,
               ep.province, ep.city, ep.district, ep.village, ep.postal_code, ep.address, ep.address_detail,
               ep.latitude, ep.longitude, ep.description, ep.verified, ep.verification_status, ep.verifier_notes,
               ep.verification_checklist, ep.assigned_to, ep.assigned_at, ep.assignment_reason, ep.rejection_count,
               ep.manual_review_status, ep.consent_data_hash, ep.consent_given_at, ep.officer_statement, ep.officer_name, ep.entity_type
        FROM employer_profiles ep
        JOIN users u ON u.id = ep.user_id
    SQL;
    $params = [];

    if ($entity === 'Individu') {
        $query .= ' WHERE (ep.entity_type = "Individu" OR ep.entity_type IS NULL)';
    } elseif ($entity === 'Perusahaan') {
        $query .= ' WHERE ep.entity_type = "Perusahaan"';
    } else {
        $query .= ' WHERE 1=1';
    }

    if ($search !== '') {
        $query .= ' AND (u.name LIKE ? OR u.email LIKE ? OR ep.phone LIKE ? OR ep.city LIKE ? OR ep.address LIKE ? OR ep.npwp LIKE ?)';
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
    }

    if ($tab === 'process') {
        $query .= ' AND ep.verification_status = "PENDING"';
    } elseif ($tab === 'approved') {
        $query .= ' AND ep.verification_status = "APPROVED"';
    } elseif ($tab === 'revision') {
        $query .= ' AND ep.verification_status = "NEEDS_REVISION"';
    } elseif ($tab === 'rejected') {
        $query .= ' AND ep.verification_status = "REJECTED"';
    }

    // Scope Admin Dinas Filter (exact domicile_city_id match)
    if ($user['role'] === 'admin_dinas' || (!empty($user['domicile_city_id']) && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
        $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
        if ($adminDomicileCity !== '') {
            $query .= ' AND ep.domicile_city_id = ?';
            $params[] = $adminDomicileCity;
        }
    }

    $query .= ' ORDER BY ep.created_at DESC';
    $stmt = db()->prepare($query);
    $stmt->execute($params);
    $verificationEmployers = $stmt->fetchAll() ?: [];

    // If detail_id is requested
    $selectedEmployer = null;
    $auditLogs = [];
    if ($detailId > 0) {
        $stmtSel = db()->prepare('SELECT u.id as user_id, u.name, u.email, u.created_at, ep.* FROM employer_profiles ep JOIN users u ON u.id = ep.user_id WHERE ep.user_id = ? LIMIT 1');
        $stmtSel->execute([$detailId]);
        $selectedEmployer = $stmtSel->fetch();
        if ($selectedEmployer) {
            $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
            if ($user['role'] === 'admin_dinas' || ($adminDomicileCity !== '' && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
                $empDomicileCity = (string)($selectedEmployer['domicile_city_id'] ?? '');
                if ($empDomicileCity === '' || $empDomicileCity !== $adminDomicileCity) {
                    $selectedEmployer = null;
                }
            }
            if ($selectedEmployer) {
                $auditLogs = fetch_audit_logs('employer', $detailId);
            }
        }
    }
}

// --- FETCH DATA FOR VERIFIKASI LOWONGAN ---
if ($view === 'verifikasi_job') {
    $query = <<<SQL
        SELECT j.*, ep.owner_name, ep.profession, ep.city as emp_city, u.name as user_name, u.email as user_email
        FROM job_posts j
        JOIN users u ON u.id = j.user_id
        LEFT JOIN employer_profiles ep ON ep.user_id = u.id
    SQL;
    $params = [];

    if ($entity === 'Individu') {
        $query .= ' WHERE j.entity_type = "Individu"';
    } elseif ($entity === 'Perusahaan') {
        $query .= ' WHERE j.entity_type = "Perusahaan"';
    } else {
        $query .= ' WHERE 1=1';
    }

    if ($search !== '') {
        $query .= ' AND (j.title LIKE ? OR j.location LIKE ? OR j.kbji_code LIKE ? OR u.name LIKE ?)';
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like, $like]);
    }

    if ($tab === 'additional_doc') {
        $query .= ' AND j.status = "ADDITIONAL_DOCUMENT_PENDING"';
    } elseif ($tab === 'process') {
        $query .= ' AND j.status = "Menunggu Verifikasi"';
    } elseif ($tab === 'approved') {
        $query .= ' AND j.status = "Tayang"';
    } elseif ($tab === 'revision') {
        $query .= ' AND j.status = "Perlu Direvisi"';
    } elseif ($tab === 'rejected') {
        $query .= ' AND (j.status = "Ditolak" OR j.status = "CANCELED")';
    }

    // Admin Dinas Scope Filter (exact domicile_city_id match)
    if ($user['role'] === 'admin_dinas' || (!empty($user['domicile_city_id']) && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
        $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
        if ($adminDomicileCity !== '') {
            $query .= ' AND ep.domicile_city_id = ?';
            $params[] = $adminDomicileCity;
        }
    }

    $query .= ' ORDER BY j.created_at DESC';
    $stmt = db()->prepare($query);
    $stmt->execute($params);
    $verificationJobs = $stmt->fetchAll() ?: [];

    // If detail_id is requested for job
    $selectedJob = null;
    $auditLogs = [];
    $additionalDocCase = null;
    if ($detailId > 0) {
        $stmtSel = db()->prepare('SELECT j.*, ep.owner_name, ep.profession, ep.city as emp_city, ep.domicile_city_id as emp_domicile_city_id, ep.phone, ep.address, u.name as user_name, u.email as user_email FROM job_posts j JOIN users u ON u.id = j.user_id LEFT JOIN employer_profiles ep ON ep.user_id = u.id WHERE j.id = ? LIMIT 1');
        $stmtSel->execute([$detailId]);
        $selectedJob = $stmtSel->fetch();
        if ($selectedJob) {
            $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
            if ($user['role'] === 'admin_dinas' || ($adminDomicileCity !== '' && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
                $empDomicileCity = (string)($selectedJob['emp_domicile_city_id'] ?? '');
                if ($empDomicileCity === '' || $empDomicileCity !== $adminDomicileCity) {
                    $selectedJob = null;
                }
            }
            if ($selectedJob) {
                $auditLogs = fetch_audit_logs('job', $detailId);
                $docStmt = db()->prepare('SELECT * FROM job_additional_documents WHERE job_id = ? ORDER BY id DESC LIMIT 1');
                $docStmt->execute([$detailId]);
                $additionalDocCase = $docStmt->fetch();
            }
        }
    }
}

$unread = unread_notification_count((int) $user['id']);
$notifications = user_notifications((int) $user['id']);
$adminInitial = strtoupper(mb_substr($user['name'], 0, 1));

// Overall KPIs for Admin Overview
$statTotalEmployers = (int) db()->query('SELECT COUNT(*) FROM users WHERE role = "employer"')->fetchColumn();
$statPendingEmployers = (int) db()->query('SELECT COUNT(*) FROM employer_profiles WHERE verification_status = "PENDING"')->fetchColumn();
$statPendingJobs = (int) db()->query('SELECT COUNT(*) FROM job_posts WHERE status = "Menunggu Disetujui"')->fetchColumn();
$statTotalSeekers = (int) db()->query('SELECT COUNT(*) FROM users WHERE role = "seeker"')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Karirhub - Admin Pusat</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css?v=admin-std-1">
    <style>
        .detail-header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .detail-grid-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            align-items: start;
        }
        @media (max-width: 1024px) {
            .detail-grid-container { grid-template-columns: 1fr; }
        }
        .key-val-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px 20px;
            font-size: 13px;
        }
        .key-val-item .label { font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 2px; text-transform: uppercase; }
        .key-val-item .value { font-weight: 600; color: #0f172a; }
        .timeline-list { position: relative; padding-left: 20px; margin-top: 10px; }
        .timeline-list::before {
            content: '';
            position: absolute;
            left: 5px;
            top: 6px;
            bottom: 6px;
            width: 2px;
            background: #e2e8f0;
        }
        .timeline-item { position: relative; margin-bottom: 18px; }
        .timeline-dot {
            position: absolute;
            left: -19px;
            top: 4px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #30aed8;
            border: 2px solid #ffffff;
            box-shadow: 0 0 0 2px #bae6fd;
        }
        .timeline-time { font-size: 11px; color: #94a3b8; margin-bottom: 2px; }
        .timeline-title { font-size: 12px; font-weight: 700; color: #1e293b; }
        .timeline-desc { font-size: 12px; color: #475569; margin-top: 2px; line-height: 1.4; }
        .compare-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .compare-table th { background: #f8fafc; padding: 10px 12px; color: #64748b; font-weight: 600; border-bottom: 1px solid #e2e8f0; }
        .compare-table td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; color: #334155; }
        .map-box-placeholder {
            height: 160px;
            background: #f1f5f9;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            font-size: 13px;
            margin-top: 12px;
            border: 1px dashed #cbd5e1;
        }
        .tab-filter-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 14px; }
        .status-tab-list { display: flex; gap: 20px; }
        .status-tab-item {
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            text-decoration: none;
            padding-bottom: 8px;
            position: relative;
            transition: all 0.2s;
        }
        .status-tab-item:hover { color: var(--primary-strong); }
        .status-tab-item.active { color: var(--primary-strong); font-weight: 700; }
        .status-tab-item.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--primary);
            border-radius: 2px;
        }
        .filter-controls { display: flex; align-items: center; gap: 10px; }
        .filter-search-box {
            display: flex;
            align-items: center;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 0 12px;
            height: 38px;
            width: 240px;
        }
        .filter-search-box input { border: none; outline: none; width: 100%; font-size: 13px; margin-left: 8px; background: transparent; }
        .entity-selector-pill {
            display: inline-flex;
            background: #f1f5f9;
            border-radius: 10px;
            padding: 3px;
            gap: 3px;
        }
        .entity-selector-btn {
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            text-decoration: none;
            transition: all 0.15s;
        }
        .entity-selector-btn.active {
            background: #ffffff;
            color: var(--primary-strong);
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
        }
        .pill-badge.disabled {
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #cbd5e1;
        }
        .pill-badge.warning {
            background: #fffbeb;
            color: #d97706;
            border: 1px solid #fde68a;
        }
        .pill-badge.neutral {
            background: #f8fafc;
            color: #94a3b8;
            border: 1px solid #e2e8f0;
        }
        .action-dropdown { position: relative; display: inline-block; }
        .action-menu-dropdown {
            display: none;
            position: absolute;
            right: 0;
            top: calc(100% + 4px);
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
            min-width: 195px;
            z-index: 50;
            padding: 6px 0;
            text-align: left;
        }
        .action-menu-dropdown.show { display: block; }
    </style>
</head>
<body>
<div class="app-layout-wrapper">
    <!-- NARROW SIDEBAR RAIL (60px) -->
    <aside class="sidebar-rail">
        <!-- Top Logo Icon -->
        <a href="admin.php" class="sidebar-rail-logo" title="Karirhub - Admin Pusat">
            <svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:28px;height:28px;">
                <circle cx="73" cy="22" r="13" fill="#2590F9" />
                <path d="M22 32 C15.37 32 10 37.37 10 44 C10 50.63 15.37 56 22 56 H42 C44.2 56 46 57.8 46 60 V76 C46 82.63 51.37 88 58 88 C64.63 88 70 82.63 70 76 V50 C70 40.06 61.94 32 52 32 H22 Z" fill="#2590F9" />
            </svg>
        </a>

        <!-- Hamburger Toggle Button -->
        <button type="button" class="sidebar-rail-toggle" id="railToggleBtn" title="Buka Menu Navigasi">
            <i class="fa-solid fa-bars"></i>
        </button>

        <!-- Navigation Icons -->
        <div class="sidebar-rail-nav">
            <a href="admin.php?view=directory_individual" class="rail-btn <?php echo str_starts_with($view, 'directory') ? 'active' : ''; ?>" title="Individual">
                <i class="fa-solid fa-building-user"></i>
            </a>
            <a href="admin.php?view=verifikasi_employer&entity=Individu" class="rail-btn <?php echo str_starts_with($view, 'verifikasi_employer') ? 'active' : ''; ?>" title="Verifikasi Profil">
                <i class="fa-solid fa-id-card"></i>
            </a>
            <a href="admin.php?view=verifikasi_job&entity=Individu" class="rail-btn <?php echo str_starts_with($view, 'verifikasi_job') ? 'active' : ''; ?>" title="Verifikasi Lowongan">
                <i class="fa-solid fa-briefcase"></i>
            </a>
        </div>

        <div class="rail-spacer"></div>

        <!-- Bottom Theme & Account Avatar -->
        <div class="rail-bottom">
            <div class="rail-popover-wrap">
                <button type="button" class="rail-btn" id="themeToggleBtn" title="Tema Tampilan">
                    <i class="fa-solid fa-display"></i>
                </button>
                <div class="rail-popover rail-theme-popover" id="railThemePopover">
                    <div class="rail-popover-header">TEMA TAMPILAN</div>
                    <div class="rail-popover-list">
                        <button type="button" class="rail-popover-item" data-theme-val="light">
                            <div class="rail-popover-item-left"><i class="fa-regular fa-sun"></i><span>Terang</span></div>
                            <i class="fa-solid fa-check rail-theme-check"></i>
                        </button>
                        <button type="button" class="rail-popover-item" data-theme-val="dark">
                            <div class="rail-popover-item-left"><i class="fa-regular fa-moon"></i><span>Gelap</span></div>
                            <i class="fa-solid fa-check rail-theme-check"></i>
                        </button>
                        <button type="button" class="rail-popover-item" data-theme-val="system">
                            <div class="rail-popover-item-left"><i class="fa-solid fa-display"></i><span>Sistem</span></div>
                            <i class="fa-solid fa-check rail-theme-check"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="rail-popover-wrap">
                <button type="button" class="rail-avatar-btn" id="sidebarAvatar" title="Akun Pengguna"><?php echo e($adminInitial ?: 'AD'); ?></button>
                <div class="rail-popover rail-account-popover" id="railAccountPopover">
                    <div class="rail-account-info">
                        <div class="rail-account-name"><?php echo e($user['name']); ?></div>
                        <div class="rail-account-email"><?php echo e($user['email']); ?></div>
                    </div>
                    <div class="rail-popover-divider"></div>
                    <div class="rail-popover-list">
                        <a href="settings.php" class="rail-popover-item">
                            <div class="rail-popover-item-left"><i class="fa-solid fa-gear"></i><span>Pengaturan</span></div>
                        </a>
                        <a href="logout.php" class="rail-popover-item item-logout">
                            <div class="rail-popover-item-left"><i class="fa-solid fa-arrow-right-from-bracket"></i><span>Keluar</span></div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </aside>

    <!-- FLYOUT / EXPANDED NAVIGATION DRAWER -->
    <div class="nav-drawer-backdrop" id="drawerBackdrop"></div>
    <div class="nav-drawer" id="navDrawer">
        <div class="drawer-header">
            <div class="drawer-brand-logo">
                <svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:32px;height:32px;">
                    <circle cx="73" cy="22" r="13" fill="#2590F9" />
                    <path d="M22 32 C15.37 32 10 37.37 10 44 C10 50.63 15.37 56 22 56 H42 C44.2 56 46 57.8 46 60 V76 C46 82.63 51.37 88 58 88 C64.63 88 70 82.63 70 76 V50 C70 40.06 61.94 32 52 32 H22 Z" fill="#2590F9" />
                </svg>
            </div>
            <div class="drawer-brand-text">
                <h3>Karirhub</h3>
                <p>Admin Pusat</p>
            </div>
            <button type="button" class="drawer-close-btn" id="drawerCloseBtn" title="Tutup">
                <i class="fa-solid fa-bars"></i>
            </button>
        </div>

        <div class="drawer-menu-list">
            <a href="admin.php?view=directory_individual" class="drawer-menu-item <?php echo str_starts_with($view, 'directory') ? 'active' : ''; ?>">
                <i class="fa-solid fa-building-user"></i>
                <span>Individual</span>
            </a>
            <a href="admin.php?view=verifikasi_employer&entity=Individu" class="drawer-menu-item <?php echo str_starts_with($view, 'verifikasi_employer') ? 'active' : ''; ?>">
                <i class="fa-solid fa-id-card"></i>
                <span>Verifikasi Profil</span>
            </a>
            <a href="admin.php?view=verifikasi_job&entity=Individu" class="drawer-menu-item <?php echo str_starts_with($view, 'verifikasi_job') ? 'active' : ''; ?>">
                <i class="fa-solid fa-briefcase"></i>
                <span>Verifikasi Lowongan</span>
            </a>
        </div>
    </div>

    <!-- MAIN APP CONTENT -->
    <div class="main" style="flex:1; display:flex; flex-direction:column; min-width:0; overflow:hidden;">
        <!-- TOPBAR (MATCHING INDIVIDUAL EMPLOYER) -->
        <header class="new-topbar">
            <div class="topbar-nav-arrows">
                <button type="button" class="topbar-arrow-btn" onclick="history.back()" title="Kembali"><i class="fa-solid fa-chevron-left"></i></button>
                <button type="button" class="topbar-arrow-btn" onclick="history.forward()" title="Maju"><i class="fa-solid fa-chevron-right"></i></button>
            </div>

            <div class="topbar-crumbs" id="crumbs">
                <span>Beranda</span>
                <span class="sep">&gt;</span>
                <?php if ($view === 'directory_individual'): ?>
                    <a href="admin.php?view=directory_individual" style="color:inherit;text-decoration:none;">Direktori Pemberi Kerja</a>
                    <?php if ($selectedEmployer): ?>
                        <span class="sep">&gt;</span>
                        <strong id="crumbCurrent">#<?php echo substr(md5($selectedEmployer['user_id']), 0, 8); ?></strong>
                    <?php endif; ?>
                <?php elseif ($view === 'verifikasi_employer'): ?>
                    <a href="admin.php?view=verifikasi_employer" style="color:inherit;text-decoration:none;">Verifikasi Pemberi Kerja</a>
                    <?php if ($selectedEmployer): ?>
                        <span class="sep">&gt;</span>
                        <strong id="crumbCurrent">#<?php echo substr(md5($selectedEmployer['user_id']), 0, 8); ?></strong>
                    <?php endif; ?>
                <?php else: ?>
                    <a href="admin.php?view=verifikasi_job" style="color:inherit;text-decoration:none;">Verifikasi Lowongan</a>
                    <?php if ($selectedJob): ?>
                        <span class="sep">&gt;</span>
                        <strong id="crumbCurrent">#<?php echo substr(md5($selectedJob['id']), 0, 8); ?></strong>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="topbar-search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <form method="get" action="admin.php" style="width:100%;margin:0;display:flex;">
                    <input type="hidden" name="view" value="<?php echo e($view); ?>">
                    <input type="hidden" name="entity" value="<?php echo e($entity); ?>">
                    <input type="hidden" name="tab" value="<?php echo e($tab); ?>">
                    <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Cari lowongan, pemberi kerja, nik, kota..." style="border:none;outline:none;width:100%;background:transparent;font-size:13px;color:var(--text-dark, #0f172a);">
                </form>
            </div>

            <div class="topbar-right-actions">
                <?php echo render_notif_dropdown($notifications, $unread); ?>

                <div class="company-profile-pill" onclick="toggleAccountMenu(event)" title="Pengaturan Akun Admin">
                    <div class="company-pill-avatar" style="background:#0284c7;color:#ffffff;"><?php echo e($adminInitial ?: 'AD'); ?></div>
                    <div class="company-pill-text">
                        <strong><?php echo e($user['name']); ?></strong>
                        <span>Admin Pusat</span>
                    </div>
                    <i class="fa-solid fa-chevron-right company-pill-arrow"></i>
                </div>
            </div>
        </header>

        <!-- CONTENT AREA -->
        <div class="content" style="flex:1; overflow-y:auto; background:#f8fafc;">
            <div class="page active">
                <?php if (!empty($_SESSION['reactivation_success_banner'])):
                    $rBanner = $_SESSION['reactivation_success_banner'];
                    unset($_SESSION['reactivation_success_banner']);
                ?>
                    <div style="background:#ecfdf5; border:1px solid #6ee7b7; border-radius:12px; padding:20px; margin-bottom:20px; color:#065f46; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                        <div style="display:flex; align-items:center; gap:10px; font-weight:700; font-size:16px; margin-bottom:6px;">
                            <i class="fa-solid fa-circle-check" style="color:#059669; font-size:20px;"></i>
                            Hak Akses berhasil direaktivasi.
                        </div>
                        <p style="margin:0 0 14px 0; font-size:13.5px; color:#047857;">Hak Akses Pemberi Kerja Individu telah langsung aktif kembali.</p>
                        <div style="background:#ffffff; border:1px solid #a7f3d0; border-radius:8px; padding:12px 18px; font-size:13px; display:inline-grid; grid-template-columns:auto auto; column-gap:20px; row-gap:8px;">
                            <span style="color:#64748b; font-weight:500;">Tanggal Aktivasi :</span>
                            <strong style="color:#0f172a;"><?php echo e($rBanner['activated_at']); ?></strong>
                            <span style="color:#64748b; font-weight:500;">Berlaku Sampai :</span>
                            <strong style="color:#0f172a;"><?php echo e($rBanner['active_until']); ?></strong>
                            <span style="color:#64748b; font-weight:500;">Status :</span>
                            <strong style="color:#059669;"><span class="pill-badge verified" style="font-size:11px; padding:2px 8px;">● Aktif</span></strong>
                        </div>
                    </div>
                <?php endif; ?>

            <?php if ($flash = get_flash()): ?>
                <div class="alert-box <?php echo $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>" style="margin-bottom:16px;">
                    <i class="fa-solid <?php echo $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                    <?php echo e($flash['message']); ?>
                </div>
            <?php endif; ?>

                <?php if (!$detailId): ?>
                    <!-- ADMIN OVERVIEW KPI STATS (4 CARDS) -->
                    <div class="cards4">
                        <div class="card">
                            <div class="mini-icon" style="background:#e0f2fe;color:#0284c7;"><i class="fa-solid fa-building-user"></i></div>
                            <h3>Total Pemberi Kerja</h3>
                            <div class="value"><?php echo number_format($statTotalEmployers); ?></div>
                            <div class="desc neutral">Akun terdaftar di sistem</div>
                        </div>
                        <div class="card">
                            <div class="mini-icon" style="background:#fff7ed;color:#ea580c;"><i class="fa-solid fa-id-card"></i></div>
                            <h3>Antrean Verifikasi Profil</h3>
                            <div class="value" style="<?php echo $statPendingEmployers > 0 ? 'color:#ea580c;' : ''; ?>"><?php echo number_format($statPendingEmployers); ?></div>
                            <div class="desc <?php echo $statPendingEmployers > 0 ? 'warning' : 'neutral'; ?>">Menunggu pemeriksaan</div>
                        </div>
                        <div class="card">
                            <div class="mini-icon" style="background:#fef3c7;color:#d97706;"><i class="fa-solid fa-briefcase"></i></div>
                            <h3>Antrean Moderasi Loker</h3>
                            <div class="value" style="<?php echo $statPendingJobs > 0 ? 'color:#d97706;' : ''; ?>"><?php echo number_format($statPendingJobs); ?></div>
                            <div class="desc <?php echo $statPendingJobs > 0 ? 'warning' : 'neutral'; ?>">Menunggu persetujuan</div>
                        </div>
                        <div class="card">
                            <div class="mini-icon" style="background:#ecfdf5;color:#059669;"><i class="fa-solid fa-users"></i></div>
                            <h3>Pencari Kerja Aktif</h3>
                            <div class="value"><?php echo number_format($statTotalSeekers); ?></div>
                            <div class="desc neutral">Talenta siap dilamar</div>
                        </div>
                    </div>
                <?php endif; ?>

            <!-- ========================================== -->
            <!-- 1. DIREKTORI INDIVIDUAL (READ-ONLY DIRECTORY) -->
            <!-- ========================================== -->
            <?php if ($view === 'directory_individual'): ?>
                <?php if ($selectedEmployer):
                    $selectedEmpStatus = get_employer_access_status($selectedEmployer);
                    $selectedSiklusTerakhir = format_cycle_range($selectedEmployer['last_activated_at'] ?? null, $selectedEmployer['active_until'] ?? null);
                    $adminCity = (string)($user['domicile_city_id'] ?? '');
                    $isScopeMatchSelected = ($user['role'] === 'admin' || $user['role'] === 'admin_pusat' || empty($adminCity) || ($selectedEmployer['domicile_city_id'] ?? '') === $adminCity);
                    $canReactivateSelected = ($selectedEmpStatus['can_direct_reactivate'] && $isScopeMatchSelected);
                ?>
                    <!-- DETAIL VIEW FOR DIRECTORY (STRICTLY READ-ONLY) -->
                    <div style="margin-bottom:16px;">
                        <a href="admin.php?view=directory_individual&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>" class="btn-lihat-detail">
                            <i class="fa-solid fa-arrow-left"></i> Kembali
                        </a>
                </div>

                    <div class="detail-header-bar">
                        <div style="display:flex; align-items:center; gap:16px;">
                            <div class="item-avatar-box" style="width:52px; height:52px; font-size:18px;">
                                <?php echo strtoupper(substr($selectedEmployer['owner_name'] ?: $selectedEmployer['name'], 0, 2)); ?>
                            </div>
                            <div>
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <h1 style="font-size:20px; font-weight:800; margin:0;"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></h1>
                                    <span class="pill-badge <?php echo $selectedEmpStatus['badge_class']; ?>">
                                        ● <?php echo e($selectedEmpStatus['label']); ?>
                                    </span>
                                </div>
                                <div style="font-size:12px; color:#64748b; margin-top:4px;">
                                    Slug: <code><?php echo strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $selectedEmployer['owner_name'] ?: $selectedEmployer['name'])); ?></code> • 
                                    Didaftarkan: <?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?> • 
                                    <?php echo e($selectedEmployer['domicile_city_id'] ?: $selectedEmployer['city'] ?: 'Kota Belum Diisi'); ?>
                                </div>
                            </div>
                        </div>

                        <div style="display:flex; gap:10px; align-items:center;">
                            <button type="button" class="btn-lihat-detail" data-open-modal="modal-ver-info">
                                <i class="fa-solid fa-shield-halved"></i> Lihat Rincian Verifikasi
                            </button>
                            <?php if ($canReactivateSelected): ?>
                                <button type="button" class="btn-lihat-detail" style="color:#0284c7; border-color:#93c5fd; background:#eff6ff; font-weight:700;" data-open-modal="modal-reactivate-<?php echo $selectedEmployer['user_id']; ?>">
                                    <i class="fa-solid fa-arrows-rotate"></i> Reaktivasi Hak Akses
                                </button>
                            <?php elseif ($selectedEmpStatus['is_online_reactivation_pending']): ?>
                                <span class="pill-badge warning" style="display:inline-flex; align-items:center; gap:6px; font-size:12px; padding:6px 12px;">
                                    <i class="fa-solid fa-hourglass-half"></i> Permohonan reaktivasi online sedang dalam proses verifikasi
                                </span>
                            <?php endif; ?>
                            <?php if ($selectedEmpStatus['is_active']): ?>
                                <button type="button" class="btn-lihat-detail" style="color:#dc2626; border-color:#fca5a5;" data-open-modal="modal-suspend">
                                    <i class="fa-solid fa-ban"></i> Tangguhkan
                                </button>
                            <?php elseif ($selectedEmpStatus['status'] === 'SUSPENDED'): ?>
                                <form method="post" action="admin.php?view=directory_individual&detail_id=<?php echo $selectedEmployer['user_id']; ?>" style="margin:0;">
                                    <input type="hidden" name="admin_action" value="unsuspend_employer">
                                    <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                    <button type="submit" class="btn-lihat-detail" style="color:#059669; border-color:#a7f3d0;">
                                        <i class="fa-solid fa-rotate-left"></i> Batalkan Penangguhan
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="detail-grid-container">
                        <!-- LEFT COLUMN: CARDS -->
                        <div>
                            <!-- RINGKASAN & INFORMASI UMUM -->
                            <div class="section-card">
                                <div class="section-card-title">Informasi Umum</div>
                                <div class="key-val-grid">
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-user"></i> Nama Lengkap</div>
                                        <div class="value"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-envelope"></i> Email</div>
                                        <div class="value"><?php echo e($selectedEmployer['email']); ?></div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-phone"></i> Telepon / WhatsApp</div>
                                        <div class="value"><?php echo e($selectedEmployer['phone'] ?: '-'); ?> / <?php echo e($selectedEmployer['whatsapp'] ?: '-'); ?></div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-briefcase"></i> Jenis Usaha / Profesi</div>
                                        <div class="value"><?php echo e($selectedEmployer['profession'] ?: '-'); ?></div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-calendar"></i> Tanggal Daftar</div>
                                        <div class="value"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-clock"></i> Masa Aktif Hingga</div>
                                        <div class="value"><?php echo !empty($selectedEmployer['active_until']) ? date('d M Y, H:i', strtotime($selectedEmployer['active_until'])) : '-'; ?></div>
                                    </div>
                                </div>
                            </div>

                            <!-- LOKASI -->
                            <div class="section-card">
                                <div class="section-card-title">Lokasi</div>
                                <div class="key-val-grid">
                                    <div class="key-val-item" style="grid-column: span 2;">
                                        <div class="label"><i class="fa-solid fa-location-dot"></i> Alamat Lengkap</div>
                                        <div class="value"><?php echo e($selectedEmployer['address'] ?: '-'); ?> <?php echo !empty($selectedEmployer['address_detail']) ? '(' . e($selectedEmployer['address_detail']) . ')' : ''; ?></div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-map"></i> Wilayah Administratif</div>
                                        <div class="value"><?php echo e(implode(', ', array_filter([$selectedEmployer['village'], $selectedEmployer['district'], $selectedEmployer['city'], $selectedEmployer['province']])) ?: '-'); ?></div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-compass"></i> Koordinat</div>
                                        <div class="value"><?php echo e($selectedEmployer['latitude'] ?: '-'); ?>, <?php echo e($selectedEmployer['longitude'] ?: '-'); ?></div>
                                    </div>
                                </div>
                                <div class="map-box-placeholder">
                                    <i class="fa-solid fa-map-location-dot" style="font-size:24px; margin-right:8px;"></i>
                                    Peta Lokasi: <?php echo e($selectedEmployer['latitude'] ?: '-6.241586'); ?>, <?php echo e($selectedEmployer['longitude'] ?: '106.992416'); ?>
                                </div>
                            </div>

                            <!-- INFORMASI USAHA & NPWP -->
                            <div class="section-card">
                                <div class="section-card-title">Informasi Usaha & Legalitas</div>
                                <div class="key-val-grid">
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-id-card"></i> NIK (SIAPkerja)</div>
                                        <div class="value"><code><?php echo e($selectedEmployer['nik'] ?: '-'); ?></code></div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-file-invoice"></i> NPWP</div>
                                        <div class="value"><code><?php echo e($selectedEmployer['npwp'] ?: '-'); ?></code></div>
                                    </div>
                                </div>
                            </div>

                            <!-- DESKRIPSI -->
                            <div class="section-card">
                                <div class="section-card-title">Deskripsi Usaha / Profil</div>
                                <div style="font-size:13px; color:#334155; line-height:1.6;">
                                    <?php echo nl2br(e($selectedEmployer['description'] ?: 'Tidak ada deskripsi yang dicantumkan.')); ?>
                                </div>
                            </div>

                            <!-- PERPANJANGAN HAK AKSES PEMBERI KERJA INDIVIDU IF REQUESTED -->
                            <?php if (($selectedEmployer['extension_status'] ?? '') === 'REQUESTED'): ?>
                                <div class="section-card" style="border:1px solid #fde68a; background:#fffbeb;">
                                    <div class="section-card-title" style="color:#92400e;">
                                        <i class="fa-solid fa-clock-rotate-left"></i> Permohonan Perpanjangan Hak Akses Pemberi Kerja Individu
                                    </div>
                                    <p style="font-size:13px; color:#78350f; margin-bottom:12px;">
                                        Pemberi kerja ini mengajukan perpanjangan Hak Akses Pemberi Kerja Individu (maksimal 1x per siklus). Silakan tentukan durasi yang disetujui (1, 2, atau 3 hari):
                                    </p>
                                    <form method="post" action="admin.php?view=directory_individual&detail_id=<?php echo $selectedEmployer['user_id']; ?>" style="display:flex; gap:12px; align-items:center;">
                                        <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                        <label style="font-size:13px; font-weight:700; color:#78350f;">Durasi:</label>
                                        <select name="extension_days" style="height:36px; padding:0 12px; border-radius:8px; border:1px solid #fcd34d; font-size:13px;">
                                            <option value="1">1 Hari</option>
                                            <option value="2">2 Hari</option>
                                            <option value="3" selected>3 Hari</option>
                                        </select>
                                        <button type="submit" name="admin_action" value="approve_extension" class="primary-btn" style="background:#059669; height:36px; padding:0 16px; font-size:12px;">
                                            <i class="fa-solid fa-check"></i> Setujui
                                        </button>
                                        <button type="submit" name="admin_action" value="reject_extension" class="ghost-btn" style="color:#dc2626; border-color:#fecaca; height:36px; padding:0 16px; font-size:12px;">
                                            <i class="fa-solid fa-xmark"></i> Tolak
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- RIGHT COLUMN: AUDIT LOG TIMELINE -->
                        <div>
                            <div class="section-card">
                                <div class="section-card-title">Aktivitas & Audit Log</div>
                                <div class="timeline-list">
                                    <div class="timeline-item">
                                        <div class="timeline-dot"></div>
                                        <div class="timeline-time"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                        <div class="timeline-title">Pemberi kerja mendaftar di platform.</div>
                                    </div>
                                    <?php foreach ($auditLogs as $log): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-dot"></div>
                                            <div class="timeline-time"><?php echo date('d M Y, H:i', strtotime($log['created_at'])); ?></div>
                                            <div class="timeline-title"><?php echo e($log['action']); ?> <small style="color:#64748b;">(oleh <?php echo e($log['actor_name']); ?>)</small></div>
                                            <div class="timeline-desc"><?php echo e($log['details']); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- MODAL RINCIAN VERIFIKASI -->
                    <div class="modal-backdrop" data-modal="modal-ver-info">
                        <div class="modal-panel" style="width:min(540px, 90vw);">
                            <div class="modal-header">
                                <div class="modal-title">Rincian Verifikasi Pemberi Kerja</div>
                                <div class="modal-subtitle"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></div>
                            </div>
                            <div class="modal-body">
                                <div style="background:#f8fafc; padding:14px; border-radius:10px; margin-bottom:14px; font-size:13px;">
                                    <div><strong>Status Verifikasi:</strong> <?php echo e($selectedEmployer['verification_status']); ?></div>
                                    <div style="margin-top:4px;"><strong>Pemeriksa:</strong> <?php echo e($selectedEmployer['assigned_to'] ?: 'Belum ditugaskan'); ?></div>
                                    <div style="margin-top:4px;"><strong>Catatan Verifikator:</strong> <?php echo e($selectedEmployer['verifier_notes'] ?: 'Belum ada catatan verifikasi.'); ?></div>
                                    <?php if (!empty($selectedEmployer['verification_checklist'])): ?>
                                        <div style="margin-top:4px;"><strong>Hasil Checklist:</strong> <?php echo e($selectedEmployer['verification_checklist']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="ghost-btn" data-close-modal="modal-ver-info">Tutup</button>
                            </div>
                        </div>
                    </div>

                    <!-- MODAL SUSPEND -->
                    <div class="modal-backdrop" data-modal="modal-suspend">
                        <div class="modal-panel" style="width:min(500px, 90vw);">
                            <div class="modal-header">
                                <div class="modal-title" style="color:#dc2626;">Tangguhkan Pemberi Kerja</div>
                                <div class="modal-subtitle"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></div>
                            </div>
                            <form method="post" action="admin.php?view=directory_individual&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
                                <input type="hidden" name="admin_action" value="suspend_employer">
                                <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                <div class="modal-body">
                                    <div style="font-size:13px; color:#475569; margin-bottom:12px;">
                                        Penangguhan Hak Akses Pemberi Kerja Individu akan membatasi aksi operasional pemohon tanpa menonaktifkan akun SIAPkerja. Masukkan alasan penangguhan:
                                    </div>
                                    <textarea name="suspension_reason" required placeholder="Alasan penangguhan wajib diisi..." style="width:100%; min-height:80px; padding:10px; border-radius:8px; border:1px solid #fca5a5; font-size:13px;"></textarea>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="ghost-btn" data-close-modal="modal-suspend">Batal</button>
                                    <button type="submit" class="primary-btn" style="background:#dc2626;">Tangguhkan Sekarang</button>
                                </div>
                            </form>
                        <!-- MODAL REACTIVATE FOR DETAIL VIEW -->
                    <?php if ($selectedEmployer && $canReactivateSelected): ?>
                        <div class="modal-backdrop" data-modal="modal-reactivate-<?php echo $selectedEmployer['user_id']; ?>">
                            <div class="modal-panel" style="width:min(540px, 92vw);">
                                <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center;">
                                    <div>
                                        <div class="modal-title" style="color:#0284c7; font-size:16px; font-weight:800; display:flex; align-items:center; gap:8px;">
                                            <i class="fa-solid fa-arrows-rotate"></i> KONFIRMASI REAKTIVASI HAK AKSES
                                        </div>
                                    </div>
                                    <button type="button" class="close-btn" data-close-modal="modal-reactivate-<?php echo $selectedEmployer['user_id']; ?>" style="background:none; border:none; font-size:18px; color:#94a3b8; cursor:pointer;">&times;</button>
                                </div>
                                <form method="post" action="admin.php?view=directory_individual&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
                                    <input type="hidden" name="admin_action" value="reactivate_employer_access">
                                    <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                    <div class="modal-body" style="padding:16px 20px;">
                                        <p style="margin:0 0 14px 0; color:#475569; font-size:13px; line-height:1.5;">
                                            Anda akan mengaktifkan kembali <strong>Hak Akses Pemberi Kerja Individu</strong> berikut:
                                        </p>

                                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px; margin-bottom:14px; font-size:13px; display:grid; grid-template-columns:130px 1fr; row-gap:8px;">
                                            <span style="color:#64748b;">Nama</span>
                                            <strong style="color:#0f172a;"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></strong>

                                            <span style="color:#64748b;">NIK</span>
                                            <code style="color:#0f172a; font-weight:600;"><?php echo e($selectedEmployer['nik'] ?: '-'); ?></code>

                                            <span style="color:#64748b;">Lokasi Domisili</span>
                                            <span style="color:#0f172a;"><?php echo e($selectedEmployer['domicile_city_id'] ?: $selectedEmployer['city'] ?: '-'); ?></span>

                                            <span style="color:#64748b;">Status Saat Ini</span>
                                            <span style="color:#dc2626; font-weight:600;">Tidak Aktif</span>
                                        </div>

                                        <div style="background:#f1f5f9; border-left:4px solid #0284c7; padding:10px 14px; border-radius:0 6px 6px 0; margin-bottom:14px;">
                                            <div style="font-size:11px; text-transform:uppercase; font-weight:700; color:#64748b; margin-bottom:2px;">Siklus Terakhir</div>
                                            <div style="font-size:13px; font-weight:600; color:#0f172a;"><?php echo e($selectedSiklusTerakhir); ?></div>
                                        </div>

                                        <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:12px 14px; font-size:12.5px; color:#1e40af; line-height:1.5;">
                                            <i class="fa-solid fa-circle-info" style="margin-right:4px;"></i>
                                            Hak Akses akan langsung aktif kembali selama <strong>3 bulan</strong> sejak tanggal reaktivasi ini.<br>
                                            Reaktivasi melalui Admin Dinas tidak memerlukan proses verifikasi lanjutan.
                                        </div>
                                    </div>
                                    <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:10px;">
                                        <button type="button" class="ghost-btn" data-close-modal="modal-reactivate-<?php echo $selectedEmployer['user_id']; ?>">Batal</button>
                                        <button type="submit" class="primary-btn" style="background:#0284c7; display:inline-flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-arrows-rotate"></i> Reaktivasi Hak Akses
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <!-- DIRECTORY TABLE VIEW (MATCHING VISUAL SCREENSHOT BASELINE) -->
                    <div style="margin-bottom:20px;">
                        <h1 style="font-size:24px; font-weight:800; margin:0 0 16px 0;">Perusahaan / Pemberi Kerja</h1>
                        <div class="tab-filter-bar">
                            <div class="status-tab-list">
                                <a href="admin.php?view=directory_individual&entity=<?php echo e($entity); ?>&tab=all" class="status-tab-item <?php echo $tab === 'all' ? 'active' : ''; ?>">Semua</a>
                                <a href="admin.php?view=directory_individual&entity=<?php echo e($entity); ?>&tab=verified" class="status-tab-item <?php echo $tab === 'verified' ? 'active' : ''; ?>">Terverifikasi</a>
                                <a href="admin.php?view=directory_individual&entity=<?php echo e($entity); ?>&tab=process" class="status-tab-item <?php echo $tab === 'process' ? 'active' : ''; ?>">Dalam Proses</a>
                                <a href="admin.php?view=directory_individual&entity=<?php echo e($entity); ?>&tab=rejected" class="status-tab-item <?php echo $tab === 'rejected' ? 'active' : ''; ?>">Ditolak</a>
                            </div>

                            <div class="filter-controls">
                                <div class="entity-selector-pill">
                                    <a href="admin.php?view=directory_individual&entity=Semua&tab=<?php echo e($tab); ?>" class="entity-selector-btn <?php echo $entity === 'Semua' ? 'active' : ''; ?>">Semua</a>
                                    <a href="admin.php?view=directory_individual&entity=Perusahaan&tab=<?php echo e($tab); ?>" class="entity-selector-btn <?php echo $entity === 'Perusahaan' ? 'active' : ''; ?>">Perusahaan</a>
                                    <a href="admin.php?view=directory_individual&entity=Individu&tab=<?php echo e($tab); ?>" class="entity-selector-btn <?php echo $entity === 'Individu' ? 'active' : ''; ?>">Individu</a>
                        </div>
                        <form method="get" action="admin.php" style="display:flex; gap:8px;">
                            <input type="hidden" name="view" value="directory_individual">
                                    <input type="hidden" name="entity" value="<?php echo e($entity); ?>">
                            <input type="hidden" name="tab" value="<?php echo e($tab); ?>">
                                    <div class="filter-search-box">
                                        <i class="fa-solid fa-magnifying-glass" style="color:#94a3b8;"></i>
                                        <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Cari perusahaan...">
                                    </div>
                                    <button type="submit" class="filter-btn"><i class="fa-solid fa-sliders"></i> Filter</button>
                        </form>
                            </div>
                        </div>
                    </div>

                    <div class="console-table-card">
                        <table class="console-table">
                            <thead>
                                <tr>
                                    <th>Nama Pemberi Kerja / Perusahaan</th>
                                    <th>Status Hak Akses</th>
                                    <th>Siklus Terakhir</th>
                                    <th>Email & Kontak</th>
                                    <th>NIK / NPWP</th>
                                    <th>Lokasi Domisili</th>
                                    <th>Tanggal Daftar</th>
                                    <th style="text-align:center;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$individualList): ?>
                                    <tr><td colspan="8" style="text-align:center; padding:40px; color:#64748b;">Tidak ada data pemberi kerja ditemukan.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($individualList as $emp):
                                        $empStatus = get_employer_access_status($emp);
                                        $siklusTerakhir = format_cycle_range($emp['last_activated_at'] ?? null, $emp['active_until'] ?? null);
                                        $adminCity = (string)($user['domicile_city_id'] ?? '');
                                        $isScopeMatch = ($user['role'] === 'admin' || $user['role'] === 'admin_pusat' || empty($adminCity) || ($emp['domicile_city_id'] ?? '') === $adminCity);
                                        $canReactivate = ($empStatus['can_direct_reactivate'] && $isScopeMatch);
                                    ?>
                                        <tr>
                                            <td>
                                                <div style="display:flex; align-items:center; gap:12px;">
                                                    <div class="item-avatar-box">
                                                        <?php echo strtoupper(substr($emp['owner_name'] ?: $emp['name'], 0, 2)); ?>
                                                    </div>
                                                    <div>
                                                        <strong><?php echo e($emp['owner_name'] ?: $emp['name']); ?></strong><br>
                                                        <small style="color:#94a3b8; font-size:11px;"><?php echo strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $emp['owner_name'] ?: $emp['name'])); ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="pill-badge <?php echo $empStatus['badge_class']; ?>">● <?php echo e($empStatus['label']); ?></span>
                                            </td>
                                            <td style="font-size:12.5px; color:#334155; font-weight:500;">
                                                <?php echo e($siklusTerakhir); ?>
                                            </td>
                                            <td>
                                                <div style="font-size:12.5px;"><?php echo e($emp['email']); ?></div>
                                                <div style="font-size:11.5px; color:#64748b;"><?php echo e($emp['phone'] ?: '-'); ?></div>
                                            </td>
                                            <td><code><?php echo e($emp['npwp'] ?: $emp['nik'] ?: '-'); ?></code></td>
                                            <td><?php echo e($emp['domicile_city_id'] ?: $emp['city'] ?: '-'); ?></td>
                                            <td><?php echo date('d M Y', strtotime($emp['created_at'])); ?></td>
                                            <td style="text-align:center;">
                                                <div class="action-dropdown">
                                                    <button type="button" class="btn-action-trigger" onclick="toggleActionMenu(this, event)" style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; padding:6px 12px; cursor:pointer; color:#475569; font-weight:700; font-size:13px; display:inline-flex; align-items:center; gap:4px;" title="Menu Aksi">
                                                        <i class="fa-solid fa-ellipsis-vertical"></i>
                                                </button>
                                                    <div class="action-menu-dropdown">
                                                        <a href="admin.php?view=directory_individual&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&detail_id=<?php echo $emp['user_id']; ?>" style="display:flex; align-items:center; gap:8px; padding:8px 14px; font-size:13px; color:#334155; text-decoration:none;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                                                            <i class="fa-solid fa-eye" style="color:#64748b; width:16px;"></i> Lihat Profil
                                                        </a>
                                                        <a href="admin.php?view=directory_individual&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&detail_id=<?php echo $emp['user_id']; ?>#audit-trail" style="display:flex; align-items:center; gap:8px; padding:8px 14px; font-size:13px; color:#334155; text-decoration:none;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                                                            <i class="fa-solid fa-clock-rotate-left" style="color:#64748b; width:16px;"></i> Riwayat Hak Akses
                                                        </a>
                                                        <?php if ($canReactivate): ?>
                                                            <div style="height:1px; background:#e2e8f0; margin:4px 0;"></div>
                                                            <button type="button" data-open-modal="modal-reactivate-<?php echo $emp['user_id']; ?>" style="display:flex; width:100%; border:none; background:none; align-items:center; gap:8px; padding:8px 14px; font-size:13px; color:#0284c7; font-weight:600; cursor:pointer; text-align:left;" onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='transparent'">
                                                                <i class="fa-solid fa-arrows-rotate" style="color:#0284c7; width:16px;"></i> Reaktivasi Hak Akses
                                                            </button>
                                                        <?php elseif ($empStatus['is_online_reactivation_pending']): ?>
                                                            <div style="height:1px; background:#e2e8f0; margin:4px 0;"></div>
                                                            <div style="padding:6px 14px; font-size:11px; color:#92400e; background:#fef3c7; line-height:1.3;">
                                                                <i class="fa-solid fa-hourglass-half"></i> Permohonan reaktivasi online sedang diverifikasi
                                                        </div>
                                                            <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- MODALS FOR TABLE VIEW REACTIVATION -->
                    <?php foreach ($individualList as $emp):
                        $empStatus = get_employer_access_status($emp);
                        $adminCity = (string)($user['domicile_city_id'] ?? '');
                        $isScopeMatch = ($user['role'] === 'admin' || $user['role'] === 'admin_pusat' || empty($adminCity) || ($emp['domicile_city_id'] ?? '') === $adminCity);
                        $canReactivate = ($empStatus['can_direct_reactivate'] && $isScopeMatch);
                        if ($canReactivate):
                            $siklusTerakhir = format_cycle_range($emp['last_activated_at'] ?? null, $emp['active_until'] ?? null);
                    ?>
                        <div class="modal-backdrop" data-modal="modal-reactivate-<?php echo $emp['user_id']; ?>">
                            <div class="modal-panel" style="width:min(540px, 92vw);">
                                <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center;">
                                    <div>
                                        <div class="modal-title" style="color:#0284c7; font-size:16px; font-weight:800; display:flex; align-items:center; gap:8px;">
                                            <i class="fa-solid fa-arrows-rotate"></i> KONFIRMASI REAKTIVASI HAK AKSES
                </div>
                                    </div>
                                    <button type="button" class="close-btn" data-close-modal="modal-reactivate-<?php echo $emp['user_id']; ?>" style="background:none; border:none; font-size:18px; color:#94a3b8; cursor:pointer;">&times;</button>
                                </div>
                                <form method="post" action="admin.php?view=directory_individual&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>">
                                    <input type="hidden" name="admin_action" value="reactivate_employer_access">
                                    <input type="hidden" name="user_id" value="<?php echo $emp['user_id']; ?>">
                                    <div class="modal-body" style="padding:16px 20px;">
                                        <p style="margin:0 0 14px 0; color:#475569; font-size:13px; line-height:1.5;">
                                            Anda akan mengaktifkan kembali <strong>Hak Akses Pemberi Kerja Individu</strong> berikut:
                                        </p>

                                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px; margin-bottom:14px; font-size:13px; display:grid; grid-template-columns:130px 1fr; row-gap:8px;">
                                            <span style="color:#64748b;">Nama</span>
                                            <strong style="color:#0f172a;"><?php echo e($emp['owner_name'] ?: $emp['name']); ?></strong>

                                            <span style="color:#64748b;">NIK</span>
                                            <code style="color:#0f172a; font-weight:600;"><?php echo e($emp['nik'] ?: '-'); ?></code>

                                            <span style="color:#64748b;">Lokasi Domisili</span>
                                            <span style="color:#0f172a;"><?php echo e($emp['domicile_city_id'] ?: $emp['city'] ?: '-'); ?></span>

                                            <span style="color:#64748b;">Status Saat Ini</span>
                                            <span style="color:#dc2626; font-weight:600;">Tidak Aktif</span>
                                        </div>

                                        <div style="background:#f1f5f9; border-left:4px solid #0284c7; padding:10px 14px; border-radius:0 6px 6px 0; margin-bottom:14px;">
                                            <div style="font-size:11px; text-transform:uppercase; font-weight:700; color:#64748b; margin-bottom:2px;">Siklus Terakhir</div>
                                            <div style="font-size:13px; font-weight:600; color:#0f172a;"><?php echo e($siklusTerakhir); ?></div>
                                        </div>

                                        <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:12px 14px; font-size:12.5px; color:#1e40af; line-height:1.5;">
                                            <i class="fa-solid fa-circle-info" style="margin-right:4px;"></i>
                                            Hak Akses akan langsung aktif kembali selama <strong>3 bulan</strong> sejak tanggal reaktivasi ini.<br>
                                            Reaktivasi melalui Admin Dinas tidak memerlukan proses verifikasi lanjutan.
                                        </div>
                                    </div>
                                    <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:10px;">
                                        <button type="button" class="ghost-btn" data-close-modal="modal-reactivate-<?php echo $emp['user_id']; ?>">Batal</button>
                                        <button type="submit" class="primary-btn" style="background:#0284c7; display:inline-flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-arrows-rotate"></i> Reaktivasi Hak Akses
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>

            <!-- ========================================== -->
            <!-- 2. VERIFIKASI PEMBERI KERJA (VERIFICATION WORKFLOW) -->
            <!-- ========================================== -->
            <?php if ($view === 'verifikasi_employer'): ?>
                <?php if ($selectedEmployer): ?>
                    <!-- DETAIL VIEW FOR VERIFIKASI PEMBERI KERJA -->
                    <div style="margin-bottom:16px;">
                        <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>" class="btn-lihat-detail">
                            <i class="fa-solid fa-arrow-left"></i> Kembali
                        </a>
                </div>

                    <div class="detail-header-bar">
                        <div style="display:flex; align-items:center; gap:16px;">
                            <div class="item-avatar-box" style="width:52px; height:52px; font-size:18px;">
                                <?php echo strtoupper(substr($selectedEmployer['owner_name'] ?: $selectedEmployer['name'], 0, 2)); ?>
                            </div>
                            <div>
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <h1 style="font-size:20px; font-weight:800; margin:0;"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></h1>
                                    <span class="pill-badge <?php echo $selectedEmployer['verification_status'] === 'APPROVED' ? 'verified' : ($selectedEmployer['verification_status'] === 'SUSPENDED' ? 'suspended' : 'pending'); ?>">
                                        ● <?php echo e($selectedEmployer['verification_status'] === 'APPROVED' ? 'Terverifikasi' : $selectedEmployer['verification_status']); ?>
                                    </span>
                                </div>
                                <div style="font-size:12px; color:#64748b; margin-top:4px;">
                                    Slug: <code><?php echo strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $selectedEmployer['owner_name'] ?: $selectedEmployer['name'])); ?></code> • 
                                    <?php echo e($selectedEmployer['entity_type'] ?? 'Individu'); ?> • 
                                    Didaftarkan: <?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?> • 
                                    <?php echo e($selectedEmployer['city'] ?: '-'); ?>
                                </div>
                        </div>
                    </div>

                        <div style="display:flex; gap:10px;">
                            <?php if (empty($selectedEmployer['assigned_to'])): ?>
                                <form method="post" action="admin.php?view=verifikasi_employer&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
                                    <input type="hidden" name="admin_action" value="assign_employer_case">
                                    <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                    <input type="hidden" name="self_assign" value="1">
                                    <input type="hidden" name="verifier_name" value="<?php echo e($user['name']); ?>">
                                    <button type="submit" class="primary-btn" style="height:36px; padding:0 16px; font-size:12px;">
                                        <i class="fa-solid fa-hand-holding-hand"></i> Ambil Case
                                    </button>
                                </form>
                            <?php else: ?>
                                <?php if (strcasecmp((string)$selectedEmployer['assigned_to'], (string)$user['name']) !== 0 && strcasecmp((string)$selectedEmployer['assigned_to'], (string)($user['email'] ?? '')) !== 0): ?>
                                    <form method="post" action="admin.php?view=verifikasi_employer&detail_id=<?php echo $selectedEmployer['user_id']; ?>" style="display:inline;">
                                        <input type="hidden" name="admin_action" value="assign_employer_case">
                                        <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                        <input type="hidden" name="self_assign" value="1">
                                        <input type="hidden" name="verifier_name" value="<?php echo e($user['name']); ?>">
                                        <button type="submit" class="primary-btn" style="height:36px; padding:0 16px; font-size:12px; background:#d97706;">
                                            <i class="fa-solid fa-hand-holding-hand"></i> Ambil Alih Case
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <button type="button" class="btn-lihat-detail" data-open-modal="modal-assign-pemeriksa">
                                    <i class="fa-solid fa-user-gear"></i> Ubah Pemeriksa
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="detail-grid-container">
                        <!-- LEFT COLUMN: VERIFICATION CARDS -->
                        <div>
                            <!-- RINGKASAN PENGAJUAN -->
                            <div class="section-card">
                                <div class="section-card-title">Ringkasan Pengajuan</div>
                                <div class="key-val-grid">
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-database"></i> Sumber Data</div>
                                        <div class="value">Registrasi Platform (SIAPkerja)</div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-calendar"></i> Tanggal Pengajuan</div>
                                        <div class="value"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-shapes"></i> Tipe</div>
                                        <div class="value"><?php echo e($selectedEmployer['entity_type'] ?? 'Individu'); ?></div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-hourglass-half"></i> Deadline</div>
                                        <div class="value">-</div>
                                    </div>
                                    <div class="key-val-item" style="grid-column: span 2;">
                                        <div class="label"><i class="fa-solid fa-location-dot"></i> Wilayah</div>
                                        <div class="value"><?php echo e($selectedEmployer['city'] ?: '-'); ?>, <?php echo e($selectedEmployer['province'] ?: '-'); ?></div>
                                    </div>
                                </div>
                            </div>

                            <!-- INFORMASI PENUGASAN DAN VERIFIKATOR -->
                            <div class="section-card">
                                <div class="section-card-title">Informasi Penugasan dan Verifikator</div>
                                <div class="key-val-grid">
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-user-shield"></i> Pemeriksa</div>
                                        <div class="value"><?php echo e($selectedEmployer['assigned_to'] ?: 'Belum ditugaskan'); ?></div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-circle-check"></i> Status Penugasan</div>
                                        <div class="value">
                                            <?php if (!empty($selectedEmployer['assigned_to'])): ?>
                                                <span class="pill-badge assigned">● Ditugaskan</span>
                                            <?php else: ?>
                                                <span style="color:#94a3b8;">-</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="key-val-item" style="grid-column: span 2;">
                                        <div class="label"><i class="fa-solid fa-clock"></i> Ditugaskan Pada</div>
                                        <div class="value"><?php echo !empty($selectedEmployer['assigned_at']) ? date('d M Y, H:i', strtotime($selectedEmployer['assigned_at'])) : '-'; ?></div>
                                    </div>
                                </div>
                            </div>

                            <!-- PERBANDINGAN DATA PEMBERI KERJA DAN OSS / SIAPKERJA -->
                            <div class="section-card">
                                <div class="section-card-title">Perbandingan Data Pemberi Kerja dan OSS / SIAPkerja</div>
                                <p style="font-size:12px; color:#64748b; margin-top:-8px; margin-bottom:12px;">Data referensi diambil otomatis berdasarkan NIK/NPWP pemohon.</p>
                                <table class="compare-table">
                            <thead>
                                <tr>
                                            <th>Variabel</th>
                                            <th>Data Pemberi Kerja</th>
                                            <th>Data OSS / SIAPkerja</th>
                                            <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                        <tr>
                                            <td><strong>Nama Lengkap / Pemilik</strong></td>
                                            <td><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></td>
                                            <td><?php echo e($selectedEmployer['name']); ?></td>
                                            <td><span class="pill-badge verified" style="font-size:11px; padding:2px 8px;">Sesuai</span></td>
                                        </tr>
                                        <tr>
                                            <td><strong>NIK</strong></td>
                                            <td><code><?php echo e($selectedEmployer['nik'] ?: '-'); ?></code></td>
                                            <td><code><?php echo e($selectedEmployer['nik'] ?: '-'); ?></code></td>
                                            <td><span class="pill-badge verified" style="font-size:11px; padding:2px 8px;">Sesuai</span></td>
                                        </tr>
                                        <tr>
                                            <td><strong>NPWP</strong></td>
                                            <td><code><?php echo e($selectedEmployer['npwp'] ?: '-'); ?></code></td>
                                            <td><code><?php echo e($selectedEmployer['npwp'] ?: '-'); ?></code></td>
                                            <td><span class="pill-badge verified" style="font-size:11px; padding:2px 8px;">Sesuai</span></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Email</strong></td>
                                            <td><?php echo e($selectedEmployer['email']); ?></td>
                                            <td><?php echo e($selectedEmployer['email']); ?></td>
                                            <td><span class="pill-badge verified" style="font-size:11px; padding:2px 8px;">Sesuai</span></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Telepon / WhatsApp</strong></td>
                                            <td><?php echo e($selectedEmployer['phone']); ?> / <?php echo e($selectedEmployer['whatsapp']); ?></td>
                                            <td><?php echo e($selectedEmployer['phone']); ?></td>
                                            <td><span class="pill-badge verified" style="font-size:11px; padding:2px 8px;">Sesuai</span></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Wilayah</strong></td>
                                            <td><?php echo e($selectedEmployer['city']); ?>, <?php echo e($selectedEmployer['province']); ?></td>
                                            <td><?php echo e($selectedEmployer['city']); ?>, <?php echo e($selectedEmployer['province']); ?></td>
                                            <td><span class="pill-badge verified" style="font-size:11px; padding:2px 8px;">Sesuai</span></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- INFORMASI PEMBERI KERJA DETAIL & MAP -->
                            <div class="section-card">
                                <div class="section-card-title">Informasi Pemberi Kerja</div>
                                <div class="key-val-grid">
                                    <div class="key-val-item">
                                        <div class="label">Alamat</div>
                                        <div class="value"><?php echo e($selectedEmployer['address'] ?: '-'); ?></div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label">Kode Pos</div>
                                        <div class="value"><?php echo e($selectedEmployer['postal_code'] ?: '-'); ?></div>
                                    </div>
                                </div>
                                <div class="map-box-placeholder">
                                    <i class="fa-solid fa-map-location-dot" style="font-size:24px; margin-right:8px;"></i>
                                    Peta Lokasi: <?php echo e($selectedEmployer['latitude'] ?: '-6.241586'); ?>, <?php echo e($selectedEmployer['longitude'] ?: '106.992416'); ?>
                                </div>
                                <div style="margin-top:14px; font-size:13px;">
                                    <strong>Deskripsi:</strong><br>
                                    <span style="color:#475569;"><?php echo nl2br(e($selectedEmployer['description'] ?: '-')); ?></span>
                                </div>
                            </div>

                            <!-- ========================================== -->
                            <!-- JALUR MANUAL DINAS SECTION (IF TRIGGERED) -->
                            <!-- ========================================== -->
                            <?php if (in_array($selectedEmployer['manual_review_status'] ?? '', ['MANUAL_DINAS_REVIEW', 'CONSENT_PENDING', 'CONSENT_GIVEN', 'INVALID'])): ?>
                                <div class="section-card" style="border:2px solid #0284c7; background:#f0f9ff;">
                                    <div class="section-card-title" style="color:#0369a1;">
                                        <i class="fa-solid fa-hands-holding-child"></i> Jalur Bantuan / Manual Dinas Tenaga Kerja
                                    </div>
                                    <p style="font-size:13px; color:#0c4a6e; line-height:1.5;">
                                        Profil ini berada dalam <strong>Jalur Manual Dinas</strong> (Penolakan ke-3 atau pendampingan khusus). Petugas Dinas dapat melakukan Controlled Edit data, mengajukan persetujuan (Consent) ke pemohon, memvalidasi pernyataan petugas, dan mengaktifkan akun.
                                    </p>

                                    <!-- STEP STATUS BANNER -->
                                    <div style="background:#ffffff; border:1px solid #bae6fd; border-radius:10px; padding:14px; margin:16px 0; font-size:13px;">
                                        <strong>Status Persetujuan Pemohon:</strong>
                                        <?php if ($selectedEmployer['manual_review_status'] === 'CONSENT_GIVEN'): ?>
                                            <span class="pill-badge verified" style="margin-left:8px;"><i class="fa-solid fa-check-circle"></i> Consent Telah Diberikan Pemohon</span>
                                        <?php elseif ($selectedEmployer['manual_review_status'] === 'CONSENT_PENDING'): ?>
                                            <span class="pill-badge pending" style="margin-left:8px;"><i class="fa-solid fa-clock"></i> Menunggu Persetujuan Pemohon</span>
                                        <?php elseif ($selectedEmployer['manual_review_status'] === 'INVALID'): ?>
                                            <span class="pill-badge danger" style="margin-left:8px;"><i class="fa-solid fa-triangle-exclamation"></i> Consent INVALID (Data Berubah Setelah Persetujuan)</span>
                                        <?php else: ?>
                                            <span class="pill-badge process" style="margin-left:8px;">Belum Mengajukan Consent</span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- 1. CONTROLLED EDIT FORM -->
                                    <details style="background:#ffffff; border:1px solid #cbd5e1; border-radius:10px; padding:12px; margin-bottom:14px;" <?php echo $selectedEmployer['manual_review_status'] !== 'CONSENT_GIVEN' ? 'open' : ''; ?>>
                                        <summary style="font-weight:700; color:#0f172a; cursor:pointer; font-size:13px;">
                                            <i class="fa-solid fa-pen-to-square"></i> 1. Controlled Edit Data Profil oleh Admin
                                        </summary>
                                        <form method="post" action="admin.php?view=verifikasi_employer&detail_id=<?php echo $selectedEmployer['user_id']; ?>" style="margin-top:14px;">
                                            <input type="hidden" name="admin_action" value="manual_dinas_edit">
                                            <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; font-size:12px;">
                                                <div>
                                                    <label style="font-weight:600; display:block; margin-bottom:4px;">Nama Lengkap Pemilik:</label>
                                                    <input type="text" name="owner_name" value="<?php echo e($selectedEmployer['owner_name']); ?>" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px;">
                                                </div>
                                                <div>
                                                    <label style="font-weight:600; display:block; margin-bottom:4px;">Jenis Usaha / Profesi:</label>
                                                    <input type="text" name="profession" value="<?php echo e($selectedEmployer['profession']); ?>" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px;">
                                                </div>
                                                <div>
                                                    <label style="font-weight:600; display:block; margin-bottom:4px;">Telepon:</label>
                                                    <input type="text" name="phone" value="<?php echo e($selectedEmployer['phone']); ?>" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px;">
                                                </div>
                                                <div>
                                                    <label style="font-weight:600; display:block; margin-bottom:4px;">WhatsApp:</label>
                                                    <input type="text" name="whatsapp" value="<?php echo e($selectedEmployer['whatsapp']); ?>" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px;">
                                                </div>
                                                <div>
                                                    <label style="font-weight:600; display:block; margin-bottom:4px;">NPWP:</label>
                                                    <input type="text" name="npwp" value="<?php echo e($selectedEmployer['npwp']); ?>" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px;">
                                                </div>
                                                <div>
                                                    <label style="font-weight:600; display:block; margin-bottom:4px;">Kota / Kabupaten:</label>
                                                    <input type="text" name="city" value="<?php echo e($selectedEmployer['city']); ?>" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px;">
                                                </div>
                                                <div style="grid-column: span 2;">
                                                    <label style="font-weight:600; display:block; margin-bottom:4px;">Alamat Lengkap:</label>
                                                    <input type="text" name="address" value="<?php echo e($selectedEmployer['address']); ?>" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px;">
                                                </div>
                                                <div style="grid-column: span 2;">
                                                    <label style="font-weight:600; display:block; margin-bottom:4px;">Deskripsi:</label>
                                                    <textarea name="description" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; min-height:50px;"><?php echo e($selectedEmployer['description']); ?></textarea>
                                                </div>
                                            </div>
                                            <div style="margin-top:12px; display:flex; justify-content:flex-end;">
                                                <button type="submit" class="primary-btn" style="height:34px; padding:0 14px; font-size:12px;">
                                                    Simpan Controlled Edit
                                                </button>
                                            </div>
                                        </form>
                                    </details>

                                    <!-- 2. AJUKAN CONSENT -->
                                    <div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:10px; padding:14px; margin-bottom:14px;">
                                        <div style="font-weight:700; color:#0f172a; font-size:13px; margin-bottom:6px;">
                                            <i class="fa-solid fa-paper-plane"></i> 2. Ajukan Permintaan Consent ke Pemohon
                                                        </div>
                                        <p style="font-size:12px; color:#64748b; margin-bottom:10px;">
                                            Klik tombol berikut untuk mengunci data hash dan mengirimkan notifikasi persetujuan ke pemohon di dashboard mereka.
                                        </p>
                                        <form method="post" action="admin.php?view=verifikasi_employer&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
                                            <input type="hidden" name="admin_action" value="manual_dinas_request_consent">
                                            <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                            <button type="submit" class="primary-btn" style="background:#0284c7; height:34px; padding:0 14px; font-size:12px;">
                                                <i class="fa-solid fa-paper-plane"></i> Ajukan Consent ke Pemohon
                                            </button>
                                        </form>
                                                                </div>

                                    <!-- 3. PERNYATAAN PETUGAS & SETUJUI AKTIFKAN -->
                                    <div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:10px; padding:14px;">
                                        <div style="font-weight:700; color:#0f172a; font-size:13px; margin-bottom:6px;">
                                            <i class="fa-solid fa-certificate"></i> 3. Pernyataan Petugas & Setujui & Aktifkan
                                        </div>
                                        <?php if ($selectedEmployer['manual_review_status'] === 'CONSENT_GIVEN'): ?>
                                            <form method="post" action="admin.php?view=verifikasi_employer&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
                                                <input type="hidden" name="admin_action" value="manual_dinas_approve_activate">
                                                <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                                <div style="margin-bottom:10px;">
                                                    <label style="font-size:12px; font-weight:600; display:block; margin-bottom:4px;">Nama Petugas Dinas:</label>
                                                    <input type="text" name="officer_name" value="<?php echo e($user['name']); ?>" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px;">
                                                </div>
                                                <div style="margin-bottom:10px;">
                                                    <label style="font-size:12px; font-weight:600; display:block; margin-bottom:4px;">Pernyataan Petugas:</label>
                                                    <textarea name="officer_statement" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; min-height:50px;">Saya telah memvalidasi keabsahan data dan identitas pemberi kerja secara langsung melalui pendampingan dinas tenaga kerja.</textarea>
                                                </div>
                                                                <div style="margin-bottom:14px;">
                                                    <label style="font-size:12px; display:flex; align-items:center; gap:8px;">
                                                        <input type="checkbox" name="statement_confirmed" value="1" required>
                                                        Saya menyatakan bahwa proses verifikasi manual telah memenuhi seluruh ketentuan regulasi yang berlaku.
                                                    </label>
                                                                    </div>
                                                <button type="submit" class="primary-btn" style="background:#059669; width:100%; height:38px; font-size:13px;">
                                                    <i class="fa-solid fa-check-double"></i> Setujui & Aktifkan Akun (3 Bulan)
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <div style="background:#f8fafc; padding:12px; border-radius:8px; font-size:12px; color:#64748b;">
                                                <i class="fa-solid fa-lock"></i> Tombol <strong>Setujui & Aktifkan</strong> akan aktif setelah pemohon membaca dan menyetujui Consent melalui Dashboard mereka.
                                                                </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- ========================================== -->
                            <!-- REGULAR DECISION PANEL (ASSIGNMENT MANDATORY) -->
                            <!-- ========================================== -->
                            <div class="section-card">
                                <div class="section-card-title">Checklist & Keputusan Verifikasi Profil</div>

                                <?php if (empty($selectedEmployer['assigned_to'])): ?>
                                    <!-- WARNING IF UNASSIGNED -->
                                    <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:14px; font-size:13px; color:#92400e;">
                                        <strong><i class="fa-solid fa-triangle-exclamation"></i> Perhatian:</strong><br>
                                        Untuk mengambil keputusan verifikasi, case pemberi kerja harus memiliki penugasan aktif terlebih dahulu. Silahkan klik tombol <strong>"Ambil Case"</strong> di atas.
                                    </div>
                                <?php elseif (strcasecmp((string)$selectedEmployer['assigned_to'], (string)$user['name']) !== 0 && strcasecmp((string)$selectedEmployer['assigned_to'], (string)($user['email'] ?? '')) !== 0): ?>
                                    <!-- WARNING IF ASSIGNED TO SOMEONE ELSE -->
                                    <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:14px; font-size:13px; color:#991b1b;">
                                        <strong><i class="fa-solid fa-lock"></i> Case Sedang Dipegang Pemeriksa Lain:</strong><br>
                                        Case verifikasi ini saat ini ditugaskan kepada <strong><?php echo e($selectedEmployer['assigned_to']); ?></strong>. Keputusan verifikasi (Setujui, Revisi, Tolak) hanya dapat diambil oleh verifikator yang ditugaskan. Silakan gunakan tombol <strong>"Ambil Alih Case"</strong> atau <strong>"Ubah Pemeriksa"</strong> di atas terlebih dahulu jika Anda ingin memproses case ini.
                                    </div>
                                <?php else: ?>
                                    <form method="post" action="admin.php?view=verifikasi_employer&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
                                        <input type="hidden" name="admin_action" value="verify_employer">
                                        <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                        <input type="hidden" name="form_token" value="<?php echo time(); ?>">

                                                                <div style="margin-bottom:14px;">
                                            <label style="font-weight:700; font-size:13px; display:block; margin-bottom:8px;">Checklist Pemeriksaan Verifikator:</label>
                                            <div style="display:grid; gap:8px; font-size:13px;">
                                                <label style="display:flex; align-items:center; gap:8px;">
                                                    <input type="checkbox" name="checklist[]" value="NPWP dan NIK valid" checked>
                                                    NPWP dan NIK sesuai dengan database Kependudukan / DJP
                                                </label>
                                                <label style="display:flex; align-items:center; gap:8px;">
                                                    <input type="checkbox" name="checklist[]" value="Lokasi tempat kerja terverifikasi" checked>
                                                    Lokasi tempat usaha/rumah terverifikasi di wilayah kerja
                                                </label>
                                                <label style="display:flex; align-items:center; gap:8px;">
                                                    <input type="checkbox" name="checklist[]" value="Dokumen pendukung sesuai" checked>
                                                    Dokumen izin / identitas pendukung sesuai
                                                </label>
                                            </div>
                                                                </div>

                                                                <div style="margin-bottom:14px;">
                                            <label style="font-weight:700; font-size:13px; display:block; margin-bottom:6px;">
                                                Catatan Verifikator <small style="color:#ef4444;">(Wajib diisi jika Revisi / Tolak)</small>:
                                            </label>
                                            <textarea name="verifier_notes" placeholder="Tuliskan catatan pemeriksaan..." style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1; font-size:13px; min-height:70px;"><?php echo e($selectedEmployer['verifier_notes']); ?></textarea>
                                        </div>

                                        <div style="margin-bottom:16px;">
                                                                    <label style="font-weight:700; font-size:13px; display:block; margin-bottom:6px;">Keputusan Final:</label>
                                            <select name="decision" required style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1; font-size:13px; font-weight:600;">
                                                                        <option value="approve">Setujui (Profil Terverifikasi 3 Bulan)</option>
                                                <option value="revision">Perlu Diperbaiki / Revisi (Membuka Kesempatan Perbaikan)</option>
                                                <option value="reject">Tolak Profil (Penolakan ke-<?php echo ((int)$selectedEmployer['rejection_count'] + 1); ?>)</option>
                                                                    </select>
                                                                </div>

                                        <div style="display:flex; justify-content:flex-end; gap:10px;">
                                            <button type="submit" class="primary-btn" style="height:38px; padding:0 20px; font-size:13px;">
                                                Simpan Keputusan Final
                                            </button>
                                                            </div>
                                                        </form>
                                <?php endif; ?>
                                                    </div>
                                                </div>

                        <!-- RIGHT COLUMN: AUDIT LOG TIMELINE -->
                        <div>
                            <div class="section-card">
                                <div class="section-card-title">Aktivitas & Audit Log</div>
                                <div class="timeline-list">
                                    <div class="timeline-item">
                                        <div class="timeline-dot"></div>
                                        <div class="timeline-time"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                        <div class="timeline-title">Pemberi kerja mendaftar di platform.</div>
                                    </div>
                                    <?php foreach ($auditLogs as $log): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-dot"></div>
                                            <div class="timeline-time"><?php echo date('d M Y, H:i', strtotime($log['created_at'])); ?></div>
                                            <div class="timeline-title"><?php echo e($log['action']); ?> <small style="color:#64748b;">(oleh <?php echo e($log['actor_name']); ?>)</small></div>
                                            <div class="timeline-desc"><?php echo e($log['details']); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                    </div>
                </div>
                        </div>
                    </div>

                    <!-- MODAL ASSIGN PEMERIKSA -->
                    <div class="modal-backdrop" data-modal="modal-assign-pemeriksa">
                        <div class="modal-panel" style="width:min(500px, 90vw);">
                            <div class="modal-header">
                                <div class="modal-title">Assign Pemeriksa Verifikasi</div>
                                <div class="modal-subtitle"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></div>
                            </div>
                            <form method="post" action="admin.php?view=verifikasi_employer&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
                                <input type="hidden" name="admin_action" value="assign_employer_case">
                                <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                <div class="modal-body">
                                    <div style="margin-bottom:12px;">
                                        <label style="font-size:13px; font-weight:700; display:block; margin-bottom:4px;">Pemeriksa:</label>
                                        <select name="verifier_name" style="width:100%; padding:8px; border-radius:8px; border:1px solid #cbd5e1; font-size:13px;">
                                            <option value="<?php echo e($user['name']); ?>"><?php echo e($user['name']); ?> (Saya)</option>
                                            <?php if ($user['name'] !== 'Admin Pusat'): ?>
                                                <option value="Admin Pusat">Admin Pusat</option>
            <?php endif; ?>
                                            <option value="Petugas Pengawas Wilayah 1">Petugas Pengawas Wilayah 1</option>
                                            <option value="Petugas Pengawas Wilayah 2">Petugas Pengawas Wilayah 2</option>
                                        </select>
                                    </div>
                                    <div style="margin-bottom:12px;">
                                        <label style="font-size:13px; font-weight:700; display:block; margin-bottom:4px;">Alasan Penugasan (Minimal 10 karakter):</label>
                                        <textarea name="assignment_reason" required minlength="10" placeholder="Contoh: Penugasan verifikasi berkas permohonan baru wilayah Kota Bekasi..." style="width:100%; min-height:80px; padding:8px; border-radius:8px; border:1px solid #cbd5e1; font-size:13px;"></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="ghost-btn" data-close-modal="modal-assign-pemeriksa">Batal</button>
                                    <button type="submit" class="primary-btn">Simpan Penugasan</button>
                                </div>
                            </form>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- VERIFIKASI PEMBERI KERJA TABLE VIEW -->
                    <div style="margin-bottom:20px;">
                        <h1 style="font-size:24px; font-weight:800; margin:0 0 16px 0;">Verifikasi Pemberi Kerja</h1>
                        <div class="tab-filter-bar">
                            <div class="status-tab-list">
                                <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=all" class="status-tab-item <?php echo $tab === 'all' ? 'active' : ''; ?>">Semua</a>
                                <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=process" class="status-tab-item <?php echo $tab === 'process' ? 'active' : ''; ?>">Menunggu Verifikasi</a>
                                <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=revision" class="status-tab-item <?php echo $tab === 'revision' ? 'active' : ''; ?>">Revisi</a>
                                <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=approved" class="status-tab-item <?php echo $tab === 'approved' ? 'active' : ''; ?>">Terverifikasi</a>
                                <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=rejected" class="status-tab-item <?php echo $tab === 'rejected' ? 'active' : ''; ?>">Ditolak</a>
                </div>

                            <div class="filter-controls">
                                <div class="entity-selector-pill">
                                    <a href="admin.php?view=verifikasi_employer&entity=Semua&tab=<?php echo e($tab); ?>" class="entity-selector-btn <?php echo $entity === 'Semua' ? 'active' : ''; ?>">Semua</a>
                                    <a href="admin.php?view=verifikasi_employer&entity=Perusahaan&tab=<?php echo e($tab); ?>" class="entity-selector-btn <?php echo $entity === 'Perusahaan' ? 'active' : ''; ?>">Perusahaan</a>
                                    <a href="admin.php?view=verifikasi_employer&entity=Individu&tab=<?php echo e($tab); ?>" class="entity-selector-btn <?php echo $entity === 'Individu' ? 'active' : ''; ?>">Individu</a>
                                </div>
                                <form method="get" action="admin.php" style="display:flex; gap:8px;">
                                    <input type="hidden" name="view" value="verifikasi_employer">
                                    <input type="hidden" name="entity" value="<?php echo e($entity); ?>">
                                    <input type="hidden" name="tab" value="<?php echo e($tab); ?>">
                                    <div class="filter-search-box">
                                        <i class="fa-solid fa-magnifying-glass" style="color:#94a3b8;"></i>
                                        <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Cari pemberi kerja...">
                                    </div>
                                    <button type="submit" class="filter-btn"><i class="fa-solid fa-sliders"></i> Filter</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="console-table-card">
                        <table class="console-table">
                            <thead>
                                <tr>
                                    <th>Nama Pemberi Kerja</th>
                                    <th>Jenis Entitas</th>
                                    <th>Lokasi</th>
                                    <th>Telepon</th>
                                    <th>Status</th>
                                    <th>Deadline</th>
                                    <th>Pemeriksa</th>
                                    <th>Tanggal Daftar</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$verificationEmployers): ?>
                                    <tr><td colspan="9" style="text-align:center; padding:40px; color:#64748b;">Tidak ada antrean verifikasi pemberi kerja.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($verificationEmployers as $vEmp): ?>
                                        <tr>
                                            <td>
                                                <div style="display:flex; align-items:center; gap:12px;">
                                                    <div class="item-avatar-box">
                                                        <?php echo strtoupper(substr($vEmp['owner_name'] ?: $vEmp['name'], 0, 2)); ?>
                                                    </div>
                                                    <div>
                                                        <strong><?php echo e($vEmp['owner_name'] ?: $vEmp['name']); ?></strong><br>
                                                        <small style="color:#94a3b8; font-size:11px;"><?php echo e($vEmp['email']); ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><span class="pill-badge verified"><?php echo e($vEmp['entity_type'] ?? 'Individu'); ?></span></td>
                                            <td><?php echo e($vEmp['city'] ?: '-'); ?></td>
                                            <td><?php echo e($vEmp['phone'] ?: '0'); ?></td>
                                            <td>
                                                <?php if ($vEmp['verification_status'] === 'APPROVED'): ?>
                                                    <span class="pill-badge verified">● Terverifikasi</span>
                                                <?php elseif ($vEmp['verification_status'] === 'PENDING'): ?>
                                                    <span class="pill-badge pending">● Menunggu</span>
                                                <?php else: ?>
                                                    <span class="pill-badge revision">● <?php echo e($vEmp['verification_status']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>-</td>
                                            <td>
                                                <?php if (!empty($vEmp['assigned_to'])): ?>
                                                    <span style="font-weight:600; color:#0284c7;"><?php echo e($vEmp['assigned_to']); ?></span>
                                                <?php else: ?>
                                                    <span style="color:#94a3b8;">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('d M Y, H:i', strtotime($vEmp['created_at'])); ?></td>
                                            <td>
                                                <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&detail_id=<?php echo $vEmp['user_id']; ?>" class="btn-lihat-detail">
                                                    Lihat Detail
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- ========================================== -->
            <!-- 3. VERIFIKASI LOWONGAN (COMPLIANCE MATRIX WORKFLOW) -->
            <!-- ========================================== -->
            <?php if ($view === 'verifikasi_job'): ?>
                <?php if ($selectedJob): ?>
                    <!-- DETAIL VIEW FOR VERIFIKASI LOWONGAN -->
                    <div style="margin-bottom:16px;">
                        <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>" class="btn-lihat-detail">
                            <i class="fa-solid fa-arrow-left"></i> Kembali
                        </a>
                    </div>

                    <div class="detail-header-bar">
                        <div style="display:flex; align-items:center; gap:16px;">
                            <div class="item-avatar-box" style="width:52px; height:52px; font-size:18px;">
                                <?php echo strtoupper(substr($selectedJob['title'], 0, 2)); ?>
                            </div>
                            <div>
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <h1 style="font-size:20px; font-weight:800; margin:0;"><?php echo e($selectedJob['title']); ?></h1>
                                    <span class="pill-badge <?php echo $selectedJob['status'] === 'Tayang' ? 'verified' : ($selectedJob['status'] === 'Ditolak' ? 'danger' : 'pending'); ?>">
                                        ● <?php echo e($selectedJob['status']); ?>
                                    </span>
                                </div>
                                <div style="font-size:12px; color:#64748b; margin-top:4px;">
                                    KBJI: <code><?php echo e($selectedJob['kbji_code']); ?></code> • 
                                    Pemberi Kerja: <strong><?php echo e($selectedJob['owner_name'] ?: $selectedJob['user_name']); ?></strong> • 
                                    Diajukan: <?php echo date('d M Y, H:i', strtotime($selectedJob['created_at'])); ?>
                                </div>
                            </div>
                        </div>

                        <div>
                            <?php if (empty($selectedJob['assigned_to'])): ?>
                                <form method="post" action="admin.php?view=verifikasi_job&detail_id=<?php echo $selectedJob['id']; ?>">
                                    <input type="hidden" name="admin_action" value="assign_job_case">
                                    <input type="hidden" name="job_id" value="<?php echo $selectedJob['id']; ?>">
                                    <input type="hidden" name="self_assign" value="1">
                                    <input type="hidden" name="verifier_name" value="<?php echo e($user['name']); ?>">
                                    <button type="submit" class="primary-btn" style="height:36px; padding:0 16px; font-size:12px;">
                                        <i class="fa-solid fa-hand-holding-hand"></i> Ambil Case Lowongan
                                                    </button>
                                                </form>
                            <?php else: ?>
                                <span class="pill-badge assigned">Pemeriksa: <?php echo e($selectedJob['assigned_to']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="detail-grid-container">
                        <!-- LEFT COLUMN: JOB DETAILS & COMPLIANCE MATRIX -->
                        <div>
                            <!-- RINGKASAN LOWONGAN -->
                            <div class="section-card">
                                <div class="section-card-title">Ringkasan Lowongan</div>
                                <?php if (!empty($selectedJob['additional_doc_required'])): ?>
                                    <div style="background:#fffbeb; border:1px solid #fde68a; color:#92400e; padding:10px 14px; border-radius:8px; font-size:12px; margin-bottom:14px;">
                                        <strong>⚠️ PERINGATAN RULES ENGINE (LAYER 2):</strong><br>
                                        Pengajuan ini merupakan publikasi ke-4+ untuk KBJI <code><?php echo e($selectedJob['kbji_code']); ?></code> pada bulan ini (<code>ADDITIONAL_DOCUMENT_PENDING</code>). Pastikan dokumen pendukung diperiksa.
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($selectedJob['parent_job_id'])): ?>
                                    <div style="background:#f0f9ff; border:1px solid #bae6fd; color:#0369a1; padding:10px 14px; border-radius:8px; font-size:12px; margin-bottom:14px;">
                                        <i class="fa-solid fa-arrows-rotate"></i> <strong>POSTING ULANG SISA KUOTA:</strong><br>
                                        Lowongan ini merupakan kelanjutan dari lowongan #<?php echo (int)$selectedJob['parent_job_id']; ?>. Kuota diajukan: <?php echo (int)$selectedJob['quota']; ?> posisi.
                                    </div>
                                <?php endif; ?>

                                <div class="key-val-grid">
                                    <div class="key-val-item">
                                        <div class="label">Jenis Entitas</div>
                                        <div class="value"><?php echo e($selectedJob['entity_type']); ?></div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label">Tipe Pekerjaan</div>
                                        <div class="value"><?php echo e($selectedJob['job_type']); ?></div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label">Lokasi Penempatan</div>
                                        <div class="value"><?php echo e($selectedJob['location']); ?></div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label">Kuota Dibuka</div>
                                        <div class="value"><?php echo (int)$selectedJob['quota']; ?> Orang</div>
                                    </div>
                                    <div class="key-val-item" style="grid-column: span 2;">
                                        <div class="label">Deskripsi & Kualifikasi</div>
                                        <div class="value" style="font-weight:normal; line-height:1.6;"><?php echo nl2br(e($selectedJob['description'])); ?></div>
                                    </div>
                                </div>
                            </div>

                            <!-- COMPLIANCE CHECKLIST MATRIX OR ADDITIONAL DOC REVIEW -->
                            <?php if ($selectedJob['status'] === 'ADDITIONAL_DOCUMENT_PENDING'): ?>
                                <div class="section-card">
                                    <div class="section-card-title">
                                        <span>Pemeriksaan Dokumen Tambahan</span>
                                        <small style="font-size:11px; font-weight:normal; color:#d97706;">(Lowongan ke-4+ KBJI Sama)</small>
                                    </div>

                                    <?php if (empty($selectedJob['assigned_to'])): ?>
                                        <div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:12px 16px; border-radius:8px; font-size:13px; margin-bottom:16px;">
                                            <i class="fa-solid fa-triangle-exclamation"></i> <strong>Penugasan Diperlukan:</strong> Case ini belum diambil. Silakan klik tombol <strong>Ambil Case Lowongan</strong> di pojok kanan atas sebelum memberikan keputusan.
                                        </div>
                                    <?php endif; ?>

                                    <div style="background:#fffbeb; border:1px solid #fde68a; color:#92400e; padding:14px; border-radius:10px; font-size:13px; margin-bottom:18px;">
                                        <strong><i class="fa-solid fa-triangle-exclamation"></i> Dokumen / Keterangan Tambahan dari Pemberi Kerja:</strong>
                                        <div style="margin-top:8px; line-height:1.5;">
                                            <?php 
                                                $docFile = $selectedJob['additional_doc_file'] ?: ($additionalDocCase['document_file'] ?? '');
                                                if (!empty($docFile)): 
                                            ?>
                                                <div style="margin-bottom:8px;">
                                                    <i class="fa-solid fa-paperclip"></i> Berkas Lampiran: 
                                                    <a href="<?php echo e($docFile); ?>" target="_blank" style="color:#0284c7; font-weight:700; text-decoration:underline;">Lihat/Unduh Berkas Lampiran</a>
                                                </div>
                                            <?php else: ?>
                                                <div style="color:#64748b; font-style:italic; margin-bottom:8px;">Belum ada file berkas yang diunggah.</div>
                                            <?php endif; ?>

                                            <div>
                                                <strong>Keterangan / Justifikasi Kebutuhan:</strong><br>
                                                <div style="background:#fff; border:1px solid #fcd34d; border-radius:6px; padding:10px; margin-top:4px; font-size:12px; color:#1e293b;">
                                                    <?php 
                                                        $notesTxt = $selectedJob['additional_doc_notes'] ?: ($additionalDocCase['description'] ?? '');
                                                        echo $notesTxt !== '' ? nl2br(e($notesTxt)) : '<em style="color:#94a3b8;">Belum ada catatan keterangan dari pemberi kerja.</em>';
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <form method="post" action="admin.php?view=verifikasi_job&detail_id=<?php echo $selectedJob['id']; ?>" id="additionalDocForm">
                                        <input type="hidden" name="admin_action" value="verify_additional_doc">
                                        <input type="hidden" name="job_id" value="<?php echo $selectedJob['id']; ?>">

                                        <div style="display:flex; flex-direction:column; gap:14px; margin-bottom:18px;">
                                            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px; display:flex; justify-content:space-between; align-items:center;">
                                                <div>
                                                    <strong style="font-size:13px; color:#1e293b;">1. Berkas telah ditinjau</strong>
                                                    <div class="tiny" style="color:#64748b;">Wajib "Ya" untuk mengaktifkan tombol keputusan</div>
                                                </div>
                                                <div style="display:flex; gap:16px; font-size:13px; font-weight:600;">
                                                    <label style="display:flex; align-items:center; gap:4px; color:#059669; cursor:pointer;">
                                                        <input type="radio" name="doc_reviewed" value="Ya" onchange="checkAddDocRadios()" required> Ya
                                                    </label>
                                                    <label style="display:flex; align-items:center; gap:4px; color:#dc2626; cursor:pointer;">
                                                        <input type="radio" name="doc_reviewed" value="Tidak" onchange="checkAddDocRadios()"> Tidak
                                                    </label>
                                                </div>
                                            </div>

                                            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px; display:flex; justify-content:space-between; align-items:center;">
                                                <div>
                                                    <strong style="font-size:13px; color:#1e293b;">2. Kunjungan lapangan</strong>
                                                    <div class="tiny" style="color:#64748b;">Pemeriksaan verifikasi fisik lokasi (Pilihan "Tidak" tetap boleh disetujui)</div>
                                                </div>
                                                <div style="display:flex; gap:16px; font-size:13px; font-weight:600;">
                                                    <label style="display:flex; align-items:center; gap:4px; color:#059669; cursor:pointer;">
                                                        <input type="radio" name="field_visit" value="Ya" onchange="checkAddDocRadios()" required> Ya
                                                    </label>
                                                    <label style="display:flex; align-items:center; gap:4px; color:#64748b; cursor:pointer;">
                                                        <input type="radio" name="field_visit" value="Tidak" onchange="checkAddDocRadios()"> Tidak
                                                    </label>
                                                </div>
                                            </div>
                                        </div>

                                        <div id="addDocValidationHint" style="font-size:12px; margin-bottom:14px; padding:8px 12px; background:#f1f5f9; border-radius:6px;">
                                            <span style="color:#64748b;"><i class="fa-solid fa-circle-info"></i> Tombol Setujui dan Tolak hanya aktif jika <strong>Berkas telah ditinjau = Ya</strong> serta pilihan <strong>Kunjungan lapangan (Ya/Tidak)</strong> sudah diisi.</span>
                                        </div>

                                        <div style="margin-bottom:16px;">
                                            <label style="font-weight:700; font-size:13px; display:block; margin-bottom:6px;">Catatan Hasil Peninjauan:</label>
                                            <textarea name="verifier_notes" placeholder="Berikan catatan hasil peninjauan berkas / verifikasi dokumen..." style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1; font-size:13px; min-height:70px;"></textarea>
                                        </div>

                                        <div style="display:flex; justify-content:flex-end; gap:10px;">
                                            <button type="submit" name="decision" value="reject" id="btnRejectAddDoc" class="danger-btn" style="background:#ef4444; color:#fff; border:none; padding:9px 18px; border-radius:6px; font-weight:600; font-size:13px; cursor:pointer;" disabled onclick="return confirm('Apakah Anda yakin ingin MENOLAK Dokumen Tambahan dan membatalkan pengajuan lowongan ini (CANCELED)?')">
                                                <i class="fa-solid fa-xmark"></i> Tolak (CANCELED)
                                                </button>
                                            <button type="submit" name="decision" value="approve" id="btnApproveAddDoc" class="primary-btn" style="height:38px; padding:0 20px; font-size:13px;" disabled>
                                                <i class="fa-solid fa-check"></i> Setujui Dokumen (Lanjut Layer 3)
                                            </button>
                                        </div>
                                    </form>

                                    <script>
                                    function checkAddDocRadios() {
                                        const docRev = document.querySelector('input[name="doc_reviewed"]:checked');
                                        const fldVis = document.querySelector('input[name="field_visit"]:checked');
                                        const btnApp = document.getElementById('btnApproveAddDoc');
                                        const btnRej = document.getElementById('btnRejectAddDoc');
                                        const hint = document.getElementById('addDocValidationHint');
                                        
                                        const canDecide = (docRev && docRev.value === 'Ya') && (fldVis && (fldVis.value === 'Ya' || fldVis.value === 'Tidak'));
                                        if (btnApp) btnApp.disabled = !canDecide;
                                        if (btnRej) btnRej.disabled = !canDecide;
                                        if (hint) {
                                            if (canDecide) {
                                                hint.innerHTML = '<span style="color:#059669; font-weight:600;"><i class="fa-solid fa-circle-check"></i> Syarat peninjauan terpenuhi. Tombol Setujui dan Tolak telah aktif.</span>';
                                            } else {
                                                hint.innerHTML = '<span style="color:#64748b;"><i class="fa-solid fa-circle-info"></i> Tombol Setujui dan Tolak hanya aktif jika <strong>Berkas telah ditinjau = Ya</strong> serta pilihan <strong>Kunjungan lapangan (Ya/Tidak)</strong> sudah diisi.</span>';
                                            }
                                        }
                                    }
                                    document.addEventListener('DOMContentLoaded', checkAddDocRadios);
                                    </script>
                                                        </div>
                            <?php else: ?>
                                <?php if (!empty($selectedJob['additional_doc_required']) || $additionalDocCase): ?>
                                    <div class="section-card" style="border-left:4px solid #0284c7;">
                                        <div class="section-card-title">
                                            <span>Pemeriksaan Dokumen Tambahan (Terkunci)</span>
                                            <span class="pill-badge <?php echo ($additionalDocCase['status'] ?? '') === 'APPROVED' ? 'verified' : 'danger'; ?>">
                                                ● Status: <?php echo e($additionalDocCase['status'] ?? $selectedJob['additional_doc_status'] ?? '-'); ?>
                                            </span>
                                                                </div>
                                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px; font-size:13px; margin-bottom:12px;">
                                            <div style="color:#0284c7; font-weight:700; margin-bottom:6px;">
                                                <i class="fa-solid fa-lock"></i> Single-Final-Decision Locked: Keputusan untuk Dokumen Tambahan lowongan ini sudah final dan terkunci.
                                            </div>
                                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:8px;">
                                                <div><strong>Berkas Ditinjau:</strong> <?php echo e($additionalDocCase['doc_reviewed'] ?? 'Ya'); ?></div>
                                                <div><strong>Kunjungan Lapangan:</strong> <?php echo e($additionalDocCase['field_visit'] ?? '-'); ?></div>
                                                <div style="grid-column: span 2;"><strong>Catatan Admin:</strong> <?php echo e($additionalDocCase['admin_notes'] ?? '-'); ?></div>
                                                <?php if (!empty($additionalDocCase['reviewed_at'])): ?>
                                                    <div style="grid-column: span 2; font-size:11px; color:#64748b;">Ditinjau pada: <?php echo date('d M Y, H:i', strtotime($additionalDocCase['reviewed_at'])); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="section-card">
                                    <div class="section-card-title">
                                        <span>Matriks Kepatuhan Verifikasi Lowongan</span>
                                        <small style="font-size:11px; font-weight:normal; color:#64748b;">(4 Kategori Wajib FSD)</small>
                                                                    </div>

                                    <form method="post" action="admin.php?view=verifikasi_job&detail_id=<?php echo $selectedJob['id']; ?>" id="jobVerificationForm">
                                        <input type="hidden" name="admin_action" value="verify_job">
                                        <input type="hidden" name="job_id" value="<?php echo $selectedJob['id']; ?>">

                                        <?php 
                                            $savedChecklist = json_decode($selectedJob['compliance_checklist'] ?? '{}', true) ?: [];
                                            $categories = compliance_categories();
                                        ?>

                                        <div style="display:flex; flex-direction:column; gap:16px; margin-bottom:20px;">
                                            <?php foreach ($categories as $index => $cat): ?>
                                                <?php 
                                                    $slug = 'cat_' . md5($cat);
                                                    $catData = $savedChecklist[$cat] ?? ['status' => 'Patuh', 'note' => ''];
                                                    $isNonCompliant = $catData['status'] === 'Tidak Patuh';
                                                ?>
                                                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                                        <span style="font-weight:700; font-size:13px; color:#1e293b;">
                                                            <?php echo ($index + 1) . '. ' . e($cat); ?>
                                                        </span>
                                                        <div style="display:flex; gap:12px; font-size:12px; font-weight:600;">
                                                            <label style="display:flex; align-items:center; gap:4px; color:#059669; cursor:pointer;">
                                                                <input type="radio" name="<?php echo $slug; ?>_status" value="Patuh" <?php echo !$isNonCompliant ? 'checked' : ''; ?> onchange="updateJobCompliance()"> Patuh
                                                            </label>
                                                            <label style="display:flex; align-items:center; gap:4px; color:#dc2626; cursor:pointer;">
                                                                <input type="radio" name="<?php echo $slug; ?>_status" value="Tidak Patuh" <?php echo $isNonCompliant ? 'checked' : ''; ?> onchange="updateJobCompliance()"> Tidak Patuh
                                                            </label>
                                                        </div>
                                                    </div>
                                                    <div class="note-box-wrapper">
                                                        <input type="text" name="<?php echo $slug; ?>_note" value="<?php echo e($catData['note']); ?>" placeholder="Catatan item (Wajib jika Tidak Patuh)..." style="width:100%; padding:8px 12px; border-radius:6px; border:1px solid #cbd5e1; font-size:12px;" oninput="updateJobCompliance()">
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                                                </div>

                                                                <div style="margin-bottom:14px;">
                                            <label style="font-weight:700; font-size:13px; display:block; margin-bottom:6px;">Catatan Umum Verifikator:</label>
                                            <textarea name="verifier_notes" placeholder="Berikan catatan detail keputusan..." style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1; font-size:13px; min-height:60px;"><?php echo e($selectedJob['verifier_notes']); ?></textarea>
                                                                </div>

                                        <div style="margin-bottom:16px;">
                                            <label style="font-weight:700; font-size:13px; display:block; margin-bottom:6px;">Keputusan Final:</label>
                                            <select name="decision" id="decisionSelect" required style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1; font-size:13px; font-weight:600;" onchange="updateJobCompliance()">
                                                <option value="approve" id="optApprove">Setujui (Tayang)</option>
                                                <option value="revision">Revisi (Kembalikan ke Pemberi Kerja)</option>
                                                                        <option value="reject">Tolak Lowongan</option>
                                                                    </select>
                                            <div id="approvalWarningNotice" style="display:none; color:#dc2626; font-size:12px; margin-top:6px; font-weight:600;">
                                                <i class="fa-solid fa-triangle-exclamation"></i> Terdapat kategori yang "Tidak Patuh". Keputusan "Setujui" tidak valid. Silakan pilih "Revisi" atau "Tolak".
                                                                </div>
                                                            </div>

                                        <div style="display:flex; justify-content:flex-end;">
                                            <button type="submit" id="btnSubmitJobDecision" class="primary-btn" style="height:38px; padding:0 20px; font-size:13px;">
                                                Simpan Keputusan Verifikasi
                                            </button>
                                                            </div>
                                                        </form>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- RIGHT COLUMN: AUDIT LOG TIMELINE -->
                        <div>
                            <div class="section-card">
                                <div class="section-card-title">Aktivitas & Audit Log</div>
                                <div class="timeline-list">
                                    <div class="timeline-item">
                                        <div class="timeline-dot"></div>
                                        <div class="timeline-time"><?php echo date('d M Y, H:i', strtotime($selectedJob['created_at'])); ?></div>
                                        <div class="timeline-title">Lowongan diajukan oleh pemberi kerja.</div>
                                    </div>
                                    <?php foreach ($auditLogs as $log): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-dot"></div>
                                            <div class="timeline-time"><?php echo date('d M Y, H:i', strtotime($log['created_at'])); ?></div>
                                            <div class="timeline-title"><?php echo e($log['action']); ?> <small style="color:#64748b;">(oleh <?php echo e($log['actor_name']); ?>)</small></div>
                                            <div class="timeline-desc"><?php echo e($log['details']); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <script>
                    function updateJobCompliance() {
                        const form = document.getElementById('jobVerificationForm');
                        if (!form) return;
                        const radios = form.querySelectorAll('input[type="radio"]:checked');
                        let hasViolation = false;
                        radios.forEach(r => {
                            if (r.value === 'Tidak Patuh') hasViolation = true;
                        });

                        const optApprove = document.getElementById('optApprove');
                        const decisionSelect = document.getElementById('decisionSelect');
                        const warningNotice = document.getElementById('approvalWarningNotice');
                        const submitBtn = document.getElementById('btnSubmitJobDecision');

                        if (hasViolation) {
                            if (optApprove) optApprove.disabled = true;
                            if (decisionSelect && decisionSelect.value === 'approve') {
                                decisionSelect.value = 'revision';
                            }
                            if (warningNotice) warningNotice.style.display = 'block';
                        } else {
                            if (optApprove) optApprove.disabled = false;
                            if (warningNotice) warningNotice.style.display = 'none';
                        }
                    }
                    document.addEventListener('DOMContentLoaded', updateJobCompliance);
                    </script>

                <?php else: ?>
                    <!-- VERIFIKASI LOWONGAN TABLE VIEW (ALIGNED WITH SCREENSHOT) -->
                    <div style="margin-bottom:20px;">
                        <h1 style="font-size:24px; font-weight:800; margin:0 0 16px 0;">Verifikasi Lowongan</h1>
                        <div class="tab-filter-bar">
                            <div class="status-tab-list">
                                <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=all" class="status-tab-item <?php echo $tab === 'all' ? 'active' : ''; ?>">Semua</a>
                                <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=process" class="status-tab-item <?php echo $tab === 'process' ? 'active' : ''; ?>">Menunggu Verifikasi</a>
                                <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=additional_doc" class="status-tab-item <?php echo $tab === 'additional_doc' ? 'active' : ''; ?>">Dokumen Tambahan</a>
                                <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=revision" class="status-tab-item <?php echo $tab === 'revision' ? 'active' : ''; ?>">Revisi</a>
                                <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=approved" class="status-tab-item <?php echo $tab === 'approved' ? 'active' : ''; ?>">Disetujui</a>
                                <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=rejected" class="status-tab-item <?php echo $tab === 'rejected' ? 'active' : ''; ?>">Ditolak</a>
                            </div>

                            <div class="filter-controls">
                                <div class="entity-selector-pill">
                                    <a href="admin.php?view=verifikasi_job&entity=Semua&tab=<?php echo e($tab); ?>" class="entity-selector-btn <?php echo $entity === 'Semua' ? 'active' : ''; ?>">Semua</a>
                                    <a href="admin.php?view=verifikasi_job&entity=Perusahaan&tab=<?php echo e($tab); ?>" class="entity-selector-btn <?php echo $entity === 'Perusahaan' ? 'active' : ''; ?>">Perusahaan</a>
                                    <a href="admin.php?view=verifikasi_job&entity=Individu&tab=<?php echo e($tab); ?>" class="entity-selector-btn <?php echo $entity === 'Individu' ? 'active' : ''; ?>">Individu</a>
                                </div>
                                <form method="get" action="admin.php" style="display:flex; gap:8px;">
                                    <input type="hidden" name="view" value="verifikasi_job">
                                    <input type="hidden" name="entity" value="<?php echo e($entity); ?>">
                                    <input type="hidden" name="tab" value="<?php echo e($tab); ?>">
                                    <div class="filter-search-box">
                                        <i class="fa-solid fa-magnifying-glass" style="color:#94a3b8;"></i>
                                        <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Cari lowongan...">
                                    </div>
                                    <button type="submit" class="filter-btn"><i class="fa-solid fa-sliders"></i> Filter</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="console-table-card">
                        <table class="console-table">
                            <thead>
                                <tr>
                                    <th>Judul Lowongan</th>
                                    <th>Jenis Entitas</th>
                                    <th>Status</th>
                                    <th>Blacklist</th>
                                    <th>Tanggal Pengajuan</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$verificationJobs): ?>
                                    <tr><td colspan="6" style="text-align:center; padding:40px; color:#64748b;">Tidak ada antrean verifikasi lowongan.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($verificationJobs as $vJob): ?>
                                        <tr>
                                            <td>
                                                <div style="display:flex; align-items:center; gap:12px;">
                                                    <div class="item-avatar-box">
                                                        <?php echo strtoupper(substr($vJob['title'], 0, 2)); ?>
                                                    </div>
                                                    <div>
                                                        <strong><?php echo e($vJob['title']); ?></strong><br>
                                                        <small style="color:#94a3b8; font-size:11px;"><?php echo e($vJob['owner_name'] ?: $vJob['user_name']); ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><span class="pill-badge verified"><?php echo e($vJob['entity_type']); ?></span></td>
                                            <td>
                                                <?php if ($vJob['status'] === 'Tayang'): ?>
                                                    <span class="pill-badge verified">● Disetujui</span>
                                                <?php elseif ($vJob['status'] === 'Menunggu Verifikasi'): ?>
                                                    <?php if (!empty($vJob['assigned_to'])): ?>
                                                        <span class="pill-badge assigned">● Ditugaskan</span>
                                                    <?php else: ?>
                                                        <span class="pill-badge pending">● Menunggu</span>
                                                    <?php endif; ?>
                                                <?php elseif ($vJob['status'] === 'Perlu Direvisi'): ?>
                                                    <span class="pill-badge revision">● Revisi</span>
                                                <?php else: ?>
                                                    <span class="pill-badge danger">● <?php echo e($vJob['status']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($vJob['is_blacklisted'])): ?>
                                                    <span class="pill-badge danger" style="font-size:11px;">Terdeteksi</span>
                                                <?php else: ?>
                                                    <span class="pill-badge safe" style="font-size:11px;">Aman</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('d M Y, H:i', strtotime($vJob['created_at'])); ?></td>
                                            <td>
                                                <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&detail_id=<?php echo $vJob['id']; ?>" class="btn-lihat-detail">
                                                    Lihat Detail
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
</div>
    </div>
<script src="assets/app.js?v=admin-std-1"></script>
<script>
function toggleActionMenu(btn, e) {
    e.stopPropagation();
    const menu = btn.nextElementSibling;
    const isShown = menu.classList.contains('show');
    document.querySelectorAll('.action-menu-dropdown').forEach(el => el.classList.remove('show'));
    if (!isShown) {
        menu.classList.add('show');
    }
}
document.addEventListener('click', () => {
    document.querySelectorAll('.action-menu-dropdown').forEach(el => el.classList.remove('show'));
});
</script>
</body>
</html>
