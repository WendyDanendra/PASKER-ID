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

        if (($user['role'] ?? '') === 'admin') {
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
    <title>Masuk - <?php echo APP_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <div class="auth-shell auth-shell-login">
        <div class="auth-visual">
            <div class="auth-brand">
                <div class="brand-pill">
                    <i class="fa-solid fa-sparkles"></i>
                    Platform Karier Modern
                </div>
                <div>
                    <h1 class="auth-gradient-title">Karirhub</h1>
                    <p class="auth-subtitle">Temukan peluang terbaik dan kelola proses rekrutmen dalam satu dashboard yang elegan.</p>
                </div>
            </div>
            <div class="auth-copy auth-copy-highlight">
                <p><strong>Alur demo:</strong> pemberi kerja individu wajib melengkapi profil dulu sebelum masuk dashboard, pencari kerja akan diarahkan ke form biodata, dan admin langsung masuk ke daftar pemberi kerja individu.</p>
                <p>Akun seed tersedia untuk demo, dan semua role sudah dipisah di database <strong>paskerid</strong>.</p>
            </div>
            <div class="demo-card">
                <p><strong>Demo Account</strong></p>
                <div class="demo-row"><span>Admin</span><code>admin@paskerid.test / admin123</code></div>
                <div class="demo-row"><span>Perorangan</span><code>perorangan@paskerid.test / demo123</code></div>
                <div class="demo-row"><span>Pencari kerja</span><code>seeker@paskerid.test / seeker123</code></div>
            </div>
        </div>
        <div class="auth-panel">
            <div class="auth-card">
                <h2>Masuk</h2>
                <p>Silakan login untuk lanjut ke dashboard sesuai role kamu.</p>

                <?php if ($error): ?>
                    <div class="alert-box alert-error"><?php echo e($error); ?></div>
                <?php endif; ?>

                <?php if ($flash = get_flash()): ?>
                    <div class="alert-box <?php echo $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>"><?php echo e($flash['message']); ?></div>
                <?php endif; ?>

                <form method="post">
                    <div class="field">
                        <label>Email</label>
                        <div class="input-wrap">
                            <i class="fa-regular fa-envelope"></i>
                            <input type="email" name="email" placeholder="nama@email.com" required>
                        </div>
                    </div>
                    <div class="field">
                        <label>Password</label>
                        <div class="input-wrap">
                            <i class="fa-solid fa-lock"></i>
                            <input type="password" name="password" placeholder="Masukkan password" required>
                        </div>
                    </div>
                    <div class="auth-actions">
                        <button class="primary-btn" type="submit">
                            <i class="fa-solid fa-arrow-right-to-bracket"></i>
                            Masuk
                        </button>
                        <a class="ghost-btn" href="register.php">Daftar</a>
                    </div>
                </form>

                <div class="switch-row">
                    Belum punya akun? <a href="register.php">Registrasi</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
