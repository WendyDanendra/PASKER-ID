<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    $user = find_user_by_email($email);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $error = 'Email atau password salah.';
    } else {
        login_user($user);

        if (in_array($user['role'] ?? '', ['admin', 'admin_dinas', 'admin_pusat'], true)) {
            redirect('admin.php');
        }

        if (($user['role'] ?? '') === 'seeker') {
            redirect(is_profile_complete($user) ? 'seeker.php' : 'profile-seeker.php');
        }

        redirect(is_profile_complete($user) ? 'dashboard.php' : 'profile-employer.php');
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk - Karirhub Kemnaker</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 50%, #f8fafc 100%);
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            box-sizing: border-box;
        }

        .auth-container {
            width: 100%;
            max-width: 1040px;
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(2, 132, 199, 0.18), 0 0 0 1px rgba(226, 232, 240, 0.8);
            display: grid;
            grid-template-columns: 1.1fr 1fr;
            overflow: hidden;
            min-height: 600px;
        }

        .auth-visual-side {
            background: linear-gradient(145deg, #0284c7 0%, #0369a1 100%);
            padding: 44px;
            color: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }

        .auth-visual-side::before {
            content: '';
            position: absolute;
            top: -100px;
            right: -100px;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            pointer-events: none;
        }

        .auth-visual-side::after {
            content: '';
            position: absolute;
            bottom: -80px;
            left: -80px;
            width: 250px;
            height: 250px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.06);
            pointer-events: none;
        }

        .logo-wrapper {
            background: #ffffff;
            padding: 16px 24px;
            border-radius: 16px;
            display: inline-block;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15);
            max-width: 280px;
        }

        .logo-wrapper img {
            height: 46px;
            width: auto;
            display: block;
        }

        .visual-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(8px);
            padding: 8px 16px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            color: #ffffff;
            margin-bottom: 24px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .visual-title {
            font-size: 28px;
            font-weight: 800;
            line-height: 1.35;
            margin: 0 0 14px 0;
            letter-spacing: -0.5px;
        }

        .visual-desc {
            font-size: 14px;
            line-height: 1.6;
            color: #e0f2fe;
            margin: 0 0 24px 0;
        }

        .demo-box {
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            padding: 20px;
            font-size: 13px;
        }

        .demo-box-header {
            font-weight: 700;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #ffffff;
        }

        .demo-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .demo-item:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateX(4px);
        }

        .demo-item span {
            font-weight: 600;
            color: #f0f9ff;
        }

        .demo-item code {
            background: rgba(0, 0, 0, 0.25);
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11.5px;
            color: #7dd3fc;
            font-family: monospace;
        }

        .auth-form-side {
            padding: 48px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: #ffffff;
        }

        .form-header h2 {
            font-size: 26px;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 6px 0;
        }

        .form-header p {
            font-size: 13.5px;
            color: #64748b;
            margin: 0 0 28px 0;
            line-height: 1.5;
        }

        .input-group {
            margin-bottom: 20px;
        }

        .input-group label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 6px;
        }

        .input-field-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-field-wrap i.prefix-icon {
            position: absolute;
            left: 14px;
            color: #94a3b8;
            font-size: 14px;
        }

        .input-field-wrap input {
            width: 100%;
            padding: 12px 14px 12px 42px;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            color: #0f172a;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
            box-sizing: border-box;
        }

        .input-field-wrap input:focus {
            border-color: #0284c7;
            box-shadow: 0 0 0 4px rgba(2, 132, 199, 0.12);
        }

        .toggle-password {
            position: absolute;
            right: 14px;
            color: #94a3b8;
            cursor: pointer;
            font-size: 14px;
        }

        .toggle-password:hover {
            color: #0284c7;
        }

        .btn-submit {
            width: 100%;
            height: 46px;
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            border: none;
            border-radius: 10px;
            color: #ffffff;
            font-weight: 700;
            font-size: 14px;
            font-family: inherit;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.3);
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(2, 132, 199, 0.4);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .btn-secondary-link {
            display: block;
            text-align: center;
            margin-top: 18px;
            font-size: 13px;
            color: #64748b;
            text-decoration: none;
        }

        .btn-secondary-link a {
            color: #0284c7;
            font-weight: 700;
            text-decoration: none;
        }

        .btn-secondary-link a:hover {
            text-decoration: underline;
        }

        @media (max-width: 860px) {
            .auth-container {
                grid-template-columns: 1fr;
            }
            .auth-visual-side {
                padding: 32px 24px;
            }
            .auth-form-side {
                padding: 32px 24px;
            }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <!-- LEFT VISUAL SIDE -->
        <div class="auth-visual-side">
            <div>
                <div class="visual-badge">
                    <i class="fa-solid fa-id-card"></i>
                    Otentikasi Akun SIAPkerja
                </div>

                <div class="logo-wrapper" style="margin-bottom: 24px;">
                    <img src="assets/logo-karirhub.png" alt="Karirhub oleh Kemnaker">
                </div>

                <h1 class="visual-title">Layanan Pemberi Kerja Individu</h1>
                <p class="visual-desc">
                    Terintegrasi dengan sistem identitas tunggal Akun SIAPkerja Kementerian Ketenagakerjaan Republik Indonesia.
                </p>
            </div>

            <?php if (is_demo_env()): ?>
            <div class="demo-box">
                <div class="demo-box-header">
                    <span><i class="fa-solid fa-key" style="margin-right:6px;"></i> Klik Akun Demo (Simulasi)</span>
                    <small style="opacity:0.8;">Pre-fill Otomatis</small>
                </div>
                <div class="demo-item" onclick="fillCredential('perorangan@paskerid.test', 'password')">
                    <span>Pemberi Kerja Individu</span>
                    <code>perorangan@paskerid.test</code>
                </div>
                <div class="demo-item" onclick="fillCredential('seeker@paskerid.test', 'password')">
                    <span>Pencari Kerja</span>
                    <code>seeker@paskerid.test</code>
                </div>
                <div class="demo-item" onclick="fillCredential('admin@paskerid.test', 'password')">
                    <span>Admin Pusat</span>
                    <code>admin@paskerid.test</code>
                </div>
                <div class="demo-item" onclick="fillCredential('admin.bandung@paskerid.test', 'password')">
                    <span>Admin Dinas (Bandung)</span>
                    <code>admin.bandung@paskerid.test</code>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT FORM SIDE -->
        <div class="auth-form-side">
            <div class="form-header">
                <h2>Masuk Akun</h2>
                <p>Silakan masukkan kredensial akun SIAPkerja Anda untuk melanjutkan ke portal layanan Karirhub.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert-box alert-error" style="background:#fee2e2; border:1px solid #fca5a5; color:#991b1b; padding:12px 14px; border-radius:10px; font-size:13px; margin-bottom:20px; display:flex; align-items:center; gap:8px;">
                    <i class="fa-solid fa-circle-exclamation"></i> <?php echo e($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($flash = get_flash()): ?>
                <div class="alert-box <?php echo $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>" style="padding:12px 14px; border-radius:10px; font-size:13px; margin-bottom:20px;">
                    <?php echo e($flash['message']); ?>
                </div>
            <?php endif; ?>

            <form method="post" id="loginForm">
                <div class="input-group">
                    <label>Email Akun SIAPkerja</label>
                    <div class="input-field-wrap">
                        <i class="fa-regular fa-envelope prefix-icon"></i>
                        <input type="email" id="emailInput" name="email" placeholder="nama@email.com" required value="<?php echo e($_POST['email'] ?? ''); ?>">
                    </div>
                </div>

                <div class="input-group">
                    <label>Password</label>
                    <div class="input-field-wrap">
                        <i class="fa-solid fa-lock prefix-icon"></i>
                        <input type="password" id="passwordInput" name="password" placeholder="Masukkan password" required>
                        <i class="fa-regular fa-eye toggle-password" onclick="togglePasswordVisibility()"></i>
                    </div>
                </div>

                <button class="btn-submit" type="submit">
                    <i class="fa-solid fa-arrow-right-to-bracket"></i>
                    Masuk Layanan
                </button>
            </form>

            <div class="btn-secondary-link">
                Belum memiliki akun SIAPkerja? <a href="register.php">Simulasi Onboarding Akun</a>
            </div>
        </div>
    </div>

    <script>
    function fillCredential(email, password) {
        document.getElementById('emailInput').value = email;
        document.getElementById('passwordInput').value = password;
    }

    function togglePasswordVisibility() {
        const pass = document.getElementById('passwordInput');
        const icon = document.querySelector('.toggle-password');
        if (pass.type === 'password') {
            pass.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            pass.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
    </script>
</body>
</html>
