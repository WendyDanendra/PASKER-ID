<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simulasi Onboarding - <?php echo APP_NAME; ?></title>
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

                <div class="modal-body" style="padding:24px;">
                    <div class="modal-section" style="margin-bottom:24px;">
                        <div class="section-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:4px;">1. IDENTITAS PERORANGAN</div>
                        <div style="font-size:12.5px; color:#64748b; margin-bottom:16px;">Lengkapi informasi utama Pemberi Kerja Individu.</div>
                        <div class="field-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                            <div class="field">
                                <label>Nama Pemberi Kerja <span class="req">*</span></label>
                                <input type="text" placeholder="Masukkan nama lengkap">
                            </div>
                            <div class="field">
                                <label>NIK <span class="req">*</span></label>
                                <input type="text" placeholder="Masukkan 16 digit NIK" maxlength="16">
                            </div>
                            <div class="field">
                                <label>Nomor Telepon Aktif <span class="req">*</span></label>
                                <input type="text" placeholder="08xxxxxxxxxx">
                            </div>
                            <div class="field">
                                <label>Nomor WhatsApp <span class="req">*</span></label>
                                <input type="text" placeholder="08xxxxxxxxxx">
                            </div>
                            <div class="field">
                                <label>Jenis Profesi / Usaha Individu <span class="req">*</span></label>
                                <select style="width:100%; height:42px; padding:0 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:13.5px; color:#0f172a; background:#fff;">
                                    <option value="">Pilih profesi / usaha</option>
                                    <option>Kuliner &amp; Katering</option>
                                    <option>Perdagangan &amp; Eceran</option>
                                    <option>Jasa Perorangan / Rumah Tangga</option>
                                    <option>Pertanian &amp; Peternakan</option>
                                    <option>Teknologi &amp; Kreatif</option>
                                    <option>Lainnya</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>NPWP <span class="req">*</span></label>
                                <input type="text" placeholder="Masukkan NPWP">
                            </div>
                        </div>
                    </div>
                    <hr class="modal-section-hr">
                    <div class="modal-section" style="margin-bottom:24px;">
                        <div class="section-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:4px;">2. MEDIA SOSIAL &amp; DESKRIPSI SINGKAT</div>
                        <div style="font-size:12.5px; color:#64748b; margin-bottom:16px;">Bagian ini bersifat opsional.</div>
                        <div class="field-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
                            <div class="field">
                                <label>LinkedIn</label>
                                <input type="url" placeholder="https://...">
                            </div>
                            <div class="field">
                                <label>Facebook</label>
                                <input type="url" placeholder="https://...">
                            </div>
                            <div class="field" style="grid-column: 1 / -1;">
                                <label>Instagram</label>
                                <input type="url" placeholder="https://..." style="max-width:calc(50% - 8px);">
                            </div>
                        </div>
                        <div class="field">
                            <label>Deskripsi Singkat Usaha / Rekrutmen</label>
                            <textarea placeholder="Tuliskan informasi singkat mengenai kegiatan, usaha, atau kebutuhan rekrutmen..." style="min-height:80px; padding:10px 14px; font-size:13.5px; border:1px solid #cbd5e1; border-radius:8px; width:100%; font-family:inherit; line-height:1.5;"></textarea>
                        </div>
                    </div>
                    <hr class="modal-section-hr">
                    <div class="modal-section" style="margin-bottom:24px;">
                        <div class="section-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:14px;">3. WILAYAH ADMINISTRATIF DOMISILI</div>
                        <div class="field-grid" style="display:grid; grid-template-columns:2fr 1fr; gap:16px;">
                            <div class="field">
                                <label>Lokasi Domisili Pemberi Kerja <span class="req">*</span></label>
                                <input type="text" placeholder="Pilih lokasi domisili">
                            </div>
                            <div class="field">
                                <label>Kode Pos</label>
                                <input type="text" placeholder="Masukkan kode pos">
                            </div>
                        </div>
                    </div>
                    <hr class="modal-section-hr">
                    <div class="modal-section" style="margin-bottom:24px;">
                        <div class="section-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:14px;">4. ALAMAT, DOKUMEN &amp; PETA</div>
                        <div class="field" style="margin-bottom:14px;">
                            <label>Alamat Lengkap Pemberi Kerja <span class="req">*</span></label>
                            <input type="text" placeholder="Masukkan nama jalan, nomor bangunan, RT/RW, dan alamat lengkap...">
                        </div>
                        <div class="field" style="margin-bottom:16px;">
                            <label>Detail Alamat / Patokan (Opsional)</label>
                            <input type="text" placeholder="Contoh: Ruko lantai 2, sebelah Kantor Kelurahan">
                        </div>
                        <div class="field-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">
                            <div class="field">
                                <label>Dokumen Pendukung <span class="req">*</span></label>
                                <input type="file" accept=".pdf,.jpg,.jpeg,.png" style="display:block; width:100%;">
                                <div style="font-size:12px; color:#64748b; margin-top:4px;">Minimal 1 dokumen wajib</div>
                            </div>
                            <div class="field">
                                <label>Foto Bukti Tempat Usaha / Lokasi</label>
                                <input type="file" accept=".jpg,.jpeg,.png,.webp" style="display:block; width:100%;">
                                <div style="font-size:12px; color:#64748b; margin-top:4px;">Opsional</div>
                            </div>
                        </div>
                    </div>
                    <hr class="modal-section-hr">
                    <div class="modal-section">
                        <div class="section-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:14px;">5. PERNYATAAN PERSETUJUAN</div>
                        <label style="display:flex; align-items:flex-start; gap:10px; font-size:13px; color:#334155; line-height:1.5;">
                            <input type="checkbox" style="margin-top:3px;">
                            <span>Saya menyatakan bahwa seluruh data yang diisikan adalah benar, sah, dan valid sesuai hukum yang berlaku. <span class="req">*</span></span>
                        </label>
                    </div>
                </div>

                <div class="modal-footer" style="padding:16px 24px;">
                    <a class="ghost-btn" href="login.php">Masuk</a>
                    <a class="primary-btn" href="dashboard.php?open_profile=1#dashboard">
                        <i class="fa-solid fa-paper-plane"></i>
                        Buka Modal Profil di Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
