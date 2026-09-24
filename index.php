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
    <title>Portal Layanan Karirhub Kemnaker</title>
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

        .portal-container {
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

        .portal-visual-side {
            background: linear-gradient(145deg, #0284c7 0%, #0369a1 100%);
            padding: 44px;
            color: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
            text-align: center;
        }

        .portal-visual-side::before {
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

        .portal-visual-side::after {
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
            padding: 20px 32px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 14px 35px -8px rgba(0, 0, 0, 0.22);
            margin: 0 auto 28px auto;
            border: 1px solid rgba(255, 255, 255, 0.8);
            width: fit-content;
        }

        .logo-wrapper img {
            height: 76px;
            width: auto;
            max-width: 100%;
            display: block;
            object-fit: contain;
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

        .portal-info-card {
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            padding: 20px;
            font-size: 13px;
            text-align: left;
        }

        .portal-info-card h4 {
            font-size: 14px;
            font-weight: 700;
            margin: 0 0 8px 0;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .portal-info-card p {
            margin: 0;
            color: #e0f2fe;
            line-height: 1.5;
        }

        .portal-content-side {
            padding: 48px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: #ffffff;
        }

        .portal-header h2 {
            font-size: 26px;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 6px 0;
        }

        .portal-header p {
            font-size: 13.5px;
            color: #64748b;
            margin: 0 0 28px 0;
            line-height: 1.5;
        }

        .role-cards-grid {
            display: grid;
            gap: 14px;
            margin-bottom: 24px;
        }

        .role-card-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px 20px;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            text-decoration: none;
            color: #1e293b;
            background: #f8fafc;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .role-card-item:hover {
            border-color: #0284c7;
            background: #f0f9ff;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px -4px rgba(2, 132, 199, 0.15);
        }

        .role-icon-box {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: #e0f2fe;
            color: #0284c7;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
            transition: all 0.2s ease;
        }

        .role-card-item:hover .role-icon-box {
            background: #0284c7;
            color: #ffffff;
        }

        .role-details h3 {
            font-size: 15px;
            font-weight: 700;
            margin: 0 0 3px 0;
            color: #0f172a;
        }

        .role-details p {
            font-size: 12.5px;
            color: #64748b;
            margin: 0;
            line-height: 1.4;
        }

        .arrow-icon {
            margin-left: auto;
            color: #94a3b8;
            font-size: 14px;
            transition: transform 0.2s ease, color 0.2s ease;
        }

        .role-card-item:hover .arrow-icon {
            color: #0284c7;
            transform: translateX(4px);
        }

        .btn-login-direct {
            width: 100%;
            height: 46px;
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            border: none;
            border-radius: 12px;
            color: #ffffff;
            font-weight: 700;
            font-size: 14px;
            font-family: inherit;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.3);
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .btn-login-direct:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(2, 132, 199, 0.4);
        }

        @media (max-width: 860px) {
            .portal-container {
                grid-template-columns: 1fr;
            }
            .portal-visual-side, .portal-content-side {
                padding: 32px 24px;
            }
        }
    </style>
</head>
<body>
    <div class="portal-container">
        <!-- LEFT VISUAL SIDE -->
        <div class="portal-visual-side">
            <div>
                <div class="logo-wrapper">
                    <img src="assets/logo-karirhub.png" alt="Karirhub oleh Kemnaker">
                </div>

                <h1 class="visual-title">Selamat Datang di Portal Karirhub</h1>
                <p class="visual-desc">
                    Layanan Resmi Pasar Kerja Kementerian Ketenagakerjaan Republik Indonesia Terintegrasi Akun SIAPkerja.
                </p>
            </div>

            <div class="portal-info-card">
                <h4><i class="fa-solid fa-shield-halved"></i> Otentikasi Terpusat</h4>
                <p>Seluruh layanan Karirhub terhubung secara aman dengan Single Sign-On (SSO) Akun SIAPkerja Kemnaker RI.</p>
            </div>
        </div>

        <!-- RIGHT CONTENT SIDE -->
        <div class="portal-content-side">
            <div class="portal-header">
                <h2>Pilih Layanan</h2>
                <p>Pilih kategori akun atau menu simulasi untuk melanjutkan ke dalam sistem Karirhub:</p>
            </div>

            <div class="role-cards-grid">
                <a href="employer-menu.php" class="role-card-item">
                    <div class="role-icon-box">
                        <i class="fa-solid fa-user-gear"></i>
                    </div>
                    <div class="role-details">
                        <h3>Pemberi Kerja</h3>
                        <p>Kelola profil usaha/individu, pasang lowongan kerja & verifikasi data.</p>
                    </div>
                    <i class="fa-solid fa-chevron-right arrow-icon"></i>
                </a>

                <a href="login.php" class="role-card-item">
                    <div class="role-icon-box">
                        <i class="fa-solid fa-user-tie"></i>
                    </div>
                    <div class="role-details">
                        <h3>Pencari Kerja</h3>
                        <p>Temukan pekerjaan impian dan kelola lamaran Anda di Karirhub.</p>
                    </div>
                    <i class="fa-solid fa-chevron-right arrow-icon"></i>
                </a>

                <a href="admin.php" class="role-card-item">
                    <div class="role-icon-box">
                        <i class="fa-solid fa-building-columns"></i>
                    </div>
                    <div class="role-details">
                        <h3>Console Petugas Dinas / Pusat</h3>
                        <p>Verifikasi profil pemberi kerja & moderasi lowongan secara resmi.</p>
                    </div>
                    <i class="fa-solid fa-chevron-right arrow-icon"></i>
                </a>
            </div>

            <a href="login.php" class="btn-login-direct">
                <i class="fa-solid fa-right-to-bracket"></i>
                Masuk Halaman Autentikasi (SIAPkerja)
            </a>
        </div>
    </div>
</body>
</html>
