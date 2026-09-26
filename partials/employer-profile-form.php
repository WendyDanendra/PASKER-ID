<?php
/**
 * Shared Form Profil Pemberi Kerja Individu
 * Digunakan untuk:
 * 1. Pendaftaran awal (register.php)
 * 2. Perbaikan / Revisi Profil (modal-employer-profile pada Index.html / dashboard.php)
 *
 * Variables passed in:
 * - $formMode: 'register' | 'revision' (default: 'revision')
 * - $formAction: string URL action (default: 'dashboard.php')
 * - $formId: string HTML form id (default: 'formEmployerProfile')
 * - $formData: array of initial / pre-filled values
 * - $isModal: boolean (default: false)
 */

$formMode = $formMode ?? 'revision';
$formAction = $formAction ?? ($formMode === 'register' ? 'register.php' : 'dashboard.php');
$formId = $formId ?? ($formMode === 'register' ? 'registrationForm' : 'formEmployerProfile');
$isModal = !empty($isModal);
$formData = $formData ?? [];

// Helper escape
if (!function_exists('pki_e')) {
    function pki_e($val) {
        return htmlspecialchars((string)($val ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// Map prefill data
$valOwnerName = $formData['owner_name'] ?? ($formData['name'] ?? '');
$valNik = $formData['nik'] ?? '';
$valPhone = $formData['phone'] ?? '';
$valWhatsapp = $formData['whatsapp'] ?? ($formData['phone'] ?? '');
$valProfession = $formData['profession'] ?? '';
$valNpwp = $formData['npwp'] ?? '';

// Domicile
$valDomProv = $formData['domicile_province'] ?? ($formData['province'] ?? '');
$valDomCity = $formData['domicile_city'] ?? ($formData['city'] ?? '');
$valDomDist = $formData['domicile_district'] ?? ($formData['district'] ?? '');
$valDomVill = $formData['domicile_village'] ?? ($formData['village'] ?? '');
$valDomPostal = $formData['domicile_postal_code'] ?? ($formData['postal_code'] ?? '');
$valDomAddr = $formData['domicile_address'] ?? ($formData['address'] ?? '');

// Workplace
$valSameAsDom = isset($formData['workplace_same_as_domicile']) ? (int)$formData['workplace_same_as_domicile'] : (isset($formData['same_location_siapkerja']) ? (int)$formData['same_location_siapkerja'] : 1);
$valWorkProv = $formData['workplace_province'] ?? ($formData['province'] ?? $valDomProv);
$valWorkCity = $formData['workplace_city'] ?? ($formData['city'] ?? $valDomCity);
$valWorkDist = $formData['workplace_district'] ?? ($formData['district'] ?? $valDomDist);
$valWorkVill = $formData['workplace_village'] ?? ($formData['village'] ?? $valDomVill);
$valWorkPostal = $formData['workplace_postal_code'] ?? ($formData['postal_code'] ?? $valDomPostal);
$valWorkAddr = $formData['workplace_address'] ?? ($formData['address'] ?? $valDomAddr);
$valWorkDetail = $formData['workplace_detail'] ?? ($formData['address_detail'] ?? ($formData['address_notes'] ?? ''));

// Files
$valPermitDoc = $formData['permit_document'] ?? ($formData['doc_permission'] ?? '');
$valWorkPhoto = $formData['workplace_photo'] ?? ($formData['doc_location_photo'] ?? '');

// Social & Description
$valLinkedin = $formData['linkedin'] ?? '';
$valFacebook = $formData['facebook'] ?? '';
$valInstagram = $formData['instagram'] ?? '';
$valDescription = $formData['description'] ?? '';

// Consent
$valConsent = !empty($formData['user_consent']) || !empty($formData['consent_accepted']) || !empty($formData['consent_agreed']);

// Industry / Profession List
$professionOptions = [
    'Kuliner & Katering',
    'Perdagangan & Eceran',
    'Jasa Perorangan / Rumah Tangga',
    'Pertanian & Peternakan',
    'Teknologi & Kreatif',
    'Lainnya'
];

$gmapsApiKey = getenv('GOOGLE_MAPS_API_KEY') ?: ($_ENV['GOOGLE_MAPS_API_KEY'] ?? '');
?>

<form method="post" action="<?php echo pki_e($formAction); ?>" id="<?php echo pki_e($formId); ?>" enctype="multipart/form-data" novalidate style="display:flex; flex-direction:column; height:100%; max-height:100%;">
    <?php if ($formMode === 'register'): ?>
        <input type="hidden" name="register_action" value="simulate_employer_session">
    <?php else: ?>
        <input type="hidden" name="submit_profile" value="1">
    <?php endif; ?>

    <!-- MODAL HEADER (Jika mode modal) -->
    <div class="modal-header" style="padding:18px 24px 14px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:flex-start; background:#ffffff;">
        <div>
            <div class="modal-title" style="font-size:16px; font-weight:800; color:#0f172a; letter-spacing:0.2px;">FORM PROFIL PEMBERI KERJA INDIVIDU</div>
            <div class="modal-subtitle" style="font-size:12.5px; color:#64748b; margin-top:3px;">Lengkapi biodata individu untuk pengajuan verifikasi Hak Akses Pemberi Kerja Individu.</div>
        </div>
        <?php if ($isModal): ?>
            <button type="button" class="ghost-btn" data-close-modal="modal-employer-profile" style="font-size:18px; padding:4px 10px; line-height:1; border:none; color:#64748b; cursor:pointer;" aria-label="Tutup">×</button>
        <?php endif; ?>
    </div>

    <!-- MODAL BODY -->
    <div class="modal-body" style="padding:24px; overflow-y:auto; flex:1;">

        <!-- ℹ MODE DEMO BANNER -->
        <div class="pki-demo-banner">
            <div class="pki-demo-banner-title">
                <i class="fa-solid fa-circle-info"></i> MODE DEMO
            </div>
            <div class="pki-demo-banner-text">
                Pada implementasi produksi, data identitas dan domisili akan terisi otomatis dari SIAPKerja. Pada demo ini, data dapat diedit untuk kebutuhan pengujian.
            </div>
        </div>

        <!-- ───────────────────────────────────────────────────────────
             1. IDENTITAS PERORANGAN
             ─────────────────────────────────────────────────────────── -->
        <div class="pki-form-section">
            <div class="pki-section-title">1. IDENTITAS PERORANGAN</div>
            <div class="pki-section-desc">Lengkapi informasi utama Pemberi Kerja Individu.</div>

            <div class="pki-field-grid">
                <!-- Nama Pemberi Kerja -->
                <div class="pki-field" data-field-key="owner_name">
                    <label for="pki_owner_name">Nama Pemberi Kerja <span class="req">*</span></label>
                    <input type="text" name="owner_name" id="pki_owner_name" value="<?php echo pki_e($valOwnerName); ?>" placeholder="Masukkan nama pemberi kerja">
                    <div class="pki-helper-text">Data nama pada produksi berasal dari akun SIAPKerja.</div>
                    <div class="pki-error-text">Nama Pemberi Kerja wajib diisi.</div>
                </div>

                <!-- NIK -->
                <div class="pki-field" data-field-key="nik">
                    <label for="pki_nik">NIK <span class="req">*</span></label>
                    <input type="text" name="nik" id="pki_nik" value="<?php echo pki_e($valNik); ?>" maxlength="16" placeholder="Masukkan 16 digit NIK">
                    <div class="pki-helper-text">NIK terdiri dari 16 digit.</div>
                    <div class="pki-error-text">NIK terdiri dari 16 digit.</div>
                </div>

                <!-- Nomor Telepon Aktif -->
                <div class="pki-field" data-field-key="phone">
                    <label for="pki_phone">Nomor Telepon Aktif <span class="req">*</span></label>
                    <input type="text" name="phone" id="pki_phone" value="<?php echo pki_e($valPhone); ?>" placeholder="08xxxxxxxxxx">
                    <div class="pki-helper-text">Gunakan nomor telepon aktif.</div>
                    <div class="pki-error-text">Gunakan nomor telepon aktif.</div>
                </div>

                <!-- Nomor WhatsApp -->
                <div class="pki-field" data-field-key="whatsapp">
                    <label for="pki_whatsapp">Nomor WhatsApp <span class="req">*</span></label>
                    <input type="text" name="whatsapp" id="pki_whatsapp" value="<?php echo pki_e($valWhatsapp); ?>" placeholder="08xxxxxxxxxx">
                    <div class="pki-helper-text">Gunakan nomor WhatsApp aktif.</div>
                    <div class="pki-error-text">Gunakan nomor WhatsApp aktif.</div>
                </div>

                <!-- Industri / Sektor -->
                <div class="pki-field" data-field-key="profession">
                    <label for="pki_profession">
                        <span>Industri / Sektor</span> <span class="req">*</span>
                        <span class="pki-tooltip-wrap">
                            <button type="button" class="pki-tooltip-btn" aria-label="Bantuan Industri/Sektor">
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <span class="pki-tooltip-popover">Pilih industri atau sektor yang paling sesuai dengan kegiatan utama usaha Anda.</span>
                        </span>
                    </label>
                    <select name="profession" id="pki_profession">
                        <option value="">Pilih industri / sektor</option>
                        <?php foreach ($professionOptions as $opt): ?>
                            <option value="<?php echo pki_e($opt); ?>" <?php echo $valProfession === $opt ? 'selected' : ''; ?>>
                                <?php echo pki_e($opt); ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if ($valProfession && !in_array($valProfession, $professionOptions)): ?>
                            <option value="<?php echo pki_e($valProfession); ?>" selected><?php echo pki_e($valProfession); ?></option>
                        <?php endif; ?>
                    </select>
                    <div class="pki-helper-text">Pilih industri atau sektor yang paling sesuai dengan kegiatan utama usaha.</div>
                    <div class="pki-error-text">Pilih industri atau sektor yang paling sesuai dengan kegiatan utama usaha.</div>
                </div>

                <!-- NPWP -->
                <div class="pki-field" data-field-key="npwp">
                    <label for="pki_npwp">NPWP <span class="req">*</span></label>
                    <input type="text" name="npwp" id="pki_npwp" value="<?php echo pki_e($valNpwp); ?>" placeholder="Masukkan NPWP">
                    <div class="pki-helper-text">NPWP 15 atau 16 digit.</div>
                    <div class="pki-error-text">NPWP 15 atau 16 digit.</div>
                </div>
            </div>
        </div>

        <hr class="pki-section-divider">

        <!-- ───────────────────────────────────────────────────────────
             2. ALAMAT DOMISILI PEMBERI KERJA
             ─────────────────────────────────────────────────────────── -->
        <div class="pki-form-section">
            <div class="pki-section-title">2. ALAMAT DOMISILI PEMBERI KERJA</div>
            <div class="pki-section-desc">Data domisili pada production berasal dari akun SIAPKerja.</div>

            <!-- Hidden Location Fields (Domicile) -->
            <input type="hidden" name="domicile_province" id="pki_dom_prov" value="<?php echo pki_e($valDomProv); ?>">
            <input type="hidden" name="domicile_city" id="pki_dom_city" value="<?php echo pki_e($valDomCity); ?>">
            <input type="hidden" name="domicile_district" id="pki_dom_dist" value="<?php echo pki_e($valDomDist); ?>">
            <input type="hidden" name="domicile_village" id="pki_dom_vill" value="<?php echo pki_e($valDomVill); ?>">
            <input type="hidden" name="domicile_city_id" id="pki_dom_city_id" value="<?php echo pki_e($valDomCity); ?>">

            <!-- Lokasi Domisili (Hierarchical) & Kode Pos Grid -->
            <div class="pki-field-grid">
                <!-- Lokasi Domisili -->
                <div class="pki-field" data-field-key="domicile_location">
                    <label for="pki_dom_loc_input">Lokasi Domisili <span class="req">*</span></label>
                    <div id="pki_dom_loc_input" class="hierarchical-loc-field" tabindex="0" role="combobox" aria-haspopup="listbox">
                        <span id="pki_dom_loc_display" class="<?php echo !empty($valDomVill) ? '' : 'placeholder'; ?>">
                            <?php
                            if (!empty($valDomVill) && !empty($valDomDist) && !empty($valDomCity) && !empty($valDomProv)) {
                                echo pki_e("{$valDomVill}, {$valDomDist}, {$valDomCity}, {$valDomProv}");
                            } else {
                                echo 'Pilih lokasi domisili';
                            }
                            ?>
                        </span>
                        <i class="fa-solid fa-chevron-down" style="color:#64748b; font-size:12px;"></i>
                    </div>

                    <!-- Dropdown Popup -->
                    <div id="pki_dom_loc_dropdown" class="loc-dropdown-popup" style="display:none;">
                        <div id="pki_dom_loc_breadcrumbs" class="loc-breadcrumbs">
                            <span class="crumb-btn active" data-level="1">Pilih Provinsi</span>
                        </div>
                        <div id="pki_dom_loc_options" class="loc-options-list"></div>
                    </div>

                    <div class="pki-helper-text">Wilayah administratif sampai Kelurahan/Desa.</div>
                    <div class="pki-error-text">Lokasi domisili wajib dipilih sampai Kelurahan/Desa.</div>
                </div>

                <!-- Kode Pos Domisili -->
                <div class="pki-field" data-field-key="domicile_postal_code">
                    <label for="pki_dom_postal_code">Kode Pos <span class="req">*</span></label>
                    <select name="domicile_postal_code" id="pki_dom_postal_code" <?php echo empty($valDomPostal) ? 'disabled' : ''; ?>>
                        <option value="">Pilih Kode Pos</option>
                        <?php if (!empty($valDomPostal)): ?>
                            <option value="<?php echo pki_e($valDomPostal); ?>" selected><?php echo pki_e($valDomPostal); ?></option>
                        <?php endif; ?>
                    </select>
                    <div class="pki-helper-text">Pilihan kode pos mengikuti lokasi yang dipilih.</div>
                    <div class="pki-error-text">Kode Pos wajib dipilih.</div>
                </div>
            </div>

            <!-- Alamat Lengkap Domisili -->
            <div class="pki-field" data-field-key="domicile_address">
                <label for="pki_dom_address">Alamat Lengkap Domisili <span class="req">*</span></label>
                <input type="text" name="domicile_address" id="pki_dom_address" value="<?php echo pki_e($valDomAddr); ?>" placeholder="Jl. Ir. H. Juanda No. 120, RT 03/RW 01">
                <div class="pki-helper-text">Tuliskan nama jalan, nomor bangunan, RT/RW, dan alamat lengkap domisili.</div>
                <div class="pki-error-text">Alamat Lengkap Domisili wajib diisi.</div>
            </div>
        </div>

        <hr class="pki-section-divider">

        <!-- ───────────────────────────────────────────────────────────
             3. TEMPAT USAHA / KEGIATAN PEMBERI KERJA
             ─────────────────────────────────────────────────────────── -->
        <div class="pki-form-section">
            <div class="pki-section-title">3. TEMPAT USAHA / KEGIATAN PEMBERI KERJA</div>

            <!-- Checkbox Same As Domicile -->
            <div style="margin-bottom:16px;">
                <label class="pki-custom-checkbox-label" for="pki_same_as_domicile" style="display:inline-flex; align-items:center; gap:8px; cursor:pointer; font-size:13.5px; font-weight:600; color:#1e293b;">
                    <input type="checkbox" name="workplace_same_as_domicile" id="pki_same_as_domicile" value="1" <?php echo !empty($valSameAsDom) ? 'checked' : ''; ?> style="width:18px; height:18px; cursor:pointer; accent-color:#0284c7;">
                    <span>Alamat tempat usaha/kegiatan sama dengan alamat domisili di SIAPKerja</span>
                </label>
            </div>

            <!-- Hidden Location Fields (Workplace) -->
            <input type="hidden" name="workplace_province" id="pki_work_prov" value="<?php echo pki_e($valWorkProv); ?>">
            <input type="hidden" name="workplace_city" id="pki_work_city" value="<?php echo pki_e($valWorkCity); ?>">
            <input type="hidden" name="workplace_district" id="pki_work_dist" value="<?php echo pki_e($valWorkDist); ?>">
            <input type="hidden" name="workplace_village" id="pki_work_vill" value="<?php echo pki_e($valWorkVill); ?>">

            <!-- Compatibility fields mirroring for existing backend/admin tables -->
            <input type="hidden" name="province" id="pki_mirror_prov" value="<?php echo pki_e($valWorkProv); ?>">
            <input type="hidden" name="city" id="pki_mirror_city" value="<?php echo pki_e($valWorkCity); ?>">
            <input type="hidden" name="district" id="pki_mirror_dist" value="<?php echo pki_e($valWorkDist); ?>">
            <input type="hidden" name="village" id="pki_mirror_vill" value="<?php echo pki_e($valWorkVill); ?>">
            <input type="hidden" name="postal_code" id="pki_mirror_postal" value="<?php echo pki_e($valWorkPostal); ?>">
            <input type="hidden" name="address" id="pki_mirror_address" value="<?php echo pki_e($valWorkAddr); ?>">
            <input type="hidden" name="address_detail" id="pki_mirror_detail" value="<?php echo pki_e($valWorkDetail); ?>">
            <input type="hidden" name="same_location_siapkerja" id="pki_mirror_same_loc" value="<?php echo !empty($valSameAsDom) ? '1' : '0'; ?>">

            <div class="pki-field-grid">
                <!-- Lokasi Tempat Usaha / Kegiatan -->
                <div class="pki-field" data-field-key="workplace_location">
                    <label for="pki_work_loc_input">
                        <span>Lokasi Tempat Usaha / Kegiatan</span> <span class="req">*</span>
                        <span class="pki-tooltip-wrap">
                            <button type="button" class="pki-tooltip-btn" aria-label="Bantuan Lokasi Usaha">
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <span class="pki-tooltip-popover">Lokasi tempat Anda menjalankan usaha atau kegiatan sebagai Pemberi Kerja Individu. Lokasi ini dapat berbeda dengan alamat domisili.</span>
                        </span>
                    </label>
                    <div id="pki_work_loc_input" class="hierarchical-loc-field" tabindex="0" role="combobox" aria-haspopup="listbox">
                        <span id="pki_work_loc_display" class="<?php echo !empty($valWorkVill) ? '' : 'placeholder'; ?>">
                            <?php
                            if (!empty($valWorkVill) && !empty($valWorkDist) && !empty($valWorkCity) && !empty($valWorkProv)) {
                                echo pki_e("{$valWorkVill}, {$valWorkDist}, {$valWorkCity}, {$valWorkProv}");
                            } else {
                                echo 'Pilih lokasi tempat usaha / kegiatan';
                            }
                            ?>
                        </span>
                        <i id="pki_work_loc_caret" class="fa-solid fa-chevron-down" style="color:#64748b; font-size:12px;"></i>
                    </div>

                    <!-- Dropdown Popup -->
                    <div id="pki_work_loc_dropdown" class="loc-dropdown-popup" style="display:none;">
                        <div id="pki_work_loc_breadcrumbs" class="loc-breadcrumbs">
                            <span class="crumb-btn active" data-level="1">Pilih Provinsi</span>
                        </div>
                        <div id="pki_work_loc_options" class="loc-options-list"></div>
                    </div>

                    <div class="pki-helper-text">Pilih lokasi secara berjenjang sampai Kelurahan/Desa.</div>
                    <div class="pki-error-text">Lokasi tempat usaha/kegiatan wajib dipilih sampai Kelurahan/Desa.</div>
                </div>

                <!-- Kode Pos Tempat Usaha -->
                <div class="pki-field" data-field-key="workplace_postal_code">
                    <label for="pki_work_postal_code">Kode Pos <span class="req">*</span></label>
                    <select name="workplace_postal_code" id="pki_work_postal_code" <?php echo empty($valWorkPostal) ? 'disabled' : ''; ?>>
                        <option value="">Pilih kode pos</option>
                        <?php if (!empty($valWorkPostal)): ?>
                            <option value="<?php echo pki_e($valWorkPostal); ?>" selected><?php echo pki_e($valWorkPostal); ?></option>
                        <?php endif; ?>
                    </select>
                    <div class="pki-helper-text">Pilihan kode pos mengikuti lokasi yang dipilih.</div>
                    <div class="pki-error-text">Kode Pos wajib dipilih.</div>
                </div>
            </div>

            <!-- Alamat Lengkap Tempat Usaha -->
            <div class="pki-field" data-field-key="workplace_address">
                <label for="pki_work_address">Alamat Lengkap Tempat Usaha <span class="req">*</span></label>
                <input type="text" name="workplace_address" id="pki_work_address" value="<?php echo pki_e($valWorkAddr); ?>" placeholder="Tuliskan nama jalan, nomor bangunan, RT/RW tempat usaha...">
                <div class="pki-helper-text">Tuliskan alamat lengkap tempat usaha/kegiatan.</div>
                <div class="pki-error-text">Alamat lengkap tempat usaha/kegiatan wajib diisi.</div>
            </div>

            <!-- Detail Alamat / Patokan -->
            <div class="pki-field" data-field-key="workplace_detail">
                <label for="pki_work_detail">
                    <span>Detail Alamat / Patokan</span>
                    <span class="pki-tooltip-wrap">
                        <button type="button" class="pki-tooltip-btn" aria-label="Bantuan Detail Alamat">
                            <i class="fa-solid fa-circle-info"></i>
                        </button>
                        <span class="pki-tooltip-popover">Tambahkan informasi yang membantu mengenali lokasi, seperti nomor bangunan, blok, lantai, atau patokan terdekat.</span>
                    </span>
                </label>
                <input type="text" name="workplace_detail" id="pki_work_detail" value="<?php echo pki_e($valWorkDetail); ?>" placeholder="Contoh: Ruko lantai 2, sebelah Alfamart atau patokan terdekat">
                <div class="pki-helper-text">Opsional. Tambahkan informasi yang membantu mengenali lokasi.</div>
            </div>

            <!-- Peta Lokasi (Google Maps, read-only marker) -->
            <input type="hidden" name="latitude" id="pki_lat" value="<?php echo pki_e($formData['latitude'] ?? ''); ?>">
            <input type="hidden" name="longitude" id="pki_lng" value="<?php echo pki_e($formData['longitude'] ?? ''); ?>">

            <div class="pki-field" data-field-key="map_preview">
                <label class="pki-label">Peta Lokasi</label>
                <div class="pki-map-container" id="pkiMapContainer">
                    <!-- Tombol Open in Maps ↗ di dalam peta -->
                    <a id="pkiBtnOpenMap" href="#" target="_blank" rel="noopener noreferrer" class="pki-map-open-btn" style="display:none;">
                        Open in Maps <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:11px; margin-left:2px;"></i>
                    </a>

                    <!-- Placeholder Belum Lengkap -->
                    <div id="pkiMapPlaceholder" class="pki-map-placeholder">
                        <div style="font-size:32px; margin-bottom:8px; opacity:0.85;">🗺️</div>
                        <div style="font-size:14px; font-weight:700; color:#334155; margin-bottom:4px;">Pratinjau peta belum tersedia. Lengkapi lokasi dan alamat terlebih dahulu.</div>
                        <div style="font-size:12px; color:#64748b;">Peta muncul otomatis setelah lokasi dan alamat lengkap tersedia.</div>
                    </div>

                    <!-- Iframe Google Maps -->
                    <div id="pkiMapFrameWrap" style="width:100%; height:100%; display:none;">
                        <iframe id="pkiGmapsIframe" width="100%" height="100%" frameborder="0" style="border:0; width:100%; height:100%;" allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                    </div>
                </div>
                <div class="pki-helper-text">Peta muncul otomatis setelah lokasi dan alamat lengkap tersedia.</div>
            </div>
        </div>

        <hr class="pki-section-divider">

        <!-- ───────────────────────────────────────────────────────────
             4. FILE & BUKTI TEMPAT USAHA
             ─────────────────────────────────────────────────────────── -->
        <div class="pki-form-section">
            <div class="pki-section-title">4. FILE & BUKTI TEMPAT USAHA</div>
            <div class="pki-section-desc">Unggah file pendukung dan dokumentasi foto tempat usaha atau lokasi kegiatan.</div>

            <div class="pki-field-grid">
                <!-- File Pendukung -->
                <div class="pki-field" data-field-key="permit_document">
                    <label>
                        <span>File Pendukung</span> <span class="req">*</span>
                        <span class="pki-tooltip-wrap">
                            <button type="button" class="pki-tooltip-btn" aria-label="Bantuan File Pendukung">
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <span class="pki-tooltip-popover">Unggah file yang mendukung validitas identitas atau kegiatan usaha Pemberi Kerja Individu.</span>
                        </span>
                    </label>

                    <!-- Hidden file inputs -->
                    <input type="file" name="permit_document" id="pki_input_permit_doc" accept=".pdf" style="display:none;">
                    <input type="hidden" name="existing_permit_document" id="pki_existing_permit_doc" value="<?php echo pki_e($valPermitDoc); ?>">

                    <!-- Upload Box (Default) -->
                    <div class="pki-upload-box" id="pki_permit_upload_box" style="<?php echo !empty($valPermitDoc) ? 'display:none;' : ''; ?>">
                        <div class="pki-upload-box-left">
                            <i class="fa-solid fa-cloud-arrow-up pki-upload-cloud-icon"></i>
                            <span class="pki-upload-prompt-text">Klik untuk meng-upload berkas / file disini</span>
                        </div>
                        <button type="button" class="pki-upload-action-btn">Upload</button>
                    </div>

                    <!-- Uploaded File Card -->
                    <div class="pki-upload-file-card" id="pki_permit_file_card" style="<?php echo !empty($valPermitDoc) ? 'display:flex;' : 'display:none;'; ?>">
                        <div class="pki-upload-file-left">
                            <i class="fa-solid fa-file-pdf" style="color:#dc2626; font-size:20px;"></i>
                            <div class="pki-file-details">
                                <span class="pki-file-name" id="pki_permit_name_display"><?php echo pki_e(basename($valPermitDoc ?: 'file-pendukung.pdf')); ?></span>
                                <span class="pki-file-size" id="pki_permit_size_display">Dokumen PDF Terlampir</span>
                            </div>
                        </div>
                        <div class="pki-upload-file-actions">
                            <button type="button" class="pki-file-btn-change" id="pki_btn_change_permit">Ganti</button>
                            <button type="button" class="pki-file-btn-delete" id="pki_btn_delete_permit" title="Hapus"><i class="fa-solid fa-trash-can"></i></button>
                        </div>
                    </div>

                    <div class="pki-helper-text">Format pdf • ukuran maks 15MB</div>
                    <div class="pki-error-text">File Pendukung wajib diunggah minimal 1 file (format PDF maks 15MB).</div>
                </div>

                <!-- Foto Bukti Tempat Usaha / Lokasi -->
                <div class="pki-field" data-field-key="workplace_photo">
                    <label>
                        <span>Foto Bukti Tempat Usaha / Lokasi</span> <span class="req">*</span>
                        <span class="pki-tooltip-wrap">
                            <button type="button" class="pki-tooltip-btn" aria-label="Bantuan Foto Bukti">
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <span class="pki-tooltip-popover">Unggah foto bagian depan rumah, tempat usaha, atau lokasi kegiatan yang sesuai dengan alamat tempat usaha/kegiatan yang dicantumkan pada profil.</span>
                        </span>
                    </label>

                    <!-- Hidden file inputs -->
                    <input type="file" name="workplace_photo" id="pki_input_workplace_photo" accept=".jpg,.jpeg,.png,.webp" style="display:none;">
                    <input type="hidden" name="existing_workplace_photo" id="pki_existing_workplace_photo" value="<?php echo pki_e($valWorkPhoto); ?>">

                    <!-- Upload Box (Default) -->
                    <div class="pki-upload-box" id="pki_photo_upload_box" style="<?php echo !empty($valWorkPhoto) ? 'display:none;' : ''; ?>">
                        <div class="pki-upload-box-left">
                            <i class="fa-solid fa-camera pki-upload-cloud-icon"></i>
                            <span class="pki-upload-prompt-text">Klik untuk meng-upload foto disini</span>
                        </div>
                        <button type="button" class="pki-upload-action-btn">Upload</button>
                    </div>

                    <!-- Uploaded File Card -->
                    <div class="pki-upload-file-card" id="pki_photo_file_card" style="<?php echo !empty($valWorkPhoto) ? 'display:flex;' : 'display:none;'; ?>">
                        <div class="pki-upload-file-left">
                            <i class="fa-solid fa-image" style="color:#0284c7; font-size:20px;"></i>
                            <div class="pki-file-details">
                                <span class="pki-file-name" id="pki_photo_name_display"><?php echo pki_e(basename($valWorkPhoto ?: 'foto-bukti-lokasi.jpg')); ?></span>
                                <span class="pki-file-size" id="pki_photo_size_display">Foto Terlampir</span>
                            </div>
                        </div>
                        <div class="pki-upload-file-actions">
                            <button type="button" class="pki-file-btn-change" id="pki_btn_change_photo">Ganti</button>
                            <button type="button" class="pki-file-btn-delete" id="pki_btn_delete_photo" title="Hapus"><i class="fa-solid fa-trash-can"></i></button>
                        </div>
                    </div>

                    <div class="pki-helper-text">Unggah minimal 1 foto tempat usaha/kegiatan sesuai alamat pada profil.</div>
                    <div class="pki-error-text">Foto Bukti Tempat Usaha / Lokasi wajib diunggah minimal 1 foto.</div>
                </div>
            </div>
        </div>

        <hr class="pki-section-divider">

        <!-- ───────────────────────────────────────────────────────────
             5. MEDIA SOSIAL & DESKRIPSI
             ─────────────────────────────────────────────────────────── -->
        <div class="pki-form-section">
            <div class="pki-section-title">5. MEDIA SOSIAL & DESKRIPSI</div>
            <div class="pki-section-desc">Informasi tambahan mengenai profil media sosial dan gambaran kegiatan usaha Anda.</div>

            <div class="pki-field-grid">
                <!-- LinkedIn -->
                <div class="pki-field" data-field-key="linkedin">
                    <label for="pki_linkedin">LinkedIn</label>
                    <input type="text" name="linkedin" id="pki_linkedin" value="<?php echo pki_e($valLinkedin); ?>" placeholder="username atau https://linkedin.com/in/...">
                    <div class="pki-helper-text">Opsional.</div>
                </div>

                <!-- Facebook -->
                <div class="pki-field" data-field-key="facebook">
                    <label for="pki_facebook">Facebook</label>
                    <input type="text" name="facebook" id="pki_facebook" value="<?php echo pki_e($valFacebook); ?>" placeholder="@username atau https://facebook.com/...">
                    <div class="pki-helper-text">Opsional.</div>
                </div>
            </div>

            <!-- Instagram -->
            <div class="pki-field" data-field-key="instagram">
                <label for="pki_instagram">Instagram</label>
                <input type="text" name="instagram" id="pki_instagram" value="<?php echo pki_e($valInstagram); ?>" placeholder="@username atau https://instagram.com/...">
                <div class="pki-helper-text">Opsional.</div>
            </div>

            <!-- Deskripsi Singkat Usaha / Rekrutmen -->
            <div class="pki-field" data-field-key="description">
                <label for="pki_description">
                    <span>Deskripsi Singkat Usaha / Rekrutmen</span>
                    <span class="pki-tooltip-wrap">
                        <button type="button" class="pki-tooltip-btn" aria-label="Bantuan Deskripsi Usaha">
                            <i class="fa-solid fa-circle-info"></i>
                        </button>
                        <span class="pki-tooltip-popover">Jelaskan secara singkat usaha atau kegiatan yang dijalankan serta gambaran kebutuhan rekrutmen yang dilakukan.</span>
                    </span>
                </label>
                <textarea name="description" id="pki_description" placeholder="Deskripsikan secara singkat profil usaha, produk/layanan, atau kebutuhan rekrutmen..."><?php echo pki_e($valDescription); ?></textarea>
                <div class="pki-helper-text">Deskripsikan secara singkat profil usaha, produk/layanan, atau kebutuhan rekrutmen.</div>
            </div>
        </div>

        <hr class="pki-section-divider">

        <!-- ───────────────────────────────────────────────────────────
             6. PERNYATAAN (Judul section TIDAK ada tanda bintang)
             ─────────────────────────────────────────────────────────── -->
        <div class="pki-form-section" style="margin-bottom:8px;">
            <div class="pki-section-title">6. PERNYATAAN</div>
            
            <div class="pki-field" data-field-key="user_consent" style="margin-bottom:0;">
                <div class="pki-consent-row">
                    <input type="checkbox" name="user_consent" id="pki_user_consent" value="1" <?php echo $valConsent ? 'checked' : ''; ?> class="pki-consent-checkbox">
                    <label for="pki_user_consent" class="pki-consent-text">
                        Saya menyatakan bahwa seluruh informasi yang saya berikan benar dan dapat dipertanggungjawabkan serta tidak digunakan untuk penipuan, lowongan palsu, atau tindakan yang melanggar hukum. <span class="req">*</span>
                    </label>
                </div>
                <div class="pki-error-text" style="margin-left:30px;">Pernyataan persetujuan wajib disetujui sebelum mengajukan profil.</div>
            </div>
        </div>

    </div>

    <!-- MODAL FOOTER -->
    <div class="pki-form-footer">
        <?php if ($isModal): ?>
            <button type="button" class="pki-btn-cancel" data-close-modal="modal-employer-profile">Batal</button>
        <?php else: ?>
            <a href="login.php" class="pki-btn-cancel" style="text-decoration:none;">Batal</a>
        <?php endif; ?>
        <button type="submit" class="pki-btn-submit" id="btnSubmitProfile">
            <i class="fa-solid fa-paper-plane"></i>
            Ajukan Profil
        </button>
    </div>
</form>

<script>
(function() {
    window.GOOGLE_MAPS_API_KEY = <?php echo json_encode($gmapsApiKey); ?>;

    const form = document.getElementById(<?php echo json_encode($formId); ?>);
    if (!form) return;

    // References to Elements
    const inputOwnerName = document.getElementById('pki_owner_name');
    const inputNik = document.getElementById('pki_nik');
    const inputPhone = document.getElementById('pki_phone');
    const inputWhatsapp = document.getElementById('pki_whatsapp');
    const selectProfession = document.getElementById('pki_profession');
    const inputNpwp = document.getElementById('pki_npwp');

    // Domicile Elements
    const domLocInput = document.getElementById('pki_dom_loc_input');
    const domLocDisplay = document.getElementById('pki_dom_loc_display');
    const domLocDropdown = document.getElementById('pki_dom_loc_dropdown');
    const domBreadcrumbs = document.getElementById('pki_dom_loc_breadcrumbs');
    const domOptions = document.getElementById('pki_dom_loc_options');
    const domProv = document.getElementById('pki_dom_prov');
    const domCity = document.getElementById('pki_dom_city');
    const domDist = document.getElementById('pki_dom_dist');
    const domVill = document.getElementById('pki_dom_vill');
    const domCityId = document.getElementById('pki_dom_city_id');
    const domPostal = document.getElementById('pki_dom_postal_code');
    const domAddress = document.getElementById('pki_dom_address');

    // Workplace Elements
    const cbSameAsDom = document.getElementById('pki_same_as_domicile');
    const workLocInput = document.getElementById('pki_work_loc_input');
    const workLocDisplay = document.getElementById('pki_work_loc_display');
    const workLocDropdown = document.getElementById('pki_work_loc_dropdown');
    const workBreadcrumbs = document.getElementById('pki_work_loc_breadcrumbs');
    const workOptions = document.getElementById('pki_work_loc_options');
    const workLocCaret = document.getElementById('pki_work_loc_caret');
    const workProv = document.getElementById('pki_work_prov');
    const workCity = document.getElementById('pki_work_city');
    const workDist = document.getElementById('pki_work_dist');
    const workVill = document.getElementById('pki_work_vill');
    const workPostal = document.getElementById('pki_work_postal_code');
    const workAddress = document.getElementById('pki_work_address');
    const workDetail = document.getElementById('pki_work_detail');

    // Mirroring Elements
    const mirrorProv = document.getElementById('pki_mirror_prov');
    const mirrorCity = document.getElementById('pki_mirror_city');
    const mirrorDist = document.getElementById('pki_mirror_dist');
    const mirrorVill = document.getElementById('pki_mirror_vill');
    const mirrorPostal = document.getElementById('pki_mirror_postal');
    const mirrorAddress = document.getElementById('pki_mirror_address');
    const mirrorDetail = document.getElementById('pki_mirror_detail');
    const mirrorSameLoc = document.getElementById('pki_mirror_same_loc');

    // Map Elements
    const mapPlaceholder = document.getElementById('pkiMapPlaceholder');
    const mapFrameWrap = document.getElementById('pkiMapFrameWrap');
    const gmapsIframe = document.getElementById('pkiGmapsIframe');
    const btnOpenMap = document.getElementById('pkiBtnOpenMap');

    // File Upload Elements
    const inputPermit = document.getElementById('pki_input_permit_doc');
    const existingPermit = document.getElementById('pki_existing_permit_doc');
    const permitUploadBox = document.getElementById('pki_permit_upload_box');
    const permitFileCard = document.getElementById('pki_permit_file_card');
    const permitNameDisplay = document.getElementById('pki_permit_name_display');
    const permitSizeDisplay = document.getElementById('pki_permit_size_display');
    const btnChangePermit = document.getElementById('pki_btn_change_permit');
    const btnDeletePermit = document.getElementById('pki_btn_delete_permit');

    const inputPhoto = document.getElementById('pki_input_workplace_photo');
    const existingPhoto = document.getElementById('pki_existing_workplace_photo');
    const photoUploadBox = document.getElementById('pki_photo_upload_box');
    const photoFileCard = document.getElementById('pki_photo_file_card');
    const photoNameDisplay = document.getElementById('pki_photo_name_display');
    const photoSizeDisplay = document.getElementById('pki_photo_size_display');
    const btnChangePhoto = document.getElementById('pki_btn_change_photo');
    const btnDeletePhoto = document.getElementById('pki_btn_delete_photo');

    // Consent
    const cbConsent = document.getElementById('pki_user_consent');

    // ── NIK Formatting (digits only, max 16) ──
    if (inputNik) {
        inputNik.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0, 16);
            if (this.value.length === 16) {
                clearFieldError('nik');
            }
        });
    }

    // ── NPWP Formatting (clean on input) ──
    if (inputNpwp) {
        inputNpwp.addEventListener('input', function() {
            const raw = this.value.replace(/\D/g, '');
            if (raw.length === 15 || raw.length === 16) {
                clearFieldError('npwp');
            }
        });
    }

    // ── Generic Clear Error on User Interaction ──
    ['owner_name', 'phone', 'whatsapp', 'profession', 'domicile_address', 'workplace_address'].forEach(key => {
        const el = form.querySelector(`[name="${key}"]`);
        if (el) {
            el.addEventListener('input', () => clearFieldError(key));
            el.addEventListener('change', () => clearFieldError(key));
        }
    });

    if (cbConsent) {
        cbConsent.addEventListener('change', function() {
            if (this.checked) clearFieldError('user_consent');
        });
    }

    // ── Tooltip Popover Positioning & Edge Avoidance ──
    document.querySelectorAll('.pki-tooltip-wrap').forEach(wrap => {
        const btn = wrap.querySelector('.pki-tooltip-btn');
        const pop = wrap.querySelector('.pki-tooltip-popover');
        if (!btn || !pop) return;

        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            pop.classList.toggle('is-open');
        });

        // Close on click outside
        document.addEventListener('click', (e) => {
            if (!wrap.contains(e.target)) {
                pop.classList.remove('is-open');
            }
        });
    });

    // ── Reusable Hierarchical Location Selector ──
    function setupHierarchicalPicker(cfg) {
        let selProv = cfg.getProv();
        let selCity = cfg.getCity();
        let selDist = cfg.getDist();
        let selVill = cfg.getVill();
        let level = 1;

        function getDataset() {
            if (typeof PKI_FORM_LOCATIONS !== 'undefined') return PKI_FORM_LOCATIONS;
            if (typeof ID_LOCATIONS !== 'undefined') return ID_LOCATIONS;
            return {};
        }

        function renderBreadcrumbs() {
            cfg.breadcrumbs.innerHTML = '';
            const b1 = document.createElement('span');
            b1.className = 'crumb-btn' + (level === 1 ? ' active' : '');
            b1.textContent = selProv || 'Pilih Provinsi';
            b1.onclick = (e) => { e.stopPropagation(); level = 1; renderOptions(); };
            cfg.breadcrumbs.appendChild(b1);

            if (selProv) {
                cfg.breadcrumbs.appendChild(document.createTextNode(' / '));
                const b2 = document.createElement('span');
                b2.className = 'crumb-btn' + (level === 2 ? ' active' : '');
                b2.textContent = selCity || 'Pilih Kota/Kab';
                b2.onclick = (e) => { e.stopPropagation(); level = 2; renderOptions(); };
                cfg.breadcrumbs.appendChild(b2);
            }

            if (selCity) {
                cfg.breadcrumbs.appendChild(document.createTextNode(' / '));
                const b3 = document.createElement('span');
                b3.className = 'crumb-btn' + (level === 3 ? ' active' : '');
                b3.textContent = selDist || 'Pilih Kecamatan';
                b3.onclick = (e) => { e.stopPropagation(); level = 3; renderOptions(); };
                cfg.breadcrumbs.appendChild(b3);
            }

            if (selDist) {
                cfg.breadcrumbs.appendChild(document.createTextNode(' / '));
                const b4 = document.createElement('span');
                b4.className = 'crumb-btn' + (level === 4 ? ' active' : '');
                b4.textContent = selVill || 'Pilih Kelurahan/Desa';
                b4.onclick = (e) => { e.stopPropagation(); level = 4; renderOptions(); };
                cfg.breadcrumbs.appendChild(b4);
            }
        }

        function renderOptions() {
            renderBreadcrumbs();
            cfg.optionsList.innerHTML = '';
            const data = getDataset();

            if (level === 1) {
                const provs = Object.keys(data);
                if (provs.length === 0) {
                    cfg.optionsList.innerHTML = '<div style="padding:10px 16px; color:#94a3b8; font-size:12.5px;">Data wilayah belum dimuat.</div>';
                    return;
                }
                provs.forEach(p => {
                    const row = document.createElement('div');
                    row.className = 'loc-opt-row' + (selProv === p ? ' selected' : '');
                    row.textContent = p;
                    row.onclick = (e) => {
                        e.stopPropagation();
                        selProv = p;
                        selCity = '';
                        selDist = '';
                        selVill = '';
                        level = 2;
                        renderOptions();
                    };
                    cfg.optionsList.appendChild(row);
                });
            } else if (level === 2) {
                const cities = Object.keys(data[selProv] || {});
                cities.forEach(c => {
                    const row = document.createElement('div');
                    row.className = 'loc-opt-row' + (selCity === c ? ' selected' : '');
                    row.textContent = c;
                    row.onclick = (e) => {
                        e.stopPropagation();
                        selCity = c;
                        selDist = '';
                        selVill = '';
                        level = 3;
                        renderOptions();
                    };
                    cfg.optionsList.appendChild(row);
                });
            } else if (level === 3) {
                const dists = Object.keys(data[selProv]?.[selCity] || {});
                dists.forEach(d => {
                    const row = document.createElement('div');
                    row.className = 'loc-opt-row' + (selDist === d ? ' selected' : '');
                    row.textContent = d;
                    row.onclick = (e) => {
                        e.stopPropagation();
                        selDist = d;
                        selVill = '';
                        level = 4;
                        renderOptions();
                    };
                    cfg.optionsList.appendChild(row);
                });
            } else if (level === 4) {
                const rawVills = data[selProv]?.[selCity]?.[selDist] || [];
                const vills = Array.isArray(rawVills) ? rawVills : Object.keys(rawVills);
                vills.forEach(v => {
                    const row = document.createElement('div');
                    row.className = 'loc-opt-row' + (selVill === v ? ' selected' : '');
                    row.textContent = v;
                    row.onclick = (e) => {
                        e.stopPropagation();
                        selVill = v;
                        cfg.onSelected(selProv, selCity, selDist, selVill);
                        closeDropdown();
                    };
                    cfg.optionsList.appendChild(row);
                });
            }
        }

        function openDropdown() {
            if (cfg.isDisabled && cfg.isDisabled()) return;
            selProv = cfg.getProv();
            selCity = cfg.getCity();
            selDist = cfg.getDist();
            selVill = cfg.getVill();

            if (selProv && selCity && selDist && selVill) {
                level = 4;
            } else if (selProv && selCity && selDist) {
                level = 3;
            } else if (selProv && selCity) {
                level = 2;
            } else {
                level = 1;
            }

            // Close other popups
            document.querySelectorAll('.loc-dropdown-popup').forEach(d => d.style.display = 'none');
            cfg.dropdown.style.display = 'block';
            renderOptions();
        }

        function closeDropdown() {
            cfg.dropdown.style.display = 'none';
        }

        cfg.inputBox.addEventListener('click', (e) => {
            e.stopPropagation();
            if (cfg.dropdown.style.display === 'block') {
                closeDropdown();
            } else {
                openDropdown();
            }
        });

        document.addEventListener('click', (e) => {
            if (!cfg.inputBox.contains(e.target) && !cfg.dropdown.contains(e.target)) {
                closeDropdown();
            }
        });

        return { open: openDropdown, close: closeDropdown };
    }

    // Postal Code Resolver
    function resolvePostalCode(prov, city, dist, vill) {
        if (typeof ID_LOCATIONS !== 'undefined' && ID_LOCATIONS[prov]?.[city]?.[dist]?.[vill]) {
            return ID_LOCATIONS[prov][city][dist][vill];
        }
        if (city && city.toLowerCase().includes('bandung')) return '40135';
        if (city && city.toLowerCase().includes('bekasi')) return '17148';
        if (city && city.toLowerCase().includes('jakarta')) return '12190';
        return '40135';
    }

    // ── Setup Domicile Hierarchical Picker ──
    setupHierarchicalPicker({
        inputBox: domLocInput,
        display: domLocDisplay,
        dropdown: domLocDropdown,
        breadcrumbs: domBreadcrumbs,
        optionsList: domOptions,
        getProv: () => domProv.value,
        getCity: () => domCity.value,
        getDist: () => domDist.value,
        getVill: () => domVill.value,
        isDisabled: () => false,
        onSelected: (p, c, d, v) => {
            domProv.value = p;
            domCity.value = c;
            domDist.value = d;
            domVill.value = v;
            domCityId.value = c;

            domLocDisplay.textContent = `${v}, ${d}, ${c}, ${p}`;
            domLocDisplay.classList.remove('placeholder');
            clearFieldError('domicile_location');

            // Populate Domicile Postal Code
            const postVal = resolvePostalCode(p, c, d, v);
            domPostal.innerHTML = `<option value="${postVal}" selected>${postVal}</option>`;
            domPostal.disabled = false;
            clearFieldError('domicile_postal_code');

            // If "Same as domicile" is checked, copy to workplace
            if (cbSameAsDom.checked) {
                syncDomicileToWorkplace();
            }
        }
    });

    // ── Setup Workplace Hierarchical Picker ──
    setupHierarchicalPicker({
        inputBox: workLocInput,
        display: workLocDisplay,
        dropdown: workLocDropdown,
        breadcrumbs: workBreadcrumbs,
        optionsList: workOptions,
        getProv: () => workProv.value,
        getCity: () => workCity.value,
        getDist: () => workDist.value,
        getVill: () => workVill.value,
        isDisabled: () => cbSameAsDom.checked,
        onSelected: (p, c, d, v) => {
            workProv.value = p;
            workCity.value = c;
            workDist.value = d;
            workVill.value = v;

            workLocDisplay.textContent = `${v}, ${d}, ${c}, ${p}`;
            workLocDisplay.classList.remove('placeholder');
            clearFieldError('workplace_location');

            const postVal = resolvePostalCode(p, c, d, v);
            workPostal.innerHTML = `<option value="${postVal}" selected>${postVal}</option>`;
            workPostal.disabled = false;
            clearFieldError('workplace_postal_code');

            syncToMirrors();
            updateGoogleMaps();
        }
    });

    // ── Sync Domicile To Workplace ──
    function syncDomicileToWorkplace() {
        workProv.value = domProv.value;
        workCity.value = domCity.value;
        workDist.value = domDist.value;
        workVill.value = domVill.value;
        workAddress.value = domAddress.value;

        if (domVill.value) {
            workLocDisplay.textContent = `${domVill.value}, ${domDist.value}, ${domCity.value}, ${domProv.value}`;
            workLocDisplay.classList.remove('placeholder');
        } else {
            workLocDisplay.textContent = 'Pilih lokasi tempat usaha / kegiatan';
            workLocDisplay.classList.add('placeholder');
        }

        if (domPostal.value) {
            workPostal.innerHTML = `<option value="${domPostal.value}" selected>${domPostal.value}</option>`;
            workPostal.disabled = true;
        } else {
            workPostal.innerHTML = '<option value="">Pilih kode pos</option>';
            workPostal.disabled = true;
        }

        syncToMirrors();
        updateGoogleMaps();
    }

    // ── Checkbox "Same as domicile" Toggle ──
    function applySameAsDomicileState() {
        const isSame = cbSameAsDom.checked;
        if (mirrorSameLoc) mirrorSameLoc.value = isSame ? '1' : '0';

        if (isSame) {
            syncDomicileToWorkplace();
            workLocInput.style.cursor = 'default';
            workLocInput.style.background = '#f8fafc';
            if (workLocCaret) workLocCaret.style.display = 'none';
            workAddress.readOnly = true;
            workAddress.style.background = '#f8fafc';
            workPostal.disabled = true;
            clearFieldError('workplace_location');
            clearFieldError('workplace_address');
            clearFieldError('workplace_postal_code');
        } else {
            workLocInput.style.cursor = 'pointer';
            workLocInput.style.background = '#ffffff';
            if (workLocCaret) workLocCaret.style.display = 'block';
            workAddress.readOnly = false;
            workAddress.style.background = '#ffffff';
            if (workVill.value) {
                workPostal.disabled = false;
            }
        }
    }

    if (cbSameAsDom) {
        cbSameAsDom.addEventListener('change', applySameAsDomicileState);
    }

    // Sync on typing domicile address when checkbox is checked
    if (domAddress) {
        domAddress.addEventListener('input', function() {
            if (cbSameAsDom.checked) {
                workAddress.value = this.value;
                syncToMirrors();
                updateGoogleMaps();
            }
        });
    }

    if (workAddress) {
        workAddress.addEventListener('input', function() {
            syncToMirrors();
            updateGoogleMaps();
        });
    }

    if (workDetail) {
        workDetail.addEventListener('input', syncToMirrors);
    }

    function syncToMirrors() {
        if (mirrorProv) mirrorProv.value = workProv.value;
        if (mirrorCity) mirrorCity.value = workCity.value;
        if (mirrorDist) mirrorDist.value = workDist.value;
        if (mirrorVill) mirrorVill.value = workVill.value;
        if (mirrorPostal) mirrorPostal.value = workPostal.value;
        if (mirrorAddress) mirrorAddress.value = workAddress.value;
        if (mirrorDetail) mirrorDetail.value = workDetail.value;
    }

    // ── Google Maps Integration (No Leaflet, No Hard-coded API Key) ──
    let mapDebounceTimer = null;

    function updateGoogleMaps() {
        clearTimeout(mapDebounceTimer);
        mapDebounceTimer = setTimeout(() => {
            const addr = workAddress.value.trim();
            const vill = workVill.value.trim();
            const dist = workDist.value.trim();
            const city = workCity.value.trim();
            const prov = workProv.value.trim();

            const isLocationComplete = Boolean(vill || city) && Boolean(addr && addr.length >= 3);

            if (!isLocationComplete) {
                mapPlaceholder.style.display = 'flex';
                mapFrameWrap.style.display = 'none';
                btnOpenMap.style.display = 'none';
                return;
            }

            const queryParts = [addr, vill, dist, city, prov, 'Indonesia'].filter(Boolean);
            const fullQuery = queryParts.join(', ');

            // Button Open in Maps ↗ (always opens on Google Maps in new tab)
            btnOpenMap.href = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(fullQuery)}`;
            btnOpenMap.style.display = 'inline-flex';

            // Google Maps Iframe Embed
            const apiKey = window.GOOGLE_MAPS_API_KEY;
            let embedUrl = '';
            if (apiKey && apiKey.length > 5) {
                embedUrl = `https://www.google.com/maps/embed/v1/place?key=${encodeURIComponent(apiKey)}&q=${encodeURIComponent(fullQuery)}`;
            } else {
                // Public Google Maps iframe embed
                embedUrl = `https://maps.google.com/maps?q=${encodeURIComponent(fullQuery)}&t=&z=15&ie=UTF8&iwloc=&output=embed`;
            }

            if (gmapsIframe.src !== embedUrl) {
                gmapsIframe.src = embedUrl;
            }

            mapPlaceholder.style.display = 'none';
            mapFrameWrap.style.display = 'block';
        }, 300);
    }

    // ── File Pendukung Upload Handler (PDF only, max 15MB) ──
    permitUploadBox.addEventListener('click', () => inputPermit.click());
    btnChangePermit.addEventListener('click', () => inputPermit.click());
    btnDeletePermit.addEventListener('click', () => {
        inputPermit.value = '';
        existingPermit.value = '';
        permitUploadBox.style.display = 'flex';
        permitFileCard.style.display = 'none';
    });

    inputPermit.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            const file = this.files[0];
            const ext = file.name.split('.').pop().toLowerCase();
            const sizeMb = file.size / (1024 * 1024);

            if (ext !== 'pdf') {
                alert('Format file pendukung harus berupa PDF.');
                this.value = '';
                return;
            }
            if (sizeMb > 15) {
                alert('Ukuran file pendukung maksimal 15MB.');
                this.value = '';
                return;
            }

            permitNameDisplay.textContent = file.name;
            permitSizeDisplay.textContent = `${sizeMb.toFixed(1)} MB • PDF`;
            permitUploadBox.style.display = 'none';
            permitFileCard.style.display = 'flex';
            clearFieldError('permit_document');
        }
    });

    // ── Foto Bukti Tempat Usaha Upload Handler (Images only) ──
    photoUploadBox.addEventListener('click', () => inputPhoto.click());
    btnChangePhoto.addEventListener('click', () => inputPhoto.click());
    btnDeletePhoto.addEventListener('click', () => {
        inputPhoto.value = '';
        existingPhoto.value = '';
        photoUploadBox.style.display = 'flex';
        photoFileCard.style.display = 'none';
    });

    inputPhoto.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            const file = this.files[0];
            const ext = file.name.split('.').pop().toLowerCase();
            const allowed = ['jpg', 'jpeg', 'png', 'webp'];

            if (!allowed.includes(ext)) {
                alert('Format foto bukti harus JPG, JPEG, PNG, atau WEBP.');
                this.value = '';
                return;
            }

            const sizeMb = file.size / (1024 * 1024);
            photoNameDisplay.textContent = file.name;
            photoSizeDisplay.textContent = `${sizeMb.toFixed(1)} MB • Foto Lokasi`;
            photoUploadBox.style.display = 'none';
            photoFileCard.style.display = 'flex';
            clearFieldError('workplace_photo');
        }
    });

    // ── Field Validation & Error States ──
    function setFieldError(fieldKey, message) {
        const wrap = form.querySelector(`.pki-field[data-field-key="${fieldKey}"]`);
        if (!wrap) return;
        wrap.classList.add('has-error');
        const errText = wrap.querySelector('.pki-error-text');
        if (errText && message) {
            errText.textContent = message;
        }
    }

    function clearFieldError(fieldKey) {
        const wrap = form.querySelector(`.pki-field[data-field-key="${fieldKey}"]`);
        if (!wrap) return;
        wrap.classList.remove('has-error');
    }

    function validatePkiForm() {
        let isValid = true;
        let firstErrorWrap = null;

        function check(key, condition, message) {
            if (!condition) {
                isValid = false;
                setFieldError(key, message);
                if (!firstErrorWrap) {
                    firstErrorWrap = form.querySelector(`.pki-field[data-field-key="${key}"]`);
                }
            } else {
                clearFieldError(key);
            }
        }

        // 1. Identitas Perorangan
        check('owner_name', inputOwnerName.value.trim().length > 0, 'Nama Pemberi Kerja wajib diisi.');
        check('nik', inputNik.value.trim().length === 16, 'NIK terdiri dari 16 digit.');
        check('phone', inputPhone.value.trim().length > 0, 'Gunakan nomor telepon aktif.');
        check('whatsapp', inputWhatsapp.value.trim().length > 0, 'Gunakan nomor WhatsApp aktif.');
        check('profession', selectProfession.value.trim().length > 0, 'Pilih industri atau sektor yang paling sesuai dengan kegiatan utama usaha.');
        
        const rawNpwp = inputNpwp.value.replace(/\D/g, '');
        check('npwp', rawNpwp.length === 15 || rawNpwp.length === 16, 'NPWP 15 atau 16 digit.');

        // 2. Alamat Domisili Pemberi Kerja
        check('domicile_location', Boolean(domVill.value.trim() && domCity.value.trim()), 'Lokasi domisili wajib dipilih sampai Kelurahan/Desa.');
        check('domicile_address', domAddress.value.trim().length > 0, 'Alamat Lengkap Domisili wajib diisi.');
        check('domicile_postal_code', domPostal.value.trim().length > 0, 'Kode Pos wajib dipilih.');

        // 3. Tempat Usaha / Kegiatan
        if (!cbSameAsDom.checked) {
            check('workplace_location', Boolean(workVill.value.trim() && workCity.value.trim()), 'Lokasi tempat usaha/kegiatan wajib dipilih sampai Kelurahan/Desa.');
            check('workplace_address', workAddress.value.trim().length > 0, 'Alamat lengkap tempat usaha/kegiatan wajib diisi.');
            check('workplace_postal_code', workPostal.value.trim().length > 0, 'Kode Pos wajib dipilih.');
        } else {
            clearFieldError('workplace_location');
            clearFieldError('workplace_address');
            clearFieldError('workplace_postal_code');
        }

        // 4. File & Bukti Tempat Usaha (Minimal 1 file pendukung, minimal 1 foto bukti)
        const hasPermit = Boolean((inputPermit.files && inputPermit.files.length > 0) || existingPermit.value.trim());
        check('permit_document', hasPermit, 'File Pendukung wajib diunggah minimal 1 file (format PDF maks 15MB).');

        const hasPhoto = Boolean((inputPhoto.files && inputPhoto.files.length > 0) || existingPhoto.value.trim());
        check('workplace_photo', hasPhoto, 'Unggah minimal 1 foto tempat usaha/kegiatan sesuai alamat pada profil.');

        // 6. Pernyataan (Wajib dicentang)
        check('user_consent', cbConsent.checked, 'Pernyataan persetujuan wajib disetujui sebelum mengajukan profil.');

        if (!isValid && firstErrorWrap) {
            firstErrorWrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
            const inputInside = firstErrorWrap.querySelector('input:not([type="hidden"]), select, textarea');
            if (inputInside) inputInside.focus();
        }

        return isValid;
    }

    // Intercept submit
    form.addEventListener('submit', function(e) {
        syncToMirrors();
        if (!validatePkiForm()) {
            e.preventDefault();
            return false;
        }
    });

    // Initial Execution
    applySameAsDomicileState();
    updateGoogleMaps();

    // Listen to modal open if inside modal
    const modalContainer = form.closest('.modal-backdrop');
    if (modalContainer) {
        modalContainer.addEventListener('modal:open', function() {
            updateGoogleMaps();
        });
        const observer = new MutationObserver(function() {
            if (modalContainer.classList.contains('open')) {
                updateGoogleMaps();
            }
        });
        observer.observe(modalContainer, { attributes: true, attributeFilter: ['class'] });
    }

})();
</script>
