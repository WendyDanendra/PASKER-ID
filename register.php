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
    <title>Simulasi Onboarding - <?php echo APP_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <div class="auth-shell auth-shell-single">
        <div class="auth-panel">
            <div class="auth-card">
                <h2>Simulasi Onboarding</h2>
                <p>Mulai simulasi akses akun SIAPkerja baru untuk pengujian prototype.</p>

                <?php if ($error): ?>
                    <div class="alert-box alert-error"><?php echo e($error); ?></div>
                <?php endif; ?>

                <form method="post">
                    <div class="field">
                        <label>Nama Pemilik Akun</label>
                        <input type="text" name="name" placeholder="Nama lengkap sesuai SIAPkerja" required>
                    </div>
                    <div class="field">
                        <label>Email Akun SIAPkerja</label>
                        <input type="email" name="email" placeholder="nama@email.com" required>
                    </div>
                    <div class="field">
                        <label>Password</label>
                        <input type="password" name="password" placeholder="Minimal 6 karakter" required>
                    </div>
                    <div class="field">
                        <label>Layanan yang Diakses</label>
                        <select name="role" required>
                            <option value="employer">Layanan Pemberi Kerja Individu (PKI)</option>
                            <option value="seeker">Layanan Pencari Kerja</option>
                        </select>
                    </div>
                    <div class="auth-actions">
                        <button class="primary-btn" type="submit">Mulai Simulasi</button>
                        <a class="ghost-btn" href="login.php">Masuk</a>
                    </div>
                </form>

                <div class="switch-row">
                    Sudah memiliki akun simulasi? <a href="login.php">Masuk</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
