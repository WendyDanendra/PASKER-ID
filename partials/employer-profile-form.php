<?php
/**
 * Shared Component: Form Profil Pemberi Kerja Individu
 * Digunakan bersama oleh:
 * 1. register.php (Pendaftaran Awal)
 * 2. Index.html / dashboard.php (Form Perbaikan / Revisi Modal)
 */

if (!isset($isRevision)) {
    $isRevision = false;
}

if (!isset($formData) || empty($formData)) {
    if ($isRevision) {
        $formData = array_merge(
            isset($user) && is_array($user) ? $user : [],
            isset($profile) && is_array($profile) ? $profile : [],
            isset($seekerProfile) && is_array($seekerProfile) ? $seekerProfile : []
        );
    } else {
        // Form Pendaftaran: ambil data akun/demo yang tersedia secara dinamis dari database tanpa hardcode testing data
        $demoUser = function_exists('find_user_by_email') ? find_user_by_email('perorangan@paskerid.test') : null;
        $demoProfile = null;
        if ($demoUser && function_exists('db')) {
            $stmt = db()->prepare('SELECT * FROM employer_profiles WHERE user_id = ? LIMIT 1');
            $stmt->execute([$demoUser['id']]);
            $demoProfile = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        $formData = array_merge(
            $demoUser ?: [],
            $demoProfile ?: []
        );
    }
}

// 1. Identitas Perorangan
$valOwnerName = $formData['owner_name'] ?? $formData['name'] ?? '';
$valNik = $formData['nik'] ?? '';
$valPhone = $formData['phone'] ?? '';
$valWhatsapp = $formData['whatsapp'] ?? ($formData['phone'] ?? '');
$valProfession = $formData['profession'] ?? $formData['industry'] ?? '';
$valNpwp = $formData['npwp'] ?? '';

// 2. Alamat Domisili
$valDomProv = $formData['domicile_province'] ?? $formData['province'] ?? '';
$valDomCity = $formData['domicile_city'] ?? $formData['city'] ?? '';
$valDomDist = $formData['domicile_district'] ?? $formData['district'] ?? '';
$valDomVill = $formData['domicile_village'] ?? $formData['village'] ?? '';
$valDomPostal = $formData['domicile_postal_code'] ?? $formData['postal_code'] ?? '';
$valDomAddress = $formData['domicile_address'] ?? $formData['address'] ?? '';

// 3. Tempat Usaha / Kegiatan
$valSameAsDom = isset($formData['workplace_same_as_domicile']) 
    ? (int)$formData['workplace_same_as_domicile'] 
    : (isset($formData['same_location_siapkerja']) ? (int)$formData['same_location_siapkerja'] : 1);

$valWorkProv = $formData['workplace_province'] ?? ($valSameAsDom ? $valDomProv : '');
$valWorkCity = $formData['workplace_city'] ?? ($valSameAsDom ? $valDomCity : '');
$valWorkDist = $formData['workplace_district'] ?? ($valSameAsDom ? $valDomDist : '');
$valWorkVill = $formData['workplace_village'] ?? ($valSameAsDom ? $valDomVill : '');
$valWorkPostal = $formData['workplace_postal_code'] ?? ($valSameAsDom ? $valDomPostal : '');
$valWorkAddress = $formData['workplace_address'] ?? ($valSameAsDom ? $valDomAddress : '');
$valWorkDetail = $formData['workplace_detail'] ?? $formData['address_detail'] ?? $formData['address_notes'] ?? '';

// 4. File & Bukti Tempat Usaha
$valPermitDoc = $formData['permit_document'] ?? $formData['doc_permission'] ?? '';
$valWorkPhoto = $formData['workplace_photo'] ?? $formData['doc_location_photo'] ?? '';

// 5. Media Sosial & Deskripsi
$valLinkedin = $formData['linkedin'] ?? '';
$valFacebook = $formData['facebook'] ?? '';
$valInstagram = $formData['instagram'] ?? '';
$valDescription = $formData['description'] ?? '';

// 6. Pernyataan
$valConsent = !empty($formData['user_consent']) || !empty($formData['consent_agreed']) || !empty($formData['consent_accepted']);

$formAction = $isRevision ? 'dashboard.php' : 'register.php';
$formId = $isRevision ? 'formEmployerProfile' : 'registrationForm';
?>

<!-- ─── SHARED FORM STYLES ─── -->
<style>
.pki-form-container {
    width: 100%;
    color: #0f172a;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}
.pki-form-container * {
    box-sizing: border-box;
}

/* Banner Mode Demo */
.pki-demo-banner {
    background: #f0f9ff;
    border: 1px solid #bae6fd;
    border-radius: 8px;
    padding: 14px 16px;
    margin-bottom: 24px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
}
.pki-demo-banner-icon {
    color: #0284c7;
    font-size: 16px;
    margin-top: 2px;
    flex-shrink: 0;
}
.pki-demo-banner-title {
    font-size: 13px;
    font-weight: 800;
    color: #0369a1;
    margin-bottom: 3px;
    letter-spacing: 0.5px;
}
.pki-demo-banner-text {
    font-size: 12.5px;
    color: #0c4a6e;
    line-height: 1.5;
}

/* Section Header */
.pki-section {
    margin-bottom: 24px;
}
.pki-section-title {
    font-size: 15px;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 4px;
    letter-spacing: 0.2px;
}
.pki-section-subtitle {
    font-size: 12.5px;
    color: #64748b;
    margin-bottom: 16px;
}
.pki-section-hr {
    border: 0;
    border-top: 1px solid #e2e8f0;
    margin: 24px 0;
}

/* Grid & Fields */
.pki-field-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}
@media (max-width: 680px) {
    .pki-field-grid {
        grid-template-columns: 1fr;
    }
}
.pki-field {
    margin-bottom: 16px;
    position: relative;
}
.pki-field-grid .pki-field {
    margin-bottom: 0;
}

/* Label styling */
.pki-label {
    display: flex;
    align-items: center;
    font-size: 13px;
    font-weight: 600;
    color: #334155;
    margin-bottom: 6px;
    transition: color 0.15s ease;
}
.pki-req {
    color: #dc2626 !important;
    font-weight: 700;
    margin-left: 3px;
}

/* Inputs, Selects, Textarea */
.pki-field input[type="text"],
.pki-field input[type="tel"],
.pki-field input[type="email"],
.pki-field select,
.pki-field textarea {
    width: 100%;
    height: 42px;
    padding: 0 14px;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 13.5px;
    color: #0f172a;
    transition: border-color 0.15s, box-shadow 0.15s;
    font-family: inherit;
}
.pki-field textarea {
    height: auto;
    min-height: 84px;
    padding: 10px 14px;
    line-height: 1.5;
    resize: vertical;
}
.pki-field input:focus,
.pki-field select:focus,
.pki-field textarea:focus {
    border-color: #0284c7;
    box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.12);
    outline: none;
}
.pki-field select:disabled,
.pki-field input:disabled {
    background: #f8fafc;
    color: #94a3b8;
    cursor: not-allowed;
}

/* Helper Text */
.pki-helper-text {
    font-size: 12px;
    color: #64748b;
    margin-top: 5px;
    line-height: 1.4;
}

/* Error Text & Error State (BORDER MUST REMAIN NORMAL) */
.pki-error-text {
    display: none;
    font-size: 12px;
    color: #dc2626;
    margin-top: 5px;
    font-weight: 500;
    line-height: 1.4;
}
.pki-field.has-error .pki-label,
.pki-field.has-error .pki-consent-label {
    color: #dc2626 !important;
}
.pki-field.has-error .pki-helper-text {
    display: none !important;
}
.pki-field.has-error .pki-error-text {
    display: block !important;
}
/* Ensure input border NEVER turns red as requested */
.pki-field.has-error input:not([type="checkbox"]),
.pki-field.has-error select,
.pki-field.has-error textarea,
.pki-field.has-error .pki-hierarchical-input,
.pki-field.has-error .pki-upload-box {
    border-color: #cbd5e1 !important;
}

/* Tooltip Popover (MUST NOT SHIFT LAYOUT) */
.pki-tooltip-wrap {
    position: relative;
    display: inline-flex;
    align-items: center;
    margin-left: 6px;
    vertical-align: middle;
}
.pki-tooltip-btn {
    background: transparent;
    border: none;
    padding: 0;
    margin: 0;
    cursor: pointer;
    color: #64748b;
    font-size: 13.5px;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    outline: none;
    transition: color 0.15s ease;
}
.pki-tooltip-btn:hover,
.pki-tooltip-btn:focus {
    color: #0284c7;
}
.pki-tooltip-popover {
    position: absolute;
    bottom: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%);
    background: #0f172a;
    color: #ffffff;
    font-size: 12px;
    line-height: 1.45;
    padding: 8px 12px;
    border-radius: 6px;
    width: max-content;
    max-width: 280px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.25);
    z-index: 9999;
    pointer-events: none;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.15s ease, visibility 0.15s ease;
    font-weight: 400;
    text-align: left;
    white-space: normal;
}
.pki-tooltip-popover::after {
    content: '';
    position: absolute;
    top: 100%;
    left: 50%;
    transform: translateX(-50%);
    border-width: 5px;
    border-style: solid;
    border-color: #0f172a transparent transparent transparent;
}
.pki-tooltip-wrap:hover .pki-tooltip-popover,
.pki-tooltip-wrap:focus-within .pki-tooltip-popover,
.pki-tooltip-wrap.active .pki-tooltip-popover {
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
}

