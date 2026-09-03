<?php
require_once __DIR__ . '/includes/bootstrap.php';


$user = require_role('employer');

// Handle context switch request
if (isset($_GET['switch_context']) && $_GET['switch_context'] === 'seeker') {
    set_active_context('seeker');
    redirect('seeker.php');
}

// Fetch Employer Profile
$profileStatement = db()->prepare('SELECT * FROM employer_profiles WHERE user_id = ? LIMIT 1');
$profileStatement->execute([$user['id']]);
$profile = $profileStatement->fetch() ?: [];

// Fetch Jobs
$jobsStatement = db()->prepare('SELECT * FROM job_posts WHERE user_id = ? ORDER BY id DESC');
$jobsStatement->execute([$user['id']]);
$jobs = $jobsStatement->fetchAll() ?: [];

// Status calculation logic according to FSD Final
$verificationStatus = $profile['verification_status'] ?? 'NOT_SUBMITTED';
if (empty($profile)) {
    $verificationStatus = 'NOT_SUBMITTED';
} elseif ($verificationStatus === 'NOT_SUBMITTED' && !empty($profile['owner_name'])) {
    $verificationStatus = $profile['verified'] ? 'APPROVED' : 'PENDING';
}

$activeUntilRaw = $profile['active_until'] ?? null;
$now = new DateTime();
$activeUntil = $activeUntilRaw ? new DateTime($activeUntilRaw) : null;

$daysRemaining = 0;
$isExpired = false;
$isTransitionPeriod = false;
$isFullDisable = false;

if ($verificationStatus === 'APPROVED' && $activeUntil) {
    $diff = $now->diff($activeUntil);
    $isExpired = $activeUntil < $now;
    $daysRemaining = $isExpired ? -$diff->days : $diff->days;

    if ($isExpired) {
        if ($daysRemaining >= -7) {
            $verificationStatus = 'TRANSITION_LIMITED';
            $isTransitionPeriod = true;
        } else {
            $verificationStatus = 'FULL_DISABLED';
            $isFullDisable = true;
        }
    }
}

// Lock state logic
$isDashboardLocked = in_array($verificationStatus, ['NOT_SUBMITTED', 'PENDING', 'SUSPENDED', 'FULL_DISABLED']);
if (isset($_GET['open_profile']) && $_GET['open_profile'] == '1') {
    $showProfileModal = true;
} else {
    $showProfileModal = ($verificationStatus === 'NOT_SUBMITTED');
}

