<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = current_user();

if ($user) {
    redirect(role_home($user['role'] ?? ''));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simulasi Pengguna - <?php echo APP_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
    <style>
        .quick-login-link {
            position: absolute;
            top: 24px;
            right: 24px;
            z-index: 4;
            min-width: 110px;
        }

        .index-logo-card {
            background: #ffffff;
            padding: 16px 28px;
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.18);
            margin-bottom: 20px;
        }

        .index-logo-card img {
            height: 64px;
            width: auto;
            display: block;
        }

        @media (max-width: 768px) {
            .quick-login-link {
                top: 14px;
                right: 14px;
                min-width: 96px;
            }
        }
    </style>
</head>
<body>
    <div class="auth-shell auth-shell-login">
        <a class="primary-btn quick-login-link" href="login.php">
            <i class="fa-solid fa-right-to-bracket"></i>
            Login
        </a>
        <div class="auth-visual">
            <div class="auth-brand">
                <div class="index-logo-card">
                    <img src="assets/logo-karirhub.png" alt="Karirhub oleh Kemnaker">
                </div>
                <div class="brand-pill">
                    <i class="fa-solid fa-users"></i>
                    Pilih Mode Simulasi
                </div>
                <div>
                    <h1 class="auth-gradient-title">Simulasi Pengguna</h1>
                    <p class="auth-subtitle">Pilih jenis pengguna terlebih dahulu sebelum masuk ke halaman autentikasi SIAPkerja.</p>
                </div>
            </div>
            <div class="auth-copy auth-copy-highlight">
                <p>Setiap pilihan pengguna akan diarahkan ke halaman <strong>login</strong> yang sama untuk simulasi alur akses.</p>
            </div>
        </div>
        <div class="auth-panel">
            <div class="auth-card">
                <h2>Daftar Sebagai</h2>
                <p>Pilih peran pengguna untuk mulai simulasi:</p>
                <div class="auth-actions" style="display:grid; gap:10px;">
                    <a class="primary-btn" href="employer-menu.php">
                        <i class="fa-solid fa-building-user"></i>
                        Employer
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