/* Hierarchical Location Dropdown */
.pki-loc-container {
    position: relative;
    width: 100%;
}
.pki-hierarchical-input {
    width: 100%;
    min-height: 42px;
    padding: 10px 14px;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 13.5px;
    color: #0f172a;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: border-color 0.15s, box-shadow 0.15s;
    user-select: none;
}
.pki-hierarchical-input:hover,
.pki-hierarchical-input:focus {
    border-color: #0284c7;
    box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.12);
    outline: none;
}
.pki-hierarchical-input .placeholder {
    color: #94a3b8;
}
.pki-loc-popup {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    box-shadow: 0 12px 32px rgba(15, 23, 42, 0.16);
    z-index: 1200;
    overflow: hidden;
    display: none;
}
.pki-loc-breadcrumbs {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 10px 14px;
    font-size: 12.5px;
    font-weight: 600;
    color: #475569;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.pki-crumb-item {
    cursor: pointer;
    color: #0284c7;
    text-decoration: none;
}
.pki-crumb-item:hover {
    text-decoration: underline;
}
.pki-crumb-item.active {
    color: #0f172a;
    font-weight: 700;
    cursor: default;
    text-decoration: none;
}
.pki-loc-list {
    max-height: 240px;
    overflow-y: auto;
    padding: 4px 0;
}
.pki-loc-item {
    padding: 9px 16px;
    font-size: 13px;
    color: #334155;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: background 0.12s;
}
.pki-loc-item:hover {
    background: #f1f5f9;
    color: #0f172a;
}
.pki-loc-item.selected {
    background: #f0f9ff;
    color: #0369a1;
    font-weight: 700;
}
.pki-radio-bullet {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    border: 2px solid #cbd5e1;
    display: inline-block;
    margin-right: 10px;
    flex-shrink: 0;
}
.pki-loc-item.selected .pki-radio-bullet {
    border-color: #0284c7;
    background: radial-gradient(circle, #0284c7 40%, transparent 45%);
}

/* Upload Box (Karirhub Visual Component) */
.pki-upload-box {
    width: 100%;
    min-height: 48px;
    padding: 8px 16px;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    transition: all 0.15s ease;
    user-select: none;
}
.pki-upload-box:hover {
    border-color: #0284c7;
    background: #f8fafc;
}
.pki-upload-placeholder {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13.5px;
    color: #64748b;
}
.pki-upload-btn-ui {
    background: #f1f5f9;
    color: #0f172a;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 5px 14px;
    font-size: 12.5px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: background 0.15s;
}
.pki-upload-box:hover .pki-upload-btn-ui {
    background: #e2e8f0;
}

/* File Card Preview */
.pki-file-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
}
.pki-file-card-info {
    display: flex;
    align-items: center;
    gap: 10px;
    overflow: hidden;
}
.pki-file-card-name {
    font-size: 13px;
    font-weight: 600;
    color: #0f172a;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 280px;
}
.pki-file-card-sub {
    font-size: 11.5px;
    color: #64748b;
}
.pki-file-btn {
    height: 28px;
    padding: 0 10px;
    font-size: 12px;
    font-weight: 600;
    border-radius: 6px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border: 1px solid transparent;
    transition: all 0.15s ease;
}
.pki-file-btn-change {
    background: #ffffff;
    border-color: #cbd5e1;
    color: #334155;
}
.pki-file-btn-change:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
}
.pki-file-btn-delete {
    background: #fef2f2;
    border-color: #fca5a5;
    color: #dc2626;
}
.pki-file-btn-delete:hover {
    background: #fee2e2;
}

/* Map Container */
.pki-map-container {
    height: 240px;
    width: 100%;
    border-radius: 10px;
    border: 1px solid #cbd5e1;
    overflow: hidden;
    position: relative;
    background: #f8fafc;
}
.pki-map-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: #64748b;
    text-align: center;
    padding: 20px;
}
.pki-map-frame-box {
    width: 100%;
    height: 100%;
    position: relative;
}
.pki-map-open-btn {
    position: absolute;
    top: 10px;
    right: 10px;
    z-index: 10;
    background: #ffffff;
    color: #0f172a;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    text-decoration: none;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border: 1px solid #cbd5e1;
    transition: background 0.15s ease;
}
.pki-map-open-btn:hover {
    background: #f8fafc;
    color: #0284c7;
}

/* Consent Checkbox */
.pki-consent-container {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-top: 8px;
}
.pki-consent-checkbox {
    width: 18px;
    height: 18px;
    min-width: 18px;
    max-width: 18px;
    margin-top: 3px;
    cursor: pointer;
    flex-shrink: 0;
    accent-color: #0284c7;
}
.pki-consent-label {
    font-size: 13px;
    color: #334155;
    line-height: 1.55;
    cursor: pointer;
    font-weight: 400;
    margin: 0;
    flex: 1;
    transition: color 0.15s ease;
}
</style>

