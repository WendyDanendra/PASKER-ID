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
        db()->prepare('INSERT INTO job_applications (job_id, seeker_id, status) VALUES (?, ?, "Lamaran Masuk")')
            ->execute([$jobId, $user['id']]);
        notify_user((int) $job['user_id'], 'Pelamar baru', $user['name'] . ' melamar lowongan "' . $job['title'] . '". Profil pelamar sudah tersinkron ke menu lowongan Anda.', 'info', $jobId);
        flash('success', 'Lamaran berhasil dikirim. Data profil Anda dikirim ke pemberi kerja.');
    } catch (Throwable $error) {
        flash('error', 'Anda sudah melamar lowongan ini.');
    }

    $back = trim((string) ($_POST['return_to'] ?? ''));
    if ($back === 'job') {
        redirect('seeker.php?page=job&id=' . $jobId);
    }
    if ($back === 'employer') {
        redirect('seeker.php?page=employer&id=' . (int) $job['user_id']);
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
$filterLocations = array_values(array_filter(array_map('trim', (array) ($_GET['location'] ?? []))));
$filterJobType = trim((string) ($_GET['job_type'] ?? ''));
$filterEducation = trim((string) ($_GET['education'] ?? ''));
$filterField = trim((string) ($_GET['job_field'] ?? ''));

$jobSql = public_job_select_sql();
$jobParams = [];
if ($search !== '') {
    $jobSql .= ' AND (j.title LIKE ? OR j.location LIKE ? OR j.industry LIKE ? OR u.name LIKE ? OR ep.owner_name LIKE ?)';
    $like = '%' . $search . '%';
    $jobParams = [$like, $like, $like, $like, $like];
}
if ($filterLocations) {
    $placeholders = implode(',', array_fill(0, count($filterLocations), '?'));
    $jobSql .= ' AND j.location IN (' . $placeholders . ')';
    $jobParams = array_merge($jobParams, $filterLocations);
}
if ($filterJobType !== '' && in_array($filterJobType, seeker_job_type_options(), true)) {
    $jobSql .= ' AND j.job_type = ?';
    $jobParams[] = $filterJobType;
}
$jobSql .= ' ORDER BY j.created_at DESC';
$jobStmt = db()->prepare($jobSql);
$jobStmt->execute($jobParams);
$jobs = $jobStmt->fetchAll() ?: [];

if ($filterEducation !== '' || $filterField !== '') {
    $jobs = array_values(array_filter($jobs, static function (array $job) use ($filterEducation, $filterField) {
        $data = job_to_form_data($job);
        if ($filterEducation !== '' && $data['education_required'] !== $filterEducation) {
            return false;
        }
        if ($filterField !== '' && $data['job_field'] !== $filterField) {
            return false;
        }
        return true;
    }));
}

$locationCounts = [];
$typeCounts = [];
foreach (db()->query('SELECT location, job_type FROM job_posts WHERE status = "Tayang"') as $row) {
    $locationCounts[$row['location']] = ($locationCounts[$row['location']] ?? 0) + 1;
    $typeCounts[$row['job_type']] = ($typeCounts[$row['job_type']] ?? 0) + 1;
}
$filterCityOptions = seeker_city_options();
foreach (array_keys($locationCounts) as $cityName) {
    if (!in_array($cityName, $filterCityOptions, true)) {
        $filterCityOptions[] = $cityName;
    }
}

$detailJob = null;
$detailData = [];
$relatedJobs = [];
$employerProfile = null;
$employerJobs = [];

if ($page === 'job') {
    $jobId = (int) ($_GET['id'] ?? 0);
    $detailStmt = db()->prepare(public_job_select_sql() . ' AND j.id = ? LIMIT 1');
    $detailStmt->execute([$jobId]);
    $detailJob = $detailStmt->fetch() ?: null;
    if (!$detailJob) {
        flash('error', 'Lowongan tidak ditemukan atau belum disetujui.');
        redirect('seeker.php?page=jobs');
    }
    $detailData = job_to_form_data($detailJob);
    $relatedStmt = db()->prepare(public_job_select_sql() . ' AND j.user_id = ? AND j.id != ? ORDER BY j.created_at DESC LIMIT 4');
    $relatedStmt->execute([(int) $detailJob['user_id'], (int) $detailJob['id']]);
    $relatedJobs = $relatedStmt->fetchAll() ?: [];
}

if ($page === 'employer') {
    $employerId = (int) ($_GET['id'] ?? 0);
    $employerStmt = db()->prepare('SELECT u.id, u.name, u.email, ep.*
        FROM users u
        LEFT JOIN employer_profiles ep ON ep.user_id = u.id
        WHERE u.id = ? AND u.role = "employer"
        LIMIT 1');
    $employerStmt->execute([$employerId]);
    $employerProfile = $employerStmt->fetch() ?: null;
    if (!$employerProfile) {
        flash('error', 'Profil pemberi kerja tidak ditemukan.');
        redirect('seeker.php?page=jobs');
    }
    $empJobsStmt = db()->prepare(public_job_select_sql() . ' AND j.user_id = ? ORDER BY j.created_at DESC');
    $empJobsStmt->execute([$employerId]);
    $employerJobs = $empJobsStmt->fetchAll() ?: [];
}

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
    <link rel="stylesheet" href="assets/app.css?v=seeker-jobs-1">
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
            <a class="menu-item <?php echo in_array($page, ['jobs', 'job', 'employer'], true) ? 'active' : ''; ?>" href="seeker.php?page=jobs">
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
                <strong><?php echo $page === 'job' ? 'Detail Lowongan' : ($page === 'employer' ? 'Profil Pemberi Kerja' : ($page === 'jobs' ? 'Lowongan Kerja' : 'Dasbor')); ?></strong>
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

            <?php if ($page === 'dashboard'): ?>
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
            <?php elseif ($page === 'job' && $detailJob): ?>
                <?php
                    $applied = in_array((int) $detailJob['id'], $appliedIds, true);
                    $employerName = job_employer_display_name($detailJob);
                    $employerInitial = strtoupper(mb_substr($employerName, 0, 1));
                    $photo = trim((string) ($detailJob['workplace_photo'] ?? ''));
                    $descriptionHtml = job_rich_html($detailData['description']);
                    $specialHtml = job_rich_html($detailData['special_requirements']);
                    $genderText = $detailData['genders'] ? implode(' / ', $detailData['genders']) : 'Tidak ada preferensi';
                    $maritalText = $detailData['marital_statuses'] ? implode(' / ', $detailData['marital_statuses']) : 'Tidak ada preferensi';
                    $physicalText = $detailData['physical_conditions'] ? implode(' & ', $detailData['physical_conditions']) : '-';
                ?>
                <div class="job-detail-page">
                    <a class="back-link" href="seeker.php?page=jobs"><i class="fa-solid fa-arrow-left"></i> Kembali ke lowongan</a>
                    <section class="job-detail-hero">
                        <div class="job-detail-hero-main">
                            <div class="vacancy-logo lg"><?php echo $photo !== '' ? '<img src="' . e($photo) . '" alt="">' : e($employerInitial); ?></div>
                            <div>
                                <h1><?php echo e($detailJob['title']); ?></h1>
                                <p class="job-detail-meta"><i class="fa-solid fa-location-dot"></i> <?php echo e(job_placement_address($detailJob)); ?></p>
                                <p class="job-detail-meta"><i class="fa-regular fa-clock"></i> Diposting <?php echo e(time_ago_id($detailJob['created_at'] ?? null)); ?> · Jumlah lowongan: <?php echo (int) $detailJob['quota']; ?></p>
                                <p class="job-detail-meta"><i class="fa-regular fa-calendar"></i> Batas waktu lamaran <?php echo e(job_apply_deadline($detailJob)); ?></p>
                            </div>
                        </div>
                        <?php if ($applied): ?>
                            <button class="vacancy-apply is-disabled" type="button" disabled>Sudah Dilamar</button>
                        <?php else: ?>
                            <form method="post">
                                <input type="hidden" name="apply_job_id" value="<?php echo (int) $detailJob['id']; ?>">
                                <input type="hidden" name="return_to" value="job">
                                <button class="vacancy-apply" type="submit">Lamar Sekarang</button>
                            </form>
                        <?php endif; ?>
                    </section>

                    <div class="job-facts">
                        <div><span>Bidang pekerjaan</span><strong><?php echo e($detailData['job_field'] ?: $detailJob['industry'] ?: '-'); ?></strong></div>
                        <div><span>Jenis pekerjaan</span><strong><?php echo e($detailJob['job_type'] ?: '-'); ?></strong></div>
                        <div><span>Tipe pekerjaan</span><strong>Lowongan dalam negeri</strong></div>
                        <div><span>Jenis kelamin</span><strong><?php echo e($genderText); ?></strong></div>
                        <div><span>Rentang gaji</span><strong><?php echo e(job_public_salary($detailJob)); ?></strong></div>
                        <div><span>Remote</span><strong><?php echo e(!empty($detailData['is_remote']) ? 'Ya' : 'Tidak'); ?></strong></div>
                    </div>

                    <div class="job-detail-layout">
                        <div class="job-detail-main">
                            <section class="job-detail-block">
                                <h2>Deskripsi Pekerjaan</h2>
                                <?php if ($descriptionHtml !== ''): ?>
                                    <div class="job-review-prose"><?php echo $descriptionHtml; ?></div>
                                <?php else: ?>
                                    <p class="job-review-empty">Belum ada deskripsi.</p>
                                <?php endif; ?>
                            </section>
                            <section class="job-detail-block">
                                <h2>Persyaratan Khusus</h2>
                                <ul class="job-spec-list">
                                    <li>Pendidikan: <?php echo e($detailData['education_required'] ?: 'Tidak ditentukan'); ?></li>
                                    <li>Pengalaman: <?php echo e($detailData['experience_required'] ?: 'Tidak ditentukan'); ?></li>
                                    <?php if ($detailData['skills']): ?>
                                        <li>Keahlian: <?php echo e(implode(', ', $detailData['skills'])); ?></li>
                                    <?php endif; ?>
                                    <?php if ($detailData['age_min'] !== '' || $detailData['age_max'] !== ''): ?>
                                        <li>Usia: <?php echo e(trim(($detailData['age_min'] !== '' ? $detailData['age_min'] . ' th' : '') . ' – ' . ($detailData['age_max'] !== '' ? $detailData['age_max'] . ' th' : ''), ' –')); ?></li>
                                    <?php endif; ?>
                                </ul>
                                <?php if ($specialHtml !== ''): ?>
                                    <div class="job-review-prose" style="margin-top:12px"><?php echo $specialHtml; ?></div>
                                <?php endif; ?>
                            </section>
                            <section class="job-detail-block">
                                <h2>Persyaratan Umum</h2>
                                <div class="job-general-grid">
                                    <div><span>Minimal pendidikan</span><strong><?php echo e($detailData['education_required'] ?: 'Tidak ditentukan'); ?></strong></div>
                                    <div><span>Status pernikahan</span><strong><?php echo e($maritalText); ?></strong></div>
                                    <div><span>Minimal pengalaman</span><strong><?php echo e($detailData['experience_required'] ?: 'Tidak ditentukan'); ?></strong></div>
                                    <div><span>Kondisi fisik</span><strong><?php echo e($physicalText); ?></strong></div>
                                </div>
                            </section>
                        </div>
                        <aside class="job-detail-side">
                            <div class="employer-side-card">
                                <h3><?php echo e($employerName); ?></h3>
                                <div class="employer-side-tags">
                                    <span class="perorangan-badge">Perorangan</span>
                                    <?php if (!empty($detailJob['verified'])): ?>
                                        <span class="verified-badge"><i class="fa-solid fa-circle-check"></i> Terverifikasi</span>
                                    <?php endif; ?>
                                    <?php if (!empty($detailJob['profession'])): ?>
                                        <span class="soft-pill"><?php echo e($detailJob['profession']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <a class="employer-profile-link" href="seeker.php?page=employer&id=<?php echo (int) $detailJob['user_id']; ?>">Lihat Profil Pemberi Kerja</a>
                                <div class="employer-side-meta">
                                    <div><span>Alamat lengkap</span><strong><?php echo e(job_placement_address($detailJob)); ?></strong></div>
                                    <div><span>Email</span><strong><?php echo e($detailJob['employer_email'] ?? '-'); ?></strong></div>
                                    <div><span>Kontak langsung</span><strong><?php echo e(job_employer_contact($detailJob)); ?></strong></div>
                                </div>
                            </div>
                            <?php if ($relatedJobs): ?>
                            <div class="employer-side-card">
                                <h3>Lowongan lain pemberi kerja ini</h3>
                                <div class="related-job-list">
                                    <?php foreach ($relatedJobs as $related): ?>
                                        <a class="related-job-item" href="seeker.php?page=job&id=<?php echo (int) $related['id']; ?>">
                                            <strong><?php echo e($related['title']); ?></strong>
                                            <span><?php echo e($related['location']); ?> · <?php echo e(job_public_salary($related)); ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </aside>
                    </div>
                </div>

            <?php elseif ($page === 'employer' && $employerProfile): ?>
                <?php
                    $empName = trim((string) ($employerProfile['owner_name'] ?? '')) ?: (string) $employerProfile['name'];
                    $empInitial = strtoupper(mb_substr($empName, 0, 1));
                    $empPhoto = trim((string) ($employerProfile['workplace_photo'] ?? ''));
                    $empAddress = implode(', ', array_filter([
                        $employerProfile['address'] ?? '',
                        $employerProfile['city'] ?? '',
                        $employerProfile['province'] ?? '',
                    ]));
                    $empPhone = trim((string) (($employerProfile['whatsapp'] ?? '') ?: ($employerProfile['phone'] ?? '')));
                ?>
                <div class="job-detail-page">
                    <a class="back-link" href="seeker.php?page=jobs"><i class="fa-solid fa-arrow-left"></i> Kembali ke lowongan</a>
                    <section class="employer-profile-hero">
                        <div class="vacancy-logo lg"><?php echo $empPhoto !== '' ? '<img src="' . e($empPhoto) . '" alt="">' : e($empInitial); ?></div>
                        <div>
                            <h1><?php echo e($empName); ?></h1>
                            <div class="employer-side-tags">
                                <span class="perorangan-badge">Perorangan</span>
                                <?php if (!empty($employerProfile['verified'])): ?>
                                    <span class="verified-badge"><i class="fa-solid fa-circle-check"></i> Terverifikasi</span>
                                <?php endif; ?>
                                <?php if (!empty($employerProfile['profession'])): ?>
                                    <span class="soft-pill"><?php echo e($employerProfile['profession']); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($employerProfile['employer_bio']) || !empty($employerProfile['description'])): ?>
                                <p class="employer-about"><?php echo e($employerProfile['employer_bio'] ?? $employerProfile['description'] ?? ''); ?></p>
                            <?php endif; ?>
                        </div>
                    </section>
                    <div class="job-facts">
                        <div><span>Email</span><strong><?php echo e($employerProfile['email'] ?? '-'); ?></strong></div>
                        <div><span>Kontak langsung</span><strong><?php echo e($empPhone !== '' ? $empPhone : '-'); ?></strong></div>
                        <div><span>Alamat lengkap</span><strong><?php echo e($empAddress !== '' ? $empAddress : '-'); ?></strong></div>
                    </div>
                    <h2 class="jobs-section-title">Lowongan pekerjaan pemberi kerja</h2>
                    <div class="vacancy-grid">
                        <?php if (!$employerJobs): ?>
                            <div class="section-card" style="padding:24px">Belum ada lowongan tayang dari pemberi kerja ini.</div>
                        <?php else: foreach ($employerJobs as $job): ?>
                            <?php echo render_seeker_job_card($job, $appliedIds); ?>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

            <?php elseif ($page === 'jobs'): ?>
                <div class="jobs-page-head">
                    <div>
                        <div class="jobs-kicker">Lowongan Dalam Negeri</div>
                        <h1>Lowongan Kerja Perorangan</h1>
                        <p><?php echo count($jobs); ?> lowongan disetujui siap dilamar</p>
                    </div>
                    <form method="get" class="jobs-search">
                        <input type="hidden" name="page" value="jobs">
                        <?php if ($filterJobType !== ''): ?><input type="hidden" name="job_type" value="<?php echo e($filterJobType); ?>"><?php endif; ?>
                        <?php foreach ($filterLocations as $loc): ?>
                            <input type="hidden" name="location[]" value="<?php echo e($loc); ?>">
                        <?php endforeach; ?>
                        <div class="search-small"><i class="fa-solid fa-magnifying-glass"></i><input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Cari lowongan yang kamu inginkan"></div>
                        <button class="primary-btn" type="submit">Cari</button>
                    </form>
                </div>
                <div class="jobs-layout">
                    <aside class="jobs-filter">
                        <form method="get">
                            <input type="hidden" name="page" value="jobs">
                            <input type="hidden" name="q" value="<?php echo e($search); ?>">
                            <div class="filter-head">
                                <strong>Filter</strong>
                                <a href="seeker.php?page=jobs">Reset</a>
                            </div>
                            <details class="filter-group" open>
                                <summary>Lokasi</summary>
                                <div class="filter-search"><input type="search" data-filter-location placeholder="Cari lokasi"></div>
                                <div class="filter-options" data-location-options>
                                    <?php foreach ($filterCityOptions as $index => $cityName): ?>
                                        <label class="filter-check" <?php echo $index > 7 ? 'data-extra-location hidden' : ''; ?>>
                                            <input type="checkbox" name="location[]" value="<?php echo e($cityName); ?>" <?php echo in_array($cityName, $filterLocations, true) ? 'checked' : ''; ?>>
                                            <span><?php echo e($cityName); ?></span>
                                            <?php if (!empty($locationCounts[$cityName])): ?>
                                                <em><?php echo (int) $locationCounts[$cityName]; ?></em>
                                            <?php endif; ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (count($filterCityOptions) > 8): ?>
                                    <button type="button" class="filter-more" data-toggle-locations>Lihat lebih banyak</button>
                                <?php endif; ?>
                            </details>
                            <details class="filter-group" open>
                                <summary>Tipe Pekerjaan</summary>
                                <div class="filter-options">
                                    <label class="filter-check">
                                        <input type="radio" name="job_type" value="" <?php echo $filterJobType === '' ? 'checked' : ''; ?>>
                                        <span>Semua</span>
                                    </label>
                                    <?php foreach (seeker_job_type_options() as $typeName): ?>
                                        <label class="filter-check">
                                            <input type="radio" name="job_type" value="<?php echo e($typeName); ?>" <?php echo $filterJobType === $typeName ? 'checked' : ''; ?>>
                                            <span><?php echo e($typeName); ?></span>
                                            <?php if (!empty($typeCounts[$typeName])): ?>
                                                <em><?php echo (int) $typeCounts[$typeName]; ?></em>
                                            <?php endif; ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                            <details class="filter-group">
                                <summary>Pendidikan</summary>
                                <div class="filter-options">
                                    <?php foreach (['SMA / SMK', 'D3', 'D4 / S1', 'S2'] as $edu): ?>
                                        <label class="filter-check">
                                            <input type="radio" name="education" value="<?php echo e($edu); ?>" <?php echo $filterEducation === $edu ? 'checked' : ''; ?>>
                                            <span><?php echo e($edu); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                            <details class="filter-group">
                                <summary>Bidang Pekerjaan</summary>
                                <div class="filter-options">
                                    <?php foreach (['Teknologi Informasi', 'Administrasi', 'Keuangan & Akuntansi', 'Penjualan & Marketing', 'Kuliner & Hospitality', 'Lainnya'] as $fieldName): ?>
                                        <label class="filter-check">
                                            <input type="radio" name="job_field" value="<?php echo e($fieldName); ?>" <?php echo $filterField === $fieldName ? 'checked' : ''; ?>>
                                            <span><?php echo e($fieldName); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                            <button class="primary-btn" type="submit" style="width:100%;margin-top:12px">Terapkan Filter</button>
                        </form>
                    </aside>
                    <div>
                        <div class="vacancy-grid">
                            <?php if (!$jobs): ?>
                                <div class="section-card" style="padding:24px;grid-column:1/-1">Belum ada lowongan yang sesuai filter. Coba ubah lokasi atau tipe pekerjaan.</div>
                            <?php else: foreach ($jobs as $job): ?>
                                <?php echo render_seeker_job_card($job, $appliedIds); ?>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="assets/app.js?v=seeker-jobs-1"></script>
</body>
</html>
