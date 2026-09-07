<?php
require __DIR__ . '/includes/bootstrap.php';

$user = require_role('admin');
$page = $_GET['page'] ?? 'individual';
$tab = $_GET['tab'] ?? 'all';
$search = trim($_GET['q'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['user_id'])) {
    $action = $_POST['action'];
    $targetUserId = (int) $_POST['user_id'];

    if ($action === 'approve') {
        db()->prepare('UPDATE employer_profiles SET verified = 1 WHERE user_id = ?')->execute([$targetUserId]);
        notify_user($targetUserId, 'Akun disetujui', 'Profil pemberi kerja Anda telah diverifikasi. Anda dapat mempublikasikan lowongan.', 'success');
        flash('success', 'Akun pemberi kerja berhasil disetujui.');
    } elseif ($action === 'reject') {
        db()->prepare('UPDATE users SET profile_complete = 0 WHERE id = ?')->execute([$targetUserId]);
        notify_user($targetUserId, 'Akun ditolak', 'Profil pemberi kerja Anda ditolak. Lengkapi ulang data untuk mengajukan verifikasi.', 'danger');
        flash('success', 'Akun pemberi kerja ditolak.');
    }

    redirect('admin.php?page=individual&tab=' . urlencode($tab));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review_job') {
    $jobId = (int) ($_POST['job_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $notes = trim($_POST['admin_notes'] ?? '');
    $reason = trim($_POST['admin_note_reason'] ?? '');

    $jobStmt = db()->prepare('SELECT * FROM job_posts WHERE id = ? LIMIT 1');
    $jobStmt->execute([$jobId]);
    $job = $jobStmt->fetch();

    if (!$job || !in_array($decision, ['revise', 'approve', 'reject'], true)) {
        flash('error', 'Keputusan tinjauan tidak valid.');
        redirect('admin.php?page=jobs');
    }

    if (!job_decision_editable($job)) {
        flash('error', 'Keputusan tidak dapat diubah karena pemberi kerja sudah membuka form revisi.');
        redirect('admin.php?page=jobs');
    }

    if ($notes === '' && $decision === 'approve') {
        $notes = job_default_approve_note();
    }

    if ($notes === '' && $decision !== 'approve' && in_array($reason, job_revision_quick_tags(), true)) {
        $notes = $reason;
    }

    if ($notes === '') {
        flash('error', 'Catatan/Alasan Admin wajib diisi sebelum menyimpan keputusan.');
        redirect('admin.php?page=jobs');
    }

    $statusMap = [
        'revise' => 'Perlu Revisi',
        'approve' => 'Tayang',
        'reject' => 'Ditolak',
    ];
    $nextStatus = $statusMap[$decision];
    db()->prepare('UPDATE job_posts SET status = ?, admin_notes = ?, revision_opened_at = NULL, updated_at = NOW() WHERE id = ?')
        ->execute([$nextStatus, $notes, $jobId]);

    if ($decision === 'revise') {
        notify_user((int) $job['user_id'], 'Lowongan perlu direvisi', 'Lowongan "' . $job['title'] . '" perlu direvisi. Catatan admin: ' . $notes, 'warning', $jobId);
    } elseif ($decision === 'approve') {
        notify_user((int) $job['user_id'], 'Lowongan disetujui', 'Lowongan "' . $job['title'] . '" telah disetujui dan kini tampil untuk pencari kerja.', 'success', $jobId);
    } else {
        notify_user((int) $job['user_id'], 'Lowongan ditolak', 'Lowongan "' . $job['title'] . '" ditolak. Alasan admin: ' . $notes, 'danger', $jobId);
    }

    flash('success', 'Keputusan lowongan berhasil disimpan.');
    redirect('admin.php?page=jobs');
}

$query = <<<SQL
    SELECT u.id, u.name, u.email, u.created_at, u.profile_complete, ep.phone, ep.whatsapp, ep.nik, ep.address, ep.city, ep.province, ep.verified, ep.profession
    FROM users u
    LEFT JOIN employer_profiles ep ON ep.user_id = u.id
    WHERE u.role = 'employer'
SQL;
$params = [];
if ($search !== '') {
    $query .= ' AND (u.name LIKE ? OR u.email LIKE ? OR ep.phone LIKE ? OR ep.city LIKE ? OR ep.address LIKE ?)';
    $like = '%' . $search . '%';
    $params = [$like, $like, $like, $like, $like];
}
if ($tab === 'verified') {
    $query .= ' AND ep.verified = 1';
} elseif ($tab === 'process') {
    $query .= ' AND (u.profile_complete = 1 AND ep.verified = 0)';
} elseif ($tab === 'rejected') {
    $query .= ' AND ep.verified = 0 AND u.profile_complete = 0';
}
$query .= ' ORDER BY u.created_at DESC';
$statement = db()->prepare($query);
$statement->execute($params);
$employers = $statement->fetchAll();

$jobs = db()->query('SELECT j.*, u.name AS employer_name, u.email AS employer_email,
        ep.phone AS employer_phone, ep.whatsapp AS employer_whatsapp, ep.profession AS employer_profession
    FROM job_posts j
    JOIN users u ON u.id = j.user_id
    LEFT JOIN employer_profiles ep ON ep.user_id = u.id
    ORDER BY j.created_at DESC')->fetchAll();

$unread = unread_notification_count((int) $user['id']);
$notifications = user_notifications((int) $user['id']);
$defaultApproveNote = job_default_approve_note();
$revisionQuickTags = job_revision_quick_tags();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Karirhub</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css?v=job-review-2">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-mark"><i class="fa-solid fa-grip-lines"></i></div>
            <div class="brand-text">
                <h1>Karirhub</h1>
                <p>Admin Pusat</p>
            </div>
        </div>
        <div class="menu-list">
            <a class="menu-item <?php echo $page === 'individual' ? 'active' : ''; ?>" href="admin.php?page=individual">
                <i class="fa-solid fa-users"></i>
                <span class="menu-label"><strong>Individual</strong><span>Data pemberi kerja</span></span>
            </a>
            <a class="menu-item <?php echo $page === 'jobs' ? 'active' : ''; ?>" href="admin.php?page=jobs">
                <i class="fa-solid fa-briefcase"></i>
                <span class="menu-label"><strong>Lowongan Kerja</strong><span>Tinjau posting baru</span></span>
            </a>
        </div>
        <div class="sidebar-spacer"></div>
        <div style="padding:0 4px">
            <div class="profile-card">
                <div class="profile-avatar">AD</div>
                <div>
                    <strong><?php echo e($user['name']); ?></strong>
                    <span>Admin pusat</span>
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
                <strong><?php echo $page === 'jobs' ? 'Lowongan Kerja' : 'Individual'; ?></strong>
            </div>
            <div class="top-actions">
                <?php echo render_notif_dropdown($notifications, $unread); ?>
                <div class="company-chip">
                    <div><strong>Admin</strong><span>Pusat</span></div>
                </div>
                <a class="action-chip" href="logout.php">Logout</a>
            </div>
        </header>

        <div class="admin-content">
            <?php if ($flash = get_flash()): ?>
                <div class="alert-box <?php echo $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>"><?php echo e($flash['message']); ?></div>
            <?php endif; ?>

            <?php if ($page !== 'jobs'): ?>
                <div class="admin-page-title">Individual</div>
                <div class="tab-row">
                    <a class="<?php echo $tab === 'all' ? 'active' : ''; ?>" href="admin.php?page=individual&tab=all">Semua</a>
                    <a class="<?php echo $tab === 'verified' ? 'active' : ''; ?>" href="admin.php?page=individual&tab=verified">Terverifikasi</a>
                    <a class="<?php echo $tab === 'process' ? 'active' : ''; ?>" href="admin.php?page=individual&tab=process">Dalam Proses</a>
                    <a class="<?php echo $tab === 'rejected' ? 'active' : ''; ?>" href="admin.php?page=individual&tab=rejected">Ditolak</a>
                </div>
                <div class="admin-panel">
                    <div class="admin-toolbar">
                        <form method="get" action="admin.php" style="flex:1;display:flex;gap:10px;flex-wrap:wrap;">
                            <input type="hidden" name="page" value="individual">
                            <input type="hidden" name="tab" value="<?php echo e($tab); ?>">
                            <div class="search-small"><i class="fa-solid fa-magnifying-glass"></i><input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Cari individual..."></div>
                            <button class="ghost-btn" type="submit">Cari</button>
                        </form>
                    </div>
                    <div class="table-shell">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th>Email</th>
                                    <th>NIK</th>
                                    <th>Telepon / WA</th>
                                    <th>Profesi</th>
                                    <th>Lokasi</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$employers): ?>
                                <tr><td colspan="8" class="admin-empty">Tidak ada data individual.</td></tr>
                            <?php else: foreach ($employers as $employer): ?>
                                <tr>
                                    <td><strong><?php echo e($employer['name']); ?></strong></td>
                                    <td><?php echo e($employer['email']); ?></td>
                                    <td><?php echo e($employer['nik'] ?? '-'); ?></td>
                                    <td><?php echo e($employer['whatsapp'] ?: ($employer['phone'] ?? '-')); ?></td>
                                    <td><?php echo e($employer['profession'] ?? '-'); ?></td>
                                    <td><?php echo e(trim(($employer['city'] ?? '-') . ', ' . ($employer['province'] ?? '-'))); ?></td>
                                    <td>
                                        <?php if ((int) ($employer['profile_complete'] ?? 0) === 1 && (int) ($employer['verified'] ?? 0) === 1): ?>
                                            <span class="status-chip ok">Terverifikasi</span>
                                        <?php elseif ((int) ($employer['profile_complete'] ?? 0) === 1): ?>
                                            <span class="status-chip pending">Dalam Proses</span>
                                        <?php else: ?>
                                            <span class="status-chip empty">Belum Lengkap / Ditolak</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int) ($employer['profile_complete'] ?? 0) === 1 && (int) ($employer['verified'] ?? 0) === 0): ?>
                                            <form method="post" style="display:inline"><input type="hidden" name="action" value="approve"><input type="hidden" name="user_id" value="<?php echo (int) $employer['id']; ?>"><button class="status-chip ok" style="border:0;cursor:pointer">Setujui</button></form>
                                            <form method="post" style="display:inline"><input type="hidden" name="action" value="reject"><input type="hidden" name="user_id" value="<?php echo (int) $employer['id']; ?>"><button class="status-chip" style="border:0;cursor:pointer;background:#fef2f2;color:#b91c1c">Tolak</button></form>
                                        <?php else: ?>-<?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php else: ?>
                <div class="admin-page-title">Lowongan Kerja</div>
                <p class="section-note" style="margin-bottom:16px">Buka <strong>Tinjau Lengkap</strong> untuk membaca seluruh data lowongan sebelum memberi keputusan. Catatan persetujuan terisi otomatis; alasan revisi atau penolakan dipilih dari daftar dan tetap dapat diedit.</p>
                <div class="admin-panel">
                    <div class="table-shell">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Lowongan</th>
                                    <th>Pemberi Kerja</th>
                                    <th>Lokasi</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$jobs): ?>
                                <tr><td colspan="5" class="admin-empty">Belum ada lowongan yang dikirim.</td></tr>
                            <?php else: foreach ($jobs as $job):
                                $meta = job_status_meta($job['status']);
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo e($job['title']); ?></strong>
                                        <div class="tiny"><?php echo e($job['job_type']); ?> · KBJI <?php echo e($job['kbji_code'] ?: '-'); ?> · <?php echo e(date('d M Y', strtotime($job['created_at']))); ?></div>
                                    </td>
                                    <td><?php echo e($job['employer_name']); ?><div class="tiny"><?php echo e($job['employer_email']); ?></div></td>
                                    <td><?php echo e($job['location']); ?></td>
                                    <td><span class="status-chip <?php echo e($meta['class']); ?>"><?php echo e($meta['label']); ?></span></td>
                                    <td>
                                        <button type="button" class="tinjau-btn" data-open-job-review="<?php echo (int) $job['id']; ?>">
                                            <i class="fa-solid fa-eye"></i>
                                            Tinjau Lengkap
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php if ($page === 'jobs' && $jobs): ?>
<div class="job-review-backdrop" data-job-review-drawer hidden>
    <div class="job-review-drawer" role="dialog" aria-modal="true" aria-labelledby="jobReviewTitle">
        <div class="job-review-header">
            <div>
                <div class="job-review-kicker">Tinjauan Lowongan</div>
                <h2 id="jobReviewTitle">Detail lengkap</h2>
            </div>
            <button type="button" class="job-review-close" data-close-job-review aria-label="Tutup"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="job-review-body" data-job-review-body></div>
    </div>
