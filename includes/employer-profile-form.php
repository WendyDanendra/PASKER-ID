<?php
/**
 * Shared Form Profil Pemberi Kerja Individu
 * Variables available:
 * - $isRevision (bool): true if editing/revision, false if initial registration
 * - $formData (array): user profile data to pre-fill (empty for initial registration)
 * - $cancelButtonHtml (string): custom HTML for Batal button
 */
$isRevision = $isRevision ?? false;
$formData = $formData ?? [];

// Helper values
$vOwnerName = e($formData['owner_name'] ?? '');
$vNik = e($formData['nik'] ?? '');
$vPhone = e($formData['phone'] ?? '');
$vWhatsapp = e($formData['whatsapp'] ?? '');
$vProfession = $formData['profession'] ?? '';
$vNpwp = e($formData['npwp'] ?? '');

$vSameLoc = !empty($formData['same_location_siapkerja']);
$vProvince = e($formData['province'] ?? '');
$vCity = e($formData['city'] ?? '');
$vDistrict = e($formData['district'] ?? '');
$vVillage = e($formData['village'] ?? '');
$vPostalCode = e($formData['postal_code'] ?? '');
$vAddress = e($formData['address'] ?? '');
$vAddressDetail = e($formData['address_detail'] ?? ($formData['address_notes'] ?? ''));

$vPermitDoc = $formData['permit_document'] ?? ($formData['doc_permission'] ?? '');
$vPhoto = $formData['workplace_photo'] ?? ($formData['doc_location_photo'] ?? '');

$vLinkedin = e($formData['linkedin'] ?? '');
$vFacebook = e($formData['facebook'] ?? '');
$vInstagram = e($formData['instagram'] ?? '');
$vDescription = e($formData['description'] ?? '');
$vConsent = !empty($formData['user_consent']);

// Location display text
$locDisplayText = 'Pilih lokasi tempat usaha / kegiatan';
$hasLocSelected = false;
if (!empty($vVillage) && !empty($vDistrict) && !empty($vCity) && !empty($vProvince)) {
    $locDisplayText = $vVillage . ', ' . $vDistrict . ', ' . $vCity . ', ' . $vProvince;
    $hasLocSelected = true;
}

$googleMapsApiKey = getenv('GOOGLE_MAPS_API_KEY') ?: ($_ENV['GOOGLE_MAPS_API_KEY'] ?? '');
?>
<link rel="stylesheet" href="assets/pki-form.css?v=20260926_3">
<style>
<?php
$pkiCssPath = __DIR__ . '/../assets/pki-form.css';
if (file_exists($pkiCssPath)) {
    echo file_get_contents($pkiCssPath);
}
?>
</style>

<!-- BANNER MODE DEMO -->
<div class="pki-demo-banner">
    <i class="fa-solid fa-circle-info"></i>
    <div>
        <div class="pki-demo-banner-title">MODE DEMO</div>
        <div class="pki-demo-banner-text">Pada implementasi produksi, data identitas akan terisi otomatis dari SIAPKerja. Pada demo ini, data dapat diisi manual untuk kebutuhan pengujian.</div>
    </div>
</div>

