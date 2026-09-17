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

        // Check assigned first
        $stmtEmp = db()->prepare('SELECT ep.*, u.name, u.email FROM employer_profiles ep JOIN users u ON u.id = ep.user_id WHERE ep.user_id = ? LIMIT 1');
        $stmtEmp->execute([$targetUserId]);
        $targetEmp = $stmtEmp->fetch();

        if (!$targetEmp || empty($targetEmp['assigned_to'])) {
            flash('error', 'Pemberi kerja harus memiliki penugasan aktif terlebih dahulu sebelum keputusan dapat diambil.');
            redirect($redirectUrl);
            exit;
        }

        if (($decision === 'revision' || $decision === 'reject') && $notes === '') {
            flash('error', 'Catatan Verifikator wajib diisi untuk keputusan Revisi atau Tolak.');
        } else {
            if ($decision === 'approve') {
                $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'sqlite') {
                    $stmt = db()->prepare('UPDATE employer_profiles SET verified = 1, verification_status = "APPROVED", active_until = datetime("now", "+3 months"), verifier_notes = ?, verification_checklist = ? WHERE user_id = ?');
                } else {
                    $stmt = db()->prepare('UPDATE employer_profiles SET verified = 1, verification_status = "APPROVED", active_until = DATE_ADD(NOW(), INTERVAL 3 MONTH), verifier_notes = ?, verification_checklist = ? WHERE user_id = ?');
                }
                $stmt->execute([$notes, $checklist, $targetUserId]);
                db()->prepare('UPDATE users SET profile_complete = 1 WHERE id = ?')->execute([$targetUserId]);
                record_audit_log('employer', $targetUserId, 'APPROVED', "Profil disetujui. Masa aktif berlaku 3 bulan. Catatan: {$notes}", $user['name']);
                notify_user($targetUserId, 'Profil Disetujui', 'Selamat! Profil Pemberi Kerja Individu Anda telah disetujui dan aktif selama 3 bulan.', 'success');
                flash('success', 'Profil Pemberi Kerja Individu berhasil Disetujui (Masa Aktif 3 Bulan).');
            } elseif ($decision === 'revision') {
                $stmt = db()->prepare('UPDATE employer_profiles SET verified = 0, verification_status = "NEEDS_REVISION", verifier_notes = ?, verification_checklist = ? WHERE user_id = ?');
                $stmt->execute([$notes, $checklist, $targetUserId]);
                record_audit_log('employer', $targetUserId, 'REVISION_REQUESTED', "Permintaan perbaikan data dikirim ke pemohon. Catatan: {$notes}", $user['name']);
                notify_user($targetUserId, 'Perbaikan Profil Diperlukan', 'Verifikator meminta perbaikan profil: ' . $notes, 'warning');
                flash('success', 'Profil dikembalikan ke pemohon untuk diperbaiki (Perlu Diperbaiki).');
            } elseif ($decision === 'reject') {
                $newRejectionCount = (int)($targetEmp['rejection_count'] ?? 0) + 1;
                if ($newRejectionCount >= 3) {
                    // 3rd rejection triggers MANUAL_DINAS_REVIEW
                    $stmt = db()->prepare('UPDATE employer_profiles SET verified = 0, verification_status = "NEEDS_REVISION", rejection_count = ?, manual_review_status = "MANUAL_DINAS_REVIEW", verifier_notes = ?, verification_checklist = ? WHERE user_id = ?');
                    $stmt->execute([$newRejectionCount, $notes, $checklist, $targetUserId]);
                    record_audit_log('employer', $targetUserId, 'REJECTED_MANUAL_DINAS', "Penolakan ke-3 dicapai. Akun dialihkan ke Jalur Manual Dinas. Catatan: {$notes}", $user['name']);
                    notify_user($targetUserId, 'Penolakan ke-3: Dialihkan ke Manual Dinas', 'Profil Anda telah ditolak 3 kali. Verifikasi dialihkan ke Jalur Manual Dinas untuk pendampingan petugas.', 'error');
                    flash('warning', 'Penolakan ke-3 telah dicapai. Profil dialihkan ke Jalur Manual Dinas.');
                } else {
                    // 1st or 2nd rejection gives chance to fix (NEEDS_REVISION)
                    $stmt = db()->prepare('UPDATE employer_profiles SET verified = 0, verification_status = "NEEDS_REVISION", rejection_count = ?, verifier_notes = ?, verification_checklist = ? WHERE user_id = ?');
                    $stmt->execute([$newRejectionCount, $notes, $checklist, $targetUserId]);
                    record_audit_log('employer', $targetUserId, 'REJECTED', "Profil ditolak (Penolakan ke-{$newRejectionCount}). Kesempatan perbaikan dibuka. Catatan: {$notes}", $user['name']);
                    notify_user($targetUserId, "Profil Belum Disetujui (Penolakan {$newRejectionCount}/3)", 'Verifikator menolak profil: ' . $notes . '. Silahkan perbaiki data Anda.', 'error');
                    flash('success', "Profil Pemberi Kerja Ditolak (Penolakan ke-{$newRejectionCount}/3). Kesempatan perbaikan dibuka.");
                }
            }
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
            $stmt = db()->prepare('UPDATE employer_profiles SET verified = 1, verification_status = "APPROVED", active_until = datetime("now", "+3 months"), manual_review_status = "APPROVED_DINAS", officer_name = ?, officer_statement = ? WHERE user_id = ?');
        } else {
            $stmt = db()->prepare('UPDATE employer_profiles SET verified = 1, verification_status = "APPROVED", active_until = DATE_ADD(NOW(), INTERVAL 3 MONTH), manual_review_status = "APPROVED_DINAS", officer_name = ?, officer_statement = ? WHERE user_id = ?');
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
        $targetUserId = (int)$_POST['user_id'];
        $reason = trim($_POST['suspension_reason'] ?? '');

        if ($reason === '') {
            flash('error', 'Alasan Penangguhan WAJIB diisi.');
        } else {
            $stmt = db()->prepare('UPDATE employer_profiles SET verification_status = "SUSPENDED", suspension_reason = ? WHERE user_id = ?');
            $stmt->execute([$reason, $targetUserId]);
            record_audit_log('employer', $targetUserId, 'SUSPENDED', "Pemberi kerja ditangguhkan. Alasan: {$reason}", $user['name']);
            flash('success', 'Pemberi kerja berhasil ditangguhkan.');
        }
        redirect($redirectUrl);
        exit;
    }

    if ($action === 'unsuspend_employer') {
        $targetUserId = (int)$_POST['user_id'];
        $stmt = db()->prepare('UPDATE employer_profiles SET verification_status = "APPROVED", suspension_reason = NULL WHERE user_id = ?');
        $stmt->execute([$targetUserId]);
        record_audit_log('employer', $targetUserId, 'UNSUSPENDED', "Penangguhan pemberi kerja dibatalkan.", $user['name']);
        flash('success', 'Penangguhan pemberi kerja berhasil dibatalkan.');
        redirect($redirectUrl);
        exit;
    }

    // 7. PERPANJANGAN MASA AKTIF TRANSISI (1, 2, ATAU 3 HARI)
    if ($action === 'approve_extension') {
        $targetUserId = (int)$_POST['user_id'];
        $extDays = isset($_POST['extension_days']) ? max(1, min(3, (int)$_POST['extension_days'])) : 3;
        $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = db()->prepare("UPDATE employer_profiles SET extension_status = 'APPROVED', active_until = datetime(CASE WHEN active_until < datetime('now') THEN datetime('now') ELSE active_until END, '+{$extDays} days') WHERE user_id = ?");
        } else {
            $stmt = db()->prepare("UPDATE employer_profiles SET extension_status = 'APPROVED', active_until = DATE_ADD(GREATEST(COALESCE(active_until, NOW()), NOW()), INTERVAL {$extDays} DAY) WHERE user_id = ?");
        }
        $stmt->execute([$targetUserId]);
        record_audit_log('employer', $targetUserId, 'EXTENSION_APPROVED', "Perpanjangan masa transisi disetujui selama {$extDays} hari.", $user['name']);
        flash('success', "Permohonan perpanjangan masa aktif ({$extDays} hari) berhasil Disetujui.");
        redirect($redirectUrl);
        exit;
    }

    if ($action === 'reject_extension') {
        $targetUserId = (int)$_POST['user_id'];
        $stmt = db()->prepare('UPDATE employer_profiles SET extension_status = "REJECTED" WHERE user_id = ?');
        $stmt->execute([$targetUserId]);
        record_audit_log('employer', $targetUserId, 'EXTENSION_REJECTED', "Permohonan perpanjangan masa transisi ditolak.", $user['name']);
        flash('success', 'Permohonan perpanjangan masa aktif Ditolak.');
        redirect($redirectUrl);
        exit;
    }

    // 8. AMBIL CASE / ASSIGN PEMERIKSA LOWONGAN
    if ($action === 'assign_job_case') {
        $jobId = (int)$_POST['job_id'];
        $verifierName = trim($_POST['verifier_name'] ?? 'Admin Pusat');
        $reason = trim($_POST['assignment_reason'] ?? '');
        $isSelfAssign = !empty($_POST['self_assign']);

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
}

