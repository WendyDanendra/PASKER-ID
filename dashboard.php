<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = require_role('employer');

// Fetch Employer Profile
$profileStatement = db()->prepare('SELECT * FROM employer_profiles WHERE user_id = ? LIMIT 1');
$profileStatement->execute([$user['id']]);
$profile = $profileStatement->fetch() ?: [];

// Fetch Seeker Profile (SIAPkerja data)
$seekerStatement = db()->prepare('SELECT * FROM seeker_profiles WHERE user_id = ? LIMIT 1');
$seekerStatement->execute([$user['id']]);
$seekerProfile = $seekerStatement->fetch() ?: [];

// Fetch Jobs
$jobsStatement = db()->prepare('SELECT * FROM job_posts WHERE user_id = ? ORDER BY id DESC');
$jobsStatement->execute([$user['id']]);
$jobs = $jobsStatement->fetchAll() ?: [];

// Status calculation logic according to FSD Final & Step 4/5 specification
$verificationStatus = $profile['verification_status'] ?? 'NOT_SUBMITTED';
if (empty($profile)) {
    $verificationStatus = 'NOT_SUBMITTED';
} elseif ($verificationStatus === 'NOT_SUBMITTED' && !empty($profile['owner_name'])) {
    $verificationStatus = !empty($profile['verified']) ? 'APPROVED' : 'PENDING';
}

$activeUntilRaw = $profile['active_until'] ?? null;
$now = new DateTime();
$activeUntil = $activeUntilRaw ? new DateTime($activeUntilRaw) : null;

$daysRemaining = 0;
$isExpired = false;
$isTransitionPeriod = false;
$isFullDisable = false;

if (in_array($verificationStatus, ['APPROVED', 'ACTIVE_VERIFIED', 'TRANSITION_LIMITED', 'FULL_DISABLED'], true)) {
    if ($activeUntil) {
        if ($now <= $activeUntil) {
            $diff = $now->diff($activeUntil);
            $daysRemaining = (int)$diff->days;
            $verificationStatus = 'ACTIVE_VERIFIED';
            $isExpired = false;
        } else {
            $diff = $now->diff($activeUntil);
            $daysPast = (int)$diff->days;
            $isExpired = true;
            $daysRemaining = -$daysPast;

            // 7 days transition period
            if ($daysPast <= 7) {
                $verificationStatus = 'TRANSITION_LIMITED';
                $isTransitionPeriod = true;
            } else {
                $verificationStatus = 'FULL_DISABLED';
                $isFullDisable = true;
            }
        }
    } else {
        $verificationStatus = 'ACTIVE_VERIFIED';
    }
}

// Reactivation eligibility: must have at least 1 candidate accepted in previous cycle
$accCandidateStmt = db()->prepare('SELECT COUNT(*) FROM job_applications a JOIN job_posts j ON j.id = a.job_id WHERE j.user_id = ? AND a.status = "Diterima"');
$accCandidateStmt->execute([$user['id']]);
$isEligibleForReactivation = ((int)$accCandidateStmt->fetchColumn() > 0);

