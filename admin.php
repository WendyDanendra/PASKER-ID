<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/platform.php';

$user = require_role('admin');

// Active Section & Filters
$view = $_GET['view'] ?? 'directory_individual';
$entity = $_GET['entity'] ?? 'Semua';
$tab = $_GET['tab'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');
$cityFilter = trim($_GET['city_filter'] ?? '');
$verifierFilter = trim($_GET['verifier_filter'] ?? '');
$officerFilter = trim($_GET['officer_filter'] ?? '');
$unassignedFilter = isset($_GET['unassigned']) && $_GET['unassigned'] === '1' ? 1 : 0;
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
                $currentRev = (int)($targetEmp['revision_count'] ?? ($targetEmp['rejection_count'] ?? 0));
                $newRevisionCount = min(3, $currentRev + 1);
                $manualStatus = ($newRevisionCount >= 3) ? 'MANUAL_DINAS_REVIEW' : 'NONE';

                try {
                    $stmt = $pdo->prepare('UPDATE employer_profiles SET verified = 0, verification_status = "NEEDS_REVISION", revision_count = ?, rejection_count = ?, manual_review_status = ?, verifier_notes = ?, verification_checklist = ? WHERE user_id = ?');
                    $stmt->execute([$newRevisionCount, $newRevisionCount, $manualStatus, $notes, $checklist, $targetUserId]);
                } catch (Throwable $ignore) {
                    $stmt = $pdo->prepare('UPDATE employer_profiles SET verified = 0, verification_status = "NEEDS_REVISION", rejection_count = ?, verifier_notes = ?, verification_checklist = ? WHERE user_id = ?');
                    $stmt->execute([$newRevisionCount, $notes, $checklist, $targetUserId]);
                }
                record_audit_log('employer', $targetUserId, 'REVISION_REQUESTED', "Permintaan perbaikan data (Revisi ke-{$newRevisionCount}) dikirim ke pemohon. Catatan: {$notes}", $user['name'], $user['role'] ?? 'admin', true);
                notify_user($targetUserId, "Perbaikan Profil Diperlukan (Revisi ke-{$newRevisionCount})", 'Verifikator meminta perbaikan profil: ' . $notes, 'warning');
                $pdo->commit();
                flash('success', "Profil dikembalikan ke pemohon untuk diperbaiki (Diminta Revisi ke-{$newRevisionCount}).");
            } elseif ($decision === 'reject') {
                $newRejectionCount = (int)($targetEmp['rejection_count'] ?? 0) + 1;
                $stmt = $pdo->prepare('UPDATE employer_profiles SET verified = 0, verification_status = "REJECTED", rejection_count = ?, verifier_notes = ?, verification_checklist = ? WHERE user_id = ?');
                $stmt->execute([$newRejectionCount, $notes, $checklist, $targetUserId]);
                record_audit_log('employer', $targetUserId, 'REJECTED', "Profil ditolak. Catatan: {$notes}", $user['name'], $user['role'] ?? 'admin', true);
                notify_user($targetUserId, 'Profil Ditolak', 'Verifikator menolak profil Anda: ' . $notes, 'error');
                $pdo->commit();
                flash('success', 'Profil Pemberi Kerja telah Ditolak.');
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

    // 3. JALUR MANUAL DINAS: CONTROLLED EDIT & AJUKAN PERMOHONAN ULANG
    if ($action === 'manual_dinas_edit') {
        $targetUserId = (int)$_POST['user_id'];
        $ownerName = trim($_POST['owner_name'] ?? '');
        $nik = trim($_POST['nik'] ?? '');
        $profession = trim($_POST['profession'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $whatsapp = trim($_POST['whatsapp'] ?? '');
        $npwp = trim($_POST['npwp'] ?? '');
        $instagram = trim($_POST['instagram'] ?? '');
        $facebook = trim($_POST['facebook'] ?? '');
        $linkedin = trim($_POST['linkedin'] ?? '');
        $province = trim($_POST['province'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $district = trim($_POST['district'] ?? '');
        $village = trim($_POST['village'] ?? '');
        $postalCode = trim($_POST['postal_code'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $addressDetail = trim($_POST['address_detail'] ?? '');
        $description = trim($_POST['description'] ?? '');

        // Fetch old profile
        $stmtOld = db()->prepare('SELECT * FROM employer_profiles WHERE user_id = ? LIMIT 1');
        $stmtOld->execute([$targetUserId]);
        $oldProfile = $stmtOld->fetch() ?: [];

        $permitDoc = store_upload('permit_document', 'employer/' . $targetUserId, ['pdf', 'jpg', 'jpeg', 'png']);
        $workplacePhoto = store_upload('workplace_photo', 'employer/' . $targetUserId, ['jpg', 'jpeg', 'png', 'webp']);
        $permitDoc = $permitDoc ?: ($oldProfile['permit_document'] ?? $oldProfile['doc_permission'] ?? null);
        $workplacePhoto = $workplacePhoto ?: ($oldProfile['workplace_photo'] ?? $oldProfile['doc_location_photo'] ?? null);

        $updateSql = <<<SQL
            UPDATE employer_profiles SET
                owner_name = ?, nik = ?, profession = ?, phone = ?, whatsapp = ?, npwp = ?,
                instagram = ?, facebook = ?, linkedin = ?,
                province = ?, city = ?, district = ?, village = ?, postal_code = ?,
                address = ?, address_detail = ?, description = ?,
                permit_document = ?, doc_permission = ?, workplace_photo = ?, doc_location_photo = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE user_id = ?
        SQL;
        db()->prepare($updateSql)->execute([
            $ownerName, $nik, $profession, $phone, $whatsapp, $npwp,
            $instagram, $facebook, $linkedin,
            $province, $city, $district, $village, $postalCode,
            $address, $addressDetail, $description,
            $permitDoc, $permitDoc, $workplacePhoto, $workplacePhoto,
            $targetUserId
        ]);

        $newProfileData = [
            'owner_name' => $ownerName, 'nik' => $nik, 'profession' => $profession,
            'phone' => $phone, 'whatsapp' => $whatsapp, 'npwp' => $npwp, 'province' => $province,
            'city' => $city, 'district' => $district, 'village' => $village, 'postal_code' => $postalCode,
            'address' => $address, 'address_detail' => $addressDetail, 'description' => $description
        ];
        $newHash = calculate_employer_consent_hash($newProfileData);

        if (!empty($_POST['send_consent'])) {
            db()->prepare('UPDATE employer_profiles SET manual_review_status = "CONSENT_PENDING", consent_data_hash = ?, consent_agreed = 0 WHERE user_id = ?')->execute([$newHash, $targetUserId]);
            record_audit_log('employer', $targetUserId, 'ADMIN_PROFILE_EDIT', "Petugas Dinas memperbarui seluruh data profil pemohon pada permohonan ulang.", $user['name'], $user['role'] ?? 'admin', true);
            record_audit_log('employer', $targetUserId, 'CONSENT_REQUESTED', "Petugas Dinas mengirimkan permintaan persetujuan (Consent) ke pemohon.", $user['name'], $user['role'] ?? 'admin', true);
            notify_user($targetUserId, 'Persetujuan Data Diperlukan (Jalur Dinas)', 'Petugas Dinas telah menyiapkan data perbaikan profil Anda. Silakan tinjau dan berikan persetujuan (Consent) di Dashboard Anda.', 'warning');
            flash('success', 'Data profil berhasil diperbarui dan Permintaan Persetujuan (Consent) telah dikirim ke akun pemohon.');
        } else {
            if (!empty($oldProfile['consent_data_hash']) && $oldProfile['consent_data_hash'] !== $newHash) {
                db()->prepare('UPDATE employer_profiles SET manual_review_status = "INVALID", consent_agreed = 0, consent_data_hash = NULL WHERE user_id = ?')->execute([$targetUserId]);
                record_audit_log('employer', $targetUserId, 'CONSENT_INVALIDATED', "Data profil diubah oleh Petugas Dinas setelah persetujuan pemohon. Consent sebelumnya otomatis INVALID.", $user['name'], $user['role'] ?? 'admin', true);
                flash('warning', 'Data profil berhasil diperbarui. PERINGATAN: Karena data berubah, persetujuan (consent) pemohon sebelumnya menjadi INVALID. Silakan klik Kirim Permintaan Consent ulang.');
            } else {
                record_audit_log('employer', $targetUserId, 'ADMIN_PROFILE_EDIT', "Petugas Dinas memperbarui data profil pemohon.", $user['name'], $user['role'] ?? 'admin', true);
                flash('success', 'Data profil berhasil disimpan oleh Petugas Dinas.');
            }
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
            record_audit_log('employer', $targetUserId, 'CONSENT_REQUESTED', "Petugas Dinas mengirimkan permintaan persetujuan (Consent) ke pemohon.", $user['name'], $user['role'] ?? 'admin', true);
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
            flash('error', 'Pernyataan Petugas dan konfirmasi checklist wajib dicentang.');
            redirect($redirectUrl);
            exit;
        }

        // Verify consent is valid
        $stmtEmp = db()->prepare('SELECT * FROM employer_profiles WHERE user_id = ? LIMIT 1');
        $stmtEmp->execute([$targetUserId]);
        $emp = $stmtEmp->fetch();

        $currentHash = calculate_employer_consent_hash($emp);
        if ($emp['manual_review_status'] !== 'CONSENT_GIVEN' || empty($emp['consent_data_hash']) || $emp['consent_data_hash'] !== $currentHash) {
            flash('error', 'Persetujuan pemohon belum disetujui atau data telah berubah setelah consent. Setujui & Aktifkan dibatalkan.');
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
        record_audit_log('employer', $targetUserId, 'APPROVED_MANUAL_DINAS', "Profil disetujui & diaktifkan melalui Jalur Manual Dinas oleh petugas: {$officerName}. Pernyataan: {$officerStatement}", $user['name'], $user['role'] ?? 'admin', true);
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

    // Filter for Individual entity type
    $query .= ' AND (ep.entity_type = "Individu" OR ep.entity_type IS NULL)';

    if ($search !== '') {
        $query .= ' AND (u.name LIKE ? OR u.email LIKE ? OR ep.phone LIKE ? OR ep.city LIKE ? OR ep.address LIKE ? OR ep.npwp LIKE ?)';
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
    }

    if ($startDate !== '') {
        $query .= ' AND DATE(u.created_at) >= ?';
        $params[] = $startDate;
    }
    if ($endDate !== '') {
        $query .= ' AND DATE(u.created_at) <= ?';
        $params[] = $endDate;
    }
    if ($cityFilter !== '') {
        $query .= ' AND (ep.city LIKE ? OR ep.domicile_city_id LIKE ? OR ep.province LIKE ?)';
        $cityLike = '%' . $cityFilter . '%';
        $params = array_merge($params, [$cityLike, $cityLike, $cityLike]);
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

    $sort = $_GET['sort'] ?? 'date_desc';
    if ($sort === 'name_asc') {
        $query .= ' ORDER BY COALESCE(NULLIF(ep.owner_name, ""), u.name) ASC';
    } elseif ($sort === 'name_desc') {
        $query .= ' ORDER BY COALESCE(NULLIF(ep.owner_name, ""), u.name) DESC';
    } elseif ($sort === 'date_asc') {
        $query .= ' ORDER BY u.created_at ASC';
    } else {
        $query .= ' ORDER BY u.created_at DESC';
    }

    $stmt = db()->prepare($query);
    $stmt->execute($params);
    $individualList = $stmt->fetchAll() ?: [];

    $perPage = 20;
    $totalData = count($individualList);
    $totalPages = max(1, (int)ceil($totalData / $perPage));
    $page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
    $showingList = array_slice($individualList, ($page - 1) * $perPage, $perPage);
    $showingCount = count($showingList);

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
        $query .= ' WHERE (ep.entity_type = "Individu" OR ep.entity_type = "Individual" OR ep.entity_type IS NULL)';
    } elseif ($entity === 'Perusahaan') {
        $query .= ' WHERE ep.entity_type = "Perusahaan"';
    } else {
        $query .= ' WHERE 1=1';
    }

    if ($search !== '') {
        $query .= ' AND (u.name LIKE ? OR u.email LIKE ? OR ep.owner_name LIKE ? OR ep.phone LIKE ? OR ep.city LIKE ? OR ep.address LIKE ? OR ep.npwp LIKE ?)';
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like]);
    }

    if ($startDate !== '') {
        $query .= ' AND DATE(u.created_at) >= ?';
        $params[] = $startDate;
    }
    if ($endDate !== '') {
        $query .= ' AND DATE(u.created_at) <= ?';
        $params[] = $endDate;
    }
    if ($cityFilter !== '') {
        $query .= ' AND (ep.city LIKE ? OR ep.province LIKE ? OR ep.district LIKE ? OR ep.address LIKE ?)';
        $cityLike = '%' . $cityFilter . '%';
        $params = array_merge($params, [$cityLike, $cityLike, $cityLike, $cityLike]);
    }
    if ($verifierFilter !== '') {
        $query .= ' AND (ep.assigned_to LIKE ? OR ep.verifier_notes LIKE ?)';
        $vLike = '%' . $verifierFilter . '%';
        $params = array_merge($params, [$vLike, $vLike]);
    }
    if ($officerFilter !== '') {
        $query .= ' AND ep.officer_name LIKE ?';
        $params[] = '%' . $officerFilter . '%';
    }
    if ($unassignedFilter === 1) {
        $query .= ' AND (ep.assigned_to IS NULL OR ep.assigned_to = "")';
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

    $sort = $_GET['sort'] ?? 'date_desc';
    if ($sort === 'name_asc') {
        $query .= ' ORDER BY ep.owner_name ASC, u.name ASC';
    } elseif ($sort === 'name_desc') {
        $query .= ' ORDER BY ep.owner_name DESC, u.name DESC';
    } elseif ($sort === 'date_asc') {
        $query .= ' ORDER BY u.created_at ASC';
    } else {
        $query .= ' ORDER BY u.created_at DESC';
    }

    $stmt = db()->prepare($query);
    $stmt->execute($params);
    $verificationEmployers = $stmt->fetchAll() ?: [];

    $perPage = 20;
    $totalData = count($verificationEmployers);
    $totalPages = max(1, (int)ceil($totalData / $perPage));
    $page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
    $offset = ($page - 1) * $perPage;
    $showingEmployers = array_slice($verificationEmployers, $offset, $perPage);

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
        SELECT j.*, ep.owner_name, ep.profession, ep.city as emp_city, ep.province as emp_province, u.name as user_name, u.email as user_email
        FROM job_posts j
        JOIN users u ON u.id = j.user_id
        LEFT JOIN employer_profiles ep ON ep.user_id = u.id
    SQL;
    $params = [];

    if ($entity === 'Perusahaan') {
        $query .= ' WHERE j.entity_type = "Perusahaan"';
    } else {
        $query .= ' WHERE (j.entity_type = "Individu" OR j.entity_type = "Individual" OR j.entity_type IS NULL)';
    }

    if ($search !== '') {
        $query .= ' AND (j.title LIKE ? OR j.location LIKE ? OR j.kbji_code LIKE ? OR u.name LIKE ? OR ep.owner_name LIKE ?)';
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like, $like, $like]);
    }

    if ($startDate !== '') {
        $query .= ' AND DATE(j.created_at) >= ?';
        $params[] = $startDate;
    }
    if ($endDate !== '') {
        $query .= ' AND DATE(j.created_at) <= ?';
        $params[] = $endDate;
    }
    if ($cityFilter !== '') {
        $query .= ' AND (j.location LIKE ? OR ep.city LIKE ? OR ep.domicile_city_id LIKE ? OR ep.province LIKE ?)';
        $cityLike = '%' . $cityFilter . '%';
        $params = array_merge($params, [$cityLike, $cityLike, $cityLike, $cityLike]);
    }

    if ($tab === 'process') {
        $query .= ' AND j.status = "Menunggu Verifikasi"';
    } elseif ($tab === 'revision') {
        $query .= ' AND j.status = "Perlu Direvisi"';
    } elseif ($tab === 'approved') {
        $query .= ' AND j.status = "Tayang"';
    } elseif ($tab === 'rejected') {
        $query .= ' AND (j.status = "Ditolak" OR j.status = "CANCELED")';
    } elseif ($tab === 'additional_doc') {
        $query .= ' AND j.status = "ADDITIONAL_DOCUMENT_PENDING"';
    }

    // Admin Dinas Scope Filter (exact domicile_city_id match)
    if ($user['role'] === 'admin_dinas' || (!empty($user['domicile_city_id']) && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
        $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
        if ($adminDomicileCity !== '') {
            $query .= ' AND ep.domicile_city_id = ?';
            $params[] = $adminDomicileCity;
        }
    }

    $sort = $_GET['sort'] ?? 'date_desc';
    if ($sort === 'name_asc') {
        $query .= ' ORDER BY j.title ASC';
    } elseif ($sort === 'name_desc') {
        $query .= ' ORDER BY j.title DESC';
    } elseif ($sort === 'date_asc') {
        $query .= ' ORDER BY j.created_at ASC';
    } else {
        $query .= ' ORDER BY j.created_at DESC';
    }

    $stmt = db()->prepare($query);
    $stmt->execute($params);
    $verificationJobs = $stmt->fetchAll() ?: [];

    $perPage = 20;
    $totalData = count($verificationJobs);
    $totalPages = max(1, (int)ceil($totalData / $perPage));
    $page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
    $showingJobs = array_slice($verificationJobs, ($page - 1) * $perPage, $perPage);
    $showingCount = count($showingJobs);

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
                <i class="fa-solid fa-user"></i>
            </a>
            <a href="admin.php?view=verifikasi_employer&entity=Individu" class="rail-btn <?php echo str_starts_with($view, 'verifikasi_employer') ? 'active' : ''; ?>" title="Verifikasi Pemberi Kerja">
                <i class="fa-solid fa-user-check"></i>
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
                <i class="fa-solid fa-user"></i>
                <span>Individual</span>
            </a>
            <a href="admin.php?view=verifikasi_employer&entity=Individu" class="drawer-menu-item <?php echo str_starts_with($view, 'verifikasi_employer') ? 'active' : ''; ?>">
                <i class="fa-solid fa-user-check"></i>
                <span>Verifikasi Pemberi Kerja</span>
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
                    <a href="admin.php?view=directory_individual" style="color:inherit;text-decoration:none;">Individual</a>
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
                    <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Cari lowongan, pemberi kerja, pencari kerja, atau..." style="border:none;outline:none;width:100%;background:transparent;font-size:13px;color:var(--text-dark, #0f172a);">
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

<script>
window.MONTH_NAMES = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agt", "Sep", "Okt", "Nov", "Des"];
window.CITY_MASTER = [
    "Kab. Timor Tengah Selatan, Nusa Tenggara Timur",
    "Kab. Aceh Barat, Aceh",
    "Kab. Aceh Barat Daya, Aceh",
    "Kab. Aceh Besar, Aceh",
    "Kab. Aceh Jaya, Aceh",
    "Kab. Aceh Selatan, Aceh",
    "Kab. Aceh Singkil, Aceh",
    "Kab. Aceh Tamiang, Aceh",
    "Kab. Aceh Tengah, Aceh",
    "Kab. Aceh Tenggara, Aceh",
    "Kab. Aceh Timur, Aceh",
    "Kab. Aceh Utara, Aceh",
    "Kab. Agam, Sumatera Barat",
    "Kab. Alor, Nusa Tenggara Timur",
    "Kab. Asahan, Sumatera Utara",
    "Kab. Badung, Bali",
    "Kab. Bangka, Bangka Belitung",
    "Kab. Banggai, Sulawesi Tengah",
    "Kab. Bandung, Jawa Barat",
    "Kab. Bandung Barat, Jawa Barat",
    "Kab. Bangkalan, Jawa Timur",
    "Kab. Bangli, Bali",
    "Kab. Banjar, Kalimantan Selatan",
    "Kab. Banjarnegara, Jawa Tengah",
    "Kab. Bantaeng, Sulawesi Selatan",
    "Kab. Bantul, DI Yogyakarta",
    "Kab. Banyumas, Jawa Tengah",
    "Kab. Banyuwangi, Jawa Timur",
    "Kab. Batang, Jawa Tengah",
    "Kab. Bekasi, Jawa Barat",
    "Kab. Belitung, Bangka Belitung",
    "Kab. Blora, Jawa Tengah",
    "Kab. Bogor, Jawa Barat",
    "Kab. Bojonegoro, Jawa Timur",
    "Kab. Bondowoso, Jawa Timur",
    "Kab. Boyolali, Jawa Tengah",
    "Kab. Brebes, Jawa Tengah",
    "Kab. Ciamis, Jawa Barat",
    "Kab. Cianjur, Jawa Barat",
    "Kab. Cilacap, Jawa Tengah",
    "Kab. Cirebon, Jawa Barat",
    "Kab. Demak, Jawa Tengah",
    "Kab. Garut, Jawa Barat",
    "Kab. Gianyar, Bali",
    "Kab. Gresik, Jawa Timur",
    "Kab. Grobogan, Jawa Tengah",
    "Kab. Gunungkidul, DI Yogyakarta",
    "Kab. Indramayu, Jawa Barat",
    "Kab. Jember, Jawa Timur",
    "Kab. Jepara, Jawa Tengah",
    "Kab. Jombang, Jawa Timur",
    "Kab. Karanganyar, Jawa Tengah",
    "Kab. Karawang, Jawa Barat",
    "Kab. Kebumen, Jawa Tengah",
    "Kab. Kediri, Jawa Timur",
    "Kab. Kendal, Jawa Tengah",
    "Kab. Klaten, Jawa Tengah",
    "Kab. Klungkung, Bali",
    "Kab. Kudus, Jawa Tengah",
    "Kab. Kulon Progo, DI Yogyakarta",
    "Kab. Kuningan, Jawa Barat",
    "Kab. Lamongan, Jawa Timur",
    "Kab. Lumajang, Jawa Timur",
    "Kab. Madiun, Jawa Timur",
    "Kab. Magelang, Jawa Tengah",
    "Kab. Magetan, Jawa Timur",
    "Kab. Majalengka, Jawa Barat",
    "Kab. Malang, Jawa Timur",
    "Kab. Mojokerto, Jawa Timur",
    "Kab. Nganjuk, Jawa Timur",
    "Kab. Ngawi, Jawa Timur",
    "Kab. Pacitan, Jawa Timur",
    "Kab. Pamekasan, Jawa Timur",
    "Kab. Pandeglang, Banten",
    "Kab. Pasuruan, Jawa Timur",
    "Kab. Pati, Jawa Tengah",
    "Kab. Pekalongan, Jawa Tengah",
    "Kab. Pemalang, Jawa Tengah",
    "Kab. Ponorogo, Jawa Timur",
    "Kab. Probolinggo, Jawa Timur",
    "Kab. Purbalingga, Jawa Tengah",
    "Kab. Purworejo, Jawa Tengah",
    "Kab. Rembang, Jawa Tengah",
    "Kab. Sampang, Jawa Timur",
    "Kab. Semarang, Jawa Tengah",
    "Kab. Serang, Banten",
    "Kab. Sidoarjo, Jawa Timur",
    "Kab. Sleman, DI Yogyakarta",
    "Kab. Sragen, Jawa Tengah",
    "Kab. Subang, Jawa Barat",
    "Kab. Sukabumi, Jawa Barat",
    "Kab. Sukoharjo, Jawa Tengah",
    "Kab. Sumedang, Jawa Barat",
    "Kab. Sumenep, Jawa Timur",
    "Kab. Tabalong, Kalimantan Selatan",
    "Kab. Tabanan, Bali",
    "Kab. Tangerang, Banten",
    "Kab. Tanggamus, Lampung",
    "Kab. Tasikmalaya, Jawa Barat",
    "Kab. Temanggung, Jawa Tengah",
    "Kab. Tuban, Jawa Timur",
    "Kab. Tulungagung, Jawa Timur",
    "Kab. Wonogiri, Jawa Tengah",
    "Kab. Wonosobo, Jawa Tengah",
    "Kota Bandung, Jawa Barat",
    "Kota Banjar, Jawa Barat",
    "Kota Batu, Jawa Timur",
    "Kota Bekasi, Jawa Barat",
    "Kota Blitar, Jawa Timur",
    "Kota Bogor, Jawa Barat",
    "Kota Cirebon, Jawa Barat",
    "Kota Denpasar, Bali",
    "Kota Depok, Jawa Barat",
    "Kota Jakarta Barat, DKI Jakarta",
    "Kota Jakarta Pusat, DKI Jakarta",
    "Kota Jakarta Selatan, DKI Jakarta",
    "Kota Jakarta Timur, DKI Jakarta",
    "Kota Jakarta Utara, DKI Jakarta",
    "Kota Kediri, Jawa Timur",
    "Kota Madiun, Jawa Timur",
    "Kota Magelang, Jawa Tengah",
    "Kota Malang, Jawa Timur",
    "Kota Mojokerto, Jawa Timur",
    "Kota Padang, Sumatera Barat",
    "Kota Pasuruan, Jawa Timur",
    "Kota Pekalongan, Jawa Tengah",
    "Kota Probolinggo, Jawa Timur",
    "Kota Salatiga, Jawa Tengah",
    "Kota Semarang, Jawa Tengah",
    "Kota Surakarta, Jawa Tengah",
    "Kota Surabaya, Jawa Timur",
    "Kota Tangerang, Banten",
    "Kota Tangerang Selatan, Banten",
    "Kota Tasikmalaya, Jawa Barat",
    "Kota Yogyakarta, DI Yogyakarta"
];
</script>

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
                    <?php
                    $filterParams = ($startDate ? '&start_date=' . urlencode($startDate) : '') . ($endDate ? '&end_date=' . urlencode($endDate) : '') . ($cityFilter ? '&city_filter=' . urlencode($cityFilter) : '');
                    ?>
                    <div style="margin-bottom:20px;">
                        <h1 style="font-size:24px; font-weight:800; margin:0 0 16px 0; color:#0f172a;">Individual</h1>

                        <div style="border-bottom:1px solid #e2e8f0; margin-bottom:16px;">
                            <div class="status-tab-list" style="gap:24px;">
                                <a href="admin.php?view=directory_individual&tab=all&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParams; ?>" class="status-tab-item <?php echo $tab === 'all' ? 'active' : ''; ?>">Semua</a>
                                <a href="admin.php?view=directory_individual&tab=verified&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParams; ?>" class="status-tab-item <?php echo $tab === 'verified' ? 'active' : ''; ?>">Terverifikasi</a>
                                <a href="admin.php?view=directory_individual&tab=process&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParams; ?>" class="status-tab-item <?php echo $tab === 'process' ? 'active' : ''; ?>">Dalam Proses</a>
                                <a href="admin.php?view=directory_individual&tab=rejected&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParams; ?>" class="status-tab-item <?php echo $tab === 'rejected' ? 'active' : ''; ?>">Ditolak</a>
                            </div>
                        </div>

                        <form method="get" action="admin.php" id="filterMainForm" style="display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:16px; width:100%; position:relative;">
                            <input type="hidden" name="view" value="directory_individual">
                            <input type="hidden" name="tab" value="<?php echo e($tab); ?>">
                            <?php if ($sort): ?><input type="hidden" name="sort" value="<?php echo e($sort); ?>"><?php endif; ?>
                            <input type="hidden" name="start_date" id="inputStartDate" value="<?php echo e($startDate); ?>">
                            <input type="hidden" name="end_date" id="inputEndDate" value="<?php echo e($endDate); ?>">
                            <input type="hidden" name="city_filter" id="inputCityFilter" value="<?php echo e($cityFilter); ?>">

                            <div class="filter-search-box" style="width:280px; border-radius:999px; height:38px;">
                                <i class="fa-solid fa-magnifying-glass" style="color:#94a3b8; font-size:13px;"></i>
                                <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Cari individual...">
                            </div>

                            <div style="position:relative;">
                                <button type="button" class="filter-btn" id="filterToggleBtn" onclick="toggleFilterPopover(event)" style="display:inline-flex; align-items:center; gap:6px; background:#ffffff; border:1px solid #cbd5e1; border-radius:10px; padding:7px 16px; font-size:13px; font-weight:600; color:#334155; cursor:pointer;">
                                    <i class="fa-solid fa-sliders" style="font-size:12px;"></i> Filter
                                    <?php if ($startDate || $endDate || $cityFilter): ?>
                                        <span style="background:#0284c7; color:#fff; font-size:10px; border-radius:999px; padding:1px 6px; margin-left:2px;">●</span>
                                    <?php endif; ?>
                                </button>

                                <!-- FILTER POPOVER CARD -->
                                <div id="filterPopover" style="display:none; position:absolute; right:0; top:calc(100% + 8px); width:280px; background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.05); z-index:1000; overflow:visible;">

                                    <!-- ACCORDION 1: TANGGAL PENDAFTARAN -->
                                    <div style="border-bottom:1px solid #f1f5f9;">
                                        <div onclick="toggleAccordion('date')" style="display:flex; justify-content:space-between; align-items:center; padding:14px 18px; cursor:pointer; user-select:none;">
                                            <span style="font-size:13.5px; font-weight:700; color:#0f172a;">Tanggal Pendaftaran</span>
                                            <i class="fa-solid fa-chevron-down" id="dateChevron" style="font-size:11px; color:#64748b; transition:transform 0.2s; <?php echo ($startDate || $endDate) ? 'transform:rotate(180deg);' : ''; ?>"></i>
                                        </div>
                                        <div id="dateAccordionBody" style="display:<?php echo ($startDate || $endDate) ? 'block' : 'none'; ?>; padding:0 18px 14px 18px;">
                                            <div id="dateRangeTrigger" onclick="toggleDatePickerPopover(event)" style="display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0; border-radius:12px; padding:9px 12px; background:#ffffff; cursor:pointer; font-size:13px; color:#475569;">
                                                <div style="display:flex; align-items:center; gap:8px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                                    <i class="fa-regular fa-calendar" style="color:#94a3b8; font-size:14px;"></i>
                                                    <span id="dateRangeLabel"><?php echo ($startDate && $endDate) ? e($startDate . ' - ' . $endDate) : ($startDate ? e($startDate) : 'Pilih rentang tanggal'); ?></span>
                                                </div>
                                                <i class="fa-regular fa-circle-xmark" id="clearDateBtn" style="color:#cbd5e1; font-size:14px; cursor:pointer; <?php echo ($startDate || $endDate) ? 'display:inline;' : 'display:none;'; ?>" onclick="event.stopPropagation(); clearDateRange();"></i>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ACCORDION 2: WILAYAH / KOTA -->
                                    <div>
                                        <div onclick="toggleAccordion('city')" style="display:flex; justify-content:space-between; align-items:center; padding:14px 18px; cursor:pointer; user-select:none;">
                                            <span style="font-size:13.5px; font-weight:700; color:#0f172a;">Wilayah / Kota</span>
                                            <i class="fa-solid fa-chevron-down" id="cityChevron" style="font-size:11px; color:#64748b; transition:transform 0.2s; <?php echo $cityFilter ? 'transform:rotate(180deg);' : ''; ?>"></i>
                                        </div>
                                        <div id="cityAccordionBody" style="display:<?php echo $cityFilter ? 'block' : 'none'; ?>; padding:0 18px 14px 18px; position:relative;">
                                            <div id="citySelectTrigger" onclick="toggleCityDropdown(event)" style="display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0; border-radius:12px; padding:9px 12px; background:#ffffff; cursor:pointer; font-size:13px; color:#475569;">
                                                <span id="citySelectLabel" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo $cityFilter ? e($cityFilter) : 'Pilih kota...'; ?></span>
                                                <i class="fa-solid fa-chevron-down" style="color:#94a3b8; font-size:11px;"></i>
                                            </div>

                                            <!-- CITY SEARCHABLE DROPDOWN -->
                                            <div id="cityDropdownListCard" style="display:none; position:absolute; left:18px; right:18px; top:calc(100% + 4px); background:#ffffff; border:1px solid #00a8e8; border-radius:14px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.12); z-index:1005; padding:8px;">
                                                <input type="text" id="citySearchInput" onkeyup="filterCityOptions()" placeholder="Cari kota..." style="width:100%; border:1px solid #00a8e8; border-radius:10px; padding:8px 12px; font-size:13px; outline:none; margin-bottom:6px; box-sizing:border-box;">
                                                <div id="cityOptionsContainer" style="max-height:220px; overflow-y:auto;"></div>
                                            </div>
                                        </div>
                                    </div>

                                </div>

                                <!-- DUAL MONTH DATE RANGE PICKER POPOVER (SIBLING ANCHORED TO RIGHT) -->
                                <div id="datePickerPopover" onclick="event.stopPropagation();" style="display:none; position:absolute; right:0; top:calc(100% + 8px); width:540px; max-width:90vw; background:#ffffff; border:1px solid #e2e8f0; border-radius:16px; box-shadow:0 15px 35px -5px rgba(0,0,0,0.15); z-index:1010; padding:18px; box-sizing:border-box;">
                                    <!-- Header row with month/year navigation -->
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                                        <button type="button" onclick="prevMonthCluster()" style="background:none; border:none; cursor:pointer; padding:6px 10px; color:#475569; font-size:14px;"><i class="fa-solid fa-chevron-left"></i></button>

                                        <div style="display:flex; gap:24px; align-items:center;">
                                            <div style="display:flex; gap:6px;">
                                                <select id="m1Select" onchange="renderCalendar()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                                <select id="y1Select" onchange="renderCalendar()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                            </div>
                                            <div style="display:flex; gap:6px;">
                                                <select id="m2Select" onchange="renderCalendar()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                                <select id="y2Select" onchange="renderCalendar()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                            </div>
                                        </div>

                                        <button type="button" onclick="nextMonthCluster()" style="background:none; border:none; cursor:pointer; padding:6px 10px; color:#475569; font-size:14px;"><i class="fa-solid fa-chevron-right"></i></button>
                                    </div>

                                    <!-- Dual Month Grids -->
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:16px;">
                                        <!-- Month 1 Grid -->
                                        <div>
                                            <div style="display:grid; grid-template-columns:repeat(7, 1fr); text-align:center; font-size:12px; font-weight:600; color:#64748b; margin-bottom:8px;">
                                                <span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum</span><span>Sab</span><span>Min</span>
                                            </div>
                                            <div id="m1DaysGrid" style="display:grid; grid-template-columns:repeat(7, 1fr); gap:2px; text-align:center; font-size:12.5px;"></div>
                                        </div>
                                        <!-- Month 2 Grid -->
                                        <div>
                                            <div style="display:grid; grid-template-columns:repeat(7, 1fr); text-align:center; font-size:12px; font-weight:600; color:#64748b; margin-bottom:8px;">
                                                <span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum</span><span>Sab</span><span>Min</span>
                                            </div>
                                            <div id="m2DaysGrid" style="display:grid; grid-template-columns:repeat(7, 1fr); gap:2px; text-align:center; font-size:12.5px;"></div>
                                        </div>
                                    </div>

                                    <!-- Footer Buttons -->
                                    <div style="display:flex; flex-direction:column; gap:8px;">
                                        <button type="button" onclick="applyDatePickerSelection()" style="width:100%; background:#00a8e8; border:none; border-radius:10px; padding:10px; color:#ffffff; font-size:13.5px; font-weight:700; cursor:pointer;">Simpan</button>
                                        <button type="button" onclick="resetDatePickerSelection()" style="width:100%; background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:10px; color:#0f172a; font-size:13.5px; font-weight:700; cursor:pointer;">Reset</button>
                                    </div>
                                </div>
                            </div>
                        </form>

<script>
const CITY_MASTER = window.CITY_MASTER || [];
const MONTH_NAMES = window.MONTH_NAMES || ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agt", "Sep", "Okt", "Nov", "Des"];

let selectedStartDate = "<?php echo e($startDate); ?>";
let selectedEndDate = "<?php echo e($endDate); ?>";
let tempStartDate = selectedStartDate;
let tempEndDate = selectedEndDate;
let currentYear1 = 2026, currentMonth1 = 8;
let currentYear2 = 2026, currentMonth2 = 9;

if (selectedStartDate) {
    const parts = selectedStartDate.split('-');
    if (parts.length === 3) {
        currentYear1 = parseInt(parts[0]);
        currentMonth1 = parseInt(parts[1]) - 1;
        currentMonth2 = (currentMonth1 + 1) % 12;
        currentYear2 = currentMonth1 === 11 ? currentYear1 + 1 : currentYear1;
    }
}

function toggleFilterPopover(e) {
    if (e) e.stopPropagation();
    const popover = document.getElementById('filterPopover');
    if (popover.style.display === 'none' || !popover.style.display) {
        popover.style.display = 'block';
    } else {
        popover.style.display = 'none';
        document.getElementById('datePickerPopover').style.display = 'none';
        document.getElementById('cityDropdownListCard').style.display = 'none';
    }
}

function toggleAccordion(type) {
    if (type === 'date') {
        const body = document.getElementById('dateAccordionBody');
        const chevron = document.getElementById('dateChevron');
        if (body.style.display === 'none' || !body.style.display) {
            body.style.display = 'block';
            chevron.style.transform = 'rotate(180deg)';
        } else {
            body.style.display = 'none';
            chevron.style.transform = 'rotate(0deg)';
            document.getElementById('datePickerPopover').style.display = 'none';
        }
    } else if (type === 'city') {
        const body = document.getElementById('cityAccordionBody');
        const chevron = document.getElementById('cityChevron');
        if (body.style.display === 'none' || !body.style.display) {
            body.style.display = 'block';
            chevron.style.transform = 'rotate(180deg)';
            populateCityOptions();
        } else {
            body.style.display = 'none';
            chevron.style.transform = 'rotate(0deg)';
            document.getElementById('cityDropdownListCard').style.display = 'none';
        }
    }
}

function toggleDatePickerPopover(e) {
    if (e) e.stopPropagation();
    const picker = document.getElementById('datePickerPopover');
    if (picker.style.display === 'none' || !picker.style.display) {
        picker.style.display = 'block';
        initMonthYearSelects();
        renderCalendar();
    } else {
        picker.style.display = 'none';
    }
}

function toggleCityDropdown(e) {
    if (e) e.stopPropagation();
    const card = document.getElementById('cityDropdownListCard');
    if (card.style.display === 'none' || !card.style.display) {
        card.style.display = 'block';
        populateCityOptions();
        setTimeout(() => {
            const input = document.getElementById('citySearchInput');
            if (input) input.focus();
        }, 50);
    } else {
        card.style.display = 'none';
    }
}

function populateCityOptions(filter = '') {
    const container = document.getElementById('cityOptionsContainer');
    if (!container) return;
    container.innerHTML = '';

    const filterLower = filter.toLowerCase();
    const filtered = CITY_MASTER.filter(c => c.toLowerCase().includes(filterLower));

    if (filtered.length === 0) {
        container.innerHTML = '<div style="padding:10px; font-size:12.5px; color:#94a3b8; text-align:center;">Kota tidak ditemukan</div>';
        return;
    }

    filtered.forEach(city => {
        const item = document.createElement('div');
        item.style.cssText = 'padding:8px 12px; font-size:13px; color:#334155; cursor:pointer; border-radius:8px; font-weight:500;';
        item.textContent = city;
        item.onmouseover = () => item.style.background = '#f1f5f9';
        item.onmouseout = () => item.style.background = 'transparent';
        item.onclick = (e) => {
            e.stopPropagation();
            selectCity(city);
        };
        container.appendChild(item);
    });
}

function filterCityOptions() {
    const val = document.getElementById('citySearchInput').value;
    populateCityOptions(val);
}

function selectCity(cityName) {
    document.getElementById('inputCityFilter').value = cityName;
    document.getElementById('citySelectLabel').textContent = cityName;
    document.getElementById('cityDropdownListCard').style.display = 'none';
    document.getElementById('filterMainForm').submit();
}

function initMonthYearSelects() {
    const m1Sel = document.getElementById('m1Select');
    const m2Sel = document.getElementById('m2Select');
    const y1Sel = document.getElementById('y1Select');
    const y2Sel = document.getElementById('y2Select');

    if (!m1Sel || !m2Sel || !y1Sel || !y2Sel) return;

    m1Sel.innerHTML = MONTH_NAMES.map((m, i) => `<option value="${i}" ${i === currentMonth1 ? 'selected' : ''}>${m}</option>`).join('');
    m2Sel.innerHTML = MONTH_NAMES.map((m, i) => `<option value="${i}" ${i === currentMonth2 ? 'selected' : ''}>${m}</option>`).join('');

    const years = [2024, 2025, 2026, 2027];
    y1Sel.innerHTML = years.map(y => `<option value="${y}" ${y === currentYear1 ? 'selected' : ''}>${y}</option>`).join('');
    y2Sel.innerHTML = years.map(y => `<option value="${y}" ${y === currentYear2 ? 'selected' : ''}>${y}</option>`).join('');
}

function prevMonthCluster() {
    currentMonth1--;
    if (currentMonth1 < 0) { currentMonth1 = 11; currentYear1--; }
    currentMonth2 = (currentMonth1 + 1) % 12;
    currentYear2 = currentMonth1 === 11 ? currentYear1 + 1 : currentYear1;
    initMonthYearSelects();
    renderCalendar();
}

function nextMonthCluster() {
    currentMonth1++;
    if (currentMonth1 > 11) { currentMonth1 = 0; currentYear1++; }
    currentMonth2 = (currentMonth1 + 1) % 12;
    currentYear2 = currentMonth1 === 11 ? currentYear1 + 1 : currentYear1;
    initMonthYearSelects();
    renderCalendar();
}

function renderMonthGrid(gridId, year, month) {
    const grid = document.getElementById(gridId);
    if (!grid) return;
    grid.innerHTML = '';

    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const prevMonthDays = new Date(year, month, 0).getDate();

    const offset = (firstDay + 6) % 7;

    for (let i = offset - 1; i >= 0; i--) {
        const dayNum = prevMonthDays - i;
        const cell = document.createElement('div');
        cell.style.cssText = 'padding:6px 0; color:#cbd5e1; font-weight:500;';
        cell.textContent = dayNum;
        grid.appendChild(cell);
    }

    for (let d = 1; d <= daysInMonth; d++) {
        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const cell = document.createElement('div');

        let isSelected = false;
        let isInRange = false;

        if (tempStartDate && dateStr === tempStartDate) isSelected = true;
        if (tempEndDate && dateStr === tempEndDate) isSelected = true;
        if (tempStartDate && tempEndDate && dateStr > tempStartDate && dateStr < tempEndDate) isInRange = true;

        let bg = 'transparent';
        let color = '#334155';
        let borderRadius = '50%';
        let fontWeight = '500';

        if (isSelected) {
            bg = '#00a8e8';
            color = '#ffffff';
            fontWeight = '700';
        } else if (isInRange) {
            bg = '#e0f2fe';
            color = '#0284c7';
            borderRadius = '0';
        }

        cell.style.cssText = `padding:6px 0; background:${bg}; color:${color}; border-radius:${borderRadius}; font-weight:${fontWeight}; cursor:pointer; font-size:12.5px; transition:all 0.15s;`;
        cell.textContent = d;
        cell.onclick = (e) => {
            if (e) e.stopPropagation();
            selectDate(dateStr);
        };
        grid.appendChild(cell);
    }

    const totalCells = offset + daysInMonth;
    const remaining = (7 - (totalCells % 7)) % 7;
    for (let n = 1; n <= remaining; n++) {
        const cell = document.createElement('div');
        cell.style.cssText = 'padding:6px 0; color:#cbd5e1; font-weight:500;';
        cell.textContent = n;
        grid.appendChild(cell);
    }
}

function renderCalendar() {
    const m1Sel = document.getElementById('m1Select');
    const y1Sel = document.getElementById('y1Select');
    const m2Sel = document.getElementById('m2Select');
    const y2Sel = document.getElementById('y2Select');

    if (m1Sel && y1Sel && m2Sel && y2Sel) {
        currentMonth1 = parseInt(m1Sel.value);
        currentYear1 = parseInt(y1Sel.value);
        currentMonth2 = parseInt(m2Sel.value);
        currentYear2 = parseInt(y2Sel.value);
    }

    renderMonthGrid('m1DaysGrid', currentYear1, currentMonth1);
    renderMonthGrid('m2DaysGrid', currentYear2, currentMonth2);
}

function selectDate(dateStr) {
    if (!tempStartDate || (tempStartDate && tempEndDate)) {
        tempStartDate = dateStr;
        tempEndDate = '';
    } else if (tempStartDate && !tempEndDate) {
        if (dateStr >= tempStartDate) {
            tempEndDate = dateStr;
        } else {
            tempEndDate = tempStartDate;
            tempStartDate = dateStr;
        }
    }
    renderCalendar();
}

function applyDatePickerSelection() {
    selectedStartDate = tempStartDate;
    selectedEndDate = tempEndDate;
    document.getElementById('inputStartDate').value = selectedStartDate;
    document.getElementById('inputEndDate').value = selectedEndDate;

    if (selectedStartDate && selectedEndDate) {
        document.getElementById('dateRangeLabel').textContent = `${selectedStartDate} - ${selectedEndDate}`;
        document.getElementById('clearDateBtn').style.display = 'inline';
    } else if (selectedStartDate) {
        document.getElementById('dateRangeLabel').textContent = selectedStartDate;
        document.getElementById('clearDateBtn').style.display = 'inline';
    } else {
        document.getElementById('dateRangeLabel').textContent = 'Pilih rentang tanggal';
        document.getElementById('clearDateBtn').style.display = 'none';
    }

    document.getElementById('datePickerPopover').style.display = 'none';
    document.getElementById('filterMainForm').submit();
}

function resetDatePickerSelection() {
    tempStartDate = '';
    tempEndDate = '';
    selectedStartDate = '';
    selectedEndDate = '';
    document.getElementById('inputStartDate').value = '';
    document.getElementById('inputEndDate').value = '';
    document.getElementById('dateRangeLabel').textContent = 'Pilih rentang tanggal';
    document.getElementById('clearDateBtn').style.display = 'none';
    renderCalendar();
    document.getElementById('filterMainForm').submit();
}

function clearDateRange() {
    resetDatePickerSelection();
}

document.addEventListener('click', function(e) {
    // Individual Filter Popover
    const popover = document.getElementById('filterPopover');
    const filterBtn = document.getElementById('filterToggleBtn');
    const picker = document.getElementById('datePickerPopover');
    const cityCard = document.getElementById('cityDropdownListCard');

    const isInsidePopover = popover && popover.contains(e.target);
    const isInsideFilterBtn = filterBtn && filterBtn.contains(e.target);
    const isInsidePicker = picker && picker.contains(e.target);

    if (!isInsidePopover && !isInsideFilterBtn && !isInsidePicker) {
        if (popover) popover.style.display = 'none';
        if (picker) picker.style.display = 'none';
        if (cityCard) cityCard.style.display = 'none';
    }

    // Job Filter Popover
    const popoverJob = document.getElementById('filterPopoverJob');
    const filterBtnJob = document.getElementById('filterToggleBtnJob');
    const pickerJob = document.getElementById('datePickerPopoverJob');
    const cityCardJob = document.getElementById('cityDropdownListCardJob');

    const isInsidePopoverJob = popoverJob && popoverJob.contains(e.target);
    const isInsideFilterBtnJob = filterBtnJob && filterBtnJob.contains(e.target);
    const isInsidePickerJob = pickerJob && pickerJob.contains(e.target);

    if (!isInsidePopoverJob && !isInsideFilterBtnJob && !isInsidePickerJob) {
        if (popoverJob) popoverJob.style.display = 'none';
        if (pickerJob) pickerJob.style.display = 'none';
        if (cityCardJob) cityCardJob.style.display = 'none';
    }

    // Employer Filter Popover
    const popoverEmp = document.getElementById('filterPopoverEmp');
    const filterBtnEmp = document.getElementById('filterToggleBtnEmp');
    const pickerEmp = document.getElementById('datePickerPopoverEmp');
    const cityCardEmp = document.getElementById('cityDropdownListCardEmp');
    const verifierCardEmp = document.getElementById('verifierDropdownListCardEmp');
    const officerCardEmp = document.getElementById('officerDropdownListCardEmp');

    const isInsidePopoverEmp = popoverEmp && popoverEmp.contains(e.target);
    const isInsideFilterBtnEmp = filterBtnEmp && filterBtnEmp.contains(e.target);
    const isInsidePickerEmp = pickerEmp && pickerEmp.contains(e.target);

    if (!isInsidePopoverEmp && !isInsideFilterBtnEmp && !isInsidePickerEmp) {
        if (popoverEmp) popoverEmp.style.display = 'none';
        if (pickerEmp) pickerEmp.style.display = 'none';
        if (cityCardEmp) cityCardEmp.style.display = 'none';
        if (verifierCardEmp) verifierCardEmp.style.display = 'none';
        if (officerCardEmp) officerCardEmp.style.display = 'none';
    }
});
</script>
                    <div class="console-table-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; overflow-x:auto;">
                        <table class="console-table" style="width:100%; border-collapse:collapse; min-width:1000px;">
                            <thead>
                                <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0; text-align:left;">
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:160px;">
                                        <a href="admin.php?view=directory_individual&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo $sort === 'name_asc' ? 'name_desc' : 'name_asc'; ?><?php echo $filterParams; ?>" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                                            Nama <i class="fa-solid fa-arrows-up-down" style="font-size:11px; color:#94a3b8;"></i>
                                        </a>
                                    </th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:180px;">Email</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:130px;">Telepon</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:180px;">Alamat</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:160px;">Lokasi</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:130px;">Status</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:150px;">
                                        <a href="admin.php?view=directory_individual&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo $sort === 'date_desc' ? 'date_asc' : 'date_desc'; ?><?php echo $filterParams; ?>" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                                            Tanggal Daftar <i class="fa-solid fa-arrow-down" style="font-size:11px; color:#64748b;"></i>
                                        </a>
                                    </th>
                                    <th style="padding:14px 16px; width:130px; text-align:right;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$showingList): ?>
                                    <tr>
                                        <td colspan="8" style="text-align:center; padding:60px 20px; color:#64748b; font-size:13.5px;">
                                            Tidak ada data individual yang tersedia.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($showingList as $emp):
                                        $nameStr = $emp['owner_name'] ?: $emp['name'];
                                        $emailStr = $emp['email'];
                                        $phoneStr = $emp['phone'] ?: ($emp['whatsapp'] ?: '-');
                                        $addressStr = $emp['address'] ?: ($emp['address_detail'] ?: '-');

                                        $locArr = [];
                                        if (!empty($emp['city'])) $locArr[] = $emp['city'];
                                        if (!empty($emp['province'])) $locArr[] = $emp['province'];
                                        $locationStr = !empty($locArr) ? implode(', ', $locArr) : '-';

                                        $vStatus = $emp['verification_status'] ?? '';
                                        if ($vStatus === 'APPROVED') {
                                            $badgeHtml = '<span class="pill-badge verified">● Terverifikasi</span>';
                                        } elseif ($vStatus === 'NEEDS_REVISION') {
                                            $revNum = (int)($emp['revision_count'] ?? ($emp['rejection_count'] ?? 1));
                                            $revNum = max(1, min(3, $revNum));
                                            $badgeHtml = '<span class="pill-badge revision" style="background:#fef3c7; color:#d97706; border:1px solid #fde68a;">● Revisi Diminta (ke-' . $revNum . ')</span>';
                                        } elseif ($vStatus === 'REJECTED') {
                                            $badgeHtml = '<span class="pill-badge rejected">● Ditolak</span>';
                                        } else {
                                            $badgeHtml = '<span class="pill-badge pending">● Dalam Proses</span>';
                                        }

                                        $dateStr = date('d M Y, H:i', strtotime($emp['created_at']));
                                    ?>
                                        <tr style="border-bottom:1px solid #f1f5f9;">
                                            <td style="padding:14px 16px; font-weight:600; color:#0f172a; font-size:13px;"><?php echo e($nameStr); ?></td>
                                            <td style="padding:14px 16px; color:#334155; font-size:13px;"><?php echo e($emailStr); ?></td>
                                            <td style="padding:14px 16px; color:#334155; font-size:13px; white-space:nowrap;"><?php echo e($phoneStr); ?></td>
                                            <td style="padding:14px 16px; color:#334155; font-size:13px; max-width:240px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo e($addressStr); ?>"><?php echo e($addressStr); ?></td>
                                            <td style="padding:14px 16px; color:#334155; font-size:13px;"><?php echo e($locationStr); ?></td>
                                            <td style="padding:14px 16px; font-size:13px; white-space:nowrap;"><?php echo $badgeHtml; ?></td>
                                            <td style="padding:14px 16px; color:#64748b; font-size:12.5px; white-space:nowrap;"><?php echo e($dateStr); ?></td>
                                            <td style="padding:14px 16px; text-align:right; white-space:nowrap;">
                                                <a href="admin.php?view=directory_individual&detail_id=<?php echo $emp['user_id']; ?>" class="btn-lihat-detail" style="white-space:nowrap;">Lihat Detail</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <?php if ($showingList): ?>
                            <div class="console-table-footer" style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; font-size:13px; color:#64748b; border-top:1px solid #e2e8f0;">
                                <div>
                                    Menampilkan <?php echo $showingCount; ?> dari <?php echo $totalData; ?> total data.
                                </div>
                                <div style="display:flex; align-items:center; gap:4px;">
                                    <?php if ($page > 1): ?>
                                        <a href="admin.php?view=directory_individual&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?>&page=<?php echo $page - 1; ?><?php echo $filterParams; ?>" style="padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; border:1px solid #cbd5e1; font-weight:600;">‹</a>
                                    <?php else: ?>
                                        <span style="padding:6px 12px; border-radius:6px; color:#cbd5e1; border:1px solid #e2e8f0; font-weight:600; cursor:not-allowed;">‹</span>
                                    <?php endif; ?>

                                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                        <?php if ($p == $page): ?>
                                            <span style="padding:6px 12px; border-radius:6px; background:#0284c7; color:#fff; font-weight:700; border:1px solid #0284c7;"><?php echo $p; ?></span>
                                        <?php else: ?>
                                            <a href="admin.php?view=directory_individual&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?>&page=<?php echo $p; ?><?php echo $filterParams; ?>" style="padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; border:1px solid #cbd5e1; font-weight:600;"><?php echo $p; ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>

                                    <?php if ($page < $totalPages): ?>
                                        <a href="admin.php?view=directory_individual&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?>&page=<?php echo $page + 1; ?><?php echo $filterParams; ?>" style="padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; border:1px solid #cbd5e1; font-weight:600;">›</a>
                                    <?php else: ?>
                                        <span style="padding:6px 12px; border-radius:6px; color:#cbd5e1; border:1px solid #e2e8f0; font-weight:600; cursor:not-allowed;">›</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>              </div>

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
                <?php
                $pemeriksaMasterList = [
                    "A. Dimas, Se", "A. Fajar Wahyu", "A. RAHMAT FAJAR", "A.a. Putra Wirasanjaya", "ABD Halim", "ABD. WAHAB, S.Pd", "ABDUL BASYIR", "ABDUL HAMID TUASALAMONY", "ABDUL SALAM LAUMA, S.Sos", "ACHMAD RAJA NASUTION",
                    "ADE KURNIA GUNAWAN", "ADE SUDARSO, ST", "ADMARINA YESTI, SE", "ADRI RANTUNG", "ADY RAHMAT", "AFDHAL NAUFAL SOFYAN", "AFRIZAL, SE", "AGUNG BACHRI, S.H", "AGUNG PRIONO", "AGUNG SURYO NUGROHO",
                    "AGUS SALIM", "AGUS ZULIARDI, S.E", "AGUSMAN ZEBUA", "AHMAD GALIH DWICAHYA", "AHMAD IQBAL, S. Sos., M.M", "AHMAD JANI, ST", "AISAH DWI ANJANI", "AISYAH DARWIS", "AJI UTAMA, S.Psi.", "AKBAR, S.Sos, MM",
                    "ALFITRA AJI NUGROHO", "ALFRED J WORABAI", "AMALIA KHAIRIDA", "AMALYA RAMADINI SAFITRI", "AMIN SUSANTO, S.E., M.M.", "AMINAH", "AMIR SAMSURIJAL", "ANDI BATARI.S.Sos.MM", "ANDI LINDA", "ANDI SULHA RAHMAN",
                    "ANDIK TAMA", "ANDRI PRATAMA", "ANDY HAMDALILAH", "ANEKA SUPIAWATI, SE", "ANGGA DARA NUGRAHA, S.STP, M.Si", "ANGGA KUSUMA WARDANI", "ANGGER GEBYARING WASKITO, S.E.", "ANNISA", "ANNISA ULHIDAYAH DWIYANTI", "ANTOK DEKI TRIANTO",
                    "ANY NOORSIAH", "APRILIANA RAHMAWATI KURDANI", "ARDIANSYAH PUTRA HALOMOAN HARAHAP", "ARGHA YONATHAN SETYAWAN KUNCORO", "ARI SAPUTRA, SE, M.Si", "ARIEF RACHMAN", "ARIES DIAN KRISTANTO, S. Sos", "ARIF SUPRAPTO", "ASDHIN PAMA", "ASMURI.S.IP",
                    "ASTI MANAO", "ASTRID YUNIAR NURBAITY", "Aa Ahmad Riswandi", "Abdi Sasra Patriyandi", "Abdul Karim", "Abdul Aziz Halim, S.IP", "Abdul Bar Zimam Rajabi", "Abdul Halim Mantau", "Abdul Rohmat", "Achmad Amrullah Yoga Priyo Darmawan",
                    "Achmad Eko Prabowo", "Achmad Rizky Mauludi", "Ade Hendri", "Ade Masynta", "Ade Riswanto", "Ade Sudrajat, S.IP", "Adelia Sekar Apsari", "Adhi Nugroho", "Adi Hendarto", "Adi Supriadi",
                    "Adib Bahari S.H.,M.E.", "Adison Sebayang", "Aditya Aprian Suari", "Aditya Irza Pahany, SE", "Aditya Nugraha Putra", "Adriana Arrung", "Adriansyah, S.hi", "Adrianto Wibowo", "Afriani Pramawati", "Afrida Susanti",
                    "Afriyani, S.Sos", "Agata Hartati", "Agoestin Faridijani, Sh.", "Agung Setiawan", "Agus", "Agus Dyanto", "Agus Firmansyah", "Agus Hadi Saputra", "Agus Haerul Rizal", "Agus Isnanto",
                    "Agus Junaidi", "Agus Suherman, S.IP", "Agus Tomi, SE", "Agustina", "Agustini", "Agustiyardi, ST. M. Si", "Agustya Dewi Kharisma", "Ahadrin", "Ahmad Fatihin", "Ahmad Hafizy Anshari",
                    "Ahmad Irfanza", "Ahmad Rifani", "Ahmad Sadili", "Ahmad Taufan Taufani, S.H.", "Aji Mohd. Afriansyah", "Ajis Suleman", "Al Muttaqin, S.T.", "Alan Maulana", "Aldestia", "Aldhila Mahati",
                    "Aldo Weldia, SH", "Alen Pandeiroth", "Alfian Agusqurrohman, SE", "Alfius Kambu", "Alfridha Nahwiyar Azis", "Ali Hasan, S.Sos", "Alif Munandar", "Alimuddin", "Alka Christo Posawa, S.Sos", "Alpian Indra Gumilar, S. Psi",
                    "Alung Juanda, SE.", "Alvin Vigo Pratama", "Amanda Yulina Putri Rahayu", "Aminuddin", "Amrah Sakti", "Amrina Rosyida", "Anak Agung Eka Dharma Kusumawati", "Andi Dian Nata", "Andi Nurdiana", "Andi Setiandy",
                    "Andi mappasukki.S.sos", "Andini andantia sonya", "Andreas Satria Wicaksana", "Andrie Lesmana", "Andry Martin", "Anestesia", "Angga Lesmana", "Anggar Wahyu Hadiyatullah, S.Kom", "Anggi Novriadi", "Anih Purwanti",
                    "Anik Estiningsih, S.I.Kom.", "Anin Khoirunnisa'", "Anitaningsi. S.Sos., M.AP", "Anna Kurnianingsih,Stp,Mm", "Annisa Dewi Hajar Satiti", "Annisa Irdhania", "Annisa Nururrohmah", "Annisa Salsabila Aulia", "Anom Yusuf", "Antares Gita Kencana, S.Pd",
                    "Anton Amau Zegar", "Antonius Syaffriel L", "Anwar Laide", "Apit Yuri Pramono", "Apri Melda, S.E", "Apriawan", "Apridayani", "April Kusuma Dwi Riwayati", "Aprillia Ayu Restiani", "Ardhi Wardhani",
                    "Ardian Muhjid Permana", "Ardiansyah. R", "Arief Wana Subagja, S.I.P.", "Arif Susanto", "Arifa Kartikasari", "Arip Rohman", "Aris Budi Setiarso", "Aristrina Sugiyanti", "Arjani Kurniansyah", "Armegi Sidik, SE",
                    "Arne Saputra BBPVP Semarang", "Arofah Kurniawan", "Artaty Pasande Runtuk, S.T.", "Arum Dwi Rahayu, Sh", "Aryanti Dwiastuti", "Aryo Brotoseno N", "Asep Kurniawan, M.PD", "Asep Saepudin", "Asep Sukandar", "Asep juhara",
                    "Ashdiqo", "Asih Setiyorini", "Asnah Kristiani Utami", "Asnawi,SE.ME", "Asniwati Br Sembiring", "Aspriani Tinambunan", "Asrianti", "Astiti Lasalutu", "Astri Liswanti", "Atika Sangadji, SE",
                    "Atirah", "Aulia Oktaviani", "Aulia ikram pulungan", "Aulifah Rachmawati", "Ayu Anisah Jayanti, S.Pd.", "Ayu Dwi Putri", "Azis Fitrianto, S.Psi.", "Azizah, S.E", "BAKTIAR,S.Sos", "BAMBANG SUMIANTO",
                    "BARLI SAPUTRA UTAMA, SH", "BARNABAS AGUS HARSOWIDIGDDO.S.E,", "BATARA MANGGALA SIANTURI", "BENYAMIN BENY NUBA", "BETTI YUDHASTUTI RETNANINGRUM", "BISMAN", "BONI GILANG KHARISMA", "BRESMAN ANDEL SARAGIH", "BUDI SETIAWAN", "Bahri",
                    "Baidhawi", "Baikuni W A Pasaribu", "Balgis Alkatiri", "Bararatul Hasanah", "Basaria Silitonga", "Baskoro Putra Aditya", "Beata Vilkanova Seraphin Waja", "Bela Merdianingsih", "Beni Yuli santoso", "Benih Subagio",
                    "Berlian", "Berton Pasaribu", "Bety Ayu Juprianti", "Bezanolo Harefa", "Bhi Anggoro Anunghadi", "Bob Santoso Abadi", "Bobby Eka Syafutra", "Bonardo Amudinta Parluhutan Marpaung", "Bram Darussalam", "Brian Fatchur Rochman",
                    "Budi Hartono", "Budi Santoso", "Budi Syafputra", "Bukhari", "Bukhori, Sh", "Bulman Muda Sidi, ST", "CASWIN.S.AN", "CAYARANI SYARIF", "Cahya Yuly Artika", "Cahyadi Maulana S.M",
                    "Cahyaning Widhi", "Calvaryneke Hanna Wantania, SH., M.Si", "Candra Dewi Hasibuan", "Carlena, S.E", "Chaeri Razikin", "Chairuddin O Mona", "Chandra Joenoes", "Chandra Setia Eswanto", "Chandra Sihotang", "Chandra Sugara",
                    "Chelsia Rorintulus", "Chintya Chandra Adella", "Chris Yudho Sih Kurniawan", "Christian Reddy Wibisono", "Christianus Kia Da Gomes", "Christy Windy Lapod", "Cicilia Srihartanti,Se", "Cipto Saputra N", "Citra Anggraini", "Citra Anggriyani",
                    "Clara Theresia Sonia Seran", "Cokorda Gede Surya Putra Trisnu, SE", "Cris Kuntadi", "Cyntia Puspita Sari, S. IP", "D. Jumiati. A.s", "DADANG KOMARA", "DANANG DWI HANANTO", "DARWIS SIREGAR, S.E", "DAVID SETIAWAN, SE., M.Si", "DEBY TWARI JASSICA TANDY",
                    "DESI AYU NINGSIH", "DESI MARLINDA SARI, S.IP", "DEWI FITRIYANA, S.Sos.", "DEWI NOFIANI, S.Sos", "DEWI PARAS UTAMI", "DIAH RIZKY PARDANI JUNAEDI", "DIANA SAKURA, S.A.P", "DIANA SUSILOWATI", "DIDIT HARTOMO", "DODI",
                    "DONY TANJUNG MUTIARA", "DRA.HENY RULIANTI MASNAWI", "DUMARIA EVI MAWARTIKU PALAMARTA BR GULTOM", "DUS DUS", "DWI BUDI SETIONO", "DWI HARTATIK", "DWI HENDARTO", "DWI PUSPA AGUSTINA, SE.MM.", "DWI UTARTI", "Dadun Kohar",
                    "Dahliana Harahap", "Dandon Anggono Putro", "Danial El Amin, S.Hut., M.E.", "Daniel Seru, SE", "Dantia Mahanani", "Darmanto,", "Darsani Sahalem", "De Safari Natadikarya, S.Kom.,M.Si.", "Dea roza ayuningtias", "Deddy Agus Pranata Harefa",
                    "Deddy Danga Rantelino", "Deddy Wilistyan, SE, MAP", "Dede Andreas, S.IP", "Dedi Candra", "Dedy Cahyadi", "Dedy Harianto", "Dedy Kurniawan", "Dedy Maryadi", "Delina Asriyani", "Delisa",
                    "Dendy Indrawan Karno Putranto", "Deni Kustiawan", "Denny Agustiansyah", "Denok Utari", "Desi Cahyandari", "Desi Liyani", "Desi Nofiyati", "Desi Rano Sulle", "Desi pangalinan", "Desniati",
                    "Desnita Thamrin S. Sos", "Dessi Dewi Yani", "Desta Trinata Amalo", "Deta Pratiwi", "Devi Nurrahayu", "Devi virayati malangkase", "Dewi Andalusia", "Dewi Asdar Purwaningsih", "Dewi Eva Kiranti", "Dewi Patriasari Sutarya, SH",
                    "Dewi Puspasari", "Dewi Sartika", "Dhanu Indra Bhaswara", "Dhian Eka Sulistiawati", "Dhina Novita Rahmaulfa", "Dhiyah Moerdhaniyati", "Dhony Suherman Putra", "Diah Ayu Novitasari,S.Pd", "Dian Dewinta", "Dian Islamiyati",
                    "Dian Mardianah", "Dian Novi Yanti", "Dian Retnowati", "Diana Eka Damayanti", "Diana Puspita Dewi", "Diana Reni Ambarwati", "Diane Prisillya Thenu", "Dianita", "Dicky Surya Pradana", "Didi Musriadi",
                    "Dies Ekaprasetya Putra", "Diky Mochamad Ramdan", "Dina Hadiani Sadarwati", "Dina Nuraeni", "Dini Munawwaroh,S.tp", "Dinul Mu'arif", "Diogenes Gedion Thonak", "Dion Ruben Timotius", "Dita Fatmawati", "Djandjang Purwanti",
                    "Doddy Danan Jaya", "Dominggus Umbu Deta", "Donna Yurika Nasution", "Dra CATUR PANGESTUNINGSIH, M.Si", "Dra. Heni Maesaroh", "Dra. Hj. Nining Herlina, M.Si", "Drs Aris Wahyudi, M.si", "Drs. Edia, M.SI", "Drs. HERMANSYAH", "Drs. MUSTAFA",
                    "Dussel Sodup Pangon Banjar Nahor", "Dwi Bambang Susanto", "Dwi Bekti Faizal", "Dwi Dis Setiyawati", "Dwi Puspa Rini", "Dwi Septina Rahayu", "Dwi Setyo Aribowo", "Dwi Susanti", "EDI SUSANTO, S.E.", "EDI WINARKO",
                    "EDINA FITRIA RAHMAN, S.STP.MM", "EDY SUYONO", "EGA EDGARDA USMAN, SE", "EKA PRASETIA ZEBUA", "ELIS MULIA SUNDAWATI, S.Pd, SH", "ELLEN CRISTIE PATTINAMA, S.Pt", "ENDAH NOVITASARI, S.Sos", "ENDAH RUHANA", "ENDANG TRI HASTOTI", "ERVAN",
                    "ERWAN S.A.P", "ESNI YULITA", "EVENTIUS PATERNUS", "EVI SUSANTI, S.Pd, M.Si", "Edhie Catur Prayitno", "Edisson Cornelis Bali", "Edwin Edzuardy", "Edwin Nugraha", "Effendi", "Efriyeni",
                    "Eka Andri Yaksa", "Eka Angelieva S", "Eka Elvira", "Eka Fajar Juniar", "Eka Permatasari", "Eka Rosmiyati, SP", "Eka Yudha Sudrajad,", "Eka michelina", "Eki Kusumadewi", "Eko Darmanto",
                    "Eko Hardiyanto", "Eko Wijayatno", "Eko bayu nugraha A Tarsi", "Eko budi setyono", "Elena Fitriyani Tjaya", "Elfried Harteguh", "Elia Susanti Titin", "Eliosa br Pinem", "Ellanda Ollivia Lesa", "Elly Safitri",
                    "Elsa Rochito Subara", "Elvi Diana Putri, S.Psi", "Elviarita Yenti", "Elviyani", "Ema Prihatini", "Emildu Azhari", "Emilia El Yunusiyah", "Emma Yunita, S.Pd.", "Endah Juli Wulandari, S.AB.", "Endah Setiawati, S.sos",
                    "Endi Mardiansyah", "Endrawati", "Erma Yustiyah", "Ermalinda Lodja", "Ernawan", "Erni Haerani", "Ernij Christin Lase", "Erniza Puspita Ningsih", "Ervin Jongguran Marajohan", "Erwin Dodengo",
                    "Erwinda Nora", "Esmet Vahlevi Cantiago", "Essie Wineri", "Esti Rohana, S.Si", "Estie Susanti", "Etik Hendarti, S.p", "Etika alistyaningsih", "Eurica Firdha Ramandita", "Eva Celia Alberthina Homer", "Eva novalinda",
                    "Everlince Yarisetouw", "Eydet Rientje Siwabessy", "FACHRUL ROSYID", "FAHMEL TRIADI", "FAHMI SAPUTRA", "FAISAL AMRI TAMPUBOLON", "FANDIAJI", "FARIDA FARADIBA", "FARIDA HOTMA,SH,.M.Si", "FARIDHA",
                    "FAUZI RAHMAN HUTABARAT", "FEKOLIMA LASE", "FERAWATY A.K DUNGGA,SS.MH", "FERRI ANDRIADI", "FIAN ISMAYADI SUSILA,SE", "FIRDAUS", "FRAN DAROMES ALIDA", "Fadilah", "Fadli Hidayat Septriana", "Fadly Syahrial",
                    "Fahruddin", "Fahrur Rozi", "Faisal Amir", "Faisal Firman", "Faisarni Namudat", "Faizal Singgih, S.I.Kom.", "Fajar Alamsyah", "Fajar Prambudi Setyagraha", "Fajrin Amin", "Farida Yulika Artati",
                    "Fariskianto Hakiki", "Fariz Bagus Pradana", "Farizal Arif Prajanto", "Farningotan siahaan", "Fatma Ramadhini, SE, MSi.", "Fatmawati", "Fatmawati", "Fatmawaty Ahmad", "Fauzan Indra Kusumah", "Fazlurrahman, S.STP",
                    "Febriza Ihsan", "Feibry Timbowo", "Felix Faro Kameubun", "Fenty Usman", "Fernanda Yogaswara Tegar Wibowo", "Ferry Gunawan M Tampubolon", "Ferry Hamonangan", "Fetriana Lestary, ST", "Fida Suherna", "Fifi Zuniarti",
                    "Finsen Demianus Furay", "Finsensius Fererius Due", "Fiora. SH", "Firdaus Gulo", "Firdaus, S.Sos, M.M", "Firdausi Nuzula", "Firman Rengga Adi Nugroho", "Firmansyah", "Firmansyah", "Fitra Rizky Yosa",
                    "Fitrah aidin", "Fitri Astuti, S.psi", "Fitri Efendi", "Fitria Ratna Sari", "Fitria Sedjati", "Fitriani", "Fitriansyah Kurniawan", "Fitrya Faradevi", "Florensa Yenialiska Ndonalia", "Foresta Siswoharsono, S.H",
                    "Frans Laurensus Sinaga", "Fredy Harry Marthonis, S.Pt., M.Si", "Frendy Nurhadi Saputra", "Frengki Tiboyong", "Fresdia Febri Yenita", "Frisca Putri Prihandini", "Fritson Patty Damo, S.I.Kom", "Fuad Kurniawan, S.H",
                    "Fuad Ramadan, S.IP", "GITA INDAH PERMATA SARI", "GUSTI ZAINAL HASAN", "GUSTIAH, S.Sos", "Galih Agan Pambayun", "Galih Pratama, S.Psi", "Gangan Ganda Somantri M.pd", "Gede Wira Pradnya", "Gemal Pramana", "Gerson Yakob Warisal",
                    "Gesta Diniarti", "Gian Jauhari Ghofiqi", "Gilang Anggi Puspita Sari", "Gilang Ikhsanul Amri", "Ginanjar Budhi Utomo", "Gita Mahartini", "Gita Rahmatillah Apsari", "Glen Pietersz, SE", "Gogo Kurnia Butar Butar", "Gracia Yanida Rachmawati",
                    "Guldhian Syahputra", "Gun Gun Agung Gumilar", "Gus Gus Taofik Hidayat", "Gusti Ayu Komang Indrayanti,S. KOM", "H. EDDY IRSON, S.T, M.Si", "HADRAYANTI", "HAIRUL AZHARI", "HAJOPAN IRIANTO ARITONANG", "HAMDAN WIDAKDO", "HANDAYANI EXTANTA RIAWATI NINGSIH",
                    "HAPPY FANTRISLA LIOW", "HARMI, SH", "HARYANTI TANAI, S.IP", "HASNI B. IBRAHIM", "HASRIADY FAMSA", "HELPINA HT. S.Sos., M.Si", "HENDRA DARMAWAN,ST.,MT", "HENDRICA MATRONA UN", "HENDRIK LOKOLLO", "HENINGSIH, S.Sos.",
                    "HENY DIANE YUSNITA, S.T., M.M.", "HEPI RAHMAWATI", "HERLINA, SE", "HERONIMUS RUMYARU", "HERRY SUSANTO", "HETTI SETIAWATI MALAKA", "Hadida Samanery, SE", "Hafiz Ansyari, S.Psi", "Haikal", "Halimatun Sa'diah",
                    "Hamidun", "Hamsiah Yahya", "Hanna Noveria Lumban Batu", "Hardi Suprapto", "Hari Fitriana, SE", "Hari Setiawan, S. Stp", "Harimukti Surya Wirawan", "Hariyani Fitrianingsih", "Hariyanto, S AP", "Hariyati",
                    "Harmawansyah", "Harmono Nugroho", "Harry Haijiwada", "Hartanto,SE", "Hartantyo Wahyu Sardono, SH", "Harun Al Rasyid, S.Si.", "Hasia Paputungan", "Hasna Rusydiani", "Hasniati", "Hatimulhusna",
                    "Hedyana Mardatina", "Hendra Buranna", "Hendra Djuanda", "Hendri", "Hendri Febrian S.Kom", "Hendriana.B,SE", "Hendrianto", "Heni Iryaningsih", "Heni Mariati", "Heni Septinawati",
                    "Heni Susilowati", "Henni Fariha", "Henny Fadilla", "Henri permana", "Herawati", "Heria, S.Pd.I", "Herlan Santoso", "Herman Rubiyansah", "Hermon Iswandi", "Herry Supriatna",
                    "Heru Setiyanto", "Heruwibowo", "Herwin Jabir", "Herwin Setiawan, S.Sos", "Hesni yuningsih, S.Kom", "Hesti Agustini", "Hestin atas asih", "Hesty Wirayati Turan", "Hidayah Fiqih Utama", "Hidayat,S.I.P",
                    "Hisyam", "Hj. ALUSMAWATI, SE", "Hj. Daryati Ratna", "Hj. Hasnun Akmal", "Hj. Jumriati.S,SE,MM", "Hj. Mukarromah", "Husran", "I Gede Agus Sudaneyasa", "I Gede Ekananda Hartika", "I Gusti Ayu Diah Kurniasari",
                    "I Gusti Ayu Made Oktavia Utami Dewi", "I KETUT ADI NATHA, S.E, M.A.P", "I Ketut Ardana", "I Ketut Suartika", "I Komang Suardana, SH", "I Made Agus Wira Wijaya", "I Made Bambang Suliastono, SE.,MAP.", "I Made Dwi Etmo Cahyono Supraptha, S.H.", "I Made Ngurah Bangun Jayadi Putra", "I Made Reta",
                    "I PUTU SUBRATA", "I Putu Sumardika", "I gede kartanayasa", "I. Syafii", "ICHWAN HAFNI, ST, MM", "IKA SETIANINGRUM", "IKHWANTI ABDUL GANI", "ILDA SUTOPO,SP", "INA WIDIAWATI, S.H.", "INSYIRA SUBAGIA",
                    "INTAN KUSUMA WARDANI", "IRAWATI,SE", "IRENE SETYANINGRUM, S.IP., M.Si.", "IRSAD ADI LAZUARDI", "IRWAN ,SE", "IRWAN KURNIAWAN", "IRWAN PRIMA HARTAWAN", "IRWANDHANI", "IRWANTI, SH", "IRWIN SETIAWAN,SH.MM",
                    "ISHAK MAULANA", "ISKANDAR, S.Kom.,MM", "ISNAENI DE ANDREOTTI", "ISWADI", "ISWANDI,SKM", "ISWARADJATI", "IVAN SEPTIANTO. S.E.,M.M", "Ibnu Aulia Hanif", "Ibnu khaldun Sahabuddin, S.Sos", "Ichsan Singi, S.Sos",
                    "Ida Ayu Mirah Setiawati", "Ida Ayu Ratih Mayuni", "Ida Bagus Agung Andi Bhisma M.", "Ida Bagus Pidada Adi Putra", "Ida Sanjaya", "Idrus, S.Sos", "Idul Aguscik, S.H, M.M", "Iehsan tri kurnia", "Ihpan Siregar", "Ika Ardiyanto",
                    "Ikhrawan", "Ikmal Hananto", "Ilham Hadikusuma", "Ilham Ramadhan", "Ilham iskandar", "Imam Mu'aziz", "Imam Robani", "Iman Rajiman", "Imanuel Yohanes Lande", "Imas Masitoh",
                    "Imawan Sujianto", "Ina Rhomy", "Inas Azzahra", "Indah Ernawati", "Indah Kurnia Lestari", "Indah Sri Wahyuni", "Indah Tri Rahayu", "Indah joelianti", "Indarti", "Indra Sahputra",
                    "Indri Chevalia", "Intan Maria Rumantir Sinambela", "Intan Priyandini", "Ir. ARIF SOEDJANARTA, MM", "Ir. I Gusti Ayu Yuliari Ratrini", "Ira Ramadhani", "Irdha Yanti Musyawarah", "Irene Kusuma Palmarani", "Irfan Risnandi", "Irma Hendriyanti",
                    "Irma Widiastuti", "Irna asih astuti", "Isal Firdaus", "Iskandar", "Islahun Nihayah", "Ismartini", "Ismi Putri", "Isti Wasono S.Pt", "Iswady", "Ivan Valentino",
                    "Iwan Hendrawan", "Iwan Widiantoro", "Izza Islamiyah Putri Fayaliq", "JANIA Hi. UMAR", "JASRIL HAKIM, S,Pd", "JEANETTE PRICHILIA SEMEN, SE", "JIMMIE MANOVO", "JONI AFRIZAL, S.E", "JOSE SOARES REGO, S.Sos", "JUARIAH, S.IP",
                    "JUMADIN", "JUMIASTI RASMAN", "JUNAIDI", "JUNIAR TIGVA BORU", "JUNIATININGSIH S.A.P", "JUNITA FLORIN BUKIT, S.E", "JUNITA JETTY HANNA KUMOWAL", "JUWITA AMELIA DAULAY", "Jahrudin", "Jamila Wael",
                    "Jandra Jessy Pangemanan", "Janu Didik Santoso", "Jatu Aji Legowo", "Jean Rizal Wijanto", "Jefri Polembi", "Jens Rere", "Jerry Diwitau", "Jetro", "Johana C. Matau", "Joko Prihharjanto, S.Sos",
                    "Joni Malau", "Joni Palentek", "Jonisten Rajagukguk", "Jordanatha", "Jovan Ferdianto", "Juima Marthen", "Jujun Hidayat", "Jules Jaurat Sibarani", "Julius Andrea Juspongo", "Junaidi Amanda Pasaribu",
                    "Junaidin", "Juni Aryanto", "Junion Mirasoni Robinson Taga", "Jusman", "KASITA PUTRI YENITA", "KENDRA YUNIAWAN", "KURNIAWAN A. NURZAL, S.Sos", "KUSNANTO", "Kadek Puspita Ratnadewi", "Kadir,S.E",
                    "Kamalia Sutra Dewi,S.Psi.,M.A.P", "Kariza Dyah Yasmin", "Karjuna", "Karmila", "Karmila Ndajakapraingu", "Kartika Sari", "Kasman Karama", "Kencana Sari", "Ketut Wiratni", "Kevin Tovani",
                    "Khairina Syafitri", "Khairudin", "Khairunnisah, S.Psi", "Khamsiardi", "Khoirurijaluddin,S.E.", "Khony Wibowo", "Khorina Noviyanti", "Khosim Wongso Suratna", "Khusni", "Komang Eli Susanti",
                    "Komarudin", "Kris Wibowo", "Kristianus Sugandi Tampar", "Kumala Nindya Pramono", "Kumaya, SE", "Kurniasari", "Kurniati", "Kurrotul aini", "LAILA RAHMI, S.Pi", "LAILATUL MARHAMAH, A.Md.",
                    "LISTYO RAHAYU", "Lagowe", "Laila Dona Apriani, S.IP.", "Lalu Satria Utama", "Laode Rekesi", "Larasati Azizah Rahimi", "Lastri, S.I.Kom", "Lati Jannani", "Laviena Octora", "Lazuardi Okva Harindra",
                    "Lenggana Dewi", "Leni Muliana", "Leny Wulandari", "Leviana Agustin", "Liesna Prasetyorini", "Linda Manurung", "Linda Rosida", "Lindasari,Se", "Lindawati", "Lineke Kaeng",
                    "Lisa", "Lisa Erma Sumarni", "Lisbeth Limbong, SE", "Lisda Dhyniarti Bachtiar", "Lita Mariyani", "Louis Stefani Sriratu, SE, M.Si", "Lubis Polo", "Ludfi", "Luki Rani Ervita, S.S.", "Lukito",
                    "Lukman", "Luthfi Adi Setiawan", "Luthfi Hariyanto", "M Mustafa Sarinanto", "M ROSIHAN NUR ANWAR, S.E", "M RUSWIYANTO", "M Zaki Dzulfiqar Rosyadi", "M. FADLI SJAH", "M. Geraldi Prihandana", "M. Ichwan",
                    "M. Jaini", "M. Rizki Ramadhan", "M.SUBHAN, SE", "M.TAHARUDDIN", "M.satrio Pratomo", "MAEMANA, SE.", "MAHENDRI ARIMURTY", "MAIDAH .S.Sos", "MAR'ATUS SOLIKHAH", "MARAGANTI HASIBUAN",
                    "MARDIYANI", "MARETA FIFIAN DWI ROSANTI", "MARGARETHA, SE", "MARIA SARIYANTI HERAWATI TARMIN", "MARIA SOFI ARDINI", "MARISTHANI", "MARLEN AGUSTIN TAMPI", "MARMIN", "MARSIA INA RAWI, S.E", "MARWIYAH, S.AP",
                    "MASRITA J. DJ. MOHI", "MAT Shonif", "MATLA'UL ANWAR", "MAYA NURPAICA, SE", "MAYSKER WILIAMSON", "MELFIDYAN GENAKAMA", "MINA BOUTY", "MINARMI", "MIRANTI, ST", "MIRNA NUR ISTIQOMAH",
                    "MOEHAMMAD ZOECHRI TOBAMBA,ST", "MOH ZAINODDIN", "MOHAMMAD FAIDIL ANWARIE", "MONALISA", "MUHAMAD WIDHIARTO, S.Si., M.Hum", "MUHAMAD YUSUF", "MUHAMMAD FIKRUL ILMI, S.Pd.", "MUHAMMAD GAZALI SYAIDAR", "MUHAMMAD HAMKA, S.A.B", "MUHAMMAD NASIR, S.Sos",
                    "MUHAMMAD SHOBIRUR RIZQI", "MUHAMMAD SYAUFI IHZA", "MUHRODHI, S.Sos", "MULIADI, Sos., M.Si.", "MUSTAFA KAMAL, SE, M. Si", "Ma'sum Makkawaru", "Madien Hilalah", "Maghfirotun Nisa", "Mahlidar", "Maifendri",
                    "Maizar, Sh, Mh", "Majarani,SE.,MM", "Maman Fadhilah, SH", "Maman Lukman", "Manake Bambang Triawan", "Mardhatillah H. Polinelo", "Mardiana", "Mardiani SE", "Mardika Belapati", "Marhaeni",
                    "Marhawia", "Maria Angganitha De Lima", "Maria Efanggelina Fahik", "Maria Legiani", "Marianti Makalalag", "Mariedi Manto", "Marina Putri", "Marjuni", "Marlen Nirahua,SE", "Marliana Agus Mante",
                    "Marliana Wale Waton, S.Sos", "Marlina", "Marlinah", "Marni Hartati", "Maroeto Yoeli Setiawan", "Marselina Gadu", "Marta Meiliana Tiurmaida Patricia", "Martania Rizki Permatasari", "Martina Amarairu", "Martini Yahya",
                    "Maryam Karepesina", "Maryati", "Mashudi", "Masianna Pasaribu", "Mateos Maleta", "Maulidar", "Maulidma Muhammad", "Maulina Adelia Pratiwi", "Mawadah Dewi Apriyani", "Maya Nursanti , SE",
                    "Maya utari", "Medianto", "Meidi H.gunawan S.sos", "Meilanny Margaretha Sondakh", "Melani Fitra Rizkianty, SP, M.Si.", "Melati Putri Mose", "Melly Pebrianti", "Melrytio Junita Sitio", "Merliani", "Merry Wadu",
                    "Mersi Tangdilassu'", "Mery Yosepha Manik", "Meta Lara Pandini", "Mexon Maiman Purba S.Sos", "Meyrina Pronityastuti", "Mia Aulia", "Miafitri Damanik", "Mikhael Nikodemus Awi", "Mirayulita", "Mirda Datuela",
                    "Miriansyah", "Mirlie Lenggo Genie", "Mirnawaty Moo", "Mirra Desthari Thiodorra", "Misgianto", "Miss Herlina", "Mita Chairunnisa", "Moch. Yusuf Efendi", "Moch.Royyanudin Mafitri", "Mohamad Ridwan",
                    "Mohamad Syaiful Amin", "Mohammad Axel Runako", "Mohammad Ido Hendra Wijaya, S.Tr.P", "Mohammad Soko Marhendi", "Mohammad Toha Putra", "Mohammad fadly", "Mokhtar Kusuma Atmaja,Se", "Mona Kiranasih", "Mona Lisa Oktavia", "Muchsin Habib",
                    "Mugiyani", "Muh Auliyah Nur Yaqin", "Muh Bahri Ikbal", "Muh. Hariadi, S.S.T", "Muhamad Aliudin Rumra", "Muhamad Najmul Fikri", "Muhamad Rifqi Robbani", "Muhamad Said", "Muhamad Taufik", "Muhamad Yani",
                    "Muhamad Yasil Farabi", "Muhammad Adenin,St", "Muhammad Agus Ilmiawan", "Muhammad Ali Akbar", "Muhammad Dikhatama Yudha", "Muhammad Eka Darmawan, SE", "Muhammad Eric Cahyadi", "Muhammad Faizal", "Muhammad Farid Ardiansyah", "Muhammad Faridhon Sy. ST.,MT",
                    "Muhammad Faried Risky", "Muhammad Hafidz Alfikri", "Muhammad Hidayatullah", "Muhammad Iksan", "Muhammad Ivandry", "Muhammad Izhar", "Muhammad Kabul, S.Sos", "Muhammad Miftahul Khoir Rahmatullah, S.E", "Muhammad Muajib Ardiansah", "Muhammad Nafarin",
                    "Muhammad Nur Ihsan", "Muhammad Rinaldy Arif", "Muhammad Rizky Sembiring", "Muhammad Rofiq Kurnia", "Muhammad Rusydi, SH., MM", "Muhammad Sofian Husein", "Muhammad Taswin", "Muhammad Tio Fadillah", "Muhammad Toni Afriady", "Muhammad Wendy Danendra Pohan",
                    "Muhammad Zuhdi Kurniawan", "Muharyadi , S. Sos", "Muhmmed Khoreiza Qodliya", "Muhtadin Mustafa", "Mujiburahman Saputra", "Munawar", "Muri Kusmahana, S.Kom", "Murni Susianti,SE", "Mursalim S", "Murseto",
                    "Murwani F", "Muryati, S.Sos.", "Mus Saputra", "Musinah, SH", "Musliono", "Musni Bakar SH", "Mustafa Kamal", "Mustain, SE", "Mutia Astar", "Mutiara Tio Nora Simarmata",
                    "N Juliawati", "NAJIH NUR FAUZI", "NAPOLEON ENA, S.Sos", "NASIRUDDIN", "NELLIZA", "NI GUSTI AYU OKA PURNAWATI, SE", "NINDING KOSMANA", "NOORHAYANI", "NUNIK INDRAWATI", "NUNIK SUPARTINI",
                    "NUR ALIYAH", "NUR LAELA PATRIANI", "NUR'AINI,S.Sos", "NURAINY BARDJA", "NURDIN ANWAR, SE", "NURFADHILLAH ARDIYANI NASARU", "NURHEMI RITONGA", "NURMANDIKA BAYU IRAWAN", "NURUL HASANAH", "Nabella Intan Pertiwi",
                    "Nabilla Mei Larasati", "Nadian Tanora Mirzani", "Nadiatul Humairoh", "Nadya Noor Oktavia", "Nafisa Aulia Fahmi", "Nailul faroh", "Nani Aprizha", "Naomi Fitria Arja", "Naomi Sa'bi", "Nasrullah",
                    "Nasrullah, SE", "Natalia Ogolmagai", "Natalia isa", "Natya Adi Nugroho", "Nazaria Febiani", "Nazarudin Arif", "Neflianty Birlian", "Nelika", "Ni Kadek Ristawati", "Ni Luh Sudiani,SH",
                    "Ni Made Dwi Ari Susilawati", "Ni Made Sri Malini,SE", "Ni Wayan Surasmini", "Nia Kurnia", "Niken Candraningrum", "Nikhen Pratiwi Sekar Tanjung", "Nikira Desti Dewati", "Nindya Rachmayanti", "Nisa Arifiana", "Niswatul Rokhma",
                    "Nita Budi Astuti", "Nitya Dimas Anggara", "Nona Monika Sombolayuk", "Noor Aida Choirunnisa", "Noor Heldayanti, SE", "Nor Aisya Mahdha Heldina", "Novia Dwi Wanti", "Novia Elisabeth Putri Permatasari", "Novia Rosvita Sari", "Novinaristanty Zega",
                    "Novistasary", "Novrita Karo Karo, SH", "Nugraha Muharafandy", "Nugroho Wijoyo Kusumo", "Nungky Puri Astuti", "Nuni Rahayu, S.Sos", "Nunik Wahyu Rahmawati", "Nunuy Nursamsiah", "Nur Alvi", "Nur Andini, SE",
                    "Nur Fitri Anasari", "Nur Lailiah", "Nur Wahid Syafarli", "Nur Wahyuni", "Nur Widiastuti", "Nur Widiyaningsih", "Nuranti Eka Oktaviana", "Nurdi Arie wibowo", "Nurfatin Fiqgiya", "Nurhadi",
                    "Nurkasanah", "Nurkholis", "Nurmina manik", "Nurnismah", "Nursamsi", "Nurul Febiyanti", "Nurul Hasanah Tul Zannah", "Nurullaily", "Nurwiranto, SH", "Nuurin Izzati,S.Hum.", "Nyoman Arsiani",
                    "OLSJE JANS PIRING", "Octa Prindani", "Okfita Linda Anjasari", "Oki Nugraha", "Oki Oktafri Yatno", "Okta ariyani", "Oliva Yohana Weridity", "Orie Secunda Ayunitantry", "Ovi Utami", "PARIAMAN DAELI",
                    "PATRICK SERVANDA GROTIUS PARADIK", "PEDI", "PENITAWATI", "PONCO PRANOTO", "PRAWITA DEWI RIANINGRUM", "Pamelia Rahayu S.", "Pamungkas Setyo Utomo", "Panca Retnawati", "Pandu Isdiyanto, S.T., M.M.", "Patmi Sahroni, SP",
                    "Paulina Maria Songkares", "Pipin Sulistiyaningsih", "Pitriani", "Poni Eka Putra", "Praba Pancala Radya", "Pradina Fitri Maniku,A.Md.Kom", "Pramudhita Ayu Amalia", "Pramudianto, S.H.", "Prasetiyaning Tyas Ari Safitri", "Prasetya Wijayanto",
                    "Pratiwi Nurdiana", "Prince Alvin Yusuf", "Putri Anggraeni", "Putu Cindy Candra Dewi", "QUDRATULLAH AGAM, S.E", "R DEDY SANTOSO SULUS SE", "R Dani Guntara", "R Nurhidayat", "R. Bambang Dwi Minardi", "R. Deddy Dwiyudha Bakti",
                    "R. Elly Widianingsih,SE.,M.AP", "RAHDAT HARI", "RAHMAD DILAGA, SH", "RAHMAD HIDAYAT", "RAHMAD MULYADI", "RAHMAD SANDI,S.T", "RAHMAH,S.Pd", "RAHMAT AULIA", "RAMLAH S. LASORE", "RASYIDAN,S.Hut",
                    "RATNA PRATMAWATI", "REBY RAMBU RITI ROBU", "RENDRA PUTRA DINATA", "RENI KLEMENTIA LASE, SE", "RENIATI, S. Sos", "RENO SAHALA JHON PAULUS PURBA", "RESTU NORO", "RESTUTY", "RETNO ANDAYANI LESTARI", "RETNO WIDHI ASTUTI",
                    "REZA AFRIANSYAH", "REZA ARIFIN", "RIANA LYZA", "RIBKA MASIE NELCE MELLES", "RICKY IDAMAN SYARFI,SH.MH", "RIDHA ANSHARI", "RIDWAN ILHAMI", "RINATIN", "RIRI FERDINA", "RISNA ARIANI",
                    "RITA AGUSTINA", "RITA DAMAYANTI", "RITA FADHILAH", "RIZA PAHLEVI", "RIZALUDIN", "RIZKA MAHARDIKA", "ROBERT FRANKY ROOROH", "ROCKY PUTRA ANTAJA GULO, SM", "ROJALI, SE", "ROMDIYAH",
                    "RONI EKA PRASETIAWAN, S.E.", "RUDIANSYAH", "RUSLAN TARFIN, S.STP", "RUSMIATI", "Rachmat ginanjar", "Raden Roro Mur Oktaviani Swieta Wijayanti, Se", "Rafi'ah Defretes, SH", "Rafki Hadinarto", "Raga Sugih Pangestu", "Rahardian Aditya Maulana",
                    "Rahel", "Rahenda Ahmad Sanusi", "Rahma Dinda Valentine", "Rahma Fitriati", "Rahma Nurlita", "Rahmani", "Rahmat Juang Kusyari", "Rahmat Waluyo", "Rahmat Widodo", "Rahmayati Br Karo",
                    "Rahmi Fitria Asril", "Raisha pulukadang", "Raka Dwiman Hudiya", "Rama", "Rama Trijaya Kusuma", "Ramadanni", "Ramadhani", "Ramal Agus Risal, S.E.", "Ramos P. Siagian", "Rangga Yudistia,S.E.,M.Si.",
                    "Ranti Roezalia Sekti", "Rantini, S.E", "Rasniati", "Rasul", "Ratama Arifin Wibowo", "Ratih Indradiyati", "Ratri Nurinda Kusumawati", "Ratri Wiryani", "Redemta Krisanti Sumiati Laka", "Redie Sumantri, S.Kom",
                    "Refi Aprisanti", "Refti Betriesva", "Renata Agustina.,S.E", "Rencana Tarigan", "Renna Aprina", "Renny Wahyuni", "Restu Sucipa", "Retno Pangestuti Widianti", "Revita Permana", "Reynhard Hutapea",
                    "Reza Diki Nisyadin", "Rezky Maharani", "Ria Aggriani Dedtiama", "Rian Rizky", "Rianti Djafar,S.Sos", "Ribka Ambarwati, S.Pd.", "Richat simangunsong", "Richi Agung Ervanto", "Rici Ronaldo", "Ridhayani Aniray",
                    "Riduansyah", "Ridwan Arif Budiman", "Rijkaard Lasol", "Rika Kurniati", "Rika Nidiya Sari", "Rika Yustika", "Rikahasnita", "Riki Chosyikin", "Riko Ekaputra, S.E, MM", "Riko Tandean",
                    "Rina", "Rina Endra Astutik", "Rini Martiana", "Riny Karpanisa", "Rio Valentino", "Riri Melanie Rahayu", "Riri Widyawati", "Ririn Martini Rezki, ME", "Rivai Sindring", "Riyanto",
                    "Rizal Ardi", "Rizka Izzati", "Rizki Al Fahri", "Rizki Putri Anuari, S.I.Kom", "Rizki Rezza Fahlevi", "Rizkia Ramadhan, S.T", "Rizky Adhia Esprila", "Rizky Amalia Ulfa", "Rizqi Meiana Putri", "Rizqi Mutahara",
                    "Robertius", "Rochmad Effendi", "Rohendi", "Rohiyah", "Rohmah Ahdiyati", "Rohmat Syaefulloh", "Roi Munazir", "Roiyah Arakhman", "Romadi", "Ronald Rotu Ludji",
                    "Ronaldus Cika Perkasa", "Roose Spiegel Nebore, S.Psi", "Root User", "Rosda, SE., M.A.P", "Rosdiani", "Rosinta Girsang", "Rr. Dhiasty Mahayanti, ST", "Rr. KURNIASIH WILUJENG", "Ruben Calvin Wattimena, SH", "Rudi Hartono, S.IP",
                    "Rudolfus Kaliang Dendimara", "Ruly Amri", "Rumiyati", "Rusdianto", "Ruslim", "Rustam", "Rustanto", "Ruth Sherina Dama Yanti", "Rutiani Umar", "Ryan Surya Nadapdap",
                    "Ryas Cahya Annisa", "Rychad Luluk Kuncahyo", "SABAARO MENDROFA, S.Sos", "SAFRANS SABLIMAN ZEGA", "SALMAWATI", "SALWA NOOR AZIZAH", "SAMUEL TANDUNGAN", "SAMURNI", "SANG AYU KETUT SRI ARMONI", "SANUSI BARDENA SEMBIRING",
                    "SAPTARINI, SE", "SARI SARLITA, S.IP", "SAYFUL ALIWU,ST", "SEBASTIANUS FANULEN,SH", "SEFTI FITRIANI", "SELVARIUS RUDI CAHYANTO, S.E", "SELVINA", "SETIAMAN LASE", "SHIFA SALASIA AGUSTIANA", "SINTYA RATIKA SARI",
                    "SISKA NURDIN MOHI", "SITI AMINAH", "SITI HARMILLA, S.IP, M.Si", "SITI KHADIJAH KITTA, S.Psi., M.Hum", "SITI SYARIAH", "SOPAN", "SRI ISYAWATI", "SRI RAHAYU", "SRI WIDANARTI PAMUJI RAHAYU", "ST.SYAMSINAR.S.IP,.M.IP.",
                    "SUBRAN KARNI, S.SY", "SUGIYANTO", "SUHARDI, SE,MM", "SUKADI, SE", "SUKIRAH, SH., M.EC.DEV", "SUMARDAN, SE.", "SUNARTO", "SUPRIYONO, S.Sos, M.M", "SUSALMAWATI, WN", "SUSY ARMAYA TANJUNG",
                    "SUYANI", "SUZZANA MARIANA DURAND", "SYAHIDAH ASMA AMANINA", "SYANTY", "SYARIFAH SALMA", "Saepul Rohmat", "Safi'i", "Sagita Kusumawardani", "Sahna Putri Aselira", "Sakban simarmata",
                    "Sakinah, SE", "Sakri Warastrotomo", "Salman Abdurakhman", "Samsir firdaus", "Samsuardi", "Samsul", "Samsul Arief", "Samsyu", "Sandi Tampubolon", "Sandy Florantine Titis Martosudarmo, S.Psi",
                    "Sapani", "Saprianto", "Sapta Wulandari, Se", "Sarah Reza Maharani", "Sari Dhewi Saraswati", "Sarjono", "Savira Amanda", "Savitria Winariah", "Sawalman", "Sayani",
                    "Selly lintari", "Selma Almakiya", "Selva Mardinawaty, ST., M.M.", "Sentia Rapika, S.E", "Sentot Tasgunarto", "Senyorita Rosaliana Aronggear", "Septi Rahayu", "Septi Wulandari", "Septian Thahir", "Septiana Vista Dewi",
                    "Septiani Khaerunnisa", "Septiarni", "Serlina,SE,MM", "Seruni Adhinta Perdana", "Seta Satria Utama", "Setiawan", "Setiono Budi Sopiy", "Shadri saputra", "Shellomita Kusumawardhani", "Shelvy Susanti",
                    "Shely Marfuah", "Sheylla Aprisca Windiyani", "Shindi Istia Ratyadi", "Shofi Fajriah Ilmi", "Sie Gerrenil Yukgikhanta,", "Sigit Ary Prasetyo", "Simon", "Siti Arfah Husen", "Siti Chumakyah", "Siti Jubaidah",
                    "Siti Mutoharoh", "Siti Ratna", "Siti Yoanita Adrina", "Siti latifatul mahmudah", "Sitti Zarfina", "Slamet Budiono", "Slamet Riyadi", "Sofia Deslinda", "Sofia Ndao", "Sofiyanti Al Hasanah",
                    "Sofyan", "Sony maulana", "Sophia Adella", "Sri hartini", "Sri Anitia Fournia", "Sri Handayani", "Sri Hartanti", "Sri Juminarsih", "Sri Oktavianti Porosi, SH.MH", "Sri Rahayu",
                    "Sri Restanti", "Sri Wahyuni", "Srisiana bunsal", "Stefanie Cicilia zarosa", "Stefanus Richard Marcus", "Stenly Rambitan", "Subhan Syukri Daulay", "Suci Anggraeny Pasaribu, SST", "Suci Islamiya", "Suci Puji Lestari",
                    "Sudaryatno", "Sudiarto", "Sudiono", "Sudiryo", "Sugeng wiyono", "Sugiman, S.IP", "Sugiyanto", "Suhaimar Alfiandri", "Suharni Bondang", "Suhartoyo", "Suheri",
                    "Sujarwati", "Sukaria Tarigan", "Sukma dryandi", "Sukmawati Syafwa", "Sulaeman", "Sulistiowati", "Suliyanti", "Sumiati Njau", "Supiandi", "Supriyati",
                    "Suratimahda", "Suriani", "Surya Lukita Warman, M.Sc.", "Suryani Ningsi Bawiling", "Susanah", "Susantie", "Susiani, S.E", "Susilawati", "Sutri Dahlena, SKM.MSi", "Swari Hadiningsih",
                    "Syafri agus zulbahri", "Syafruddin, Se", "Syahril N. Hilumalo", "Syahrizal, SE,MM", "Syahromadoni", "Syamsir", "Syamsun Nur Syamsuddin", "Syaqmal Raditya Latarang", "Syarifuddin,S.E.", "Syifa Nurul Azizah",
                    "Syofik Maizola", "Syukriani, ST", "Syukrizal", "TITIK PURWANI, S.Sos.", "TRI HASTUTI HANDAYANI", "TRIO DORA WANDA MANANEKE", "TURWAPIT, S.IP", "Tamrin. T", "Tantriati", "Tatik Ika Mustika, S.IP, M.Si",
                    "Tedi Suryo Wibowo", "Teguh Eka Saputro", "Teja dahliawati", "Teni Tanzilal", "Tetty Sinambela", "Theressa Zaratrusha", "Thomas Andrean", "Thomas Bagas Wisnu Putra", "Tiara Ghaitsha Handayani", "Tiara Ramadhani",
                    "Timbul Tua Panggabean", "Tita herita", "Titan Pancawati", "Titi Nurul Hopipah", "Titiek nansriaty", "Titin Maryati", "Titis Sri Hartopo", "Toto Supriyanto", "Tresy Trinita", "Tri Darmawan Sambodho",
                    "Tri Martuti Rini Susanti", "Tri Wahyuning Hastuti", "Tri Windari", "Tumpak Boangmanalu", "Tuti Ismawati", "UNTARI MULYANINGSIH,S.E.", "USMADI", "USMAN, SH, M.Si", "Umar Abdul Syukur", "Umi Kadar Utami",
                    "Uriantono Triwibowo", "Uswah Delsia", "Uun Nurfitriana", "Uyun Fanny Fahraeni, ST.,M.M", "VIRGO ANGELA MARICE LAKE", "VIVI SULVIANTI.SE", "Veni Nur Agustin", "Verawaty Sambine", "Verdi J Pangaribuan", "Veronika susane paula wondal",
                    "Victoryado Shandez Joseph", "Vini Juliarini Putri", "Viona Azzahra", "Vivi Manisha", "WAHIDAH, A.Md", "WAHYU HARSIKIN", "WAHYUNI", "WAHYUNI J. L. PANGARIBUAN, S.E", "WALFRIK ZEBUA", "WIDARTY",
                    "WIDHI LESTARI OKTHARINA", "WINDY HIJJRIANTO ILHAMSYAH, S.Stat.", "WISDA NINGSIH SAFITRI", "Wa Ode Hesti Zuhaliman, S.M", "Wa Ode Sumiati Rusli", "Wahab Sugiarto", "Wahyu Efendy", "Wahyu Panca Pamungkas",
                    "Wahyu Sakti Tri Atmojo", "Wahyu Widiyanto", "Wahyu Yudowibisono", "Wahyuni Lasabuda", "Wahyuniati, S.Sos. M.Si", "Wardaniah Andi Paelori", "Wawan gunawan", "Wayan Ari Sude", "Wayan Sulatri", "Wega Nurhidayah",
                    "Weli Gustia Putri, SEI", "Wendy Burhannurdin", "Wenny Yuliang Prihatin,S Psi.", "Widiar Wahyudi", "Widiawati, Sh", "Widiyo Handono", "Widya Agsari Rallang", "Wijaya Kesuma", "Wijayanti Purnasari", "Wildani Syifaa",
                    "Windi Ahmad Hasyimi, S.Sos", "Windi Astuti", "Windi Triana Sari", "Windy Pradita Harma", "Winna Algustin", "Wiryawan", "Witri amelia", "Wiwin Asmianti", "Wiyono", "YAN PIETER RAUBABA",
                    "YENDRA YADI, S.T.,(ARCH). M.T", "YEYEN TAHIR PALLATJE", "YOESMARFIQ", "YOSI IRAWATI", "YUDHISTARI, SH", "YULIANA TITIARI", "YULISWAN ZN", "YULIUS KARETH", "YURNALIS TITRAWATI TORE, SE", "YUSRIWANTI",
                    "YUSTARI YUSUF", "Yahya Imansyah Girsang", "Yani Fitriyani, S.sos", "Yanita Uly Br Tarigan", "Yannear Al Reza", "Yanni Maria Cristianti Nahas", "Yanti febrini", "Yarmadanis", "Yasin R., S.E.", "Yen Zubriyani",
                    "Yenni Narulitha Anggraeni", "Yeny Rudianto", "Yeremias Andreas Amoye", "Yesi Elvi Cahyanti", "Yogautomo Budinugroho", "Yogi Wibowo", "Yogie Noor Hidayat", "Yohana Agustin Wijayantie, S.E.", "Yon Ersa Rewa", "Yonathan Tanna",
                    "Yoni Oktavia", "Yonita", "Yopi Saproni, SH", "Yori Dharta Wijaya", "Yoseph Moris Magang Sau", "Yova Krisma Hara", "Yovi Yuliana", "Yuda Hardika ,S.sos.", "Yudha Prasetya Maha Putra., SE", "Yudha. S",
                    "Yudik Hendri Hananto", "Yugita Putra Distriawan", "Yuli Mulyasari", "Yuli Sri Wardani", "Yulia Rani", "Yuliana Elu Nino", "Yuliana Ningsih, S. Pd", "Yuliani Safitri", "Yulianis", "Yulianti",
                    "Yulita Andiani", "Yuliyadi Christal Leo Taga Lele", "Yumna Basir", "Yungki Kantiana Taqwa", "Yuni Kurnia Putri", "Yuni Kurniawati", "Yuni Wulandari", "Yunian Prihatini", "Yuniarti, Se", "Yuningsih",
                    "Yunita Dwi Nuraeni, S.Pd", "Yunita Rahmawaty Utami", "Yurida Noerhania", "Yusep Hendarsyah", "Yussiwendi", "Yustina Diana Gama Putri", "Yusuf Ardabili", "Yusuf Fajra Maulia, S.H", "Yusuf Kurniawan", "Yusuf Mochamad",
                    "Yuyum Puspitaningrum", "ZAILLA NURFAZRIANI PONTO", "Zainah Afrianti", "Zainal Abidin", "Zamzam", "Zul Faizah", "Zulfian Hafni Nazar", "Zulfirayanti Abas", "Zulhendri", "afrizal",
                    "akhmad gaos", "akhmad gunawan", "aldi", "andiro Maleani", "arie widyotomo", "asrian darma saputra", "aw. budiansyah", "chairul saleh,SE,M.Si", "citro joyo trisno", "dani setiawan",
    "daniel s dethan", "de viviant", "dian kurniawati subardi, s.ip", "eli fitriani", "esy novialtri", "eta lestari tambunan", "eviyanti", "febrianto", "fitria rozalina, S.Sos", "fitriana wibawanti",
    "hariyono", "i dewa gede juniartana", "indra", "jana silniodi", "kristina royan", "lilis darojah", "luther ta'dung", "maha rani putri", "meria sari umar", "mochamad fajar sigit rahmanto",
    "mokhammad farid maruf", "muh irwan hasib, se", "muhammad aris aprianoor", "muhammad farid", "muhammad taufik,S.Ip", "mumsita iryani", "ni luh putu widyantari", "novita sari", "nyoto budhi astoro", "ovi mawaddah",
    "rahmah gustiha", "rahmi fauziah", "rodianti, S.Sos", "romy andi manik", "salmidawati", "sartono", "saurma rumiris", "sigma kusuma wijaya", "sukardi", "suryani",
    "suryati", "suryono", "susana margretha thei", "susi marini", "syamsul bahri", "syofian", "titiek suyatni wantogia", "vernny moriane sjultje soputan", "victor yuditara", "waode rosliani",
    "warliah", "widia apriyanti.S.STP.MM", "yakobus alex yocom", "yudha setyo nugroho", "zainal guzali"
                ];
                ?>
                <?php if ($selectedEmployer): ?>
                    <!-- DETAIL VIEW FOR VERIFIKASI PEMBERI KERJA -->
                    <div style="margin-bottom:16px;">
                        <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>" style="display:inline-flex; align-items:center; gap:6px; color:#475569; font-weight:600; font-size:13px; text-decoration:none;">
                            <i class="fa-solid fa-arrow-left"></i> Kembali
                        </a>
                    </div>

                    <!-- TOP HEADER BAR -->
                    <div class="detail-header-bar" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:20px 24px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center;">
                        <div style="display:flex; align-items:center; gap:16px;">
                            <div class="item-avatar-box" style="width:52px; height:52px; font-size:18px; border-radius:12px; background:#f1f5f9; color:#0f172a; display:flex; align-items:center; justify-content:center; font-weight:700;">
                                <?php echo strtoupper(substr($selectedEmployer['owner_name'] ?: $selectedEmployer['name'], 0, 2)); ?>
                            </div>
                            <div>
                                <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.5px; font-weight:700; color:#64748b; margin-bottom:4px;">
                                    DETAIL PENGAJUAN VERIFIKASI
                                </div>
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <h1 style="font-size:20px; font-weight:800; margin:0; color:#0f172a;"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></h1>
                                    <?php
                                        $status = $selectedEmployer['verification_status'] ?? 'PENDING';
                                        $isRevisionStatus = in_array(strtoupper($status), ['REVISION', 'NEEDS_REVISION']) || ($tab === 'revision');
                                        $isIndividual = strcasecmp((string)($selectedEmployer['entity_type'] ?? 'Individual'), 'Individual') === 0;
                                        $statusClass = 'pending';
                                        $statusLabel = 'Dikirim';
                                        $revNum = 1;
                                        if ($isRevisionStatus) {
                                            $statusClass = 'revision';
                                            $revNum = (int)($selectedEmployer['revision_count'] ?? ($selectedEmployer['rejection_count'] ?? 1));
                                            $revNum = max(1, min(3, $revNum));
                                            $statusLabel = "Revisi Diminta (ke-{$revNum})";
                                        } elseif ($status === 'APPROVED') {
                                            $statusClass = 'verified';
                                            $statusLabel = 'Terverifikasi';
                                        } elseif ($status === 'REJECTED') {
                                            $statusClass = 'danger';
                                            $statusLabel = 'Ditolak';
                                        } elseif ($status === 'PENDING') {
                                            $statusClass = 'pending';
                                            $statusLabel = 'Dikirim';
                                        }
                                        $isDinasFlow = ($isRevisionStatus && ($revNum >= 3 || in_array($selectedEmployer['manual_review_status'] ?? '', ['MANUAL_DINAS_REVIEW', 'CONSENT_PENDING', 'CONSENT_GIVEN', 'INVALID'])));
                                    ?>
                                    <span class="pill-badge <?php echo $statusClass; ?>" style="<?php echo $isRevisionStatus ? 'background:#fef3c7; color:#d97706; border:1px solid #fde68a;' : ''; ?>">
                                        ● <?php echo e($statusLabel); ?>
                                    </span>
                                </div>
                                <div style="font-size:12px; color:#64748b; margin-top:4px;">
                                    Slug: <code><?php echo strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $selectedEmployer['owner_name'] ?: $selectedEmployer['name'])); ?></code> &nbsp;|&nbsp;
                                    <strong><?php echo e($selectedEmployer['entity_type'] ?? 'Individual'); ?></strong> &nbsp;|&nbsp;
                                    Didaftarkan: <?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?> &nbsp;|&nbsp;
                                    <?php echo e($selectedEmployer['city'] ?: 'Dalung, Kuta Utara, Kab. Badung, Bali'); ?>
                                </div>
                            </div>
                        </div>

                        <div>
                            <?php if ($isDinasFlow): ?>
                                <button type="button" onclick="const sec = document.getElementById('sectionManualDinasEdit'); if (sec) { sec.scrollIntoView({behavior:'smooth'}); const dt = sec.querySelector('details'); if (dt) dt.open = true; }" style="display:inline-flex; align-items:center; gap:8px; background:#0284c7; border:none; border-radius:999px; padding:9px 20px; font-size:13px; font-weight:700; color:#ffffff; cursor:pointer; box-shadow:0 2px 6px rgba(2,132,199,0.25);">
                                    <i class="fa-solid fa-pen-to-square"></i> Ajukan Permohonan Ulang
                                </button>
                            <?php elseif (!$isRevisionStatus && $status !== 'APPROVED'): ?>
                                <button type="button" data-open-modal="modal-assign-pemeriksa" style="display:inline-flex; align-items:center; gap:8px; background:#ffffff; border:1px solid #00a8e8; border-radius:999px; padding:8px 18px; font-size:13px; font-weight:700; color:#0284c7; cursor:pointer; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                                    <i class="fa-solid fa-arrows-rotate" style="color:#00a8e8;"></i> Ambil Pengajuan
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="detail-grid-container" style="display:grid; grid-template-columns: 1fr 340px; gap:20px; align-items:start;">
                        <!-- LEFT COLUMN: MAIN VERIFICATION CONTENT -->
                        <div style="display:flex; flex-direction:column; gap:20px;">

                            <!-- CARD 1: RINGKASAN PENGAJUAN -->
                            <div class="section-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:20px;">
                                <div class="section-card-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:16px;">Ringkasan Pengajuan</div>
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px 24px; font-size:13px;">
                                    <div>
                                        <div style="color:#64748b; margin-bottom:4px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-database" style="color:#00a8e8; font-size:12px;"></i> Sumber Data
                                        </div>
                                        <div style="font-weight:700; color:#0f172a;">Registrasi Platform</div>
                                    </div>
                                    <div>
                                        <div style="color:#64748b; margin-bottom:4px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-regular fa-calendar" style="color:#d97706; font-size:12px;"></i> Tanggal Pengajuan
                                        </div>
                                        <div style="font-weight:700; color:#0f172a;"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                    </div>
                                    <div>
                                        <div style="color:#64748b; margin-bottom:4px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-shapes" style="color:#e11d48; font-size:12px;"></i> Tipe
                                        </div>
                                        <div style="font-weight:700; color:#0f172a;"><?php echo e($selectedEmployer['entity_type'] ?? 'Individual'); ?></div>
                                    </div>
                                    <div>
                                        <div style="color:#64748b; margin-bottom:4px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-regular fa-clock" style="color:#e11d48; font-size:12px;"></i> Deadline
                                        </div>
                                        <div style="font-weight:700; color:#0f172a;">-</div>
                                    </div>
                                    <div style="grid-column: span 2;">
                                        <div style="color:#64748b; margin-bottom:4px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-location-dot" style="color:#00a8e8; font-size:12px;"></i> Wilayah
                                        </div>
                                        <div style="font-weight:700; color:#0f172a;"><?php echo e($selectedEmployer['city'] ?: 'Dalung, Kuta Utara, Kab. Badung, Bali'); ?><?php echo !empty($selectedEmployer['province']) ? ', ' . e($selectedEmployer['province']) : ''; ?></div>
                                    </div>
                                </div>
                            </div>

                            <!-- CARD 2: INFORMASI PENUGASAN DAN VERIFIKATOR -->
                            <div class="section-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:20px;">
                                <div class="section-card-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:16px;">Informasi Penugasan dan Verifikator</div>
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px 24px; font-size:13px;">
                                    <div>
                                        <div style="color:#64748b; margin-bottom:4px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-user-check" style="color:#00a8e8; font-size:12px;"></i> Pemeriksa
                                        </div>
                                        <div style="font-weight:700; color:#0f172a;"><?php echo e($selectedEmployer['assigned_to'] ?: 'Belum ditugaskan'); ?></div>
                                    </div>
                                    <div>
                                        <div style="color:#64748b; margin-bottom:4px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-shield-halved" style="color:#10b981; font-size:12px;"></i> Status Penugasan
                                        </div>
                                        <div style="font-weight:700; color:#0f172a;">
                                            <?php if (!empty($selectedEmployer['assigned_to'])): ?>
                                                <span class="pill-badge assigned">● Ditugaskan</span>
                                            <?php else: ?>
                                                <span style="color:#94a3b8;">-</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div style="grid-column: span 2;">
                                        <div style="color:#64748b; margin-bottom:4px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-regular fa-calendar-check" style="color:#d97706; font-size:12px;"></i> Ditugaskan Pada
                                        </div>
                                        <div style="font-weight:700; color:#0f172a;"><?php echo !empty($selectedEmployer['assigned_at']) ? date('d M Y, H:i', strtotime($selectedEmployer['assigned_at'])) : '-'; ?></div>
                                    </div>
                                </div>
                            </div>

                            <!-- CARD 3: DATA PROFIL TABLE (INDIVIDUAL VS PERUSAHAAN/OSS) -->
                            <?php if (strcasecmp((string)($selectedEmployer['entity_type'] ?? 'Individual'), 'Perusahaan') === 0): ?>
                                <!-- TABLE FOR PERUSAHAAN (PERBANDINGAN DATA OSS) -->
                                <div class="section-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:20px;">
                                    <div class="section-card-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:4px;">Perbandingan Data Pemberi Kerja dan OSS</div>
                                    <p style="font-size:12px; color:#64748b; margin:0 0 14px 0;">Data OSS diambil otomatis berdasarkan NIB perusahaan.</p>
                                    <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:10px 14px; font-size:12.5px; color:#991b1b; margin-bottom:14px;">
                                        Data pemberi kerja ini tidak dapat ditemukan karena NIB tidak terdaftar di WUP.
                                    </div>
                                    <div style="overflow-x:auto;">
                                        <table class="compare-table" style="width:100%; border-collapse:collapse; font-size:13px;">
                                            <thead>
                                                <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0; text-align:left; color:#475569;">
                                                    <th style="padding:10px 14px; font-weight:700;">Variabel</th>
                                                    <th style="padding:10px 14px; font-weight:700;">Data Pemberi Kerja</th>
                                                    <th style="padding:10px 14px; font-weight:700;">Data OSS</th>
                                                    <th style="padding:10px 14px; font-weight:700;">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600;">Nama Pemberi Kerja</td><td style="padding:10px 14px; font-weight:600; color:#0f172a;"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></td><td style="padding:10px 14px; color:#94a3b8;">-</td><td><span class="pill-badge danger" style="font-size:11px; padding:2px 8px;">Tidak Ditemukan</span></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600;">NIB</td><td style="padding:10px 14px; color:#0f172a;">-</td><td style="padding:10px 14px; color:#94a3b8;">-</td><td><span class="pill-badge danger" style="font-size:11px; padding:2px 8px;">Tidak Ditemukan</span></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600;">Email</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['email']); ?></td><td style="padding:10px 14px; color:#94a3b8;">-</td><td><span class="pill-badge danger" style="font-size:11px; padding:2px 8px;">Tidak Ditemukan</span></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600;">Telepon</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['phone']); ?></td><td style="padding:10px 14px; color:#94a3b8;">-</td><td><span class="pill-badge danger" style="font-size:11px; padding:2px 8px;">Tidak Ditemukan</span></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600;">Alamat</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['address'] ?: '-'); ?></td><td style="padding:10px 14px; color:#94a3b8;">-</td><td><span class="pill-badge danger" style="font-size:11px; padding:2px 8px;">Tidak Ditemukan</span></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600;">Provinsi</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['province'] ?: '-'); ?></td><td style="padding:10px 14px; color:#94a3b8;">-</td><td><span class="pill-badge danger" style="font-size:11px; padding:2px 8px;">Tidak Ditemukan</span></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600;">Kota/Kabupaten</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['city'] ?: '-'); ?></td><td style="padding:10px 14px; color:#94a3b8;">-</td><td><span class="pill-badge danger" style="font-size:11px; padding:2px 8px;">Tidak Ditemukan</span></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600;">Kode Pos</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['postal_code'] ?: '-'); ?></td><td style="padding:10px 14px; color:#94a3b8;">-</td><td><span class="pill-badge danger" style="font-size:11px; padding:2px 8px;">Tidak Ditemukan</span></td></tr>
                                                <tr><td style="padding:10px 14px; font-weight:600;">Deskripsi</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['description'] ?: '-'); ?></td><td style="padding:10px 14px; color:#94a3b8;">-</td><td><span class="pill-badge danger" style="font-size:11px; padding:2px 8px;">Tidak Ditemukan</span></td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php else: ?>
                                <!-- TABLE FOR INDIVIDUAL (DATA PROFIL PEMBERI KERJA INDIVIDU) -->
                                <div class="section-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:20px;">
                                    <div class="section-card-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:16px;">Data Profil Pemberi Kerja Individu</div>
                                    <div style="overflow-x:auto;">
                                        <table class="compare-table" style="width:100%; border-collapse:collapse; font-size:13px;">
                                            <thead>
                                                <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0; text-align:left; color:#475569;">
                                                    <th style="padding:10px 14px; font-weight:700; width:260px;">Variabel</th>
                                                    <th style="padding:10px 14px; font-weight:700;">Data Pemberi Kerja Individu</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">Nama Pemberi Kerja</td><td style="padding:10px 14px; font-weight:600; color:#0f172a;"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">NIK</td><td style="padding:10px 14px; color:#0f172a;"><code><?php echo e($selectedEmployer['nik'] ?: '3273xxxxxxxxxxxx'); ?></code></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">Email</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['email'] ?: 'ahmad@email.com'); ?></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">Telepon</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['phone'] ?: '0812xxxxxxxx'); ?></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">WhatsApp</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['whatsapp'] ?: '0812xxxxxxxx'); ?></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">Jenis Profesi / Usaha Individu</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['profession'] ?: ($selectedEmployer['description'] ?: 'Jasa Desain Grafis')); ?></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">NPWP</td><td style="padding:10px 14px; color:#0f172a;"><code><?php echo e($selectedEmployer['npwp'] ?: '12.345.678.9-123.000'); ?></code></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">Sosial Media</td><td style="padding:10px 14px; color:#0f172a;">Instagram: @<?php echo e(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $selectedEmployer['owner_name'] ?: $selectedEmployer['name']))); ?></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">Lokasi Domisili</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['city'] ?: 'Dago, Coblong, Kota Bandung, Jawa Barat'); ?></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">Alamat Lengkap</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['address'] ?: 'Jl. Ir. H. Juanda No. 25'); ?></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">Detail Alamat / Patokan</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['address_detail'] ?: 'Dekat persimpangan utama'); ?></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">Kode Pos</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['postal_code'] ?: '40135'); ?></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">Deskripsi Singkat Usaha / Rekrutmen</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['description'] ?: 'Usaha jasa desain grafis dan digital'); ?></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;">
                                                    <td style="padding:10px 14px; font-weight:600; color:#334155;">Dokumen Pendukung</td>
                                                    <td style="padding:10px 14px; color:#0f172a;">
                                                        <div style="display:flex; align-items:center; gap:10px;">
                                                            <span>dokumen-usaha.pdf</span>
                                                            <button type="button" data-open-modal="modal-view-doc" style="background:#f1f5f9; border:1px solid #cbd5e1; border-radius:6px; padding:4px 10px; color:#0284c7; font-weight:700; font-size:12px; cursor:pointer; display:inline-flex; align-items:center; gap:5px;">
                                                                <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:11px;"></i> Lihat Dokumen
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td style="padding:10px 14px; font-weight:600; color:#334155;">Foto Bukti Tempat Usaha / Lokasi</td>
                                                    <td style="padding:10px 14px; color:#0f172a;">
                                                        <div style="display:flex; align-items:center; gap:10px;">
                                                            <span>2 Foto</span>
                                                            <button type="button" data-open-modal="modal-view-photos" style="background:#f1f5f9; border:1px solid #cbd5e1; border-radius:6px; padding:4px 10px; color:#0284c7; font-weight:700; font-size:12px; cursor:pointer; display:inline-flex; align-items:center; gap:5px;">
                                                                <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:11px;"></i> Lihat Foto
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- CARD: HASIL VERIFIKASI (IF REVISI DIMINTA / PROCESSED) -->
                            <?php if ($isRevisionStatus): ?>
                                <div class="section-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:20px;">
                                    <div class="section-card-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:16px;">Hasil Verifikasi</div>
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px 20px; font-size:13px; margin-bottom:16px;">
                                        <div>
                                            <div style="color:#64748b; margin-bottom:4px; font-weight:500;">Status Keputusan</div>
                                            <div>
                                                <span style="background:#fffbeb; color:#b45309; border:1px solid #fde68a; font-weight:700; font-size:12px; padding:4px 10px; border-radius:12px; display:inline-flex; align-items:center; gap:6px;">
                                                    <span style="width:7px; height:7px; border-radius:50%; background:#f59e0b;"></span> Revisi Diminta (Revisi ke-<?php echo e($selectedEmployer['revision_count'] ?? 1); ?>)
                                                </span>
                                            </div>
                                        </div>
                                        <div>
                                            <div style="color:#64748b; margin-bottom:4px; font-weight:500;">Tanggal Keputusan</div>
                                            <div style="font-weight:600; color:#0f172a;"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['updated_at'] ?? '2026-09-24 09:18')); ?></div>
                                        </div>
                                        <div style="grid-column: span 2;">
                                            <div style="color:#64748b; margin-bottom:4px; font-weight:500;">Nama Pemeriksa</div>
                                            <div style="font-weight:700; color:#0f172a;"><?php echo e($selectedEmployer['assigned_to'] ?: 'Budi Santoso'); ?></div>
                                        </div>
                                    </div>
                                    <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:14px;">
                                        <div style="font-size:12.5px; font-weight:700; color:#92400e; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-circle-exclamation"></i> Catatan Perbaikan
                                        </div>
                                        <div style="font-size:13px; color:#78350f; line-height:1.5;">
                                            <?php echo e($selectedEmployer['verifier_notes'] ?: 'Mohon perbaiki dan lengkapi foto tempat usaha serta sesuaikan dokumen identitas pendukung dengan alamat domisili terbaru.'); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- CARD 4: INFORMASI PEMBERI KERJA DETAIL & MAP -->
                            <div class="section-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:20px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                                    <div class="section-card-title" style="font-size:15px; font-weight:800; color:#0f172a; margin:0;">Informasi Pemberi Kerja Individu</div>
                                    <a href="#" onclick="event.preventDefault();" style="color:#00a8e8; text-decoration:none; font-size:12.5px; font-weight:600; display:inline-flex; align-items:center; gap:4px;">
                                        <i class="fa-solid fa-arrow-up-right-from-square"></i> Lihat Detail
                                    </a>
                                </div>

                                <div style="margin-bottom:14px;">
                                    <div style="font-size:16px; font-weight:800; color:#0f172a;"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></div>
                                    <div style="font-size:12.5px; color:#64748b; margin-top:2px; font-weight:500;"><?php echo e($isIndividual ? 'Individual' : ($selectedEmployer['entity_type'] ?? 'Perusahaan')); ?></div>
                                </div>

                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px 20px; font-size:13px; margin-bottom:16px;">
                                    <div>
                                        <div style="color:#64748b; margin-bottom:2px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-location-dot" style="color:#94a3b8;"></i> Wilayah
                                        </div>
                                        <div style="font-weight:600; color:#0f172a;"><?php echo e($selectedEmployer['city'] ?: 'Dago, Coblong, Kota Bandung, Jawa Barat'); ?></div>
                                    </div>
                                    <div>
                                        <div style="color:#64748b; margin-bottom:2px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-regular fa-calendar" style="color:#94a3b8;"></i> Tanggal Daftar
                                        </div>
                                        <div style="font-weight:600; color:#0f172a;"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                    </div>
                                    <div>
                                        <div style="color:#64748b; margin-bottom:2px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-regular fa-envelope" style="color:#94a3b8;"></i> Email
                                        </div>
                                        <div style="font-weight:600; color:#00a8e8;"><?php echo e($selectedEmployer['email'] ?: 'ahmad@email.com'); ?></div>
                                    </div>
                                    <div>
                                        <div style="color:#64748b; margin-bottom:2px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-phone" style="color:#94a3b8;"></i> Telepon
                                        </div>
                                        <div style="font-weight:600; color:#0f172a;"><?php echo e($selectedEmployer['phone'] ?: '0812xxxxxxxx'); ?></div>
                                    </div>
                                    <div>
                                        <div style="color:#64748b; margin-bottom:2px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-brands fa-instagram" style="color:#94a3b8;"></i> Sosial Media
                                        </div>
                                        <div style="font-weight:600; color:#0f172a;">Instagram: @<?php echo e(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $selectedEmployer['owner_name'] ?: $selectedEmployer['name']))); ?></div>
                                    </div>
                                    <div>
                                        <div style="color:#64748b; margin-bottom:2px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-brands fa-whatsapp" style="color:#94a3b8;"></i> WhatsApp
                                        </div>
                                        <div style="font-weight:600; color:#0f172a;"><?php echo e($selectedEmployer['whatsapp'] ?: '0812xxxxxxxx'); ?></div>
                                    </div>
                                    <div style="grid-column: span 2;">
                                        <div style="color:#64748b; margin-bottom:2px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-house" style="color:#94a3b8;"></i> Alamat Lengkap
                                        </div>
                                        <div style="font-weight:600; color:#0f172a;"><?php echo e($selectedEmployer['address'] ?: 'Jl. Ir. H. Juanda No. 25'); ?></div>
                                        <div style="font-size:12px; color:#64748b; margin-top:2px;">Kode Pos: <?php echo e($selectedEmployer['postal_code'] ?: '40135'); ?></div>
                                    </div>
                                </div>

                                <!-- MAP PREVIEW BOX -->
                                <div style="border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; margin-bottom:16px; background:#e0f2fe; height:180px; position:relative; display:flex; align-items:center; justify-content:center;">
                                    <iframe width="100%" height="180" frameborder="0" style="border:0;" src="https://maps.google.com/maps?q=<?php echo urlencode($selectedEmployer['address'] ?: ($selectedEmployer['city'] ?: 'Kota Bandung Jawa Barat')); ?>&t=&z=13&ie=UTF8&iwloc=&output=embed" allowfullscreen></iframe>
                                </div>

                                <div>
                                    <div style="color:#64748b; font-size:12.5px; font-weight:600; margin-bottom:4px;">Deskripsi Singkat Usaha / Rekrutmen</div>
                                    <div style="font-size:13px; color:#334155; line-height:1.5;"><?php echo e($selectedEmployer['description'] ?: 'Usaha jasa desain grafis dan digital'); ?></div>
                                </div>
                            </div>

                            <!-- CARD 5: ASSIGN PEMERIKSA -->
                            <div class="section-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:20px;">
                                <div class="section-card-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:14px;">Assign Pemeriksa</div>

                                <!-- NOTICE BOX -->
                                <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:14px; margin-bottom:16px;">
                                    <div style="font-size:13px; font-weight:700; color:#92400e; margin-bottom:4px;">Perhatian</div>
                                    <div style="font-size:12.5px; color:#b45309; line-height:1.4;">
                                        Untuk mengubah pemeriksa, pemberi kerja harus memiliki penugasan aktif terlebih dahulu. Silakan ambil case terlebih dahulu melalui aksi di header.
                                    </div>
                                </div>

                                <?php if ($isRevisionStatus): ?>
                                    <div style="margin-bottom:14px;">
                                        <label style="font-size:13px; font-weight:600; color:#0f172a; display:block; margin-bottom:6px;">Pemeriksa <span style="color:#ef4444;">*</span></label>
                                        <div style="display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; background:#f8fafc; color:#94a3b8; font-size:13px; cursor:not-allowed;">
                                            <span>Pilih pemeriksa...</span>
                                            <i class="fa-solid fa-chevron-down" style="color:#cbd5e1; font-size:11px;"></i>
                                        </div>
                                    </div>

                                    <div style="margin-bottom:16px;">
                                        <label style="font-size:13px; font-weight:600; color:#0f172a; display:block; margin-bottom:6px;">Alasan <span style="color:#ef4444;">*</span></label>
                                        <textarea disabled placeholder="Masukkan alasan penugasan (minimal 10 karakter)..." style="width:100%; min-height:80px; padding:10px 12px; border-radius:8px; border:1px solid #e2e8f0; background:#f8fafc; font-size:13px; color:#94a3b8; outline:none; font-family:inherit; cursor:not-allowed; resize:none;"></textarea>
                                    </div>

                                    <button type="button" disabled style="width:100%; height:42px; background:#7dd3fc; opacity:0.6; border:none; border-radius:10px; color:#0369a1; font-weight:700; font-size:13.5px; cursor:not-allowed;">
                                        Assign Pemeriksa
                                    </button>
                                <?php else: ?>
                                    <form method="post" action="admin.php?view=verifikasi_employer&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
                                        <input type="hidden" name="admin_action" value="assign_employer_case">
                                        <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">

                                        <div style="margin-bottom:14px; position:relative;">
                                            <label style="font-size:13px; font-weight:600; color:#0f172a; display:block; margin-bottom:6px;">Pemeriksa <span style="color:#ef4444;">*</span></label>
                                            <input type="hidden" name="verifier_name" id="inputAssignPemeriksa" required value="">
                                            <div id="assignPemeriksaTrigger" onclick="toggleAssignPemeriksaDropdown(event)" style="display:flex; align-items:center; justify-content:space-between; border:1px solid #cbd5e1; border-radius:8px; padding:10px 12px; background:#ffffff; cursor:pointer; font-size:13px; color:#0f172a;">
                                                <span id="assignPemeriksaLabel" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#64748b;">Pilih pemeriksa...</span>
                                                <i class="fa-solid fa-chevron-down" style="color:#94a3b8; font-size:11px;"></i>
                                            </div>

                                            <!-- SEARCHABLE DROPDOWN CARD -->
                                            <div id="assignPemeriksaDropdownCard" style="display:none; position:absolute; left:0; right:0; top:calc(100% + 4px); background:#ffffff; border:1px solid #00a8e8; border-radius:12px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.15); z-index:1005; padding:8px;">
                                                <input type="text" id="assignPemeriksaSearchInput" onkeyup="filterAssignPemeriksaOptions()" placeholder="Cari Pemeriksa..." style="width:100%; border:1px solid #00a8e8; border-radius:8px; padding:8px 12px; font-size:13px; outline:none; margin-bottom:6px; box-sizing:border-box;">
                                                <div id="assignPemeriksaOptionsContainer" style="max-height:220px; overflow-y:auto;"></div>
                                            </div>
                                        </div>

                                        <div style="margin-bottom:16px;">
                                            <label style="font-size:13px; font-weight:600; color:#0f172a; display:block; margin-bottom:6px;">Alasan <span style="color:#ef4444;">*</span></label>
                                            <textarea name="assignment_reason" required minlength="10" placeholder="Masukkan alasan penugasan (minimal 10 karakter)..." style="width:100%; min-height:80px; padding:10px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:13px; color:#0f172a; outline:none; font-family:inherit;"></textarea>
                                        </div>

                                        <button type="submit" style="width:100%; height:42px; background:#7dd3fc; border:none; border-radius:10px; color:#0369a1; font-weight:700; font-size:13.5px; cursor:pointer; transition:background 0.2s;">
                                            Assign Pemeriksa
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <!-- ========================================== -->
                            <!-- JALUR MANUAL DINAS SECTION (REVISI DIMINTA KE-3) -->
                            <!-- ========================================== -->
                            <?php if ($isDinasFlow): ?>
                                <div id="sectionManualDinasEdit" class="section-card" style="border:2px solid #0284c7; background:#f0f9ff; border-radius:14px; padding:20px;">
                                    <div class="section-card-title" style="color:#0369a1; font-size:15px; font-weight:800; margin-bottom:8px; display:flex; align-items:center; gap:8px;">
                                        <i class="fa-solid fa-hands-holding-child"></i> Pendampingan & Permohonan Ulang Bersama Petugas Dinas (Revisi Diminta ke-3)
                                    </div>
                                    <p style="font-size:13px; color:#0c4a6e; line-height:1.5; margin-bottom:16px;">
                                        Pengajuan ini telah mencapai batas maksimal revisi mandiri (<strong>Revisi Diminta ke-3</strong>). Petugas Dinas sesuai domisili dapat membantu memperbaiki data profil pemohon, mengirimkan permohonan persetujuan (Consent), memverifikasi secara manual, dan menyetujui serta mengaktifkan hak akses.
                                    </p>

                                    <!-- STEP 1: STATUS CONSENT BOX -->
                                    <div style="background:#ffffff; border:1px solid #bae6fd; border-radius:10px; padding:14px; margin-bottom:16px; font-size:13px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                                        <div>
                                            <strong>Status Consent Pemberi Kerja:</strong>
                                            <?php if ($selectedEmployer['manual_review_status'] === 'CONSENT_GIVEN'): ?>
                                                <span class="pill-badge verified" style="margin-left:8px; font-weight:700; background:#dcfce7; color:#15803d; border:1px solid #bbf7d0;">
                                                    <i class="fa-solid fa-check-circle"></i> Sudah Disetujui
                                                </span>
                                            <?php elseif ($selectedEmployer['manual_review_status'] === 'CONSENT_PENDING'): ?>
                                                <span class="pill-badge pending" style="margin-left:8px; background:#fef3c7; color:#b45309; border:1px solid #fde68a;">
                                                    <i class="fa-solid fa-clock"></i> Menunggu Persetujuan Pemohon (Consent Terkirim)
                                                </span>
                                            <?php elseif ($selectedEmployer['manual_review_status'] === 'INVALID'): ?>
                                                <span class="pill-badge danger" style="margin-left:8px; background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5;">
                                                    <i class="fa-solid fa-triangle-exclamation"></i> Consent INVALID (Data Berubah Setelah Persetujuan)
                                                </span>
                                            <?php else: ?>
                                                <span class="pill-badge process" style="margin-left:8px; background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;">
                                                    Belum Dikirimkan Consent
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($selectedEmployer['consent_given_at']) && $selectedEmployer['manual_review_status'] === 'CONSENT_GIVEN'): ?>
                                            <div style="font-size:12px; color:#059669; font-weight:600;">
                                                <i class="fa-regular fa-calendar-check"></i> <?php echo date('d M Y, H:i', strtotime($selectedEmployer['consent_given_at'])); ?> WIB
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- STEP 2: FORM PROFIL PEMBERI KERJA INDIVIDU (VERSI ADMIN) -->
                                    <details id="detailsAdminProfileForm" style="background:#ffffff; border:1px solid #cbd5e1; border-radius:12px; padding:16px; margin-bottom:16px;" <?php echo ($selectedEmployer['manual_review_status'] !== 'CONSENT_GIVEN') ? 'open' : ''; ?>>
                                        <summary style="font-weight:700; color:#0f172a; cursor:pointer; font-size:13.5px; display:flex; align-items:center; gap:8px;">
                                            <i class="fa-solid fa-pen-to-square" style="color:#0284c7;"></i> Form Profil Pemberi Kerja Individu (Versi Admin - Prefilled & Editable)
                                        </summary>
                                        <form method="post" action="admin.php?view=verifikasi_employer&detail_id=<?php echo $selectedEmployer['user_id']; ?>" enctype="multipart/form-data" style="margin-top:16px;">
                                            <input type="hidden" name="admin_action" value="manual_dinas_edit">
                                            <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                            
                                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; font-size:12.5px;">
                                                <div>
                                                    <label style="font-weight:600; display:block; margin-bottom:4px; color:#334155;">Nama Lengkap Pemberi Kerja:</label>
                                                    <input type="text" name="owner_name" value="<?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?>" required style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:12.5px;">
                                                </div>
                                                <div>
                                                    <label style="font-weight:600; display:block; margin-bottom:4px; color:#334155;">NIK:</label>
                                                    <input type="text" name="nik" value="<?php echo e($selectedEmployer['nik'] ?? ''); ?>" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:12.5px;">
                                                </div>
                                                <div>
                                                    <label style="font-weight:600; display:block; margin-bottom:4px; color:#334155;">Nomor Telepon:</label>
                                                    <input type="text" name="phone" value="<?php echo e($selectedEmployer['phone']); ?>" required style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:12.5px;">
                                                </div>
                                                <div>
                                                    <label style="font-weight:600; display:block; margin-bottom:4px; color:#334155;">WhatsApp:</label>
                                                    <input type="text" name="whatsapp" value="<?php echo e($selectedEmployer['whatsapp'] ?? $selectedEmployer['phone']); ?>" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:12.5px;">
                                                </div>
                                                <div>
                                                    <label style="font-weight:600; display:block; margin-bottom:4px; color:#334155;">Jenis Profesi / Usaha Individu:</label>
                                                    <input type="text" name="profession" value="<?php echo e($selectedEmployer['profession'] ?? ''); ?>" required style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:12.5px;">
                                                </div>
                                                <div>
                                                    <label style="font-weight:600; display:block; margin-bottom:4px; color:#334155;">NPWP:</label>
                                                    <input type="text" name="npwp" value="<?php echo e($selectedEmployer['npwp'] ?? ''); ?>" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:12.5px;">
                                                </div>
                                                          <!-- SOSIAL MEDIA -->
                                                <div>
                                                    <label style="font-weight:600; display:block; margin-bottom:4px; color:#334155;">Instagram:</label>
                                                    <input type="text" name="instagram" value="<?php echo e($selectedEmployer['instagram'] ?? ''); ?>" placeholder="@username atau https://instagram.com/..." style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:12.5px;">
                                                </div>
                                                <div>
                                                    <label style="font-weight:600; display:block; margin-bottom:4px; color:#334155;">Facebook / LinkedIn:</label>
                                                    <input type="text" name="facebook" value="<?php echo e($selectedEmployer['facebook'] ?? ''); ?>" placeholder="@username atau https://facebook.com/..." style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:12.5px;">
                                                </div>                                  </div>

                                                <!-- LOKASI WILAYAH -->
                                                <div>
                                                    <label style="font-weight:600; display:block; margin-bottom:4px; color:#334155;">Provinsi:</label>
                                                    <input type="text" name="province" value="<?php echo e($selectedEmployer['province'] ?? ''); ?>" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:12.5px;">
                                                </div>
                                                <div>
                                                    <label style="font-weight:600; display:block; margin-bottom:4px; color:#334155;">Kota / Kabupaten:</label>
                                                    <input type="text" name="city" value="<?php echo e($selectedEmployer['city']); ?>" required style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:12.5px;">
                                                </div>
                                                <div>
                                                    <label style="font-weight:600; display:block; margin-bottom:4px; color:#334155;">Kecamatan:</label>
                                                    <input type="text" name="district" value="<?php echo e($selectedEmployer['district'] ?? ''); ?>" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:12.5px;">
                                                </div>
                                                <div>
                                                    <label style="font-weight:600; display:block; margin-bottom:4px; color:#334155;">Kelurahan / Desa:</label>
                                                    <input type="text" name="village" value="<?php echo e($selectedEmployer['village'] ?? ''); ?>" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:12.5px;">
                                                </div>
                                                <div>
                                                    <label style="font-weight:600; display:block; margin-bottom:4px; color:#334155;">Kode Pos:</label>
                                                    <input type="text" name="postal_code" value="<?php echo e($selectedEmployer['postal_code'] ?? ''); ?>" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:12.5px;">
                                                </div>
                                                <div>
                                                    <label style="font-weight:600; display:block; margin-bottom:4px; color:#334155;">Detail Alamat / Patokan:</label>
                                                    <input type="text" name="address_detail" value="<?php echo e($selectedEmployer['address_detail'] ?? ''); ?>" placeholder="Patokan lokasi..." style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:12.5px;">
                                                </div>

                                                <!-- ALAMAT LENGKAP -->
                                                <div style="grid-column: span 2;">
                                                    <label style="font-weight:600; display:block; margin-bottom:4px; color:#334155;">Alamat Lengkap Domisili:</label>
                                                    <input type="text" name="address" value="<?php echo e($selectedEmployer['address']); ?>" required style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:12.5px;">
                                                </div>

                                                <!-- DESKRIPSI -->
                                                <div style="grid-column: span 2;">
                                                    <label style="font-weight:600; display:block; margin-bottom:4px; color:#334155;">Deskripsi Singkat Usaha / Rekrutmen:</label>
                                                    <textarea name="description" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:12.5px; min-height:60px;"><?php echo e($selectedEmployer['description']); ?></textarea>
                                                </div>

                                                <!-- DOKUMEN & FOTO -->
                                                <div>
                                                    <label style="font-weight:600; display:block; margin-bottom:4px; color:#334155;">Dokumen Pendukung:</label>
                                                    <?php if (!empty($selectedEmployer['permit_document']) || !empty($selectedEmployer['doc_permission'])): ?>
                                                        <div style="margin-bottom:6px; font-size:11.5px; color:#0284c7;">
                                                            <i class="fa-solid fa-file-lines"></i> File tersimpan: <code><?php echo e(basename($selectedEmployer['permit_document'] ?? $selectedEmployer['doc_permission'])); ?></code>
                                                        </div>
                                                    <?php endif; ?>
                                                    <input type="file" name="permit_document" accept=".pdf,.jpg,.jpeg,.png" style="width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:8px; font-size:11.5px;">
                                                </div>
                                                <div>
                                                    <label style="font-weight:600; display:block; margin-bottom:4px; color:#334155;">Foto Bukti Tempat Usaha / Lokasi:</label>
                                                    <?php if (!empty($selectedEmployer['workplace_photo']) || !empty($selectedEmployer['doc_location_photo'])): ?>
                                                        <div style="margin-bottom:6px; font-size:11.5px; color:#0284c7;">
                                                            <i class="fa-solid fa-image"></i> Foto tersimpan: <code><?php echo e(basename($selectedEmployer['workplace_photo'] ?? $selectedEmployer['doc_location_photo'])); ?></code>
                                                        </div>
                                                    <?php endif; ?>
                                                    <input type="file" name="workplace_photo" accept=".jpg,.jpeg,.png,.webp" style="width:100%; padding:6px; border:1px solid #cbd5e1; border-radius:8px; font-size:11.5px;">
                                                </div>
                                            </div>

                                            <!-- FORM ACTION BUTTONS -->
                                            <div style="margin-top:16px; display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #f1f5f9; padding-top:14px;">
                                                <button type="submit" name="save_only" value="1" class="secondary-btn" style="height:36px; padding:0 16px; font-size:12.5px; font-weight:600; border:1px solid #cbd5e1; border-radius:8px; cursor:pointer; background:#ffffff; color:#334155;">
                                                    <i class="fa-solid fa-floppy-disk"></i> Simpan Perubahan Data
                                                </button>
                                                <button type="submit" name="send_consent" value="1" class="primary-btn" style="height:36px; padding:0 16px; font-size:12.5px; font-weight:700; background:#0284c7; color:#ffffff; border:none; border-radius:8px; cursor:pointer;">
                                                    <i class="fa-solid fa-paper-plane"></i> Kirim Permintaan Consent
                                                </button>
                                            </div>
                                        </form>
                                    </details>

                                    <!-- STEP 3: PERNYATAAN VERIFIKASI MANUAL PETUGAS DINAS -->
                                    <div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:12px; padding:18px;">
                                        <div style="font-weight:700; color:#0f172a; font-size:14px; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                                            <i class="fa-solid fa-certificate" style="color:#059669;"></i> Pernyataan Verifikasi Manual Petugas Dinas
                                        </div>

                                        <div style="background:#f8fafc; border-left:4px solid #0284c7; padding:12px 14px; border-radius:6px; font-size:12.5px; color:#334155; line-height:1.6; margin-bottom:14px;">
                                            “Saya sebagai Petugas Dinas yang berwenang menyatakan telah melakukan pemeriksaan dan verifikasi manual terhadap identitas, bukti tempat pemberi kerja, serta data pendukung Pemberi Kerja Individu yang bersangkutan. Saya memastikan hasil pemeriksaan ini dapat dipertanggungjawabkan secara kedinasan dan hukum.”
                                        </div>

                                        <form method="post" action="admin.php?view=verifikasi_employer&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
                                            <input type="hidden" name="admin_action" value="manual_dinas_approve_activate">
                                            <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                            <input type="hidden" name="officer_name" value="<?php echo e($user['name']); ?>">
                                            <input type="hidden" name="officer_statement" value="Saya sebagai Petugas Dinas yang berwenang menyatakan telah melakukan pemeriksaan dan verifikasi manual terhadap identitas, bukti tempat pemberi kerja, serta data pendukung Pemberi Kerja Individu yang bersangkutan. Saya memastikan hasil pemeriksaan ini dapat dipertanggungjawabkan secara kedinasan dan hukum.">

                                            <div style="margin-bottom:16px;">
                                                <label style="font-size:13px; font-weight:600; color:#0f172a; display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
                                                    <input type="checkbox" id="officerStatementCheck" name="statement_confirmed" value="1" onchange="toggleOfficerApproveButton()" <?php echo ($selectedEmployer['manual_review_status'] !== 'CONSENT_GIVEN') ? 'disabled' : ''; ?> style="margin-top:2px; width:16px; height:16px; accent-color:#059669; cursor:pointer;">
                                                    <span>Saya menyatakan telah melakukan verifikasi manual dan bertanggung jawab atas hasil pemeriksaan ini.</span>
                                                </label>
                                            </div>

                                            <button type="submit" id="btnOfficerApproveActivate" class="primary-btn" style="background:#059669; width:100%; height:44px; font-size:13.5px; font-weight:700; border-radius:10px; border:none; color:#ffffff; display:flex; align-items:center; justify-content:center; gap:8px; opacity:0.5; cursor:not-allowed;" disabled>
                                                <i class="fa-solid fa-check-double"></i> Setujui & Aktifkan Akses
                                            </button>

                                            <div id="officerApproveNotice" style="font-size:12px; color:#64748b; margin-top:10px; text-align:center;">
                                                <?php if ($selectedEmployer['manual_review_status'] === 'CONSENT_GIVEN'): ?>
                                                    <span style="color:#b45309;"><i class="fa-solid fa-circle-info"></i> Centang pernyataan verifikasi manual di atas untuk mengaktifkan tombol Setujui & Aktifkan Akses.</span>
                                                <?php else: ?>
                                                    <i class="fa-solid fa-lock"></i> Tombol <strong>Setujui & Aktifkan Akses</strong> dinonaktifkan sampai User Consent Pemberi Kerja = <strong>Sudah Disetujui</strong> dan Pernyataan Petugas Dinas dicentang.
                                                <?php endif; ?>
                                            </div>
                                        </form>

                                        <script>
                                        function toggleOfficerApproveButton() {
                                            const isConsentGiven = <?php echo ($selectedEmployer['manual_review_status'] === 'CONSENT_GIVEN') ? 'true' : 'false'; ?>;
                                            const check = document.getElementById('officerStatementCheck');
                                            const btn = document.getElementById('btnOfficerApproveActivate');
                                            const notice = document.getElementById('officerApproveNotice');
                                            if (isConsentGiven && check && check.checked) {
                                                btn.disabled = false;
                                                btn.style.opacity = '1';
                                                btn.style.cursor = 'pointer';
                                                if (notice) notice.innerHTML = '<span style="color:#059669; font-weight:600;"><i class="fa-solid fa-circle-check"></i> Seluruh syarat terpenuhi. Anda dapat menyetujui dan mengaktifkan akses pemberi kerja.</span>';
                                            } else {
                                                btn.disabled = true;
                                                btn.style.opacity = '0.5';
                                                btn.style.cursor = 'not-allowed';
                                                if (notice && isConsentGiven) {
                                                    notice.innerHTML = '<span style="color:#b45309;"><i class="fa-solid fa-circle-info"></i> Centang pernyataan verifikasi manual di atas untuk mengaktifkan tombol.</span>';
                                                }
                                            }
                                        }
                                        </script>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- ========================================== -->
                            <!-- REGULAR DECISION PANEL (ASSIGNMENT MANDATORY) -->
                            <!-- ========================================== -->
                            <!-- REGULAR DECISION PANEL (ASSIGNMENT MANDATORY) -->
                            <!-- ========================================== -->
                            <?php if (!$isRevisionStatus && $status === 'PENDING'): ?>
                                <div class="section-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:20px; margin-top:10px;">
                                    <div class="section-card-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:16px;">Checklist & Keputusan Verifikasi Profil</div>

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
                                                <?php
                                                $currRevCount = (int)($selectedEmployer['revision_count'] ?? ($selectedEmployer['rejection_count'] ?? 0));
                                                $nextRevStep = min(3, max(1, $currRevCount + 1));
                                                ?>
                                                <label style="font-weight:700; font-size:13px; display:block; margin-bottom:6px;">Keputusan Final:</label>
                                                <select name="decision" required style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1; font-size:13px; font-weight:600;">
                                                    <option value="approve">Setujui (Profil Terverifikasi 3 Bulan)</option>
                                                    <option value="revision">Perlu Diperbaiki / Revisi (Diminta Revisi ke-<?php echo $nextRevStep; ?>)</option>
                                                    <option value="reject">Tolak Profil</option>
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
                            <?php endif; ?>
                        </div>

                        <!-- RIGHT COLUMN: AKUN & AUDIT LOG -->
                        <div style="display:flex; flex-direction:column; gap:20px;">
                            <!-- CARD: AKUN PEMBERI KERJA -->
                            <div class="section-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:20px;">
                                <div class="section-card-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:16px;">Akun Pemberi Kerja</div>

                                <div style="display:flex; align-items:center; gap:12px; margin-bottom:16px;">
                                    <div style="width:42px; height:42px; border-radius:50%; background:#ef4444; color:#ffffff; font-weight:800; display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0; overflow:hidden;">
                                        <?php if (!empty($selectedEmployer['avatar'])): ?>
                                            <img src="<?php echo e($selectedEmployer['avatar']); ?>" style="width:100%; height:100%; object-fit:cover;">
                                        <?php else: ?>
                                            <?php echo strtoupper(substr($selectedEmployer['owner_name'] ?: $selectedEmployer['name'], 0, 2)); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div style="flex:1; min-width:0;">
                                        <div style="font-size:14px; font-weight:800; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-transform:uppercase;"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></div>
                                    </div>
                                    <span style="background:#dcfce7; color:#15803d; font-size:11px; font-weight:700; padding:3px 10px; border-radius:12px;">Aktif</span>
                                </div>

                                <div style="margin-bottom:14px;">
                                    <div style="font-size:12px; color:#64748b; font-weight:500; margin-bottom:2px;">Email Akun</div>
                                    <div style="font-size:13px; font-weight:600; color:#00a8e8; word-break:break-all;"><?php echo e($selectedEmployer['email'] ?: 'ahmad@email.com'); ?></div>
                                </div>

                                <div>
                                    <div style="font-size:12px; color:#64748b; font-weight:500; margin-bottom:2px;">Status Akun</div>
                                    <div style="font-size:13px; font-weight:600; color:#0f172a;">Aktif</div>
                                </div>
                            </div>

                            <!-- CARD: AKTIVITAS & AUDIT LOG -->
                            <div class="section-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:20px;">
                                <div class="section-card-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:16px;">Aktivitas & Audit Log</div>

                                <div class="timeline-list">
                                    <?php if ($isRevisionStatus && empty($auditLogs)): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-dot" style="background:#f59e0b;"></div>
                                            <div class="timeline-time">24 Sep 2026, 09:20</div>
                                            <div class="timeline-title" style="font-weight:600; color:#0f172a;">Revisi Diminta kepada Pemberi Kerja Individu.</div>
                                        </div>
                                        <div class="timeline-item">
                                            <div class="timeline-dot"></div>
                                            <div class="timeline-time">24 Sep 2026, 09:18</div>
                                            <div class="timeline-title" style="font-weight:600; color:#0f172a;"><?php echo strtoupper(e($selectedEmployer['assigned_to'] ?: 'BUDI SANTOSO')); ?> - Memberikan keputusan Revisi Diminta.</div>
                                        </div>
                                        <div class="timeline-item">
                                            <div class="timeline-dot"></div>
                                            <div class="timeline-time">22 Sep 2026, 11:50</div>
                                            <div class="timeline-title" style="font-weight:600; color:#0f172a;">Ditugaskan ke <?php echo e($selectedEmployer['assigned_to'] ?: 'Budi Santoso'); ?>.</div>
                                        </div>
                                        <div class="timeline-item">
                                            <div class="timeline-dot"></div>
                                            <div class="timeline-time">22 Sep 2026, 11:50</div>
                                            <div class="timeline-title" style="font-weight:600; color:#0f172a;"><?php echo strtoupper(e($selectedEmployer['assigned_to'] ?: 'BUDI SANTOSO')); ?> - Mengambil Pengajuan: Dikirim → Dalam Verifikasi</div>
                                        </div>
                                        <div class="timeline-item">
                                            <div class="timeline-dot"></div>
                                            <div class="timeline-time"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                            <div class="timeline-title" style="font-weight:600; color:#0f172a;">Data profil dikirim untuk verifikasi.</div>
                                        </div>
                                        <div class="timeline-item">
                                            <div class="timeline-dot"></div>
                                            <div class="timeline-time"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'] . ' -2 minutes')); ?></div>
                                            <div class="timeline-title" style="font-weight:600; color:#0f172a;">Pemberi kerja mengajukan profil.</div>
                                        </div>
                                    <?php else: ?>
                                        <div class="timeline-item">
                                            <div class="timeline-dot"></div>
                                            <div class="timeline-time"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                            <div class="timeline-title">Data pemberi kerja dikirim untuk verifikasi.</div>
                                        </div>
                                        <?php if (!empty($auditLogs)): ?>
                                            <?php foreach ($auditLogs as $log): ?>
                                                <div class="timeline-item">
                                                    <div class="timeline-dot"></div>
                                                    <div class="timeline-time"><?php echo date('d M Y, H:i', strtotime($log['created_at'])); ?></div>
                                                    <div class="timeline-title"><?php echo e($log['action']); ?> <small style="color:#64748b;">(oleh <?php echo e($log['actor_name']); ?>)</small></div>
                                                    <div class="timeline-desc"><?php echo e($log['details']); ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="timeline-item">
                                                <div class="timeline-dot"></div>
                                                <div class="timeline-time"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                                <div class="timeline-title"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?> - Mengirim Data: Membuat → Dikirim</div>
                                            </div>
                                            <div class="timeline-item">
                                                <div class="timeline-dot"></div>
                                                <div class="timeline-time"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                                <div class="timeline-title">Pemberi kerja mendaftar di platform.</div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- MODAL LIHAT DOKUMEN PENDUKUNG -->
                    <div class="modal-backdrop" data-modal="modal-view-doc">
                        <div class="modal-panel" style="width:min(720px, 92vw); max-height:90vh; display:flex; flex-direction:column;">
                            <div class="modal-header">
                                <div>
                                    <div class="modal-title">Dokumen Pendukung</div>
                                    <div class="modal-subtitle">dokumen-usaha.pdf &bull; <?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></div>
                                </div>
                                <button type="button" data-close-modal="modal-view-doc" style="background:none; border:none; color:#64748b; font-size:18px; cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
                            </div>
                            <div class="modal-body" style="flex:1; overflow-y:auto; padding:20px; background:#f8fafc; text-align:center;">
                                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:30px; box-shadow:0 4px 12px rgba(0,0,0,0.05); max-width:550px; margin:0 auto; text-align:left;">
                                    <div style="display:flex; align-items:center; gap:12px; border-bottom:1px solid #e2e8f0; padding-bottom:16px; margin-bottom:16px;">
                                        <div style="width:44px; height:44px; border-radius:10px; background:#fee2e2; color:#ef4444; display:flex; align-items:center; justify-content:center; font-size:22px;">
                                            <i class="fa-solid fa-file-pdf"></i>
                                        </div>
                                        <div>
                                            <div style="font-weight:700; color:#0f172a; font-size:15px;">dokumen-usaha.pdf</div>
                                            <div style="font-size:12px; color:#64748b;">PDF Document &bull; 1.4 MB &bull; Diunggah pada <?php echo date('d M Y', strtotime($selectedEmployer['created_at'])); ?></div>
                                        </div>
                                    </div>
                                    <div style="font-size:13px; color:#475569; line-height:1.6; margin-bottom:20px;">
                                        <p><strong>Nama Pemilik / Usaha:</strong> <?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></p>
                                        <p><strong>NIK:</strong> <?php echo e($selectedEmployer['nik'] ?: '3273xxxxxxxxxxxx'); ?></p>
                                        <p><strong>NPWP:</strong> <?php echo e($selectedEmployer['npwp'] ?: '12.345.678.9-123.000'); ?></p>
                                        <p><strong>Jenis Usaha:</strong> <?php echo e($selectedEmployer['profession'] ?: ($selectedEmployer['description'] ?: 'Jasa Desain Grafis')); ?></p>
                                        <p><strong>Alamat:</strong> <?php echo e($selectedEmployer['address'] ?: 'Jl. Ir. H. Juanda No. 25, Bandung'); ?></p>
                                    </div>
                                    <div style="background:#f1f5f9; border-radius:8px; padding:12px; font-size:12px; color:#64748b; display:flex; align-items:center; gap:8px;">
                                        <i class="fa-solid fa-circle-check" style="color:#10b981;"></i> Dokumen telah tersertifikasi digital dan terenkripsi.
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:10px;">
                                <button type="button" class="ghost-btn" data-close-modal="modal-view-doc">Tutup</button>
                                <a href="#" onclick="event.preventDefault(); alert('Mengunduh dokumen-usaha.pdf');" class="primary-btn" style="text-decoration:none; display:inline-flex; align-items:center; gap:6px; height:38px; padding:0 16px; font-size:13px;">
                                    <i class="fa-solid fa-download"></i> Unduh File
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- MODAL LIHAT FOTO TEMPAT USAHA -->
                    <div class="modal-backdrop" data-modal="modal-view-photos">
                        <div class="modal-panel" style="width:min(800px, 94vw); max-height:90vh; display:flex; flex-direction:column;">
                            <div class="modal-header">
                                <div>
                                    <div class="modal-title">Foto Bukti Tempat Usaha / Lokasi</div>
                                    <div class="modal-subtitle">2 Foto &bull; <?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></div>
                                </div>
                                <button type="button" data-close-modal="modal-view-photos" style="background:none; border:none; color:#64748b; font-size:18px; cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
                            </div>
                            <div class="modal-body" style="flex:1; overflow-y:auto; padding:20px;">
                                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:16px;">
                                    <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden;">
                                        <div style="height:200px; background:#e2e8f0; display:flex; align-items:center; justify-content:center; color:#64748b; font-size:36px; position:relative;">
                                            <i class="fa-solid fa-shop" style="color:#94a3b8;"></i>
                                            <span style="position:absolute; bottom:8px; left:8px; background:rgba(0,0,0,0.6); color:#ffffff; font-size:11px; padding:3px 8px; border-radius:6px;">Foto 1 - Tampak Depan</span>
                                        </div>
                                        <div style="padding:12px;">
                                            <div style="font-weight:700; font-size:13px; color:#0f172a;">Tampak Depan Tempat Usaha</div>
                                            <div style="font-size:12px; color:#64748b; margin-top:2px;">Jl. Ir. H. Juanda No. 25, Bandung</div>
                                        </div>
                                    </div>
                                    <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden;">
                                        <div style="height:200px; background:#e2e8f0; display:flex; align-items:center; justify-content:center; color:#64748b; font-size:36px; position:relative;">
                                            <i class="fa-solid fa-laptop-code" style="color:#94a3b8;"></i>
                                            <span style="position:absolute; bottom:8px; left:8px; background:rgba(0,0,0,0.6); color:#ffffff; font-size:11px; padding:3px 8px; border-radius:6px;">Foto 2 - Ruang Kerja / Studio</span>
                                        </div>
                                        <div style="padding:12px;">
                                            <div style="font-weight:700; font-size:13px; color:#0f172a;">Aktivitas Kerja / Studio Desain</div>
                                            <div style="font-size:12px; color:#64748b; margin-top:2px;">Perangkat & fasilitas kerja individu</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer" style="display:flex; justify-content:flex-end;">
                                <button type="button" class="ghost-btn" data-close-modal="modal-view-photos">Tutup</button>
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
                                    <div style="margin-bottom:14px; position:relative;">
                                        <label style="font-size:13px; font-weight:700; display:block; margin-bottom:4px;">Pemeriksa:</label>
                                        <input type="hidden" name="verifier_name" id="inputAssignPemeriksaModal" required value="<?php echo e($user['name']); ?>">
                                        <div id="assignPemeriksaModalTrigger" onclick="toggleAssignPemeriksaModalDropdown(event)" style="display:flex; align-items:center; justify-content:space-between; border:1px solid #cbd5e1; border-radius:8px; padding:9px 12px; background:#ffffff; cursor:pointer; font-size:13px; color:#0f172a;">
                                            <span id="assignPemeriksaModalLabel" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#0f172a; font-weight:600;"><?php echo e($user['name']); ?> (Saya)</span>
                                            <i class="fa-solid fa-chevron-down" style="color:#94a3b8; font-size:11px;"></i>
                                        </div>

                                        <!-- SEARCHABLE DROPDOWN CARD -->
                                        <div id="assignPemeriksaModalDropdownCard" style="display:none; position:absolute; left:0; right:0; top:calc(100% + 4px); background:#ffffff; border:1px solid #00a8e8; border-radius:12px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.15); z-index:1005; padding:8px;">
                                            <input type="text" id="assignPemeriksaModalSearchInput" onkeyup="filterAssignPemeriksaModalOptions()" placeholder="Cari Pemeriksa..." style="width:100%; border:1px solid #00a8e8; border-radius:8px; padding:8px 12px; font-size:13px; outline:none; margin-bottom:6px; box-sizing:border-box;">
                                            <div id="assignPemeriksaModalOptionsContainer" style="max-height:220px; overflow-y:auto;"></div>
                                        </div>
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

                    <script>
                    const ALL_PEMERIKSA_MASTER = <?php echo json_encode(array_merge([$user['name'] . ' (Saya)'], $pemeriksaMasterList)); ?>;

                    function toggleAssignPemeriksaDropdown(e) {
                        if (e) e.stopPropagation();
                        const card = document.getElementById('assignPemeriksaDropdownCard');
                        if (!card) return;
                        const isHidden = card.style.display === 'none';
                        card.style.display = isHidden ? 'block' : 'none';
                        if (isHidden) {
                            populateAssignPemeriksaOptions();
                            setTimeout(() => {
                                const input = document.getElementById('assignPemeriksaSearchInput');
                                if (input) input.focus();
                            }, 50);
                        }
                    }

                    function populateAssignPemeriksaOptions() {
                        const container = document.getElementById('assignPemeriksaOptionsContainer');
                        if (!container || container.children.length > 0) return;
                        renderAssignPemeriksaList(ALL_PEMERIKSA_MASTER);
                    }

                    function renderAssignPemeriksaList(list) {
                        const container = document.getElementById('assignPemeriksaOptionsContainer');
                        if (!container) return;
                        container.innerHTML = '';
                        const currentVal = document.getElementById('inputAssignPemeriksa') ? document.getElementById('inputAssignPemeriksa').value : '';

                        list.forEach(itemText => {
                            const item = document.createElement('div');
                            const val = itemText.replace(' (Saya)', '');
                            const isSelected = val === currentVal || itemText === currentVal;
                            item.style.cssText = `padding:8px 12px; font-size:13px; color:#1e293b; border-radius:8px; cursor:pointer; background:${isSelected ? '#f0f9ff' : 'transparent'}; font-weight:${isSelected ? '700' : 'normal'}; transition:background 0.15s;`;
                            item.textContent = itemText;
                            item.onmouseover = () => { if (!isSelected) item.style.background = '#f8fafc'; };
                            item.onmouseout = () => { if (!isSelected) item.style.background = 'transparent'; };
                            item.onclick = (e) => {
                                e.stopPropagation();
                                selectAssignPemeriksa(val, itemText);
                            };
                            container.appendChild(item);
                        });
                    }

                    function filterAssignPemeriksaOptions() {
                        const input = document.getElementById('assignPemeriksaSearchInput');
                        const query = (input ? input.value : '').toLowerCase().trim();
                        const filtered = ALL_PEMERIKSA_MASTER.filter(n => n.toLowerCase().includes(query));
                        renderAssignPemeriksaList(filtered);
                    }

                    function selectAssignPemeriksa(val, labelText) {
                        const input = document.getElementById('inputAssignPemeriksa');
                        if (input) input.value = val;
                        const label = document.getElementById('assignPemeriksaLabel');
                        if (label) {
                            label.textContent = labelText;
                            label.style.color = '#0f172a';
                        }
                        const card = document.getElementById('assignPemeriksaDropdownCard');
                        if (card) card.style.display = 'none';
                    }

                    function toggleAssignPemeriksaModalDropdown(e) {
                        if (e) e.stopPropagation();
                        const card = document.getElementById('assignPemeriksaModalDropdownCard');
                        if (!card) return;
                        const isHidden = card.style.display === 'none';
                        card.style.display = isHidden ? 'block' : 'none';
                        if (isHidden) {
                            populateAssignPemeriksaModalOptions();
                            setTimeout(() => {
                                const input = document.getElementById('assignPemeriksaModalSearchInput');
                                if (input) input.focus();
                            }, 50);
                        }
                    }

                    function populateAssignPemeriksaModalOptions() {
                        const container = document.getElementById('assignPemeriksaModalOptionsContainer');
                        if (!container || container.children.length > 0) return;
                        renderAssignPemeriksaModalList(ALL_PEMERIKSA_MASTER);
                    }

                    function renderAssignPemeriksaModalList(list) {
                        const container = document.getElementById('assignPemeriksaModalOptionsContainer');
                        if (!container) return;
                        container.innerHTML = '';
                        const currentVal = document.getElementById('inputAssignPemeriksaModal') ? document.getElementById('inputAssignPemeriksaModal').value : '';

                        list.forEach(itemText => {
                            const item = document.createElement('div');
                            const val = itemText.replace(' (Saya)', '');
                            const isSelected = val === currentVal || itemText === currentVal;
                            item.style.cssText = `padding:8px 12px; font-size:13px; color:#1e293b; border-radius:8px; cursor:pointer; background:${isSelected ? '#f0f9ff' : 'transparent'}; font-weight:${isSelected ? '700' : 'normal'}; transition:background 0.15s;`;
                            item.textContent = itemText;
                            item.onmouseover = () => { if (!isSelected) item.style.background = '#f8fafc'; };
                            item.onmouseout = () => { if (!isSelected) item.style.background = 'transparent'; };
                            item.onclick = (e) => {
                                e.stopPropagation();
                                selectAssignPemeriksaModal(val, itemText);
                            };
                            container.appendChild(item);
                        });
                    }

                    function filterAssignPemeriksaModalOptions() {
                        const input = document.getElementById('assignPemeriksaModalSearchInput');
                        const query = (input ? input.value : '').toLowerCase().trim();
                        const filtered = ALL_PEMERIKSA_MASTER.filter(n => n.toLowerCase().includes(query));
                        renderAssignPemeriksaModalList(filtered);
                    }

                    function selectAssignPemeriksaModal(val, labelText) {
                        const input = document.getElementById('inputAssignPemeriksaModal');
                        if (input) input.value = val;
                        const label = document.getElementById('assignPemeriksaModalLabel');
                        if (label) {
                            label.textContent = labelText;
                            label.style.color = '#0f172a';
                        }
                        const card = document.getElementById('assignPemeriksaModalDropdownCard');
                        if (card) card.style.display = 'none';
                    }

                    document.addEventListener('click', function(e) {
                        const card = document.getElementById('assignPemeriksaDropdownCard');
                        const trigger = document.getElementById('assignPemeriksaTrigger');
                        if (card && card.style.display !== 'none' && !card.contains(e.target) && (!trigger || !trigger.contains(e.target))) {
                            card.style.display = 'none';
                        }

                        const modalCard = document.getElementById('assignPemeriksaModalDropdownCard');
                        const modalTrigger = document.getElementById('assignPemeriksaModalTrigger');
                        if (modalCard && modalCard.style.display !== 'none' && !modalCard.contains(e.target) && (!modalTrigger || !modalTrigger.contains(e.target))) {
                            modalCard.style.display = 'none';
                        }
                    });
                    </script>

                <?php else: ?>
                    <!-- VERIFIKASI PEMBERI KERJA TABLE VIEW -->
                    <?php
                    $filterParamsEmp = ($startDate ? '&start_date=' . urlencode($startDate) : '')
                        . ($endDate ? '&end_date=' . urlencode($endDate) : '')
                        . ($cityFilter ? '&city_filter=' . urlencode($cityFilter) : '')
                        . ($verifierFilter ? '&verifier_filter=' . urlencode($verifierFilter) : '')
                        . ($officerFilter ? '&officer_filter=' . urlencode($officerFilter) : '')
                        . ($unassignedFilter ? '&unassigned=1' : '');
                    ?>
                    <div style="margin-bottom:20px;">
                        <h1 style="font-size:24px; font-weight:800; margin:0 0 16px 0; color:#0f172a;">Verifikasi Pemberi Kerja</h1>

                        <!-- STATUS TAB LIST -->
                        <div style="border-bottom:1px solid #e2e8f0; margin-bottom:16px;">
                            <div class="status-tab-list" style="gap:24px;">
                                <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=all&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParamsEmp; ?>" class="status-tab-item <?php echo $tab === 'all' ? 'active' : ''; ?>">Semua</a>
                                <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=process&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParamsEmp; ?>" class="status-tab-item <?php echo $tab === 'process' ? 'active' : ''; ?>">Menunggu Verifikasi</a>
                                <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=revision&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParamsEmp; ?>" class="status-tab-item <?php echo $tab === 'revision' ? 'active' : ''; ?>">Revisi</a>
                                <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=approved&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParamsEmp; ?>" class="status-tab-item <?php echo $tab === 'approved' ? 'active' : ''; ?>">Terverifikasi</a>
                                <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=rejected&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParamsEmp; ?>" class="status-tab-item <?php echo $tab === 'rejected' ? 'active' : ''; ?>">Ditolak</a>
                            </div>
                        </div>

                        <!-- FILTER MAIN FORM EMP -->
                        <form method="get" action="admin.php" id="filterMainFormEmp" style="display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:16px; width:100%; position:relative;">
                            <input type="hidden" name="view" value="verifikasi_employer">
                            <input type="hidden" name="tab" value="<?php echo e($tab); ?>">
                            <input type="hidden" name="entity" id="inputEntityEmp" value="<?php echo e($entity); ?>">
                            <?php if ($sort): ?><input type="hidden" name="sort" value="<?php echo e($sort); ?>"><?php endif; ?>
                            <input type="hidden" name="start_date" id="inputStartDateEmp" value="<?php echo e($startDate); ?>">
                            <input type="hidden" name="end_date" id="inputEndDateEmp" value="<?php echo e($endDate); ?>">
                            <input type="hidden" name="city_filter" id="inputCityFilterEmp" value="<?php echo e($cityFilter); ?>">
                            <input type="hidden" name="verifier_filter" id="inputVerifierFilterEmp" value="<?php echo e($verifierFilter); ?>">
                            <input type="hidden" name="officer_filter" id="inputOfficerFilterEmp" value="<?php echo e($officerFilter); ?>">
                            <input type="hidden" name="unassigned" id="inputUnassignedEmp" value="<?php echo $unassignedFilter ? '1' : '0'; ?>">

                            <!-- LEFT CONTAINER: SEARCH BOX & SEGMENTED PILL FILTER -->
                            <div style="display:flex; align-items:center; gap:12px;">
                                <!-- SEARCH BOX -->
                                <div class="filter-search-box" style="width:280px; border-radius:999px; height:38px;">
                                    <i class="fa-solid fa-magnifying-glass" style="color:#94a3b8; font-size:13px;"></i>
                                    <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Cari Pemberi Kerja...">
                                </div>

                                <!-- SEGMENTED PILL FILTER: Semua | Perusahaan | Individual -->
                                <div style="display:inline-flex; background:#f1f5f9; border-radius:999px; padding:3px; gap:2px;">
                                    <a href="admin.php?view=verifikasi_employer&entity=Semua&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?><?php echo $filterParamsEmp; ?>" style="padding:6px 16px; border-radius:999px; font-size:13px; font-weight:600; text-decoration:none; <?php echo ($entity === 'Semua' || !$entity) ? 'background:#ffffff; color:#0f172a; box-shadow:0 1px 3px rgba(0,0,0,0.1);' : 'color:#64748b;'; ?>">Semua</a>
                                    <a href="admin.php?view=verifikasi_employer&entity=Perusahaan&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?><?php echo $filterParamsEmp; ?>" style="padding:6px 16px; border-radius:999px; font-size:13px; font-weight:600; text-decoration:none; <?php echo $entity === 'Perusahaan' ? 'background:#ffffff; color:#0f172a; box-shadow:0 1px 3px rgba(0,0,0,0.1);' : 'color:#64748b;'; ?>">Perusahaan</a>
                                    <a href="admin.php?view=verifikasi_employer&entity=Individu&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?><?php echo $filterParamsEmp; ?>" style="padding:6px 16px; border-radius:999px; font-size:13px; font-weight:600; text-decoration:none; <?php echo ($entity === 'Individu' || $entity === 'Individual') ? 'background:#ffffff; color:#0f172a; box-shadow:0 1px 3px rgba(0,0,0,0.1);' : 'color:#64748b;'; ?>">Individual</a>
                                </div>
                            </div>

                            <!-- FILTER BUTTON & POPOVER -->
                            <div style="position:relative;">
                                <button type="button" class="filter-btn" id="filterToggleBtnEmp" onclick="toggleFilterPopoverEmp(event)" style="display:inline-flex; align-items:center; gap:6px; background:#ffffff; border:1px solid #cbd5e1; border-radius:10px; padding:7px 16px; font-size:13px; font-weight:600; color:#334155; cursor:pointer;">
                                    <i class="fa-solid fa-sliders" style="font-size:12px;"></i> Filter
                                    <?php if ($startDate || $endDate || $cityFilter || $verifierFilter || $officerFilter || $unassignedFilter): ?>
                                        <span style="background:#0284c7; color:#fff; font-size:10px; border-radius:999px; padding:1px 6px; margin-left:2px;">●</span>
                                    <?php endif; ?>
                                </button>

                                <!-- FILTER POPOVER CARD EMP -->
                                <div id="filterPopoverEmp" style="display:none; position:absolute; right:0; top:calc(100% + 8px); width:280px; background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.05); z-index:1000; overflow:visible;">

                                    <!-- ACCORDION 1: TANGGAL PENGAJUAN -->
                                    <div style="border-bottom:1px solid #f1f5f9;">
                                        <div onclick="toggleAccordionEmp('date')" style="display:flex; justify-content:space-between; align-items:center; padding:14px 18px; cursor:pointer; user-select:none;">
                                            <span style="font-size:13.5px; font-weight:700; color:#334155;">Tanggal Pengajuan</span>
                                            <i class="fa-solid fa-chevron-down" id="dateChevronEmp" style="font-size:11px; color:#94a3b8; transition:transform 0.2s; <?php echo ($startDate || $endDate) ? 'transform:rotate(180deg);' : ''; ?>"></i>
                                        </div>
                                        <div id="dateAccordionBodyEmp" style="display:<?php echo ($startDate || $endDate) ? 'block' : 'none'; ?>; padding:0 18px 14px 18px;">
                                            <div id="dateRangeTriggerEmp" onclick="toggleDatePickerPopoverEmp(event)" style="display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0; border-radius:12px; padding:9px 12px; background:#ffffff; cursor:pointer; font-size:13px; color:#475569;">
                                                <div style="display:flex; align-items:center; gap:8px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                                    <i class="fa-regular fa-calendar" style="color:#94a3b8; font-size:14px;"></i>
                                                    <span id="dateRangeLabelEmp"><?php echo ($startDate && $endDate) ? e($startDate . ' - ' . $endDate) : ($startDate ? e($startDate) : 'Pilih rentang tanggal'); ?></span>
                                                </div>
                                                <i class="fa-regular fa-circle-xmark" id="clearDateBtnEmp" style="color:#cbd5e1; font-size:14px; cursor:pointer; <?php echo ($startDate || $endDate) ? 'display:inline;' : 'display:none;'; ?>" onclick="event.stopPropagation(); clearDateRangeEmp();"></i>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ACCORDION 2: WILAYAH / KOTA -->
                                    <div style="border-bottom:1px solid #f1f5f9;">
                                        <div onclick="toggleAccordionEmp('city')" style="display:flex; justify-content:space-between; align-items:center; padding:14px 18px; cursor:pointer; user-select:none;">
                                            <span style="font-size:13.5px; font-weight:700; color:#334155;">Wilayah / Kota</span>
                                            <i class="fa-solid fa-chevron-down" id="cityChevronEmp" style="font-size:11px; color:#94a3b8; transition:transform 0.2s; <?php echo $cityFilter ? 'transform:rotate(180deg);' : ''; ?>"></i>
                                        </div>
                                        <div id="cityAccordionBodyEmp" style="display:<?php echo $cityFilter ? 'block' : 'none'; ?>; padding:0 18px 14px 18px; position:relative;">
                                            <div id="citySelectTriggerEmp" onclick="toggleCityDropdownEmp(event)" style="display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0; border-radius:12px; padding:9px 12px; background:#ffffff; cursor:pointer; font-size:13px; color:#475569;">
                                                <span id="citySelectLabelEmp" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo $cityFilter ? e($cityFilter) : 'Pilih kota...'; ?></span>
                                                <i class="fa-solid fa-chevron-down" style="color:#94a3b8; font-size:11px;"></i>
                                            </div>

                                            <!-- CITY SEARCHABLE DROPDOWN EMP -->
                                            <div id="cityDropdownListCardEmp" style="display:none; position:absolute; left:18px; right:18px; top:calc(100% + 4px); background:#ffffff; border:1px solid #00a8e8; border-radius:14px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.12); z-index:1005; padding:8px;">
                                                <input type="text" id="citySearchInputEmp" onkeyup="filterCityOptionsEmp()" placeholder="Cari kota..." style="width:100%; border:1px solid #00a8e8; border-radius:10px; padding:8px 12px; font-size:13px; outline:none; margin-bottom:6px; box-sizing:border-box;">
                                                <div id="cityOptionsContainerEmp" style="max-height:220px; overflow-y:auto;"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ACCORDION 3: VERIFIKATOR -->
                                    <div style="border-bottom:1px solid #f1f5f9;">
                                        <div onclick="toggleAccordionEmp('verifier')" style="display:flex; justify-content:space-between; align-items:center; padding:14px 18px; cursor:pointer; user-select:none;">
                                            <span style="font-size:13.5px; font-weight:700; color:#334155;">Verifikator</span>
                                            <i class="fa-solid fa-chevron-down" id="verifierChevronEmp" style="font-size:11px; color:#94a3b8; transition:transform 0.2s; <?php echo $verifierFilter ? 'transform:rotate(180deg);' : ''; ?>"></i>
                                        </div>
                                        <div id="verifierAccordionBodyEmp" style="display:<?php echo $verifierFilter ? 'block' : 'none'; ?>; padding:0 18px 14px 18px; position:relative;">
                                            <div id="verifierSelectTriggerEmp" onclick="toggleVerifierDropdownEmp(event)" style="display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0; border-radius:12px; padding:9px 12px; background:#ffffff; cursor:pointer; font-size:13px; color:#475569;">
                                                <span id="verifierSelectLabelEmp" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo $verifierFilter ? e($verifierFilter) : 'Pilih verifikator...'; ?></span>
                                                <i class="fa-solid fa-chevron-down" style="color:#94a3b8; font-size:11px;"></i>
                                            </div>

                                            <!-- VERIFIER SEARCHABLE DROPDOWN EMP -->
                                            <div id="verifierDropdownListCardEmp" style="display:none; position:absolute; left:18px; right:18px; top:calc(100% + 4px); background:#ffffff; border:1px solid #00a8e8; border-radius:14px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.12); z-index:1005; padding:8px;">
                                                <input type="text" id="verifierSearchInputEmp" onkeyup="filterVerifierOptionsEmp()" placeholder="Cari verifikator..." style="width:100%; border:1px solid #00a8e8; border-radius:10px; padding:8px 12px; font-size:13px; outline:none; margin-bottom:6px; box-sizing:border-box;">
                                                <div id="verifierOptionsContainerEmp" style="max-height:220px; overflow-y:auto;"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ACCORDION 4: PETUGAS PEMERIKSA -->
                                    <div style="border-bottom:1px solid #f1f5f9;">
                                        <div onclick="toggleAccordionEmp('officer')" style="display:flex; justify-content:space-between; align-items:center; padding:14px 18px; cursor:pointer; user-select:none;">
                                            <span style="font-size:13.5px; font-weight:700; color:#334155;">Petugas Pemeriksa</span>
                                            <i class="fa-solid fa-chevron-down" id="officerChevronEmp" style="font-size:11px; color:#94a3b8; transition:transform 0.2s; <?php echo $officerFilter ? 'transform:rotate(180deg);' : ''; ?>"></i>
                                        </div>
                                        <div id="officerAccordionBodyEmp" style="display:<?php echo $officerFilter ? 'block' : 'none'; ?>; padding:0 18px 14px 18px; position:relative;">
                                            <div id="officerSelectTriggerEmp" onclick="toggleOfficerDropdownEmp(event)" style="display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0; border-radius:12px; padding:9px 12px; background:#ffffff; cursor:pointer; font-size:13px; color:#475569;">
                                                <span id="officerSelectLabelEmp" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo $officerFilter ? e($officerFilter) : 'Pilih pemeriksa...'; ?></span>
                                                <i class="fa-solid fa-chevron-down" style="color:#94a3b8; font-size:11px;"></i>
                                            </div>

                                            <!-- OFFICER SEARCHABLE DROPDOWN EMP -->
                                            <div id="officerDropdownListCardEmp" style="display:none; position:absolute; left:18px; right:18px; top:calc(100% + 4px); background:#ffffff; border:1px solid #00a8e8; border-radius:14px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.12); z-index:1005; padding:8px;">
                                                <input type="text" id="officerSearchInputEmp" onkeyup="filterOfficerOptionsEmp()" placeholder="Cari pemeriksa..." style="width:100%; border:1px solid #00a8e8; border-radius:10px; padding:8px 12px; font-size:13px; outline:none; margin-bottom:6px; box-sizing:border-box;">
                                                <div id="officerOptionsContainerEmp" style="max-height:220px; overflow-y:auto;"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ACCORDION 5: STATUS PENUGASAN -->
                                    <div>
                                        <div onclick="toggleAccordionEmp('unassigned')" style="display:flex; justify-content:space-between; align-items:center; padding:14px 18px; cursor:pointer; user-select:none;">
                                            <span style="font-size:13.5px; font-weight:700; color:#334155;">Status Penugasan</span>
                                            <i class="fa-solid fa-chevron-down" id="unassignedChevronEmp" style="font-size:11px; color:#94a3b8; transition:transform 0.2s; <?php echo $unassignedFilter ? 'transform:rotate(180deg);' : ''; ?>"></i>
                                        </div>
                                        <div id="unassignedAccordionBodyEmp" style="display:<?php echo $unassignedFilter ? 'block' : 'none'; ?>; padding:0 18px 18px 18px;">
                                            <label onclick="toggleUnassignedOptionEmp()" style="display:flex; align-items:center; gap:10px; cursor:pointer; user-select:none; font-size:13px; color:#1e293b; font-weight:500;">
                                                <div id="unassignedRadioCircleEmp" style="width:18px; height:18px; border-radius:50%; border:2px solid <?php echo $unassignedFilter ? '#00a8e8' : '#cbd5e1'; ?>; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                                    <div style="width:8px; height:8px; border-radius:50%; background:#00a8e8; display:<?php echo $unassignedFilter ? 'block' : 'none'; ?>;"></div>
                                                </div>
                                                <span>Tampilkan yang belum ada pemeriksa</span>
                                            </label>
                                        </div>
                                    </div>

                                </div>

                                <!-- DUAL MONTH DATE RANGE PICKER POPOVER EMP -->
                                <div id="datePickerPopoverEmp" onclick="event.stopPropagation();" style="display:none; position:absolute; right:0; top:calc(100% + 8px); width:540px; max-width:90vw; background:#ffffff; border:1px solid #e2e8f0; border-radius:16px; box-shadow:0 15px 35px -5px rgba(0,0,0,0.15); z-index:1010; padding:18px; box-sizing:border-box;">
                                    <!-- Header row with month/year navigation -->
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                                        <button type="button" onclick="event.stopPropagation(); prevMonthClusterEmp()" style="background:none; border:none; cursor:pointer; padding:6px 10px; color:#475569; font-size:14px;"><i class="fa-solid fa-chevron-left"></i></button>

                                        <div style="display:flex; gap:24px; align-items:center;">
                                            <div style="display:flex; gap:6px;">
                                                <select id="m1SelectEmp" onchange="renderCalendarEmp()" onclick="event.stopPropagation()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                                <select id="y1SelectEmp" onchange="renderCalendarEmp()" onclick="event.stopPropagation()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                            </div>
                                            <div style="display:flex; gap:6px;">
                                                <select id="m2SelectEmp" onchange="renderCalendarEmp()" onclick="event.stopPropagation()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                                <select id="y2SelectEmp" onchange="renderCalendarEmp()" onclick="event.stopPropagation()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                            </div>
                                        </div>

                                        <button type="button" onclick="event.stopPropagation(); nextMonthClusterEmp()" style="background:none; border:none; cursor:pointer; padding:6px 10px; color:#475569; font-size:14px;"><i class="fa-solid fa-chevron-right"></i></button>
                                    </div>

                                    <!-- Dual Month Grids -->
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:16px;">
                                        <div>
                                            <div style="display:grid; grid-template-columns:repeat(7, 1fr); text-align:center; font-size:12px; font-weight:600; color:#64748b; margin-bottom:8px;">
                                                <span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum</span><span>Sab</span><span>Min</span>
                                            </div>
                                            <div id="m1DaysGridEmp" style="display:grid; grid-template-columns:repeat(7, 1fr); gap:2px; text-align:center; font-size:12.5px;"></div>
                                        </div>
                                        <div>
                                            <div style="display:grid; grid-template-columns:repeat(7, 1fr); text-align:center; font-size:12px; font-weight:600; color:#64748b; margin-bottom:8px;">
                                                <span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum</span><span>Sab</span><span>Min</span>
                                            </div>
                                            <div id="m2DaysGridEmp" style="display:grid; grid-template-columns:repeat(7, 1fr); gap:2px; text-align:center; font-size:12.5px;"></div>
                                        </div>
                                    </div>

                                    <!-- Footer Buttons -->
                                    <div style="display:flex; flex-direction:column; gap:8px;">
                                        <button type="button" onclick="applyDatePickerSelectionEmp()" style="width:100%; background:#00a8e8; border:none; border-radius:10px; padding:10px; color:#ffffff; font-size:13.5px; font-weight:700; cursor:pointer;">Simpan</button>
                                        <button type="button" onclick="resetDatePickerSelectionEmp()" style="width:100%; background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:10px; color:#0f172a; font-size:13.5px; font-weight:700; cursor:pointer;">Reset</button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="console-table-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; overflow-x:auto;">
                        <table class="console-table" style="width:100%; border-collapse:collapse; min-width:1000px;">
                            <thead>
                                <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0; text-align:left;">
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:200px;">
                                        <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo $sort === 'name_asc' ? 'name_desc' : 'name_asc'; ?><?php echo $filterParamsEmp; ?>" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                                            Nama Pemberi Kerja <i class="fa-solid fa-arrows-up-down" style="font-size:11px; color:#94a3b8;"></i>
                                        </a>
                                    </th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569;">Jenis Entitas</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569;">Lokasi</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569;">Telepon</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569;">Status</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569;">Deadline</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569;">Pemeriksa</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569;">
                                        <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo $sort === 'date_desc' ? 'date_asc' : 'date_desc'; ?><?php echo $filterParamsEmp; ?>" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                                            Tanggal Daftar <i class="fa-solid fa-arrow-down" style="font-size:11px; color:#64748b;"></i>
                                        </a>
                                    </th>
                                    <th style="padding:14px 16px; text-align:right;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$showingEmployers): ?>
                                    <tr><td colspan="9" style="text-align:center; padding:60px 20px; color:#64748b; font-size:13.5px;">Tidak ada data pemberi kerja.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($showingEmployers as $vEmp): ?>
                                        <tr style="border-bottom:1px solid #f1f5f9;">
                                            <td style="padding:14px 16px;">
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
                                            <td style="padding:14px 16px;"><span class="pill-badge verified"><?php echo e($vEmp['entity_type'] ?? 'Individu'); ?></span></td>
                                            <td style="padding:14px 16px; color:#334155; font-size:13px;"><?php echo e($vEmp['city'] ?: '-'); ?></td>
                                            <td style="padding:14px 16px; color:#334155; font-size:13px;"><?php echo e($vEmp['phone'] ?: '0'); ?></td>
                                            <td style="padding:14px 16px; font-size:13px; white-space:nowrap;">
                                                <?php if ($vEmp['verification_status'] === 'APPROVED'): ?>
                                                    <span class="pill-badge verified">● Terverifikasi</span>
                                                <?php elseif ($vEmp['verification_status'] === 'PENDING'): ?>
                                                    <span class="pill-badge pending">● Menunggu</span>
                                                <?php elseif (in_array($vEmp['verification_status'], ['NEEDS_REVISION', 'REVISION'])): ?>
                                                    <?php $revCount = max(1, min(3, (int)($vEmp['revision_count'] ?? $vEmp['rejection_count'] ?? 1))); ?>
                                                    <span class="pill-badge revision">● Revisi Diminta (ke-<?php echo $revCount; ?>)</span>
                                                <?php elseif ($vEmp['verification_status'] === 'REJECTED'): ?>
                                                    <span class="pill-badge danger">● Ditolak</span>
                                                <?php else: ?>
                                                    <span class="pill-badge revision">● <?php echo e($vEmp['verification_status']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:14px 16px; color:#64748b; font-size:13px;">-</td>
                                            <td style="padding:14px 16px; font-size:13px;">
                                                <?php if (!empty($vEmp['assigned_to'])): ?>
                                                    <span style="font-weight:600; color:#0284c7;"><?php echo e($vEmp['assigned_to']); ?></span>
                                                <?php else: ?>
                                                    <span style="color:#94a3b8;">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:14px 16px; color:#64748b; font-size:12.5px; white-space:nowrap;"><?php echo date('d M Y, H:i', strtotime($vEmp['created_at'])); ?></td>
                                            <td style="padding:14px 16px; text-align:right; white-space:nowrap;">
                                                <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&detail_id=<?php echo $vEmp['user_id']; ?>" class="btn-lihat-detail">
                                                    Lihat Detail
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <?php if ($showingEmployers): ?>
                            <div class="console-table-footer" style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; font-size:13px; color:#64748b; border-top:1px solid #e2e8f0;">
                                <div>
                                    Menampilkan <?php echo count($showingEmployers); ?> dari <?php echo $totalData; ?> total data.
                                </div>
                                <div style="display:flex; align-items:center; gap:4px;">
                                    <?php if ($page > 1): ?>
                                        <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?>&page=<?php echo $page - 1; ?><?php echo $filterParamsEmp; ?>" style="padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; border:1px solid #cbd5e1; font-weight:600;">‹</a>
                                    <?php else: ?>
                                        <span style="padding:6px 12px; border-radius:6px; color:#cbd5e1; border:1px solid #e2e8f0; font-weight:600; cursor:not-allowed;">‹</span>
                                    <?php endif; ?>

                                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                        <?php if ($p == $page): ?>
                                            <span style="padding:6px 12px; border-radius:6px; background:#0284c7; color:#fff; font-weight:700; border:1px solid #0284c7;"><?php echo $p; ?></span>
                                        <?php else: ?>
                                            <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?>&page=<?php echo $p; ?><?php echo $filterParamsEmp; ?>" style="padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; border:1px solid #cbd5e1; font-weight:600;"><?php echo $p; ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>

                                    <?php if ($page < $totalPages): ?>
                                        <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?>&page=<?php echo $page + 1; ?><?php echo $filterParamsEmp; ?>" style="padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; border:1px solid #cbd5e1; font-weight:600;">›</a>
                                    <?php else: ?>
                                        <span style="padding:6px 12px; border-radius:6px; color:#cbd5e1; border:1px solid #e2e8f0; font-weight:600; cursor:not-allowed;">›</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <script>
                    const VERIFIER_LIST_EMP = [
                        "A. Dimas, Se",
                        "A. Fajar Wahyu",
                        "A. RAHMAT FAJAR",
                        "A.a. Putra Wirasanjaya",
                        "ABD Halim",
                        "ABD. WAHAB, S.Pd",
                        "ABDUL BASYIR",
                        "ABDUL HAMID TUASALAMONY",
                        "ABDUL SALAM LAUMA, S.Sos",
                        "ACHMAD RAJA NASUTION"
                    ];


                    function toggleFilterPopoverEmp(e) {
                        if (e) e.stopPropagation();
                        const popover = document.getElementById('filterPopoverEmp');
                        if (!popover) return;
                        const isVisible = popover.style.display === 'block';
                        popover.style.display = isVisible ? 'none' : 'block';
                        if (!isVisible) {
                            populateCityOptionsEmp();
                            populateVerifierOptionsEmp();
                            populateOfficerOptionsEmp();
                        }
                    }

                    function toggleAccordionEmp(type) {
                        const bodyMap = {
                            'date': 'dateAccordionBodyEmp',
                            'city': 'cityAccordionBodyEmp',
                            'verifier': 'verifierAccordionBodyEmp',
                            'officer': 'officerAccordionBodyEmp',
                            'unassigned': 'unassignedAccordionBodyEmp'
                        };
                        const chevMap = {
                            'date': 'dateChevronEmp',
                            'city': 'cityChevronEmp',
                            'verifier': 'verifierChevronEmp',
                            'officer': 'officerChevronEmp',
                            'unassigned': 'unassignedChevronEmp'
                        };

                        const targetId = bodyMap[type];
                        const targetChevId = chevMap[type];
                        if (!targetId) return;

                        const targetBody = document.getElementById(targetId);
                        const targetChev = document.getElementById(targetChevId);
                        if (!targetBody) return;

                        const isHidden = targetBody.style.display === 'none';
                        targetBody.style.display = isHidden ? 'block' : 'none';
                        if (targetChev) {
                            targetChev.style.transform = isHidden ? 'rotate(180deg)' : 'rotate(0deg)';
                        }

                        if (type === 'city' && isHidden) {
                            populateCityOptionsEmp();
                        } else if (type === 'verifier' && isHidden) {
                            populateVerifierOptionsEmp();
                        } else if (type === 'officer' && isHidden) {
                            populateOfficerOptionsEmp();
                        }
                    }

                    function toggleCityDropdownEmp(e) {
                        if (e) e.stopPropagation();
                        const card = document.getElementById('cityDropdownListCardEmp');
                        if (!card) return;
                        const isHidden = card.style.display === 'none';
                        card.style.display = isHidden ? 'block' : 'none';
                        if (isHidden) {
                            populateCityOptionsEmp();
                            setTimeout(() => {
                                const input = document.getElementById('citySearchInputEmp');
                                if (input) input.focus();
                            }, 50);
                        }
                    }

                    function populateCityOptionsEmp() {
                        const container = document.getElementById('cityOptionsContainerEmp');
                        if (!container || container.children.length > 0) return;
                        renderCityListEmp(typeof CITY_MASTER !== 'undefined' ? CITY_MASTER : []);
                    }

                    function renderCityListEmp(list) {
                        const container = document.getElementById('cityOptionsContainerEmp');
                        if (!container) return;
                        container.innerHTML = '';
                        const currentVal = document.getElementById('inputCityFilterEmp') ? document.getElementById('inputCityFilterEmp').value : '';

                        list.forEach(city => {
                            const item = document.createElement('div');
                            const isSelected = city === currentVal;
                            item.style.cssText = `padding:8px 12px; font-size:13px; color:#1e293b; border-radius:8px; cursor:pointer; background:${isSelected ? '#f0f9ff' : 'transparent'}; font-weight:${isSelected ? '700' : 'normal'}; transition:background 0.15s;`;
                            item.textContent = city;
                            item.onmouseover = () => { if (!isSelected) item.style.background = '#f8fafc'; };
                            item.onmouseout = () => { if (!isSelected) item.style.background = 'transparent'; };
                            item.onclick = (e) => {
                                e.stopPropagation();
                                selectCityEmp(city);
                            };
                            container.appendChild(item);
                        });
                    }

                    function filterCityOptionsEmp() {
                        const input = document.getElementById('citySearchInputEmp');
                        const query = (input ? input.value : '').toLowerCase().trim();
                        const masterList = typeof CITY_MASTER !== 'undefined' ? CITY_MASTER : [];
                        const filtered = masterList.filter(c => c.toLowerCase().includes(query));
                        renderCityListEmp(filtered);
                    }

                    function selectCityEmp(city) {
                        const input = document.getElementById('inputCityFilterEmp');
                        if (input) input.value = city;
                        const label = document.getElementById('citySelectLabelEmp');
                        if (label) label.textContent = city;
                        const card = document.getElementById('cityDropdownListCardEmp');
                        if (card) card.style.display = 'none';
                        const form = document.getElementById('filterMainFormEmp');
                        if (form) form.submit();
                    }

                    function toggleVerifierDropdownEmp(e) {
                        if (e) e.stopPropagation();
                        const card = document.getElementById('verifierDropdownListCardEmp');
                        if (!card) return;
                        const isHidden = card.style.display === 'none';
                        card.style.display = isHidden ? 'block' : 'none';
                        if (isHidden) {
                            populateVerifierOptionsEmp();
                            setTimeout(() => {
                                const input = document.getElementById('verifierSearchInputEmp');
                                if (input) input.focus();
                            }, 50);
                        }
                    }

                    function populateVerifierOptionsEmp() {
                        const container = document.getElementById('verifierOptionsContainerEmp');
                        if (!container || container.children.length > 0) return;
                        renderVerifierListEmp(VERIFIER_LIST_EMP);
                    }

                    function renderVerifierListEmp(list) {
                        const container = document.getElementById('verifierOptionsContainerEmp');
                        if (!container) return;
                        container.innerHTML = '';
                        const currentVal = document.getElementById('inputVerifierFilterEmp') ? document.getElementById('inputVerifierFilterEmp').value : '';

                        list.forEach(name => {
                            const item = document.createElement('div');
                            const isSelected = name === currentVal;
                            item.style.cssText = `padding:8px 12px; font-size:13px; color:#1e293b; border-radius:8px; cursor:pointer; background:${isSelected ? '#f0f9ff' : 'transparent'}; font-weight:${isSelected ? '700' : 'normal'}; transition:background 0.15s;`;
                            item.textContent = name;
                            item.onmouseover = () => { if (!isSelected) item.style.background = '#f8fafc'; };
                            item.onmouseout = () => { if (!isSelected) item.style.background = 'transparent'; };
                            item.onclick = (e) => {
                                e.stopPropagation();
                                selectVerifierEmp(name);
                            };
                            container.appendChild(item);
                        });
                    }

                    function filterVerifierOptionsEmp() {
                        const input = document.getElementById('verifierSearchInputEmp');
                        const query = (input ? input.value : '').toLowerCase().trim();
                        const filtered = VERIFIER_LIST_EMP.filter(n => n.toLowerCase().includes(query));
                        renderVerifierListEmp(filtered);
                    }

                    function selectVerifierEmp(name) {
                        const input = document.getElementById('inputVerifierFilterEmp');
                        if (input) input.value = name;
                        const label = document.getElementById('verifierSelectLabelEmp');
                        if (label) label.textContent = name;
                        const card = document.getElementById('verifierDropdownListCardEmp');
                        if (card) card.style.display = 'none';
                        const form = document.getElementById('filterMainFormEmp');
                        if (form) form.submit();
                    }

                    function toggleOfficerDropdownEmp(e) {
                        if (e) e.stopPropagation();
                        const card = document.getElementById('officerDropdownListCardEmp');
                        if (!card) return;
                        const isHidden = card.style.display === 'none';
                        card.style.display = isHidden ? 'block' : 'none';
                        if (isHidden) {
                            populateOfficerOptionsEmp();
                            setTimeout(() => {
                                const input = document.getElementById('officerSearchInputEmp');
                                if (input) input.focus();
                            }, 50);
                        }
                    }

                    function populateOfficerOptionsEmp() {
                        const container = document.getElementById('officerOptionsContainerEmp');
                        if (!container || container.children.length > 0) return;
                        renderOfficerListEmp(VERIFIER_LIST_EMP);
                    }

                    function renderOfficerListEmp(list) {
                        const container = document.getElementById('officerOptionsContainerEmp');
                        if (!container) return;
                        container.innerHTML = '';
                        const currentVal = document.getElementById('inputOfficerFilterEmp') ? document.getElementById('inputOfficerFilterEmp').value : '';

                        list.forEach(name => {
                            const item = document.createElement('div');
                            const isSelected = name === currentVal;
                            item.style.cssText = `padding:8px 12px; font-size:13px; color:#1e293b; border-radius:8px; cursor:pointer; background:${isSelected ? '#f0f9ff' : 'transparent'}; font-weight:${isSelected ? '700' : 'normal'}; transition:background 0.15s;`;
                            item.textContent = name;
                            item.onmouseover = () => { if (!isSelected) item.style.background = '#f8fafc'; };
                            item.onmouseout = () => { if (!isSelected) item.style.background = 'transparent'; };
                            item.onclick = (e) => {
                                e.stopPropagation();
                                selectOfficerEmp(name);
                            };
                            container.appendChild(item);
                        });
                    }

                    function filterOfficerOptionsEmp() {
                        const input = document.getElementById('officerSearchInputEmp');
                        const query = (input ? input.value : '').toLowerCase().trim();
                        const filtered = VERIFIER_LIST_EMP.filter(n => n.toLowerCase().includes(query));
                        renderOfficerListEmp(filtered);
                    }

                    function selectOfficerEmp(name) {
                        const input = document.getElementById('inputOfficerFilterEmp');
                        if (input) input.value = name;
                        const label = document.getElementById('officerSelectLabelEmp');
                        if (label) label.textContent = name;
                        const card = document.getElementById('officerDropdownListCardEmp');
                        if (card) card.style.display = 'none';
                        const form = document.getElementById('filterMainFormEmp');
                        if (form) form.submit();
                    }

                    function toggleUnassignedOptionEmp() {
                        const input = document.getElementById('inputUnassignedEmp');
                        const circle = document.getElementById('unassignedRadioCircleEmp');
                        if (!input || !circle) return;

                        const isCurrentlyChecked = input.value === '1';
                        if (isCurrentlyChecked) {
                            input.value = '0';
                            circle.style.borderColor = '#cbd5e1';
                            if (circle.firstElementChild) circle.firstElementChild.style.display = 'none';
                        } else {
                            input.value = '1';
                            circle.style.borderColor = '#00a8e8';
                            if (circle.firstElementChild) circle.firstElementChild.style.display = 'block';
                        }
                        const form = document.getElementById('filterMainFormEmp');
                        if (form) form.submit();
                    }

                    /* DUAL MONTH DATE PICKER FUNCTIONS FOR EMP */
                    let selectedStartDateEmp = "<?php echo e($startDate); ?>";
                    let selectedEndDateEmp = "<?php echo e($endDate); ?>";
                    let tempStartDateEmp = selectedStartDateEmp;
                    let tempEndDateEmp = selectedEndDateEmp;
                    let currentYear1Emp = 2026, currentMonth1Emp = 8;
                    let currentYear2Emp = 2026, currentMonth2Emp = 9;

                    function toggleDatePickerPopoverEmp(e) {
                        if (e) e.stopPropagation();
                        const popover = document.getElementById('datePickerPopoverEmp');
                        if (!popover) return;
                        const isVisible = popover.style.display === 'block';
                        popover.style.display = isVisible ? 'none' : 'block';
                        if (!isVisible) {
                            initYearSelectsEmp();
                            renderCalendarEmp();
                        }
                    }

                    function initYearSelectsEmp() {
                        const y1 = document.getElementById('y1SelectEmp');
                        const y2 = document.getElementById('y2SelectEmp');
                        const m1 = document.getElementById('m1SelectEmp');
                        const m2 = document.getElementById('m2SelectEmp');
                        if (!y1 || y1.children.length > 0) return;

                        const monthList = window.MONTH_NAMES || ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agt", "Sep", "Okt", "Nov", "Des"];
                        const startY = 2020, endY = 2030;
                        for (let y = startY; y <= endY; y++) {
                            y1.add(new Option(y, y, false, y === currentYear1Emp));
                            y2.add(new Option(y, y, false, y === currentYear2Emp));
                        }
                        monthList.forEach((m, idx) => {
                            m1.add(new Option(m, idx, false, idx === currentMonth1Emp));
                            m2.add(new Option(m, idx, false, idx === currentMonth2Emp));
                        });
                    }

                    function prevMonthClusterEmp() {
                        if (currentMonth1Emp === 0) {
                            currentMonth1Emp = 11; currentYear1Emp--;
                        } else {
                            currentMonth1Emp--;
                        }
                        if (currentMonth2Emp === 0) {
                            currentMonth2Emp = 11; currentYear2Emp--;
                        } else {
                            currentMonth2Emp--;
                        }
                        updateSelectValsEmp();
                        renderCalendarEmp();
                    }

                    function nextMonthClusterEmp() {
                        if (currentMonth1Emp === 11) {
                            currentMonth1Emp = 0; currentYear1Emp++;
                        } else {
                            currentMonth1Emp++;
                        }
                        if (currentMonth2Emp === 11) {
                            currentMonth2Emp = 0; currentYear2Emp++;
                        } else {
                            currentMonth2Emp++;
                        }
                        updateSelectValsEmp();
                        renderCalendarEmp();
                    }

                    function updateSelectValsEmp() {
                        const m1 = document.getElementById('m1SelectEmp');
                        const y1 = document.getElementById('y1SelectEmp');
                        const m2 = document.getElementById('m2SelectEmp');
                        const y2 = document.getElementById('y2SelectEmp');
                        if (m1) m1.value = currentMonth1Emp;
                        if (y1) y1.value = currentYear1Emp;
                        if (m2) m2.value = currentMonth2Emp;
                        if (y2) y2.value = currentYear2Emp;
                    }

                    function renderMonthGridEmp(gridId, year, month) {
                        const grid = document.getElementById(gridId);
                        if (!grid) return;
                        grid.innerHTML = '';

                        const firstDay = new Date(year, month, 1).getDay();
                        const offset = (firstDay + 6) % 7;
                        const daysInMonth = new Date(year, month + 1, 0).getDate();
                        const prevMonthDays = new Date(year, month, 0).getDate();

                        for (let i = offset - 1; i >= 0; i--) {
                            const cell = document.createElement('div');
                            cell.style.cssText = 'padding:6px 0; color:#cbd5e1; font-weight:500;';
                            cell.textContent = prevMonthDays - i;
                            grid.appendChild(cell);
                        }

                        for (let d = 1; d <= daysInMonth; d++) {
                            const cell = document.createElement('div');
                            const mStr = String(month + 1).padStart(2, '0');
                            const dStr = String(d).padStart(2, '0');
                            const dateStr = `${year}-${mStr}-${dStr}`;

                            let bg = 'transparent';
                            let color = '#1e293b';
                            let fontWeight = '500';
                            let borderRadius = '0';

                            if (tempStartDateEmp && tempEndDateEmp) {
                                if (dateStr === tempStartDateEmp) {
                                    bg = '#00a8e8'; color = '#ffffff'; fontWeight = '700'; borderRadius = '8px 0 0 8px';
                                } else if (dateStr === tempEndDateEmp) {
                                    bg = '#00a8e8'; color = '#ffffff'; fontWeight = '700'; borderRadius = '0 8px 8px 0';
                                } else if (dateStr > tempStartDateEmp && dateStr < tempEndDateEmp) {
                                    bg = '#e0f2fe'; color = '#0284c7'; fontWeight = '600';
                                }
                            } else if (tempStartDateEmp && dateStr === tempStartDateEmp) {
                                bg = '#00a8e8'; color = '#ffffff'; fontWeight = '700'; borderRadius = '8px';
                            }

                            cell.style.cssText = `padding:6px 0; background:${bg}; color:${color}; border-radius:${borderRadius}; font-weight:${fontWeight}; cursor:pointer; font-size:12.5px; transition:all 0.15s;`;
                            cell.textContent = d;
                            cell.onclick = (e) => {
                                if (e) e.stopPropagation();
                                selectDateEmp(dateStr);
                            };
                            grid.appendChild(cell);
                        }

                        const totalCells = offset + daysInMonth;
                        const remaining = (7 - (totalCells % 7)) % 7;
                        for (let n = 1; n <= remaining; n++) {
                            const cell = document.createElement('div');
                            cell.style.cssText = 'padding:6px 0; color:#cbd5e1; font-weight:500;';
                            cell.textContent = n;
                            grid.appendChild(cell);
                        }
                    }

                    function renderCalendarEmp() {
                        const m1Sel = document.getElementById('m1SelectEmp');
                        const y1Sel = document.getElementById('y1SelectEmp');
                        const m2Sel = document.getElementById('m2SelectEmp');
                        const y2Sel = document.getElementById('y2SelectEmp');

                        if (m1Sel && y1Sel && m2Sel && y2Sel) {
                            currentMonth1Emp = parseInt(m1Sel.value);
                            currentYear1Emp = parseInt(y1Sel.value);
                            currentMonth2Emp = parseInt(m2Sel.value);
                            currentYear2Emp = parseInt(y2Sel.value);
                        }

                        renderMonthGridEmp('m1DaysGridEmp', currentYear1Emp, currentMonth1Emp);
                        renderMonthGridEmp('m2DaysGridEmp', currentYear2Emp, currentMonth2Emp);
                    }

                    function selectDateEmp(dateStr) {
                        if (!tempStartDateEmp || (tempStartDateEmp && tempEndDateEmp)) {
                            tempStartDateEmp = dateStr;
                            tempEndDateEmp = '';
                        } else if (tempStartDateEmp && !tempEndDateEmp) {
                            if (dateStr >= tempStartDateEmp) {
                                tempEndDateEmp = dateStr;
                            } else {
                                tempEndDateEmp = tempStartDateEmp;
                                tempStartDateEmp = dateStr;
                            }
                        }
                        renderCalendarEmp();
                    }

                    function applyDatePickerSelectionEmp() {
                        selectedStartDateEmp = tempStartDateEmp;
                        selectedEndDateEmp = tempEndDateEmp;
                        const inputStart = document.getElementById('inputStartDateEmp');
                        const inputEnd = document.getElementById('inputEndDateEmp');
                        if (inputStart) inputStart.value = selectedStartDateEmp;
                        if (inputEnd) inputEnd.value = selectedEndDateEmp;

                        const label = document.getElementById('dateRangeLabelEmp');
                        const clearBtn = document.getElementById('clearDateBtnEmp');

                        if (selectedStartDateEmp && selectedEndDateEmp) {
                            if (label) label.textContent = `${selectedStartDateEmp} - ${selectedEndDateEmp}`;
                            if (clearBtn) clearBtn.style.display = 'inline';
                        } else if (selectedStartDateEmp) {
                            if (label) label.textContent = selectedStartDateEmp;
                            if (clearBtn) clearBtn.style.display = 'inline';
                        } else {
                            if (label) label.textContent = 'Pilih rentang tanggal';
                            if (clearBtn) clearBtn.style.display = 'none';
                        }

                        const picker = document.getElementById('datePickerPopoverEmp');
                        if (picker) picker.style.display = 'none';
                        const form = document.getElementById('filterMainFormEmp');
                        if (form) form.submit();
                    }

                    function resetDatePickerSelectionEmp() {
                        tempStartDateEmp = '';
                        tempEndDateEmp = '';
                        selectedStartDateEmp = '';
                        selectedEndDateEmp = '';
                        const inputStart = document.getElementById('inputStartDateEmp');
                        const inputEnd = document.getElementById('inputEndDateEmp');
                        if (inputStart) inputStart.value = '';
                        if (inputEnd) inputEnd.value = '';
                        const label = document.getElementById('dateRangeLabelEmp');
                        const clearBtn = document.getElementById('clearDateBtnEmp');
                        if (label) label.textContent = 'Pilih rentang tanggal';
                        if (clearBtn) clearBtn.style.display = 'none';
                        renderCalendarEmp();
                        const form = document.getElementById('filterMainFormEmp');
                        if (form) form.submit();
                    }

                    function clearDateRangeEmp() {
                        resetDatePickerSelectionEmp();
                    }
                    </script>
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
                    <!-- VERIFIKASI LOWONGAN TABLE VIEW (MATCHING EXISTING KARIRHUB CONSOLE) -->
                    <?php
                    $filterParamsJob = ($startDate ? '&start_date=' . urlencode($startDate) : '') . ($endDate ? '&end_date=' . urlencode($endDate) : '') . ($cityFilter ? '&city_filter=' . urlencode($cityFilter) : '');
                    ?>
                    <div style="margin-bottom:20px;">
                        <h1 style="font-size:24px; font-weight:800; margin:0 0 16px 0; color:#0f172a;">Verifikasi Lowongan</h1>

                        <!-- STATUS TAB LIST -->
                        <div style="border-bottom:1px solid #e2e8f0; margin-bottom:16px;">
                            <div class="status-tab-list" style="gap:24px;">
                                <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=all&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParamsJob; ?>" class="status-tab-item <?php echo $tab === 'all' ? 'active' : ''; ?>">Semua</a>
                                <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=process&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParamsJob; ?>" class="status-tab-item <?php echo $tab === 'process' ? 'active' : ''; ?>">Menunggu Verifikasi</a>
                                <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=revision&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParamsJob; ?>" class="status-tab-item <?php echo $tab === 'revision' ? 'active' : ''; ?>">Revisi</a>
                                <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=approved&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParamsJob; ?>" class="status-tab-item <?php echo $tab === 'approved' ? 'active' : ''; ?>">Disetujui</a>
                                <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=rejected&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParamsJob; ?>" class="status-tab-item <?php echo $tab === 'rejected' ? 'active' : ''; ?>">Ditolak</a>
                            </div>
                        </div>

                        <!-- FILTER MAIN FORM JOB -->
                        <form method="get" action="admin.php" id="filterMainFormJob" style="display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:16px; width:100%; position:relative;">
                            <input type="hidden" name="view" value="verifikasi_job">
                            <input type="hidden" name="tab" value="<?php echo e($tab); ?>">
                            <input type="hidden" name="entity" id="inputEntityJob" value="<?php echo e($entity); ?>">
                            <?php if ($sort): ?><input type="hidden" name="sort" value="<?php echo e($sort); ?>"><?php endif; ?>
                            <input type="hidden" name="start_date" id="inputStartDateJob" value="<?php echo e($startDate); ?>">
                            <input type="hidden" name="end_date" id="inputEndDateJob" value="<?php echo e($endDate); ?>">
                            <input type="hidden" name="city_filter" id="inputCityFilterJob" value="<?php echo e($cityFilter); ?>">

                            <!-- LEFT CONTAINER: SEARCH BOX & SEGMENTED PILL FILTER -->
                            <div style="display:flex; align-items:center; gap:12px;">
                                <!-- SEARCH BOX -->
                                <div class="filter-search-box" style="width:280px; border-radius:999px; height:38px;">
                                    <i class="fa-solid fa-magnifying-glass" style="color:#94a3b8; font-size:13px;"></i>
                                    <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Cari lowongan...">
                                </div>

                                <!-- SEGMENTED PILL FILTER: Semua | Perusahaan | Individual -->
                                <div style="display:inline-flex; background:#f1f5f9; border-radius:999px; padding:3px; gap:2px;">
                                    <a href="admin.php?view=verifikasi_job&entity=Semua&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?><?php echo $filterParamsJob; ?>" style="padding:6px 16px; border-radius:999px; font-size:13px; font-weight:600; text-decoration:none; <?php echo ($entity === 'Semua' || !$entity) ? 'background:#ffffff; color:#0f172a; box-shadow:0 1px 3px rgba(0,0,0,0.1);' : 'color:#64748b;'; ?>">Semua</a>
                                    <a href="admin.php?view=verifikasi_job&entity=Perusahaan&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?><?php echo $filterParamsJob; ?>" style="padding:6px 16px; border-radius:999px; font-size:13px; font-weight:600; text-decoration:none; <?php echo $entity === 'Perusahaan' ? 'background:#ffffff; color:#0f172a; box-shadow:0 1px 3px rgba(0,0,0,0.1);' : 'color:#64748b;'; ?>">Perusahaan</a>
                                    <a href="admin.php?view=verifikasi_job&entity=Individu&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?><?php echo $filterParamsJob; ?>" style="padding:6px 16px; border-radius:999px; font-size:13px; font-weight:600; text-decoration:none; <?php echo ($entity === 'Individu' || $entity === 'Individual') ? 'background:#ffffff; color:#0f172a; box-shadow:0 1px 3px rgba(0,0,0,0.1);' : 'color:#64748b;'; ?>">Individual</a>
                                </div>
                            </div>

                            <!-- FILTER BUTTON & POPOVER -->
                            <div style="position:relative;">
                                <button type="button" class="filter-btn" id="filterToggleBtnJob" onclick="toggleFilterPopoverJob(event)" style="display:inline-flex; align-items:center; gap:6px; background:#ffffff; border:1px solid #cbd5e1; border-radius:10px; padding:7px 16px; font-size:13px; font-weight:600; color:#334155; cursor:pointer;">
                                    <i class="fa-solid fa-sliders" style="font-size:12px;"></i> Filter
                                    <?php if ($startDate || $endDate || $cityFilter): ?>
                                        <span style="background:#0284c7; color:#fff; font-size:10px; border-radius:999px; padding:1px 6px; margin-left:2px;">●</span>
                                    <?php endif; ?>
                                </button>

                                <!-- FILTER POPOVER CARD JOB -->
                                <div id="filterPopoverJob" style="display:none; position:absolute; right:0; top:calc(100% + 8px); width:280px; background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.05); z-index:1000; overflow:visible;">

                                    <!-- ACCORDION 1: TANGGAL PENGAJUAN -->
                                    <div style="border-bottom:1px solid #f1f5f9;">
                                        <div onclick="toggleAccordionJob('date')" style="display:flex; justify-content:space-between; align-items:center; padding:14px 18px; cursor:pointer; user-select:none;">
                                            <span style="font-size:13.5px; font-weight:700; color:#0f172a;">Tanggal Pengajuan</span>
                                            <i class="fa-solid fa-chevron-down" id="dateChevronJob" style="font-size:11px; color:#64748b; transition:transform 0.2s; <?php echo ($startDate || $endDate) ? 'transform:rotate(180deg);' : ''; ?>"></i>
                                        </div>
                                        <div id="dateAccordionBodyJob" style="display:<?php echo ($startDate || $endDate) ? 'block' : 'none'; ?>; padding:0 18px 14px 18px;">
                                            <div id="dateRangeTriggerJob" onclick="toggleDatePickerPopoverJob(event)" style="display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0; border-radius:12px; padding:9px 12px; background:#ffffff; cursor:pointer; font-size:13px; color:#475569;">
                                                <div style="display:flex; align-items:center; gap:8px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                                    <i class="fa-regular fa-calendar" style="color:#94a3b8; font-size:14px;"></i>
                                                    <span id="dateRangeLabelJob"><?php echo ($startDate && $endDate) ? e($startDate . ' - ' . $endDate) : ($startDate ? e($startDate) : 'Pilih rentang tanggal'); ?></span>
                                                </div>
                                                <i class="fa-regular fa-circle-xmark" id="clearDateBtnJob" style="color:#cbd5e1; font-size:14px; cursor:pointer; <?php echo ($startDate || $endDate) ? 'display:inline;' : 'display:none;'; ?>" onclick="event.stopPropagation(); clearDateRangeJob();"></i>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ACCORDION 2: WILAYAH / KOTA -->
                                    <div>
                                        <div onclick="toggleAccordionJob('city')" style="display:flex; justify-content:space-between; align-items:center; padding:14px 18px; cursor:pointer; user-select:none;">
                                            <span style="font-size:13.5px; font-weight:700; color:#0f172a;">Wilayah / Kota</span>
                                            <i class="fa-solid fa-chevron-down" id="cityChevronJob" style="font-size:11px; color:#64748b; transition:transform 0.2s; <?php echo $cityFilter ? 'transform:rotate(180deg);' : ''; ?>"></i>
                                        </div>
                                        <div id="cityAccordionBodyJob" style="display:<?php echo $cityFilter ? 'block' : 'none'; ?>; padding:0 18px 14px 18px; position:relative;">
                                            <div id="citySelectTriggerJob" onclick="toggleCityDropdownJob(event)" style="display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0; border-radius:12px; padding:9px 12px; background:#ffffff; cursor:pointer; font-size:13px; color:#475569;">
                                                <span id="citySelectLabelJob" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo $cityFilter ? e($cityFilter) : 'Pilih kota...'; ?></span>
                                                <i class="fa-solid fa-chevron-down" style="color:#94a3b8; font-size:11px;"></i>
                                            </div>

                                            <!-- CITY SEARCHABLE DROPDOWN JOB -->
                                            <div id="cityDropdownListCardJob" style="display:none; position:absolute; left:18px; right:18px; top:calc(100% + 4px); background:#ffffff; border:1px solid #00a8e8; border-radius:14px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.12); z-index:1005; padding:8px;">
                                                <input type="text" id="citySearchInputJob" onkeyup="filterCityOptionsJob()" placeholder="Cari kota..." style="width:100%; border:1px solid #00a8e8; border-radius:10px; padding:8px 12px; font-size:13px; outline:none; margin-bottom:6px; box-sizing:border-box;">
                                                <div id="cityOptionsContainerJob" style="max-height:220px; overflow-y:auto;"></div>
                                            </div>
                                        </div>
                                    </div>

                                </div>

                                <!-- DUAL MONTH DATE RANGE PICKER POPOVER JOB -->
                                <div id="datePickerPopoverJob" onclick="event.stopPropagation();" style="display:none; position:absolute; right:0; top:calc(100% + 8px); width:540px; max-width:90vw; background:#ffffff; border:1px solid #e2e8f0; border-radius:16px; box-shadow:0 15px 35px -5px rgba(0,0,0,0.15); z-index:1010; padding:18px; box-sizing:border-box;">
                                    <!-- Header row with month/year navigation -->
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                                        <button type="button" onclick="event.stopPropagation(); prevMonthClusterJob()" style="background:none; border:none; cursor:pointer; padding:6px 10px; color:#475569; font-size:14px;"><i class="fa-solid fa-chevron-left"></i></button>

                                        <div style="display:flex; gap:24px; align-items:center;">
                                            <div style="display:flex; gap:6px;">
                                                <select id="m1SelectJob" onchange="renderCalendarJob()" onclick="event.stopPropagation()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                                <select id="y1SelectJob" onchange="renderCalendarJob()" onclick="event.stopPropagation()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                            </div>
                                            <div style="display:flex; gap:6px;">
                                                <select id="m2SelectJob" onchange="renderCalendarJob()" onclick="event.stopPropagation()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                                <select id="y2SelectJob" onchange="renderCalendarJob()" onclick="event.stopPropagation()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                            </div>
                                        </div>

                                        <button type="button" onclick="event.stopPropagation(); nextMonthClusterJob()" style="background:none; border:none; cursor:pointer; padding:6px 10px; color:#475569; font-size:14px;"><i class="fa-solid fa-chevron-right"></i></button>
                                    </div>

                                    <!-- Dual Month Grids -->
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:16px;">
                                        <div>
                                            <div style="display:grid; grid-template-columns:repeat(7, 1fr); text-align:center; font-size:12px; font-weight:600; color:#64748b; margin-bottom:8px;">
                                                <span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum</span><span>Sab</span><span>Min</span>
                                            </div>
                                            <div id="m1DaysGridJob" style="display:grid; grid-template-columns:repeat(7, 1fr); gap:2px; text-align:center; font-size:12.5px;"></div>
                                        </div>
                                        <div>
                                            <div style="display:grid; grid-template-columns:repeat(7, 1fr); text-align:center; font-size:12px; font-weight:600; color:#64748b; margin-bottom:8px;">
                                                <span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum</span><span>Sab</span><span>Min</span>
                                            </div>
                                            <div id="m2DaysGridJob" style="display:grid; grid-template-columns:repeat(7, 1fr); gap:2px; text-align:center; font-size:12.5px;"></div>
                                        </div>
                                    </div>

                                    <!-- Footer Buttons -->
                                    <div style="display:flex; flex-direction:column; gap:8px;">
                                        <button type="button" onclick="applyDatePickerSelectionJob()" style="width:100%; background:#00a8e8; border:none; border-radius:10px; padding:10px; color:#ffffff; font-size:13.5px; font-weight:700; cursor:pointer;">Simpan</button>
                                        <button type="button" onclick="resetDatePickerSelectionJob()" style="width:100%; background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:10px; color:#0f172a; font-size:13.5px; font-weight:700; cursor:pointer;">Reset</button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>

                    <script>
                    const CITY_MASTER = window.CITY_MASTER || [];
                    const MONTH_NAMES = window.MONTH_NAMES || ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agt", "Sep", "Okt", "Nov", "Des"];

                    let selectedStartDateJob = "<?php echo e($startDate); ?>";
                    let selectedEndDateJob = "<?php echo e($endDate); ?>";
                    let tempStartDateJob = selectedStartDateJob;
                    let tempEndDateJob = selectedEndDateJob;
                    let currentYear1Job = 2026, currentMonth1Job = 8;
                    let currentYear2Job = 2026, currentMonth2Job = 9;

                    if (selectedStartDateJob) {
                        const parts = selectedStartDateJob.split('-');
                        if (parts.length === 3) {
                            currentYear1Job = parseInt(parts[0]);
                            currentMonth1Job = parseInt(parts[1]) - 1;
                            currentMonth2Job = (currentMonth1Job + 1) % 12;
                            currentYear2Job = currentMonth1Job === 11 ? currentYear1Job + 1 : currentYear1Job;
                        }
                    }

                    function toggleFilterPopoverJob(e) {
                        if (e) e.stopPropagation();
                        const popover = document.getElementById('filterPopoverJob');
                        if (!popover) return;
                        if (popover.style.display === 'none' || !popover.style.display) {
                            popover.style.display = 'block';
                        } else {
                            popover.style.display = 'none';
                            if (document.getElementById('datePickerPopoverJob')) document.getElementById('datePickerPopoverJob').style.display = 'none';
                            if (document.getElementById('cityDropdownListCardJob')) document.getElementById('cityDropdownListCardJob').style.display = 'none';
                        }
                    }

                    function toggleAccordionJob(type) {
                        if (type === 'date') {
                            const body = document.getElementById('dateAccordionBodyJob');
                            const chevron = document.getElementById('dateChevronJob');
                            if (body.style.display === 'none' || !body.style.display) {
                                body.style.display = 'block';
                                chevron.style.transform = 'rotate(180deg)';
                            } else {
                                body.style.display = 'none';
                                chevron.style.transform = 'rotate(0deg)';
                                if (document.getElementById('datePickerPopoverJob')) document.getElementById('datePickerPopoverJob').style.display = 'none';
                            }
                        } else if (type === 'city') {
                            const body = document.getElementById('cityAccordionBodyJob');
                            const chevron = document.getElementById('cityChevronJob');
                            if (body.style.display === 'none' || !body.style.display) {
                                body.style.display = 'block';
                                chevron.style.transform = 'rotate(180deg)';
                                populateCityOptionsJob();
                            } else {
                                body.style.display = 'none';
                                chevron.style.transform = 'rotate(0deg)';
                                if (document.getElementById('cityDropdownListCardJob')) document.getElementById('cityDropdownListCardJob').style.display = 'none';
                            }
                        }
                    }

                    function toggleDatePickerPopoverJob(e) {
                        if (e) e.stopPropagation();
                        const picker = document.getElementById('datePickerPopoverJob');
                        if (!picker) return;
                        if (picker.style.display === 'none' || !picker.style.display) {
                            picker.style.display = 'block';
                            initMonthYearSelectsJob();
                            renderCalendarJob();
                        } else {
                            picker.style.display = 'none';
                        }
                    }

                    function toggleCityDropdownJob(e) {
                        if (e) e.stopPropagation();
                        const card = document.getElementById('cityDropdownListCardJob');
                        if (!card) return;
                        if (card.style.display === 'none' || !card.style.display) {
                            card.style.display = 'block';
                            populateCityOptionsJob();
                            setTimeout(() => {
                                const input = document.getElementById('citySearchInputJob');
                                if (input) input.focus();
                            }, 50);
                        } else {
                            card.style.display = 'none';
                        }
                    }

                    function populateCityOptionsJob(filter = '') {
                        const container = document.getElementById('cityOptionsContainerJob');
                        if (!container) return;
                        container.innerHTML = '';

                        const filterLower = filter.toLowerCase();
                        const masterList = (typeof CITY_MASTER !== 'undefined') ? CITY_MASTER : [];
                        const filtered = masterList.filter(c => c.toLowerCase().includes(filterLower));

                        if (filtered.length === 0) {
                            container.innerHTML = '<div style="padding:10px; font-size:12.5px; color:#94a3b8; text-align:center;">Kota tidak ditemukan</div>';
                            return;
                        }

                        filtered.forEach(city => {
                            const item = document.createElement('div');
                            item.style.cssText = 'padding:8px 12px; font-size:13px; color:#334155; cursor:pointer; border-radius:8px; font-weight:500;';
                            item.textContent = city;
                            item.onmouseover = () => item.style.background = '#f1f5f9';
                            item.onmouseout = () => item.style.background = 'transparent';
                            item.onclick = (e) => {
                                e.stopPropagation();
                                selectCityJob(city);
                            };
                            container.appendChild(item);
                        });
                    }

                    function filterCityOptionsJob() {
                        const val = document.getElementById('citySearchInputJob').value;
                        populateCityOptionsJob(val);
                    }

                    function selectCityJob(cityName) {
                        document.getElementById('inputCityFilterJob').value = cityName;
                        document.getElementById('citySelectLabelJob').textContent = cityName;
                        document.getElementById('cityDropdownListCardJob').style.display = 'none';
                        document.getElementById('filterMainFormJob').submit();
                    }

                    function initMonthYearSelectsJob() {
                        const m1Sel = document.getElementById('m1SelectJob');
                        const m2Sel = document.getElementById('m2SelectJob');
                        const y1Sel = document.getElementById('y1SelectJob');
                        const y2Sel = document.getElementById('y2SelectJob');

                        if (!m1Sel || !m2Sel || !y1Sel || !y2Sel) return;

                        m1Sel.innerHTML = MONTH_NAMES.map((m, i) => `<option value="${i}" ${i === currentMonth1Job ? 'selected' : ''}>${m}</option>`).join('');
                        m2Sel.innerHTML = MONTH_NAMES.map((m, i) => `<option value="${i}" ${i === currentMonth2Job ? 'selected' : ''}>${m}</option>`).join('');

                        const years = [2024, 2025, 2026, 2027];
                        y1Sel.innerHTML = years.map(y => `<option value="${y}" ${y === currentYear1Job ? 'selected' : ''}>${y}</option>`).join('');
                        y2Sel.innerHTML = years.map(y => `<option value="${y}" ${y === currentYear2Job ? 'selected' : ''}>${y}</option>`).join('');
                    }

                    function prevMonthClusterJob() {
                        currentMonth1Job--;
                        if (currentMonth1Job < 0) { currentMonth1Job = 11; currentYear1Job--; }
                        currentMonth2Job = (currentMonth1Job + 1) % 12;
                        currentYear2Job = currentMonth1Job === 11 ? currentYear1Job + 1 : currentYear1Job;
                        initMonthYearSelectsJob();
                        renderCalendarJob();
                    }

                    function nextMonthClusterJob() {
                        currentMonth1Job++;
                        if (currentMonth1Job > 11) { currentMonth1Job = 0; currentYear1Job++; }
                        currentMonth2Job = (currentMonth1Job + 1) % 12;
                        currentYear2Job = currentMonth1Job === 11 ? currentYear1Job + 1 : currentYear1Job;
                        initMonthYearSelectsJob();
                        renderCalendarJob();
                    }

                    function renderMonthGridJob(gridId, year, month) {
                        const grid = document.getElementById(gridId);
                        if (!grid) return;
                        grid.innerHTML = '';

                        const firstDay = new Date(year, month, 1).getDay();
                        const daysInMonth = new Date(year, month + 1, 0).getDate();
                        const prevMonthDays = new Date(year, month, 0).getDate();

                        const offset = (firstDay + 6) % 7;

                        for (let i = offset - 1; i >= 0; i--) {
                            const dayNum = prevMonthDays - i;
                            const cell = document.createElement('div');
                            cell.style.cssText = 'padding:6px 0; color:#cbd5e1; font-weight:500;';
                            cell.textContent = dayNum;
                            grid.appendChild(cell);
                        }

                        for (let d = 1; d <= daysInMonth; d++) {
                            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                            const cell = document.createElement('div');

                            let isSelected = false;
                            let isInRange = false;

                            if (tempStartDateJob && dateStr === tempStartDateJob) isSelected = true;
                            if (tempEndDateJob && dateStr === tempEndDateJob) isSelected = true;
                            if (tempStartDateJob && tempEndDateJob && dateStr > tempStartDateJob && dateStr < tempEndDateJob) isInRange = true;

                            let bg = 'transparent';
                            let color = '#334155';
                            let borderRadius = '50%';
                            let fontWeight = '500';

                            if (isSelected) {
                                bg = '#00a8e8';
                                color = '#ffffff';
                                fontWeight = '700';
                            } else if (isInRange) {
                                bg = '#e0f2fe';
                                color = '#0284c7';
                                borderRadius = '0';
                            }

                            cell.style.cssText = `padding:6px 0; background:${bg}; color:${color}; border-radius:${borderRadius}; font-weight:${fontWeight}; cursor:pointer; font-size:12.5px; transition:all 0.15s;`;
                            cell.textContent = d;
                            cell.onclick = (e) => {
                                if (e) e.stopPropagation();
                                selectDateJob(dateStr);
                            };
                            grid.appendChild(cell);
                        }

                        const totalCells = offset + daysInMonth;
                        const remaining = (7 - (totalCells % 7)) % 7;
                        for (let n = 1; n <= remaining; n++) {
                            const cell = document.createElement('div');
                            cell.style.cssText = 'padding:6px 0; color:#cbd5e1; font-weight:500;';
                            cell.textContent = n;
                            grid.appendChild(cell);
                        }
                    }

                    function renderCalendarJob() {
                        const m1Sel = document.getElementById('m1SelectJob');
                        const y1Sel = document.getElementById('y1SelectJob');
                        const m2Sel = document.getElementById('m2SelectJob');
                        const y2Sel = document.getElementById('y2SelectJob');

                        if (m1Sel && y1Sel && m2Sel && y2Sel) {
                            currentMonth1Job = parseInt(m1Sel.value);
                            currentYear1Job = parseInt(y1Sel.value);
                            currentMonth2Job = parseInt(m2Sel.value);
                            currentYear2Job = parseInt(y2Sel.value);
                        }

                        renderMonthGridJob('m1DaysGridJob', currentYear1Job, currentMonth1Job);
                        renderMonthGridJob('m2DaysGridJob', currentYear2Job, currentMonth2Job);
                    }

                    function selectDateJob(dateStr) {
                        if (!tempStartDateJob || (tempStartDateJob && tempEndDateJob)) {
                            tempStartDateJob = dateStr;
                            tempEndDateJob = '';
                        } else if (tempStartDateJob && !tempEndDateJob) {
                            if (dateStr >= tempStartDateJob) {
                                tempEndDateJob = dateStr;
                            } else {
                                tempEndDateJob = tempStartDateJob;
                                tempStartDateJob = dateStr;
                            }
                        }
                        renderCalendarJob();
                    }

                    function applyDatePickerSelectionJob() {
                        selectedStartDateJob = tempStartDateJob;
                        selectedEndDateJob = tempEndDateJob;
                        document.getElementById('inputStartDateJob').value = selectedStartDateJob;
                        document.getElementById('inputEndDateJob').value = selectedEndDateJob;

                        if (selectedStartDateJob && selectedEndDateJob) {
                            document.getElementById('dateRangeLabelJob').textContent = `${selectedStartDateJob} - ${selectedEndDateJob}`;
                            document.getElementById('clearDateBtnJob').style.display = 'inline';
                        } else if (selectedStartDateJob) {
                            document.getElementById('dateRangeLabelJob').textContent = selectedStartDateJob;
                            document.getElementById('clearDateBtnJob').style.display = 'inline';
                        } else {
                            document.getElementById('dateRangeLabelJob').textContent = 'Pilih rentang tanggal';
                            document.getElementById('clearDateBtnJob').style.display = 'none';
                        }

                        document.getElementById('datePickerPopoverJob').style.display = 'none';
                        document.getElementById('filterMainFormJob').submit();
                    }

                    function resetDatePickerSelectionJob() {
                        tempStartDateJob = '';
                        tempEndDateJob = '';
                        selectedStartDateJob = '';
                        selectedEndDateJob = '';
                        document.getElementById('inputStartDateJob').value = '';
                        document.getElementById('inputEndDateJob').value = '';
                        document.getElementById('dateRangeLabelJob').textContent = 'Pilih rentang tanggal';
                        document.getElementById('clearDateBtnJob').style.display = 'none';
                        renderCalendarJob();
                        document.getElementById('filterMainFormJob').submit();
                    }

                    function clearDateRangeJob() {
                        resetDatePickerSelectionJob();
                    }
                    </script>

                    <div class="console-table-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; overflow-x:auto;">
                        <table class="console-table" style="width:100%; border-collapse:collapse; min-width:1000px;">
                            <thead>
                                <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0; text-align:left;">
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:200px;">
                                        <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo $sort === 'name_asc' ? 'name_desc' : 'name_asc'; ?><?php echo $filterParamsJob; ?>" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                                            Lowongan <i class="fa-solid fa-arrows-up-down" style="font-size:11px; color:#94a3b8;"></i>
                                        </a>
                                    </th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:180px;">Pemberi Kerja</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:150px;">Lokasi</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:140px;">Status</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:160px;">
                                        <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo $sort === 'date_desc' ? 'date_asc' : 'date_desc'; ?><?php echo $filterParamsJob; ?>" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                                            Tanggal Pengajuan <i class="fa-solid fa-arrow-down" style="font-size:11px; color:#64748b;"></i>
                                        </a>
                                    </th>
                                    <th style="padding:14px 16px; width:130px; text-align:right;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$showingJobs): ?>
                                    <tr>
                                        <td colspan="6" style="text-align:center; padding:60px 20px; color:#64748b; font-size:13.5px;">
                                            <?php echo $entity === 'Perusahaan' ? 'Tidak ada data lowongan perusahaan.' : 'Tidak ada data lowongan yang tersedia.'; ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($showingJobs as $vJob):
                                        $jobTitle = $vJob['title'];
                                        $employerName = $vJob['owner_name'] ?: $vJob['user_name'];
                                        $locationStr = $vJob['location'] ?: ($vJob['emp_city'] ?: '-');

                                        $jStatus = $vJob['status'] ?? '';
                                        if ($jStatus === 'Tayang') {
                                            $badgeHtml = '<span class="pill-badge verified">● Disetujui</span>';
                                        } elseif ($jStatus === 'Menunggu Verifikasi') {
                                            if (!empty($vJob['assigned_to'])) {
                                                $badgeHtml = '<span class="pill-badge assigned">● Ditugaskan</span>';
                                            } else {
                                                $badgeHtml = '<span class="pill-badge pending">● Menunggu</span>';
                                            }
                                        } elseif ($jStatus === 'Perlu Direvisi') {
                                            $badgeHtml = '<span class="pill-badge revision">● Revisi</span>';
                                        } elseif ($jStatus === 'Ditolak' || $jStatus === 'CANCELED') {
                                            $badgeHtml = '<span class="pill-badge danger">● Ditolak</span>';
                                        } else {
                                            $badgeHtml = '<span class="pill-badge pending">● ' . e($jStatus) . '</span>';
                                        }

                                        $dateStr = date('d M Y, H:i', strtotime($vJob['created_at']));
                                    ?>
                                        <tr style="border-bottom:1px solid #f1f5f9;">
                                            <td style="padding:14px 16px; font-weight:600; color:#0f172a; font-size:13px;"><?php echo e($jobTitle); ?></td>
                                            <td style="padding:14px 16px; color:#334155; font-size:13px;"><?php echo e($employerName); ?></td>
                                            <td style="padding:14px 16px; color:#334155; font-size:13px;"><?php echo e($locationStr); ?></td>
                                            <td style="padding:14px 16px; font-size:13px; white-space:nowrap;"><?php echo $badgeHtml; ?></td>
                                            <td style="padding:14px 16px; color:#64748b; font-size:12.5px; white-space:nowrap;"><?php echo e($dateStr); ?></td>
                                            <td style="padding:14px 16px; text-align:right; white-space:nowrap;">
                                                <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&detail_id=<?php echo $vJob['id']; ?>" class="btn-lihat-detail" style="white-space:nowrap;">Lihat Detail</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <?php if ($showingJobs): ?>
                            <div class="console-table-footer" style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; font-size:13px; color:#64748b; border-top:1px solid #e2e8f0;">
                                <div>
                                    Menampilkan <?php echo $showingCount; ?> dari <?php echo $totalData; ?> total data.
                                </div>
                                <div style="display:flex; align-items:center; gap:4px;">
                                    <?php if ($page > 1): ?>
                                        <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?>&page=<?php echo $page - 1; ?><?php echo $filterParamsJob; ?>" style="padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; border:1px solid #cbd5e1; font-weight:600;">‹</a>
                                    <?php else: ?>
                                        <span style="padding:6px 12px; border-radius:6px; color:#cbd5e1; border:1px solid #e2e8f0; font-weight:600; cursor:not-allowed;">‹</span>
                                    <?php endif; ?>

                                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                        <?php if ($p == $page): ?>
                                            <span style="padding:6px 12px; border-radius:6px; background:#0284c7; color:#fff; font-weight:700; border:1px solid #0284c7;"><?php echo $p; ?></span>
                                        <?php else: ?>
                                            <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?>&page=<?php echo $p; ?><?php echo $filterParamsJob; ?>" style="padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; border:1px solid #cbd5e1; font-weight:600;"><?php echo $p; ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>

                                    <?php if ($page < $totalPages): ?>
                                        <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?>&page=<?php echo $page + 1; ?><?php echo $filterParamsJob; ?>" style="padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; border:1px solid #cbd5e1; font-weight:600;">›</a>
                                    <?php else: ?>
                                        <span style="padding:6px 12px; border-radius:6px; color:#cbd5e1; border:1px solid #e2e8f0; font-weight:600; cursor:not-allowed;">›</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
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
document.addEventListener('click', (e) => {
    document.querySelectorAll('.action-menu-dropdown').forEach(el => el.classList.remove('show'));

    // Employer Filter Popover outside click close
    const popoverEmp = document.getElementById('filterPopoverEmp');
    const filterBtnEmp = document.getElementById('filterToggleBtnEmp');
    const pickerEmp = document.getElementById('datePickerPopoverEmp');
    const cityCardEmp = document.getElementById('cityDropdownListCardEmp');
    const verifierCardEmp = document.getElementById('verifierDropdownListCardEmp');
    const officerCardEmp = document.getElementById('officerDropdownListCardEmp');

    const isInsidePopoverEmp = popoverEmp && popoverEmp.contains(e.target);
    const isInsideFilterBtnEmp = filterBtnEmp && filterBtnEmp.contains(e.target);
    const isInsidePickerEmp = pickerEmp && pickerEmp.contains(e.target);

    if (!isInsidePopoverEmp && !isInsideFilterBtnEmp && !isInsidePickerEmp) {
        if (popoverEmp) popoverEmp.style.display = 'none';
        if (pickerEmp) pickerEmp.style.display = 'none';
        if (cityCardEmp) cityCardEmp.style.display = 'none';
        if (verifierCardEmp) verifierCardEmp.style.display = 'none';
        if (officerCardEmp) officerCardEmp.style.display = 'none';
    }
});
</script>
</body>
</html>
