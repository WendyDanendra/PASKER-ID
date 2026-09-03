<?php
require __DIR__ . '/includes/bootstrap.php';

$user = require_role('seeker');

if (!is_profile_complete($user)) {
    redirect('profile-seeker.php');
}

$page = $_GET['page'] ?? 'dashboard';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_job_id'])) {
    $jobId = (int) $_POST['apply_job_id'];
    $jobStmt = db()->prepare('SELECT * FROM job_posts WHERE id = ? AND status = "Tayang" LIMIT 1');
    $jobStmt->execute([$jobId]);
    $job = $jobStmt->fetch();

    if (!$job) {
        flash('error', 'Lowongan tidak tersedia atau belum disetujui.');
        redirect('seeker.php?page=jobs');
    }

    try {
        db()->prepare('INSERT INTO job_applications (job_id, seeker_id, status) VALUES (?, ?, "Dilamar")')
            ->execute([$jobId, $user['id']]);
        notify_user((int) $job['user_id'], 'Pelamar baru', $user['name'] . ' melamar lowongan "' . $job['title'] . '". Profil pelamar sudah tersinkron ke menu lowongan Anda.', 'info', $jobId);
        flash('success', 'Lamaran berhasil dikirim. Data profil Anda dikirim ke pemberi kerja.');
    } catch (Throwable $error) {
        flash('error', 'Anda sudah melamar lowongan ini.');
    }
    redirect('seeker.php?page=jobs');
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

$search = trim($_GET['q'] ?? '');
$jobSql = 'SELECT j.*, u.name AS employer_name, ep.profession
    FROM job_posts j
    JOIN users u ON u.id = j.user_id
    LEFT JOIN employer_profiles ep ON ep.user_id = j.user_id
    WHERE j.status = "Tayang"';
$jobParams = [];
if ($search !== '') {
    $jobSql .= ' AND (j.title LIKE ? OR j.location LIKE ? OR j.industry LIKE ?)';
    $like = '%' . $search . '%';
    $jobParams = [$like, $like, $like];
}
$jobSql .= ' ORDER BY j.created_at DESC';
$jobStmt = db()->prepare($jobSql);
$jobStmt->execute($jobParams);
$jobs = $jobStmt->fetchAll();

$appliedStmt = db()->prepare('SELECT job_id FROM job_applications WHERE seeker_id = ?');
$appliedStmt->execute([$user['id']]);
$appliedIds = array_map('intval', array_column($appliedStmt->fetchAll(), 'job_id'));