<div class="pki-form-container">
    <form method="post" action="<?php echo $formAction; ?>" enctype="multipart/form-data" id="<?php echo $formId; ?>" novalidate>
        <?php if ($isRevision): ?>
            <input type="hidden" name="submit_profile" value="1">
        <?php else: ?>
            <input type="hidden" name="register_action" value="simulate_employer_session">
        <?php endif; ?>

        <!-- BANNER MODE DEMO -->
        <div class="pki-demo-banner">
            <i class="fa-solid fa-circle-info pki-demo-banner-icon"></i>
            <div>
                <div class="pki-demo-banner-title">MODE DEMO</div>
                <div class="pki-demo-banner-text">Pada implementasi produksi, data identitas dan domisili akan terisi otomatis dari SIAPKerja. Pada demo ini, data dapat diedit untuk kebutuhan pengujian.</div>
            </div>
        </div>

        <!-- 1. IDENTITAS PERORANGAN -->
        <div class="pki-section">
            <div class="pki-section-title">1. IDENTITAS PERORANGAN</div>
            <div class="pki-section-subtitle">Lengkapi informasi utama Pemberi Kerja Individu.</div>

            <div class="pki-field-grid">
                <!-- Nama Pemberi Kerja -->
                <div class="pki-field" id="field_owner_name">
                    <label class="pki-label" for="pki_owner_name">
                        Nama Pemberi Kerja <span class="pki-req">*</span>
                    </label>
                    <input type="text" name="owner_name" id="pki_owner_name" value="<?php echo e($valOwnerName); ?>" placeholder="Masukkan nama lengkap">
                    <div class="pki-helper-text">Data nama pada produksi berasal dari akun SIAPKerja.</div>
                    <div class="pki-error-text">Nama Pemberi Kerja wajib diisi.</div>
                </div>

                <!-- NIK -->
                <div class="pki-field" id="field_nik">
                    <label class="pki-label" for="pki_nik">
                        NIK <span class="pki-req">*</span>
                    </label>
                    <input type="text" name="nik" id="pki_nik" maxlength="16" value="<?php echo e($valNik); ?>" placeholder="Masukkan 16 digit NIK">
                    <div class="pki-helper-text">NIK terdiri dari 16 digit.</div>
                    <div class="pki-error-text">NIK harus terdiri dari 16 digit angka.</div>
                </div>

                <!-- Nomor Telepon Aktif -->
                <div class="pki-field" id="field_phone">
                    <label class="pki-label" for="pki_phone">
                        Nomor Telepon Aktif <span class="pki-req">*</span>
                    </label>
                    <input type="tel" name="phone" id="pki_phone" value="<?php echo e($valPhone); ?>" placeholder="Contoh: 08123456789">
                    <div class="pki-helper-text">Gunakan nomor telepon aktif.</div>
                    <div class="pki-error-text">Nomor telepon aktif wajib diisi.</div>
                </div>

                <!-- Nomor WhatsApp -->
                <div class="pki-field" id="field_whatsapp">
                    <label class="pki-label" for="pki_whatsapp">
                        Nomor WhatsApp <span class="pki-req">*</span>
                    </label>
                    <input type="tel" name="whatsapp" id="pki_whatsapp" value="<?php echo e($valWhatsapp); ?>" placeholder="Contoh: 08123456789">
                    <div class="pki-helper-text">Gunakan nomor WhatsApp aktif.</div>
                    <div class="pki-error-text">Nomor WhatsApp aktif wajib diisi.</div>
                </div>

                <!-- Industri / Sektor -->
                <div class="pki-field" id="field_profession">
                    <label class="pki-label" for="pki_profession">
                        <span>Industri / Sektor <span class="pki-req">*</span></span>
                        <span class="pki-tooltip-wrap">
                            <button type="button" class="pki-tooltip-btn" aria-label="Info Industri / Sektor">
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <span class="pki-tooltip-popover">Pilih industri atau sektor yang paling sesuai dengan kegiatan utama usaha Anda.</span>
                        </span>
                    </label>
                    <select name="profession" id="pki_profession">
                        <option value="">Pilih industri / sektor</option>
                        <?php
                        $sectorOptions = [
                            'Jasa Perorangan / Rumah Tangga',
                            'Kuliner & Katering',
                            'Perdagangan & Eceran',
                            'Teknologi & Kreatif',
                            'Pertanian & Peternakan',
                            'Konstruksi & Perbaikan Bangunan',
                            'Transportasi & Logistik',
                            'Pendidikan & Pelatihan',
                            'Kesehatan & Kebugaran',
                            'Lainnya'
                        ];
                        foreach ($sectorOptions as $opt):
                            $selected = ($valProfession === $opt) ? 'selected' : '';
                        ?>
                            <option value="<?php echo e($opt); ?>" <?php echo $selected; ?>><?php echo e($opt); ?></option>
                        <?php endforeach; ?>
                        <?php if ($valProfession && !in_array($valProfession, $sectorOptions)): ?>
                            <option value="<?php echo e($valProfession); ?>" selected><?php echo e($valProfession); ?></option>
                        <?php endif; ?>
                    </select>
                    <div class="pki-helper-text">Pilih industri atau sektor yang paling sesuai dengan kegiatan utama usaha.</div>
                    <div class="pki-error-text">Pilih industri atau sektor yang paling sesuai.</div>
                </div>

                <!-- NPWP -->
                <div class="pki-field" id="field_npwp">
                    <label class="pki-label" for="pki_npwp">
                        NPWP <span class="pki-req">*</span>
                    </label>
                    <input type="text" name="npwp" id="pki_npwp" value="<?php echo e($valNpwp); ?>" placeholder="Masukkan NPWP 15 atau 16 digit">
                    <div class="pki-helper-text">NPWP 15 atau 16 digit.</div>
                    <div class="pki-error-text">NPWP harus terdiri dari 15 atau 16 digit.</div>
                </div>
            </div>
        </div>

        <hr class="pki-section-hr">

        <!-- 2. ALAMAT DOMISILI PEMBERI KERJA -->
        <div class="pki-section">
            <div class="pki-section-title">2. ALAMAT DOMISILI PEMBERI KERJA</div>
            <div class="pki-section-subtitle">Data domisili pada production berasal dari akun SIAPKerja.</div>

            <!-- Hidden Inputs Domisili -->
            <input type="hidden" name="domicile_province" id="pki_domicile_province" value="<?php echo e($valDomProv); ?>">
            <input type="hidden" name="domicile_city" id="pki_domicile_city" value="<?php echo e($valDomCity); ?>">
            <input type="hidden" name="domicile_district" id="pki_domicile_district" value="<?php echo e($valDomDist); ?>">
            <input type="hidden" name="domicile_village" id="pki_domicile_village" value="<?php echo e($valDomVill); ?>">

            <!-- Lokasi Domisili -->
            <div class="pki-field" id="field_domicile_location">
                <label class="pki-label">
                    Lokasi Domisili <span class="pki-req">*</span>
                </label>
                <div class="pki-loc-container" id="pki_dom_loc_container">
                    <div class="pki-hierarchical-input" id="pki_dom_loc_display" tabindex="0">
                        <span id="pki_dom_loc_text" class="<?php echo empty($valDomVill) ? 'placeholder' : ''; ?>">
                            <?php
                            if ($valDomVill && $valDomDist && $valDomCity && $valDomProv) {
                                echo e("{$valDomVill}, {$valDomDist}, {$valDomCity}, {$valDomProv}");
                            } else {
                                echo "Pilih lokasi domisili";
                            }
                            ?>
                        </span>
                        <i class="fa-solid fa-chevron-down" id="pki_dom_loc_caret" style="color:#64748b; font-size:12px;"></i>
                    </div>
                    <div class="pki-loc-popup" id="pki_dom_loc_popup">
                        <div class="pki-loc-breadcrumbs" id="pki_dom_breadcrumbs"></div>
                        <div class="pki-loc-list" id="pki_dom_options_list"></div>
                    </div>
                </div>
                <div class="pki-helper-text">Wilayah administratif sampai Kelurahan/Desa.</div>
                <div class="pki-error-text">Lokasi domisili wajib dipilih sampai Kelurahan/Desa.</div>
            </div>

            <!-- Alamat Lengkap Domisili (Input Text) -->
            <div class="pki-field" id="field_domicile_address">
                <label class="pki-label" for="pki_domicile_address">
                    Alamat Lengkap Domisili <span class="pki-req">*</span>
                </label>
                <input type="text" name="domicile_address" id="pki_domicile_address" value="<?php echo e($valDomAddress); ?>" placeholder="Jl. Ir. H. Juanda No. 120, RT 03/RW 01">
                <div class="pki-error-text">Alamat lengkap domisili wajib diisi.</div>
            </div>

            <!-- Kode Pos Domisili -->
            <div class="pki-field" id="field_domicile_postal_code">
                <label class="pki-label" for="pki_domicile_postal_code">
                    Kode Pos <span class="pki-req">*</span>
                </label>
                <select name="domicile_postal_code" id="pki_domicile_postal_code" <?php echo empty($valDomPostal) ? 'disabled' : ''; ?> style="max-width:320px;">
                    <option value="">Pilih kode pos</option>
                    <?php if ($valDomPostal): ?>
                        <option value="<?php echo e($valDomPostal); ?>" selected><?php echo e($valDomPostal); ?></option>
                    <?php endif; ?>
                </select>
                <div class="pki-helper-text">Pilihan kode pos mengikuti lokasi yang dipilih.</div>
                <div class="pki-error-text">Kode pos domisili wajib dipilih.</div>
            </div>
        </div>

        <hr class="pki-section-hr">

        <!-- 3. TEMPAT USAHA / KEGIATAN PEMBERI KERJA -->
        <div class="pki-section">
            <div class="pki-section-title">3. TEMPAT USAHA / KEGIATAN PEMBERI KERJA</div>
            <div class="pki-section-subtitle">Tentukan lokasi tempat pelaksanaan usaha atau kegiatan operasional Anda.</div>

            <!-- Checkbox Sama dengan Domisili -->
            <div style="margin-bottom:16px;">
                <label style="display:flex; align-items:center; gap:8px; font-size:13px; font-weight:600; color:#334155; cursor:pointer;">
                    <input type="checkbox" name="workplace_same_as_domicile" id="pki_cb_same_as_domicile" value="1" <?php echo $valSameAsDom ? 'checked' : ''; ?> style="width:16px; height:16px; accent-color:#0284c7;">
                    <span>Alamat tempat usaha/kegiatan sama dengan alamat domisili di SIAPKerja</span>
                </label>
            </div>

            <!-- Hidden Inputs Tempat Usaha -->
            <input type="hidden" name="workplace_province" id="pki_workplace_province" value="<?php echo e($valWorkProv); ?>">
            <input type="hidden" name="workplace_city" id="pki_workplace_city" value="<?php echo e($valWorkCity); ?>">
            <input type="hidden" name="workplace_district" id="pki_workplace_district" value="<?php echo e($valWorkDist); ?>">
            <input type="hidden" name="workplace_village" id="pki_workplace_village" value="<?php echo e($valWorkVill); ?>">

            <!-- Notice jika checkbox dicentang -->
            <div id="pki_same_domicile_notice" style="display:<?php echo $valSameAsDom ? 'block' : 'none'; ?>; margin-bottom:16px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; padding:10px 14px; font-size:12.5px; color:#0369a1;">
                <i class="fa-solid fa-circle-check" style="margin-right:6px;"></i> Lokasi dan alamat tempat usaha disalin secara otomatis dari data Domisili.
            </div>

            <!-- Manual Workplace Fields Container -->
            <div id="pki_workplace_manual_container" style="display:<?php echo $valSameAsDom ? 'none' : 'block'; ?>;">
                <!-- Lokasi Tempat Usaha / Kegiatan -->
                <div class="pki-field" id="field_workplace_location">
                    <label class="pki-label">
                        <span>Lokasi Tempat Usaha / Kegiatan <span class="pki-req">*</span></span>
                        <span class="pki-tooltip-wrap">
                            <button type="button" class="pki-tooltip-btn" aria-label="Info Lokasi Usaha">
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <span class="pki-tooltip-popover">Lokasi tempat Anda menjalankan usaha atau kegiatan sebagai Pemberi Kerja Individu. Lokasi ini dapat berbeda dengan alamat domisili.</span>
                        </span>
                    </label>
                    <div class="pki-loc-container" id="pki_work_loc_container">
                        <div class="pki-hierarchical-input" id="pki_work_loc_display" tabindex="0">
                            <span id="pki_work_loc_text" class="<?php echo empty($valWorkVill) ? 'placeholder' : ''; ?>">
                                <?php
                                if ($valWorkVill && $valWorkDist && $valWorkCity && $valWorkProv) {
                                    echo e("{$valWorkVill}, {$valWorkDist}, {$valWorkCity}, {$valWorkProv}");
                                } else {
                                    echo "Pilih lokasi tempat usaha / kegiatan";
                                }
                                ?>
                            </span>
                            <i class="fa-solid fa-chevron-down" id="pki_work_loc_caret" style="color:#64748b; font-size:12px;"></i>
                        </div>
                        <div class="pki-loc-popup" id="pki_work_loc_popup">
                            <div class="pki-loc-breadcrumbs" id="pki_work_breadcrumbs"></div>
                            <div class="pki-loc-list" id="pki_work_options_list"></div>
                        </div>
                    </div>
                    <div class="pki-helper-text">Pilih lokasi secara berjenjang sampai Kelurahan/Desa.</div>
                    <div class="pki-error-text">Lokasi tempat usaha wajib dipilih sampai Kelurahan/Desa.</div>
                </div>

                <!-- Alamat Lengkap Tempat Usaha -->
                <div class="pki-field" id="field_workplace_address">
                    <label class="pki-label" for="pki_workplace_address">
                        Alamat Lengkap Tempat Usaha <span class="pki-req">*</span>
                    </label>
                    <input type="text" name="workplace_address" id="pki_workplace_address" value="<?php echo e($valWorkAddress); ?>" placeholder="Tuliskan alamat lengkap tempat usaha/kegiatan">
                    <div class="pki-helper-text">Tuliskan alamat lengkap tempat usaha/kegiatan.</div>
                    <div class="pki-error-text">Alamat lengkap tempat usaha wajib diisi.</div>
                </div>

                <!-- Kode Pos Tempat Usaha -->
                <div class="pki-field" id="field_workplace_postal_code">
                    <label class="pki-label" for="pki_workplace_postal_code">
                        Kode Pos <span class="pki-req">*</span>
                    </label>
                    <select name="workplace_postal_code" id="pki_workplace_postal_code" <?php echo empty($valWorkPostal) ? 'disabled' : ''; ?> style="max-width:320px;">
                        <option value="">Pilih kode pos</option>
                        <?php if ($valWorkPostal): ?>
                            <option value="<?php echo e($valWorkPostal); ?>" selected><?php echo e($valWorkPostal); ?></option>
                        <?php endif; ?>
                    </select>
                    <div class="pki-helper-text">Pilihan kode pos mengikuti lokasi yang dipilih.</div>
                    <div class="pki-error-text">Kode pos tempat usaha wajib dipilih.</div>
                </div>

                <!-- Detail Alamat / Patokan (Opsional) -->
                <div class="pki-field" id="field_workplace_detail">
                    <label class="pki-label" for="pki_workplace_detail">
                        <span>Detail Alamat / Patokan</span>
                        <span class="pki-tooltip-wrap">
                            <button type="button" class="pki-tooltip-btn" aria-label="Info Detail Alamat">
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <span class="pki-tooltip-popover">Tambahkan informasi yang membantu mengenali lokasi, seperti nomor bangunan, blok, lantai, atau patokan terdekat.</span>
                        </span>
                    </label>
                    <input type="text" name="workplace_detail" id="pki_workplace_detail" value="<?php echo e($valWorkDetail); ?>" placeholder="Contoh: Ruko 2 lantai, sebelah kantor pos, seberang masjid">
                    <div class="pki-helper-text">Opsional. Tambahkan informasi yang membantu mengenali lokasi.</div>
                </div>
            </div>

            <!-- PETA LOKASI (GOOGLE MAPS) -->
            <div class="pki-field" style="margin-top:16px;">
                <label class="pki-label" style="font-weight:700; color:#0f172a; margin-bottom:8px;">
                    Peta Lokasi
                </label>
                <div class="pki-map-container" id="pki_gmap_wrapper">
                    <!-- Placeholder saat alamat belum lengkap -->
                    <div class="pki-map-placeholder" id="pki_gmap_placeholder">
                        <div style="font-size:28px; margin-bottom:6px;">📍</div>
                        <div style="font-size:13.5px; font-weight:700; color:#334155; margin-bottom:4px;">Pratinjau peta belum tersedia. Lengkapi lokasi dan alamat terlebih dahulu.</div>
                    </div>
                    <!-- Frame Google Maps -->
                    <div class="pki-map-frame-box" id="pki_gmap_frame_box" style="display:none;">
                        <iframe id="pki_gmap_iframe" width="100%" height="100%" frameborder="0" style="border:0;" loading="lazy" src=""></iframe>
                        <a id="pki_gmap_open_btn" href="#" target="_blank" rel="noopener noreferrer" class="pki-map-open-btn">
                            Open in Maps <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:11px;"></i>
                        </a>
                    </div>
                </div>
                <div class="pki-helper-text" style="margin-top:6px;">Peta muncul otomatis setelah lokasi dan alamat lengkap tersedia.</div>
            </div>
        </div>

        <hr class="pki-section-hr">

        <!-- 4. FILE & BUKTI TEMPAT USAHA -->
        <div class="pki-section">
            <div class="pki-section-title">4. FILE & BUKTI TEMPAT USAHA</div>
            <div class="pki-section-subtitle">Unggah berkas legalitas dan foto bukti keberadaan lokasi tempat usaha.</div>

            <!-- File Pendukung -->
            <div class="pki-field" id="field_permit_document" style="margin-bottom:20px;">
                <label class="pki-label">
                    <span>File Pendukung <span class="pki-req">*</span></span>
                    <span class="pki-tooltip-wrap">
                        <button type="button" class="pki-tooltip-btn" aria-label="Info File Pendukung">
                            <i class="fa-solid fa-circle-info"></i>
                        </button>
                        <span class="pki-tooltip-popover">Unggah file yang mendukung validitas identitas atau kegiatan usaha Pemberi Kerja Individu.</span>
                    </span>
                </label>

                <!-- Hidden Input File -->
                <input type="file" name="permit_document" id="pki_input_permit_document" accept=".pdf" style="display:none;">
                <input type="hidden" name="existing_permit_document" id="pki_existing_permit_document" value="<?php echo e($valPermitDoc); ?>">

                <!-- Existing File Card -->
                <div class="pki-file-card" id="pki_card_permit_document" style="display:<?php echo $valPermitDoc ? 'flex' : 'none'; ?>;">
                    <div class="pki-file-card-info">
                        <i class="fa-solid fa-file-pdf" style="color:#dc2626; font-size:20px;"></i>
                        <div>
                            <div class="pki-file-card-name" id="pki_name_permit_document"><?php echo e(basename($valPermitDoc)); ?></div>
                            <div class="pki-file-card-sub" id="pki_sub_permit_document">File terunggah • Format PDF</div>
                        </div>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <button type="button" class="pki-file-btn pki-file-btn-change" onclick="triggerPkiUpload('permit')">Ganti</button>
                        <button type="button" class="pki-file-btn pki-file-btn-delete" onclick="removePkiFile('permit')">Hapus</button>
                    </div>
                </div>

                <!-- Upload Box -->
                <div class="pki-upload-box" id="pki_box_permit_document" onclick="triggerPkiUpload('permit')" style="display:<?php echo $valPermitDoc ? 'none' : 'flex'; ?>;">
                    <div class="pki-upload-placeholder">
                        <i class="fa-solid fa-cloud-arrow-up" style="color:#0284c7; font-size:16px;"></i>
                        <span>Klik untuk meng-upload berkas / file disini</span>
                    </div>
                    <span class="pki-upload-btn-ui">[ Upload ]</span>
                </div>

                <div class="pki-helper-text">Format pdf • ukuran maks 15MB</div>
                <div class="pki-helper-text" style="color:#64748b; font-size:11.5px;">File Pendukung wajib minimal 1 file.</div>
                <div class="pki-error-text">File Pendukung wajib diunggah minimal 1 file (Format PDF, maks 15MB).</div>
            </div>

            <!-- Foto Bukti Tempat Usaha / Lokasi -->
            <div class="pki-field" id="field_workplace_photo">
                <label class="pki-label">
                    <span>Foto Bukti Tempat Usaha / Lokasi <span class="pki-req">*</span></span>
                    <span class="pki-tooltip-wrap">
                        <button type="button" class="pki-tooltip-btn" aria-label="Info Foto Bukti">
                            <i class="fa-solid fa-circle-info"></i>
                        </button>
                        <span class="pki-tooltip-popover">Unggah foto bagian depan rumah, tempat usaha, atau lokasi kegiatan yang sesuai dengan alamat tempat usaha/kegiatan yang dicantumkan pada profil.</span>
                    </span>
                </label>

                <!-- Hidden Input Foto -->
                <input type="file" name="workplace_photo" id="pki_input_workplace_photo" accept=".jpg,.jpeg,.png,.webp" style="display:none;">
                <input type="hidden" name="existing_workplace_photo" id="pki_existing_workplace_photo" value="<?php echo e($valWorkPhoto); ?>">

                <!-- Existing Photo Card -->
                <div class="pki-file-card" id="pki_card_workplace_photo" style="display:<?php echo $valWorkPhoto ? 'flex' : 'none'; ?>;">
                    <div class="pki-file-card-info">
                        <i class="fa-solid fa-image" style="color:#0284c7; font-size:20px;"></i>
                        <div>
                            <div class="pki-file-card-name" id="pki_name_workplace_photo"><?php echo e(basename($valWorkPhoto)); ?></div>
                            <div class="pki-file-card-sub" id="pki_sub_workplace_photo">Foto terunggah</div>
                        </div>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <button type="button" class="pki-file-btn pki-file-btn-change" onclick="triggerPkiUpload('photo')">Ganti</button>
                        <button type="button" class="pki-file-btn pki-file-btn-delete" onclick="removePkiFile('photo')">Hapus</button>
                    </div>
                </div>

                <!-- Upload Box -->
                <div class="pki-upload-box" id="pki_box_workplace_photo" onclick="triggerPkiUpload('photo')" style="display:<?php echo $valWorkPhoto ? 'none' : 'flex'; ?>;">
                    <div class="pki-upload-placeholder">
                        <i class="fa-solid fa-camera" style="color:#0284c7; font-size:16px;"></i>
                        <span>Klik untuk meng-upload foto disini</span>
                    </div>
                    <span class="pki-upload-btn-ui">[ Upload ]</span>
                </div>

                <div class="pki-helper-text">Unggah minimal 1 foto tempat usaha/kegiatan sesuai alamat pada profil.</div>
                <div class="pki-error-text">Foto Bukti Tempat Usaha / Lokasi wajib diunggah minimal 1 foto.</div>
            </div>
        </div>

        <hr class="pki-section-hr">

        <!-- 5. MEDIA SOSIAL & DESKRIPSI -->
        <div class="pki-section">
            <div class="pki-section-title">5. MEDIA SOSIAL & DESKRIPSI</div>
            <div class="pki-section-subtitle">Tambahkan tautan jejaring sosial dan deskripsi singkat kegiatan usaha Anda.</div>

            <div class="pki-field-grid">
                <!-- LinkedIn -->
                <div class="pki-field">
                    <label class="pki-label" for="pki_linkedin">LinkedIn</label>
                    <input type="text" name="linkedin" id="pki_linkedin" value="<?php echo e($valLinkedin); ?>" placeholder="username atau https://linkedin.com/in/...">
                    <div class="pki-helper-text">Opsional.</div>
                </div>

                <!-- Facebook -->
                <div class="pki-field">
                    <label class="pki-label" for="pki_facebook">Facebook</label>
                    <input type="text" name="facebook" id="pki_facebook" value="<?php echo e($valFacebook); ?>" placeholder="@username atau https://facebook.com/...">
                    <div class="pki-helper-text">Opsional.</div>
                </div>

                <!-- Instagram -->
                <div class="pki-field" style="grid-column: 1 / -1;">
                    <label class="pki-label" for="pki_instagram">Instagram</label>
                    <input type="text" name="instagram" id="pki_instagram" value="<?php echo e($valInstagram); ?>" placeholder="@username atau https://instagram.com/..." style="max-width:calc(50% - 8px);">
                    <div class="pki-helper-text">Opsional.</div>
                </div>
            </div>

            <!-- Deskripsi Singkat Usaha / Rekrutmen -->
            <div class="pki-field" id="field_description">
                <label class="pki-label" for="pki_description">
                    <span>Deskripsi Singkat Usaha / Rekrutmen</span>
                    <span class="pki-tooltip-wrap">
                        <button type="button" class="pki-tooltip-btn" aria-label="Info Deskripsi Usaha">
                            <i class="fa-solid fa-circle-info"></i>
                        </button>
                        <span class="pki-tooltip-popover">Jelaskan secara singkat usaha atau kegiatan yang dijalankan serta gambaran kebutuhan rekrutmen yang dilakukan.</span>
                    </span>
                </label>
                <textarea name="description" id="pki_description" placeholder="Deskripsikan secara singkat profil usaha, produk/layanan, atau kebutuhan rekrutmen..."><?php echo e($valDescription); ?></textarea>
                <div class="pki-helper-text">Deskripsikan secara singkat profil usaha, produk/layanan, atau kebutuhan rekrutmen.</div>
            </div>
        </div>

        <hr class="pki-section-hr">

        <!-- 6. PERNYATAAN (Judul TIDAK diberi mandatory *) -->
        <div class="pki-section">
            <div class="pki-section-title">6. PERNYATAAN</div>
            <div class="pki-field" id="field_user_consent" style="margin-top:12px; margin-bottom:0;">
                <div class="pki-consent-container">
                    <input type="checkbox" name="user_consent" id="pki_user_consent" value="1" class="pki-consent-checkbox" <?php echo $valConsent ? 'checked' : ''; ?>>
                    <label for="pki_user_consent" class="pki-consent-label" id="pki_consent_label">
                        Saya menyatakan bahwa seluruh informasi yang saya berikan benar dan dapat dipertanggungjawabkan serta tidak digunakan untuk penipuan, lowongan palsu, atau tindakan yang melanggar hukum. <span class="pki-req">*</span>
                    </label>
                </div>
                <div class="pki-error-text" style="margin-left:30px;">Pernyataan persetujuan wajib dicentang sebelum mengajukan profil.</div>
            </div>
        </div>

        <!-- HIDDEN FIELDS FOR BACKWARD COMPATIBILITY / LAT LNG -->
        <input type="hidden" name="province" id="pki_legacy_province" value="<?php echo e($formData['province'] ?? $valDomProv); ?>">
        <input type="hidden" name="city" id="pki_legacy_city" value="<?php echo e($formData['city'] ?? $valDomCity); ?>">
        <input type="hidden" name="district" id="pki_legacy_district" value="<?php echo e($formData['district'] ?? $valDomDist); ?>">
        <input type="hidden" name="village" id="pki_legacy_village" value="<?php echo e($formData['village'] ?? $valDomVill); ?>">
        <input type="hidden" name="domicile_city_id" id="pki_legacy_domicile_city_id" value="<?php echo e($formData['domicile_city_id'] ?? $valDomCity); ?>">
        <input type="hidden" name="postal_code" id="pki_legacy_postal_code" value="<?php echo e($formData['postal_code'] ?? $valDomPostal); ?>">
        <input type="hidden" name="address" id="pki_legacy_address" value="<?php echo e($formData['address'] ?? $valDomAddress); ?>">
        <input type="hidden" name="address_detail" id="pki_legacy_address_detail" value="<?php echo e($formData['address_detail'] ?? $valWorkDetail); ?>">
        <input type="hidden" name="latitude" id="pki_input_lat" value="<?php echo e($formData['latitude'] ?? ''); ?>">
        <input type="hidden" name="longitude" id="pki_input_lng" value="<?php echo e($formData['longitude'] ?? ''); ?>">

        <!-- ACTIONS FOOTER -->
        <div style="display:flex; justify-content:flex-end; align-items:center; gap:12px; margin-top:32px; padding-top:20px; border-top:1px solid #e2e8f0;">
            <?php if ($isRevision): ?>
                <button type="button" class="ghost-btn" data-close-modal="modal-employer-profile" style="height:40px; padding:0 20px; font-size:13.5px;">Batal</button>
            <?php else: ?>
                <a href="login.php" class="ghost-btn" style="height:40px; padding:0 20px; font-size:13.5px; text-decoration:none; display:inline-flex; align-items:center;">Batal</a>
            <?php endif; ?>

            <button type="submit" class="primary-btn" id="pki_btn_submit" style="height:40px; padding:0 24px; font-size:13.5px; display:inline-flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-paper-plane"></i>
                <span>Ajukan Profil</span>
            </button>
        </div>
    </form>