// --- API / JSON HANDLERS ---
if (isset($_GET['read_notif'])) {
    mark_notifications_read((int) $user['id']);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

if (isset($_GET['applicant_json'])) {
    $applicationId = (int) $_GET['applicant_json'];
    $detail = db()->prepare('SELECT a.*, j.title AS job_title, j.user_id AS employer_id, u.name AS seeker_name, u.email AS seeker_email,
            sp.nik, sp.phone, sp.gender, sp.marital_status, sp.birth_place, sp.birth_date, sp.ktp_address, sp.domicile_address
        FROM job_applications a
        JOIN job_posts j ON j.id = a.job_id
        JOIN users u ON u.id = a.seeker_id
        LEFT JOIN seeker_profiles sp ON sp.user_id = a.seeker_id
        WHERE a.id = ? AND j.user_id = ?
        LIMIT 1');
    $detail->execute([$applicationId, $user['id']]);
    $row = $detail->fetch();
    header('Content-Type: application/json');
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false]);
        exit;
    }
    $row['status'] = normalize_application_status($row['status'] ?? '');
    $row['profile'] = seeker_profile_bundle((int) $row['seeker_id']);
    echo json_encode(['ok' => true, 'data' => $row], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['job_json'])) {
    $jobId = (int) $_GET['job_json'];
    $jobStmt = db()->prepare('SELECT * FROM job_posts WHERE id = ? AND user_id = ? AND status IN ("Perlu Direvisi", "Perlu Revisi", "Draft") LIMIT 1');
    $jobStmt->execute([$jobId, $user['id']]);
    $job = $jobStmt->fetch();
    header('Content-Type: application/json');
    if (!$job) {
        http_response_code(404);
        echo json_encode(['ok' => false]);
        exit;
    }

    if (in_array($job['status'], ['Perlu Direvisi', 'Perlu Revisi'], true) && empty($job['revision_opened_at'])) {
        db()->prepare('UPDATE job_posts SET revision_opened_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ? AND status IN ("Perlu Direvisi", "Perlu Revisi") AND revision_opened_at IS NULL')
            ->execute([$jobId, $user['id']]);
    }

    echo json_encode(['ok' => true, 'data' => job_to_form_data($job)], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- POST HANDLERS ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // 1. UPDATE STATUS PELAMAR (Diizinkan saat ACTIVE_VERIFIED dan TRANSITION_LIMITED)
    if (isset($_POST['update_application_status'])) {
        if ($isFullDisable || $verificationStatus === 'SUSPENDED') {
            flash('error', 'Akun sedang terkunci atau ditangguhkan.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        $applicationId = (int) ($_POST['application_id'] ?? 0);
        $nextStatus = normalize_application_status($_POST['status'] ?? '');
        if (!in_array($nextStatus, application_statuses(), true)) {
            flash('error', 'Status pelamar tidak valid.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        $owned = db()->prepare('SELECT a.id, a.job_id, a.seeker_id, j.title FROM job_applications a JOIN job_posts j ON j.id = a.job_id WHERE a.id = ? AND j.user_id = ?');
        $owned->execute([$applicationId, $user['id']]);
        $application = $owned->fetch();
        if (!$application) {
            flash('error', 'Pelamar tidak ditemukan.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        db()->prepare('UPDATE job_applications SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$nextStatus, $applicationId]);

        // Recalculate accepted_count for parent job
        $jobIdForApp = (int)$application['job_id'];
        $accStmt = db()->prepare('SELECT COUNT(*) FROM job_applications WHERE job_id = ? AND status = "Diterima"');
        $accStmt->execute([$jobIdForApp]);
        $accCount = (int)$accStmt->fetchColumn();
        db()->prepare('UPDATE job_posts SET accepted_count = ? WHERE id = ?')->execute([$accCount, $jobIdForApp]);

        notify_user((int) $application['seeker_id'], 'Status lamaran diperbarui', 'Status lamaran Anda untuk "' . $application['title'] . '" sekarang: ' . $nextStatus . '.', 'info', $applicationId);
        flash('success', 'Status pelamar diperbarui menjadi ' . $nextStatus . '.');
        redirect('dashboard.php#lowongan');
        exit;
    }

    // 2. SIMPAN & AJUKAN PROFIL PEMBERI KERJA INDIVIDU (ONBOARDING / REVISION / REACTIVATION)
    if (isset($_POST['submit_profile'])) {
        $ownerName   = trim($_POST['owner_name'] ?? '');
        $nik         = trim($_POST['nik'] ?? '');
        $phone       = trim($_POST['phone'] ?? '');
        $whatsapp    = trim($_POST['whatsapp'] ?? '');
        $profession  = trim($_POST['profession'] ?? '');
        $npwp        = trim($_POST['npwp'] ?? '');
        
        $linkedin    = trim($_POST['linkedin'] ?? '');
        $facebook    = trim($_POST['facebook'] ?? '');
        $instagram   = trim($_POST['instagram'] ?? '');

        $sameLoc     = isset($_POST['same_location_siapkerja']) ? 1 : 0;
        $province    = trim($_POST['province'] ?? '');
        $city        = trim($_POST['city'] ?? '');
        $district    = trim($_POST['district'] ?? '');
        $village     = trim($_POST['village'] ?? '');
        $postalCode  = trim($_POST['postal_code'] ?? '');

        $sameAddr    = isset($_POST['same_address_siapkerja']) ? 1 : 0;
        $address     = trim($_POST['address'] ?? '');
        $addressDetail = trim($_POST['address_detail'] ?? '');

        $latitude    = trim($_POST['latitude'] ?? '');
        $longitude   = trim($_POST['longitude'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $consent     = isset($_POST['user_consent']) ? 1 : 0;

        if ($ownerName === '' || $nik === '' || $phone === '' || $whatsapp === '' || $profession === '' || $npwp === '' || !$consent) {
            flash('error', 'Lengkapi semua field wajib (Nama, NIK, Telepon, WhatsApp, Profesi, NPWP) dan centang persetujuan pengguna.');
            redirect('dashboard.php?open_profile=1');
            exit;
        }

        $permitDoc = store_upload('permit_document', 'employer/' . $user['id'], ['pdf', 'jpg', 'jpeg', 'png']);
        $workplacePhoto = store_upload('workplace_photo', 'employer/' . $user['id'], ['jpg', 'jpeg', 'png', 'webp']);
        $permitDoc = $permitDoc ?: ($profile['permit_document'] ?? $profile['doc_permission'] ?? null);
        $workplacePhoto = $workplacePhoto ?: ($profile['workplace_photo'] ?? $profile['doc_location_photo'] ?? null);

        if (empty($permitDoc)) {
            flash('error', 'Dokumen Pendukung wajib diunggah minimal 1 dokumen.');
            redirect('dashboard.php?open_profile=1');
            exit;
        }

        if ($profile) {
            $stmt = db()->prepare('UPDATE employer_profiles SET 
                owner_name = ?, nik = ?, phone = ?, whatsapp = ?, profession = ?, npwp = ?,
                linkedin = ?, facebook = ?, instagram = ?,
                same_location_siapkerja = ?, province = ?, city = ?, district = ?, village = ?, postal_code = ?,
                same_address_siapkerja = ?, address = ?, address_detail = ?,
                latitude = ?, longitude = ?, permit_document = ?, doc_permission = ?,
                workplace_photo = ?, doc_location_photo = ?,
                description = ?, user_consent = ?,
                verification_status = "PENDING", verified = 0, active_until = NULL, updated_at = CURRENT_TIMESTAMP
                WHERE user_id = ?');
            $stmt->execute([
                $ownerName, $nik, $phone, $whatsapp, $profession, $npwp,
                $linkedin, $facebook, $instagram,
                $sameLoc, $province, $city, $district, $village, $postalCode,
                $sameAddr, $address, $addressDetail,
                $latitude, $longitude, $permitDoc, $permitDoc,
                $workplacePhoto, $workplacePhoto,
                $description, $consent,
                $user['id']
            ]);
        } else {
            $stmt = db()->prepare('INSERT INTO employer_profiles (
                user_id, owner_name, nik, phone, whatsapp, profession, npwp,
                linkedin, facebook, instagram,
                same_location_siapkerja, province, city, district, village, postal_code,
                same_address_siapkerja, address, address_detail,
                latitude, longitude, permit_document, doc_permission, workplace_photo, doc_location_photo,
                description, user_consent,
                verification_status, verified, active_until
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "PENDING", 0, NULL)');
            $stmt->execute([
                $user['id'], $ownerName, $nik, $phone, $whatsapp, $profession, $npwp,
                $linkedin, $facebook, $instagram,
                $sameLoc, $province, $city, $district, $village, $postalCode,
                $sameAddr, $address, $addressDetail,
                $latitude, $longitude, $permitDoc, $permitDoc, $workplacePhoto, $workplacePhoto,
                $description, $consent
            ]);
        }

        db()->prepare('UPDATE users SET name = ?, profile_complete = 1 WHERE id = ?')->execute([$ownerName, $user['id']]);

        flash('pending_popup', 'Profil Anda berhasil diajukan! Status Profil: Menunggu Verifikasi.');
        redirect('dashboard.php');
        exit;
    }

    // 3. TAMBAH / UPDATE DRAFT LOWONGAN (SELALU Draft, TANPA Rules Engine)
    if (isset($_POST['save_job']) || isset($_POST['update_job'])) {
        if ($isTransitionPeriod || $isFullDisable || $verificationStatus === 'SUSPENDED') {
            flash('error', 'Akun dalam Masa Transisi (Akses Dibatasi) atau terkunci. Tidak dapat membuat atau mengubah lowongan.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        $jobId = (int)($_POST['job_id'] ?? 0);
        $title = trim($_POST['job_title'] ?? $_POST['title'] ?? '');
        $location = trim($_POST['job_location'] ?? $_POST['location'] ?? '');
        $jobType = trim($_POST['job_type'] ?? '');
        $industry = trim($_POST['industry'] ?? $_POST['job_field'] ?? '');
        $kbjiCode = trim($_POST['kbji_code'] ?? '');
        $minEducation = trim($_POST['min_education'] ?? $_POST['education_required'] ?? '');
        $minExperience = trim($_POST['min_experience'] ?? $_POST['experience_required'] ?? '');
        $quota = (int)($_POST['quota'] ?? 1);
        $description = trim($_POST['job_description'] ?? $_POST['description'] ?? '');

        if ($title !== '' && $location !== '' && $kbjiCode !== '' && $quota > 0 && $description !== '') {
            if ($jobId > 0) {
                // Update existing job maintaining its identity and draft/revision status
                $stmt = db()->prepare('UPDATE job_posts SET title = ?, location = ?, job_type = ?, industry = ?, kbji_code = ?, min_education = ?, min_experience = ?, quota = ?, description = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ? AND status IN ("Draft", "Perlu Direvisi", "Perlu Revisi")');
                $stmt->execute([$title, $location, $jobType, $industry, $kbjiCode, $minEducation, $minExperience, $quota, $description, $jobId, $user['id']]);
                flash('success', 'Draft lowongan berhasil diperbarui.');
            } else {
                // Always create as Draft with NO rules engine checks
                $stmt = db()->prepare('INSERT INTO job_posts (user_id, title, description, location, job_type, industry, entity_type, status, quota, kbji_code, min_education, min_experience, created_at) VALUES (?, ?, ?, ?, ?, ?, "Individu", "Draft", ?, ?, ?, ?, CURRENT_TIMESTAMP)');
                $stmt->execute([$user['id'], $title, $description, $location, $jobType, $industry, $quota, $kbjiCode, $minEducation, $minExperience]);
                $jobId = (int)db()->lastInsertId();
                flash('success', 'Lowongan baru berhasil dibuat dan disimpan sebagai Draft.');
            }
            redirect('dashboard.php?open_draft=' . $jobId . '#lowongan');
            exit;
        } else {
            flash('error', 'Lengkapi semua field wajib pada form lowongan.');
            redirect('dashboard.php?open_draft=' . $jobId . '#lowongan');
            exit;
        }
    }

    // 4. KIRIM LOWONGAN (VALIDATION + RULES ENGINE 3 LAYERS)
    if (isset($_POST['send_job'])) {
        if ($isTransitionPeriod || $isFullDisable || $verificationStatus === 'SUSPENDED') {
            flash('error', 'Akun dalam Masa Transisi (Akses Dibatasi) atau terkunci. Tidak dapat mengirim lowongan baru.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        $jobId = (int)$_POST['job_id'];
        
        $jobStmt = db()->prepare('SELECT * FROM job_posts WHERE id = ? AND user_id = ?');
        $jobStmt->execute([$jobId, $user['id']]);
        $targetJob = $jobStmt->fetch();

        if (!$targetJob) {
            flash('error', 'Lowongan tidak ditemukan.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        if (empty($targetJob['title']) || empty($targetJob['location']) || empty($targetJob['kbji_code']) || empty($targetJob['description'])) {
            flash('error', 'Informasi lowongan belum lengkap. Harap perbarui draft Anda.');
            redirect('dashboard.php?open_draft=' . $jobId . '#lowongan');
            exit;
        }

        $isChildRepost = !empty($targetJob['parent_job_id']);
        $rulesResult = check_pki_job_rules_engine(
            db(),
            (int)$user['id'],
            (string)$targetJob['kbji_code'],
            (int)($targetJob['quota'] ?? 1),
            $jobId,
            $isChildRepost
        );

        if (!$rulesResult['allowed']) {
            if ($rulesResult['layer'] === 1) {
                $_SESSION['kbji_duplicate_error'] = [
                    'job_id' => $jobId,
                    'kbji_code' => $targetJob['kbji_code'],
                    'active_job_id' => $rulesResult['conflict_job']['id'] ?? 0,
                    'active_job_title' => $rulesResult['conflict_job']['title'] ?? '',
                    'active_job_status' => $rulesResult['conflict_job']['status'] ?? ''
                ];
                redirect('dashboard.php?kbji_conflict=1&draft_id=' . $jobId . '#lowongan');
                exit;
            } else {
                // Layer 3: Monthly quota exceeded (>10)
                flash('error', $rulesResult['error_message']);
                redirect('dashboard.php?open_draft=' . $jobId . '#lowongan');
                exit;
            }
        }

        $additionalDocRequired = !empty($rulesResult['additional_doc_required']) ? 1 : 0;
        $layerFlag = $additionalDocRequired ? 'ADDITIONAL_DOCUMENT_PENDING' : ($isChildRepost ? 'REPOST_CONTINUATION' : null);

        // Update to canonical status: Menunggu Verifikasi
        $update = db()->prepare('UPDATE job_posts SET status = "Menunggu Verifikasi", additional_doc_required = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?');
        $update->execute([$additionalDocRequired, $jobId, $user['id']]);

        // Create or update verification record
        try {
            $caseStmt = db()->prepare('INSERT INTO job_verifications (job_id, user_id, kbji_code, status, additional_doc_required, layer_flags) VALUES (?, ?, ?, "PENDING", ?, ?)');
            $caseStmt->execute([$jobId, $user['id'], $targetJob['kbji_code'], $additionalDocRequired, $layerFlag]);
        } catch (Throwable $ignored) {}

        if ($additionalDocRequired) {
            flash('warning', 'Lowongan berhasil dikirim dan Menunggu Verifikasi. Catatan: Pengajuan publikasi ini masuk kuota ke-4+ untuk KBJI ' . $targetJob['kbji_code'] . ' bulan ini (Status: ADDITIONAL_DOCUMENT_PENDING).');
        } else {
            flash('success', 'Lowongan berhasil dikirim dan sedang Menunggu Verifikasi.');
        }
        redirect('dashboard.php#lowongan');
        exit;
    }

    // 5. TUTUP LOWONGAN & POSTING ULANG SISA KUOTA
    if (isset($_POST['close_job'])) {
        $jobId = (int)$_POST['job_id'];
        $repost = ($_POST['repost'] ?? '0') === '1';
        $reasons = $_POST['reasons'] ?? [];
        $lainnya = trim($_POST['reason_lainnya'] ?? '');
        
        $jobStmt = db()->prepare('SELECT * FROM job_posts WHERE id = ? AND user_id = ?');
        $jobStmt->execute([$jobId, $user['id']]);
        $oldJob = $jobStmt->fetch();

        if (!$oldJob) {
            flash('error', 'Lowongan tidak ditemukan.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        // Calculate accepted_count and remaining_quota
        $accStmt = db()->prepare('SELECT COUNT(*) FROM job_applications WHERE job_id = ? AND status = "Diterima"');
        $accStmt->execute([$jobId]);
        $acceptedCount = (int)$accStmt->fetchColumn();
        $requestedQuota = (int)($oldJob['quota'] ?? 1);
        $sisaKuota = max(0, $requestedQuota - $acceptedCount);

        // Update accepted_count on source job
        db()->prepare('UPDATE job_posts SET accepted_count = ? WHERE id = ?')->execute([$acceptedCount, $jobId]);

        if ($repost && ($isTransitionPeriod || $isFullDisable || $verificationStatus === 'SUSPENDED')) {
            flash('error', 'Akun dalam Masa Transisi (Akses Dibatasi) atau terkunci. Tidak dapat memposting ulang sisa kuota.');
            redirect('dashboard.php#lowongan');
            exit;
        }
        
        if (empty($reasons)) {
            flash('error', 'Anda wajib memilih minimal 1 alasan mengapa lowongan diselesaikan / sisa kuota belum terpenuhi.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        $validReasons = pki_close_reasons();
        foreach ($reasons as $r) {
            if (!in_array($r, $validReasons, true)) {
                flash('error', 'Alasan penutupan tidak valid.');
                redirect('dashboard.php#lowongan');
                exit;
            }
        }

        if (in_array('Lainnya', $reasons, true) && $lainnya === '') {
            flash('error', 'Alasan "Lainnya" wajib diisi.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        $reasonStr = implode(', ', $reasons);
        if (in_array('Lainnya', $reasons, true)) {
            $reasonStr .= ' - ' . $lainnya;
        }

        // Close original job
        $stmt = db()->prepare('UPDATE job_posts SET status = "Ditutup", unfulfilled_reason = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$reasonStr, $jobId, $user['id']]);

        if ($repost && $sisaKuota > 0) {
            // Create child posting with quota = sisa_kuota, status = Menunggu Verifikasi
            $insert = db()->prepare('INSERT INTO job_posts (user_id, title, description, location, job_type, industry, entity_type, status, quota, accepted_count, kbji_code, min_education, min_experience, parent_job_id, created_at) VALUES (?, ?, ?, ?, ?, ?, "Individu", "Menunggu Verifikasi", ?, 0, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
            $insert->execute([
                $user['id'], 
                $oldJob['title'] . ' (Posting Ulang Sisa Kuota)', 
                $oldJob['description'], 
                $oldJob['location'], 
                $oldJob['job_type'], 
                $oldJob['industry'], 
                $sisaKuota, 
                $oldJob['kbji_code'], 
                $oldJob['min_education'] ?? '', 
                $oldJob['min_experience'] ?? '', 
                $jobId
            ]);
            $childId = (int)db()->lastInsertId();

            try {
                $caseStmt = db()->prepare('INSERT INTO job_verifications (job_id, user_id, kbji_code, status, layer_flags) VALUES (?, ?, ?, "PENDING", "REPOST_CONTINUATION")');
                $caseStmt->execute([$childId, $user['id'], $oldJob['kbji_code']]);
            } catch (Throwable $ignored) {}

            flash('success', 'Lowongan awal telah Ditutup. Posting turunan sisa kuota (' . $sisaKuota . ' posisi) berhasil dibuat dan sedang Menunggu Verifikasi.');
        } else {
            flash('success', 'Lowongan berhasil Ditutup.');
        }

        redirect('dashboard.php#lowongan');
        exit;
    }

    // 6. AJUKAN PERPANJANGAN WAKTU (1x)
    if (isset($_POST['request_extension'])) {
        if (($profile['extension_requested'] ?? 0) == 0) {
            $stmt = db()->prepare('UPDATE employer_profiles SET extension_requested = 1, extension_status = "REQUESTED" WHERE user_id = ?');
            $stmt->execute([$user['id']]);
            flash('success', 'Permohonan perpanjangan masa aktif (1x) berhasil dikirim ke Admin Dinas. Silakan menunggu persetujuan.');
        } else {
            flash('error', 'Anda sudah pernah mengajukan perpanjangan masa aktif.');
        }
        redirect('dashboard.php');
        exit;
    }

    // 7. HAPUS DRAFT LOWONGAN
    if (isset($_POST['delete_job'])) {
        $jobId = (int)$_POST['job_id'];
        $stmt = db()->prepare('DELETE FROM job_posts WHERE id = ? AND user_id = ? AND status = "Draft"');
        $stmt->execute([$jobId, $user['id']]);
        flash('success', 'Draft lowongan berhasil dihapus.');
        redirect('dashboard.php#lowongan');
        exit;
    }
}

$ownerName = $profile['owner_name'] ?? $user['name'];
$profession = $profile['profession'] ?? 'Kuliner';
$city = $profile['city'] ?? 'Kota Bekasi';

// Ambil flash message SEBELUM ob_start (karena session harus dibaca dulu)
$flashData = get_flash();
$flashHtml = '';
$pendingPopupMessage = null;
if ($flashData && $flashData['type'] === 'pending_popup') {
    $pendingPopupMessage = $flashData['message'];
    $flashData = null;
} elseif ($flashData) {
    $flashType = $flashData['type'] === 'success' ? 'success' : 'error';
    $flashIcon = $flashType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation';
    $flashColor = $flashType === 'success'
        ? 'background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;'
        : 'background:#fef2f2;border:1px solid #fecaca;color:#991b1b;';
    $flashHtml = '<div style="position:fixed;top:20px;right:20px;z-index:9999;max-width:400px;border-radius:14px;padding:14px 18px;font-size:14px;font-weight:600;display:flex;align-items:center;gap:10px;box-shadow:0 8px 24px rgba(0,0,0,0.12);' . $flashColor . '">';
    $flashHtml .= '<i class="fa-solid ' . $flashIcon . '"></i>';
    $flashHtml .= htmlspecialchars($flashData['message'], ENT_QUOTES, 'UTF-8');
    $flashHtml .= '<button onclick="this.parentElement.remove()" style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:16px;color:inherit;opacity:0.6;">×</button>';
    $flashHtml .= '</div>';
}

$kbjiDuplicateError = $_SESSION['kbji_duplicate_error'] ?? null;
unset($_SESSION['kbji_duplicate_error']);

if (isset($_GET['open_profile']) && $_GET['open_profile'] == '1') {
    $showProfileModal = true;
} else {
    $showProfileModal = ($verificationStatus === 'NOT_SUBMITTED');
}

ob_start();
include __DIR__ . '/Index.html';
$html = ob_get_clean();

// Sisipkan flash toast ke dalam body
if ($flashHtml) {
    $html = preg_replace('/(<body[^>]*>)/i', '$1' . "\n" . $flashHtml, $html, 1);
}

$initials = mb_strtoupper(mb_substr($ownerName, 0, 1));
if (str_contains($ownerName, ' ')) {
    $parts = explode(' ', $ownerName);
    $initials = mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
}

$replacements = [
    'Karirhub - Pemberi Kerja Individu'   => 'Karirhub - ' . htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8'),
    'Halo nama Pemberi Kerja Individu'    => 'Halo ' . htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8'),
    // Sidebar profile card
    'id="sidebarName">Pemberi Kerja Individu' => 'id="sidebarName">' . htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8'),
    'id="sidebarProfession">Profesi: Kuliner'  => 'id="sidebarProfession">Profesi: ' . htmlspecialchars($profession, ENT_QUOTES, 'UTF-8'),
    'id="sidebarAvatar">PI'               => 'id="sidebarAvatar">' . $initials,
    // Topbar chip
    '<strong>Pemberi Kerja Individu</strong>' => '<strong>' . htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8') . '</strong>',
    '<span>Profesi: Kuliner</span>'        => '<span>Profesi: ' . htmlspecialchars($profession, ENT_QUOTES, 'UTF-8') . '</span>',
    // Profile page
    'PT. Pandu Jaya'                       => htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8'),
    'Kota Bekasi'                          => htmlspecialchars($city, ENT_QUOTES, 'UTF-8'),
    '<strong>Sisa 87 hari</strong>'        => '<strong>Sisa ' . max(0, $daysRemaining) . ' hari</strong>',
];

$html = str_replace(array_keys($replacements), array_values($replacements), $html);

$modalStyles = <<<'CSS'
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: none;
            align-items: center;
            justify-content: flex-end;
            z-index: 1000;
            padding: 16px;
        }
        .modal-backdrop.open {
            display: flex;
        }
        .modal-panel {
            width: min(720px, 100%);
            max-height: calc(100vh - 32px);
            overflow: auto;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 24px 80px rgba(15, 23, 42, 0.28);
        }
        .job-create-panel {
            width: min(860px, 100%);
            height: calc(100vh - 32px);
            max-height: calc(100vh - 32px);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            min-height: 0;
        }
        .job-create-panel form {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }
        .modal-header,
        .modal-footer {
            padding: 20px 24px 16px;
            border-bottom: 1px solid #eef2f7;
            flex-shrink: 0;
        }
        .job-create-panel .modal-header {
            position: relative;
            padding-right: 56px;
        }
        .modal-footer {
            border-bottom: none;
            border-top: 1px solid #eef2f7;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 14px 24px;
        }
        .modal-title {
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -0.02em;
            margin-bottom: 4px;
            color: #111827;
        }
        .modal-subtitle {
            color: #6b7280;
            font-size: 13px;
        }
        .revision-banner {
            margin-top: 12px;
            padding: 12px 14px;
            border-radius: 12px;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #9a3412;
        }
        .revision-banner strong {
            display: block;
            font-size: 12px;
            margin-bottom: 4px;
        }
        .revision-banner p {
            margin: 0;
            font-size: 13px;
            line-height: 1.5;
        }
        .step-progress {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 24px 0;
            background: #fff;
            flex-shrink: 0;
        }
        .step-progress-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 700;
            color: #94a3b8;
        }
        .step-progress-item.active {
            color: #0284c7;
        }
        .step-progress-item.done {
            color: #0f172a;
        }
        .step-progress-item .bubble {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #e2e8f0;
            color: #475569;
            font-size: 11px;
            font-weight: 800;
        }
        .step-progress-item.active .bubble {
            background: #0284c7;
            color: #fff;
        }
        .step-progress-item.done .bubble {
            background: #0f172a;
            color: #fff;
        }
        .step-progress-line {
            flex: 1;
            height: 2px;
            background: #e2e8f0;
            margin: 0 10px;
        }
        .step-progress-line.done {
            background: #0284c7;
        }
        .rich-editor-shell {
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
        }
        .rich-editor-shell:focus-within {
            border-color: #0284c7;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.12);
        }
        .rich-toolbar {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 8px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }
        .rich-btn {
            border: 1px solid transparent;
            background: transparent;
            color: #475569;
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 12px;
            cursor: pointer;
        }
        .rich-btn:hover {
            background: #e2e8f0;
            color: #0f172a;
        }
        .rich-area {
            min-height: 120px;
            max-height: 200px;
            overflow: auto;
            padding: 10px 12px;
            outline: none;
            font-size: 13px;
            line-height: 1.6;
        }
        .choice-chip-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 8px;
        }
        .choice-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 9999px;
            background: #e0f2fe;
            color: #0369a1;
            font-size: 12px;
            font-weight: 600;
        }
        .choice-chip button {
            border: none;
            background: transparent;
            color: inherit;
            cursor: pointer;
            padding: 0;
            font-size: 14px;
            line-height: 1;
        }
        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 8px;
        }
        .checkbox-card {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 13px;
            cursor: pointer;
            background: #f8fafc;
        }
        .checkbox-card:hover {
            background: #f1f5f9;
        }
CSS;

$html = str_replace('</head>', "<style>\n" . $modalStyles . "\n</style>\n</head>", $html);

$modal = <<<'HTML'
    <div class="modal-backdrop" data-modal="job-create">
        <div class="modal-panel job-create-panel" role="dialog" aria-modal="true">
            <div class="modal-header">
                <button type="button" class="modal-close" data-close-modal="job-create" aria-label="Tutup"><i class="fa-solid fa-xmark"></i></button>
                <div class="modal-title" id="jobCreateTitle">Tambah Lowongan</div>
                <div class="modal-subtitle" id="jobCreateSubtitle">Lengkapi formulir 3 langkah untuk membuat lowongan baru</div>
                <div class="revision-banner" id="revisionBanner" hidden>
                    <strong><i class="fa-solid fa-triangle-exclamation"></i> Catatan Revisi dari Admin</strong>
                    <p id="revisionBannerText"></p>
                </div>
            </div>
            <div class="step-progress" aria-hidden="true">
                <div class="step-progress-item active" data-step-label="1"><span class="bubble">1</span><span>Informasi Loker</span></div>
                <div class="step-progress-line" data-step-line="1"></div>
                <div class="step-progress-item" data-step-label="2"><span class="bubble">2</span><span>Persyaratan</span></div>
                <div class="step-progress-line" data-step-line="2"></div>
                <div class="step-progress-item" data-step-label="3"><span class="bubble">3</span><span>Tambahan</span></div>
            </div>
            <form method="post" action="dashboard.php" data-job-create-form>
                <input type="hidden" name="save_job" value="1">
                <input type="hidden" name="job_action" value="save">
                <input type="hidden" name="job_id" id="reviseJobId" value="">
                <div class="modal-body" style="flex:1; overflow-y:auto; padding:20px 24px;">
                    <!-- Step 1: Informasi Loker -->
                    <div class="form-step active" data-job-step="1">
                        <div class="field" style="margin-bottom:14px;">
                            <label>Judul Lowongan <span class="req">*</span></label>
                            <input type="text" name="job_title" placeholder="Contoh: Barista, Asisten Rumah Tangga, Supir Pribadi" required>
                        </div>
                        <div class="field" style="margin-bottom:14px;">
                            <label>Deskripsi Pekerjaan <span class="req">*</span></label>
                            <div class="rich-editor-shell" data-rich-editor>
                                <div class="rich-toolbar">
                                    <button type="button" class="rich-btn" data-cmd="bold"><b>B</b></button>
                                    <button type="button" class="rich-btn" data-cmd="italic"><i>I</i></button>
                                    <button type="button" class="rich-btn" data-cmd="insertUnorderedList">• List</button>
                                </div>
                                <div class="rich-area" contenteditable="true"></div>
                                <textarea name="job_description" hidden></textarea>
                            </div>
                        </div>
                        <div class="field-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:14px;">
                            <div class="field">
                                <label>Kode KBJI <span class="req">*</span></label>
                                <select name="kbji_code" required>
                                    <option value="">Pilih Jabatan (KBJI)</option>
                                    <option value="5120.01">5120.01 - Juru Masak / Koki</option>
                                    <option value="5131.00">5131.00 - Pelayan Restoran / Kafe</option>
                                    <option value="5151.01">5151.01 - Pengurus Rumah Tangga / ART</option>
                                    <option value="8322.01">8322.01 - Pengemudi Mobil Pribadi</option>
                                    <option value="5322.00">5322.00 - Pengasuh Anak / Babysitter</option>
                                    <option value="5414.01">5414.01 - Penjaga Keamanan / Satpam</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>Jenis Pekerjaan <span class="req">*</span></label>
                                <select name="job_type" required>
                                    <option value="Penuh Waktu">Penuh Waktu</option>
                                    <option value="Paruh Waktu">Paruh Waktu</option>
                                    <option value="Kontrak">Kontrak</option>
                                    <option value="Harian Lepas">Harian Lepas</option>
                                </select>
                            </div>
                        </div>
                        <div class="field-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                            <div class="field">
                                <label>Bidang Pekerjaan <span class="req">*</span></label>
                                <input type="text" name="job_field" placeholder="Contoh: Kuliner, Domestik, Logistik" required>
                            </div>
                            <div class="field">
                                <label>Industri <span class="req">*</span></label>
                                <input type="text" name="industry" placeholder="Contoh: Rumah Tangga, Jasa Makanan" required>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Persyaratan -->
                    <div class="form-step" data-job-step="2" hidden>
                        <div class="field-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:14px;">
                            <div class="field">
                                <label>Minimal Pendidikan <span class="req">*</span></label>
                                <select name="education_required" required>
                                    <option value="Tidak Ada Minimal">Tidak Ada Minimal</option>
                                    <option value="SD">SD Sederajat</option>
                                    <option value="SMP">SMP Sederajat</option>
                                    <option value="SMA/SMK">SMA/SMK Sederajat</option>
                                    <option value="Diploma">Diploma (D3)</option>
                                    <option value="Sarjana">Sarjana (S1)</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>Minimal Pengalaman <span class="req">*</span></label>
                                <select name="experience_required" required>
                                    <option value="Fresh Graduate / Pemula">Fresh Graduate / Pemula</option>
                                    <option value="Kurang dari 1 tahun">Kurang dari 1 tahun</option>
                                    <option value="1 - 3 tahun">1 - 3 tahun</option>
                                    <option value="Lebih dari 3 tahun">Lebih dari 3 tahun</option>
                                </select>
                            </div>
                        </div>
                        <div class="field-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:14px;">
                            <div class="field">
                                <label>Usia Minimal</label>
                                <input type="number" name="age_min" placeholder="Contoh: 18">
                            </div>
                            <div class="field">
                                <label>Usia Maksimal</label>
                                <input type="number" name="age_max" placeholder="Contoh: 45">
                            </div>
                        </div>
                        <div class="field" style="margin-bottom:14px;">
                            <label>Keahlian yang Dibutuhkan</label>
                            <input type="text" data-chip-input="skills" placeholder="Ketik keahlian lalu tekan Enter...">
                            <input type="hidden" name="skills" data-chip-value="skills">
                            <div class="choice-chip-wrap" data-chip-list="skills"></div>
                        </div>
                    </div>

                    <!-- Step 3: Tambahan -->
                    <div class="form-step" data-job-step="3" hidden>
                        <div class="field" style="margin-bottom:14px;">
                            <label>Lokasi Kerja <span class="req">*</span></label>
                            <input type="text" name="job_location" placeholder="Kota / Wilayah Kerja" required>
                        </div>
                        <div class="field-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:14px;">
                            <div class="field">
                                <label>Gaji Minimal (Rp)</label>
                                <input type="number" name="salary_min" placeholder="0">
                            </div>
                            <div class="field">
                                <label>Gaji Maksimal (Rp)</label>
                                <input type="number" name="salary_max" placeholder="0">
                            </div>
                        </div>
                        <div class="field" style="margin-bottom:14px;">
                            <label style="display:flex; align-items:center; gap:8px; font-weight:normal; cursor:pointer;">
                                <input type="checkbox" name="show_salary" value="1">
                                <span>Tampilkan besaran gaji kepada pencari kerja</span>
                            </label>
                        </div>
                        <div class="field-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                            <div class="field">
                                <label>Kuota Penerimaan (Orang) <span class="req">*</span></label>
                                <input type="number" name="quota" min="1" value="1" required>
                            </div>
                            <div class="field">
                                <label>Masa Berlaku Tayang (Hari) <span class="req">*</span></label>
                                <select name="expiry_days" required>
                                    <option value="14">14 Hari</option>
                                    <option value="30" selected>30 Hari</option>
                                    <option value="60">60 Hari</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="ghost-btn" data-job-cancel data-close-modal="job-create">Batal</button>
                    <button type="button" class="ghost-btn" data-job-back hidden><i class="fa-solid fa-arrow-left"></i> Kembali</button>
                    <button type="button" class="primary-btn" data-job-next>Lanjut <i class="fa-solid fa-arrow-right"></i></button>
                    <button type="submit" class="primary-btn" data-job-submit hidden><i class="fa-solid fa-paper-plane"></i> TAMBAH LOKER</button>
                </div>
            </form>
        </div>
    </div>
    <div class="modal-backdrop" data-modal="job-close">
        <div class="modal-panel" style="width: min(500px, 100%);" role="dialog">
            <div class="modal-header">
                <div class="modal-title">Selesaikan Lowongan & Tetapkan Kandidat</div>
                <div class="modal-subtitle">Kuota belum terpenuhi. Mohon isi alasan.</div>
            </div>
            <form method="post" action="dashboard.php">
                <input type="hidden" name="close_job" value="1">
                <input type="hidden" name="job_id" id="close_job_id" value="">
                <input type="hidden" name="sisa_kuota" id="close_sisa_kuota" value="1">
                <div class="modal-body">
                    <div class="modal-section" style="border-bottom:none;">
                        <div class="section-text" style="margin-bottom:8px;">
                            Anda menetapkan kandidat kurang dari kuota yang tersedia. Mohon pilih alasan mengapa sisa kuota belum terpenuhi (pilih minimal 1):
                        </div>
                        <div style="display:flex; flex-direction:column; gap:8px; margin-bottom: 16px;">
                            <label style="font-size:13px;"><input type="checkbox" name="reasons[]" value="Jumlah pelamar belum mencukupi"> Jumlah pelamar belum mencukupi</label>
                            <label style="font-size:13px;"><input type="checkbox" name="reasons[]" value="Pelamar belum sesuai kompetensi/kualifikasi"> Pelamar belum sesuai kompetensi/kualifikasi</label>
                            <label style="font-size:13px;"><input type="checkbox" name="reasons[]" value="Pelamar mengundurkan diri"> Pelamar mengundurkan diri</label>
                            <label style="font-size:13px;"><input type="checkbox" name="reasons[]" value="Kandidat tidak hadir/tidak melanjutkan proses seleksi"> Kandidat tidak hadir/tidak melanjutkan proses seleksi</label>
                            <label style="font-size:13px;"><input type="checkbox" name="reasons[]" value="Kesepakatan kerja tidak tercapai"> Kesepakatan kerja tidak tercapai</label>
                            <label style="font-size:13px;"><input type="checkbox" name="reasons[]" value="Lainnya" onchange="document.getElementById('reason_lainnya').style.display = this.checked ? 'block' : 'none'"> Lainnya</label>
                            <textarea id="reason_lainnya" name="reason_lainnya" placeholder="Tulis alasan spesifik Anda..." style="display:none; font-size:13px; padding:8px; border:1px solid #dbe7f0; border-radius:8px; min-height:60px; margin-top:4px;"></textarea>
                        </div>
                        
                        <div style="background:#f0f9ff; padding:12px; border-radius:8px; border:1px solid #bae6fd;">
                            <div style="font-weight:700; font-size:13px; color:#0369a1; margin-bottom:4px;">Posting Ulang Sisa Kuota?</div>
                            <div style="font-size:12px; color:#0c4a6e; margin-bottom:10px;">Apakah Anda ingin mempublikasikan ulang lowongan ini secara otomatis untuk memenuhi sisa kuota?</div>
                            <select name="repost" required style="width:100%; padding:8px; border:1px solid #bae6fd; border-radius:6px; font-size:13px;">
                                <option value="">Pilih tindakan...</option>
                                <option value="1">Ya, Posting Ulang Sisa Kuota</option>
                                <option value="0">Tidak, Tutup Saja</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="ghost-btn" data-close-modal="job-close">Batal</button>
                    <button type="submit" class="primary-btn">Simpan & Selesaikan</button>
                </div>
            </form>
        </div>
    </div>
    <div class="modal-backdrop" data-modal="applicant-profile">
        <div class="modal-panel applicant-profile-panel" role="dialog" aria-modal="true">
            <div class="modal-header">
                <button type="button" class="modal-close" data-close-modal="applicant-profile" aria-label="Tutup"><i class="fa-solid fa-xmark"></i></button>
                <div class="modal-title" id="applicantName">Profil Pelamar</div>
                <div class="modal-subtitle" id="applicantJob">Lowongan</div>
            </div>
            <form method="post" action="dashboard.php#lowongan">
                <input type="hidden" name="update_application_status" value="1">
                <input type="hidden" name="application_id" id="applicantId" value="">
                <div class="modal-body">
                    <div class="field">
                        <label>Status pelamar</label>
                        <select class="status-select" name="status" id="applicantStatus">
                            <option>Lamaran Masuk</option>
                            <option>Sedang Dipelajari</option>
                            <option>Wawancara</option>
                            <option>Diterima</option>
                            <option>Ditolak</option>
                        </select>
                    </div>
                    <div class="applicant-profile-grid" id="applicantBiodata"></div>
                    <div class="profile-block"><h4>Pendidikan</h4><div id="applicantEducation"></div></div>
                    <div class="profile-block"><h4>Pengalaman</h4><div id="applicantExperience"></div></div>
                    <div class="profile-block"><h4>Keahlian</h4><div id="applicantSkills"></div></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="ghost-btn" data-close-modal="applicant-profile">Tutup</button>
                    <button type="submit" class="primary-btn">Simpan Status</button>
                </div>
            </form>
        </div>
    </div>
HTML;

$html = str_replace('</body>', $modal . "\n</body>", $html);

$html = str_replace('<button class="primary-btn"><i class="fa-solid fa-plus"></i> Tambah Lowongan</button>', '<button class="primary-btn" data-open-modal="job-create"><i class="fa-solid fa-plus"></i> Tambah Lowongan</button>', $html);
$html = str_replace('<button class="primary-btn"><i class="fa-solid fa-plus"></i> Tambah Lowongan', '<button class="primary-btn" data-open-modal="job-create"><i class="fa-solid fa-plus"></i> Tambah Lowongan', $html);

if (empty($profile['verified']) || $isTransitionPeriod || $isFullDisable || $verificationStatus === 'SUSPENDED') {
    $html = str_replace('<button class="primary-btn" data-open-modal="job-create"><i class="fa-solid fa-plus"></i> Tambah Lowongan</button>', '<button class="primary-btn" style="opacity:0.5;cursor:not-allowed;" disabled><i class="fa-solid fa-plus"></i> Tambah Lowongan</button>', $html);
    $html = str_replace('<button class="primary-btn" data-open-modal="job-create"><i class="fa-solid fa-plus"></i> Tambah Lowongan', '<button class="primary-btn" style="opacity:0.5;cursor:not-allowed;" disabled><i class="fa-solid fa-plus"></i> Tambah Lowongan', $html);
}

// Lock state logic
$isDashboardLocked = in_array($verificationStatus, ['NOT_SUBMITTED', 'PENDING', 'SUSPENDED', 'FULL_DISABLED'], true);

if (!str_contains($html, 'src="assets/app.js"')) {
    $html = str_replace('</body>', '<script src="assets/app.js"></script>' . "\n</body>", $html);
}

if (!str_contains($html, 'window.testCloseJob')) {
    $html = str_replace('</body>', <<<'JS'
    <script>
    window.testCloseJob = function(jobId, sisaKuota) {
        document.getElementById("close_job_id").value = jobId;
        document.getElementById("close_sisa_kuota").value = sisaKuota;
        const modal = document.querySelector("[data-modal='job-close']");
        if (modal) modal.classList.add("open");
    };
    </script>
</body>
JS, $html);
}

$employerJobs = db()->prepare('SELECT * FROM job_posts WHERE user_id = ? ORDER BY created_at DESC');
$employerJobs->execute([$user['id']]);
$employerJobs = $employerJobs->fetchAll();

$jobCounts = [
    'Draft' => 0, 
    'Menunggu Verifikasi' => 0, 
    'Perlu Direvisi' => 0, 
    'Tayang' => 0,
    'Ditolak' => 0,
    'Ditutup' => 0
];
foreach ($employerJobs as $row) {
    $cStat = normalize_job_status($row['status'] ?? '');
    if (isset($jobCounts[$cStat])) {
        $jobCounts[$cStat]++;
    }
}

$jobRowsHtml = '';
if (!$employerJobs) {
    $jobRowsHtml = '<tr><td colspan="6" style="text-align:center;color:#64748b;padding:28px">Belum ada lowongan. Klik Tambah Lowongan untuk membuat postingan baru.</td></tr>';
} else {
    foreach ($employerJobs as $row) {
        $meta = job_status_meta($row['status']);
        $countStmt = db()->prepare('SELECT COUNT(*) FROM job_applications WHERE job_id = ?');
        $countStmt->execute([$row['id']]);
        $appCount = (int) $countStmt->fetchColumn();
        $reviseBtn = in_array($row['status'], ['Perlu Direvisi', 'Perlu Revisi'], true)
            ? '<button type="button" class="ghost-btn" data-revise-job="' . (int) $row['id'] . '">Revisi</button>'
            : '-';
        $adminNoteHtml = '';
        if (in_array($row['status'], ['Perlu Direvisi', 'Perlu Revisi'], true) && trim((string) ($row['admin_notes'] ?? '')) !== '') {
            $adminNoteHtml = '<div class="tiny" style="color:#b45309">Catatan admin: ' . e($row['admin_notes']) . '</div>';
        }
        $jobRowsHtml .= '<tr><td><strong>' . e($row['title']) . '</strong><div class="tiny">Dibuat ' . e(date('d M Y', strtotime($row['created_at']))) . '</div>' . $adminNoteHtml . '</td>'
            . '<td>' . e($row['location']) . '</td>'
            . '<td>' . (int) $row['quota'] . ' orang</td>'
            . '<td>' . $appCount . ' pelamar</td>'
            . '<td><span class="status ' . e($meta['class']) . '">' . e($meta['label']) . '</span></td>'
            . '<td>' . $reviseBtn . '</td></tr>';
    }
}

$applicants = employer_applicants((int) $user['id']);
$stageCounts = [
    'Lamaran Masuk' => 0,
    'Sedang Dipelajari' => 0,
    'Wawancara' => 0,
    'Diterima' => 0,
    'Ditolak' => 0,
];
foreach ($applicants as $applicant) {
    $stage = normalize_application_status($applicant['status'] ?? '');
    if (isset($stageCounts[$stage])) {
        $stageCounts[$stage]++;
    }
}
$totalApplicants = count($applicants);
$funnelTotal = max(1, $totalApplicants);
$hiredRate = $totalApplicants ? (int) round(($stageCounts['Diterima'] / $totalApplicants) * 100) : 0;
$funnelRow = static function (string $label, int $count, int $total, string $extraClass = '') {
    $width = $total > 0 ? max(8, (int) round(($count / $total) * 100)) : 8;
    return '<div class="funnel-row"><div class="funnel-label">' . e($label) . '</div><div class="funnel-track"><div class="funnel-fill ' . $extraClass . '" style="width:' . $width . '%">' . $count . '</div></div><div class="funnel-percent">' . $width . '%</div></div>';
};
$funnelHtml = '<div class="funnel-layout"><div class="funnel-bars">'
    . $funnelRow('Pelamar Masuk', $totalApplicants, $funnelTotal)
    . $funnelRow('Dipelajari', $stageCounts['Sedang Dipelajari'], $funnelTotal, 'soft')
    . $funnelRow('Wawancara', $stageCounts['Wawancara'], $funnelTotal, 'softest')
    . $funnelRow('Diterima', $stageCounts['Diterima'], $funnelTotal, 'pale')
    . '</div><div class="funnel-stats"><h4>Konversi keseluruhan</h4><h2>' . $hiredRate . '%</h2><p>dari pelamar masuk sampai diterima</p><div class="stat-block"><div style="color:var(--muted);font-size:12px;margin-bottom:8px;">Tidak berlanjut</div><div class="stat-block-row"><span>Ditolak</span><strong>' . $stageCounts['Ditolak'] . '</strong></div></div></div></div>';

if (!$applicants) {
    $readyHtml = '<div class="empty-state"><i class="fa-solid fa-user-group" style="color:#c9dff7"></i><h4>Belum ada pelamar</h4><p>Pelamar yang masuk akan<br>muncul di sini.</p></div>';
    $applicantHtml = '<div class="record-item"><strong>Belum ada pelamar</strong><span>Lamaran dari pencari kerja akan tampil di sini.</span></div>';
    $activityHtml = '<div class="list"><div class="activity-item"><div class="activity-title">Belum ada aktivitas lamaran.</div><div class="activity-subtitle">Riwayat perubahan status akan muncul di sini.</div></div></div>';
} else {
    $readyHtml = '<div class="applicant-ready-list">';
    foreach ($applicants as $index => $applicant) {
        $meta = application_status_meta($applicant['status']);
        $hidden = $index >= 4 ? ' hidden' : '';
        $avatar = 'https://ui-avatars.com/api/?name=' . rawurlencode($applicant['seeker_name']) . '&background=e8f7fc&color=1e97c4';
        $readyHtml .= '<button type="button" class="applicant-item" data-open-applicant="' . (int) $applicant['id'] . '"' . $hidden . '>'
            . '<img class="avatar" src="' . e($avatar) . '" alt="">'
            . '<div class="meta"><div class="title">' . e($applicant['seeker_name']) . '</div><div class="subtitle">' . e($applicant['job_title']) . '</div></div>'
            . '<span class="tag ' . e($meta['class']) . '">' . e($meta['label']) . '</span></button>';
    }
    $readyHtml .= '</div>';
    if ($totalApplicants > 4) {
        $readyHtml .= '<button type="button" class="show-more-btn" data-expand-applicants>Tampilkan lebih banyak</button>';
    }

    $applicantHtml = '<table class="applicant-table"><thead><tr><th>Pelamar</th><th>Lowongan</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach ($applicants as $applicant) {
        $meta = application_status_meta($applicant['status']);
        $applicantHtml .= '<tr data-open-applicant="' . (int) $applicant['id'] . '"><td><strong>' . e($applicant['seeker_name']) . '</strong><div class="tiny">' . e($applicant['seeker_email']) . '</div></td><td>' . e($applicant['job_title']) . '</td><td><span class="tag ' . e($meta['class']) . '">' . e($meta['label']) . '</span></td><td>Lihat profil</td></tr>';
    }
    $applicantHtml .= '</tbody></table>';

    $activityHtml = '<div class="list">';
    foreach (array_slice($applicants, 0, 5) as $applicant) {
        $meta = application_status_meta($applicant['status']);
        $when = $applicant['updated_at'] ?: $applicant['created_at'];
        $activityHtml .= '<div class="activity-item"><div class="activity-title">' . e($applicant['seeker_name']) . ' · ' . e($meta['label']) . '</div><div class="activity-subtitle">' . e($applicant['job_title']) . '</div><div class="activity-meta">' . e(date('d M Y H:i', strtotime((string) $when))) . '</div></div>';
    }
    $activityHtml .= '</div>';
}

$notifications = user_notifications((int) $user['id']);
$unread = unread_notification_count((int) $user['id']);
$html = str_replace('<div class="notif"><i class="fa-regular fa-bell"></i></div>', render_notif_dropdown($notifications, $unread), $html);
$html = preg_replace('/<h3>Draft<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Draft</h3><div class="value">' . $jobCounts['Draft'] . '</div>', $html, 1);
$html = preg_replace('/<h3>Dikirim<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Dikirim</h3><div class="value">' . $jobCounts['Menunggu Verifikasi'] . '</div>', $html, 1);
$html = preg_replace('/<h3>Perlu Direvisi<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Perlu Direvisi</h3><div class="value">' . $jobCounts['Perlu Direvisi'] . '</div>', $html, 1);
$html = preg_replace('/<h3>Lowongan Aktif<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Lowongan Aktif</h3><div class="value">' . $jobCounts['Tayang'] . '</div>', $html, 1);
$html = str_replace('<!--JOB_TABLE_ROWS-->', $jobRowsHtml, $html);
$html = str_replace('<!--JOB_APPLICANTS-->', $applicantHtml, $html);
$html = str_replace('<!--READY_APPLICANTS-->', $readyHtml, $html);
$html = str_replace('<!--FUNNEL_METRICS-->', $funnelHtml, $html);
$html = str_replace('<!--APPLICATION_ACTIVITY-->', $activityHtml, $html);
$html = preg_replace('/<h3>Lowongan<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Lowongan</h3><div class="value">' . count($employerJobs) . '</div>', $html, 1);
$html = preg_replace('/<h3>Pelamar<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Pelamar</h3><div class="value">' . $totalApplicants . '</div>', $html, 1);
$html = preg_replace('/<h3>Wawancara<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Wawancara</h3><div class="value">' . $stageCounts['Wawancara'] . '</div>', $html, 1);
$html = preg_replace('/<h3>Diterima<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Diterima</h3><div class="value">' . $stageCounts['Diterima'] . '</div>', $html, 1);

echo $html;