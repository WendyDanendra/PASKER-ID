<?php
$adminFile = __DIR__ . '/../admin.php';
$content = file_get_contents($adminFile);

// 1. Update Header action button: hide Ambil Pengajuan when status === 'APPROVED'
$content = str_replace(
    '<?php elseif (!$isRevisionStatus): ?>',
    '<?php elseif (!$isRevisionStatus && $status !== \'APPROVED\'): ?>',
    $content
);

// 2. Update Status Penugasan in Card 2 to show "Selesai" when APPROVED
$oldPenugasan = <<<'HTML'
                                        <div style="font-weight:700; color:#0f172a;">
                                            <?php if (!empty($selectedEmployer['assigned_to'])): ?>
                                                <span class="pill-badge assigned">● Ditugaskan</span>
                                            <?php else: ?>
                                                <span style="color:#94a3b8;">-</span>
                                            <?php endif; ?>
                                        </div>
HTML;

$newPenugasan = <<<'HTML'
                                        <div style="font-weight:700; color:#0f172a;">
                                            <?php if ($status === 'APPROVED'): ?>
                                                <span class="pill-badge verified" style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0;">Selesai</span>
                                            <?php elseif (!empty($selectedEmployer['assigned_to'])): ?>
                                                <span class="pill-badge assigned">● Ditugaskan</span>
                                            <?php else: ?>
                                                <span style="color:#94a3b8;">-</span>
                                            <?php endif; ?>
                                        </div>
HTML;

$content = str_replace($oldPenugasan, $newPenugasan, $content);

// 3. Update Assign Pemeriksa section to handle APPROVED status disabled state
$oldAssign = <<<'HTML'
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
HTML;

$newAssign = <<<'HTML'
                            <!-- CARD 5: ASSIGN PEMERIKSA -->
                            <div class="section-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:20px;">
                                <div class="section-card-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:14px;">Assign Pemeriksa</div>

                                <?php if ($status === 'APPROVED'): ?>
                                    <!-- NOTICE BOX FOR APPROVED STATUS -->
                                    <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:14px; margin-bottom:16px;">
                                        <div style="font-size:13px; font-weight:700; color:#92400e; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-triangle-exclamation"></i> Perhatian
                                        </div>
                                        <div style="font-size:12.5px; color:#b45309; line-height:1.4;">
                                            Pengajuan telah selesai diverifikasi sehingga penugasan pemeriksa tidak dapat diubah.
                                        </div>
                                    </div>

                                    <div style="margin-bottom:14px;">
                                        <label style="font-size:13px; font-weight:600; color:#0f172a; display:block; margin-bottom:6px;">Pemeriksa <span style="color:#ef4444;">*</span></label>
                                        <div style="display:flex; align-items:center; justify-content:space-between; border:1px solid #cbd5e1; border-radius:8px; padding:10px 12px; background:#f1f5f9; color:#334155; font-size:13px; cursor:not-allowed; font-weight:600;">
                                            <span><?php echo e($selectedEmployer['assigned_to'] ?: 'Budi Santoso'); ?></span>
                                            <i class="fa-solid fa-chevron-down" style="color:#cbd5e1; font-size:11px;"></i>
                                        </div>
                                    </div>

                                    <div style="margin-bottom:16px;">
                                        <label style="font-size:13px; font-weight:600; color:#0f172a; display:block; margin-bottom:6px;">Alasan <span style="color:#ef4444;">*</span></label>
                                        <textarea disabled style="width:100%; min-height:80px; padding:10px 12px; border-radius:8px; border:1px solid #cbd5e1; background:#f1f5f9; font-size:13px; color:#64748b; outline:none; font-family:inherit; cursor:not-allowed; resize:none;">Pengajuan telah selesai diverifikasi.</textarea>
                                    </div>

                                    <button type="button" disabled style="width:100%; height:42px; background:#7dd3fc; opacity:0.6; border:none; border-radius:10px; color:#0369a1; font-weight:700; font-size:13.5px; cursor:not-allowed;">
                                        Assign Pemeriksa
                                    </button>
                                <?php elseif ($isRevisionStatus): ?>
                                    <!-- NOTICE BOX FOR REVISION STATUS -->
                                    <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:14px; margin-bottom:16px;">
                                        <div style="font-size:13px; font-weight:700; color:#92400e; margin-bottom:4px;">Perhatian</div>
                                        <div style="font-size:12.5px; color:#b45309; line-height:1.4;">
                                            Untuk mengubah pemeriksa, pemberi kerja harus memiliki penugasan aktif terlebih dahulu. Silakan ambil case terlebih dahulu melalui aksi di header.
                                        </div>
                                    </div>
HTML;

$content = str_replace($oldAssign, $newAssign, $content);

// 4. Update Regular Decision panel condition so it only appears when PENDING
$content = str_replace(
    '<?php if (!$isRevisionStatus): ?>',
    '<?php if (!$isRevisionStatus && $status === \'PENDING\'): ?>',
    $content
);

file_put_contents($adminFile, $content);
echo "SUCCESS: admin.php approved view layout updated!\n";
