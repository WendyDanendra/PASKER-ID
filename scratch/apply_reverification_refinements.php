<?php
$baseDir = 'c:/Users/M Wendy Danendra P/Downloads/PASKER ID';

// --- 1. PATCH dashboard.php ---
$dashFile = $baseDir . '/dashboard.php';
$dashCode = file_get_contents($dashFile);

// 1.1 REVERIFICATION_PENDING in status calculation
$oldPart1 = '$isFullDisable = false;

if (!empty($profile[\'verified\']) && (int)$profile[\'verified\'] === 1';
$newPart1 = '$isFullDisable = false;

if ($verificationStatus === \'REVERIFICATION_PENDING\') {
    $isFullDisable = true;
} elseif (!empty($profile[\'verified\']) && (int)$profile[\'verified\'] === 1';

if (str_contains($dashCode, $oldPart1)) {
    $dashCode = str_replace($oldPart1, $newPart1, $dashCode);
}

// 1.2 Form submission: set status to REVERIFICATION_PENDING if re-verification
$oldPart2 = 'entity_type = "Individu", verification_status = "PENDING", verified = 0, active_until = NULL,';
$newPart2 = 'entity_type = "Individu", verification_status = ($isReactivation || !empty($profile[\'last_activated_at\']) || ($profile[\'verification_status\'] ?? \'\') === \'INACTIVE_REVERIFICATION_REQUIRED\' ? "REVERIFICATION_PENDING" : "PENDING"), verified = 0, active_until = NULL,';

if (str_contains($dashCode, $oldPart2)) {
    $dashCode = str_replace($oldPart2, $newPart2, $dashCode);
}

// 1.3 Block new job creation during transition / inactive
$oldPart3 = 'if ($title !== \'\' && $location !== \'\' && $kbjiCode !== \'\' && $quota > 0 && $description !== \'\') {
            if ($jobId > 0) {';
$newPart3 = 'if ($jobId <= 0 && ($isTransitionPeriod || $isFullDisable || in_array($verificationStatus, [\'TRANSITION_LIMITED\', \'INACTIVE_REVERIFICATION_REQUIRED\', \'REVERIFICATION_PENDING\'], true))) {
            flash(\'error\', \'Selama masa transisi atau verifikasi ulang profil, Anda tidak dapat membuat lowongan baru.\');
            redirect(\'dashboard.php#lowongan\');
            exit;
        }

        if ($title !== \'\' && $location !== \'\' && $kbjiCode !== \'\' && $quota > 0 && $description !== \'\') {
            if ($jobId > 0) {';

if (str_contains($dashCode, $oldPart3)) {
    $dashCode = str_replace($oldPart3, $newPart3, $dashCode);
}

// 1.4 Block Kirim Lowongan during transition / inactive
$oldPart4 = 'if ($isTransitionPeriod || $isFullDisable || $verificationStatus === \'SUSPENDED\') {
            flash(\'error\', \'Hak Akses Pemberi Kerja Individu dalam Masa Transisi atau terkunci. Tidak dapat mengirim lowongan baru.\');';
$newPart4 = 'if ($isTransitionPeriod || $isFullDisable || in_array($verificationStatus, [\'TRANSITION_LIMITED\', \'INACTIVE_REVERIFICATION_REQUIRED\', \'REVERIFICATION_PENDING\', \'SUSPENDED\'], true)) {
            flash(\'error\', \'Selama masa transisi atau verifikasi ulang profil, Anda tidak dapat mengirim lowongan baru.\');';

if (str_contains($dashCode, $oldPart4)) {
    $dashCode = str_replace($oldPart4, $newPart4, $dashCode);
}

// 1.5 Update $isProfileVerified for nav locking: allow transition to view/manage, block inactive/reverification
$oldPart5 = '$isProfileVerified = (!empty($profile[\'verified\']) && (int)$profile[\'verified\'] === 1) && in_array($verificationStatus, [\'APPROVED\', \'ACTIVE_VERIFIED\'], true);';
$newPart5 = '$isProfileVerified = (!empty($profile[\'verified\']) && (int)$profile[\'verified\'] === 1) && in_array($verificationStatus, [\'APPROVED\', \'ACTIVE_VERIFIED\', \'TRANSITION_LIMITED\'], true);';

if (str_contains($dashCode, $oldPart5)) {
    $dashCode = str_replace($oldPart5, $newPart5, $dashCode);
}

// 1.6 Pending reverification notification reminder
$oldPart6 = '} elseif ($verificationStatus === \'PENDING\' && !empty($profile[\'last_activated_at\'])) {';
$newPart6 = '} elseif (in_array($verificationStatus, [\'REVERIFICATION_PENDING\', \'PENDING\'], true) && !empty($profile[\'last_activated_at\'])) {';

if (str_contains($dashCode, $oldPart6)) {
    $dashCode = str_replace($oldPart6, $newPart6, $dashCode);
}

file_put_contents($dashFile, $dashCode);
echo "dashboard.php updated.\n";


// --- 2. PATCH Index.html ---
$indexFile = $baseDir . '/Index.html';
$indexCode = file_get_contents($indexFile);

// 2.1 Dashboard Header Summary & Banners
$oldHeaderAndBanners = '                        <!-- HEADER DASHBOARD -->
                        <div class="lifecycle-header">
                            <h1 class="lifecycle-title">Halo, <?php echo htmlspecialchars($ownerName, ENT_QUOTES, \'UTF-8\'); ?></h1>

                            <?php if (in_array($verificationStatus, [\'ACTIVE_VERIFIED\', \'APPROVED\'], true)): ?>
                                <?php if ($hasValidDates): ?>
                                    <div class="lifecycle-header-summary">
                                        <div class="lifecycle-summary-item">
                                            <div class="summary-top-tag <?php echo $isH7 ? \'warning\' : \'\'; ?>">
                                                <i class="<?php echo $isH7 ? \'fa-solid fa-triangle-exclamation\' : \'fa-regular fa-clock\'; ?>"></i>
                                                HARI KE-<?php echo $elapsedDays; ?> DARI <?php echo $totalDays; ?>
                                            </div>
                                        </div>
                                        <div class="lifecycle-summary-sep"></div>
                                        <div class="lifecycle-summary-item">
                                            <div class="summary-main-val">
                                                <i class="fa-regular fa-calendar" style="<?php echo $isH7 ? \'color:#d97706;\' : \'color:#0284c7;\'; ?>"></i>
                                                Sisa <?php echo $daysRemaining; ?> hari
                                            </div>
                                            <div class="summary-sub-val">Siklus 6 Bulan</div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="lifecycle-header-summary">
                                        <div class="lifecycle-summary-item">
                                            <div class="summary-main-val" style="color:#16a34a; font-size:13.5px; display:flex; align-items:center; gap:6px;">
                                                <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#16a34a;"></span> Hak Akses Aktif
                                            </div>
                                            <div class="summary-sub-val" style="color:#64748b;">Data periode belum tersedia</div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php elseif ($isTransitionPeriod): ?>
                            <!-- BANNER: TRANSITION_LIMITED -->
                            <div class="lifecycle-banner banner-warning">
                                <div class="banner-title"><i class="fa-solid fa-triangle-exclamation"></i> MASA TRANSISI — HAK AKSES TERBATAS</div>
                                <div class="banner-desc">
                                    Masa aktif Pemberi Kerja Individu telah berakhir.<br>
                                    Anda berada dalam masa transisi selama <?php echo $transRemain; ?> hari dan tidak dapat membuat lowongan baru.
                                </div>
                                <div class="banner-countdown-row" style="margin-top:10px;">
                                    <div class="countdown-stat">Hari ke-<?php echo $transElapsed; ?> dari 7</div>
                                    <div class="countdown-remain">Sisa <?php echo $transRemain; ?> hari</div>
                                </div>
                            </div>
                        <?php elseif (in_array($verificationStatus, [\'ACTIVE_VERIFIED\', \'APPROVED\'], true) && $isH7 && $hasValidDates): ?>
                            <!-- BANNER: ACTIVE H-7 -->
                            <div class="lifecycle-banner banner-warning">
                                <div class="banner-title"><i class="fa-solid fa-triangle-exclamation"></i> MASA AKTIF HAK AKSES AKAN SEGERA BERAKHIR</div>
                                <div class="banner-desc">
                                    Hak Akses Pemberi Kerja Individu Anda akan berakhir dalam <?php echo $daysRemaining; ?> hari pada <?php echo $activeUntilLong; ?>. Setelah masa aktif berakhir, Hak Akses akan memasuki Masa Transisi sesuai ketentuan yang berlaku.
                                </div>
                            </div>
                        <?php elseif (in_array($verificationStatus, [\'ACTIVE_VERIFIED\', \'APPROVED\'], true)): ?>
                            <!-- BANNER: ACTIVE NORMAL / REACTIVATED -->
                            <?php if (!empty($isReactivatedEmployer)): ?>
                                <div class="lifecycle-banner banner-info" style="border-color:#a7f3d0; background:#f0fdf4;">
                                    <div class="banner-title" style="color:#065f46;"><i class="fa-solid fa-circle-check" style="color:#059669;"></i> HAK AKSES PEMBERI KERJA INDIVIDU TELAH DIAKTIFKAN KEMBALI</div>
                                    <div class="banner-desc" style="color:#047857;">
                                        Hak Akses Pemberi Kerja Individu Anda telah direaktivasi dan kembali aktif selama 6 bulan sejak tanggal aktivasi terbaru.
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="lifecycle-banner banner-info">
                                    <div class="banner-title"><i class="fa-solid fa-circle-info"></i> HAK AKSES PEMBERI KERJA INDIVIDU TELAH AKTIF</div>
                                    <div class="banner-desc">
                                        Hak Akses Pemberi Kerja Individu Anda telah aktif dan berlaku selama 6 bulan sejak tanggal aktivasi. Anda kini dapat mempublikasikan lowongan kerja.
                                        <br><br>
                                        <strong>Catatan penting:</strong> Anda dapat memiliki lebih dari satu lowongan aktif untuk jabatan yang berbeda. Kesamaan jabatan ditentukan berdasarkan Kode KBJI. Selama masih terdapat lowongan aktif dengan Kode KBJI yang sama, Anda tidak dapat membuat lowongan baru menggunakan Kode KBJI tersebut.
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>';

$newHeaderAndBanners = '                        <!-- HEADER DASHBOARD -->
                        <div class="lifecycle-header">
                            <h1 class="lifecycle-title">Halo, <?php echo htmlspecialchars($ownerName, ENT_QUOTES, \'UTF-8\'); ?></h1>

                            <?php if (in_array($verificationStatus, [\'ACTIVE_VERIFIED\', \'APPROVED\'], true) && $hasValidDates): ?>
                                <div class="lifecycle-header-summary">
                                    <div class="lifecycle-summary-item">
                                        <div class="summary-top-tag <?php echo $isH7 ? \'warning\' : \'\'; ?>">
                                            <i class="<?php echo $isH7 ? \'fa-solid fa-triangle-exclamation\' : \'fa-regular fa-clock\'; ?>"></i>
                                            Aktif sampai <?php echo $activeUntilLong; ?>
                                        </div>
                                        <div class="summary-sub-val" style="margin-top:2px;">Siklus 6 Bulan</div>
                                    </div>
                                    <div class="lifecycle-summary-sep"></div>
                                    <div class="lifecycle-summary-item">
                                        <div class="summary-main-val" style="<?php echo $isH7 ? \'color:#d97706;\' : \'\'; ?>">
                                            <i class="fa-regular fa-calendar" style="<?php echo $isH7 ? \'color:#d97706;\' : \'color:#0284c7;\'; ?>"></i>
                                            Sisa masa aktif: <?php echo $daysRemaining; ?> hari
                                        </div>
                                    </div>
                                </div>
                            <?php elseif ($isTransitionPeriod): ?>
                                <div class="lifecycle-header-summary">
                                    <div class="lifecycle-summary-item">
                                        <div class="summary-top-tag warning">
                                            <i class="fa-solid fa-triangle-exclamation"></i>
                                            Masa Transisi (7 Hari)
                                        </div>
                                        <div class="summary-sub-val" style="margin-top:2px;">Hak Akses Terbatas</div>
                                    </div>
                                    <div class="lifecycle-summary-sep"></div>
                                    <div class="lifecycle-summary-item">
                                        <div class="summary-main-val" style="color:#d97706;">
                                            <i class="fa-regular fa-clock" style="color:#d97706;"></i>
                                            Sisa masa transisi: <?php echo $transRemain; ?> hari
                                        </div>
                                    </div>
                                </div>
                            <?php elseif ($isFullDisable || $verificationStatus === \'INACTIVE_REVERIFICATION_REQUIRED\'): ?>
                                <div class="lifecycle-header-summary">
                                    <div class="lifecycle-summary-item">
                                        <div class="summary-top-tag error" style="background:#fee2e2; color:#b91c1c; border-color:#fca5a5;">
                                            <i class="fa-solid fa-lock"></i>
                                            Hak Akses Tidak Aktif
                                        </div>
                                        <div class="summary-sub-val" style="margin-top:2px; color:#b91c1c;">Verifikasi Ulang Profil Diperlukan</div>
                                    </div>
                                </div>
                            <?php elseif ($verificationStatus === \'REVERIFICATION_PENDING\' || ($verificationStatus === \'PENDING\' && !empty($profile[\'last_activated_at\']))): ?>
                                <div class="lifecycle-header-summary">
                                    <div class="lifecycle-summary-item">
                                        <div class="summary-top-tag info" style="background:#e0f2fe; color:#0369a1; border-color:#bae6fd;">
                                            <i class="fa-solid fa-hourglass-half"></i>
                                            Verifikasi Ulang Sedang Diproses
                                        </div>
                                        <div class="summary-sub-val" style="margin-top:2px; color:#0369a1;">Menunggu Keputusan Verifikator</div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- BANNERS BASED ON LIFECYCLE & VERIFICATION STATE -->
                        <?php if (in_array($verificationStatus, [\'ACTIVE_VERIFIED\', \'APPROVED\'], true) && $isH7 && $hasValidDates): ?>
                            <!-- BANNER: ACTIVE H-7 (NO VERIFICATION CTA) -->
                            <div class="lifecycle-banner banner-warning" style="background:#fffbe6; border:1px solid #ffe58f; border-radius:12px; padding:16px 20px; margin-bottom:20px;">
                                <div class="banner-title" style="color:#d48806; font-weight:700; font-size:15px; display:flex; align-items:center; gap:8px;">
                                    <i class="fa-solid fa-triangle-exclamation"></i> MASA AKTIF HAK AKSES AKAN SEGERA BERAKHIR
                                </div>
                                <div class="banner-desc" style="color:#8c6a00; font-size:13.5px; margin-top:6px; line-height:1.5;">
                                    Hak Akses Pemberi Kerja Individu Anda akan berakhir dalam <?php echo $daysRemaining; ?> hari pada <strong><?php echo $activeUntilLong; ?></strong> (Sisa masa aktif: <?php echo $daysRemaining; ?> hari). Setelah masa aktif berakhir, Hak Akses akan memasuki Masa Transisi 7 hari sesuai ketentuan yang berlaku.
                                </div>
                            </div>
                        <?php elseif ($isTransitionPeriod): ?>
                            <!-- BANNER: TRANSITION_LIMITED (NO VERIFICATION CTA) -->
                            <div class="lifecycle-banner banner-warning" style="background:#fffbe6; border:1px solid #ffe58f; border-radius:12px; padding:16px 20px; margin-bottom:20px;">
                                <div class="banner-title" style="color:#d48806; font-weight:700; font-size:15px; display:flex; align-items:center; gap:8px;">
                                    <i class="fa-solid fa-triangle-exclamation"></i> MASA TRANSISI — HAK AKSES TERBATAS
                                </div>
                                <div class="banner-desc" style="color:#8c6a00; font-size:13.5px; margin-top:6px; line-height:1.5;">
                                    Masa aktif 6 bulan Pemberi Kerja Individu Anda telah berakhir.<br>
                                    Selama masa transisi, Anda <strong>tidak dapat membuat atau mengirim lowongan baru</strong> (tombol Tambah Lowongan dan Kirim Lowongan diblokir). Anda tetap dapat melanjutkan <strong>seleksi kandidat existing, wawancara, mengubah status kandidat (Diterima / Ditolak), dan menutup lowongan existing</strong>.
                                </div>
                                <div class="banner-countdown-row" style="margin-top:12px; display:flex; align-items:center; gap:16px; font-weight:600; font-size:13px; color:#b45309;">
                                    <div class="countdown-stat" style="background:#fef3c7; padding:4px 12px; border-radius:6px;">Hari ke-<?php echo $transElapsed; ?> dari 7</div>
                                    <div class="countdown-remain" style="background:#fef3c7; padding:4px 12px; border-radius:6px;">Sisa masa transisi: <?php echo $transRemain; ?> hari</div>
                                </div>
                            </div>
                        <?php elseif ($isFullDisable || $verificationStatus === \'INACTIVE_REVERIFICATION_REQUIRED\'): ?>
                            <!-- BANNER: INACTIVE_REVERIFICATION_REQUIRED (SHOW VERIFICATION CTA ONLY NOW) -->
                            <div class="lifecycle-banner banner-error" style="background:#fef2f2; border:1px solid #fecaca; border-radius:12px; padding:18px 20px; margin-bottom:20px;">
                                <div class="banner-title" style="color:#991b1b; font-weight:700; font-size:15px; display:flex; align-items:center; gap:8px;">
                                    <i class="fa-solid fa-lock"></i> HAK AKSES TIDAK AKTIF — VERIFIKASI ULANG DIPERLUKAN
                                </div>
                                <div class="banner-desc" style="color:#7f1d1d; font-size:13.5px; margin-top:6px; line-height:1.5;">
                                    Masa aktif 6 bulan dan 7 hari Masa Transisi Anda telah berakhir. Seluruh aktivitas operasional (membuat lowongan & rekrutmen) saat ini diblokir. Lakukan <strong>Verifikasi Ulang Profil</strong> untuk mengaktifkan kembali Hak Akses Anda selama 6 bulan ke depan.
                                </div>
                                <div style="margin-top:14px;">
                                    <button type="button" class="btn-primary-custom" data-open-modal="modal-employer-profile" style="background:#0284c7; color:#ffffff; border:none; padding:10px 22px; border-radius:8px; font-weight:700; font-size:13.5px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; box-shadow:0 2px 4px rgba(2,132,199,0.25);">
                                        <i class="fa-solid fa-shield-halved"></i> Verifikasi Ulang Profil
                                    </button>
                                </div>
                            </div>
                        <?php elseif ($verificationStatus === \'REVERIFICATION_PENDING\' || ($verificationStatus === \'PENDING\' && !empty($profile[\'last_activated_at\']))): ?>
                            <!-- BANNER: REVERIFICATION_PENDING -->
                            <div class="lifecycle-banner banner-info" style="background:#f0f9ff; border:1px solid #bae6fd; border-radius:12px; padding:18px 20px; margin-bottom:20px;">
                                <div class="banner-title" style="color:#0369a1; font-weight:700; font-size:15px; display:flex; align-items:center; gap:8px;">
                                    <i class="fa-solid fa-hourglass-half"></i> VERIFIKASI ULANG PROFIL SEDANG DIPROSES
                                </div>
                                <div class="banner-desc" style="color:#0c4a6e; font-size:13.5px; margin-top:6px; line-height:1.5;">
                                    Pengajuan verifikasi ulang profil Anda telah diterima dan sedang diproses oleh Verifikator (Admin). Aktivitas operasional tetap diblokir sampai verifikasi disetujui. Anda akan mendapatkan notifikasi setelah Admin memberikan keputusan.
                                </div>
                            </div>
                        <?php elseif ($verificationStatus === \'NEEDS_REVISION\'): ?>
                            <!-- BANNER: NEEDS REVISION -->
                            <div class="lifecycle-banner banner-warning" style="background:#fff7ed; border:1px solid #fed7aa; border-radius:12px; padding:18px 20px; margin-bottom:20px;">
                                <div class="banner-title" style="color:#c2410c; font-weight:700; font-size:15px; display:flex; align-items:center; gap:8px;">
                                    <i class="fa-solid fa-pen-to-square"></i> VERIFIKASI ULANG PROFIL PERLU REVISI
                                </div>
                                <div class="banner-desc" style="color:#9a3412; font-size:13.5px; margin-top:6px; line-height:1.5;">
                                    Verifikator meminta perbaikan pada pengajuan verifikasi ulang Anda.<br>
                                    <strong>Catatan Verifikator:</strong> <?php echo e($profile[\'verifier_notes\'] ?? \'Harap lengkapi dan perbarui data/dokumen Anda.\'); ?>
                                </div>
                                <div style="margin-top:14px;">
                                    <button type="button" class="btn-warning-custom" data-open-modal="modal-employer-profile" style="background:#ea580c; color:#ffffff; border:none; padding:10px 22px; border-radius:8px; font-weight:700; font-size:13.5px; cursor:pointer; display:inline-flex; align-items:center; gap:8px;">
                                        <i class="fa-solid fa-pen-to-square"></i> Perbaiki Verifikasi Ulang Profil
                                    </button>
                                </div>
                            </div>
                        <?php elseif ($verificationStatus === \'REJECTED\'): ?>
                            <!-- BANNER: REJECTED -->
                            <div class="lifecycle-banner banner-error" style="background:#fef2f2; border:1px solid #fecaca; border-radius:12px; padding:18px 20px; margin-bottom:20px;">
                                <div class="banner-title" style="color:#991b1b; font-weight:700; font-size:15px; display:flex; align-items:center; gap:8px;">
                                    <i class="fa-solid fa-circle-xmark"></i> VERIFIKASI ULANG PROFIL DITOLAK
                                </div>
                                <div class="banner-desc" style="color:#7f1d1d; font-size:13.5px; margin-top:6px; line-height:1.5;">
                                    Pengajuan verifikasi ulang profil Anda ditolak oleh Verifikator.<br>
                                    <strong>Catatan Verifikator:</strong> <?php echo e($profile[\'verifier_notes\'] ?? \'Data/dokumen tidak sesuai.\'); ?>
                                </div>
                                <div style="margin-top:14px;">
                                    <button type="button" class="btn-primary-custom" data-open-modal="modal-employer-profile" style="background:#dc2626; color:#ffffff; border:none; padding:10px 22px; border-radius:8px; font-weight:700; font-size:13.5px; cursor:pointer; display:inline-flex; align-items:center; gap:8px;">
                                        <i class="fa-solid fa-rotate-right"></i> Ajukan Ulang Profil
                                    </button>
                                </div>
                            </div>
                        <?php elseif (in_array($verificationStatus, [\'ACTIVE_VERIFIED\', \'APPROVED\'], true)): ?>
                            <!-- BANNER: ACTIVE NORMAL / REACTIVATED -->
                            <?php if (!empty($isReactivatedEmployer)): ?>
                                <div class="lifecycle-banner banner-info" style="border-color:#a7f3d0; background:#f0fdf4; border-radius:12px; padding:16px 20px; margin-bottom:20px;">
                                    <div class="banner-title" style="color:#065f46; font-weight:700; font-size:15px; display:flex; align-items:center; gap:8px;">
                                        <i class="fa-solid fa-circle-check" style="color:#059669;"></i> HAK AKSES PEMBERI KERJA INDIVIDU TELAH DIAKTIFKAN KEMBALI
                                    </div>
                                    <div class="banner-desc" style="color:#047857; font-size:13.5px; margin-top:6px; line-height:1.5;">
                                        Hak Akses Pemberi Kerja Individu Anda telah disetujui dan kembali aktif selama 6 bulan sejak tanggal aktivasi terbaru (Aktif sampai <strong><?php echo $activeUntilLong; ?></strong>).
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="lifecycle-banner banner-info" style="background:#f0f9ff; border:1px solid #bae6fd; border-radius:12px; padding:16px 20px; margin-bottom:20px;">
                                    <div class="banner-title" style="color:#0369a1; font-weight:700; font-size:15px; display:flex; align-items:center; gap:8px;">
                                        <i class="fa-solid fa-circle-info"></i> HAK AKSES PEMBERI KERJA INDIVIDU TELAH AKTIF
                                    </div>
                                    <div class="banner-desc" style="color:#0c4a6e; font-size:13.5px; margin-top:6px; line-height:1.5;">
                                        Hak Akses Pemberi Kerja Individu Anda telah aktif dan berlaku selama 6 bulan sejak tanggal aktivasi (Aktif sampai <strong><?php echo $activeUntilLong; ?></strong>). Anda kini dapat mempublikasikan lowongan kerja.
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>';

if (str_contains($indexCode, $oldHeaderAndBanners)) {
    $indexCode = str_replace($oldHeaderAndBanners, $newHeaderAndBanners, $indexCode);
}

// 2.2 Disable Kirim Lowongan button on draft lowongan modal during transition / inactive
$oldKirimBtn = '<form method="post" action="dashboard.php#lowongan" style="display:inline;" onsubmit="return handleKirimLowongan(event, <?php echo (int)$detailJob[\'id\']; ?>, \'<?php echo e($detailJob[\'kbji_code\'] ?? \'\'); ?>\', <?php echo (int)($kbjiPublishedCounts[$detailJob[\'kbji_code\'] ?? \'\'] ?? 0); ?>)">
                                            <input type="hidden" name="send_job" value="1">
                                            <input type="hidden" name="job_id" value="<?php echo $detailJob[\'id\']; ?>">
                                            <button type="submit" class="btn-primary-custom" style="background:#0ea5e9; color:#fff; border:none; padding:9px 18px; border-radius:8px; font-weight:700; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; box-shadow:0 1px 3px rgba(14,165,233,0.3);">
                                                <i class="fa-solid fa-paper-plane"></i> Kirim Lowongan
                                            </button>
                                        </form>';

$newKirimBtn = '<?php if ($isTransitionPeriod || $isFullDisable || in_array($verificationStatus, [\'TRANSITION_LIMITED\', \'INACTIVE_REVERIFICATION_REQUIRED\', \'REVERIFICATION_PENDING\'], true)): ?>
                                            <button type="button" class="btn-primary-custom" disabled style="background:#cbd5e1; color:#94a3b8; border:none; padding:9px 18px; border-radius:8px; font-weight:700; font-size:13px; cursor:not-allowed; display:inline-flex; align-items:center; gap:6px;" title="Tidak dapat mengirim lowongan baru selama masa transisi atau verifikasi ulang">
                                                <i class="fa-solid fa-paper-plane"></i> Kirim Lowongan
                                            </button>
                                        <?php else: ?>
                                            <form method="post" action="dashboard.php#lowongan" style="display:inline;" onsubmit="return handleKirimLowongan(event, <?php echo (int)$detailJob[\'id\']; ?>, \'<?php echo e($detailJob[\'kbji_code\'] ?? \'\'); ?>\', <?php echo (int)($kbjiPublishedCounts[$detailJob[\'kbji_code\'] ?? \'\'] ?? 0); ?>)">
                                                <input type="hidden" name="send_job" value="1">
                                                <input type="hidden" name="job_id" value="<?php echo $detailJob[\'id\']; ?>">
                                                <button type="submit" class="btn-primary-custom" style="background:#0ea5e9; color:#fff; border:none; padding:9px 18px; border-radius:8px; font-weight:700; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; box-shadow:0 1px 3px rgba(14,165,233,0.3);">
                                                    <i class="fa-solid fa-paper-plane"></i> Kirim Lowongan
                                                </button>
                                            </form>
                                        <?php endif; ?>';

if (str_contains($indexCode, $oldKirimBtn)) {
    $indexCode = str_replace($oldKirimBtn, $newKirimBtn, $indexCode);
}

file_put_contents($indexFile, $indexCode);
echo "Index.html updated.\n";


// --- 3. PATCH admin.php ---
$adminFile = $baseDir . '/admin.php';
$adminCode = file_get_contents($adminFile);

// 3.1 Tag reverification in verification table badge
$oldBadgeCode = '$vStatus = $emp[\'verification_status\'] ?? \'\';
                                        if ($vStatus === \'APPROVED\' || !empty($emp[\'verified\'])) {
                                            $badgeHtml = \'<span class="pill-badge verified" style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; font-weight:600; padding:4px 10px; border-radius:999px; font-size:12px; display:inline-flex; align-items:center; gap:5px;"><span style="font-size:8px;">●</span> Terverifikasi</span>\';
                                        } elseif ($vStatus === \'NEEDS_REVISION\') {
                                            $revNum = (int)($emp[\'revision_count\'] ?? ($emp[\'rejection_count\'] ?? 1));
                                            $revNum = max(1, min(3, $revNum));
                                            $badgeHtml = \'<span class="pill-badge revision" style="background:#fff7ed; color:#ea580c; border:1px solid #ffedd5; font-weight:600; padding:4px 10px; border-radius:999px; font-size:12px; display:inline-flex; align-items:center; gap:5px;"><span style="font-size:8px;">●</span> Diminta Revisi (ke-\' . $revNum . \')</span>\';
                                        } elseif ($vStatus === \'REJECTED\' || $vStatus === \'FULL_DISABLED\') {
                                            $badgeHtml = \'<span class="pill-badge rejected" style="background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; font-weight:600; padding:4px 10px; border-radius:999px; font-size:12px; display:inline-flex; align-items:center; gap:5px;"><span style="font-size:8px;">●</span> Ditolak</span>\';
                                        } else {
                                            $badgeHtml = \'<span class="pill-badge pending" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-weight:600; padding:4px 10px; border-radius:999px; font-size:12px; display:inline-flex; align-items:center; gap:5px;"><span style="font-size:8px;">●</span> Dikirim</span>\';
                                        }';

$newBadgeCode = '$vStatus = $emp[\'verification_status\'] ?? \'\';
                                        $isReverifCase = ($vStatus === \'REVERIFICATION_PENDING\') || (!empty($emp[\'last_activated_at\']) && in_array($vStatus, [\'PENDING\', \'REVERIFICATION_PENDING\'], true));
                                        if ($vStatus === \'APPROVED\' || (!empty($emp[\'verified\']) && (int)$emp[\'verified\'] === 1 && !$isReverifCase && !in_array($vStatus, [\'NEEDS_REVISION\', \'REJECTED\'], true))) {
                                            $badgeHtml = \'<span class="pill-badge verified" style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; font-weight:600; padding:4px 10px; border-radius:999px; font-size:12px; display:inline-flex; align-items:center; gap:5px;"><span style="font-size:8px;">●</span> Terverifikasi</span>\';
                                        } elseif ($isReverifCase) {
                                            $badgeHtml = \'<span class="pill-badge reverification" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-weight:600; padding:4px 10px; border-radius:999px; font-size:12px; display:inline-flex; align-items:center; gap:5px;"><span style="font-size:8px;">●</span> Verifikasi Ulang</span>\';
                                        } elseif ($vStatus === \'NEEDS_REVISION\') {
                                            $revNum = (int)($emp[\'revision_count\'] ?? ($emp[\'rejection_count\'] ?? 1));
                                            $revNum = max(1, min(3, $revNum));
                                            $badgeHtml = \'<span class="pill-badge revision" style="background:#fff7ed; color:#ea580c; border:1px solid #ffedd5; font-weight:600; padding:4px 10px; border-radius:999px; font-size:12px; display:inline-flex; align-items:center; gap:5px;"><span style="font-size:8px;">●</span> Diminta Revisi (ke-\' . $revNum . \')</span>\';
                                        } elseif ($vStatus === \'REJECTED\' || $vStatus === \'FULL_DISABLED\') {
                                            $badgeHtml = \'<span class="pill-badge rejected" style="background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; font-weight:600; padding:4px 10px; border-radius:999px; font-size:12px; display:inline-flex; align-items:center; gap:5px;"><span style="font-size:8px;">●</span> Ditolak</span>\';
                                        } else {
                                            $badgeHtml = \'<span class="pill-badge pending" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-weight:600; padding:4px 10px; border-radius:999px; font-size:12px; display:inline-flex; align-items:center; gap:5px;"><span style="font-size:8px;">●</span> Pendaftaran Baru</span>\';
                                        }';

if (str_contains($adminCode, $oldBadgeCode)) {
    $adminCode = str_replace($oldBadgeCode, $newBadgeCode, $adminCode);
}

file_put_contents($adminFile, $adminCode);
echo "admin.php updated.\n";


// --- 4. PATCH includes/platform.php ---
$platFile = $baseDir . '/includes/platform.php';
$platCode = file_get_contents($platFile);

$oldNotifRender = '$modalAttr = $canOpenConsent ? \' data-open-modal="modal-user-consent" style="cursor:pointer;"\' : \'\';';
$newNotifRender = '$isProfileTitle = (stripos($row[\'title\'], \'Verifikasi Ulang\') !== false || stripos($row[\'title\'], \'Perbaikan Profil\') !== false || stripos($row[\'title\'], \'Profil Ditolak\') !== false || stripos($row[\'title\'], \'Hak Akses Tidak Aktif\') !== false);
            if ($canOpenConsent) {
                $modalAttr = \' data-open-modal="modal-user-consent" style="cursor:pointer;"\';
            } elseif ($isProfileTitle || in_array($verStatus, [\'INACTIVE_REVERIFICATION_REQUIRED\', \'NEEDS_REVISION\', \'REJECTED\'], true)) {
                $modalAttr = \' data-open-modal="modal-employer-profile" style="cursor:pointer;"\';
            } else {
                $modalAttr = \'\';
            }';

if (str_contains($platCode, $oldNotifRender)) {
    $platCode = str_replace($oldNotifRender, $newNotifRender, $platCode);
}

file_put_contents($platFile, $platCode);
echo "includes/platform.php updated.\n";

echo "All patches completed successfully.\n";
