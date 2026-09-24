<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (current_user() && !isset($_GET['success'])) {
    redirect('index.php');
}

$showSuccessPopup = isset($_GET['success']) && $_GET['success'] === '1';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['register_action'] ?? '') === 'simulate_employer_session') {
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
    $province = trim($_POST['province'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $district = trim($_POST['district'] ?? '');
    $village = trim($_POST['village'] ?? '');
    $domicileCityId = trim($_POST['domicile_city_id'] ?? $city);
    $postalCode = trim($_POST['postal_code'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $addressNotes = trim($_POST['address_notes'] ?? '');
    $userConsent = !empty($_POST['user_consent']) ? 1 : 0;

    create_user($ownerName, $uniqueEmail, $tempPassword, 'employer');
    $newUser = find_user_by_email($uniqueEmail);
    if ($newUser) {
        $userId = (int)$newUser['id'];
        
        // Update user profile complete and domicile
        db()->prepare('UPDATE users SET profile_complete = 1, domicile_city_id = ?, city = ? WHERE id = ?')->execute([$domicileCityId, $city, $userId]);
        
        $socialSummary = implode(', ', array_filter([
            $instagram ? "Instagram: {$instagram}" : null,
            $linkedin ? "LinkedIn: {$linkedin}" : null,
            $facebook ? "Facebook: {$facebook}" : null
        ]));

        $stmtEp = db()->prepare('INSERT INTO employer_profiles (
            user_id, owner_name, nik, profession, phone, whatsapp, npwp,
            province, city, district, village, postal_code, address, address_detail,
            latitude, longitude, description, linkedin, instagram, facebook, social_media,
            entity_type, verification_status, verified, domicile_city_id, user_consent, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?,
            "Individu", "PENDING", 0, ?, ?, CURRENT_TIMESTAMP
        )');

        $stmtEp->execute([
            $userId, $ownerName, $nik, $profession, $phone, $whatsapp, $npwp,
            $province, $city, $district, $village, $postalCode, $address, $addressNotes,
            '-6.887844', '107.613038', $description, $linkedin, $instagram, $facebook, $socialSummary,
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
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
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

                <form method="post" id="registrationForm">
                    <input type="hidden" name="register_action" value="simulate_employer_session">
                <div class="modal-body" style="padding:24px;">
                    <div class="modal-section" style="margin-bottom:24px;">
                        <div class="section-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:4px;">1. IDENTITAS PERORANGAN</div>
                        <div style="font-size:12.5px; color:#64748b; margin-bottom:16px;">Lengkapi informasi utama Pemberi Kerja Individu.</div>
                        <div class="field-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                            <div class="field">
                                <label>Nama Pemberi Kerja <span class="req">*</span></label>
                                <input type="text" name="owner_name" required placeholder="Masukkan nama lengkap">
                            </div>
                            <div class="field">
                                <label>NIK <span class="req">*</span></label>
                                <input type="text" name="nik" required placeholder="Masukkan 16 digit NIK" maxlength="16">
                            </div>
                            <div class="field">
                                <label>Nomor Telepon Aktif <span class="req">*</span></label>
                                <input type="text" name="phone" required placeholder="08xxxxxxxxxx">
                            </div>
                            <div class="field">
                                <label>Nomor WhatsApp <span class="req">*</span></label>
                                <input type="text" name="whatsapp" required placeholder="08xxxxxxxxxx">
                            </div>
                            <div class="field">
                                <label>Jenis Profesi / Usaha Individu <span class="req">*</span></label>
                                <select name="profession" required style="width:100%; height:42px; padding:0 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:13.5px; color:#0f172a; background:#fff;">
                                    <option value="">Pilih profesi / usaha</option>
                                    <option value="Kuliner &amp; Katering">Kuliner &amp; Katering</option>
                                    <option value="Perdagangan &amp; Eceran">Perdagangan &amp; Eceran</option>
                                    <option value="Jasa Perorangan / Rumah Tangga">Jasa Perorangan / Rumah Tangga</option>
                                    <option value="Pertanian &amp; Peternakan">Pertanian &amp; Peternakan</option>
                                    <option value="Teknologi &amp; Kreatif">Teknologi &amp; Kreatif</option>
                                    <option value="Lainnya">Lainnya</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>NPWP <span class="req">*</span></label>
                                <input type="text" name="npwp" required placeholder="Masukkan NPWP">
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
                                <input type="text" name="linkedin" placeholder="username atau https://linkedin.com/in/...">
                            </div>
                            <div class="field">
                                <label>Facebook</label>
                                <input type="text" name="facebook" placeholder="@username atau https://facebook.com/...">
                            </div>
                            <div class="field" style="grid-column: 1 / -1;">
                                <label>Instagram</label>
                                <input type="text" name="instagram" placeholder="@username atau https://instagram.com/..." style="max-width:calc(50% - 8px);">
                            </div>
                        </div>
                        <div class="field">
                            <label>Deskripsi Singkat Usaha / Rekrutmen</label>
                            <textarea name="description" placeholder="Tuliskan informasi singkat mengenai kegiatan, usaha, atau kebutuhan rekrutmen..." style="min-height:80px; padding:10px 14px; font-size:13.5px; border:1px solid #cbd5e1; border-radius:8px; width:100%; font-family:inherit; line-height:1.5;"></textarea>
                        </div>
                    </div>
                    <hr class="modal-section-hr">
                    <div class="modal-section" style="margin-bottom:24px;">
                        <div class="section-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:14px;">3. ALAMAT DOMISILI PEMBERI KERJA</div>

                        <div style="margin-bottom:14px;">
                            <label style="font-size:13px; font-weight:600; color:#334155; display:flex; align-items:center; gap:8px; cursor:pointer;">
                                <input type="checkbox" name="same_location_siapkerja" id="cbSameLocation" value="1">
                                <span>Sama seperti lokasi Domisili?</span>
                            </label>
                        </div>

                        <!-- HIDDEN LOCATION FIELDS FOR BACKEND -->
                        <input type="hidden" name="province" id="hiddenProvince" value="">
                        <input type="hidden" name="city" id="hiddenCity" value="">
                        <input type="hidden" name="district" id="hiddenDistrict" value="">
                        <input type="hidden" name="village" id="hiddenVillage" value="">
                        <input type="hidden" name="domicile_city_id" id="hiddenDomicileCityId" value="">

                        <div class="field-grid" style="display:grid; grid-template-columns:2fr 1fr; gap:16px; margin-bottom:16px;">
                            <div class="field" style="position: relative;">
                                <label>Lokasi Domisili Pemberi Kerja <span class="req">*</span></label>
                                <div id="hierarchicalLocationInput" class="hierarchical-loc-field" tabindex="0">
                                    <span id="locDisplayValue" class="placeholder">Pilih lokasi domisili</span>
                                    <i id="locCaret" class="fa-solid fa-chevron-down" style="color:#64748b; font-size:12px;"></i>
                                </div>

                                <div id="hierarchicalLocDropdown" class="loc-dropdown-popup" style="display: none;">
                                    <div id="locBreadcrumbs" class="loc-breadcrumbs">
                                        <span class="crumb-btn active" data-level="1">Pilih Provinsi</span>
                                    </div>
                                    <div id="locOptionsList" class="loc-options-list"></div>
                                </div>

                                <div id="siapkerjaNotice" style="display:none; color:#0284c7; font-size:12px; margin-top:6px; background:#f0f9ff; padding:8px 12px; border-radius:6px; border:1px solid #bae6fd; font-weight:500;">
                                    <i class="fa-solid fa-circle-info" style="margin-right:6px;"></i>Lokasi domisili akan menggunakan data dari akun SIAPKerja.
                                </div>
                            </div>

                            <div class="field">
                                <label>Kode Pos <span class="req">*</span></label>
                                <select name="postal_code" id="selectPostalCode" style="width:100%; height:42px; padding:0 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:13.5px; color:#0f172a; background:#fff;" disabled>
                                    <option value="">Pilih Kode Pos</option>
                                </select>
                            </div>
                        </div>

                        <div class="field" style="margin-bottom:14px;">
                            <label>Alamat Lengkap <span class="req">*</span></label>
                            <input type="text" name="address" id="inputAddress" required placeholder="Masukkan nama jalan, nomor bangunan, RT/RW, dan alamat lengkap...">
                        </div>
                        <div class="field" style="margin-bottom:16px;">
                            <label>Detail Alamat / Patokan (Opsional)</label>
                            <input type="text" name="address_notes" id="inputAddressNotes" placeholder="Contoh: Ruko lantai 2, sebelah Kantor Kelurahan">
                        </div>
                        <div class="field-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">
                            <div class="field">
                                <label>Dokumen Pendukung <span class="req">*</span></label>
                                <input type="file" name="supporting_doc" required accept=".pdf,.jpg,.jpeg,.png" style="display:block; width:100%;">
                                <div style="font-size:12px; color:#64748b; margin-top:4px;">Minimal 1 dokumen wajib</div>
                            </div>
                            <div class="field">
                                <label>Foto Bukti Tempat Usaha / Lokasi <span class="req">*</span></label>
                                <input type="file" name="workplace_photo" required accept=".jpg,.jpeg,.png,.webp" style="display:block; width:100%;">
                                <div style="font-size:12px; color:#64748b; margin-top:4px;">Wajib diunggah</div>
                            </div>
                        </div>

                        <!-- PETA LOKASI (VIEW-ONLY, MUNCUL SETELAH ALAMAT LENGKAP TERISI) -->
                        <div class="field" id="mapFieldContainer" style="margin-top:16px;">
                            <label style="margin-bottom:8px; font-weight:700; color:#0f172a; display:block;">Peta Lokasi</label>
                            <div id="pkiMapContainer" style="height:220px; width:100%; border-radius:10px; border:1px solid #cbd5e1; overflow:hidden; position:relative; background:#f8fafc;">
                                <div id="mapPlaceholder" style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%; color:#64748b; text-align:center; padding:20px;">
                                    <div style="font-size:28px; margin-bottom:6px;">🗺</div>
                                    <div style="font-size:14px; font-weight:700; color:#334155; margin-bottom:4px;">Pratinjau peta belum tersedia</div>
                                    <div style="font-size:12px; max-width:360px; line-height:1.4;">Tuliskan Alamat Lengkap untuk menampilkan peta lokasi.</div>
                                </div>
                                <div id="leafletMap" style="height:100%; width:100%; display:none;"></div>
                            </div>
                            <div id="mapOpenLinkWrapper" style="display:none; margin-top:8px; text-align:right;">
                                <a id="btnOpenMap" href="#" target="_blank" style="display:inline-flex; align-items:center; gap:6px; color:#0284c7; font-size:12.5px; text-decoration:none; font-weight:600;">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Buka di Maps
                                </a>
                            </div>
                        </div>
                    </div>
                    <hr class="modal-section-hr">
                    <div class="modal-section">
                        <div class="section-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:14px;">4. PERNYATAAN PERSETUJUAN</div>
                        <label style="display:flex; align-items:flex-start; gap:10px; font-size:13px; color:#334155; line-height:1.5;">
                            <input type="checkbox" name="user_consent" id="cbUserConsent" value="1" required style="margin-top:3px;">
                            <span>Saya menyatakan bahwa seluruh informasi yang saya berikan adalah benar dan dapat dipertanggungjawabkan. Saya berkomitmen untuk tidak melakukan penipuan, mempublikasikan lowongan palsu, atau tindakan lain yang merugikan pelamar maupun melanggar hukum. Apabila terbukti melakukan pelanggaran, saya bersedia menerima sanksi sesuai ketentuan hukum yang berlaku. <span class="req">*</span></span>
                        </label>
                    </div>
                </div>

                <div class="modal-footer" style="padding:16px 24px;">
                    <button type="submit" class="primary-btn" id="btnDaftarPopup">
                        <i class="fa-solid fa-paper-plane"></i>
                        Daftar
                    </button>
                </div>
                </form>
            </div>
        </div>
    </div>
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
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        (function () {
            var modal = document.getElementById('modalPendaftaranBerhasil');
            if (modal) {
                modal.addEventListener('click', function (event) {
                    if (event.target === modal) {
                        modal.classList.remove('open');
                    }
                });
            }

            var cbSameLocation = document.getElementById('cbSameLocation');
            var siapkerjaNotice = document.getElementById('siapkerjaNotice');
            var locInput = document.getElementById('hierarchicalLocationInput');
            var locDropdown = document.getElementById('hierarchicalLocDropdown');
            var locDisplay = document.getElementById('locDisplayValue');
            var locCaret = document.getElementById('locCaret');
            var breadcrumbs = document.getElementById('locBreadcrumbs');
            var optionsList = document.getElementById('locOptionsList');

            var hiddenProv = document.getElementById('hiddenProvince');
            var hiddenCity = document.getElementById('hiddenCity');
            var hiddenDist = document.getElementById('hiddenDistrict');
            var hiddenVill = document.getElementById('hiddenVillage');
            var hiddenCityId = document.getElementById('hiddenDomicileCityId');
            var selectPostal = document.getElementById('selectPostalCode');
            var inputAddress = document.getElementById('inputAddress');

            var mapBox = document.getElementById('leafletMap');
            var mapPlaceholder = document.getElementById('mapPlaceholder');
            var mapLinkWrap = document.getElementById('mapOpenLinkWrapper');
            var btnOpenMap = document.getElementById('btnOpenMap');
            var leafletMapInstance = null;
            var pkiMapMarker = null;

            var siapkerjaData = {
                province: 'Jawa Barat',
                city: 'Kota Bekasi',
                district: 'Bekasi Selatan',
                village: 'Pekayon Jaya',
                postal: '17148'
            };

            var selProv = '';
            var selCity = '';
            var selDistrict = '';
            var selVillage = '';
            var currentLocLevel = 1;

            var locData = window.ID_LOCATIONS || {
                'Aceh': {
                    'Kota Banda Aceh': {
                        'Kuta Alam': { 'Beurawe': '23124', 'Bandar Baru': '23126', 'Kota Baru': '23125', 'Keuramat': '23123', 'Lambaro Skep': '23127' },
                        'Baiturrahman': { 'Neusu Aceh': '23241', 'Peunitia': '23241', 'Ateuk Pabuat': '23241', 'Sukaramai': '23241' },
                        'Banda Raya': { 'Lamlagang': '23239', 'Geuceu Komplek': '23239', 'Geuceu Ineum': '23239' },
                        'Jaya Baru': { 'Punge Blang Cut': '23233', 'Lampoh Daya': '23233', 'Empee Trieng': '23233' },
                        'Syiah Kuala': { 'Darussalam': '23111', 'Kopelma Darussalam': '23111', 'Ie Masen Kayee Adang': '23111' }
                    },
                    'Kota Sabang': {
                        'Sukakarya': { 'Aneuk Laot': '23511', 'Iboih': '23523', 'Krueng Raya': '23511' },
                        'Sukajaya': { 'Anoi Itam': '23521', 'Balohan': '23521', 'Cot Abeuk': '23521' }
                    },
                    'Kabupaten Aceh Besar': {
                        'Ingin Jaya': { 'Lambaro': '23371', 'Aneyuk Batee': '23371', 'Lubok Sukon': '23371' },
                        'Darul Imarah': { 'Lampeuneurut': '23238', 'Punieu': '23238', 'Gue Gajah': '23238' },
                        'Lhoknga': { 'Mon Ikeun': '23353', 'Lampuuk': '23353' }
                    },
                    'Kabupaten Pidie': {
                        'Sigli': { 'Blang Paseh': '24112', 'Kuala Pidie': '24113', 'Kramat Luar': '24114' }
                    }
                },
                'Sumatera Utara': {
                    'Kota Medan': {
                        'Medan Kota': { 'Pasar Merah Timur': '20217', 'Teladan Barat': '20217', 'Pusat Pasar': '20212', 'Siti Rejo I': '20216' },
                        'Medan Petisah': { 'Sekip': '20111', 'Petisah Tengah': '20112', 'Sei Sikambing D': '20114', 'Silalas': '20114' },
                        'Medan Barat': { 'Kesawan': '20111', 'Glugur Kota': '20115', 'Karang Berombak': '20115' },
                        'Medan Timur': { 'Gugor': '20235', 'Perintis': '20231', 'Sidodadi': '20234' },
                        'Medan Selayang': { 'Padang Bulan Selayang I': '20131', 'Sempakata': '20131', 'Tanjung Sari': '20132' },
                        'Medan Johor': { 'Gedung Johor': '20144', 'Pangkalan Mansyur': '20143', 'Suka Maju': '20146' },
                        'Medan Helvetia': { 'Helvetia': '20124', 'Dwikora': '20123', 'Tanjung Gusta': '20125' }
                    },
                    'Kota Binjai': {
                        'Binjai Kota': { 'Pekan Binjai': '20711', 'Kartini': '20712', 'Setia': '20713' },
                        'Binjai Barat': { 'Payaroba': '20718', 'Limau Sundai': '20719' }
                    },
                    'Kabupaten Deli Serdang': {
                        'Lubuk Pakam': { 'Lubuk Pakam Pekan': '20511', 'Sekip': '20512', 'Bakaran Batu': '20513' },
                        'Percut Sei Tuan': { 'Tembung': '20371', 'Saentis': '20371', 'Sampali': '20371' },
                        'Sunggal': { 'Sunggal Kanan': '20351', 'Helvetia': '20351', 'Sei Semayang': '20351' },
                        'Tanjung Morawa': { 'Tanjung Morawa A': '20362', 'Tanjung Morawa B': '20362', 'Wono Giri': '20362' }
                    }
                },
                'Sumatera Barat': {
                    'Kota Padang': {
                        'Padang Barat': { 'Olo': '25117', 'Kampung Jao': '25112', 'Flamboyan Baru': '25115' },
                        'Padang Timur': { 'Sawahan': '25121', 'Jati': '25129', 'Ganting Parak Gadang': '25122' },
                        'Padang Selatan': { 'Mata Air': '25211', 'Pasa Gadang': '25211', 'Teluk Bayur': '25213' },
                        'Kuranji': { 'Kuranji': '25157', 'Pasar Ambacang': '25152', 'Anduring': '25151' }
                    },
                    'Kota Bukittinggi': {
                        'Guguk Panjang': { 'Tarigo': '26114', 'Benteng Pasar Atas': '26113', 'Kayu Kubu': '26115' },
                        'Mandiangin Koto Selayan': { 'Campago Ipuuh': '26122', 'Pulai Anak Air': '26124' }
                    }
                },
                'Riau': {
                    'Kota Pekanbaru': {
                        'Pekanbaru Kota': { 'Simpang Empat': '28116', 'Sumahilang': '28111', 'Sukaramai': '28112' },
                        'Tampan': { 'Sidomulyo Barat': '28289', 'Delima': '28289', 'Tuah Karya': '28289' },
                        'Marpoyan Damai': { 'Tangkerang Tengah': '28282', 'Sidomulyo Timur': '28284' },
                        'Rumbai': { 'Umban Sari': '28265', 'Palas': '28264' }
                    },
                    'Kota Dumai': {
                        'Dumai Timur': { 'Teluk Binjai': '28812', 'Buluh Kasap:': '28814', 'Jaya Mukti': '28815' }
                    }
                },
                'Kepulauan Riau': {
                    'Kota Batam': {
                        'Batam Kota': { 'Teluk Tering': '29461', 'Belian': '29464', 'Baloi Permai': '29432', 'Sukajadi': '29432' },
                        'Lubuk Baja': { 'Nagoya': '29432', 'Kampung Seraya': '29432', 'Batu Selicin': '29432' },
                        'Sekupang': { 'Tiban Indah': '29425', 'Sungai Harapan': '29422', 'Tiban Lama': '29424' },
                        'Batu Ampar': { 'Jabu Subur': '29452', 'Sungai Jodoh': '29453' }
                    },
                    'Kota Tanjungpinang': {
                        'Tanjungpinang Kota': { 'Tanjungpinang Kota': '29111', 'Kampung Bugis': '29112' },
                        'Bukit Bestari': { 'Tanjung Ayun': '29122', 'Dompak': '29124' }
                    }
                },
                'Sumatera Selatan': {
                    'Kota Palembang': {
                        'Ilir Timur I': { 'Demang Lebar Daun': '30137', '20 Ilir D I': '30128', 'Sungai Buah': '30118' },
                        'Ilir Barat I': { 'Lorok Pakjo': '30137', 'Demang Lebar Daun': '30137', 'Bukit Lama': '30139' },
                        'Seberang Ulu I': { '7 Ulu': '30251', '9/10 Ulu': '30251', '15 Ulu': '30252' },
                        'Bukit Kecil': { '26 Ilir': '30136', 'Talang Semut': '30135' },
                        'Plaju': { 'Plaju Ulu': '30266', 'Plaju Darat': '30268' }
                    }
                },
                'Bengkulu': {
                    'Kota Bengkulu': {
                        'Ratu Samban': { 'Pengantungan': '38221', 'Belakang Pondok': '38222', 'Padang Jati': '38227' },
                        'Teluk Segara': { 'Pasar Baru': '38114', 'Kampung Kelawi': '38119' }
                    }
                },
                'Lampung': {
                    'Kota Bandar Lampung': {
                        'Tanjung Karang Pusat': { 'Tanjung Karang': '35111', 'Enggal': '35118', 'Gotong Royong': '35119' },
                        'Kedaton': { 'Kedaton': '35141', 'Labuhan Ratu': '35142', 'Penengahan': '35143' },
                        'Teluk Betung Selatan': { 'Gedong Pakuon': '35221', 'Pesawahan': '35223' }
                    }
                },
                'DKI Jakarta': {
                    'Kota Jakarta Selatan': {
                        'Tebet': { 'Bukit Duri': '12840', 'Kebon Baru': '12830', 'Manggarai': '12850', 'Manggarai Selatan': '12860', 'Menteng Dalam': '12870', 'Tebet Barat': '12810', 'Tebet Timur': '12820' },
                        'Kebayoran Baru': { 'Cipete Utara': '12150', 'Gandaria Utara': '12140', 'Gunung': '12120', 'Kramat Pela': '12130', 'Melawai': '12160', 'Petogogan': '12170', 'Pulo': '12160', 'Rawa Barat': '12180', 'Selong': '12110', 'Senayan': '12190' },
                        'Cilandak': { 'Cilandak Barat': '12430', 'Cipete Selatan': '12410', 'Gandaria Selatan': '12420', 'Lebak Bulus': '12440', 'Pondok Labu': '12450' },
                        'Setiabudi': { 'Guntur': '12980', 'Karet': '12920', 'Karet Kuningan': '12940', 'Karet Semanggi': '12930', 'Kuningan Timur': '12950', 'Menteng Atas': '12960', 'Pasar Manggis': '12970', 'Setiabudi': '12910' },
                        'Pasar Minggu': { 'Cilandak Timur': '12560', 'Jati Padang': '12540', 'Kebagusan': '12520', 'Pasar Minggu': '12520', 'Pejaten Barat': '12510', 'Pejaten Timur': '12510', 'Ragunan': '12550' },
                        'Pancoran': { 'Cikoko': '12770', 'Duren Tiga': '12760', 'Kalibata': '12740', 'Pancoran': '12780', 'Pengadegan': '12770', 'Rawajati': '12750' },
                        'Kebayoran Lama': { 'Cipulir': '12230', 'Grogol Selatan': '12220', 'Grogol Utara': '12210', 'Kebayoran Lama Selatan': '12240', 'Kebayoran Lama Utara': '12240', 'Pondok Pinang': '12310' },
                        'Jagakarsa': { 'Ciganjur': '12630', 'Cipedak': '12630', 'Jagakarsa': '12620', 'Lenteng Agung': '12610', 'Srengseng Sawah': '12640', 'Tanjung Barat': '12530' }
                    },
                    'Kota Jakarta Pusat': {
                        'Gambir': { 'Cideng': '10150', 'Duri Pulo': '10140', 'Gambir': '10110', 'Kebon Kelapa': '10120', 'Petojo Selatan': '10160', 'Petojo Utara': '10130' },
                        'Tanah Abang': { 'Bendungan Hilir': '10210', 'Gelora': '10270', 'Kampung Bali': '10250', 'Karet Tengsin': '10220', 'Kebon Kacang': '10240', 'Kebon Melati': '10230', 'Petamburan': '10260' },
                        'Menteng': { 'Cikini': '10330', 'Gondangdia': '10350', 'Kebon Sirih': '10340', 'Menteng': '10310', 'Pegangsaan': '10320' },
                        'Kemayoran': { 'Cempaka Baru': '10640', 'Gunung Sahari Selatan': '10610', 'Harapan Mulya': '10640', 'Kebon Kosong': '10630', 'Kemayoran': '10620', 'Serdang': '10650', 'Sumur Batu': '10650', 'Utan Panjang': '10650' },
                        'Sawah Besar': { 'Gunung Sahari Utara': '10720', 'Karang Anyar': '10740', 'Kartini': '10750', 'Mangga Dua Selatan': '10730', 'Pasar Baru': '10710' },
                        'Senen': { 'Bungur': '10460', 'Kenari': '10430', 'Kramat': '10450', 'Kwitang': '10420', 'Paseban': '10440', 'Senen': '10410' }
                    },
                    'Kota Jakarta Barat': {
                        'Grogol Petamburan': { 'Grogol': '11450', 'Jelambar': '11460', 'Jelambar Baru': '11460', 'Tanjung Duren Selatan': '11470', 'Tanjung Duren Utara': '11470', 'Tomang': '11440', 'Wijaya Kusuma': '11460' },
                        'Kebon Jeruk': { 'Duri Kepa': '11510', 'Kebon Jeruk': '11530', 'Kedoya Selatan': '11520', 'Kedoya Utara': '11520', 'Kelapa Dua': '11550', 'Sukabumi Selatan': '11560', 'Sukabumi Utara': '11540' },
                        'Kembangan': { 'Joglo': '11640', 'Kembangan Selatan': '11610', 'Kembangan Utara': '11610', 'Meruya Selatan': '11650', 'Meruya Utara': '11620', 'Srengseng': '11630' },
                        'Palmerah': { 'Jatipulo': '11430', 'Kemanggisan': '11480', 'Kota Bambu Selatan': '11420', 'Kota Bambu Utara': '11420', 'Palmerah': '11480', 'Slipi': '11410' },
                        'Cengkareng': { 'Cengkareng Barat': '11730', 'Cengkareng Timur': '11730', 'Duri Kosambi': '11750', 'Kapuk': '11720', 'Kedaung Kali Angke': '11710', 'Rawa Buaya': '11740' }
                    },
                    'Kota Jakarta Timur': {
                        'Jatinegara': { 'Bali Mester': '13310', 'Bidara Cina': '13330', 'Cipinang Besar Selatan': '13410', 'Cipinang Besar Utara': '13410', 'Cipinang Cempedak': '13340', 'Cipinang Muara': '13420', 'Kampung Melayu': '13320' },
                        'Duren Sawit': { 'Duren Sawit': '13440', 'Klender': '13470', 'Malaka Jaya': '13460', 'Malaka Sari': '13460', 'Pondok Bambu': '13430', 'Pondok Kelapa': '13450', 'Pondok Kopi': '13460' },
                        'Cakung': { 'Cakung Barat': '13910', 'Cakung Timur': '13910', 'Jatinegara': '13930', 'Penggilingan': '13940', 'Pulogebang': '13950', 'Rawa Terate': '13920', 'Ujung Menteng': '13960' },
                        'Kramat Jati': { 'Balekambang': '13530', 'Batu Ampar': '13520', 'Cawang': '13630', 'Cililitan': '13640', 'Dukuh': '13550', 'Kramat Jati': '13510', 'Tengah': '13540' },
                        'Pulogadung': { 'Cipinang': '13240', 'Jati': '13210', 'Jatinegara Kaum': '13250', 'Kayu Putih': '13210', 'Pisangan Timur': '13230', 'Pulogadung': '13260', 'Rawamangun': '13220' }
                    },
                    'Kota Jakarta Utara': {
                        'Kelapa Gading': { 'Kelapa Gading Barat': '14240', 'Kelapa Gading Timur': '14240', 'Pegangsaan Dua': '14250' },
                        'Penjaringan': { 'Kamal Muara': '14470', 'Kapuk Muara': '14460', 'Pejagalan': '14450', 'Penjaringan': '14440', 'Pluit': '14450' },
                        'Tanjung Priok': { 'Kebon Bawang': '14320', 'Papanggo': '14340', 'Sungai Bambu': '14330', 'Sunter Agung': '14350', 'Sunter Jaya': '14350', 'Tanjung Priok': '14310', 'Warakas': '14340' }
                    }
                },
                'Jawa Barat': {
                    'Kota Bekasi': {
                        'Bekasi Selatan': { 'Pekayon Jaya': '17148', 'Jaka Setia': '17147', 'Kayuringin Jaya': '17144', 'Marga Jaya': '17141' },
                        'Bekasi Timur': { 'Aren Jaya': '17111', 'Bekasi Jaya': '17112', 'Duren Jaya': '17111', 'Margahayu': '17113' },
                        'Bekasi Barat': { 'Bintara': '17134', 'Kranji': '17135', 'Kota Baru': '17133', 'Bintara Jaya': '17136' },
                        'Bekasi Utara': { 'Harapan Baru': '17123', 'Harapan Jaya': '17124', 'Teluk Pucung': '17121', 'Perwira': '17122' },
                        'Rawalumbu': { 'Bojong Rawalumbu': '17116', 'Pengasinan': '17115', 'Sepanjang Jaya': '17114' },
                        'Pondok Gede': { 'Jaticempaka': '17411', 'Jatiwaringin': '17411', 'Jatibening': '17412' },
                        'Jatiasih': { 'Jatiasih': '17423', 'Jatikramat': '17421', 'Jatimekar': '17422' },
                        'Medan Satria': { 'Medan Satria': '17132', 'Pejuang': '17131', 'Harapan Mulya': '17143' }
                    },
                    'Kabupaten Bekasi': {
                        'Cikarang Pusat': { 'Jayamukti': '17530', 'Sukamahi': '17530', 'Pasirranji': '17530' },
                        'Cikarang Selatan': { 'Cibatu': '17530', 'Pasirsari': '17530', 'Sukaresmi': '17530', 'Serang': '17530' },
                        'Cikarang Utara': { 'Waluya': '17530', 'Simpangan': '17530', 'Mekarmukti': '17530' },
                        'Tambun Selatan': { 'Jatimulya': '17510', 'Tambun': '17510', 'Tridayajaya': '17510', 'Mekarsari': '17510' },
                        'Cibitung': { 'Wanasari': '17520', 'Gandasari': '17520' }
                    },
                    'Kota Bandung': {
                        'Coblong': { 'Dago': '40135', 'Sadang Serang': '40133', 'Sekeloa': '40134', 'Lebak Siliwangi': '40132', 'Cipaganti': '40131' },
                        'Sukajadi': { 'Pasteur': '40161', 'Sukajadi': '40162', 'Sukawarna': '40164', 'Gegerkalongan': '40153' },
                        'Sumur Bandung': { 'Braga': '40111', 'Kebon Pisang': '40112', 'Merdeka': '40113' },
                        'Bandung Wetan': { 'Citarum': '40115', 'Tamansari': '40116', 'Cihapit': '40114' },
                        'Cicendo': { 'Pasirkaliki': '40171', 'Arjuna': '40172', 'Pajajaran': '40173' },
                        'Lengkong': { 'Malabar': '40262', 'Cijagra': '40265', 'Burangrang': '40262' },
                        'Regol': { 'Balonggede': '40251', 'Ciateul': '40252', 'Palleser': '40253' }
                    },
                    'Kota Depok': {
                        'Beji': { 'Beji': '16421', 'Kukusan': '16425', 'Pondok Cina': '16424', 'Tanah Baru': '16426' },
                        'Pancoran Mas': { 'Depok': '16431', 'Mampang': '16433', 'Depok Jaya': '16432', 'Rangkapan Jaya': '16435' },
                        'Cimanggis': { 'Tugu': '16451', 'Pasir Gunung Selatan': '16451', 'Mekarsari': '16452' },
                        'Sukmajaya': { 'Abadijaya': '16417', 'Mekarjaya': '16411', 'Baktijaya': '16418' }
                    },
                    'Kota Bogor': {
                        'Bogor Tengah': { 'Babakan': '16128', 'Paledang': '16122', 'Sempur': '16129', 'Kebon Kelapa': '16125' },
                        'Bogor Timur': { 'Baranangsiang': '16143', 'Katulampa': '16144', 'Tajur': '16141' },
                        'Bogor Selatan': { 'Batutulis': '16133', 'Lawanggintung': '16134', 'Empang': '16132' },
                        'Tanah Sareal': { 'Tanah Sareal': '16161', 'Kedung Badak': '16162', 'Kebon Pedes': '16165' }
                    },
                    'Kabupaten Bogor': {
                        'Cibinong': { 'Cibinong': '16911', 'Cirimekar': '16915', 'Pakansari': '16915', 'Nanggewer': '16912' },
                        'Gunung Putri': { 'Gunung Putri': '16961', 'Tlajung Udik': '16962', 'Cicadas': '16964' },
                        'Bojonggede': { 'Bojonggede': '16922', 'Pabuaran': '16921' },
                        'Parung': { 'Parung': '16330', 'Waru': '16330' }
                    },
                    'Kota Cimahi': {
                        'Cimahi Utara': { 'Cipageran': '40511', 'Citeureup': '40512', 'Pasirkaliki': '40514' },
                        'Cimahi Tengah': { 'Cimahi': '40525', 'Karangmekar': '40523', 'Padasuka': '40526' }
                    }
                },
                'Banten': {
                    'Kota Tangerang': {
                        'Tangerang': { 'Cikokol': '15117', 'Babakan': '15118', 'Buaran Indah': '15119', 'Tanah Tinggi': '15119' },
                        'Cipondoh': { 'Cipondoh': '15148', 'Petir': '15147', 'Poris Plawad': '15141', 'Poris Indah': '15141' },
                        'Karawaci': { 'Karawaci': '15115', 'Cimone': '15114', 'Bugel': '15113' },
                        'Ciledug': { 'Sudimara Barat': '15151', 'Paninggilan': '15153' },
                        'Pinang': { 'Kunciran': '15144', 'Pinang': '15145', 'Sudimara Pinang': '15145' }
                    },
                    'Kota Tangerang Selatan': {
                        'Serpong': { 'Rawa Buntu': '15318', 'Serpong': '15311', 'Lengkong Gudang': '15321', 'Lengkong Karya': '15322' },
                        'Serpong Utara': { 'Pakulonan': '15325', 'Jelupang': '15323', 'Pondok Jagung': '15326' },
                        'Pondok Aren': { 'Pondok Aren': '15224', 'Bintaro': '15225', 'Jurang Mangu Barat': '15223', 'Pondok Betung': '15221' },
                        'Pamulang': { 'Pamulang Barat': '15417', 'Pamulang Timur': '15417', 'Pondok Benda': '15416' },
                        'Ciputat': { 'Ciputat': '15411', 'Cipayung': '15414', 'Sawah Besar': '15413' }
                    },
                    'Kabupaten Tangerang': {
                        'Kelapa Dua': { 'Kelapa Dua': '15810', 'Bencongan': '15810', 'Bojong Nangka': '15810' },
                        'Curug': { 'Curug Kulon': '15810', 'Binong': '15810' },
                        'Cikupa': { 'Cikupa': '15710', 'Talaga': '15710' },
                        'Pasar Kemis': { 'Pasar Kemis': '15560', 'Kutajaya': '15560' }
                    },
                    'Kota Serang': {
                        'Serang': { 'Cipare': '42117', 'Serang': '42116', 'Kagungan': '42114', 'Lontarbaru': '42115' },
                        'Cipocok Jaya': { 'Cipocok Jaya': '42121', 'Banjaragung': '42122' }
                    },
                    'Kota Cilegon': {
                        'Cilegon': { 'Bagendung': '42419', 'Ciwedus': '42418' },
                        'Citangkil': { 'Citangkil': '42441' }
                    }
                },
                'Jawa Tengah': {
                    'Kota Semarang': {
                        'Semarang Tengah': { 'Pekunden': '50134', 'Sekyu': '50132', 'Bangunharjo': '50139', 'Pandansari': '50139' },
                        'Semarang Selatan': { 'Peterongan': '50242', 'Randusari': '50244', 'Pleburan': '50241', 'Lamper Kidul': '50249' },
                        'Gajahmungkur': { 'Bendan Ngisor': '50233', 'Petompon': '50237', 'Sampangan': '50233', 'Gajahmungkur': '50232' },
                        'Semarang Barat': { 'Karangayu': '50149', 'Krobokan': '50141', 'Cabean': '50141' },
                        'Banyumanik': { 'Srondol Kulon': '50265', 'Pedalangan': '50268', 'Pudakpayung': '50265' }
                    },
                    'Kota Surakarta': {
                        'Banjarsari': { 'Kadipiro': '57136', 'Nusukan': '57135', 'Timuran': '57131', 'Manahan': '57139' },
                        'Jebres': { 'Jebres': '57126', 'Purwodiningratan': '57128', 'Mojosongo': '57127' },
                        'Laweyan': { 'Purwosari': '57142', 'Kerten': '57143', 'Sondakan': '57147' },
                        'Pasar Kliwon': { 'Kauman': '57112', 'Kedung Lumbu': '57113' }
                    },
                    'Kabupaten Banyumas': {
                        'Purwokerto Timur': { 'Kranji': '53111', 'Sokanegara': '53115' },
                        'Purwokerto Selatan': { 'Karangklesem': '53144' }
                    },
                    'Kabupaten Kudus': {
                        'Kota Kudus': { 'Demaan': '59313', 'Glantengan': '59313' },
                        'Jati': { 'Getas Peformat': '59343' }
                    }
                },
                'DI Yogyakarta': {
                    'Kota Yogyakarta': {
                        'Danurejan': { 'Suryatmajan': '55213', 'Bausasran': '55211' },
                        'Gondokusuman': { 'Terban': '55223', 'Kotabaru': '55224', 'Baciro': '55225' },
                        'Jetis': { 'Cokrodiningratan': '55233', 'Gowongan': '55232' },
                        'Umbulharjo': { 'Muja Muju': '55165', 'Giwangan': '55163', 'Tahunan': '55167' },
                        'Mantrijeron': { 'Suryodiningratan': '55141', 'Gedongkiwo': '55142' }
                    },
                    'Kabupaten Sleman': {
                        'Depok': { 'Caturtunggal': '55281', 'Maguwoharjo': '55282', 'Condongcatur': '55283' },
                        'Mlati': { 'Sinduadi': '55284', 'Sendangadi': '55285' },
                        'Gamping': { 'Nogotirto': '55292', 'Ambarketawang': '55294' }
                    },
                    'Kabupaten Bantul': {
                        'Kasihan': { 'Bangunjiwo': '55184', 'Tirtonirmolo': '55181' },
                        'Banguntapan': { 'Banguntapan': '55198' }
                    }
                },
                'Jawa Timur': {
                    'Kota Surabaya': {
                        'Tegalsari': { 'Dr. Soetomo': '60264', 'Kedungdoro': '60261', 'Wonorejo': '60263', 'Keputran': '60265' },
                        'Gubeng': { 'Gubeng': '60281', 'Airlangga': '60286', 'Kertajaya': '60282', 'Mojo': '60285' },
                        'Wonokromo': { 'Darmo': '60241', 'Sawunggaling': '60242', 'Wonokromo': '60243', 'Jagir': '60244' },
                        'Sukolilo': { 'Keputih': '60111', 'Gebang Putih': '60117', 'Nginden Jangkungan': '60118', 'Menur Pumpungan': '60118' },
                        'Rungkut': { 'Kalirungkut': '60293', 'Rungkut Kidul': '60293', 'Medokan Ayu': '60295' },
                        'Genteng': { 'Embong Kaliasin': '60271', 'Genteng': '60275' },
                        'Jambangan': { 'Jambangan': '60232', 'Karah': '60232' }
                    },
                    'Kota Malang': {
                        'Lowokwaru': { 'Jatimulyo': '65141', 'Ketawanggede': '65145', 'Dinoyo': '65144', 'Mojolangu': '65142', 'Tunggulwulung': '65143' },
                        'Klojen': { 'Klojen': '65111', 'Rampal Celaket': '65111', 'Oro-Oro Dowo': '65119', 'Kauman': '65119' },
                        'Blimbing': { 'Blimbing': '65126', 'Purwantoro': '65122', 'Arjosari': '65126' },
                        'Sukun': { 'Sukun': '65147', 'Kebonsari': '65149' }
                    },
                    'Kabupaten Sidoarjo': {
                        'Sidoarjo': { 'Sidokumpul': '61212', 'Magersari': '61212', 'Lemahputro': '61213' },
                        'Waru': { 'Waru': '61256', 'Tropodo': '61256', 'Ngingas': '61256', 'Medaeng': '61256' },
                        'Gedangan': { 'Gedangan': '61254', 'Sawotratap': '61254' },
                        'Taman': { 'Sepanjang': '61257', 'Kalijaten': '61257' }
                    },
                    'Kota Kediri': {
                        'Kota': { 'Kampung Dalem': '64126', 'Pocanan': '64129' },
                        'Mojoroto': { 'Bandar Lor': '64114', 'Bujel': '64118' }
                    }
                },
                'Bali': {
                    'Kota Denpasar': {
                        'Denpasar Selatan': { 'Sanur': '80228', 'Renon': '80226', 'Panjer': '80225', 'Pedungan': '80222', 'Sidakarya': '80224' },
                        'Denpasar Barat': { 'Dauh Puri': '80113', 'Pemecutan': '80119', 'Padangsambian': '80117' },
                        'Denpasar Utara': { 'Peguyangan': '80115', 'Ubung': '80116' },
                        'Denpasar Timur': { 'Dangin Puri': '80232', 'Sumerta': '80235' }
                    },
                    'Kabupaten Badung': {
                        'Kuta': { 'Kuta': '80361', 'Legian': '80361', 'Seminyak': '80361' },
                        'Kuta Utara': { 'Tibubeneng': '80361', 'Kerobokan': '80361', 'Canggu': '80361' },
                        'Kuta Selatan': { 'Jimbaran': '80361', 'Benoa': '80361', 'Ungasan': '80361', 'Pecatu': '80361' },
                        'Mengwi': { 'Mengwi': '80351', 'Kapal': '80351' }
                    },
                    'Kabupaten Gianyar': {
                        'Ubud': { 'Ubud': '80571', 'Peliatan': '80571', 'Sayan': '80571', 'Petulu': '80571' },
                        'Sukawati': { 'Sukawati': '80582', 'Batubulan': '80582' }
                    }
                },
                'Nusa Tenggara Barat': {
                    'Kota Mataram': {
                        'Mataram': { 'Mataram Barat': '83126', 'Pejanggik': '83127' },
                        'Selaparang': { 'Monjok': '83122' },
                        'Ampenan': { 'Ampenan Selatan': '83111' }
                    }
                },
                'Nusa Tenggara Timur': {
                    'Kota Kupang': {
                        'Oebobo': { 'Oebobo': '85111', 'Fatululi': '85111' },
                        'Kelapa Lima': { 'Kelapa Lima': '85228' }
                    }
                },
                'Kalimantan Barat': {
                    'Kota Pontianak': {
                        'Pontianak Selatan': { 'Benua Melayu Darat': '78121', 'Akcaya': '78121' },
                        'Pontianak Kota': { 'Mariana': '78112', 'Sungai Bangkong': '78116' }
                    }
                },
                'Kalimantan Timur': {
                    'Kota Balikpapan': {
                        'Balikpapan Kota': { 'Klandasan Ulu': '76112', 'Damai': '76114' },
                        'Balikpapan Selatan': { 'Sepinggan': '76115', 'Gunung Bahagia': '76114' },
                        'Balikpapan Utara': { 'Muara Rapak': '76124' },
                        'Balikpapan Tengah': { 'Gunung Sari': '76113' }
                    },
                    'Kota Samarinda': {
                        'Samarinda Kota': { 'Bugis': '75121', 'Pelabuhan': '75112' },
                        'Samarinda Ulu': { 'Sidodadi': '75123', 'Air Putih': '75124' },
                        'Sungai Kunjang': { 'Teluk Lerong Ulu': '75127', 'Loa Bakung': '75126' }
                    },
                    'Nusantara (IKN)': {
                        'Sepaku': { 'Pemaluan': '76148', 'Bumi Harapan': '76148', 'Sepaku': '76148' }
                    }
                },
                'Sulawesi Selatan': {
                    'Kota Makassar': {
                        'Ujung Pandang': { 'Baraya': '90111', 'Mangkura': '90114', 'Sawerigading': '90115' },
                        'Panakkukang': { 'Masale': '90231', 'Tamamaung': '90231', 'Paropo': '90233', 'Karampuang': '90231' },
                        'Tamalate': { 'Tanjung Merdeka': '90224', 'Maccini Sombala': '90224', 'Mangasa': '90221' },
                        'Rappocini': { 'Buakana': '90222', 'Karunrung': '90222', 'Kassi-Kassi': '90222' },
                        'Biringkanaya': { 'Daya': '90241', 'Sudiang': '90242' }
                    }
                },
                'Sulawesi Utara': {
                    'Kota Manado': {
                        'Wenang': { 'Wenang Utara': '95121', 'Teling Bawah': '95125' },
                        'Malalayang': { 'Malalayang Satu': '95115' }
                    }
                },
                'Papua': {
                    'Kota Jayapura': {
                        'Jayapura Utara': { 'Gurabesi': '99111', 'Mandala': '99112' },
                        'Abepura': { 'Kota Baru': '99351', 'Asano': '99351' }
                    }
                }
            };

            function updatePostalDropdown(postalCodeVal) {
                if (!selectPostal) return;
                selectPostal.innerHTML = '';
                if (!postalCodeVal) {
                    selectPostal.innerHTML = '<option value="">Pilih Kode Pos</option>';
                    selectPostal.disabled = true;
                    return;
                }

                var codes = Array.isArray(postalCodeVal) ? postalCodeVal : [postalCodeVal];
                selectPostal.innerHTML = '<option value="">Pilih Kode Pos</option>';
                codes.forEach(function (c) {
                    var opt = document.createElement('option');
                    opt.value = c;
                    opt.textContent = c;
                    selectPostal.appendChild(opt);
                });

                selectPostal.disabled = false;
                if (codes.length === 1) {
                    selectPostal.value = codes[0];
                }
            }

            function updateMapState() {
                if (!inputAddress) return;
                var addrVal = inputAddress.value.trim();
                if (addrVal.length > 0) {
                    if (mapPlaceholder) mapPlaceholder.style.display = 'none';
                    if (mapBox) mapBox.style.display = 'block';
                    if (mapLinkWrap) mapLinkWrap.style.display = 'block';

                    var cityVal = (hiddenCity && hiddenCity.value) ? hiddenCity.value : 'Bekasi';
                    var queryStr = encodeURIComponent(addrVal + ', ' + cityVal);
                    if (btnOpenMap) {
                        btnOpenMap.href = 'https://www.google.com/maps/search/?api=1&query=' + queryStr;
                    }

                    if (window.L && mapBox) {
                        if (!leafletMapInstance) {
                            leafletMapInstance = L.map('leafletMap', {
                                dragging: false,
                                touchZoom: false,
                                scrollWheelZoom: false,
                                doubleClickZoom: false,
                                boxZoom: false,
                                keyboard: false,
                                zoomControl: true
                            }).setView([-6.2349, 106.9896], 15);

                            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                                attribution: '&copy; OpenStreetMap'
                            }).addTo(leafletMapInstance);

                            pkiMapMarker = L.marker([-6.2349, 106.9896], { interactive: false }).addTo(leafletMapInstance);
                        } else {
                            leafletMapInstance.invalidateSize();
                        }
                    }
                } else {
                    if (mapPlaceholder) mapPlaceholder.style.display = 'flex';
                    if (mapBox) mapBox.style.display = 'none';
                    if (mapLinkWrap) mapLinkWrap.style.display = 'none';
                }
            }

            if (inputAddress) {
                inputAddress.addEventListener('input', updateMapState);
                inputAddress.addEventListener('change', updateMapState);
            }

            function updateDisplayLocation() {
                if (cbSameLocation && cbSameLocation.checked) {
                    var fullLocStr = siapkerjaData.village + ', ' + siapkerjaData.district + ', ' + siapkerjaData.city + ', ' + siapkerjaData.province;
                    if (locDisplay) {
                        locDisplay.textContent = fullLocStr;
                        locDisplay.classList.remove('placeholder');
                    }
                    if (hiddenProv) hiddenProv.value = siapkerjaData.province;
                    if (hiddenCity) hiddenCity.value = siapkerjaData.city;
                    if (hiddenDist) hiddenDist.value = siapkerjaData.district;
                    if (hiddenVill) hiddenVill.value = siapkerjaData.village;
                    if (hiddenCityId) hiddenCityId.value = siapkerjaData.city;

                    if (selectPostal) {
                        selectPostal.innerHTML = '<option value="' + siapkerjaData.postal + '" selected>' + siapkerjaData.postal + '</option>';
                        selectPostal.disabled = true;
                    }

                    if (locInput) {
                        locInput.style.cursor = 'default';
                        locInput.style.background = '#f8fafc';
                    }
                    if (locCaret) locCaret.style.display = 'none';
                    if (siapkerjaNotice) siapkerjaNotice.style.display = 'block';
                    closeLocDropdown();
                } else {
                    if (locInput) {
                        locInput.style.cursor = 'pointer';
                        locInput.style.background = '#ffffff';
                    }
                    if (locCaret) locCaret.style.display = 'block';
                    if (siapkerjaNotice) siapkerjaNotice.style.display = 'none';

                    if (selProv && selCity && selDistrict && selVillage) {
                        if (locDisplay) {
                            locDisplay.textContent = selVillage + ', ' + selDistrict + ', ' + selCity + ', ' + selProv;
                            locDisplay.classList.remove('placeholder');
                        }
                    } else {
                        if (locDisplay) {
                            locDisplay.textContent = 'Pilih lokasi domisili';
                            locDisplay.classList.add('placeholder');
                        }
                        if (hiddenProv) hiddenProv.value = '';
                        if (hiddenCity) hiddenCity.value = '';
                        if (hiddenDist) hiddenDist.value = '';
                        if (hiddenVill) hiddenVill.value = '';
                        if (hiddenCityId) hiddenCityId.value = '';

                        if (selectPostal) {
                            selectPostal.innerHTML = '<option value="">Pilih Kode Pos</option>';
                            selectPostal.disabled = true;
                        }
                    }
                }
                updateMapState();
            }

            if (cbSameLocation) {
                cbSameLocation.addEventListener('change', function () {
                    updateDisplayLocation();
                });
            }

            if (locInput) {
                locInput.addEventListener('click', function (e) {
                    if (cbSameLocation && cbSameLocation.checked) return;
                    e.stopPropagation();
                    if (locDropdown && locDropdown.style.display === 'block') {
                        closeLocDropdown();
                    } else {
                        openLocDropdown();
                    }
                });
            }

            if (locDropdown) {
                locDropdown.addEventListener('click', function (e) {
                    e.stopPropagation();
                });
            }

            document.addEventListener('click', function (e) {
                if (locInput && locDropdown && !locInput.contains(e.target) && !locDropdown.contains(e.target)) {
                    closeLocDropdown();
                }
            });

            function openLocDropdown() {
                if (!locDropdown) return;
                locDropdown.style.display = 'block';
                if (locCaret) {
                    locCaret.classList.remove('fa-chevron-down');
                    locCaret.classList.add('fa-chevron-up');
                }

                if (selProv && selCity && selDistrict && selVillage && locData[selProv]?.[selCity]?.[selDistrict]) {
                    currentLocLevel = 4;
                } else if (selProv && selCity && selDistrict && locData[selProv]?.[selCity]?.[selDistrict]) {
                    currentLocLevel = 4;
                } else if (selProv && selCity && locData[selProv]?.[selCity]) {
                    currentLocLevel = 3;
                } else if (selProv && locData[selProv]) {
                    currentLocLevel = 2;
                } else {
                    currentLocLevel = 1;
                }
                renderLocationView();
            }

            function closeLocDropdown() {
                if (!locDropdown) return;
                locDropdown.style.display = 'none';
                if (locCaret) {
                    locCaret.classList.remove('fa-chevron-up');
                    locCaret.classList.add('fa-chevron-down');
                }
            }

            window.switchLocLevel = function (level) {
                currentLocLevel = level;
                renderLocationView();
            };

            window.selectProv = function (prov) {
                selProv = prov;
                selCity = '';
                selDistrict = '';
                selVillage = '';
                updatePostalDropdown('');
                currentLocLevel = 2;
                renderLocationView();
            };

            window.selectCity = function (city) {
                selCity = city;
                selDistrict = '';
                selVillage = '';
                updatePostalDropdown('');
                currentLocLevel = 3;
                renderLocationView();
            };

            window.selectDistrict = function (dist) {
                selDistrict = dist;
                selVillage = '';
                updatePostalDropdown('');
                currentLocLevel = 4;
                renderLocationView();
            };

            window.selectVillage = function (vill, postal) {
                selVillage = vill;
                if (hiddenProv) hiddenProv.value = selProv;
                if (hiddenCity) hiddenCity.value = selCity;
                if (hiddenDist) hiddenDist.value = selDistrict;
                if (hiddenVill) hiddenVill.value = selVillage;
                if (hiddenCityId) hiddenCityId.value = selCity;

                updatePostalDropdown(postal);

                if (locDisplay) {
                    locDisplay.textContent = selVillage + ', ' + selDistrict + ', ' + selCity + ', ' + selProv;
                    locDisplay.classList.remove('placeholder');
                }
                closeLocDropdown();
                updateMapState();
            };

            function renderLocationView() {
                renderBreadcrumbs();
                renderOptions();
            }

            function renderBreadcrumbs() {
                if (!breadcrumbs) return;
                var html = '';
                if (currentLocLevel === 1) {
                    html += '<span class="crumb-btn active" onclick="switchLocLevel(1)">Pilih Provinsi</span>';
                } else if (currentLocLevel === 2) {
                    html += '<span class="crumb-btn" onclick="switchLocLevel(1)">' + selProv + '</span> › ';
                    html += '<span class="crumb-btn active" onclick="switchLocLevel(2)">Pilih Kabupaten / Kota</span>';
                } else if (currentLocLevel === 3) {
                    html += '<span class="crumb-btn" onclick="switchLocLevel(1)">' + selProv + '</span> › ';
                    html += '<span class="crumb-btn" onclick="switchLocLevel(2)">' + selCity + '</span> › ';
                    html += '<span class="crumb-btn active" onclick="switchLocLevel(3)">Pilih Kecamatan</span>';
                } else if (currentLocLevel === 4) {
                    html += '<span class="crumb-btn" onclick="switchLocLevel(1)">' + selProv + '</span> › ';
                    html += '<span class="crumb-btn" onclick="switchLocLevel(2)">' + selCity + '</span> › ';
                    html += '<span class="crumb-btn" onclick="switchLocLevel(3)">' + selDistrict + '</span> › ';
                    html += '<span class="crumb-btn active" onclick="switchLocLevel(4)">Pilih Kelurahan / Desa</span>';
                }
                breadcrumbs.innerHTML = html;
            }

            function renderOptions() {
                if (!optionsList) return;
                var html = '';

                if (currentLocLevel === 1) {
                    Object.keys(locData).sort().forEach(function (prov) {
                        var isSel = prov === selProv;
                        html += '<div class="loc-opt-row ' + (isSel ? 'selected' : '') + '" onclick="selectProv(\'' + prov + '\')">';
                        html += '<div style="display:flex; align-items:center;"><span class="radio-bullet"></span><span>' + prov + '</span></div>';
                        html += '<i class="fa-solid fa-chevron-right" style="color:#94a3b8; font-size:12px;"></i></div>';
                    });
                } else if (currentLocLevel === 2 && selProv && locData[selProv]) {
                    Object.keys(locData[selProv]).sort().forEach(function (city) {
                        var isSel = city === selCity;
                        html += '<div class="loc-opt-row ' + (isSel ? 'selected' : '') + '" onclick="selectCity(\'' + city + '\')">';
                        html += '<div style="display:flex; align-items:center;"><span class="radio-bullet"></span><span>' + city + '</span></div>';
                        html += '<i class="fa-solid fa-chevron-right" style="color:#94a3b8; font-size:12px;"></i></div>';
                    });
                } else if (currentLocLevel === 3 && selProv && selCity && locData[selProv]?.[selCity]) {
                    Object.keys(locData[selProv][selCity]).sort().forEach(function (dist) {
                        var isSel = dist === selDistrict;
                        html += '<div class="loc-opt-row ' + (isSel ? 'selected' : '') + '" onclick="selectDistrict(\'' + dist + '\')">';
                        html += '<div style="display:flex; align-items:center;"><span class="radio-bullet"></span><span>' + dist + '</span></div>';
                        html += '<i class="fa-solid fa-chevron-right" style="color:#94a3b8; font-size:12px;"></i></div>';
                    });
                } else if (currentLocLevel === 4 && selProv && selCity && selDistrict && locData[selProv]?.[selCity]?.[selDistrict]) {
                    var vills = locData[selProv][selCity][selDistrict];
                    if (typeof vills === 'object') {
                        Object.keys(vills).sort().forEach(function (v) {
                            var isSel = v === selVillage;
                            var postal = vills[v];
                            html += '<div class="loc-opt-row ' + (isSel ? 'selected' : '') + '" onclick="selectVillage(\'' + v + '\', \'' + postal + '\')">';
                            html += '<div style="display:flex; align-items:center;"><span class="radio-bullet"></span><span>' + v + '</span></div>';
                            html += '<span style="font-size:12px; color:#64748b;">' + postal + '</span></div>';
                        });
                    }
                }
                optionsList.innerHTML = html;
            }

            var regForm = document.getElementById('registrationForm');
            if (regForm) {
                regForm.addEventListener('submit', function (e) {
                    if (cbSameLocation && !cbSameLocation.checked && (!hiddenVill || !hiddenVill.value)) {
                        e.preventDefault();
                        alert('Silakan pilih Lokasi Domisili Pemberi Kerja terlebih dahulu!');
                        return false;
                    }

                    if (selectPostal && !selectPostal.value) {
                        e.preventDefault();
                        alert('Silakan pilih Kode Pos terlebih dahulu!');
                        return false;
                    }
                });
            }

            updateDisplayLocation();
        })();
    </script>
</body>
</html>