<!-- 1. IDENTITAS PERORANGAN -->
<div class="modal-section" style="margin-bottom:20px;">
    <div class="section-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:16px;">1. IDENTITAS PERORANGAN</div>
    
    <div class="pki-grid-2">
        <div class="pki-field">
            <label class="pki-field-label" id="label_owner_name" for="pki_owner_name">
                Nama Pemberi Kerja <span class="req">*</span>
            </label>
            <input type="text" name="owner_name" id="pki_owner_name" value="<?php echo $vOwnerName; ?>" placeholder="Masukkan nama lengkap">
            <div class="pki-field-helper" id="helper_owner_name">Data nama pada produksi berasal dari akun SIAPKerja.</div>
        </div>
        <div class="pki-field">
            <label class="pki-field-label" id="label_nik" for="pki_nik">
                NIK <span class="req">*</span>
            </label>
            <input type="text" name="nik" id="pki_nik" value="<?php echo $vNik; ?>" maxlength="16" placeholder="Masukkan 16 digit NIK">
            <div class="pki-field-helper" id="helper_nik">NIK terdiri dari 16 digit.</div>
        </div>
    </div>

    <div class="pki-grid-2">
        <div class="pki-field">
            <label class="pki-field-label" id="label_phone" for="pki_phone">
                Nomor Telepon Aktif <span class="req">*</span>
            </label>
            <input type="text" name="phone" id="pki_phone" value="<?php echo $vPhone; ?>" placeholder="08xxxxxxxxxx">
            <div class="pki-field-helper" id="helper_phone">Gunakan nomor telepon aktif.</div>
        </div>
        <div class="pki-field">
            <label class="pki-field-label" id="label_whatsapp" for="pki_whatsapp">
                Nomor WhatsApp <span class="req">*</span>
            </label>
            <input type="text" name="whatsapp" id="pki_whatsapp" value="<?php echo $vWhatsapp; ?>" placeholder="08xxxxxxxxxx">
            <div class="pki-field-helper" id="helper_whatsapp">Gunakan nomor WhatsApp aktif.</div>
        </div>
    </div>

    <div class="pki-grid-2">
        <div class="pki-field">
            <label class="pki-field-label" id="label_profession" for="pki_profession">
                Industri / Sektor <span class="req">*</span>
                <span class="pki-tooltip" tabindex="0" role="tooltip">
                    <i class="fa-solid fa-circle-info tooltip-icon"></i>
                    <span class="tooltip-popover">Pilih industri atau sektor yang paling sesuai dengan kegiatan utama usaha Anda.</span>
                </span>
            </label>
            <select name="profession" id="pki_profession" style="width:100%; height:42px; padding:0 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:13.5px; color:#0f172a; background:#fff;">
                <option value="">Pilih industri / sektor</option>
                <?php
                $professions = [
                    'Kuliner & Katering',
                    'Perdagangan & Eceran',
                    'Jasa Perorangan / Rumah Tangga',
                    'Pertanian & Peternakan',
                    'Teknologi & Kreatif',
                    'Lainnya'
                ];
                foreach ($professions as $prof) {
                    $selected = ($vProfession === $prof) ? 'selected' : '';
                    echo "<option value=\"" . e($prof) . "\" $selected>" . e($prof) . "</option>";
                }
                if ($vProfession && !in_array($vProfession, $professions)) {
                    echo "<option value=\"" . e($vProfession) . "\" selected>" . e($vProfession) . "</option>";
                }
                ?>
            </select>
            <div class="pki-field-helper" id="helper_profession">Pilih industri atau sektor yang paling sesuai dengan kegiatan utama usaha.</div>
        </div>
        <div class="pki-field">
            <label class="pki-field-label" id="label_npwp" for="pki_npwp">
                NPWP <span class="req">*</span>
            </label>
            <input type="text" name="npwp" id="pki_npwp" value="<?php echo $vNpwp; ?>" placeholder="Masukkan NPWP">
            <div class="pki-field-helper" id="helper_npwp">NPWP 15 atau 16 digit.</div>
        </div>
    </div>
</div>

<hr class="modal-section-hr">

