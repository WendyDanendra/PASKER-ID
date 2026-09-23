<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect(role_home(current_user()['role'] ?? ''));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pilih Jenis Pemberi Kerja - <?php echo APP_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
    <style>
        .registration-shell {
            min-height: 100vh;
            background: #f6f8fc;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .registration-card {
            width: min(760px, 100%);
            background: #fff;
            border: 1px solid #e9edf5;
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 8px 24px rgba(18, 36, 84, 0.06);
        }

        .registration-title {
            margin: 0;
            font-size: 30px;
            color: #141b34;
        }

        .registration-subtitle {
            margin: 10px 0 28px;
            color: #5c6785;
        }

        .registration-option {
            display: block;
            text-decoration: none;
            border: 1px solid #dde5f1;
            border-radius: 14px;
            padding: 18px 20px;
            color: inherit;
            margin-bottom: 14px;
            transition: box-shadow 0.2s ease, transform 0.2s ease;
            position: relative;
        }

        .registration-option:hover {
            box-shadow: 0 8px 20px rgba(33, 86, 197, 0.12);
            transform: translateY(-1px);
        }

        .registration-option.disabled {
            background: #f9fbff;
            cursor: not-allowed;
            opacity: 0.85;
            pointer-events: none;
        }

        .registration-option-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 8px;
        }

        .registration-option-left {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            font-size: 24px;
            color: #2f79f6;
        }

        .registration-option h3 {
            margin: 0;
            font-size: 24px;
            color: #111827;
        }

        .registration-option p {
            margin: 0;
            color: #57607a;
            line-height: 1.55;
        }

        .coming-soon {
            position: absolute;
            top: 0;
            right: 0;
            background: #f9d54f;
            color: #775b00;
            font-size: 13px;
            font-weight: 600;
            padding: 7px 12px;
            border-radius: 0 14px 0 12px;
        }

        .registration-footer {
            margin-top: 18px;
            text-align: center;
        }

        .registration-footer a {
            color: #667085;
            text-decoration: none;
            font-weight: 700;
            font-size: 20px;
        }
    </style>
</head>
<body>
    <div class="registration-shell">
        <div class="registration-card">
            <h1 class="registration-title">Pilih jenis pemberi kerja</h1>
            <p class="registration-subtitle">Pilih jenis pemberi kerja yang sesuai dengan kondisi Anda untuk melanjutkan.</p>

            <a class="registration-option" href="register.php">
                <div class="registration-option-head">
                    <div class="registration-option-left">
                        <i class="fa-solid fa-user"></i>
                        <h3>Pemberi Kerja Individu</h3>
                    </div>
                    <i class="fa-solid fa-chevron-right"></i>
                </div>
                <p>Perorangan yang membutuhkan tenaga kerja seperti asisten rumah tangga, pengasuh, sopir pribadi atau kebutuhan pekerjaan perorangan lainnya.</p>
            </a>

            <div class="registration-option disabled">
                <span class="coming-soon">Akan datang</span>
                <div class="registration-option-head">
                    <div class="registration-option-left">
                        <i class="fa-solid fa-building"></i>
                        <h3>Pemberi Kerja Badan Usaha/Instansi/Lembaga</h3>
                    </div>
                    <i class="fa-solid fa-chevron-right"></i>
                </div>
                <p>Untuk perusahaan, instansi pemerintah, yayasan, organisasi atau lembaga yang memiliki pegawai atau membuka lowongan kerja atas nama entitas.</p>
            </div>

            <div class="registration-footer">
                <a href="employer-menu.php">Sebelumnya</a>
            </div>
        </div>
    </div>
</body>
</html>
