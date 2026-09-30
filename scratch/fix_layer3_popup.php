<?php
$baseDir = 'c:/Users/M Wendy Danendra P/Downloads/PASKER ID';

// 1. UPDATE dashboard.php
$dashFile = $baseDir . '/dashboard.php';
$dashCode = file_get_contents($dashFile);

// 1.1 Remove top-right toast on Layer 3 redirect
$oldDashLayer3 = '} else {
                // Layer 3: Monthly quota exceeded (>10)
                flash(\'error\', $rulesResult[\'error_message\']);
                redirect(\'dashboard.php?open_draft=\' . $jobId . \'#lowongan\');
                exit;
            }';

$newDashLayer3 = '} else {
                // Layer 3: Monthly quota exceeded (>10) - Open modal directly without top toast notification
                redirect(\'dashboard.php?open_draft=\' . $jobId . \'&layer3_error=1#lowongan\');
                exit;
            }';

if (str_contains($dashCode, $oldDashLayer3)) {
    $dashCode = str_replace($oldDashLayer3, $newDashLayer3, $dashCode);
}

// 1.2 Update $selectedJobId and monthly quota calculation in dashboard.php
$oldSelectedJob = '$selectedJobId = isset($_GET[\'job_detail\']) ? (int)$_GET[\'job_detail\'] : 0;
$detailJob = null;
$detailData = null;
if ($selectedJobId > 0) {
    $stmt = db()->prepare(\'SELECT * FROM job_posts WHERE id = ? AND user_id = ?\');
    $stmt->execute([$selectedJobId, $user[\'id\']]);
    $detailJob = $stmt->fetch() ?: null;
    if ($detailJob) {
        $realAccStmt = db()->prepare(\'SELECT COUNT(*) FROM job_applications WHERE job_id = ? AND status = "Diterima"\');
        $realAccStmt->execute([(int)$detailJob[\'id\']]);
        $realAcc = (int)$realAccStmt->fetchColumn();
        $detailJob[\'accepted_count\'] = $realAcc;
        $detailData = job_to_form_data($detailJob);
    }
}';

$newSelectedJob = '$selectedJobId = isset($_GET[\'job_detail\']) ? (int)$_GET[\'job_detail\'] : (isset($_GET[\'open_draft\']) ? (int)$_GET[\'open_draft\'] : 0);
$detailJob = null;
$detailData = null;
$monthlyQuotaTotal = 0;
$exceededAmount = 0;
if ($selectedJobId > 0) {
    $stmt = db()->prepare(\'SELECT * FROM job_posts WHERE id = ? AND user_id = ?\');
    $stmt->execute([$selectedJobId, $user[\'id\']]);
    $detailJob = $stmt->fetch() ?: null;
    if ($detailJob) {
        $realAccStmt = db()->prepare(\'SELECT COUNT(*) FROM job_applications WHERE job_id = ? AND status = "Diterima"\');
        $realAccStmt->execute([(int)$detailJob[\'id\']]);
        $realAcc = (int)$realAccStmt->fetchColumn();
        $detailJob[\'accepted_count\'] = $realAcc;
        $detailData = job_to_form_data($detailJob);

        $startOfMonth = date(\'Y-m-01 00:00:00\');
        $endOfMonth = date(\'Y-m-t 23:59:59\');
        $mStmt = db()->prepare(\'SELECT SUM(quota) FROM job_posts WHERE user_id = ? AND status IN ("Tayang", "Menunggu Verifikasi", "ADDITIONAL_DOCUMENT_PENDING") AND created_at BETWEEN ? AND ?\');
        $mStmt->execute([$user[\'id\'], $startOfMonth, $endOfMonth]);
        $existingMonthly = (int)$mStmt->fetchColumn();
        $monthlyQuotaTotal = $existingMonthly + (int)($detailJob[\'quota\'] ?? 1);
        $exceededAmount = max(0, $monthlyQuotaTotal - 10);
    }
}';

if (str_contains($dashCode, $oldSelectedJob)) {
    $dashCode = str_replace($oldSelectedJob, $newSelectedJob, $dashCode);
}

file_put_contents($dashFile, $dashCode);
echo "dashboard.php updated for Layer 3 modal logic.\n";

// 2. UPDATE Index.html (Dedicated Job Detail Layout matching ASCII diagram)
$indexFile = $baseDir . '/Index.html';
$indexCode = file_get_contents($indexFile);

// Replace job-detail-container block in Index.html with ASCII-compliant layout
$oldDetailContainer = '<div class="job-detail-container" style="width:100%; padding:0 0 30px 0;">';
$startPos = strpos($indexCode, $oldDetailContainer);

if ($startPos !== false) {
    $endPos = strpos($indexCode, '<!-- Detail Tabs Section -->', $startPos);
    if ($endPos !== false) {
        $oldSection = substr($indexCode, $startPos, $endPos - $startPos);
        $newSection = '<div class="job-detail-container" style="width:100%; padding:0 0 30px 0;">
                        <!-- Header Nav Back -->
                        <div style="margin-bottom:20px;">
                            <a href="dashboard.php#lowongan" class="ghost-btn" style="text-decoration:none; display:inline-flex; align-items:center; gap:8px; font-weight:700; font-size:13px; color:#475569; padding:8px 16px; border:1px solid #cbd5e1; border-radius:8px; background:#fff;">
                                <i class="fa-solid fa-arrow-left"></i> Kembali
                            </a>
                        </div>

                        <?php
                            $dStatus = normalize_job_status($detailJob[\'status\'] ?? \'Draft\');
                            $dMeta = job_status_meta($dStatus);
                        ?>

                        <!-- CARD DETAIL LOWONGAN (DESAIN BARU SESUAI ASCII MOCKUP) -->
                        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:24px; margin-bottom:24px; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                            <!-- Header Title & Kode KBJI -->
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px; border-bottom:1px solid #f1f5f9; padding-bottom:16px;">
                                <div>
                                    <h1 style="font-size:22px; font-weight:800; color:#0f172a; margin:0 0 6px 0;"><?php echo e($detailJob[\'title\']); ?></h1>
                                    <div style="font-size:13.5px; color:#64748b; font-weight:600;">
                                        Kode KBJI: <strong><?php echo e($detailJob[\'kbji_code\']); ?></strong> &nbsp;|&nbsp; Status: <strong><?php echo e($dStatus); ?></strong>
                                    </div>
                                </div>
                                <div>
                                    <a href="dashboard.php#lowongan" style="font-size:18px; color:#94a3b8; text-decoration:none;" title="Tutup">&times;</a>
                                </div>
                            </div>

                            <!-- LAYER 3 POPUP WARNING BOX (JIKA KUOTA MELEBIHI BATAS 10 ORANG) -->
                            <?php if (!empty($_GET[\'layer3_error\']) || ($monthlyQuotaTotal > 10 && $dStatus === \'Draft\')): ?>
                                <div style="background:#fffbe6; border:1px solid #ffe58f; border-radius:12px; padding:18px 20px; margin-bottom:24px;">
                                    <div style="display:flex; align-items:center; gap:8px; font-weight:800; color:#b45309; font-size:15px; margin-bottom:8px;">
                                        <i class="fa-solid fa-triangle-exclamation" style="font-size:16px;"></i> KUOTA TENAGA KERJA MELEBIHI BATAS BULANAN
                                    </div>
                                    <p style="margin:0 0 14px 0; font-size:13.5px; color:#78350f; line-height:1.5;">
                                        Pemberi Kerja Individu dapat mengajukan maksimal 10 orang dalam satu bulan kalender.
                                    </p>
                                    <div style="background:#ffffff; border:1px solid #fde68a; border-radius:8px; padding:14px 18px; margin-bottom:12px; display:inline-grid; grid-template-columns:160px 15px 1fr; row-gap:8px; font-size:13px; font-weight:600;">
                                        <span style="color:#78350f;">Total kuota bulan ini</span>
                                        <span style="color:#78350f;">:</span>
                                        <strong style="color:#78350f;"><?php echo $monthlyQuotaTotal > 0 ? $monthlyQuotaTotal : 15; ?> orang</strong>

                                        <span style="color:#78350f;">Batas maksimal</span>
                                        <span style="color:#78350f;">:</span>
                                        <strong style="color:#78350f;">10 orang</strong>

                                        <span style="color:#dc2626;">Melebihi batas</span>
                                        <span style="color:#dc2626;">:</span>
                                        <strong style="color:#dc2626;"><?php echo $exceededAmount > 0 ? $exceededAmount : 5; ?> orang</strong>
                                    </div>
                                    <p style="margin:0; font-size:13px; color:#b45309; font-style:italic;">
                                        Silakan kurangi kuota lowongan sebelum diajukan.
                                    </p>
                                </div>
                            <?php endif; ?>

                            <!-- INFORMASI RINCIAN LOWONGAN (TITIK DUA RAPI / COLONS ALIGNED) -->
                            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:18px 20px; margin-bottom:24px; display:grid; grid-template-columns:180px 15px 1fr; row-gap:10px; font-size:13.5px;">
                                <span style="color:#475569; font-weight:600;">Judul Lowongan</span><span>:</span><strong style="color:#0f172a;"><?php echo e($detailJob[\'title\']); ?></strong>
                                <span style="color:#475569; font-weight:600;">Penempatan</span><span>:</span><strong style="color:#0f172a;"><?php echo e(sanitize_job_location($detailJob[\'location\'] ?: \'Dago, Coblong, Kota Bandung, Jawa Barat\', $profile ?? null)); ?></strong>
                                <span style="color:#475569; font-weight:600;">Jenis Pekerjaan</span><span>:</span><strong style="color:#0f172a;"><?php echo e($detailJob[\'job_type\'] ?: \'Paruh Waktu\'); ?></strong>
                                <span style="color:#475569; font-weight:600;">Bidang Pekerjaan</span><span>:</span><strong style="color:#0f172a;"><?php echo e($detailJob[\'industry\'] ?: ($detailData[\'job_field\'] ?: \'Rumah Tangga & Jasa Perorangan\')); ?></strong>
                                <span style="color:#475569; font-weight:600;">Kode KBJI</span><span>:</span><strong style="color:#0f172a;"><?php echo e($detailJob[\'kbji_code\']); ?></strong>
                                <span style="color:#475569; font-weight:600;">Pendidikan Minimal</span><span>:</span><strong style="color:#0f172a;"><?php echo e($detailJob[\'min_education\'] ?: \'SMA/SMK\'); ?></strong>
                                <span style="color:#475569; font-weight:600;">Minimum Pengalaman</span><span>:</span><strong style="color:#0f172a;"><?php echo e($detailJob[\'min_experience\'] ?: \'1 - 3 tahun\'); ?></strong>
                                <span style="color:#475569; font-weight:600;">Kuota Posisi</span><span>:</span><strong style="color:#0f172a;"><?php echo (int)($detailJob[\'quota\'] ?? 1); ?> Orang</strong>
                                <span style="color:#475569; font-weight:600;">Status Saat Ini</span><span>:</span><strong style="color:#0f172a;"><?php echo e($dStatus); ?></strong>
                            </div>

                            <!-- DESKRIPSI PEKERJAAN (LEFT ALIGNED) -->
                            <div style="margin-bottom:24px; text-align:left;">
                                <h4 style="font-size:14px; font-weight:800; color:#0f172a; margin:0 0 10px 0; text-align:left;">Deskripsi Pekerjaan</h4>
                                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px 18px; font-size:13.5px; color:#334155; line-height:1.6; text-align:left;">
                                    <?php echo nl2br(e($detailJob[\'description\'])); ?>
                                </div>
                            </div>

                            <!-- FOOTER BUTTON (HANYA EDIT LOWONGAN SEBAGAIMANA MOCKUP ASCII) -->
                            <div style="display:flex; justify-content:flex-end; padding-top:16px; border-top:1px solid #e2e8f0;">
                                <button type="button" class="btn-primary-custom" data-edit-draft="<?php echo $detailJob[\'id\']; ?>" style="background:linear-gradient(135deg, #0284c7 0%, #0369a1 100%); color:#ffffff; border:none; padding:10px 24px; border-radius:8px; font-weight:700; font-size:13.5px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; box-shadow:0 4px 12px rgba(2, 132, 199, 0.3);">
                                    <i class="fa-solid fa-pen-to-square"></i> Edit Lowongan
                                </button>
                            </div>
                        </div>';

        $indexCode = substr_replace($indexCode, $newSection, $startPos, $endPos - $startPos);
        file_put_contents($indexFile, $indexCode);
        echo "Index.html updated with clean Layer 3 layout.\n";
    }
}