<!-- 2. TEMPAT USAHA / KEGIATAN PEMBERI KERJA -->
<div class="modal-section" style="margin-bottom:20px;">
    <div class="section-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:14px;">2. TEMPAT USAHA / KEGIATAN PEMBERI KERJA</div>

    <div style="margin-bottom:16px;">
        <label style="font-size:13px; font-weight:600; color:#334155; display:inline-flex; align-items:center; gap:8px; cursor:pointer;">
            <input type="checkbox" name="same_location_siapkerja" id="pki_cbSameLocation" value="1" <?php echo $vSameLoc ? 'checked' : ''; ?>>
            <span>Gunakan alamat domisili di SIAPKerja sebagai lokasi tempat usaha/kegiatan</span>
        </label>
    </div>

    <!-- Hidden Location Fields -->
    <input type="hidden" name="province" id="pki_hiddenProvince" value="<?php echo $vProvince; ?>">
    <input type="hidden" name="city" id="pki_hiddenCity" value="<?php echo $vCity; ?>">
    <input type="hidden" name="district" id="pki_hiddenDistrict" value="<?php echo $vDistrict; ?>">
    <input type="hidden" name="village" id="pki_hiddenVillage" value="<?php echo $vVillage; ?>">
    <input type="hidden" name="domicile_city_id" id="pki_hiddenDomicileCityId" value="<?php echo $vCity; ?>">
    <input type="hidden" name="latitude" id="pki_inputLat" value="<?php echo e($formData['latitude'] ?? '-6.887844'); ?>">
    <input type="hidden" name="longitude" id="pki_inputLng" value="<?php echo e($formData['longitude'] ?? '107.613038'); ?>">

    <!-- Lokasi Tempat Usaha / Kegiatan -->
    <div class="pki-field" style="position:relative;">
        <label class="pki-field-label" id="label_location" for="pki_hierarchicalLocationInput">
            Lokasi Tempat Usaha / Kegiatan <span class="req">*</span>
            <span class="pki-tooltip" tabindex="0" role="tooltip">
                <i class="fa-solid fa-circle-info tooltip-icon"></i>
                <span class="tooltip-popover">Lokasi tempat Anda menjalankan usaha atau kegiatan sebagai Pemberi Kerja Individu.</span>
            </span>
        </label>
        <div id="pki_hierarchicalLocationInput" class="hierarchical-loc-field" tabindex="0">
            <span id="pki_locDisplayValue" class="<?php echo $hasLocSelected ? '' : 'placeholder'; ?>"><?php echo e($locDisplayText); ?></span>
            <i id="pki_locCaret" class="fa-solid fa-chevron-down" style="color:#64748b; font-size:12px;"></i>
        </div>
        <div id="pki_hierarchicalLocDropdown" class="loc-dropdown-popup" style="display:none;">
            <div id="pki_locBreadcrumbs" class="loc-breadcrumbs">
                <span class="crumb-btn active" data-level="1">Pilih Provinsi</span>
            </div>
            <div id="pki_locOptionsList" class="loc-options-list"></div>
        </div>
        <div class="pki-field-helper" id="helper_location">Pilih lokasi secara berjenjang sampai Kelurahan/Desa.</div>
    </div>

    <!-- Alamat Lengkap Tempat Usaha -->
    <div class="pki-field">
        <label class="pki-field-label" id="label_address" for="pki_inputAddress">
            Alamat Lengkap Tempat Usaha <span class="req">*</span>
        </label>
        <input type="text" name="address" id="pki_inputAddress" value="<?php echo $vAddress; ?>" placeholder="Masukkan nama jalan, nomor bangunan, RT/RW, dan alamat lengkap...">
        <div class="pki-field-helper" id="helper_address">Tuliskan alamat lengkap tempat usaha/kegiatan.</div>
    </div>

    <!-- Kode Pos -->
    <div class="pki-field">
        <label class="pki-field-label" id="label_postal_code" for="pki_selectPostalCode">
            Kode Pos <span class="req">*</span>
        </label>
        <select name="postal_code" id="pki_selectPostalCode" style="width:100%; height:42px; padding:0 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:13.5px; color:#0f172a; background:#fff;">
            <option value="">Pilih kode pos</option>
            <?php if (!empty($vPostalCode)): ?>
                <option value="<?php echo $vPostalCode; ?>" selected><?php echo $vPostalCode; ?></option>
            <?php endif; ?>
        </select>
        <div class="pki-field-helper" id="helper_postal_code">Pilihan kode pos mengikuti lokasi yang dipilih.</div>
    </div>

    <!-- Detail Alamat / Patokan (Opsional) -->
    <div class="pki-field">
        <label class="pki-field-label" id="label_address_detail" for="pki_inputAddressDetail">
            Detail Alamat / Patokan (Opsional)
            <span class="pki-tooltip" tabindex="0" role="tooltip">
                <i class="fa-solid fa-circle-info tooltip-icon"></i>
                <span class="tooltip-popover">Tambahkan informasi yang membantu mengenali lokasi, seperti nomor bangunan, blok, lantai, atau patokan terdekat.</span>
            </span>
        </label>
        <input type="text" name="address_detail" id="pki_inputAddressDetail" value="<?php echo $vAddressDetail; ?>" placeholder="Contoh: Ruko lantai 2, sebelah Kantor Kelurahan">
        <div class="pki-field-helper" id="helper_address_detail">Opsional. Tambahkan informasi yang membantu mengenali lokasi.</div>
    </div>

    <!-- Peta Lokasi (Google Maps) -->
    <div class="pki-field">
        <label class="pki-field-label" style="margin-bottom:8px;">Peta Lokasi</label>
        <div id="pki_mapContainer" class="pki-map-container">
            <div id="pki_mapPlaceholder" class="pki-map-placeholder">
                <div style="font-size:28px; margin-bottom:6px;">🗺</div>
                <div style="font-size:13.5px; font-weight:700; color:#334155; margin-bottom:4px;">Pratinjau peta belum tersedia</div>
                <div style="font-size:12px; color:#64748b; max-width:360px; line-height:1.4;">Lengkapi lokasi dan alamat terlebih dahulu.</div>
            </div>
            <div id="pki_googleMapWrapper" class="pki-map-wrapper" style="display:none; width:100%; height:100%;">
                <iframe id="pki_gmapIframe" width="100%" height="100%" style="border:0;" loading="lazy" allowfullscreen referrerpolicy="no-referrer-when-downgrade" src="about:blank"></iframe>
            </div>
        </div>
        <div class="pki-field-helper" id="helper_map" style="margin-top:8px;">Peta muncul otomatis setelah lokasi dan alamat lengkap tersedia.</div>
    </div>
