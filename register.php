<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (current_user() && !isset($_GET['success'])) {
    redirect('index.php');
}

$showSuccessPopup = isset($_GET['success']) && $_GET['success'] === '1';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['register_action'] ?? '') === 'simulate_employer_session') {
    try {
        $ownerName = trim($_POST['owner_name'] ?? '');
        if ($ownerName === '') {
            $ownerName = 'Pemberi Kerja Individu';
        }

        $cleanSlug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '.', trim($ownerName)));
        $cleanSlug = trim($cleanSlug, '.');
        if ($cleanSlug === '') {
            $cleanSlug = 'employer';
        }
        $uniqueEmail = $cleanSlug . '@paskerid.test';
        if (find_user_by_email($uniqueEmail)) {
            $uniqueEmail = $cleanSlug . '.' . date('YmdHis') . '@paskerid.test';
        }
        $tempPassword = 'password';

        $nik = trim($_POST['nik'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $whatsapp = trim($_POST['whatsapp'] ?? '');
        $profession = trim($_POST['profession'] ?? '');
        $npwp = trim($_POST['npwp'] ?? '');
        $linkedin = trim($_POST['linkedin'] ?? '');
        $facebook = trim($_POST['facebook'] ?? '');
        $instagram = trim($_POST['instagram'] ?? '');
        $description = trim($_POST['description'] ?? '');

        $domProv = trim($_POST['domicile_province'] ?? $_POST['province'] ?? '');
        $domCity = trim($_POST['domicile_city'] ?? $_POST['city'] ?? '');
        $domDist = trim($_POST['domicile_district'] ?? $_POST['district'] ?? '');
        $domVill = trim($_POST['domicile_village'] ?? $_POST['village'] ?? '');
        $domPostal = trim($_POST['domicile_postal_code'] ?? $_POST['postal_code'] ?? '');
        $domAddress = trim($_POST['domicile_address'] ?? $_POST['address'] ?? '');

        $workSame = isset($_POST['workplace_same_as_domicile']) ? 1 : 0;
        $workProv = trim($_POST['workplace_province'] ?? ($workSame ? $domProv : ''));
        $workCity = trim($_POST['workplace_city'] ?? ($workSame ? $domCity : ''));
        $workDist = trim($_POST['workplace_district'] ?? ($workSame ? $domDist : ''));
        $workVill = trim($_POST['workplace_village'] ?? ($workSame ? $domVill : ''));
        $workPostal = trim($_POST['workplace_postal_code'] ?? ($workSame ? $domPostal : ''));
        $workAddress = trim($_POST['workplace_address'] ?? ($workSame ? $domAddress : ''));
        $workDetail = trim($_POST['workplace_detail'] ?? $_POST['address_notes'] ?? $_POST['address_detail'] ?? '');

        $province = $workProv ?: $domProv;
        $city = $workCity ?: $domCity;
        $district = $workDist ?: $domDist;
        $village = $workVill ?: $domVill;
        $domicileCityId = $city;
        $postalCode = $workPostal ?: $domPostal;
        $address = $workAddress ?: $domAddress;
        $addressNotes = $workDetail;
        $userConsent = !empty($_POST['user_consent']) ? 1 : 0;

        create_user($ownerName, $uniqueEmail, $tempPassword, 'employer');
        $newUser = find_user_by_email($uniqueEmail);
        if ($newUser) {
            $userId = (int)$newUser['id'];
            
            // Store uploaded documents
            $permitDoc = store_upload('permit_document', 'employer/' . $userId, ['pdf', 'jpg', 'jpeg', 'png'])
                ?: store_upload('supporting_doc', 'employer/' . $userId, ['pdf', 'jpg', 'jpeg', 'png'])
                ?: trim($_POST['existing_permit_document'] ?? 'dokumen-legalitas.pdf');

            $workplacePhoto = store_upload('workplace_photo', 'employer/' . $userId, ['jpg', 'jpeg', 'png', 'webp'])
                ?: trim($_POST['existing_workplace_photo'] ?? 'foto-rumah.jpg');

            // Update user profile complete and domicile
            db()->prepare('UPDATE users SET profile_complete = 1, domicile_city_id = ?, city = ? WHERE id = ?')->execute([$domicileCityId, $city, $userId]);
            
            $socialSummary = implode(', ', array_filter([
                $instagram ? "Instagram: {$instagram}" : null,
                $linkedin ? "LinkedIn: {$linkedin}" : null,
                $facebook ? "Facebook: {$facebook}" : null
            ]));
            $pdo = db();

            $stmtEp = $pdo->prepare('INSERT INTO employer_profiles (
                user_id, owner_name, nik, profession, phone, whatsapp, npwp,
                domicile_province, domicile_city, domicile_district, domicile_village, domicile_postal_code, domicile_address,
                workplace_same_as_domicile, workplace_province, workplace_city, workplace_district, workplace_village, workplace_postal_code, workplace_address, workplace_detail,
                province, city, district, village, postal_code, address, address_detail,
                latitude, longitude, description, linkedin, instagram, facebook, social_media,
                permit_document, doc_permission, workplace_photo, doc_location_photo,
                entity_type, verification_status, verified, domicile_city_id, user_consent, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                "Individu", "PENDING", 0, ?, ?, CURRENT_TIMESTAMP
            )');

            $stmtEp->execute([
                $userId, $ownerName, $nik, $profession, $phone, $whatsapp, $npwp,
                $domProv, $domCity, $domDist, $domVill, $domPostal, $domAddress,
                $workSame, $workProv, $workCity, $workDist, $workVill, $workPostal, $workAddress, $workDetail,
                $province, $city, $district, $village, $postalCode, $address, $addressNotes,
                '-6.887844', '107.613038', $description, $linkedin, $instagram, $facebook, $socialSummary,
                $permitDoc, $permitDoc, $workplacePhoto, $workplacePhoto,
                $domicileCityId, $userConsent
            ]);

            try {
                $stmtLog = db()->prepare("INSERT INTO audit_logs (entity_type, entity_id, actor_name, actor_role, action, details, created_at) VALUES ('employer', ?, ?, 'employer', 'Profil dikirim untuk verifikasi.', 'Pengajuan profil pemberi kerja baru.', CURRENT_TIMESTAMP)");
                $stmtLog->execute([$userId, $ownerName]);
            } catch (Throwable $ignored) {}

            $newUser['profile_complete'] = 1;
            $newUser['domicile_city_id'] = $domicileCityId;
            $newUser['city'] = $city;

            login_user($newUser);
            redirect('register.php?success=1');
        }
    } catch (Throwable $e) {
        error_log('[Karirhub Register Error] ' . $e->getMessage());
        flash('error', 'Pendaftaran gagal: ' . $e->getMessage());
        redirect('register.php');
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pendaftaran Profil Pemberi Kerja Individu - <?php echo APP_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <div class="auth-shell auth-shell-single">
        <div class="auth-panel">
            <div class="auth-card profile-modal-preview">
                <div class="modal-header">
                    <div>
                        <div class="modal-title">FORM PROFIL PEMBERI KERJA INDIVIDU</div>
                        <div class="modal-subtitle">Lengkapi biodata individu untuk pengajuan verifikasi Hak Akses Pemberi Kerja Individu.</div>
                    </div>
                </div>

                <?php $flash = get_flash(); if ($flash): ?>
                    <div style="margin:16px 24px 0; padding:12px 16px; border-radius:8px; font-size:13.5px; font-weight:600; background:<?php echo $flash['type'] === 'error' ? '#fef2f2' : '#f0fdf4'; ?>; color:<?php echo $flash['type'] === 'error' ? '#991b1b' : '#166534'; ?>; border:1px solid <?php echo $flash['type'] === 'error' ? '#fecaca' : '#bbf7d0'; ?>;">
                        <i class="fa-solid <?php echo $flash['type'] === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>" style="margin-right:6px;"></i>
                        <?php echo e($flash['message']); ?>
                    </div>
                <?php endif; ?>

                <div class="modal-body" style="padding:24px;">
                    <?php
                    $isRevision = false;
                    include __DIR__ . '/partials/employer-profile-form.php';
                    ?>
                </div>
            </div>
        </div>
    </div>

    <!-- POPUP DIALOG BERHASIL -->
    <div class="modal-backdrop <?php echo $showSuccessPopup ? 'open' : ''; ?>" id="modalPendaftaranBerhasil">
        <div class="popup-dialog-card">
            <div class="popup-dialog-icon success"><i class="fa-solid fa-circle-check"></i></div>
            <h3 style="font-size:22px; font-weight:800; color:#0f172a; margin-bottom:10px;">Pendaftaran Berhasil</h3>
            <div style="font-size:12px; font-weight:800; letter-spacing:0.6px; color:#ea580c; margin-bottom:10px;">MENUNGGU VERIFIKASI</div>
            <p style="font-size:14px; color:#475569; line-height:1.65; margin-bottom:20px;">
                Pengajuan Profil Pemberi Kerja Individu sedang dalam proses verifikasi.<br>
                Hak Akses akan mulai berlaku setelah pengajuan disetujui Admin.
            </p>
            <a class="primary-btn" href="dashboard.php#dashboard" style="width:100%; text-align:center; justify-content:center;">Menuju Dashboard Pemberi Kerja</a>
        </div>
    </div>

    <script>
        var modal = document.getElementById('modalPendaftaranBerhasil');
        if (modal) {
            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    modal.classList.remove('open');
                }
            });
        }
    </script>
</body>
</html>
