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
                                <label>Kode Pos</label>
                                <input type="text" name="postal_code" id="inputPostalCode" placeholder="Masukkan kode pos">
                            </div>
                        </div>

                        <div class="field" style="margin-bottom:14px;">
                            <label>Alamat Lengkap <span class="req">*</span></label>
                            <input type="text" name="address" id="inputAddress" placeholder="Masukkan nama jalan, nomor bangunan, RT/RW, dan alamat lengkap...">
                        </div>
                        <div class="field" style="margin-bottom:16px;">
                            <label>Detail Alamat / Patokan (Opsional)</label>
                            <input type="text" name="address_notes" id="inputAddressNotes" placeholder="Contoh: Ruko lantai 2, sebelah Kantor Kelurahan">
                        </div>
                        <div class="field-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">
                            <div class="field">
                                <label>Dokumen Pendukung <span class="req">*</span></label>
                                <input type="file" name="supporting_doc" accept=".pdf,.jpg,.jpeg,.png" style="display:block; width:100%;">
                                <div style="font-size:12px; color:#64748b; margin-top:4px;">Minimal 1 dokumen wajib</div>
                            </div>
                            <div class="field">
                                <label>Foto Bukti Tempat Usaha / Lokasi</label>
                                <input type="file" name="workplace_photo" accept=".jpg,.jpeg,.png,.webp" style="display:block; width:100%;">
                                <div style="font-size:12px; color:#64748b; margin-top:4px;">Opsional</div>
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
            var inputPostal = document.getElementById('inputPostalCode');
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
                    if (inputPostal) inputPostal.value = siapkerjaData.postal;

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
                        if (inputPostal) inputPostal.value = '';
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
                currentLocLevel = 2;
                renderLocationView();
            };

            window.selectCity = function (city) {
                selCity = city;
                selDistrict = '';
                selVillage = '';
                currentLocLevel = 3;
                renderLocationView();
            };

            window.selectDistrict = function (dist) {
                selDistrict = dist;
                selVillage = '';
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
                if (inputPostal && postal) inputPostal.value = postal;

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

            updateDisplayLocation();
        })();
    </script>
</body>
</html>