</div>

<hr class="modal-section-hr">

<!-- 3. FILE & BUKTI TEMPAT USAHA -->
<div class="modal-section" style="margin-bottom:20px;">
    <div class="section-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:16px;">3. FILE & BUKTI TEMPAT USAHA</div>

    <div class="pki-grid-2">
        <!-- File Pendukung -->
        <div class="pki-field">
            <label class="pki-field-label" id="label_permit_document">
                File Pendukung <span class="req">*</span>
                <span class="pki-tooltip" tabindex="0" role="tooltip">
                    <i class="fa-solid fa-circle-info tooltip-icon"></i>
                    <span class="tooltip-popover">Unggah file yang mendukung validitas identitas atau kegiatan usaha Pemberi Kerja Individu.</span>
                </span>
            </label>
            <div class="pki-upload-box" id="pki_box_permit_document" onclick="pkiTriggerUpload('permit_document')">
                <span class="pki-upload-text <?php echo !empty($vPermitDoc) ? 'has-file' : ''; ?>" id="pki_text_permit_document">
                    <?php if (!empty($vPermitDoc)): ?>
                        <i class="fa-solid fa-file-pdf" style="color:#ef4444;"></i> <?php echo e(basename($vPermitDoc)); ?>
                    <?php else: ?>
                        Klik untuk meng-upload berkas / file
                    <?php endif; ?>
                </span>
                <div style="display:flex; align-items:center; gap:6px;">
                    <?php if (!empty($vPermitDoc)): ?>
                        <button type="button" class="pki-upload-clear-btn" title="Hapus berkas" onclick="pkiClearUpload(event, 'permit_document')">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="pki-upload-btn" id="pki_btn_permit_document"><?php echo !empty($vPermitDoc) ? 'Ganti' : 'Upload'; ?></button>
                </div>
            </div>
            <input type="file" name="permit_document" id="pki_input_permit_document" accept=".pdf" style="display:none;" onchange="pkiHandleFileChange(this, 'permit_document')">
            <input type="hidden" name="existing_permit_document" id="pki_existing_permit_document" value="<?php echo e($vPermitDoc); ?>">
            <div class="pki-field-helper" id="helper_permit_document">Format pdf • ukuran maks 15MB</div>
        </div>

        <!-- Foto Bukti Tempat Usaha / Lokasi -->
        <div class="pki-field">
            <label class="pki-field-label" id="label_workplace_photo">
                Foto Bukti Tempat Usaha / Lokasi <span class="req">*</span>
                <span class="pki-tooltip" tabindex="0" role="tooltip">
                    <i class="fa-solid fa-circle-info tooltip-icon"></i>
                    <span class="tooltip-popover">Unggah foto bagian depan rumah, tempat usaha, atau lokasi kegiatan yang sesuai dengan alamat tempat usaha/kegiatan yang dicantumkan pada profil.</span>
                </span>
            </label>
            <div class="pki-upload-box" id="pki_box_workplace_photo" onclick="pkiTriggerUpload('workplace_photo')">
                <span class="pki-upload-text <?php echo !empty($vPhoto) ? 'has-file' : ''; ?>" id="pki_text_workplace_photo">
                    <?php if (!empty($vPhoto)): ?>
                        <i class="fa-solid fa-image" style="color:#0284c7;"></i> <?php echo e(basename($vPhoto)); ?>
                    <?php else: ?>
                        Klik untuk meng-upload foto
                    <?php endif; ?>
                </span>
                <div style="display:flex; align-items:center; gap:6px;">
                    <?php if (!empty($vPhoto)): ?>
                        <button type="button" class="pki-upload-clear-btn" title="Hapus foto" onclick="pkiClearUpload(event, 'workplace_photo')">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="pki-upload-btn" id="pki_btn_workplace_photo"><?php echo !empty($vPhoto) ? 'Ganti' : 'Upload'; ?></button>
                </div>
            </div>
            <input type="file" name="workplace_photo" id="pki_input_workplace_photo" accept=".jpg,.jpeg,.png,.webp" style="display:none;" onchange="pkiHandleFileChange(this, 'workplace_photo')">
            <input type="hidden" name="existing_workplace_photo" id="pki_existing_workplace_photo" value="<?php echo e($vPhoto); ?>">
            <div class="pki-field-helper" id="helper_workplace_photo">Unggah minimal 1 foto tempat usaha/kegiatan sesuai alamat pada profil.</div>
        </div>
    </div>
