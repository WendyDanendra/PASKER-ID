<?php
require __DIR__ . '/includes/bootstrap.php';

$user = require_role('seeker');

if (!is_profile_complete($user)) {
    redirect('profile-seeker.php');
}

$profileStatement = db()->prepare('SELECT * FROM seeker_profiles WHERE user_id = ? LIMIT 1');
$profileStatement->execute([$user['id']]);
$profile = $profileStatement->fetch() ?: [];

$experienceCount = (int) db()->query('SELECT COUNT(*) FROM seeker_experiences WHERE user_id = ' . (int) $user['id'])->fetchColumn();
$trainingCount = (int) db()->query('SELECT COUNT(*) FROM seeker_trainings WHERE user_id = ' . (int) $user['id'])->fetchColumn();
$educationCount = (int) db()->query('SELECT COUNT(*) FROM seeker_educations WHERE user_id = ' . (int) $user['id'])->fetchColumn();
$skillCount = (int) db()->query('SELECT COUNT(*) FROM seeker_skills WHERE user_id = ' . (int) $user['id'])->fetchColumn();
$languageCount = (int) db()->query('SELECT COUNT(*) FROM seeker_languages WHERE user_id = ' . (int) $user['id'])->fetchColumn();

$experienceRows = db()->query('SELECT * FROM seeker_experiences WHERE user_id = ' . (int) $user['id'] . ' ORDER BY id DESC')->fetchAll();
$trainingRows = db()->query('SELECT * FROM seeker_trainings WHERE user_id = ' . (int) $user['id'] . ' ORDER BY id DESC')->fetchAll();
$educationRows = db()->query('SELECT * FROM seeker_educations WHERE user_id = ' . (int) $user['id'] . ' ORDER BY id DESC')->fetchAll();
$skillRows = db()->query('SELECT * FROM seeker_skills WHERE user_id = ' . (int) $user['id'] . ' ORDER BY id DESC')->fetchAll();
$languageRows = db()->query('SELECT * FROM seeker_languages WHERE user_id = ' . (int) $user['id'] . ' ORDER BY id DESC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Pencari Kerja</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
    <style>
        .seeker-shell {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .seeker-content {
            padding: 22px;
            display: grid;
            gap: 16px;
        }
        .hero-card,
        .info-card,
        .section-card {
            background: #fff;
            border: 1px solid #e8ebf2;
            border-radius: 18px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.05);
        }
        .hero-card {
            padding: 20px;
            display: flex;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .hero-card h1 {
            font-size: 28px;
            margin-bottom: 6px;
        }
        .hero-card p {
            color: #64748b;
            font-size: 14px;
        }
        .metric-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 12px;
        }
        .metric {
            background: #fff;
            border: 1px solid #e8ebf2;
            border-radius: 18px;
            padding: 16px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.05);
        }
        .metric strong {
            display: block;
            font-size: 28px;
            margin-top: 6px;
        }
        .split-grid {
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 12px;
        }
        .section-card {
            padding: 16px;
        }
        .section-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }
        .section-title h3 {
            font-size: 15px;
            font-weight: 800;
        }
        .record-list {
            display: grid;
            gap: 8px;
        }
        .record-item {
            border: 1px solid #edf1f6;
            background: #fafcff;
            border-radius: 12px;
            padding: 10px 12px;
        }
        .record-item strong {
            display: block;
            font-size: 13px;
            margin-bottom: 2px;
        }
        .record-item span {
            font-size: 11px;
            color: #64748b;
        }
        .profile-summary {
            display: grid;
            gap: 10px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            font-size: 12px;
            color: #475569;
            border-bottom: 1px solid #eef2f7;
            padding-bottom: 10px;
        }
        .summary-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        @media (max-width: 1100px) {
            .metric-grid,
            .split-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        @media (max-width: 820px) {
            .seeker-content,
            .split-grid,
            .metric-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="seeker-shell">
        <header class="topbar">
            <div class="crumbs">
                <span><i class="fa-solid fa-chevron-left"></i></span>
                <span>Beranda</span>
                <span>&gt;</span>
                <strong>Pencari Kerja</strong>
            </div>
            <div class="search-bar">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" placeholder="Cari lowongan, perusahaan, atau lokasi...">
            </div>
            <div class="top-actions">
                <div class="notif"><i class="fa-regular fa-bell"></i></div>
                <div class="company-chip">
                    <div>
                        <strong><?php echo e($user['name']); ?></strong>
                        <span>Pencari kerja</span>
                    </div>
                </div>
                <a class="action-chip" href="logout.php">Logout</a>
            </div>
        </header>

        <div class="seeker-content">
            <div class="hero-card">
                <div>
                    <h1>Halo, <?php echo e($user['name']); ?></h1>
                    <p>Profil kamu sudah lengkap. Sekarang dashboard ini bisa dipakai untuk lihat status dan riwayat data diri.</p>
                </div>
                <a class="primary-btn" href="profile-seeker.php"><i class="fa-solid fa-pen-to-square"></i> Edit Profil</a>
            </div>

            <div class="metric-grid">
                <div class="metric"><span>Biodata</span><strong>Lengkap</strong></div>
                <div class="metric"><span>Pengalaman</span><strong><?php echo $experienceCount; ?></strong></div>
                <div class="metric"><span>Pelatihan</span><strong><?php echo $trainingCount; ?></strong></div>
                <div class="metric"><span>Pendidikan</span><strong><?php echo $educationCount; ?></strong></div>
                <div class="metric"><span>Keahlian / Bahasa</span><strong><?php echo $skillCount + $languageCount; ?></strong></div>
            </div>

            <div class="split-grid">
                <div class="section-card">
                    <div class="section-title">
                        <h3>Riwayat Profil</h3>
                        <span class="chip-inline"><i class="fa-solid fa-id-card"></i> <?php echo e($profile['nik'] ?? '-'); ?></span>
                    </div>
                    <div class="profile-summary">
                        <div class="summary-row"><span>Tempat, tanggal lahir</span><strong><?php echo e(($profile['birth_place'] ?? '-') . ', ' . ($profile['birth_date'] ?? '-')); ?></strong></div>
                        <div class="summary-row"><span>Jenis kelamin</span><strong><?php echo e($profile['gender'] ?? '-'); ?></strong></div>
                        <div class="summary-row"><span>Status</span><strong><?php echo e($profile['marital_status'] ?? '-'); ?></strong></div>
                        <div class="summary-row"><span>Telepon</span><strong><?php echo e($profile['phone'] ?? '-'); ?></strong></div>
                        <div class="summary-row"><span>Alamat KTP</span><strong><?php echo e($profile['ktp_address'] ?? '-'); ?></strong></div>
                        <div class="summary-row"><span>Alamat domisili</span><strong><?php echo e($profile['domicile_address'] ?? '-'); ?></strong></div>
                    </div>
                </div>

                <div class="section-card">
                    <div class="section-title"><h3>Ringkasan Skill</h3></div>
                    <div class="record-list">
                        <?php foreach ($skillRows as $row): ?>
                            <div class="record-item"><strong><?php echo e($row['skill_name']); ?></strong><span><?php echo e($row['level'] ?: 'Level tidak diisi'); ?></span></div>
                        <?php endforeach; ?>
                        <?php if (!$skillRows): ?>
                            <div class="record-item"><strong>Belum ada keahlian</strong><span>Tambahkan dari halaman profil.</span></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="split-grid">
                <div class="section-card">
                    <div class="section-title"><h3>Pengalaman Kerja</h3></div>
                    <div class="record-list">
                        <?php foreach ($experienceRows as $row): ?>
                            <div class="record-item"><strong><?php echo e($row['company_name']); ?> - <?php echo e($row['position']); ?></strong><span><?php echo e($row['duration']); ?><?php echo $row['notes'] ? ' | ' . e($row['notes']) : ''; ?></span></div>
                        <?php endforeach; ?>
                        <?php if (!$experienceRows): ?>
                            <div class="record-item"><strong>Belum ada pengalaman kerja</strong><span>Tambahkan dari profil.</span></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="section-card">
                    <div class="section-title"><h3>Pelatihan, Pendidikan, Bahasa</h3></div>
                    <div class="record-list">
                        <?php foreach ($trainingRows as $row): ?>
                            <div class="record-item"><strong><?php echo e($row['training_name']); ?></strong><span><?php echo e(trim(($row['organizer'] ?? '-') . ' | ' . ($row['year'] ?? '-'))); ?></span></div>
                        <?php endforeach; ?>
                        <?php foreach ($educationRows as $row): ?>
                            <div class="record-item"><strong><?php echo e($row['level']); ?> - <?php echo e($row['school_name']); ?></strong><span><?php echo e(trim(($row['major'] ?? '-') . ' | ' . ($row['graduation_year'] ?? '-'))); ?></span></div>
                        <?php endforeach; ?>
                        <?php foreach ($languageRows as $row): ?>
                            <div class="record-item"><strong><?php echo e($row['language_name']); ?></strong><span><?php echo e($row['proficiency'] ?: 'Level tidak diisi'); ?></span></div>
                        <?php endforeach; ?>
                        <?php if (!$trainingRows && !$educationRows && !$languageRows): ?>
                            <div class="record-item"><strong>Belum ada data tambahan</strong><span>Semua data ada di profil awal.</span></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