// --- POST HANDLERS ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    
    // 1. SIMPAN & AJUKAN PROFIL PEMBERI KERJA INDIVIDU
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

        $latitude    = trim($_POST['latitude'] ?? '-6.241586');
        $longitude   = trim($_POST['longitude'] ?? '106.992416');
        $description = trim($_POST['description'] ?? '');
        $consent     = isset($_POST['user_consent']) ? 1 : 0;

        if ($ownerName === '' || $nik === '' || $phone === '' || $profession === '' || $npwp === '' || $province === '' || $city === '' || $address === '' || !$consent) {
            flash('error', 'Lengkapi semua field wajib dan centang persetujuan pengguna.');
            redirect('dashboard.php?open_profile=1');
            exit;
        }

        if ($profile) {
            $stmt = db()->prepare('UPDATE employer_profiles SET 
                owner_name = ?, nik = ?, phone = ?, whatsapp = ?, profession = ?, npwp = ?,
                linkedin = ?, facebook = ?, instagram = ?,
                same_location_siapkerja = ?, province = ?, city = ?, district = ?, village = ?, postal_code = ?,
                same_address_siapkerja = ?, address = ?, address_detail = ?,
                latitude = ?, longitude = ?, description = ?, user_consent = ?,
                verification_status = "PENDING", verified = 0, updated_at = NOW()
                WHERE user_id = ?');
            $stmt->execute([
                $ownerName, $nik, $phone, $whatsapp, $profession, $npwp,
                $linkedin, $facebook, $instagram,
                $sameLoc, $province, $city, $district, $village, $postalCode,
                $sameAddr, $address, $addressDetail,
                $latitude, $longitude, $description, $consent,
                $user['id']
            ]);
        } else {
            $stmt = db()->prepare('INSERT INTO employer_profiles (
                user_id, owner_name, nik, phone, whatsapp, profession, npwp,
                linkedin, facebook, instagram,
                same_location_siapkerja, province, city, district, village, postal_code,
                same_address_siapkerja, address, address_detail,
                latitude, longitude, description, user_consent,
                verification_status, verified
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "PENDING", 0)');
            $stmt->execute([
                $user['id'], $ownerName, $nik, $phone, $whatsapp, $profession, $npwp,
                $linkedin, $facebook, $instagram,
                $sameLoc, $province, $city, $district, $village, $postalCode,
                $sameAddr, $address, $addressDetail,
                $latitude, $longitude, $description, $consent
            ]);
        }

        db()->prepare('UPDATE users SET name = ?, profile_complete = 1 WHERE id = ?')->execute([$ownerName, $user['id']]);

        flash('pending_popup', 'Profil Anda berhasil diajukan! Status Profil: Menunggu Verifikasi.');
        redirect('dashboard.php');
        exit;
    }

    // 2. BUAT LOWONGAN BARU (SELALU STATUS DRAFT)
    if (isset($_POST['create_job'])) {
        if ($verificationStatus !== 'APPROVED') {
            flash('error', 'Tidak dapat membuat lowongan. Akun Anda belum terverifikasi atau dalam status terkunci.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        $title = trim($_POST['job_title'] ?? '');
        $description = trim($_POST['job_description'] ?? '');
        $location = trim($_POST['job_location'] ?? '');
        $jobType = trim($_POST['job_type'] ?? '');
        $industry = trim($_POST['industry'] ?? '');
        $kbjiCode = trim($_POST['kbji_code'] ?? '');
        $quota = max(1, (int) ($_POST['quota'] ?? 1));

        if ($title !== '' && $description !== '' && $location !== '' && $jobType !== '' && $kbjiCode !== '') {
            $statement = db()->prepare('INSERT INTO job_posts (user_id, title, description, location, job_type, industry, entity_type, status, quota, kbji_code) VALUES (?, ?, ?, ?, ?, ?, "Individu", "Draft", ?, ?)');
            $statement->execute([$user['id'], $title, $description, $location, $jobType, $industry, $quota, $kbjiCode]);
            flash('success', 'Lowongan baru berhasil dibuat dan disimpan sebagai Draft.');
            redirect('dashboard.php#lowongan');
            exit;
        } else {
            flash('error', 'Mohon lengkapi judul, deskripsi, lokasi, jenis pekerjaan, dan KBJI.');
            redirect('dashboard.php#lowongan');
            exit;
        }
    }

    // 3. KIRIM LOWONGAN (RULES ENGINE KBJI)
    if (isset($_POST['send_job'])) {
        $jobId = (int)$_POST['job_id'];
        
        $jobStmt = db()->prepare('SELECT * FROM job_posts WHERE id = ? AND user_id = ?');
        $jobStmt->execute([$jobId, $user['id']]);
        $targetJob = $jobStmt->fetch();

        if (!$targetJob) {
            flash('error', 'Lowongan tidak ditemukan.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        // Cek duplicate active job for SAME KBJI
        $cekDuplicate = db()->prepare('SELECT * FROM job_posts WHERE user_id = ? AND kbji_code = ? AND status IN ("Tayang", "Dikirim/Menunggu Verifikasi") AND id != ? LIMIT 1');
        $cekDuplicate->execute([$user['id'], $targetJob['kbji_code'], $jobId]);
        $activeDuplicate = $cekDuplicate->fetch();

        if ($activeDuplicate) {
            $_SESSION['kbji_duplicate_error'] = [
                'job_id' => $jobId,
                'kbji_code' => $targetJob['kbji_code'],
                'active_job_id' => $activeDuplicate['id'],
                'active_job_title' => $activeDuplicate['title'],
                'active_job_status' => $activeDuplicate['status']
            ];
            redirect('dashboard.php#lowongan');
            exit;
        }

        // If valid, submit for verification
        $update = db()->prepare('UPDATE job_posts SET status = "Dikirim/Menunggu Verifikasi" WHERE id = ? AND user_id = ?');
        $update->execute([$jobId, $user['id']]);
        flash('success', 'Lowongan berhasil dikirim dan sedang Menunggu Verifikasi.');
        redirect('dashboard.php#lowongan');
        exit;
    }

    // 4. TUTUP LOWONGAN & POSTING ULANG SISA KUOTA
    if (isset($_POST['close_job'])) {
        $jobId = (int)$_POST['job_id'];
        $sisaKuota = (int)$_POST['sisa_kuota'];
        $repost = ($_POST['repost'] ?? '0') === '1';
        $reasons = $_POST['reasons'] ?? [];
        $lainnya = trim($_POST['reason_lainnya'] ?? '');
        
        if (empty($reasons)) {
            flash('error', 'Anda wajib memilih minimal 1 alasan mengapa sisa kuota belum terpenuhi.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        if (in_array('Lainnya', $reasons) && $lainnya === '') {
            flash('error', 'Alasan "Lainnya" wajib diisi.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        $reasonStr = implode(', ', $reasons);
        if (in_array('Lainnya', $reasons)) {
            $reasonStr .= ' - ' . $lainnya;
        }

        // Close original job
        $stmt = db()->prepare('UPDATE job_posts SET status = "Ditutup", unfulfilled_reason = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$reasonStr, $jobId, $user['id']]);

        if ($repost) {
            $jobStmt = db()->prepare('SELECT * FROM job_posts WHERE id = ?');
            $jobStmt->execute([$jobId]);
            $oldJob = $jobStmt->fetch();

            if ($oldJob) {
                $insert = db()->prepare('INSERT INTO job_posts (user_id, title, description, location, job_type, industry, entity_type, status, quota, kbji_code, parent_job_id) VALUES (?, ?, ?, ?, ?, ?, "Individu", "Dikirim/Menunggu Verifikasi", ?, ?, ?)');
                $insert->execute([
                    $user['id'], 
                    $oldJob['title'] . ' (Posting Ulang)', 
                    $oldJob['description'], 
                    $oldJob['location'], 
                    $oldJob['job_type'], 
                    $oldJob['industry'], 
                    $sisaKuota, 
                    $oldJob['kbji_code'], 
                    $jobId
                ]);
                flash('success', 'Lowongan awal telah Ditutup. Posting turunan sisa kuota (' . $sisaKuota . ') berhasil dibuat dan Menunggu Verifikasi.');
            }
        } else {
            flash('success', 'Lowongan berhasil ditutup.');
        }

        redirect('dashboard.php#lowongan');
        exit;
    }

    // 5. AJUKAN PERPANJANGAN WAKTU
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

    // 6. HAPUS DRAFT LOWONGAN
    if (isset($_POST['delete_job'])) {
        $jobId = (int)$_POST['job_id'];
        $stmt = db()->prepare('DELETE FROM job_posts WHERE id = ? AND user_id = ? AND status = "Draft"');
        $stmt->execute([$jobId, $user['id']]);
        flash('success', 'Draft lowongan berhasil dihapus.');
        redirect('dashboard.php#lowongan');
        exit;
    }
}

// Flash toast notification
$flashData = get_flash();
$pendingPopupMessage = null;

if ($flashData && $flashData['type'] === 'pending_popup') {
    $pendingPopupMessage = $flashData['message'];
    $flashData = null;
}

$kbjiDuplicateError = $_SESSION['kbji_duplicate_error'] ?? null;
unset($_SESSION['kbji_duplicate_error']);

// Load HTML Prototype Template
ob_start();
include __DIR__ . '/Index.html';
$html = ob_get_clean();

echo $html;
