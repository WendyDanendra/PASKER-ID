<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user() && !isset($_GET['success'])) {
    redirect('index.php');
}

$showSuccessPopup = isset($_GET['success']) && $_GET['success'] === '1';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['register_action'] ?? '') === 'simulate_employer_session') {
    $ownerName = trim($_POST['owner_name'] ?? '');
    if ($ownerName === '') {
        $ownerName = 'Pemberi Kerja Individu';
    }

    $uniqueEmail = 'sim.employer.' . date('YmdHis') . '.' . random_int(1000, 9999) . '@paskerid.test';
    $tempPassword = 'Sim' . random_int(100000, 999999);

    create_user($ownerName, $uniqueEmail, $tempPassword, 'employer');
    $newUser = find_user_by_email($uniqueEmail);
    if ($newUser) {
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

                <form method="post">
                    <input type="hidden" name="register_action" value="simulate_employer_session">
                <div class="modal-body" style="padding:24px;">
                    <div class="modal-section" style="margin-bottom:24px;">
                        <div class="section-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:4px;">1. IDENTITAS PERORANGAN</div>
                        <div style="font-size:12.5px; color:#64748b; margin-bottom:16px;">Lengkapi informasi utama Pemberi Kerja Individu.</div>
                        <div class="field-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                            <div class="field">
                                <label>Nama Pemberi Kerja <span class="req">*</span></label>
                                <input type="text" name="owner_name" placeholder="Masukkan nama lengkap">
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

                        <div style="margin-bottom:14px;">
                            <label style="font-size:13px; font-weight:600; color:#334155; display:flex; align-items:center; gap:8px; cursor:pointer;">
                                <input type="checkbox" name="same_location_siapkerja" id="cbSameLocation" value="1" checked>
                                <span>Sama seperti lokasi Domisili?</span>
                            </label>
                            <div id="siapkerjaNotice" style="color:#0284c7; font-size:13px; margin-top:10px; background:#f0f9ff; padding:10px 14px; border-radius:8px; border:1px solid #bae6fd; font-weight:500;">
                                <i class="fa-solid fa-circle-info" style="margin-right:6px;"></i>Lokasi domisili akan menggunakan data dari akun SIAPKerja.
                            </div>
                        </div>

                        <div id="regionSelectorsContainer" style="display:none;">
                            <div class="field-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px;">
                                <div class="field">
                                    <label>Provinsi <span class="req">*</span></label>
                                    <select name="province" id="selectProvince" style="width:100%; height:42px; padding:0 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:13.5px; color:#0f172a; background:#fff;">
                                        <option value="">Pilih Provinsi</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Kabupaten / Kota <span class="req">*</span></label>
                                    <select name="city" id="selectCity" style="width:100%; height:42px; padding:0 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:13.5px; color:#0f172a; background:#fff;" disabled>
                                        <option value="">Pilih Kabupaten / Kota</option>
                                    </select>
                                </div>
                            </div>
                            <div class="field-grid" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px;">
                                <div class="field">
                                    <label>Kecamatan <span class="req">*</span></label>
                                    <select name="district" id="selectDistrict" style="width:100%; height:42px; padding:0 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:13.5px; color:#0f172a; background:#fff;" disabled>
                                        <option value="">Pilih Kecamatan</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Kelurahan / Desa <span class="req">*</span></label>
                                    <select name="village" id="selectVillage" style="width:100%; height:42px; padding:0 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:13.5px; color:#0f172a; background:#fff;" disabled>
                                        <option value="">Pilih Kelurahan / Desa</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Kode Pos</label>
                                    <input type="text" name="postal_code" id="inputPostalCode" placeholder="Masukkan kode pos">
                                </div>
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
            var regionSelectorsContainer = document.getElementById('regionSelectorsContainer');

            var selectProv = document.getElementById('selectProvince');
            var selectCity = document.getElementById('selectCity');
            var selectDist = document.getElementById('selectDistrict');
            var selectVill = document.getElementById('selectVillage');
            var inputPostal = document.getElementById('inputPostalCode');

            var locData = window.ID_LOCATIONS || {
                'DKI Jakarta': {
                    'Jakarta Selatan': {
                        'Kebayoran Baru': { 'Melawai': '12160', 'Gandaria Utara': '12140', 'Senayan': '12190' },
                        'Cilandak': { 'Cilandak Barat': '12430', 'Lebak Bulus': '12440' },
                        'Setiabudi': { 'Karet': '12920', 'Kuningan Timur': '12950' },
                        'Pasar Minggu': { 'Pejaten Barat': '12510', 'Pasar Minggu': '12520' }
                    },
                    'Jakarta Pusat': {
                        'Gambir': { 'Gambir': '10110', 'Petojo Selatan': '10160' },
                        'Tanah Abang': { 'Bendungan Hilir': '10210', 'Karet Tengsin': '10220' },
                        'Menteng': { 'Menteng': '10310', 'Cikini': '10330' }
                    },
                    'Jakarta Barat': {
                        'Grogol Petamburan': { 'Tanjung Duren Utara': '11470', 'Grogol': '11450' },
                        'Kebon Jeruk': { 'Kebon Jeruk': '11530', 'Kedoya Utara': '11520' }
                    },
                    'Jakarta Timur': {
                        'Jatinegara': { 'Kampung Melayu': '13320', 'Bidara Cina': '13330' },
                        'Duren Sawit': { 'Pondok Bambu': '13430', 'Duren Sawit': '13440' }
                    }
                },
                'Jawa Barat': {
                    'Kota Bekasi': {
                        'Bekasi Selatan': { 'Pekayon Jaya': '17148', 'Jaka Setia': '17147', 'Kayuringin Jaya': '17144', 'Marga Jaya': '17141' },
                        'Bekasi Timur': { 'Aren Jaya': '17111', 'Bekasi Jaya': '17112' },
                        'Bekasi Barat': { 'Bintara': '17134', 'Kranji': '17135' },
                        'Bekasi Utara': { 'Harapan Baru': '17123', 'Harapan Jaya': '17124' }
                    },
                    'Kabupaten Bekasi': {
                        'Cikarang Pusat': { 'Jayamukti': '17530', 'Sukamahi': '17530' },
                        'Cikarang Selatan': { 'Cibatu': '17530', 'Pasirsari': '17530' },
                        'Tambun Selatan': { 'Jatimulya': '17510', 'Tambun': '17510' }
                    },
                    'Kota Bandung': {
                        'Coblong': { 'Dago': '40135', 'Sadang Serang': '40133' },
                        'Sukajadi': { 'Pasteur': '40161', 'Sukajadi': '40162' }
                    },
                    'Kota Depok': {
                        'Beji': { 'Beji': '16421', 'Kukusan': '16425' },
                        'Pancoran Mas': { 'Depok': '16431', 'Mampang': '16433' }
                    },
                    'Kota Bogor': {
                        'Bogor Tengah': { 'Babakan': '16128', 'Paledang': '16122' }
                    }
                },
                'Banten': {
                    'Kota Tangerang': {
                        'Tangerang': { 'Cikokol': '15117', 'Babakan': '15118' },
                        'Cipondoh': { 'Cipondoh': '15148', 'Petir': '15147' }
                    },
                    'Kota Tangerang Selatan': {
                        'Serpong': { 'Rawa Buntu': '15318', 'Serpong': '15311' },
                        'Pondok Aren': { 'Pondok Aren': '15224', 'Bintaro': '15225' }
                    },
                    'Kota Serang': {
                        'Serang': { 'Cipare': '42117', 'Serang': '42116' }
                    }
                },
                'Jawa Tengah': {
                    'Kota Semarang': {
                        'Semarang Tengah': { 'Pekunden': '50134', 'Sekyu': '50132' }
                    },
                    'Kota Surakarta': {
                        'Banjarsari': { 'Kadipiro': '57136', 'Nusukan': '57135' }
                    }
                },
                'Jawa Timur': {
                    'Kota Surabaya': {
                        'Tegalsari': { 'Dr. Soetomo': '60264', 'Kedungdoro': '60261' },
                        'Gubeng': { 'Gubeng': '60281', 'Airlangga': '60286' }
                    },
                    'Kota Malang': {
                        'Lowokwaru': { 'Jatimulyo': '65141', 'Ketawanggede': '65145' }
                    }
                }
            };

            function populateProvinces() {
                if (!selectProv) return;
                selectProv.innerHTML = '<option value="">Pilih Provinsi</option>';
                Object.keys(locData).sort().forEach(function (prov) {
                    var opt = document.createElement('option');
                    opt.value = prov;
                    opt.textContent = prov;
                    selectProv.appendChild(opt);
                });
            }

            function toggleLocationView() {
                if (!cbSameLocation) return;
                if (cbSameLocation.checked) {
                    regionSelectorsContainer.style.display = 'none';
                    siapkerjaNotice.style.display = 'block';
                } else {
                    regionSelectorsContainer.style.display = 'block';
                    siapkerjaNotice.style.display = 'none';
                }
            }

            if (cbSameLocation) {
                cbSameLocation.addEventListener('change', toggleLocationView);
                toggleLocationView();
            }

            populateProvinces();

            if (selectProv) {
                selectProv.addEventListener('change', function () {
                    var provVal = this.value;
                    selectCity.innerHTML = '<option value="">Pilih Kabupaten / Kota</option>';
                    selectDist.innerHTML = '<option value="">Pilih Kecamatan</option>';
                    selectVill.innerHTML = '<option value="">Pilih Kelurahan / Desa</option>';
                    selectCity.disabled = !provVal;
                    selectDist.disabled = true;
                    selectVill.disabled = true;

                    if (provVal && locData[provVal]) {
                        Object.keys(locData[provVal]).sort().forEach(function (city) {
                            var opt = document.createElement('option');
                            opt.value = city;
                            opt.textContent = city;
                            selectCity.appendChild(opt);
                        });
                    }
                });
            }

            if (selectCity) {
                selectCity.addEventListener('change', function () {
                    var provVal = selectProv.value;
                    var cityVal = this.value;
                    selectDist.innerHTML = '<option value="">Pilih Kecamatan</option>';
                    selectVill.innerHTML = '<option value="">Pilih Kelurahan / Desa</option>';
                    selectDist.disabled = !cityVal;
                    selectVill.disabled = true;

                    if (provVal && cityVal && locData[provVal] && locData[provVal][cityVal]) {
                        Object.keys(locData[provVal][cityVal]).sort().forEach(function (dist) {
                            var opt = document.createElement('option');
                            opt.value = dist;
                            opt.textContent = dist;
                            selectDist.appendChild(opt);
                        });
                    }
                });
            }

            if (selectDist) {
                selectDist.addEventListener('change', function () {
                    var provVal = selectProv.value;
                    var cityVal = selectCity.value;
                    var distVal = this.value;
                    selectVill.innerHTML = '<option value="">Pilih Kelurahan / Desa</option>';
                    selectVill.disabled = !distVal;

                    if (provVal && cityVal && distVal && locData[provVal] && locData[provVal][cityVal] && locData[provVal][cityVal][distVal]) {
                        var vills = locData[provVal][cityVal][distVal];
                        if (Array.isArray(vills)) {
                            vills.forEach(function (v) {
                                var opt = document.createElement('option');
                                opt.value = v;
                                opt.textContent = v;
                                selectVill.appendChild(opt);
                            });
                        } else if (typeof vills === 'object') {
                            Object.keys(vills).sort().forEach(function (v) {
                                var opt = document.createElement('option');
                                opt.value = v;
                                opt.textContent = v;
                                selectVill.appendChild(opt);
                            });
                        }
                    }
                });
            }

            if (selectVill) {
                selectVill.addEventListener('change', function () {
                    var provVal = selectProv.value;
                    var cityVal = selectCity.value;
                    var distVal = selectDist.value;
                    var villVal = this.value;

                    if (inputPostal && provVal && cityVal && distVal && villVal && locData[provVal] && locData[provVal][cityVal] && locData[provVal][cityVal][distVal]) {
                        var vills = locData[provVal][cityVal][distVal];
                        if (typeof vills === 'object' && vills[villVal]) {
                            inputPostal.value = vills[villVal];
                        }
                    }
                });
            }
        })();
    </script>
</body>
</html>