$unread = unread_notification_count((int) $user['id']);
$notifications = user_notifications((int) $user['id']);
$initials = strtoupper(mb_substr($user['name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pencari Kerja - Karirhub</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-mark"><i class="fa-solid fa-grip-lines"></i></div>
            <div class="brand-text">
                <h1>Karirhub</h1>
                <p>Pencari Kerja</p>
            </div>
        </div>
        <div class="menu-list">
            <a class="menu-item <?php echo $page === 'dashboard' ? 'active' : ''; ?>" href="seeker.php?page=dashboard">
                <i class="fa-regular fa-chart-bar"></i>
                <span class="menu-label"><strong>Dasbor</strong><span>Ringkasan profil</span></span>
            </a>
            <a class="menu-item <?php echo $page === 'jobs' ? 'active' : ''; ?>" href="seeker.php?page=jobs">
                <i class="fa-solid fa-briefcase"></i>
                <span class="menu-label"><strong>Lowongan Kerja</strong><span>Cari & lamar</span></span>
            </a>
        </div>
        <div class="sidebar-spacer"></div>
        <div style="padding:0 4px">
            <div class="profile-card">
                <div class="profile-avatar"><?php echo e($initials); ?></div>
                <div>
                    <strong><?php echo e($user['name']); ?></strong>
                    <span>Pencari kerja</span>
                </div>
            </div>
            <a href="logout.php" class="sidebar-logout"><i class="fa-solid fa-right-from-bracket"></i> Keluar</a>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <button id="sidebarToggle" class="sidebar-toggle" type="button"><i class="fa-solid fa-bars"></i></button>
            <div class="crumbs">
                <span>Beranda</span><span>&gt;</span>
                <strong><?php echo $page === 'jobs' ? 'Lowongan Kerja' : 'Dasbor'; ?></strong>
            </div>
            <div class="top-actions">
                <?php echo render_notif_dropdown($notifications, $unread); ?>
                <div class="company-chip">
                    <div><strong><?php echo e($user['name']); ?></strong><span>Pencari kerja</span></div>
                </div>
                <a class="action-chip" href="logout.php">Logout</a>
            </div>
        </header>

        <div class="seeker-content">
            <?php if ($flash = get_flash()): ?>
                <div class="alert-box <?php echo $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>"><?php echo e($flash['message']); ?></div>
            <?php endif; ?>

            <?php if ($page !== 'jobs'): ?>
                <div class="hero-card" style="padding:20px;display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                    <div>
                        <h1 style="font-size:28px;margin-bottom:6px">Halo, <?php echo e($user['name']); ?></h1>
                        <p style="color:#64748b">Profil kamu sudah lengkap. Lamar lowongan yang sudah disetujui admin.</p>
                    </div>
                    <a class="primary-btn" href="profile-seeker.php"><i class="fa-solid fa-pen-to-square"></i> Edit Profil</a>
                </div>
                <div class="metric-grid" style="display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-top:16px">
                    <div class="section-card" style="padding:16px"><span>Biodata</span><strong style="display:block;font-size:24px">Lengkap</strong></div>
                    <div class="section-card" style="padding:16px"><span>Pengalaman</span><strong style="display:block;font-size:24px"><?php echo $experienceCount; ?></strong></div>
                    <div class="section-card" style="padding:16px"><span>Pelatihan</span><strong style="display:block;font-size:24px"><?php echo $trainingCount; ?></strong></div>
                    <div class="section-card" style="padding:16px"><span>Pendidikan</span><strong style="display:block;font-size:24px"><?php echo $educationCount; ?></strong></div>
                    <div class="section-card" style="padding:16px"><span>Keahlian</span><strong style="display:block;font-size:24px"><?php echo $skillCount + $languageCount; ?></strong></div>
                </div>
                <div class="section-card" style="padding:16px;margin-top:16px">
                    <h3 style="margin-bottom:10px">Riwayat Profil</h3>
                    <div class="tiny">NIK <?php echo e($profile['nik'] ?? '-'); ?> · <?php echo e($profile['phone'] ?? '-'); ?></div>
                    <p style="margin-top:8px;font-size:13px;color:#475569"><?php echo e($profile['domicile_address'] ?? '-'); ?></p>
                    <div style="margin-top:12px">
                        <?php foreach ($skillRows as $row): ?>
                            <span class="status-chip ok"><?php echo e($row['skill_name']); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="admin-page-title">Lowongan Kerja</div>
                <form method="get" class="admin-toolbar" style="padding:0 0 16px">
                    <input type="hidden" name="page" value="jobs">
                    <div class="search-small"><i class="fa-solid fa-magnifying-glass"></i><input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Cari judul, lokasi, atau industri..."></div>
                    <button class="primary-btn" type="submit">Cari</button>
                </form>
                <div class="job-grid">
                    <?php if (!$jobs): ?>
                        <div class="section-card" style="padding:24px">Belum ada lowongan yang disetujui admin.</div>
                    <?php endif; ?>
                    <?php foreach ($jobs as $job): $meta = job_status_meta($job['status']); $applied = in_array((int) $job['id'], $appliedIds, true); ?>
                        <div class="job-card">
                            <span class="status-chip live"><?php echo e($meta['label']); ?></span>
                            <h3><?php echo e($job['title']); ?></h3>
                            <div class="tiny"><?php echo e($job['employer_name']); ?> · <?php echo e($job['profession'] ?? 'Pemberi kerja individu'); ?></div>
                            <p style="font-size:13px;color:#475569"><?php echo e($job['location']); ?> · <?php echo e($job['job_type']); ?></p>
                            <?php if ($job['salary_min'] || $job['salary_max']): ?>
                                <strong>Rp <?php echo e(number_format((int) $job['salary_min'])); ?> - <?php echo e(number_format((int) $job['salary_max'])); ?></strong>
                            <?php endif; ?>
                            <div><?php echo nl2br(e(strip_tags((string) $job['description']))); ?></div>
                            <?php if ($applied): ?>
                                <button class="ghost-btn" disabled>Sudah Dilamar</button>
                            <?php else: ?>
                                <form method="post">
                                    <input type="hidden" name="apply_job_id" value="<?php echo (int) $job['id']; ?>">
                                    <button class="primary-btn" type="submit">Lamar Sekarang</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="assets/app.js"></script>
</body>
</html>