</div>
<?php foreach ($jobs as $job):
    $meta = job_status_meta($job['status']);
    $editable = job_decision_editable($job);
    $reviewable = in_array($job['status'], ['Menunggu Verifikasi', 'Perlu Revisi', 'Tayang', 'Ditolak'], true);
    $currentNotes = trim((string) ($job['admin_notes'] ?? ''));
?>
<template data-job-review-template="<?php echo (int) $job['id']; ?>">
    <div class="job-review-pack">
        <div class="job-review-summary">
            <div>
                <h3><?php echo e($job['title']); ?></h3>
                <p><?php echo e($job['employer_name']); ?> · <?php echo e($job['location']); ?></p>
            </div>
            <span class="status-chip <?php echo e($meta['class']); ?>"><?php echo e($meta['label']); ?></span>
        </div>
        <?php echo render_job_review_details($job); ?>
        <section class="job-review-section job-review-decision">
            <h3>Keputusan &amp; Catatan Admin</h3>
            <?php if ($editable): ?>
            <form method="post" class="review-form" data-review-form data-default-approve="<?php echo e($defaultApproveNote); ?>">
                <input type="hidden" name="action" value="review_job">
                <input type="hidden" name="job_id" value="<?php echo (int) $job['id']; ?>">
                <label class="review-reason-label">Alasan standar</label>
                <div class="reason-dropdown" data-reason-dropdown>
                    <input type="hidden" name="admin_note_reason" data-note-reason value="<?php echo in_array($currentNotes, $revisionQuickTags, true) ? e($currentNotes) : ''; ?>">
                    <button type="button" class="reason-dropdown-trigger" data-reason-trigger>
                        <span data-reason-label><?php echo in_array($currentNotes, $revisionQuickTags, true) ? e($currentNotes) : 'Pilih alasan standar'; ?></span>
                        <i class="fa-solid fa-chevron-down reason-chevron"></i>
                    </button>
                    <div class="reason-dropdown-menu" data-reason-menu hidden>
                        <?php foreach ($revisionQuickTags as $tag): ?>
                            <button type="button" class="reason-option<?php echo $currentNotes === $tag ? ' is-selected' : ''; ?>" data-reason-option="<?php echo e($tag); ?>"><?php echo e($tag); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <label class="review-reason-label" for="admin-notes-<?php echo (int) $job['id']; ?>">Catatan admin</label>
                <textarea id="admin-notes-<?php echo (int) $job['id']; ?>" name="admin_notes" placeholder="Catatan terisi otomatis saat Disetujui, atau dari alasan standar. Anda dapat mengedit teks ini sebelum menyimpan." required><?php echo e($currentNotes); ?></textarea>
                <p class="review-hint">Disetujui: catatan terisi otomatis. Perlu Revisi / Ditolak: pilih satu alasan, lalu edit jika perlu. Keputusan masih dapat diubah sampai pemberi kerja membuka form revisi.</p>
                <div class="review-actions">
                    <button name="decision" value="revise" class="ghost-btn<?php echo $job['status'] === 'Perlu Revisi' ? ' is-current' : ''; ?>">Perlu Revisi</button>
                    <button name="decision" value="approve" class="primary-btn<?php echo $job['status'] === 'Tayang' ? ' is-current' : ''; ?>">Disetujui</button>
                    <button name="decision" value="reject" class="ghost-btn reject-btn<?php echo $job['status'] === 'Ditolak' ? ' is-current' : ''; ?>">Ditolak</button>
                </div>
            </form>
            <?php elseif ($reviewable): ?>
            <div class="review-locked">
                <div class="tiny">Terkunci — pemberi kerja sudah membuka form revisi. Keputusan dapat diubah lagi setelah lowongan dikirim ulang.</div>
                <?php if ($currentNotes !== ''): ?>
                    <p><?php echo nl2br(e($currentNotes)); ?></p>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <p class="job-review-empty"><?php echo $currentNotes !== '' ? e($currentNotes) : 'Tidak ada tindakan tinjauan untuk status ini.'; ?></p>
            <?php endif; ?>
        </section>
    </div>
</template>
<?php endforeach; endif; ?>
<script src="assets/app.js?v=job-review-2"></script>
</body>
</html>