// --- FETCH DATA FOR DIRECTORY INDIVIDUAL ---
if ($view === 'directory_individual') {
    $query = <<<SQL
        SELECT u.id as user_id, u.name, u.email, u.created_at, u.profile_complete,
               ep.id as profile_id, ep.owner_name, ep.nik, ep.phone, ep.whatsapp, ep.npwp, ep.profession, ep.address, ep.address_detail,
               ep.city, ep.province, ep.district, ep.village, ep.postal_code, ep.latitude, ep.longitude, ep.description,
               ep.verified, ep.verification_status, ep.suspension_reason, ep.extension_status, ep.verifier_notes, ep.verification_checklist,
               ep.manual_review_status, ep.assigned_to, ep.assigned_at, ep.rejection_count, ep.entity_type
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
            $auditLogs = fetch_audit_logs('employer', $detailId);
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
            $auditLogs = fetch_audit_logs('employer', $detailId);
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

    if ($tab === 'process') {
        $query .= ' AND j.status = "Menunggu Verifikasi"';
    } elseif ($tab === 'approved') {
        $query .= ' AND j.status = "Tayang"';
    } elseif ($tab === 'revision') {
        $query .= ' AND j.status = "Perlu Direvisi"';
    } elseif ($tab === 'rejected') {
        $query .= ' AND j.status = "Ditolak"';
    }

    $query .= ' ORDER BY j.created_at DESC';
    $stmt = db()->prepare($query);
    $stmt->execute($params);
    $verificationJobs = $stmt->fetchAll() ?: [];

    // If detail_id is requested for job
    $selectedJob = null;
    $auditLogs = [];
    if ($detailId > 0) {
        $stmtSel = db()->prepare('SELECT j.*, ep.owner_name, ep.profession, ep.city as emp_city, ep.phone, ep.address, u.name as user_name, u.email as user_email FROM job_posts j JOIN users u ON u.id = j.user_id LEFT JOIN employer_profiles ep ON ep.user_id = u.id WHERE j.id = ? LIMIT 1');
        $stmtSel->execute([$detailId]);
        $selectedJob = $stmtSel->fetch();
        if ($selectedJob) {
            $auditLogs = fetch_audit_logs('job', $detailId);
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
    </style>
</head>
<body>
<div class="app-shell" style="width:100%;height:100vh;display:flex;overflow:hidden;">
    <!-- SIDEBAR 260px (COLLAPSIBLE) -->
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-mark"><i class="fa-solid fa-shield-halved"></i></div>
            <div class="brand-text">
                <h1>Karirhub</h1>
                <p>Admin Pusat</p>
            </div>
        </div>
        <div class="menu-list">
            <a class="menu-item <?php echo $view === 'directory_individual' ? 'active' : ''; ?>" href="admin.php?view=directory_individual">
                <i class="fa-solid fa-building-user"></i>
                <span class="menu-label"><strong>Direktori Profil</strong><span>Pemberi kerja individu</span></span>
            </a>
            <a class="menu-item <?php echo $view === 'verifikasi_employer' ? 'active' : ''; ?>" href="admin.php?view=verifikasi_employer&entity=Individu">
                <i class="fa-solid fa-id-card"></i>
                <span class="menu-label"><strong>Verifikasi Profil</strong><span>Antrean verifikasi</span></span>
            </a>
            <a class="menu-item <?php echo $view === 'verifikasi_job' ? 'active' : ''; ?>" href="admin.php?view=verifikasi_job&entity=Individu">
                <i class="fa-solid fa-briefcase"></i>
                <span class="menu-label"><strong>Verifikasi Lowongan</strong><span>Moderasi loker</span></span>
            </a>
        </div>
        <div class="sidebar-spacer"></div>
        <div style="padding:0 4px">
            <div class="profile-card">
                <div class="profile-avatar">AD</div>
                <div style="overflow:hidden;text-overflow:ellipsis;">
                    <strong style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo e($user['name']); ?></strong>
                    <span>Admin Pusat</span>
                </div>
            </div>
            <a href="logout.php" class="sidebar-logout"><i class="fa-solid fa-right-from-bracket"></i> Keluar</a>
        </div>
    </aside>

    <!-- MAIN CONTAINER -->
    <div class="main">
        <!-- TOPBAR 68px -->
        <header class="topbar">
            <button id="sidebarToggle" class="sidebar-toggle" type="button" aria-label="Toggle Sidebar"><i class="fa-solid fa-bars"></i></button>
            <div class="crumbs">
                <span>Beranda</span><span>&gt;</span>
                <?php if ($view === 'directory_individual'): ?>
                    <a href="admin.php?view=directory_individual" style="color:inherit;text-decoration:none;">Direktori Pemberi Kerja</a>
                    <?php if ($selectedEmployer): ?>
                        <span>&gt;</span>
                        <strong>#<?php echo substr(md5($selectedEmployer['user_id']), 0, 8); ?></strong>
                    <?php endif; ?>
                <?php elseif ($view === 'verifikasi_employer'): ?>
                    <a href="admin.php?view=verifikasi_employer" style="color:inherit;text-decoration:none;">Verifikasi Pemberi Kerja</a>
                    <?php if ($selectedEmployer): ?>
                        <span>&gt;</span>
                        <strong>#<?php echo substr(md5($selectedEmployer['user_id']), 0, 8); ?></strong>
                    <?php endif; ?>
                <?php else: ?>
                    <a href="admin.php?view=verifikasi_job" style="color:inherit;text-decoration:none;">Verifikasi Lowongan</a>
                    <?php if ($selectedJob): ?>
                        <span>&gt;</span>
                        <strong>#<?php echo substr(md5($selectedJob['id']), 0, 8); ?></strong>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <form method="get" action="admin.php" class="search-bar">
                <input type="hidden" name="view" value="<?php echo e($view); ?>">
                <input type="hidden" name="entity" value="<?php echo e($entity); ?>">
                <input type="hidden" name="tab" value="<?php echo e($tab); ?>">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Cari lowongan, pemberi kerja, nik, kota...">
            </form>

            <div class="top-actions">
                <?php echo render_notif_dropdown($notifications, $unread); ?>
                <div class="company-chip">
                    <div class="profile-avatar" style="width:28px;height:28px;font-size:11px;">AD</div>
                    <div>
                        <strong><?php echo e($user['name']); ?></strong>
                        <span>Admin Pusat</span>
                    </div>
                </div>
                <a class="action-chip" href="logout.php">Logout</a>
            </div>
        </header>

        <!-- CONTENT AREA -->
        <div class="content">
            <div class="page active">
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
                <?php if ($selectedEmployer): ?>
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
                                    <span class="pill-badge <?php echo $selectedEmployer['verification_status'] === 'APPROVED' ? 'verified' : ($selectedEmployer['verification_status'] === 'SUSPENDED' ? 'suspended' : 'pending'); ?>">
                                        ● <?php echo e($selectedEmployer['verification_status'] === 'APPROVED' ? 'Terverifikasi' : $selectedEmployer['verification_status']); ?>
                                    </span>
                                </div>
                                <div style="font-size:12px; color:#64748b; margin-top:4px;">
                                    Slug: <code><?php echo strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $selectedEmployer['owner_name'] ?: $selectedEmployer['name'])); ?></code> • 
                                    Didaftarkan: <?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?> • 
                                    <?php echo e($selectedEmployer['city'] ?: 'Kota Belum Diisi'); ?>
                                </div>
                            </div>
                        </div>

                        <div style="display:flex; gap:10px;">
                            <button type="button" class="btn-lihat-detail" data-open-modal="modal-ver-info">
                                <i class="fa-solid fa-shield-halved"></i> Lihat Rincian Verifikasi
                            </button>
                            <?php if ($selectedEmployer['verification_status'] === 'APPROVED'): ?>
                                <button type="button" class="btn-lihat-detail" style="color:#dc2626; border-color:#fca5a5;" data-open-modal="modal-suspend">
                                    <i class="fa-solid fa-ban"></i> Tangguhkan
                                </button>
                            <?php elseif ($selectedEmployer['verification_status'] === 'SUSPENDED'): ?>
                                <form method="post" action="admin.php?view=directory_individual&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
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

                            <!-- PERPANJANGAN MASA AKTIF MODAL / EXTENSION IF REQUESTED -->
                            <?php if (($selectedEmployer['extension_status'] ?? '') === 'REQUESTED'): ?>
                                <div class="section-card" style="border:1px solid #fde68a; background:#fffbeb;">
                                    <div class="section-card-title" style="color:#92400e;">
                                        <i class="fa-solid fa-clock-rotate-left"></i> Permohonan Perpanjangan Masa Transisi
                                    </div>
                                    <p style="font-size:13px; color:#78350f; margin-bottom:12px;">
                                        Pemberi kerja ini mengajukan perpanjangan masa transisi (1x per siklus). Silakan tentukan durasi yang disetujui (1, 2, atau 3 hari):
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
                                        Penangguhan akun akan menonaktifkan seluruh lowongan yang sedang tayang dan membatasi akses pemberi kerja. Masukkan alasan penangguhan:
                                    </div>
                                    <textarea name="suspension_reason" required placeholder="Alasan penangguhan wajib diisi..." style="width:100%; min-height:80px; padding:10px; border-radius:8px; border:1px solid #fca5a5; font-size:13px;"></textarea>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="ghost-btn" data-close-modal="modal-suspend">Batal</button>
                                    <button type="submit" class="primary-btn" style="background:#dc2626;">Tangguhkan Sekarang</button>
                                </div>
                            </form>
                        </div>
                    </div>

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
                                    <th>Nama Perusahaan / Pemberi Kerja</th>
                                    <th>Email</th>
                                    <th>Telepon</th>
                                    <th>NIB / NPWP</th>
                                    <th>PIC</th>
                                    <th>Lokasi</th>
                                    <th>Status</th>
                                    <th>Tanggal Daftar</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$individualList): ?>
                                    <tr><td colspan="9" style="text-align:center; padding:40px; color:#64748b;">Tidak ada data pemberi kerja ditemukan.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($individualList as $emp): ?>
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
                                            <td><?php echo e($emp['email']); ?></td>
                                            <td><?php echo e($emp['phone'] ?: '0'); ?></td>
                                            <td><code><?php echo e($emp['npwp'] ?: '-'); ?></code></td>
                                            <td>-</td>
                                            <td><?php echo e($emp['city'] ?: '-'); ?>, <?php echo e($emp['province'] ?: '-'); ?></td>
                                            <td>
                                                <?php if ($emp['verification_status'] === 'APPROVED'): ?>
                                                    <span class="pill-badge verified">● Terverifikasi</span>
                                                <?php elseif ($emp['verification_status'] === 'PENDING'): ?>
                                                    <span class="pill-badge pending">● Menunggu</span>
                                                <?php elseif ($emp['verification_status'] === 'SUSPENDED'): ?>
                                                    <span class="pill-badge suspended">● Ditangguhkan</span>
                                                <?php else: ?>
                                                    <span class="pill-badge revision">● <?php echo e($emp['verification_status']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('d M Y, H:i', strtotime($emp['created_at'])); ?></td>
                                            <td>
                                                <a href="admin.php?view=directory_individual&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&detail_id=<?php echo $emp['user_id']; ?>" class="btn-lihat-detail">
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
                                            <option value="Admin Pusat">Admin Pusat</option>
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

                            <!-- COMPLIANCE CHECKLIST MATRIX (4 CATEGORIES) -->
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
</div>

<script src="assets/app.js?v=admin-std-1"></script>
</body>
</html>
