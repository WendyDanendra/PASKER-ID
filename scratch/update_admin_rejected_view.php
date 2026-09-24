<?php
$adminFile = __DIR__ . '/../admin.php';
$content = file_get_contents($adminFile);

// 1. Define $isIndividual variable at top of detail view if not present
if (!str_contains($content, '$isIndividual = ')) {
    $content = str_replace(
        '$isRevisionStatus = in_array(strtoupper($status), [\'REVISION\', \'NEEDS_REVISION\']) || ($tab === \'revision\');',
        '$isRevisionStatus = in_array(strtoupper($status), [\'REVISION\', \'NEEDS_REVISION\']) || ($tab === \'revision\');' . "\n" . '                                        $isIndividual = strcasecmp((string)($selectedEmployer[\'entity_type\'] ?? \'Individual\'), \'Individual\') === 0;',
        $content
    );
}

// 2. Update Akun Pemberi Kerja Card to distinguish Individual vs Perusahaan for status Ditolak
$oldAkunCard = <<<'HTML'
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
HTML;

$newAkunCard = <<<'HTML'
                                    <div style="flex:1; min-width:0;">
                                        <div style="font-size:14px; font-weight:800; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-transform:uppercase;"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></div>
                                    </div>
                                    <?php if ($isIndividual): ?>
                                        <span style="background:#dcfce7; color:#15803d; font-size:11px; font-weight:700; padding:3px 10px; border-radius:12px;">Aktif</span>
                                    <?php else: ?>
                                        <?php if ($status === 'REJECTED'): ?>
                                            <span style="background:#fee2e2; color:#b91c1c; font-size:11px; font-weight:700; padding:3px 10px; border-radius:12px;">Diblokir</span>
                                        <?php else: ?>
                                            <span style="background:#dcfce7; color:#15803d; font-size:11px; font-weight:700; padding:3px 10px; border-radius:12px;">Aktif</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>

                                <div style="margin-bottom:14px;">
                                    <div style="font-size:12px; color:#64748b; font-weight:500; margin-bottom:2px;">Email Akun</div>
                                    <div style="font-size:13px; font-weight:600; color:#00a8e8; word-break:break-all;"><?php echo e($selectedEmployer['email'] ?: 'ahmad@email.com'); ?></div>
                                </div>

                                <div>
                                    <?php if ($isIndividual): ?>
                                        <div style="font-size:12px; color:#64748b; font-weight:500; margin-bottom:2px;">Hak Akses Pemberi Kerja Individu</div>
                                        <div style="font-size:13px; font-weight:700; color:<?php echo ($status === 'APPROVED') ? '#059669' : (($status === 'REJECTED') ? '#dc2626' : '#64748b'); ?>;">
                                            <?php echo ($status === 'APPROVED') ? 'Aktif' : (($status === 'REJECTED') ? 'Tidak Aktif' : 'Belum Aktif'); ?>
                                        </div>
                                    <?php else: ?>
                                        <div style="font-size:12px; color:#64748b; font-weight:500; margin-bottom:2px;">Status Akun</div>
                                        <div style="font-size:13px; font-weight:600; color:#0f172a;"><?php echo ($status === 'REJECTED') ? 'Diblokir' : 'Aktif'; ?></div>
                                    <?php endif; ?>
                                </div>
HTML;

$content = str_replace($oldAkunCard, $newAkunCard, $content);

// 3. Update timeline list fallback to include REJECTED status
$oldTimeline = <<<'HTML'
                                <div class="timeline-list">
                                    <?php if ($isRevisionStatus && empty($auditLogs)): ?>
HTML;

$newTimeline = <<<'HTML'
                                <div class="timeline-list">
                                    <?php if ($status === 'REJECTED' && empty($auditLogs)): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-dot" style="background:#ef4444;"></div>
                                            <div class="timeline-time"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['updated_at'] ?? '2026-08-26 08:05')); ?></div>
                                            <div class="timeline-title" style="font-weight:700; color:#0f172a;"><?php echo e($selectedEmployer['assigned_to'] ?: 'Budi Santoso'); ?> menolak pengajuan profil.</div>
                                            <div style="font-size:12px; color:#dc2626; margin-top:2px;">Alasan: <?php echo e($selectedEmployer['verifier_notes'] ?: 'Data pendukung tidak memenuhi ketentuan verifikasi.'); ?></div>
                                        </div>
                                        <div class="timeline-item">
                                            <div class="timeline-dot"></div>
                                            <div class="timeline-time">24 Agt 2026, 09:15</div>
                                            <div class="timeline-title" style="font-weight:600; color:#0f172a;">Pengajuan diambil oleh <?php echo e($selectedEmployer['assigned_to'] ?: 'Budi Santoso'); ?>.</div>
                                        </div>
                                        <div class="timeline-item">
                                            <div class="timeline-dot"></div>
                                            <div class="timeline-time"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                            <div class="timeline-title" style="font-weight:600; color:#0f172a;">Profil dikirim untuk verifikasi.</div>
                                        </div>
                                        <div class="timeline-item">
                                            <div class="timeline-dot"></div>
                                            <div class="timeline-time"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'] . ' -4 minutes')); ?></div>
                                            <div class="timeline-title" style="font-weight:600; color:#0f172a;">Pemberi kerja mengajukan profil.</div>
                                        </div>
                                    <?php elseif ($isRevisionStatus && empty($auditLogs)): ?>
HTML;

$content = str_replace($oldTimeline, $newTimeline, $content);

// 4. Update Assign Pemeriksa card for REJECTED status
$oldAssignCheck = '<?php if ($status === \'APPROVED\'): ?>';
$newAssignCheck = <<<'HTML'
<?php if ($status === 'REJECTED'): ?>
                                    <!-- NOTICE BOX FOR REJECTED STATUS -->
                                    <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:14px; margin-bottom:16px;">
                                        <div style="font-size:13px; font-weight:700; color:#92400e; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-triangle-exclamation"></i> Perhatian
                                        </div>
                                        <div style="font-size:12.5px; color:#b45309; line-height:1.4;">
                                            Pengajuan telah selesai diproses sehingga penugasan pemeriksa tidak dapat diubah.
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
                                        <textarea disabled style="width:100%; min-height:80px; padding:10px 12px; border-radius:8px; border:1px solid #cbd5e1; background:#f1f5f9; font-size:13px; color:#64748b; outline:none; font-family:inherit; cursor:not-allowed; resize:none;">Pengajuan telah selesai dengan keputusan Ditolak.</textarea>
                                    </div>

                                    <button type="button" disabled style="width:100%; height:42px; background:#7dd3fc; opacity:0.6; border:none; border-radius:10px; color:#0369a1; font-weight:700; font-size:13.5px; cursor:not-allowed;">
                                        Assign Pemeriksa
                                    </button>
                                <?php elseif ($status === 'APPROVED'): ?>
HTML;

$content = str_replace($oldAssignCheck, $newAssignCheck, $content);

file_put_contents($adminFile, $content);
echo "SUCCESS: admin.php rejected view layout updated!\n";
