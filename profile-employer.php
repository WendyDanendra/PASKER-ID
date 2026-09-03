<?php
require __DIR__ . '/includes/bootstrap.php';

$user = require_role('employer');

$statement = db()->prepare('SELECT * FROM employer_profiles WHERE user_id = ? LIMIT 1');
$statement->execute([$user['id']]);
$profile = $statement->fetch() ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ownerName   = trim($_POST['owner_name']   ?? '');
    $profession  = trim($_POST['profession']   ?? '');
    $phone       = trim($_POST['phone']        ?? '');
    $province    = trim($_POST['province']     ?? '');
    $city        = trim($_POST['city']         ?? '');
    $address     = trim($_POST['address']      ?? '');
    $description = trim($_POST['description']  ?? '');

    if ($ownerName === '' || $profession === '' || $phone === '' || $province === '' || $city === '' || $address === '') {
        flash('error', 'Lengkapi semua field wajib untuk melanjutkan.');
        redirect('profile-employer.php');
    }

    if ($profile) {
        // Update: reset verified ke 0 agar admin verifikasi ulang jika ada perubahan data
        $update = db()->prepare('UPDATE employer_profiles SET owner_name = ?, profession = ?, phone = ?, province = ?, city = ?, address = ?, description = ?, verified = 0, updated_at = NOW() WHERE user_id = ?');
        $update->execute([$ownerName, $profession, $phone, $province, $city, $address, $description, $user['id']]);
    } else {
        // Insert baru: active_until di-set 3 bulan ke depan agar tidak NULL dan tidak crash di dashboard
        $insert = db()->prepare('INSERT INTO employer_profiles (user_id, owner_name, profession, phone, province, city, address, description, verified, active_until) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, DATE_ADD(NOW(), INTERVAL 3 MONTH))');
        $insert->execute([$user['id'], $ownerName, $profession, $phone, $province, $city, $address, $description]);
    }

    $updateUser = db()->prepare('UPDATE users SET name = ?, profile_complete = 1 WHERE id = ?');
    $updateUser->execute([$ownerName, $user['id']]);

    flash('success', 'Profil berhasil disimpan! Silakan tunggu verifikasi dari Admin sebelum dapat memposting lowongan.');
    redirect('dashboard.php');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lengkapi Profil - <?php echo APP_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: radial-gradient(circle at top left, #e8f7fd 0%, #f0f4f8 40%, #f6f7fb 100%);
            min-height: 100vh;
            color: #1f2937;
        }
        a { text-decoration: none; }

        /* ─── Layout ─────────────────────────────── */
        .shell {
            min-height: 100vh;
            display: grid;
            grid-template-rows: auto 1fr;
        }

        /* ─── Topbar ─────────────────────────────── */
        .topbar {
            background: rgba(255,255,255,0.88);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid #e2e8f0;
            padding: 0 32px;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 20;
        }
        .topbar-brand {
            font-size: 20px;
            font-weight: 800;
            background: linear-gradient(135deg, #30aed8, #1e97c4);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.5px;
        }
        .topbar-right { display: flex; align-items: center; gap: 12px; }
        .logout-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 12px;
            border: 1.5px solid #e2e8f0;
            background: #fff;
            color: #475569;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .logout-btn:hover { border-color: #cbd5e1; background: #f8fafc; color: #0f172a; }

        /* ─── Main Content ───────────────────────── */
        .main-content {
            padding: 40px 24px 60px;
            max-width: 860px;
            margin: 0 auto;
            width: 100%;
        }

        /* ─── Progress Header ────────────────────── */
        .page-header { margin-bottom: 32px; }
        .page-header h1 {
            font-size: 28px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.5px;
            margin-bottom: 6px;
        }
        .page-header p { font-size: 15px; color: #64748b; }
        .steps-row {
            display: flex;
            align-items: center;
            gap: 0;
            margin-top: 20px;
        }
        .step-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            font-weight: 600;
            color: #94a3b8;
        }
        .step-bubble {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #e2e8f0;
            display: grid;
            place-items: center;
            font-size: 12px;
            font-weight: 700;
            color: #94a3b8;
            flex-shrink: 0;
        }
        .step-item.active { color: #30aed8; }
        .step-item.active .step-bubble { background: #30aed8; color: #fff; }
        .step-item.done { color: #21b36b; }
        .step-item.done .step-bubble { background: #21b36b; color: #fff; }
        .step-line {
            flex: 1;
            height: 2px;
            background: #e2e8f0;
            margin: 0 12px;
        }
        .step-line.done { background: #21b36b; }

        /* ─── Alert ──────────────────────────────── */
        .alert-box {
            border-radius: 14px;
            padding: 14px 16px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        .alert-box i { margin-top: 1px; flex-shrink: 0; }
        .alert-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

        /* ─── Notice Banner ──────────────────────── */
        .notice-banner {
            background: linear-gradient(135deg, #fffbeb, #fef9ee);
            border: 1px solid #fcd34d;
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }
        .notice-banner i { color: #f59e0b; margin-top: 2px; flex-shrink: 0; font-size: 18px; }
        .notice-banner strong { display: block; font-size: 14px; color: #92400e; margin-bottom: 4px; }
        .notice-banner p { font-size: 13px; color: #78350f; line-height: 1.6; }

        /* ─── Card ───────────────────────────────── */
        .form-card {
            background: rgba(255,255,255,0.95);
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(15,23,42,0.06);
            overflow: hidden;
        }
        .form-card-header {
            padding: 20px 24px;
            border-bottom: 1px solid #f1f5f9;
            background: linear-gradient(180deg, #fff 0%, #fbfdff 100%);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .form-card-header-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: linear-gradient(135deg, #d8f3fb, #eefcff);
            display: grid;
            place-items: center;
            color: #30aed8;
            font-size: 16px;
        }
        .form-card-header h2 { font-size: 16px; font-weight: 700; color: #0f172a; }
        .form-card-header p { font-size: 12px; color: #64748b; margin-top: 2px; }
        .form-card-body { padding: 24px; }

        /* ─── Fields ─────────────────────────────── */
        .field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }
        .field-grid .span2 { grid-column: span 2; }
        .field { display: flex; flex-direction: column; gap: 6px; }
        .field label {
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
        }
        .field label .req { color: #ef4444; margin-left: 2px; }
        .field input,
        .field select,
        .field textarea {
            width: 100%;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 14px;
            background: #f8fafc;
            color: #0f172a;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            outline: none;
            transition: all 0.25s;
        }
        .field textarea { min-height: 100px; resize: vertical; }
        .field input:focus,
        .field select:focus,
        .field textarea:focus {
            border-color: #30aed8;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(48,174,216,0.12);
        }
        .field input::placeholder,
        .field textarea::placeholder { color: #94a3b8; }

        /* ─── Footer Actions ─────────────────────── */
        .form-card-footer {
            padding: 16px 24px 20px;
            border-top: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .footer-hint { font-size: 12px; color: #94a3b8; }
        .footer-actions { display: flex; gap: 10px; }
        .btn-cancel {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 44px;
            padding: 0 20px;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            background: transparent;
            color: #475569;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-cancel:hover { border-color: #cbd5e1; background: #f8fafc; }
        .btn-submit {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 44px;
            padding: 0 24px;
            border-radius: 12px;
            border: none;
            background: linear-gradient(135deg, #30aed8 0%, #1e97c4 100%);
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 6px 20px rgba(48,174,216,0.25);
            transition: all 0.25s;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(48,174,216,0.35);
        }
        .btn-submit:active { transform: translateY(0); }

        @media (max-width: 640px) {
            .main-content { padding: 24px 16px 48px; }
            .field-grid { grid-template-columns: 1fr; }
            .field-grid .span2 { grid-column: span 1; }
            .topbar { padding: 0 16px; }
            .form-card-footer { flex-direction: column; align-items: flex-end; }
        }
    </style>
</head>
<body>
<div class="shell">
    <header class="topbar">
        <span class="topbar-brand">Karirhub</span>
        <div class="topbar-right">
            <a href="logout.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Keluar</a>
        </div>
    </header>

    <div class="main-content">
        <div class="page-header">
            <h1><?php echo $profile ? 'Perbarui Profil' : 'Lengkapi Profil'; ?></h1>
            <p>Isi data diri Anda sebagai pemberi kerja individu untuk melanjutkan ke dashboard.</p>
            <div class="steps-row">
                <div class="step-item done"><span class="step-bubble"><i class="fa-solid fa-check" style="font-size:10px"></i></span> Registrasi</div>
                <div class="step-line done"></div>
                <div class="step-item active"><span class="step-bubble">2</span> Profil Anda</div>
                <div class="step-line"></div>
                <div class="step-item"><span class="step-bubble">3</span> Verifikasi Admin</div>
            </div>
        </div>

        <?php if ($flash = get_flash()): ?>
            <div class="alert-box <?php echo $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>">
                <i class="fa-solid <?php echo $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <div class="notice-banner">
            <i class="fa-solid fa-shield-halved"></i>
            <div>
                <strong>Proses Verifikasi Admin</strong>
                <p>Setelah mengisi profil, data Anda akan ditinjau oleh Admin. Fitur posting lowongan baru dapat diakses setelah akun diverifikasi. Proses ini biasanya memakan waktu 1–2 hari kerja.</p>
            </div>
        </div>

        <form method="post">
            <div class="form-card">
                <div class="form-card-header">
                    <div class="form-card-header-icon"><i class="fa-solid fa-user-gear"></i></div>
                    <div>
                        <h2>Data Pemberi Kerja Individu</h2>
                        <p>Semua field bertanda <span style="color:#ef4444">*</span> wajib diisi.</p>
                    </div>
                </div>

                <div class="form-card-body">
                    <div class="field-grid">
                        <div class="field">
                            <label>Nama Lengkap / Nama Usaha <span class="req">*</span></label>
                            <input type="text" name="owner_name"
                                   value="<?php echo htmlspecialchars($profile['owner_name'] ?? $user['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="Contoh: Budi Santoso" required>
                        </div>
                        <div class="field">
                            <label>Bidang Profesi / Usaha <span class="req">*</span></label>
                            <input type="text" name="profession"
                                   value="<?php echo htmlspecialchars($profile['profession'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="Contoh: Kuliner, Jasa, Teknologi" required>
                        </div>
                        <div class="field">
                            <label>Nomor Telepon Aktif <span class="req">*</span></label>
                            <input type="text" name="phone"
                                   value="<?php echo htmlspecialchars($profile['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="Contoh: 08123456789" required>
                        </div>
                        <div class="field">
                            <label>Provinsi <span class="req">*</span></label>
                            <input type="text" name="province"
                                   value="<?php echo htmlspecialchars($profile['province'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="Contoh: Jawa Barat" required>
                        </div>
                        <div class="field">
                            <label>Kota / Kabupaten <span class="req">*</span></label>
                            <input type="text" name="city"
                                   value="<?php echo htmlspecialchars($profile['city'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="Contoh: Kota Bekasi" required>
                        </div>
                        <div class="field">
                            <label>Alamat Lengkap <span class="req">*</span></label>
                            <input type="text" name="address"
                                   value="<?php echo htmlspecialchars($profile['address'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="Contoh: Jl. Raya Bekasi No.10" required>
                        </div>
                        <div class="field span2">
                            <label>Deskripsi Singkat <small style="font-weight:400;color:#94a3b8">(opsional)</small></label>
                            <textarea name="description" placeholder="Ceritakan sedikit tentang usaha atau pekerjaan Anda..."><?php echo htmlspecialchars($profile['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="form-card-footer">
                    <span class="footer-hint"><i class="fa-solid fa-lock" style="color:#cbd5e1"></i> Data Anda aman dan hanya digunakan untuk proses verifikasi.</span>
                    <div class="footer-actions">
                        <a href="logout.php" class="btn-cancel">Batal</a>
                        <button type="submit" class="btn-submit">
                            <i class="fa-solid fa-paper-plane"></i>
                            <?php echo $profile ? 'Perbarui & Kirim Ulang' : 'Simpan & Kirim ke Admin'; ?>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
</body>
</html>
