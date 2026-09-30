<?php
$baseDir = 'c:/Users/M Wendy Danendra P/Downloads/PASKER ID';
$indexFile = $baseDir . '/Index.html';
$indexCode = file_get_contents($indexFile);
$indexCodeNorm = str_replace("\r\n", "\n", $indexCode);

// 1. Modal Body Warning Box
$oldModalBody = '                                                               <?php
                                                               $startOfMonth = date(\'Y-m-01 00:00:00\');
                                                               $endOfMonth   = date(\'Y-m-t 23:59:59\');
                                                               $mQuotaStmt = db()->prepare(\'SELECT COALESCE(SUM(quota), 0) FROM job_posts WHERE user_id = ? AND parent_job_id IS NULL AND created_at BETWEEN ? AND ? AND status NOT IN ("Ditolak", "CANCELED")\');
                                                               $mQuotaStmt->execute([$user[\'id\'], $startOfMonth, $endOfMonth]);
                                                               $totalMonthlyQuota = (int)$mQuotaStmt->fetchColumn();
                                                               if ($totalMonthlyQuota <= 0) {
                                                                   $totalMonthlyQuota = array_reduce($jobs, fn($acc, $item) => $acc + (int)($item[\'quota\'] ?? 0), 0);
                                                               }
                                                               $maxMonthlyLimit = 10;
                                                               $exceededMonthlyQuota = max(0, $totalMonthlyQuota - $maxMonthlyLimit);
                                                               ?>

                                                               <?php if ($j[\'status\'] === \'Menunggu Verifikasi\'): ?>';

$newModalBody = '                                                               <?php
                                                               $startOfMonth = date(\'Y-m-01 00:00:00\');
                                                               $endOfMonth   = date(\'Y-m-t 23:59:59\');
                                                               $mQuotaStmt = db()->prepare(\'SELECT COALESCE(SUM(quota), 0) FROM job_posts WHERE user_id = ? AND parent_job_id IS NULL AND created_at BETWEEN ? AND ? AND status NOT IN ("Ditolak", "CANCELED")\');
                                                               $mQuotaStmt->execute([$user[\'id\'], $startOfMonth, $endOfMonth]);
                                                               $totalMonthlyQuota = (int)$mQuotaStmt->fetchColumn();
                                                               if ($totalMonthlyQuota <= 0) {
                                                                   $totalMonthlyQuota = array_reduce($jobs, fn($acc, $item) => $acc + (int)($item[\'quota\'] ?? 0), 0);
                                                               }
                                                               $maxMonthlyLimit = 10;
                                                               $exceededMonthlyQuota = max(0, $totalMonthlyQuota - $maxMonthlyLimit);
                                                               $isCurrentDraftLayer3Error = (!empty($_GET[\'layer3_error\']) && (isset($_GET[\'open_draft\']) && (int)$_GET[\'open_draft\'] === (int)$j[\'id\'])) || ($totalMonthlyQuota > 10 && $j[\'status\'] === \'Draft\');
                                                               ?>

                                                               <?php if ($isCurrentDraftLayer3Error): ?>
                                                                   <!-- LAYER 3 WARNING BOX INSIDE DRAFT DETAIL MODAL -->
                                                                   <div style="background:#fffbe6; border:1px solid #ffe58f; border-radius:12px; padding:18px 20px; margin-bottom:20px;">
                                                                       <div style="display:flex; align-items:center; gap:8px; font-weight:800; color:#b45309; font-size:15px; margin-bottom:8px;">
                                                                           <i class="fa-solid fa-triangle-exclamation" style="font-size:16px;"></i> KUOTA TENAGA KERJA MELEBIHI BATAS BULANAN
                                                                       </div>
                                                                       <p style="margin:0 0 14px 0; font-size:13.5px; color:#78350f; line-height:1.5;">
                                                                           Pemberi Kerja Individu dapat mengajukan maksimal 10 orang dalam satu bulan kalender.
                                                                       </p>
                                                                       <div style="background:#ffffff; border:1px solid #fde68a; border-radius:10px; padding:14px 18px; margin-bottom:12px; display:grid; grid-template-columns:180px 15px 1fr; row-gap:10px; font-size:13.5px; align-items:center;">
                                                                           <span style="color:#78350f; font-weight:600;">Total kuota bulan ini</span>
                                                                           <span style="color:#78350f; font-weight:600;">:</span>
                                                                           <strong style="color:#78350f; font-weight:700;"><?php echo $totalMonthlyQuota > 0 ? $totalMonthlyQuota : 15; ?> orang</strong>

                                                                           <span style="color:#78350f; font-weight:600;">Batas maksimal</span>
                                                                           <span style="color:#78350f; font-weight:600;">:</span>
                                                                           <strong style="color:#78350f; font-weight:700;">10 orang</strong>

                                                                           <span style="color:#dc2626; font-weight:700;">Melebihi batas</span>
                                                                           <span style="color:#dc2626; font-weight:700;">:</span>
                                                                           <strong style="color:#dc2626; font-weight:800; font-size:14px;"><?php echo $exceededMonthlyQuota > 0 ? $exceededMonthlyQuota : 5; ?> orang</strong>
                                                                       </div>
                                                                       <p style="margin:0; font-size:13px; color:#b45309; font-style:italic;">
                                                                           Silakan kurangi kuota lowongan sebelum diajukan.
                                                                       </p>
                                                                   </div>
                                                               <?php endif; ?>

                                                               <?php if ($j[\'status\'] === \'Menunggu Verifikasi\'): ?>';

$oldModalBodyNorm = str_replace("\r\n", "\n", $oldModalBody);
if (str_contains($indexCodeNorm, $oldModalBodyNorm)) {
    $indexCodeNorm = str_replace($oldModalBodyNorm, $newModalBody, $indexCodeNorm);
    echo "Index.html modal body updated.\n";
} else {
    echo "Modal body chunk not found.\n";
}

// 2. Job Grid
$oldJobGrid = '<!-- JOB DETAILS GRID -->
                                                               <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin-bottom:16px;">
                                                                   <div style="display:grid; grid-template-columns:160px 1fr; row-gap:8px; column-gap:12px; font-size:13px; color:#334155;">
                                                                       <div style="font-weight:600; color:#64748b;">Judul Lowongan</div>
                                                                       <div>: <?php echo e($j[\'title\']); ?></div>

                                                                       <div style="font-weight:600; color:#64748b;">Penempatan</div>
                                                                       <div>: <?php echo e(sanitize_job_location($j[\'location\'], $profile ?? null)); ?></div>

                                                                       <div style="font-weight:600; color:#64748b;">Jenis Pekerjaan</div>
                                                                       <div>: <?php echo e($j[\'job_type\']); ?></div>

                                                                       <div style="font-weight:600; color:#64748b;">Bidang Pekerjaan</div>
                                                                       <div>: <?php echo e($j[\'industry\']); ?></div>

                                                                       <div style="font-weight:600; color:#64748b;">Kode KBJI</div>
                                                                       <div>: <?php echo e($j[\'kbji_code\']); ?></div>

                                                                       <div style="font-weight:600; color:#64748b;">Pendidikan Minimal</div>
                                                                       <div>: <?php echo e($j[\'min_education\'] ?? \'SMA/SMK\'); ?></div>

                                                                       <div style="font-weight:600; color:#64748b;">Minimum Pengalaman</div>
                                                                       <div>: <?php echo e($j[\'min_experience\'] ?? \'1 - 3 tahun\'); ?></div>

                                                                       <div style="font-weight:600; color:#64748b;">Kuota Posisi</div>
                                                                       <div>: <?php echo (int)$j[\'quota\']; ?> Orang</div>

                                                                       <div style="font-weight:600; color:#64748b;">Status Saat Ini</div>
                                                                       <div>: <?php echo e($j[\'status\']); ?></div>
                                                                   </div>
                                                               </div>';

$newJobGrid = '<!-- JOB DETAILS GRID (COLONS ALIGNED) -->
                                                               <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:18px 20px; margin-bottom:20px; display:grid; grid-template-columns:180px 15px 1fr; row-gap:10px; font-size:13.5px; align-items:center;">
                                                                   <span style="color:#475569; font-weight:600;">Judul Lowongan</span><span>:</span><strong style="color:#0f172a;"><?php echo e($j[\'title\']); ?></strong>
                                                                   <span style="color:#475569; font-weight:600;">Penempatan</span><span>:</span><strong style="color:#0f172a;"><?php echo e(sanitize_job_location($j[\'location\'], $profile ?? null)); ?></strong>
                                                                   <span style="color:#475569; font-weight:600;">Jenis Pekerjaan</span><span>:</span><strong style="color:#0f172a;"><?php echo e($j[\'job_type\']); ?></strong>
                                                                   <span style="color:#475569; font-weight:600;">Bidang Pekerjaan</span><span>:</span><strong style="color:#0f172a;"><?php echo e($j[\'industry\']); ?></strong>
                                                                   <span style="color:#475569; font-weight:600;">Kode KBJI</span><span>:</span><strong style="color:#0f172a;"><?php echo e($j[\'kbji_code\']); ?></strong>
                                                                   <span style="color:#475569; font-weight:600;">Pendidikan Minimal</span><span>:</span><strong style="color:#0f172a;"><?php echo e($j[\'min_education\'] ?? \'SMA/SMK\'); ?></strong>
                                                                   <span style="color:#475569; font-weight:600;">Minimum Pengalaman</span><span>:</span><strong style="color:#0f172a;"><?php echo e($j[\'min_experience\'] ?? \'1 - 3 tahun\'); ?></strong>
                                                                   <span style="color:#475569; font-weight:600;">Kuota Posisi</span><span>:</span><strong style="color:#0f172a;"><?php echo (int)$j[\'quota\']; ?> Orang</strong>
                                                                   <span style="color:#475569; font-weight:600;">Status Saat Ini</span><span>:</span><strong style="color:#0f172a;"><?php echo e($j[\'status\']); ?></strong>
                                                               </div>';

$oldJobGridNorm = str_replace("\r\n", "\n", $oldJobGrid);
if (str_contains($indexCodeNorm, $oldJobGridNorm)) {
    $indexCodeNorm = str_replace($oldJobGridNorm, $newJobGrid, $indexCodeNorm);
    echo "Index.html job grid updated.\n";
} else {
    echo "Job grid chunk not found.\n";
}

// 3. Footer button
$oldFooter = '<button type="button" class="btn-primary-custom" <?php echo ($j[\'status\'] === \'Perlu Direvisi\') ? \'data-revise-job="\' . $j[\'id\'] . \'"\' : \'data-edit-draft="\' . $j[\'id\'] . \'"\'; ?> data-close-modal="detail-draft-<?php echo $j[\'id\']; ?>" style="background:#0284c7; color:#ffffff; border:none; padding:9px 18px; border-radius:8px; font-weight:600; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:all 0.2s;" onmouseover="this.style.background=\'#0369a1\'" onmouseout="this.style.background=\'#0284c7\'">
                                                                   <i class="fa-solid fa-pen"></i> Edit Lowongan
                                                               </button>';

$newFooter = '<button type="button" class="btn-primary-custom" <?php echo ($j[\'status\'] === \'Perlu Direvisi\') ? \'data-revise-job="\' . $j[\'id\'] . \'"\' : \'data-edit-draft="\' . $j[\'id\'] . \'"\'; ?> data-close-modal="detail-draft-<?php echo $j[\'id\']; ?>" style="background:linear-gradient(135deg, #0284c7 0%, #0369a1 100%); color:#ffffff; border:none; padding:10px 24px; border-radius:8px; font-weight:700; font-size:13.5px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; box-shadow:0 4px 12px rgba(2, 132, 199, 0.3);">
                                                                   <i class="fa-solid fa-pen-to-square"></i> Edit Lowongan
                                                               </button>';

$oldFooterNorm = str_replace("\r\n", "\n", $oldFooter);
if (str_contains($indexCodeNorm, $oldFooterNorm)) {
    $indexCodeNorm = str_replace($oldFooterNorm, $newFooter, $indexCodeNorm);
    echo "Index.html footer updated.\n";
} else {
    echo "Footer chunk not found.\n";
}

file_put_contents($indexFile, $indexCodeNorm);
echo "Done Index.html patch.\n";