</div>

<!-- ─── SHARED JAVASCRIPT LOGIC ─── -->
<script>
(function() {
    // 1. DATA MASTER WILAYAH INDONESIA DENGAN KODE POS
    window.ID_LOCATIONS = window.ID_LOCATIONS || {
        'Jawa Barat': {
            'Kota Bandung': {
                'Coblong': { 'Dago': '40135', 'Sadang Serang': '40133', 'Sekeloa': '40134', 'Lebak Siliwangi': '40132', 'Cipaganti': '40131' },
                'Sukajadi': { 'Pasteur': '40161', 'Sukajadi': '40162', 'Sukawarna': '40164', 'Gegerkalongan': '40153' },
                'Sumur Bandung': { 'Braga': '40111', 'Kebon Pisang': '40112', 'Merdeka': '40113' },
                'Bandung Wetan': { 'Citarum': '40115', 'Tamansari': '40116', 'Cihapit': '40114' },
                'Cicendo': { 'Pasirkaliki': '40171', 'Arjuna': '40172', 'Pajajaran': '40173' },
                'Lengkong': { 'Malabar': '40262', 'Cijagra': '40265', 'Burangrang': '40262' }
            },
            'Kota Bekasi': {
                'Bekasi Selatan': { 'Pekayon Jaya': '17148', 'Jaka Setia': '17147', 'Kayuringin Jaya': '17144', 'Marga Jaya': '17141' },
                'Bekasi Timur': { 'Aren Jaya': '17111', 'Bekasi Jaya': '17112', 'Duren Jaya': '17111', 'Margahayu': '17113' },
                'Bekasi Barat': { 'Bintara': '17134', 'Kranji': '17135', 'Kota Baru': '17133', 'Bintara Jaya': '17136' },
                'Bekasi Utara': { 'Harapan Baru': '17123', 'Harapan Jaya': '17124', 'Teluk Pucung': '17121' }
            },
            'Kota Bogor': {
                'Bogor Tengah': { 'Babakan': '16128', 'Paledang': '16122', 'Sempur': '16129', 'Kebon Kelapa': '16125' },
                'Bogor Timur': { 'Baranangsiang': '16143', 'Katulampa': '16144', 'Tajur': '16141' }
            },
            'Kota Depok': {
                'Beji': { 'Beji': '16421', 'Kukusan': '16425', 'Pondok Cina': '16424', 'Tanah Baru': '16426' },
                'Pancoran Mas': { 'Depok': '16431', 'Mampang': '16433', 'Depok Jaya': '16432' }
            }
        },
        'DKI Jakarta': {
            'Kota Jakarta Selatan': {
                'Tebet': { 'Bukit Duri': '12840', 'Kebon Baru': '12830', 'Manggarai': '12850', 'Tebet Barat': '12810', 'Tebet Timur': '12820' },
                'Kebayoran Baru': { 'Cipete Utara': '12150', 'Gandaria Utara': '12140', 'Gunung': '12120', 'Melawai': '12160', 'Senayan': '12190' },
                'Cilandak': { 'Cilandak Barat': '12430', 'Cipete Selatan': '12410', 'Gandaria Selatan': '12420', 'Lebak Bulus': '12440' },
                'Setiabudi': { 'Karet': '12920', 'Karet Kuningan': '12940', 'Kuningan Timur': '12950', 'Setiabudi': '12910' }
            },
            'Kota Jakarta Pusat': {
                'Gambir': { 'Gambir': '10110', 'Cideng': '10150', 'Petojo Selatan': '10160' },
                'Tanah Abang': { 'Bendungan Hilir': '10210', 'Gelora': '10270', 'Kebon Kacang': '10240' },
                'Menteng': { 'Menteng': '10310', 'Cikini': '10330', 'Gondangdia': '10350' }
            },
            'Kota Jakarta Barat': {
                'Grogol Petamburan': { 'Grogol': '11450', 'Tomang': '11440', 'Tanjung Duren Selatan': '11470' },
                'Kebon Jeruk': { 'Kebon Jeruk': '11530', 'Kedoya Selatan': '11520' }
            }
        },
        'Banten': {
            'Kota Tangerang': {
                'Tangerang': { 'Cikokol': '15117', 'Babakan': '15118', 'Sukasari': '15118' },
                'Cipondoh': { 'Cipondoh': '15148', 'Petir': '15147', 'Poris Plawad': '15141' }
            },
            'Kota Tangerang Selatan': {
                'Serpong': { 'Serpong': '15311', 'Lengkong Gudang': '15310', 'Rawa Buntu': '15318' },
                'Ciputat': { 'Ciputat': '15411', 'Cipayung': '15411' }
            }
        },
        'Jawa Tengah': {
            'Kota Semarang': {
                'Semarang Tengah': { 'Pandansari': '50139', 'Sekayu': '50132', 'Pekunden': '50134' },
                'Banyumanik': { 'Banyumanik': '50264', 'Srondol Kulon': '50263' }
            },
            'Kota Surakarta': {
                'Banjarsari': { 'Keprabon': '57131', 'Manahan': '57139', 'Gilingan': '57134' },
                'Laweyan': { 'Laweyan': '57148', 'Purwosari': '57142' }
            }
        },
        'DI Yogyakarta': {
            'Kota Yogyakarta': {
                'Danurejan': { 'Bausasran': '55211', 'Tegal Panggung': '55212', 'Suryatmajan': '55213' },
                'Gondomanan': { 'Ngupasan': '55122', 'Prawirodirjan': '55121' },
                'Umbulharjo': { 'Semaki': '55166', 'Tahunan': '55167', 'Giwangan': '55163' }
            },
            'Kabupaten Sleman': {
                'Depok': { 'Caturtunggal': '55281', 'Maguwoharjo': '55282', 'Condongcatur': '55283' }
            }
        },
        'Jawa Timur': {
            'Kota Surabaya': {
                'Tegalsari': { 'Tegalsari': '60262', 'Kedungdoro': '60261', 'Dr. Soetomo': '60264' },
                'Gubeng': { 'Gubeng': '60281', 'Airlangga': '60286', 'Kertajaya': '60282' },
                'Wonokromo': { 'Wonokromo': '60243', 'Darmo': '60241', 'Sawunggaling': '60242' }
            },
            'Kota Malang': {
                'Klojen': { 'Klojen': '65111', 'Kauman': '65119', 'Rampal Celaket': '65111' }
            }
        },
        'Sumatera Utara': {
            'Kota Medan': {
                'Medan Kota': { 'Pasar Merah Timur': '20217', 'Teladan Barat': '20217', 'Pusat Pasar': '20212' },
                'Medan Petisah': { 'Sekip': '20111', 'Petisah Tengah': '20112' }
            }
        },
        'Bali': {
            'Kota Denpasar': {
                'Denpasar Barat': { 'Pemecutan': '80112', 'Dauh Puri': '80113', 'Padangsambian': '80118' },
                'Denpasar Selatan': { 'Sanur': '80228', 'Panjer': '80225', 'Renon': '80226' }
            }
        }
    };

    // 2. CLASS REUSABLE SELEKTOR WILAYAH HIERARKIS
    function HierarchicalLocationSelector(config) {
        this.container = document.getElementById(config.containerId);
        this.display = document.getElementById(config.displayId);
        this.displayText = document.getElementById(config.textId);
        this.caret = document.getElementById(config.caretId);
        this.popup = document.getElementById(config.popupId);
        this.breadcrumbs = document.getElementById(config.breadcrumbsId);
        this.optionsList = document.getElementById(config.optionsListId);

        this.inputProv = document.getElementById(config.inputProvId);
        this.inputCity = document.getElementById(config.inputCityId);
        this.inputDist = document.getElementById(config.inputDistId);
        this.inputVill = document.getElementById(config.inputVillId);
        this.selectPostal = document.getElementById(config.selectPostalId);

        this.placeholderText = config.placeholderText || 'Pilih lokasi';
        this.onChangeCallback = config.onChange || null;

        this.currentLevel = 1; // 1: Prov, 2: City, 3: Dist, 4: Vill
        this.selectedProv = this.inputProv ? this.inputProv.value : '';
        this.selectedCity = this.inputCity ? this.inputCity.value : '';
        this.selectedDist = this.inputDist ? this.inputDist.value : '';
        this.selectedVill = this.inputVill ? this.inputVill.value : '';

        this.init();
    }

    HierarchicalLocationSelector.prototype.init = function() {
        var self = this;
        if (!this.display || !this.popup) return;

        this.display.addEventListener('click', function(e) {
            e.stopPropagation();
            if (self.popup.style.display === 'block') {
                self.close();
            } else {
                self.open();
            }
        });

        this.popup.addEventListener('click', function(e) {
            e.stopPropagation();
        });

        document.addEventListener('click', function(e) {
            if (!self.container.contains(e.target)) {
                self.close();
            }
        });
    };

    HierarchicalLocationSelector.prototype.open = function() {
        // Tutup dropdown lain yang sedang terbuka
        document.querySelectorAll('.pki-loc-popup').forEach(function(p) {
            p.style.display = 'none';
        });

        this.popup.style.display = 'block';
        if (this.caret) {
            this.caret.classList.remove('fa-chevron-down');
            this.caret.classList.add('fa-chevron-up');
        }

        if (this.selectedProv && this.selectedCity && this.selectedDist && window.ID_LOCATIONS[this.selectedProv]?.[this.selectedCity]?.[this.selectedDist]) {
            this.currentLevel = 4;
        } else if (this.selectedProv && this.selectedCity && window.ID_LOCATIONS[this.selectedProv]?.[this.selectedCity]) {
            this.currentLevel = 3;
        } else if (this.selectedProv && window.ID_LOCATIONS[this.selectedProv]) {
            this.currentLevel = 2;
        } else {
            this.currentLevel = 1;
        }

        this.render();
    };

    HierarchicalLocationSelector.prototype.close = function() {
        this.popup.style.display = 'none';
        if (this.caret) {
            this.caret.classList.remove('fa-chevron-up');
            this.caret.classList.add('fa-chevron-down');
        }
    };

    HierarchicalLocationSelector.prototype.render = function() {
        this.renderBreadcrumbs();
        this.renderOptions();
    };

    HierarchicalLocationSelector.prototype.renderBreadcrumbs = function() {
        var self = this;
        if (!this.breadcrumbs) return;

        var html = '';
        if (this.currentLevel === 1) {
            html += '<span class="pki-crumb-item active">Pilih Provinsi</span>';
        } else if (this.currentLevel === 2) {
            html += '<span class="pki-crumb-item" data-goto="1">' + this.selectedProv + '</span> › ';
            html += '<span class="pki-crumb-item active">Pilih Kabupaten / Kota</span>';
        } else if (this.currentLevel === 3) {
            html += '<span class="pki-crumb-item" data-goto="1">' + this.selectedProv + '</span> › ';
            html += '<span class="pki-crumb-item" data-goto="2">' + this.selectedCity + '</span> › ';
            html += '<span class="pki-crumb-item active">Pilih Kecamatan</span>';
        } else if (this.currentLevel === 4) {
            html += '<span class="pki-crumb-item" data-goto="1">' + this.selectedProv + '</span> › ';
            html += '<span class="pki-crumb-item" data-goto="2">' + this.selectedCity + '</span> › ';
            html += '<span class="pki-crumb-item" data-goto="3">' + this.selectedDist + '</span> › ';
            html += '<span class="pki-crumb-item active">Pilih Kelurahan / Desa</span>';
        }

        this.breadcrumbs.innerHTML = html;

        this.breadcrumbs.querySelectorAll('[data-goto]').forEach(function(el) {
            el.addEventListener('click', function() {
                self.currentLevel = parseInt(el.getAttribute('data-goto'), 10);
                self.render();
            });
        });
    };

    HierarchicalLocationSelector.prototype.renderOptions = function() {
        var self = this;
        if (!this.optionsList) return;

        var html = '';
        var locData = window.ID_LOCATIONS;

        if (this.currentLevel === 1) {
            Object.keys(locData).sort().forEach(function(prov) {
                var isSel = prov === self.selectedProv;
                html += '<div class="pki-loc-item ' + (isSel ? 'selected' : '') + '" data-val="' + prov + '">';
                html += '<div style="display:flex; align-items:center;"><span class="pki-radio-bullet"></span><span>' + prov + '</span></div>';
                html += '<i class="fa-solid fa-chevron-right" style="color:#94a3b8; font-size:11px;"></i></div>';
            });
        } else if (this.currentLevel === 2 && this.selectedProv && locData[this.selectedProv]) {
            Object.keys(locData[this.selectedProv]).sort().forEach(function(city) {
                var isSel = city === self.selectedCity;
                html += '<div class="pki-loc-item ' + (isSel ? 'selected' : '') + '" data-val="' + city + '">';
                html += '<div style="display:flex; align-items:center;"><span class="pki-radio-bullet"></span><span>' + city + '</span></div>';
                html += '<i class="fa-solid fa-chevron-right" style="color:#94a3b8; font-size:11px;"></i></div>';
            });
        } else if (this.currentLevel === 3 && this.selectedProv && this.selectedCity && locData[this.selectedProv]?.[this.selectedCity]) {
            Object.keys(locData[this.selectedProv][this.selectedCity]).sort().forEach(function(dist) {
                var isSel = dist === self.selectedDist;
                html += '<div class="pki-loc-item ' + (isSel ? 'selected' : '') + '" data-val="' + dist + '">';
                html += '<div style="display:flex; align-items:center;"><span class="pki-radio-bullet"></span><span>' + dist + '</span></div>';
                html += '<i class="fa-solid fa-chevron-right" style="color:#94a3b8; font-size:11px;"></i></div>';
            });
        } else if (this.currentLevel === 4 && this.selectedProv && this.selectedCity && this.selectedDist && locData[this.selectedProv]?.[this.selectedCity]?.[this.selectedDist]) {
            var vills = locData[this.selectedProv][this.selectedCity][this.selectedDist];
            Object.keys(vills).sort().forEach(function(vill) {
                var isSel = vill === self.selectedVill;
                var postal = vills[vill];
                html += '<div class="pki-loc-item ' + (isSel ? 'selected' : '') + '" data-val="' + vill + '" data-postal="' + postal + '">';
                html += '<div style="display:flex; align-items:center;"><span class="pki-radio-bullet"></span><span>' + vill + '</span></div>';
                html += '<span style="font-size:11.5px; color:#64748b; font-weight:600;">' + postal + '</span></div>';
            });
        }

        this.optionsList.innerHTML = html;

        this.optionsList.querySelectorAll('.pki-loc-item').forEach(function(el) {
            el.addEventListener('click', function() {
                var val = el.getAttribute('data-val');
                if (self.currentLevel === 1) {
                    self.selectedProv = val;
                    self.selectedCity = '';
                    self.selectedDist = '';
                    self.selectedVill = '';
                    self.currentLevel = 2;
                    self.render();
                } else if (self.currentLevel === 2) {
                    self.selectedCity = val;
                    self.selectedDist = '';
                    self.selectedVill = '';
                    self.currentLevel = 3;
                    self.render();
                } else if (self.currentLevel === 3) {
                    self.selectedDist = val;
                    self.selectedVill = '';
                    self.currentLevel = 4;
                    self.render();
                } else if (self.currentLevel === 4) {
                    self.selectedVill = val;
                    var postal = el.getAttribute('data-postal') || '';
                    self.applySelection(postal);
                }
            });
        });
    };

    HierarchicalLocationSelector.prototype.applySelection = function(postal) {
        if (this.inputProv) this.inputProv.value = this.selectedProv;
        if (this.inputCity) this.inputCity.value = this.selectedCity;
        if (this.inputDist) this.inputDist.value = this.selectedDist;
        if (this.inputVill) this.inputVill.value = this.selectedVill;

        if (this.displayText) {
            this.displayText.textContent = this.selectedVill + ', ' + this.selectedDist + ', ' + this.selectedCity + ', ' + this.selectedProv;
            this.displayText.classList.remove('placeholder');
        }

        // Update dropdown kode pos
        if (this.selectPostal) {
            this.selectPostal.innerHTML = '';
            if (postal) {
                var opt = document.createElement('option');
                opt.value = postal;
                opt.textContent = postal;
                opt.selected = true;
                this.selectPostal.appendChild(opt);
                this.selectPostal.disabled = false;
            } else {
                this.selectPostal.innerHTML = '<option value="">Pilih kode pos</option>';
            }
        }

        this.close();

        // Hapus error state jika ada
        var fieldWrapper = this.container.closest('.pki-field');
        if (fieldWrapper) {
            fieldWrapper.classList.remove('has-error');
        }

        if (typeof this.onChangeCallback === 'function') {
            this.onChangeCallback({
                province: this.selectedProv,
                city: this.selectedCity,
                district: this.selectedDist,
                village: this.selectedVill,
                postalCode: postal
            });
        }
    };

    HierarchicalLocationSelector.prototype.setLocation = function(prov, city, dist, vill, postal) {
        this.selectedProv = prov || '';
        this.selectedCity = city || '';
        this.selectedDist = dist || '';
        this.selectedVill = vill || '';

        if (this.inputProv) this.inputProv.value = this.selectedProv;
        if (this.inputCity) this.inputCity.value = this.selectedCity;
        if (this.inputDist) this.inputDist.value = this.selectedDist;
        if (this.inputVill) this.inputVill.value = this.selectedVill;

        if (this.displayText) {
            if (this.selectedVill && this.selectedDist && this.selectedCity && this.selectedProv) {
                this.displayText.textContent = this.selectedVill + ', ' + this.selectedDist + ', ' + this.selectedCity + ', ' + this.selectedProv;
                this.displayText.classList.remove('placeholder');
            } else {
                this.displayText.textContent = this.placeholderText;
                this.displayText.classList.add('placeholder');
            }
        }

        if (this.selectPostal) {
            if (postal) {
                this.selectPostal.innerHTML = '<option value="' + postal + '" selected>' + postal + '</option>';
                this.selectPostal.disabled = false;
            } else {
                this.selectPostal.innerHTML = '<option value="">Pilih kode pos</option>';
            }
        }
    };

    // 3. INISIALISASI FORM KOMPONEN
    var domSelector = null;
    var workSelector = null;

    function initForm() {
        // Inisialisasi Domisili Selector
        domSelector = new HierarchicalLocationSelector({
            containerId: 'pki_dom_loc_container',
            displayId: 'pki_dom_loc_display',
            textId: 'pki_dom_loc_text',
            caretId: 'pki_dom_loc_caret',
            popupId: 'pki_dom_loc_popup',
            breadcrumbsId: 'pki_dom_breadcrumbs',
            optionsListId: 'pki_dom_options_list',
            inputProvId: 'pki_domicile_province',
            inputCityId: 'pki_domicile_city',
            inputDistId: 'pki_domicile_district',
            inputVillId: 'pki_domicile_village',
            selectPostalId: 'pki_domicile_postal_code',
            placeholderText: 'Pilih lokasi domisili',
            onChange: function(loc) {
                var cbSame = document.getElementById('pki_cb_same_as_domicile');
                if (cbSame && cbSame.checked && workSelector) {
                    syncDomicileToWorkplace();
                }
                updateGoogleMaps();
            }
        });

        // Inisialisasi Tempat Usaha Selector
        workSelector = new HierarchicalLocationSelector({
            containerId: 'pki_work_loc_container',
            displayId: 'pki_work_loc_display',
            textId: 'pki_work_loc_text',
            caretId: 'pki_work_loc_caret',
            popupId: 'pki_work_loc_popup',
            breadcrumbsId: 'pki_work_breadcrumbs',
            optionsListId: 'pki_work_options_list',
            inputProvId: 'pki_workplace_province',
            inputCityId: 'pki_workplace_city',
            inputDistId: 'pki_workplace_district',
            inputVillId: 'pki_workplace_village',
            selectPostalId: 'pki_workplace_postal_code',
            placeholderText: 'Pilih lokasi tempat usaha / kegiatan',
            onChange: function(loc) {
                updateGoogleMaps();
            }
        });

        // Setup Checkbox "Alamat tempat usaha/kegiatan sama dengan alamat domisili di SIAPKerja"
        var cbSame = document.getElementById('pki_cb_same_as_domicile');
        var sameNotice = document.getElementById('pki_same_domicile_notice');
        var manualWrap = document.getElementById('pki_workplace_manual_container');

        if (cbSame) {
            cbSame.addEventListener('change', function() {
                if (this.checked) {
                    if (sameNotice) sameNotice.style.display = 'block';
                    if (manualWrap) manualWrap.style.display = 'none';
                    syncDomicileToWorkplace();
                } else {
                    if (sameNotice) sameNotice.style.display = 'none';
                    if (manualWrap) manualWrap.style.display = 'block';
                }
                updateGoogleMaps();
            });
        }

        // Listen input alamat untuk trigger Google Maps otomatis
        var domAddrInput = document.getElementById('pki_domicile_address');
        var workAddrInput = document.getElementById('pki_workplace_address');

        if (domAddrInput) {
            domAddrInput.addEventListener('input', function() {
                clearFieldError('field_domicile_address');
                var cbSame = document.getElementById('pki_cb_same_as_domicile');
                if (cbSame && cbSame.checked) {
                    if (workAddrInput) workAddrInput.value = this.value;
                }
                updateGoogleMaps();
            });
        }

        if (workAddrInput) {
            workAddrInput.addEventListener('input', function() {
                clearFieldError('field_workplace_address');
                updateGoogleMaps();
            });
        }

        // NIK input sanitasi (hanya angka)
        var nikInput = document.getElementById('pki_nik');
        if (nikInput) {
            nikInput.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '').slice(0, 16);
                if (this.value.length === 16) {
                    clearFieldError('field_nik');
                }
            });
        }

        // Clear error listeners pada interaksi field lainnya
        setupFieldClearListeners();

        // Setup Tooltip Toggles (Hover, Focus, Click)
        setupTooltipPopovers();

        // Setup File Upload Listeners
        setupUploadListeners();

        // Initial sync jika same as domicile dicentang
        if (cbSame && cbSame.checked) {
            syncDomicileToWorkplace();
        }

        // Initial map check
        updateGoogleMaps();
    }

    function syncDomicileToWorkplace() {
        var domProv = document.getElementById('pki_domicile_province')?.value || '';
        var domCity = document.getElementById('pki_domicile_city')?.value || '';
        var domDist = document.getElementById('pki_domicile_district')?.value || '';
        var domVill = document.getElementById('pki_domicile_village')?.value || '';
        var domPostal = document.getElementById('pki_domicile_postal_code')?.value || '';
        var domAddress = document.getElementById('pki_domicile_address')?.value || '';

        if (workSelector) {
            workSelector.setLocation(domProv, domCity, domDist, domVill, domPostal);
        }

        var workAddrInput = document.getElementById('pki_workplace_address');
        if (workAddrInput) {
            workAddrInput.value = domAddress;
        }

        var workPostalSelect = document.getElementById('pki_workplace_postal_code');
        if (workPostalSelect && domPostal) {
            workPostalSelect.innerHTML = '<option value="' + domPostal + '" selected>' + domPostal + '</option>';
            workPostalSelect.disabled = false;
        }
    }

    // 4. GOOGLE MAPS PREVIEW CONTROLLER (READ-ONLY, NO LEAFLET, TIDAK HARDCODE API KEY)
    function updateGoogleMaps() {
        var cbSame = document.getElementById('pki_cb_same_as_domicile');
        var isSame = cbSame ? cbSame.checked : true;

        var prov = isSame 
            ? document.getElementById('pki_domicile_province')?.value 
            : document.getElementById('pki_workplace_province')?.value;
        var city = isSame 
            ? document.getElementById('pki_domicile_city')?.value 
            : document.getElementById('pki_workplace_city')?.value;
        var dist = isSame 
            ? document.getElementById('pki_domicile_district')?.value 
            : document.getElementById('pki_workplace_district')?.value;
        var vill = isSame 
            ? document.getElementById('pki_domicile_village')?.value 
            : document.getElementById('pki_workplace_village')?.value;
        var address = isSame 
            ? document.getElementById('pki_domicile_address')?.value?.trim() 
            : document.getElementById('pki_workplace_address')?.value?.trim();

        var placeholder = document.getElementById('pki_gmap_placeholder');
        var frameBox = document.getElementById('pki_gmap_frame_box');
        var iframe = document.getElementById('pki_gmap_iframe');
        var openBtn = document.getElementById('pki_gmap_open_btn');

        if (!placeholder || !frameBox || !iframe) return;

        // Peta HANYA muncul jika lokasi dan alamat lengkap tersedia
        if ((vill || city) && address && address.length >= 3) {
            var fullLocationQuery = address + ', ' + (vill ? vill + ', ' : '') + (dist ? dist + ', ' : '') + (city ? city + ', ' : '') + (prov ? prov + ', ' : '') + 'Indonesia';
            var encodedQuery = encodeURIComponent(fullLocationQuery);

            iframe.src = 'https://maps.google.com/maps?q=' + encodedQuery + '&hl=id&z=15&output=embed';
            if (openBtn) {
                openBtn.href = 'https://www.google.com/maps/search/?api=1&query=' + encodedQuery;
            }

            placeholder.style.display = 'none';
            frameBox.style.display = 'block';

            // Sync legacy lat/lng jika ada kota
            var cityCoords = {
                'Kota Bandung': ['-6.9175', '107.6191'],
                'Kota Bekasi': ['-6.2383', '106.9756'],
                'Kota Jakarta Selatan': ['-6.2615', '106.8106'],
                'Kota Jakarta Pusat': ['-6.1805', '106.8284'],
                'Kota Jakarta Barat': ['-6.1683', '106.7588'],
                'Kota Depok': ['-6.4025', '106.7942'],
                'Kota Bogor': ['-6.5971', '106.8060'],
                'Kota Semarang': ['-6.9667', '110.4167'],
                'Kota Surakarta': ['-7.5755', '110.8243'],
                'Kota Yogyakarta': ['-7.7956', '110.3695'],
                'Kota Surabaya': ['-7.2575', '112.7521'],
                'Kota Medan': ['3.5952', '98.6722']
            };
            if (city && cityCoords[city]) {
                var latEl = document.getElementById('pki_input_lat');
                var lngEl = document.getElementById('pki_input_lng');
                if (latEl) latEl.value = cityCoords[city][0];
                if (lngEl) lngEl.value = cityCoords[city][1];
            }
        } else {
            iframe.src = '';
            frameBox.style.display = 'none';
            placeholder.style.display = 'flex';
        }
    }

    // 5. TOOLTIPS POPOVER (HOVER, FOCUS, CLICK TANPA MENGGESER LAYOUT)
    function setupTooltipPopovers() {
        document.querySelectorAll('.pki-tooltip-wrap').forEach(function(wrap) {
            var btn = wrap.querySelector('.pki-tooltip-btn');
            if (!btn) return;

            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                // Toggle active state
                var wasActive = wrap.classList.contains('active');
                document.querySelectorAll('.pki-tooltip-wrap.active').forEach(function(w) {
                    w.classList.remove('active');
                });
                if (!wasActive) {
                    wrap.classList.add('active');
                }
            });
        });

        document.addEventListener('click', function() {
            document.querySelectorAll('.pki-tooltip-wrap.active').forEach(function(w) {
                w.classList.remove('active');
            });
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.pki-tooltip-wrap.active').forEach(function(w) {
                    w.classList.remove('active');
                });
            }
        });
    }

    // 6. FILE UPLOAD CONTROLLER
    window.triggerPkiUpload = function(type) {
        var inputId = type === 'permit' ? 'pki_input_permit_document' : 'pki_input_workplace_photo';
        var fileInput = document.getElementById(inputId);
        if (fileInput) {
            fileInput.click();
        }
    };

    window.removePkiFile = function(type) {
        if (type === 'permit') {
            var input = document.getElementById('pki_input_permit_document');
            var existing = document.getElementById('pki_existing_permit_document');
            var card = document.getElementById('pki_card_permit_document');
            var box = document.getElementById('pki_box_permit_document');
            if (input) input.value = '';
            if (existing) existing.value = '';
            if (card) card.style.display = 'none';
            if (box) box.style.display = 'flex';
        } else if (type === 'photo') {
            var input = document.getElementById('pki_input_workplace_photo');
            var existing = document.getElementById('pki_existing_workplace_photo');
            var card = document.getElementById('pki_card_workplace_photo');
            var box = document.getElementById('pki_box_workplace_photo');
            if (input) input.value = '';
            if (existing) existing.value = '';
            if (card) card.style.display = 'none';
            if (box) box.style.display = 'flex';
        }
    };

    function setupUploadListeners() {
        var permitInput = document.getElementById('pki_input_permit_document');
        if (permitInput) {
            permitInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    var file = this.files[0];
                    if (file.size > 15 * 1024 * 1024) {
                        alert('Ukuran berkas melebihi batas maksimal 15MB.');
                        this.value = '';
                        return;
                    }
                    var card = document.getElementById('pki_card_permit_document');
                    var box = document.getElementById('pki_box_permit_document');
                    var nameEl = document.getElementById('pki_name_permit_document');
                    var subEl = document.getElementById('pki_sub_permit_document');

                    if (nameEl) nameEl.textContent = file.name;
                    if (subEl) subEl.textContent = (file.size / (1024 * 1024)).toFixed(2) + ' MB • Format PDF';
                    if (card) card.style.display = 'flex';
                    if (box) box.style.display = 'none';

                    clearFieldError('field_permit_document');
                }
            });
        }

        var photoInput = document.getElementById('pki_input_workplace_photo');
        if (photoInput) {
            photoInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    var file = this.files[0];
                    var card = document.getElementById('pki_card_workplace_photo');
                    var box = document.getElementById('pki_box_workplace_photo');
                    var nameEl = document.getElementById('pki_name_workplace_photo');
                    var subEl = document.getElementById('pki_sub_workplace_photo');

                    if (nameEl) nameEl.textContent = file.name;
                    if (subEl) subEl.textContent = (file.size / (1024 * 1024)).toFixed(2) + ' MB • Gambar Foto';
                    if (card) card.style.display = 'flex';
                    if (box) box.style.display = 'none';

                    clearFieldError('field_workplace_photo');
                }
            });
        }
    }

    // 7. VALIDASI FORM DAN ERROR STATE KHUSUS
    // Aturan wajib: Label berubah merah, helper/error text merah tepat di bawah field,
    // BORDER INPUT/CONTROL TETAP NORMAL DAN TIDAK BERUBAH MERAH!
    function setFieldError(fieldId, errorMsg) {
        var field = document.getElementById(fieldId);
        if (!field) return;

        field.classList.add('has-error');
        var errText = field.querySelector('.pki-error-text');
        if (errText) {
            if (errorMsg) errText.textContent = errorMsg;
            errText.style.display = 'block';
        }
    }

    function clearFieldError(fieldId) {
        var field = document.getElementById(fieldId);
        if (!field) return;

        field.classList.remove('has-error');
        var errText = field.querySelector('.pki-error-text');
        if (errText) {
            errText.style.display = 'none';
        }
    }

    function setupFieldClearListeners() {
        var mapInputs = [
            { id: 'pki_owner_name', fieldId: 'field_owner_name', evt: 'input' },
            { id: 'pki_phone', fieldId: 'field_phone', evt: 'input' },
            { id: 'pki_whatsapp', fieldId: 'field_whatsapp', evt: 'input' },
            { id: 'pki_profession', fieldId: 'field_profession', evt: 'change' },
            { id: 'pki_npwp', fieldId: 'field_npwp', evt: 'input' },
            { id: 'pki_domicile_postal_code', fieldId: 'field_domicile_postal_code', evt: 'change' },
            { id: 'pki_workplace_postal_code', fieldId: 'field_workplace_postal_code', evt: 'change' },
            { id: 'pki_user_consent', fieldId: 'field_user_consent', evt: 'change' }
        ];

        mapInputs.forEach(function(item) {
            var el = document.getElementById(item.id);
            if (el) {
                el.addEventListener(item.evt, function() {
                    clearFieldError(item.fieldId);
                });
            }
        });
    }

    // 8. SUBMIT VALIDATION HANDLER
    var form = document.getElementById('<?php echo $formId; ?>');
    if (form) {
        form.addEventListener('submit', function(e) {
            var hasErrors = false;
            var firstErrorEl = null;

            // 1. Identitas Perorangan
            var ownerName = document.getElementById('pki_owner_name')?.value?.trim();
            if (!ownerName) {
                setFieldError('field_owner_name', 'Nama Pemberi Kerja wajib diisi.');
                hasErrors = true;
                firstErrorEl = firstErrorEl || document.getElementById('pki_owner_name');
            }

            var nik = document.getElementById('pki_nik')?.value?.trim();
            if (!nik || nik.length !== 16 || !/^\d{16}$/.test(nik)) {
                setFieldError('field_nik', 'NIK harus terdiri dari 16 digit angka.');
                hasErrors = true;
                firstErrorEl = firstErrorEl || document.getElementById('pki_nik');
            }

            var phone = document.getElementById('pki_phone')?.value?.trim();
            if (!phone) {
                setFieldError('field_phone', 'Nomor telepon aktif wajib diisi.');
                hasErrors = true;
                firstErrorEl = firstErrorEl || document.getElementById('pki_phone');
            }

            var whatsapp = document.getElementById('pki_whatsapp')?.value?.trim();
            if (!whatsapp) {
                setFieldError('field_whatsapp', 'Nomor WhatsApp aktif wajib diisi.');
                hasErrors = true;
                firstErrorEl = firstErrorEl || document.getElementById('pki_whatsapp');
            }

            var profession = document.getElementById('pki_profession')?.value?.trim();
            if (!profession) {
                setFieldError('field_profession', 'Pilih industri atau sektor yang paling sesuai.');
                hasErrors = true;
                firstErrorEl = firstErrorEl || document.getElementById('pki_profession');
            }

            var npwp = document.getElementById('pki_npwp')?.value?.trim();
            var cleanNpwp = npwp ? npwp.replace(/\D/g, '') : '';
            if (!npwp || (cleanNpwp.length !== 15 && cleanNpwp.length !== 16)) {
                setFieldError('field_npwp', 'NPWP harus terdiri dari 15 atau 16 digit.');
                hasErrors = true;
                firstErrorEl = firstErrorEl || document.getElementById('pki_npwp');
            }

            // 2. Alamat Domisili
            var domVill = document.getElementById('pki_domicile_village')?.value;
            if (!domVill) {
                setFieldError('field_domicile_location', 'Lokasi domisili wajib dipilih sampai Kelurahan/Desa.');
                hasErrors = true;
                firstErrorEl = firstErrorEl || document.getElementById('pki_dom_loc_display');
            }

            var domAddress = document.getElementById('pki_domicile_address')?.value?.trim();
            if (!domAddress) {
                setFieldError('field_domicile_address', 'Alamat lengkap domisili wajib diisi.');
                hasErrors = true;
                firstErrorEl = firstErrorEl || document.getElementById('pki_domicile_address');
            }

            var domPostal = document.getElementById('pki_domicile_postal_code')?.value;
            if (!domPostal) {
                setFieldError('field_domicile_postal_code', 'Kode pos domisili wajib dipilih.');
                hasErrors = true;
                firstErrorEl = firstErrorEl || document.getElementById('pki_domicile_postal_code');
            }

            // 3. Tempat Usaha
            var cbSame = document.getElementById('pki_cb_same_as_domicile');
            if (cbSame && cbSame.checked) {
                // Otomatis salin domisili ke tempat usaha
                syncDomicileToWorkplace();
            } else {
                var workVill = document.getElementById('pki_workplace_village')?.value;
                if (!workVill) {
                    setFieldError('field_workplace_location', 'Lokasi tempat usaha wajib dipilih sampai Kelurahan/Desa.');
                    hasErrors = true;
                    firstErrorEl = firstErrorEl || document.getElementById('pki_work_loc_display');
                }

                var workAddress = document.getElementById('pki_workplace_address')?.value?.trim();
                if (!workAddress) {
                    setFieldError('field_workplace_address', 'Alamat lengkap tempat usaha wajib diisi.');
                    hasErrors = true;
                    firstErrorEl = firstErrorEl || document.getElementById('pki_workplace_address');
                }

                var workPostal = document.getElementById('pki_workplace_postal_code')?.value;
                if (!workPostal) {
                    setFieldError('field_workplace_postal_code', 'Kode pos tempat usaha wajib dipilih.');
                    hasErrors = true;
                    firstErrorEl = firstErrorEl || document.getElementById('pki_workplace_postal_code');
                }
            }

            // 4. File & Bukti Tempat Usaha (Wajib minimal 1 masing-masing)
            var permitInput = document.getElementById('pki_input_permit_document');
            var existingPermit = document.getElementById('pki_existing_permit_document')?.value;
            var hasPermit = (permitInput && permitInput.files && permitInput.files.length > 0) || (existingPermit && existingPermit !== '');
            if (!hasPermit) {
                setFieldError('field_permit_document', 'File Pendukung wajib diunggah minimal 1 file (Format PDF, maks 15MB).');
                hasErrors = true;
                firstErrorEl = firstErrorEl || document.getElementById('pki_box_permit_document');
            }

            var photoInput = document.getElementById('pki_input_workplace_photo');
            var existingPhoto = document.getElementById('pki_existing_workplace_photo')?.value;
            var hasPhoto = (photoInput && photoInput.files && photoInput.files.length > 0) || (existingPhoto && existingPhoto !== '');
            if (!hasPhoto) {
                setFieldError('field_workplace_photo', 'Foto Bukti Tempat Usaha / Lokasi wajib diunggah minimal 1 foto.');
                hasErrors = true;
                firstErrorEl = firstErrorEl || document.getElementById('pki_box_workplace_photo');
            }

            // 6. Pernyataan (Checkbox wajib disetujui)
            var consentCb = document.getElementById('pki_user_consent');
            if (!consentCb || !consentCb.checked) {
                setFieldError('field_user_consent', 'Pernyataan persetujuan wajib dicentang sebelum mengajukan profil.');
                hasErrors = true;
                firstErrorEl = firstErrorEl || document.getElementById('pki_user_consent');
            }

            if (hasErrors) {
                e.preventDefault();
                if (firstErrorEl) {
                    firstErrorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    if (typeof firstErrorEl.focus === 'function') {
                        firstErrorEl.focus();
                    }
                }
                return false;
            }

            // Update legacy synchronization fields before submit
            var finalProv = document.getElementById('pki_workplace_province')?.value || document.getElementById('pki_domicile_province')?.value || '';
            var finalCity = document.getElementById('pki_workplace_city')?.value || document.getElementById('pki_domicile_city')?.value || '';
            var finalDist = document.getElementById('pki_workplace_district')?.value || document.getElementById('pki_domicile_district')?.value || '';
            var finalVill = document.getElementById('pki_workplace_village')?.value || document.getElementById('pki_domicile_village')?.value || '';
            var finalPostal = document.getElementById('pki_workplace_postal_code')?.value || document.getElementById('pki_domicile_postal_code')?.value || '';
            var finalAddr = document.getElementById('pki_workplace_address')?.value || document.getElementById('pki_domicile_address')?.value || '';
            var finalDetail = document.getElementById('pki_workplace_detail')?.value || '';

            var legProv = document.getElementById('pki_legacy_province');
            var legCity = document.getElementById('pki_legacy_city');
            var legDist = document.getElementById('pki_legacy_district');
            var legVill = document.getElementById('pki_legacy_village');
            var legCityId = document.getElementById('pki_legacy_domicile_city_id');
            var legPostal = document.getElementById('pki_legacy_postal_code');
            var legAddr = document.getElementById('pki_legacy_address');
            var legDetail = document.getElementById('pki_legacy_address_detail');

            if (legProv) legProv.value = finalProv;
            if (legCity) legCity.value = finalCity;
            if (legDist) legDist.value = finalDist;
            if (legVill) legVill.value = finalVill;
            if (legCityId) legCityId.value = finalCity;
            if (legPostal) legPostal.value = finalPostal;
            if (legAddr) legAddr.value = finalAddr;
            if (legDetail) legDetail.value = finalDetail;
        });
    }

    // Jalankan initForm saat DOM siap
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initForm);
    } else {
        initForm();
    }
})();
</script>
