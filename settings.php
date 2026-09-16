<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();

// Fetch Profile
$profileStatement = db()->prepare('SELECT * FROM employer_profiles WHERE user_id = ? LIMIT 1');
$profileStatement->execute([$user['id']]);
$employerProfile = $profileStatement->fetch() ?: [];

$seekerStatement = db()->prepare('SELECT * FROM seeker_profiles WHERE user_id = ? LIMIT 1');
$seekerStatement->execute([$user['id']]);
$seekerProfile = $seekerStatement->fetch() ?: [];

$displayName = $employerProfile['owner_name'] ?? ($seekerProfile['full_name'] ?? $user['name']);
$displayEmail = $user['email'];
$displayPhone = $employerProfile['phone'] ?? ($seekerProfile['phone'] ?? '-');

$initials = mb_strtoupper(mb_substr($displayName, 0, 1));
if (str_contains($displayName, ' ')) {
    $parts = explode(' ', $displayName);
    $initials = mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
}

$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'change_password';

    if ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!password_verify($currentPassword, $user['password_hash'])) {
            $errorMsg = 'Password sekarang yang Anda masukkan tidak sesuai.';
        } elseif (strlen($newPassword) < 8) {
            $errorMsg = 'Password baru harus memiliki minimal 8 karakter.';
        } elseif (!preg_match('/[a-z]/', $newPassword)) {
            $errorMsg = 'Password baru harus terdapat minimal satu huruf kecil.';
        } elseif (!preg_match('/[A-Z]/', $newPassword)) {
            $errorMsg = 'Password baru harus terdapat minimal satu huruf besar.';
        } elseif (!preg_match('/[0-9]/', $newPassword)) {
            $errorMsg = 'Password baru harus terdapat minimal satu angka.';
        } elseif (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $newPassword)) {
            $errorMsg = 'Password baru harus terdapat salah satu simbol (! @ # $ % ^ & *).';
        } elseif ($newPassword !== $confirmPassword) {
            $errorMsg = 'Konfirmasi password baru tidak cocok.';
        } else {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([$newHash, $user['id']]);
            $successMsg = 'Password berhasil diubah. Silakan gunakan password baru pada sesi login berikutnya.';
        }
    } elseif ($action === 'update_email') {
        $newEmail = trim($_POST['email'] ?? '');
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $errorMsg = 'Format email tidak valid.';
        } else {
            $stmt = db()->prepare('UPDATE users SET email = ? WHERE id = ?');
            $stmt->execute([$newEmail, $user['id']]);
            $displayEmail = $newEmail;
            $successMsg = 'Email berhasil diperbarui.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SIAPkerja ID - Pengaturan Akun</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', sans-serif;
        }

        body {
            background: #ffffff;
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* TOPBAR */
        .siap-topbar {
            height: 64px;
            background: #ffffff;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 40px;
        }

        .siap-brand {
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            color: #0f172a;
        }

        .siap-logo-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
        }

        .siap-brand-text {
            font-size: 19px;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .siap-brand-text .siap-green {
            color: #00a79d;
        }

        .siap-brand-text .siap-id {
            color: #00a79d;
            font-weight: 800;
        }

        .siap-top-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .siap-services-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }

        .siap-services-btn:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        .siap-user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #00a79d;
            color: #ffffff;
            font-weight: 700;
            font-size: 13px;
            display: grid;
            place-items: center;
            cursor: pointer;
            text-decoration: none;
        }

        /* MAIN CONTAINER */
        .siap-container {
            max-width: 1040px;
            width: 100%;
            margin: 48px auto;
            padding: 0 24px;
            display: grid;
            grid-template-columns: 220px 1fr;
            gap: 48px;
            flex: 1;
        }

        /* LEFT SIDEBAR */
        .siap-sidebar {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .siap-nav-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .siap-nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 18px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            color: #475569;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            background: transparent;
            transition: all 0.15s ease;
            text-align: left;
            width: 100%;
        }

        .siap-nav-item:hover {
            background: #f8fafc;
            color: #0f172a;
        }

        .siap-nav-item.active {
            border: 1px solid #14b8a6;
            background: #ffffff;
            color: #00a79d;
            font-weight: 600;
            box-shadow: 0 1px 4px rgba(0, 167, 157, 0.08);
        }

        .siap-nav-item i {
            font-size: 16px;
            width: 20px;
            text-align: center;
        }

        .siap-sidebar-footer {
            font-size: 12px;
            color: #94a3b8;
            padding: 24px 0 12px 6px;
        }

        /* MAIN CONTENT CARD */
        .siap-card-panel {
            background: #ffffff;
            border: 1px solid #f1f5f9;
            border-radius: 16px;
            box-shadow: 0 2px 16px rgba(15, 23, 42, 0.04);
            max-width: 520px;
            padding: 36px 36px 32px;
        }

        .siap-card-title {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
        }

        .siap-card-subtitle {
            font-size: 13px;
            color: #64748b;
            line-height: 1.45;
            margin-bottom: 28px;
        }

        /* ALERTS */
        .siap-alert {
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .siap-alert.success {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
        }

        .siap-alert.danger {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        /* FORM */
        .siap-form-group {
            margin-bottom: 22px;
        }

        .siap-form-label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 8px;
        }

        .siap-input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .siap-input {
            width: 100%;
            height: 44px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 0 42px 0 14px;
            font-size: 13.5px;
            color: #0f172a;
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .siap-input:focus {
            border-color: #00a79d;
            box-shadow: 0 0 0 3px rgba(0, 167, 157, 0.12);
        }

        .siap-input-toggle-eye {
            position: absolute;
            right: 12px;
            background: transparent;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 14px;
            display: grid;
            place-items: center;
            padding: 4px;
        }

        .siap-input-toggle-eye:hover {
            color: #475569;
        }

        /* PASSWORD CRITERIA CHECKLIST */
        .siap-criteria-list {
            margin: 12px 0 22px;
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .siap-criteria-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: #64748b;
            transition: color 0.15s ease;
        }

        .siap-criteria-item i {
            font-size: 13px;
            color: #cbd5e1;
            transition: color 0.15s ease;
        }

        .siap-criteria-item.valid {
            color: #059669;
        }

        .siap-criteria-item.valid i {
            color: #059669;
        }

        .siap-submit-btn {
            background: #00a79d;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            height: 42px;
            padding: 0 24px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.15s ease;
            margin-top: 6px;
        }

        .siap-submit-btn:hover {
            background: #0d9488;
        }

        /* TAB PANES */
        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
        }

        @media (max-width: 768px) {
            .siap-container {
                grid-template-columns: 1fr;
                gap: 24px;
                margin: 24px auto;
            }

            .siap-topbar {
                padding: 0 16px;
            }
        }
    </style>
</head>

<body>
    <!-- HEADER -->
    <header class="siap-topbar">
        <a href="dashboard.php" class="siap-brand">
            <div class="siap-logo-icon">
                <svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:28px;height:28px;">
                    <circle cx="28" cy="50" r="14" fill="#00a79d" />
                    <circle cx="50" cy="28" r="14" fill="#00a79d" />
                    <circle cx="72" cy="50" r="14" fill="#00a79d" />
                    <circle cx="50" cy="72" r="14" fill="#00a79d" />
                </svg>
            </div>
            <div class="siap-brand-text">
                <span class="siap-green">SIAP</span>kerja <span class="siap-id">ID</span>
            </div>
        </a>

        <div class="siap-top-actions">
            <a href="dashboard.php" class="siap-services-btn" title="Kembali ke Layanan Karirhub">
                <i class="fa-solid fa-table-cells"></i> Layanan
            </a>
            <a href="dashboard.php" class="siap-user-avatar" title="<?php echo htmlspecialchars($displayName); ?>">
                <?php echo $initials; ?>
            </a>
        </div>
    </header>

    <!-- MAIN CONTAINER -->
    <main class="siap-container">
        <!-- SIDEBAR NAVIGATION (SCREENSHOT 2) -->
        <aside class="siap-sidebar">
            <nav class="siap-nav-list">
                <button type="button" class="siap-nav-item" data-tab="profil" onclick="switchSiapTab('profil')">
                    <i class="fa-regular fa-user"></i>
                    <span>Lihat Profil</span>
                </button>
                <button type="button" class="siap-nav-item active" data-tab="password" onclick="switchSiapTab('password')">
                    <i class="fa-solid fa-lock"></i>
                    <span>Password</span>
                </button>
                <button type="button" class="siap-nav-item" data-tab="email" onclick="switchSiapTab('email')">
                    <i class="fa-regular fa-envelope"></i>
                    <span>Email</span>
                </button>
                <button type="button" class="siap-nav-item" data-tab="ponsel" onclick="switchSiapTab('ponsel')">
                    <i class="fa-solid fa-mobile-screen"></i>
                    <span>Ponsel</span>
                </button>
            </nav>

            <div class="siap-sidebar-footer">
                &copy; 2026 Kemnaker RI
            </div>
        </aside>

        <!-- MAIN CARD CONTENT -->
        <section class="siap-main-content">
            
            <!-- 1. TAB GANTI PASSWORD (EXACT MATCH SCREENSHOT 2) -->
            <div class="tab-pane active" id="pane-password">
                <div class="siap-card-panel">
                    <h1 class="siap-card-title">Ganti Password</h1>
                    <p class="siap-card-subtitle">Untuk mengganti password, kamu harus memasukkan passwordmu yang aktif sekarang.</p>

                    <?php if ($successMsg): ?>
                        <div class="siap-alert success">
                            <i class="fa-solid fa-circle-check"></i>
                            <span><?php echo htmlspecialchars($successMsg); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($errorMsg): ?>
                        <div class="siap-alert danger">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <span><?php echo htmlspecialchars($errorMsg); ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="settings.php">
                        <input type="hidden" name="action" value="change_password">

                        <!-- Password Sekarang -->
                        <div class="siap-form-group">
                            <label class="siap-form-label" for="current_password">Password sekarang</label>
                            <div class="siap-input-wrap">
                                <input type="password" id="current_password" name="current_password" class="siap-input" placeholder="Masukkan password saat ini" required>
                                <button type="button" class="siap-input-toggle-eye" onclick="togglePasswordVisibility('current_password', this)">
                                    <i class="fa-regular fa-eye-slash"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Password Baru -->
                        <div class="siap-form-group" style="margin-bottom: 0;">
                            <label class="siap-form-label" for="new_password">Password baru</label>
                            <div class="siap-input-wrap">
                                <input type="password" id="new_password" name="new_password" class="siap-input" placeholder="Masukkan password baru" onkeyup="checkPasswordCriteria(this.value)" required>
                                <button type="button" class="siap-input-toggle-eye" onclick="togglePasswordVisibility('new_password', this)">
                                    <i class="fa-regular fa-eye-slash"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Criteria Checklist -->
                        <div class="siap-criteria-list">
                            <div class="siap-criteria-item" id="crit-len">
                                <i class="fa-solid fa-circle-check"></i>
                                <span>Minimal 8 karakter</span>
                            </div>
                            <div class="siap-criteria-item" id="crit-lower">
                                <i class="fa-solid fa-circle-check"></i>
                                <span>Terdapat minimal satu huruf kecil</span>
                            </div>
                            <div class="siap-criteria-item" id="crit-upper">
                                <i class="fa-solid fa-circle-check"></i>
                                <span>Terdapat minimal satu huruf besar</span>
                            </div>
                            <div class="siap-criteria-item" id="crit-num">
                                <i class="fa-solid fa-circle-check"></i>
                                <span>Terdapat minimal satu angka</span>
                            </div>
                            <div class="siap-criteria-item" id="crit-sym">
                                <i class="fa-solid fa-circle-check"></i>
                                <span>Terdapat salah satu simbol: ! @ # $ % ^ & *</span>
                            </div>
                        </div>

                        <!-- Konfirmasi Password -->
                        <div class="siap-form-group">
                            <label class="siap-form-label" for="confirm_password">Konfirmasi password</label>
                            <div class="siap-input-wrap">
                                <input type="password" id="confirm_password" name="confirm_password" class="siap-input" placeholder="Konfirmasi password baru" required>
                                <button type="button" class="siap-input-toggle-eye" onclick="togglePasswordVisibility('confirm_password', this)">
                                    <i class="fa-regular fa-eye-slash"></i>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="siap-submit-btn">Ganti Password</button>
                    </form>
                </div>
            </div>

            <!-- 2. TAB LIHAT PROFIL -->
            <div class="tab-pane" id="pane-profil">
                <div class="siap-card-panel">
                    <h1 class="siap-card-title">Profil Akun</h1>
                    <p class="siap-card-subtitle">Data akun terdaftar di ekosistem SIAPkerja ID.</p>
                    <div style="display:flex; flex-direction:column; gap:16px; margin-bottom:24px;">
                        <div>
                            <span style="font-size:12px; color:#64748b;">Nama Lengkap</span>
                            <div style="font-size:15px; font-weight:700; color:#0f172a;"><?php echo htmlspecialchars($displayName); ?></div>
                        </div>
                        <div>
                            <span style="font-size:12px; color:#64748b;">Alamat Email</span>
                            <div style="font-size:15px; font-weight:600; color:#0f172a;"><?php echo htmlspecialchars($displayEmail); ?></div>
                        </div>
                        <div>
                            <span style="font-size:12px; color:#64748b;">Nomor Ponsel</span>
                            <div style="font-size:15px; font-weight:600; color:#0f172a;"><?php echo htmlspecialchars($displayPhone); ?></div>
                        </div>
                    </div>
                    <a href="dashboard.php" class="siap-submit-btn" style="display:inline-flex; align-items:center; text-decoration:none;">Kembali ke Dasbor Karirhub</a>
                </div>
            </div>

            <!-- 3. TAB EMAIL -->
            <div class="tab-pane" id="pane-email">
                <div class="siap-card-panel">
                    <h1 class="siap-card-title">Ubah Email</h1>
                    <p class="siap-card-subtitle">Email digunakan untuk menerima pemberitahuan penting dan akses masuk akun.</p>
                    <form method="post" action="settings.php">
                        <input type="hidden" name="action" value="update_email">
                        <div class="siap-form-group">
                            <label class="siap-form-label" for="email">Alamat Email Baru</label>
                            <input type="email" id="email" name="email" class="siap-input" value="<?php echo htmlspecialchars($displayEmail); ?>" required>
                        </div>
                        <button type="submit" class="siap-submit-btn">Simpan Email</button>
                    </form>
                </div>
            </div>

            <!-- 4. TAB PONSEL -->
            <div class="tab-pane" id="pane-ponsel">
                <div class="siap-card-panel">
                    <h1 class="siap-card-title">Nomor Ponsel</h1>
                    <p class="siap-card-subtitle">Nomor ponsel yang terhubung dengan akun SIAPkerja ID Anda.</p>
                    <div class="siap-form-group">
                        <label class="siap-form-label">Nomor Ponsel Saat Ini</label>
                        <input type="text" class="siap-input" value="<?php echo htmlspecialchars($displayPhone); ?>" disabled style="background:#f8fafc;">
                    </div>
                    <a href="dashboard.php" class="siap-submit-btn" style="display:inline-flex; align-items:center; text-decoration:none;">Kelola di Dasbor</a>
                </div>
            </div>

        </section>
    </main>

    <script>
        function togglePasswordVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            if (!input) return;
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            const icon = btn.querySelector('i');
            if (icon) {
                icon.className = isPassword ? 'fa-regular fa-eye' : 'fa-regular fa-eye-slash';
            }
        }

        function checkPasswordCriteria(val) {
            const critLen = document.getElementById('crit-len');
            const critLower = document.getElementById('crit-lower');
            const critUpper = document.getElementById('crit-upper');
            const critNum = document.getElementById('crit-num');
            const critSym = document.getElementById('crit-sym');

            critLen.classList.toggle('valid', val.length >= 8);
            critLower.classList.toggle('valid', /[a-z]/.test(val));
            critUpper.classList.toggle('valid', /[A-Z]/.test(val));
            critNum.classList.toggle('valid', /[0-9]/.test(val));
            critSym.classList.toggle('valid', /[!@#$%^&*(),.?":{}|<>]/.test(val));
        }

        function switchSiapTab(tabName) {
            document.querySelectorAll('.siap-nav-item').forEach(b => {
                b.classList.toggle('active', b.dataset.tab === tabName);
            });
            document.querySelectorAll('.tab-pane').forEach(p => {
                p.classList.toggle('active', p.id === 'pane-' + tabName);
            });
        }
    </script>
</body>

</html>
