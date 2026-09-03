<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $role = $_POST['role'] ?? 'employer';

    if ($name === '' || $email === '' || $password === '') {
        $error = 'Semua field wajib diisi.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.';
    } elseif (strlen($password) < 6) {
        $error = 'Password minimal 6 karakter.';
    } elseif (!in_array($role, ['employer', 'seeker'], true)) {
        $error = 'Role registrasi tidak valid.';
    } elseif (find_user_by_email($email)) {
        $error = 'Email sudah terdaftar.';
    } else {
        $userId = create_user($name, $email, $password, $role);
        $user = find_user_by_email($email);
        login_user($user);

        if ($role === 'seeker') {
            redirect('profile-seeker.php');
        }

        redirect('profile-employer.php');
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrasi - <?php echo APP_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <div class="auth-shell">
        <div class="auth-visual">
            <div class="auth-brand">
                <div class="brand-mark"><i class="fa-solid fa-user-plus"></i></div>
                <div>
                    <h1>Buat akun demo</h1>
                    <p>Registrasi untuk role pemberi kerja individu atau pencari kerja</p>
                </div>
            </div>
            <div class="auth-copy">
                <p><strong>Perhatian:</strong> admin tidak dibuka dari registrasi. Akun admin disiapkan langsung dari database seed.</p>
                <p style="margin-top:12px;">Setelah daftar, sistem akan mengarahkan ke form profil yang sesuai supaya alur demo terlihat nyata.</p>
            </div>
        </div>
        <div class="auth-panel">
            <div class="auth-card">
                <h2>Registrasi</h2>
                <p>Buat akun baru untuk demo web Pasker ID.</p>

                <?php if ($error): ?>
                    <div class="alert-box alert-error"><?php echo e($error); ?></div>
                <?php endif; ?>

                <form method="post">
                    <div class="field">
                        <label>Nama</label>
                        <input type="text" name="name" placeholder="Nama lengkap" required>
                    </div>
                    <div class="field">
                        <label>Email</label>
                        <input type="email" name="email" placeholder="nama@email.com" required>
                    </div>
                    <div class="field">
                        <label>Password</label>
                        <input type="password" name="password" placeholder="Minimal 6 karakter" required>
                    </div>
                    <div class="field">
                        <label>Role</label>
                        <select name="role" required>
                            <option value="employer">Pemberi Kerja Individu</option>
                            <option value="seeker">Pencari Kerja</option>
                        </select>
                    </div>
                    <div class="auth-actions">
                        <button class="primary-btn" type="submit">Daftar</button>
                        <a class="ghost-btn" href="login.php">Masuk</a>
                    </div>
                </form>

                <div class="switch-row">
                    Sudah punya akun? <a href="login.php">Login</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