</div>

<hr class="modal-section-hr">

<!-- 4. MEDIA SOSIAL & DESKRIPSI -->
<div class="modal-section" style="margin-bottom:20px;">
    <div class="section-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:16px;">4. MEDIA SOSIAL & DESKRIPSI</div>

    <div class="pki-grid-2">
        <div class="pki-field">
            <label class="pki-field-label" id="label_linkedin" for="pki_linkedin">LinkedIn</label>
            <input type="text" name="linkedin" id="pki_linkedin" value="<?php echo $vLinkedin; ?>" placeholder="username atau https://linkedin.com/in/...">
            <div class="pki-field-helper" id="helper_linkedin">Opsional.</div>
        </div>
        <div class="pki-field">
            <label class="pki-field-label" id="label_facebook" for="pki_facebook">Facebook</label>
            <input type="text" name="facebook" id="pki_facebook" value="<?php echo $vFacebook; ?>" placeholder="@username atau https://facebook.com/...">
            <div class="pki-field-helper" id="helper_facebook">Opsional.</div>
        </div>
    </div>

    <div class="pki-field">
        <label class="pki-field-label" id="label_instagram" for="pki_instagram">Instagram</label>
        <input type="text" name="instagram" id="pki_instagram" value="<?php echo $vInstagram; ?>" placeholder="@username atau https://instagram.com/...">
        <div class="pki-field-helper" id="helper_instagram">Opsional.</div>
    </div>

    <div class="pki-field">
        <label class="pki-field-label" id="label_description" for="pki_description">
            Deskripsi Singkat Usaha / Rekrutmen
            <span class="pki-tooltip" tabindex="0" role="tooltip">
                <i class="fa-solid fa-circle-info tooltip-icon"></i>
                <span class="tooltip-popover">Jelaskan secara singkat usaha atau kegiatan yang dijalankan serta gambaran kebutuhan rekrutmen yang dilakukan.</span>
            </span>
        </label>
        <textarea name="description" id="pki_description" placeholder="Deskripsikan secara singkat profil usaha, produk/layanan, atau kebutuhan rekrutmen..." style="min-height:90px; padding:10px 14px; font-size:13.5px; border:1px solid #cbd5e1; border-radius:8px; width:100%; font-family:inherit; line-height:1.5; resize:vertical;"><?php echo $vDescription; ?></textarea>
        <div class="pki-field-helper" id="helper_description">Deskripsikan secara singkat profil usaha, produk/layanan, atau kebutuhan rekrutmen.</div>
    </div>
</div>

<hr class="modal-section-hr">

<!-- 5. PERNYATAAN -->
<div class="modal-section" style="margin-bottom:12px;">
    <div class="section-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:14px;">5. PERNYATAAN</div>
    <div class="pki-field" style="margin-bottom:0;">
        <label style="display:flex; align-items:flex-start; gap:10px; font-size:13px; color:#334155; line-height:1.55; cursor:pointer;" id="label_user_consent_container">
            <input type="checkbox" name="user_consent" id="pki_user_consent" value="1" <?php echo $vConsent ? 'checked' : ''; ?> style="margin-top:3px; flex-shrink:0;">
            <span id="label_user_consent_text">Saya menyatakan bahwa seluruh informasi yang saya berikan benar dan dapat dipertanggungjawabkan serta tidak digunakan untuk penipuan, lowongan palsu, atau tindakan yang melanggar hukum. <span class="req">*</span></span>
        </label>
        <div class="pki-field-helper" id="helper_user_consent" style="display:none;">Centang pernyataan persetujuan untuk melanjutkan.</div>
    </div>
</div>

<!-- MODAL / FORM FOOTER -->
<div class="modal-footer" style="padding:20px 0 0 0; display:flex; justify-content:flex-end; gap:12px; border-top:1px solid #e2e8f0; margin-top:24px;">
    <?php if (!empty($cancelButtonHtml)): ?>
        <?php echo $cancelButtonHtml; ?>
    <?php else: ?>
        <a href="employer-type.php" class="ghost-btn" style="text-decoration:none; display:inline-flex; align-items:center; justify-content:center;">Batal</a>
    <?php endif; ?>
    <button type="submit" class="primary-btn" id="btnSubmitProfile">
        <i class="fa-solid fa-paper-plane" style="margin-right:6px;"></i>
        Ajukan Profil
    </button>
</div>

<script>
window.PKI_CONFIG = {
    googleMapsApiKey: "<?php echo addslashes($googleMapsApiKey); ?>",
    isRevision: <?php echo $isRevision ? 'true' : 'false'; ?>
};
</script>
