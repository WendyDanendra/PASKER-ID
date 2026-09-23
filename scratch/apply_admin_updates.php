<?php
$adminFile = __DIR__ . '/../admin.php';
$content = file_get_contents($adminFile);

// 1. Replace the section from <!-- 1. CONTROLLED EDIT FORM --> to end of manual review section
$pattern = '/<!-- 1\. CONTROLLED EDIT FORM -->.*?<!-- 2\. AJUKAN CONSENT -->.*?<!-- 3\. PERNYATAAN PETUGAS & SETUJUI AKTIFKAN -->.*?<\/div>\s*<\/div>\s*<\?php endif; \?>/s';

$replacement = <<<'HTML'
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
                                                    <input type="text" name="instagram" value="<?php echo e($selectedEmployer['instagram'] ?? ''); ?>" placeholder="https://instagram.com/..." style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:12.5px;">
                                                </div>
                                                <div>
                                                    <label style="font-weight:600; display:block; margin-bottom:4px; color:#334155;">Facebook / LinkedIn:</label>
                                                    <input type="text" name="facebook" value="<?php echo e($selectedEmployer['facebook'] ?? ''); ?>" placeholder="Tautan profil medsos..." style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:12.5px;">
                                                </div>

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
HTML;

$newContent = preg_replace($pattern, $replacement, $content, 1, $count);
if ($count === 0) {
    echo "ERROR: Pattern for manual review section not matched!\n";
    exit(1);
}

// 2. Replace badge in verifikasi_employer table
$badgePattern = '/<\?php if \(\$vEmp\[\'verification_status\'\] === \'APPROVED\'\): \?>.*?<\?php elseif \(\$vEmp\[\'verification_status\'\] === \'PENDING\'\): \?>.*?<span class="pill-badge pending">● Menunggu<\/span>.*?<\?php else: \?>.*?<span class="pill-badge revision">● <\?php echo e\(\$vEmp\[\'verification_status\'\]\); \?><\/span>.*?<\?php endif; \?>/s';

$badgeReplacement = <<<'HTML'
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
HTML;

$newContent = preg_replace($badgePattern, $badgeReplacement, $newContent, 1, $badgeCount);
if ($badgeCount === 0) {
    echo "ERROR: Pattern for table badge not matched!\n";
    exit(1);
}

file_put_contents($adminFile, $newContent);
echo "SUCCESS: admin.php updated successfully!\n";
