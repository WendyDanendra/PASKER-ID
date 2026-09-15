<?php
require_once __DIR__ . '/includes/bootstrap.php';


$user = require_role('admin');

// Active Section & Filters
$view = $_GET['view'] ?? 'directory_individual';
$page = $_GET['page'] ?? 'individual';
$entity = $_GET['entity'] ?? 'Individu';
$tab = $_GET['tab'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$cityFilter = trim($_GET['city'] ?? '');

// --- POST HANDLERS FOR ADMIN ACTIONS ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['admin_action'])) {
    $action = $_POST['admin_action'];

    // 1. Decision for Employer Verification Case
    if ($action === 'verify_employer') {
        $targetUserId = (int)$_POST['user_id'];
        $decision = $_POST['decision']; // approve | revision | reject
        $notes = trim($_POST['verifier_notes'] ?? '');
        $checklist = isset($_POST['checklist']) ? implode(', ', $_POST['checklist']) : '';

        if (($decision === 'revision' || $decision === 'reject') && $notes === '') {
            flash('error', 'Catatan Verifikator wajib diisi untuk keputusan Revisi atau Tolak.');
        } else {
            if ($decision === 'approve') {
                $stmt = db()->prepare('UPDATE employer_profiles SET verified = 1, verification_status = "APPROVED", active_until = DATE_ADD(NOW(), INTERVAL 3 MONTH), verifier_notes = ?, verification_checklist = ? WHERE user_id = ?');
                $stmt->execute([$notes, $checklist, $targetUserId]);
                db()->prepare('UPDATE users SET profile_complete = 1 WHERE id = ?')->execute([$targetUserId]);
                flash('success', 'Profil Pemberi Kerja Individu berhasil Disetujui.');
            } elseif ($decision === 'revision') {
                $stmt = db()->prepare('UPDATE employer_profiles SET verified = 0, verification_status = "NEEDS_REVISION", verifier_notes = ?, verification_checklist = ? WHERE user_id = ?');
                $stmt->execute([$notes, $checklist, $targetUserId]);
                flash('success', 'Profil dikembalikan ke pemohon untuk diperbaiki (Perlu Diperbaiki).');
            } elseif ($decision === 'reject') {
                $stmt = db()->prepare('UPDATE employer_profiles SET verified = 0, verification_status = "REJECTED", rejection_count = rejection_count + 1, verifier_notes = ?, verification_checklist = ? WHERE user_id = ?');
                $stmt->execute([$notes, $checklist, $targetUserId]);
                flash('success', 'Profil Pemberi Kerja Individu Ditolak.');
            }
        }
        redirect("admin.php?view={$view}&entity={$entity}&tab={$tab}");
        exit;
    }

    // 2. Tangguhkan Pemberi Kerja (Suspension)
    if ($action === 'suspend_employer') {
        $targetUserId = (int)$_POST['user_id'];
        $reason = trim($_POST['suspension_reason'] ?? '');

        if ($reason === '') {
            flash('error', 'Alasan Penangguhan WAJIB diisi.');
        } else {
            $stmt = db()->prepare('UPDATE employer_profiles SET verification_status = "SUSPENDED", suspension_reason = ? WHERE user_id = ?');
            $stmt->execute([$reason, $targetUserId]);
            flash('success', 'Pemberi kerja berhasil ditangguhkan.');
        }
        redirect("admin.php?view={$view}&entity={$entity}&tab={$tab}");
        exit;
    }

    // 3. Batalkan Penangguhan
    if ($action === 'unsuspend_employer') {
        $targetUserId = (int)$_POST['user_id'];
        $stmt = db()->prepare('UPDATE employer_profiles SET verification_status = "APPROVED", suspension_reason = NULL WHERE user_id = ?');
        $stmt->execute([$targetUserId]);
        flash('success', 'Penangguhan pemberi kerja berhasil dibatalkan.');
        redirect("admin.php?view={$view}&entity={$entity}&tab={$tab}");
        exit;
    }

    // 4. Decision for Job Verification Case
    if ($action === 'verify_job') {
        $jobId = (int)$_POST['job_id'];
        $decision = $_POST['decision']; // approve | revision | reject
        $notes = trim($_POST['verifier_notes'] ?? '');
        $checklist = isset($_POST['checklist']) ? $_POST['checklist'] : [];
        $checklistStr = implode(', ', $checklist);

        if (!empty($checklist) && $notes === '') {
            flash('error', 'CATATAN VERIFIKATOR wajib diisi jika checklist pelanggaran dipilih.');
        } else {
            if ($decision === 'approve') {
                $stmt = db()->prepare('UPDATE job_posts SET status = "Tayang", verifier_notes = ?, verification_checklist = ? WHERE id = ?');
                $stmt->execute([$notes, $checklistStr, $jobId]);
                flash('success', 'Lowongan berhasil disetujui dan Tayang.');
            } elseif ($decision === 'revision') {
                $stmt = db()->prepare('UPDATE job_posts SET status = "Perlu Direvisi", verifier_notes = ?, verification_checklist = ? WHERE id = ?');
                $stmt->execute([$notes, $checklistStr, $jobId]);
                flash('success', 'Lowongan dikembalikan ke pemberi kerja (Perlu Direvisi).');
            } elseif ($decision === 'reject') {
                $stmt = db()->prepare('UPDATE job_posts SET status = "Ditolak", verifier_notes = ?, verification_checklist = ? WHERE id = ?');
                $stmt->execute([$notes, $checklistStr, $jobId]);
                flash('success', 'Lowongan Ditolak.');
            }
        }
        redirect("admin.php?view={$view}&entity={$entity}&tab={$tab}");
        exit;
    }

    // 5. Toggle Blacklist Lowongan
    if ($action === 'toggle_blacklist') {
        $jobId = (int)$_POST['job_id'];
        $stmt = db()->prepare('UPDATE job_posts SET is_blacklisted = IF(is_blacklisted = 1, 0, 1) WHERE id = ?');
        $stmt->execute([$jobId]);
        flash('success', 'Status blacklist lowongan diperbarui.');
        redirect("admin.php?view={$view}&entity={$entity}&tab={$tab}");
        exit;
    }
}

// --- FETCH DATA FOR DIRECTORY INDIVIDUAL ---
if ($view === 'directory_individual') {
    $query = <<<SQL
        SELECT u.id as user_id, u.name, u.email, u.created_at, u.profile_complete,
               ep.owner_name, ep.phone, ep.whatsapp, ep.npwp, ep.profession, ep.address, ep.city, ep.province, ep.district, ep.village,
               ep.verified, ep.verification_status, ep.suspension_reason
        FROM users u
        LEFT JOIN employer_profiles ep ON ep.user_id = u.id
        WHERE u.role = 'employer'
    SQL;
    $params = [];

    if ($search !== '') {
        $query .= ' AND (u.name LIKE ? OR u.email LIKE ? OR ep.phone LIKE ? OR ep.city LIKE ? OR ep.address LIKE ? OR ep.npwp LIKE ?)';
        $like = '%' . $search . '%';
        $params = [$like, $like, $like, $like, $like, $like];
    }

    if ($tab === 'verified') {
        $query .= ' AND ep.verification_status = "APPROVED"';
    } elseif ($tab === 'process') {
        $query .= ' AND ep.verification_status = "PENDING"';
    } elseif ($tab === 'rejected') {
        $query .= ' AND ep.verification_status IN ("REJECTED", "NEEDS_REVISION")';
    }

    $query .= ' ORDER BY u.created_at DESC';
    $stmt = db()->prepare($query);
    $stmt->execute($params);
    $individualList = $stmt->fetchAll() ?: [];
}

// --- FETCH DATA FOR VERIFIKASI PEMBERI KERJA ---
if ($view === 'verifikasi_employer') {
    $query = <<<SQL
        SELECT u.id as user_id, u.name, u.email, u.created_at,
               ep.id as profile_id, ep.owner_name, ep.nik, ep.profession, ep.phone, ep.whatsapp, ep.npwp,
               ep.province, ep.city, ep.district, ep.village, ep.postal_code, ep.address, ep.address_detail,
               ep.verified, ep.verification_status, ep.verifier_notes, ep.verification_checklist
        FROM employer_profiles ep
        JOIN users u ON u.id = ep.user_id
    SQL;
    $params = [];

    if ($entity === 'Individu') {
        $query .= ' WHERE 1=1 '; // Filter by entity type if stored
    } else {
        $query .= ' WHERE 1=1 ';
    }

    if ($tab === 'process' || $tab === 'all') {
        $query .= ' AND ep.verification_status = "PENDING"';
    } elseif ($tab === 'approved') {
        $query .= ' AND ep.verification_status = "APPROVED"';
    } elseif ($tab === 'revision') {
        $query .= ' AND ep.verification_status = "NEEDS_REVISION"';
    } elseif ($tab === 'rejected') {
        $query .= ' AND ep.verification_status = "REJECTED"';
    }

    $query .= ' ORDER BY ep.created_at DESC';
    $stmt = db()->prepare($query);
    $stmt->execute($params);
    $verificationEmployers = $stmt->fetchAll() ?: [];
}

// --- FETCH DATA FOR VERIFIKASI LOWONGAN ---
if ($view === 'verifikasi_job') {
    $query = <<<SQL
        SELECT j.*, ep.owner_name, ep.profession, ep.city as emp_city, u.name as user_name, u.email as user_email
        FROM job_posts j
        JOIN users u ON u.id = j.user_id
        LEFT JOIN employer_profiles ep ON ep.user_id = u.id
    SQL;
    $params = [];

    if ($entity === 'Individu') {
        $query .= ' WHERE j.entity_type = "Individu"';
    } elseif ($entity === 'Perusahaan') {
        $query .= ' WHERE j.entity_type = "Perusahaan"';
    } else {
        $query .= ' WHERE 1=1';
    }

    if ($tab === 'process') {
        $query .= ' AND j.status = "Dikirim/Menunggu Verifikasi"';
    } elseif ($tab === 'approved') {
        $query .= ' AND j.status = "Tayang"';
    } elseif ($tab === 'revision') {
        $query .= ' AND j.status = "Perlu Direvisi"';
    } elseif ($tab === 'rejected') {
        $query .= ' AND j.status = "Ditolak"';
    }

    $query .= ' ORDER BY j.created_at DESC';
    $stmt = db()->prepare($query);
    $stmt->execute($params);
    $verificationJobs = $stmt->fetchAll() ?: [];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'review_job') {
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
    <title>Karirhub Console - Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
    <style>
        .admin-layout { display: flex; min-height: 100vh; background: #f8fafc; }
        .admin-sidebar { width: 260px; background: #0f172a; color: #94a3b8; display: flex; flex-direction: column; flex-shrink: 0; }
        .admin-sidebar .brand { padding: 20px; font-size: 18px; font-weight: 800; color: #38bdf8; border-bottom: 1px solid #1e293b; display: flex; align-items: center; gap: 10px; }
        .admin-sidebar .menu-group { padding: 16px 12px 6px; font-size: 11px; font-weight: 800; text-transform: uppercase; color: #475569; letter-spacing: 0.5px; }
        .admin-sidebar .nav-item { display: flex; align-items: center; gap: 12px; padding: 10px 16px; border-radius: 12px; color: #cbd5e1; text-decoration: none; font-size: 13px; font-weight: 600; transition: all 0.2s; margin: 2px 10px; }
        .admin-sidebar .nav-item:hover, .admin-sidebar .nav-item.active { background: #1e293b; color: #38bdf8; }
        .admin-main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
        .admin-topbar { height: 64px; background: #fff; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; padding: 0 24px; }
        .admin-container { padding: 24px; flex: 1; overflow-y: auto; }
        
        .entity-selector { display: inline-flex; background: #e2e8f0; border-radius: 12px; padding: 3px; gap: 3px; margin-bottom: 16px; }
        .entity-btn { padding: 6px 16px; border-radius: 10px; font-size: 13px; font-weight: 700; color: #475569; text-decoration: none; transition: all 0.2s; }
        .entity-btn.active { background: #fff; color: #0284c7; box-shadow: 0 2px 6px rgba(0,0,0,0.06); }

        .admin-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: 0 4px 16px rgba(15,23,42,0.04); overflow: hidden; }
        .admin-card-header { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
        .admin-table { width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; }
        .admin-table th { background: #f8fafc; padding: 12px 16px; font-weight: 700; color: #475569; border-bottom: 1px solid #e2e8f0; }
        .admin-table td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; color: #334155; vertical-align: middle; }
        
        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .badge.ok { background: #ecfdf5; color: #047857; }
        .badge.pending { background: #fff7ed; color: #c2410c; }
        .badge.revision { background: #fef2f2; color: #b91c1c; }
        .badge.suspended { background: #fef2f2; color: #991b1b; }
    </style>
</head>
<body>
<div class="admin-layout">
    <!-- REUSE EXISTING CONSOLE SIDEBAR -->
    <aside class="admin-sidebar">
        <div class="brand">
            <i class="fa-solid fa-shield-halved"></i> Karirhub Console
        </div>
        <div class="menu-group">Pemberi Kerja</div>
        <a href="admin.php?view=directory_individual" class="nav-item <?php echo $view === 'directory_individual' ? 'active' : ''; ?>">
            <i class="fa-solid fa-users"></i> Individual (Direktori)
        </a>

        <div class="menu-group">Verifikasi</div>
        <a href="admin.php?view=verifikasi_employer&entity=Individu" class="nav-item <?php echo $view === 'verifikasi_employer' ? 'active' : ''; ?>">
            <i class="fa-solid fa-id-card"></i> Verifikasi Pemberi Kerja
        </a>
        <a href="admin.php?view=verifikasi_job&entity=Individu" class="nav-item <?php echo $view === 'verifikasi_job' ? 'active' : ''; ?>">
            <i class="fa-solid fa-file-circle-check"></i> Verifikasi Lowongan
        </a>

        <div style="margin-top:auto; padding:20px;">
            <a href="logout.php" class="nav-item" style="color:#ef4444; background:rgba(239,68,68,0.1);"><i class="fa-solid fa-right-from-bracket"></i> Logout Admin</a>
        </div>
    </aside>

    <main class="admin-main">
        <header class="admin-topbar">
            <div style="font-weight:700; font-size:15px; color:#0f172a;">
                <?php 
                    if ($view === 'directory_individual') echo 'Direktori Pemberi Kerja Individu (Read-Only)';
                    elseif ($view === 'verifikasi_employer') echo 'Verifikasi Pemberi Kerja';
                    else echo 'Verifikasi Lowongan';
                ?>
            </div>
            <div style="display:flex; align-items:center; gap:12px;">
                <span class="badge ok"><i class="fa-solid fa-user-shield"></i> Admin Pusat</span>
            </div>
        </header>

        <div class="admin-container">
            <?php if ($flash = get_flash()): ?>
                <div class="alert-box <?php echo $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>" style="margin-bottom:16px;">
                    <i class="fa-solid <?php echo $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                    <?php echo e($flash['message']); ?>
                </div>
            <?php endif; ?>

            <!-- 1. DIREKTORI INDIVIDUAL (SECTION P) -->
            <?php if ($view === 'directory_individual'): ?>
                <div style="margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                    <h2 style="font-size:20px; font-weight:800; color:#0f172a;">Direktori Pemberi Kerja Individu</h2>
                    <div style="font-size:12px; color:#64748b;"><i class="fa-solid fa-info-circle"></i> Tampilan direktori bersifat Read-Only. Verifikasi dilakukan di menu Verifikasi.</div>
                </div>

                <div class="admin-card">
                    <div class="admin-card-header">
                        <div class="tab-row" style="border:none; margin:0;">
                            <a class="<?php echo $tab === 'all' ? 'active' : ''; ?>" href="admin.php?view=directory_individual&tab=all">Semua</a>
                            <a class="<?php echo $tab === 'verified' ? 'active' : ''; ?>" href="admin.php?view=directory_individual&tab=verified">Terverifikasi</a>
                            <a class="<?php echo $tab === 'process' ? 'active' : ''; ?>" href="admin.php?view=directory_individual&tab=process">Dalam Proses</a>
                            <a class="<?php echo $tab === 'rejected' ? 'active' : ''; ?>" href="admin.php?view=directory_individual&tab=rejected">Ditolak / Perlu Perbaikan</a>
                        </div>
                        <form method="get" action="admin.php" style="display:flex; gap:8px;">
                            <input type="hidden" name="view" value="directory_individual">
                            <input type="hidden" name="tab" value="<?php echo e($tab); ?>">
                            <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Cari nama, email, NPWP..." style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:12px;">
                            <button class="primary-btn" type="submit" style="height:32px; padding:0 12px; font-size:12px;">Cari</button>
                        </form>
                    </div>
                    <div class="table-shell">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th>Email</th>
                                    <th>Telepon</th>
                                    <th>NPWP</th>
                                    <th>Alamat & Lokasi</th>
                                    <th>Status</th>
                                    <th>Tanggal Daftar</th>
                                    <th>Lihat Detail</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$individualList): ?>
                                    <tr><td colspan="8" style="text-align:center; padding:30px; color:#64748b;">Tidak ada data pemberi kerja individu.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($individualList as $emp): ?>
                                        <tr>
                                            <td><strong><?php echo e($emp['owner_name'] ?: $emp['name']); ?></strong></td>
                                            <td><?php echo e($emp['email']); ?></td>
                                            <td><?php echo e($emp['phone'] ?: '-'); ?></td>
                                            <td><code><?php echo e($emp['npwp'] ?: '-'); ?></code></td>
                                            <td><?php echo e(trim(($emp['address'] ?: '-') . ', ' . ($emp['city'] ?: '-'))); ?></td>
                                            <td>
                                                <?php if ($emp['verification_status'] === 'APPROVED'): ?>
                                                    <span class="badge ok">Terverifikasi</span>
                                                <?php elseif ($emp['verification_status'] === 'PENDING'): ?>
                                                    <span class="badge pending">Menunggu Verifikasi</span>
                                                <?php elseif ($emp['verification_status'] === 'SUSPENDED'): ?>
                                                    <span class="badge suspended">Ditangguhkan</span>
                                                <?php else: ?>
                                                    <span class="badge revision"><?php echo e($emp['verification_status']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('d M Y', strtotime($emp['created_at'])); ?></td>
                                            <td>
                                                <button type="button" class="ghost-btn" style="padding:4px 10px; font-size:11px;" data-open-drawer="detail-emp-<?php echo $emp['user_id']; ?>">
                                                    <i class="fa-solid fa-eye"></i> Detail
                                                </button>

                                                <!-- DRAWER DETAIL INDIVIDUAL READ-ONLY -->
                                                <div class="drawer-backdrop" data-drawer="detail-emp-<?php echo $emp['user_id']; ?>">
                                                    <div class="drawer-panel">
                                                        <div class="drawer-header">
                                                            <div class="drawer-title">Detail Pemberi Kerja Individu</div>
                                                            <button type="button" class="ghost-btn" data-close-drawer="detail-emp-<?php echo $emp['user_id']; ?>">×</button>
                                                        </div>
                                                        <div class="drawer-body">
                                                            <div style="background:#f8fafc; padding:16px; border-radius:12px; margin-bottom:16px;">
                                                                <h3 style="font-size:16px; font-weight:800;"><?php echo e($emp['owner_name']); ?></h3>
                                                                <p style="font-size:12px; color:#64748b;"><?php echo e($emp['profession']); ?></p>
                                                                <span class="badge <?php echo $emp['verification_status'] === 'APPROVED' ? 'ok' : 'pending'; ?>" style="margin-top:8px;">
                                                                    Status: <?php echo e($emp['verification_status']); ?>

                                                                </span>
                                                            </div>
                                                            <div style="display:grid; gap:12px; font-size:13px;">
                                                                <div><strong>Email:</strong> <?php echo e($emp['email']); ?></div>
                                                                <div><strong>Telepon / WA:</strong> <?php echo e($emp['phone']); ?> / <?php echo e($emp['whatsapp']); ?></div>
                                                                <div><strong>NPWP:</strong> <?php echo e($emp['npwp']); ?></div>
                                                                <div><strong>Wilayah:</strong> <?php echo e($emp['village']); ?>, <?php echo e($emp['district']); ?>, <?php echo e($emp['city']); ?>, <?php echo e($emp['province']); ?></div>
                                                                <div><strong>Alamat Lengkap:</strong> <?php echo e($emp['address']); ?></div>
                                                            </div>

                                                            <?php if ($emp['verification_status'] === 'APPROVED'): ?>
                                                                <hr style="margin:20px 0; border:none; border-top:1px solid #e2e8f0;">
                                                                <form method="post" action="admin.php?view=directory_individual">
                                                                    <input type="hidden" name="admin_action" value="suspend_employer">
                                                                    <input type="hidden" name="user_id" value="<?php echo $emp['user_id']; ?>">
                                                                    <div style="font-weight:700; color:#991b1b; margin-bottom:6px;">Tangguhkan Pemberi Kerja</div>
                                                                    <textarea name="suspension_reason" placeholder="Alasan penangguhan wajib diisi..." required style="width:100%; padding:8px; font-size:12px; border:1px solid #fca5a5; border-radius:8px; min-height:60px; margin-bottom:10px;"></textarea>
                                                                    <button type="submit" class="primary-btn" style="background:#dc2626; width:100%; height:36px; font-size:12px;">Tangguhkan Akun</button>
                                                                </form>
                                                            <?php elseif ($emp['verification_status'] === 'SUSPENDED'): ?>
                                                                <hr style="margin:20px 0; border:none; border-top:1px solid #e2e8f0;">
                                                                <form method="post" action="admin.php?view=directory_individual">
                                                                    <input type="hidden" name="admin_action" value="unsuspend_employer">
                                                                    <input type="hidden" name="user_id" value="<?php echo $emp['user_id']; ?>">
                                                                    <button type="submit" class="primary-btn" style="background:#059669; width:100%; height:36px; font-size:12px;">Batalkan Penangguhan Akun</button>
                                                                </form>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 2. VERIFIKASI PEMBERI KERJA (SECTION P) -->
            <?php if ($view === 'verifikasi_employer'): ?>
                <div class="entity-selector">
                    <a href="admin.php?view=verifikasi_employer&entity=Semua" class="entity-btn <?php echo $entity === 'Semua' ? 'active' : ''; ?>">Semua</a>
                    <a href="admin.php?view=verifikasi_employer&entity=Perusahaan" class="entity-btn <?php echo $entity === 'Perusahaan' ? 'active' : ''; ?>">Perusahaan</a>
                    <a href="admin.php?view=verifikasi_employer&entity=Individu" class="entity-btn <?php echo $entity === 'Individu' ? 'active' : ''; ?>">Individu</a>
                </div>

                <div class="admin-card">
                    <div class="admin-card-header">
                        <div class="tab-row" style="border:none; margin:0;">
                            <a class="<?php echo $tab === 'process' ? 'active' : ''; ?>" href="admin.php?view=verifikasi_employer&entity=<?php echo $entity; ?>&tab=process">Menunggu Verifikasi</a>
                            <a class="<?php echo $tab === 'approved' ? 'active' : ''; ?>" href="admin.php?view=verifikasi_employer&entity=<?php echo $entity; ?>&tab=approved">Disetujui</a>
                            <a class="<?php echo $tab === 'revision' ? 'active' : ''; ?>" href="admin.php?view=verifikasi_employer&entity=<?php echo $entity; ?>&tab=revision">Perlu Diperbaiki</a>
                            <a class="<?php echo $tab === 'rejected' ? 'active' : ''; ?>" href="admin.php?view=verifikasi_employer&entity=<?php echo $entity; ?>&tab=rejected">Ditolak</a>
                        </div>
                    </div>

                    <div class="table-shell">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Pemohon</th>
                                    <th>NIK & NPWP</th>
                                    <th>Profesi</th>
                                    <th>Kota/Kab</th>
                                    <th>Status Case</th>
                                    <th>Aksi Decision</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$verificationEmployers): ?>
                                    <tr><td colspan="6" style="text-align:center; padding:30px; color:#64748b;">Tidak ada antrean verifikasi pemberi kerja.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($verificationEmployers as $vEmp): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo e($vEmp['owner_name']); ?></strong><br>
                                                <small style="color:#64748b;"><?php echo e($vEmp['email']); ?></small>
                                            </td>
                                            <td>
                                                NIK: <?php echo e($vEmp['nik'] ?: '-'); ?><br>
                                                NPWP: <code><?php echo e($vEmp['npwp'] ?: '-'); ?></code>
                                            </td>
                                            <td><?php echo e($vEmp['profession']); ?></td>
                                            <td><?php echo e($vEmp['city']); ?></td>
                                            <td><span class="badge pending"><?php echo e($vEmp['verification_status']); ?></span></td>
                                            <td>
                                                <button class="primary-btn" style="height:32px; padding:0 12px; font-size:12px;" data-open-modal="modal-ver-emp-<?php echo $vEmp['user_id']; ?>">
                                                    <i class="fa-solid fa-gavel"></i> Ambil Keputusan
                                                </button>

                                                <!-- MODAL KEPUTUSAN VERIFIKASI PROFIL -->
                                                <div class="modal-backdrop" data-modal="modal-ver-emp-<?php echo $vEmp['user_id']; ?>">
                                                    <div class="modal-panel" style="width:min(600px, 90vw);">
                                                        <div class="modal-header">
                                                            <div class="modal-title">Ambil Keputusan Verifikasi Profil</div>
                                                            <div class="modal-subtitle"><?php echo e($vEmp['owner_name']); ?> (Pemberi Kerja Individu)</div>
                                                        </div>
                                                        <form method="post" action="admin.php?view=verifikasi_employer&entity=<?php echo $entity; ?>&tab=<?php echo $tab; ?>">
                                                            <input type="hidden" name="admin_action" value="verify_employer">
                                                            <input type="hidden" name="user_id" value="<?php echo $vEmp['user_id']; ?>">
                                                            <div class="modal-body">
                                                                <div style="background:#f8fafc; padding:12px; border-radius:10px; margin-bottom:14px; font-size:12px;">
                                                                    <div><strong>NPWP:</strong> <?php echo e($vEmp['npwp']); ?> | <strong>NIK:</strong> <?php echo e($vEmp['nik']); ?></div>
                                                                    <div><strong>Alamat:</strong> <?php echo e($vEmp['address']); ?>, <?php echo e($vEmp['city']); ?></div>
                                                                </div>

                                                                <div style="margin-bottom:14px;">
                                                                    <label style="font-weight:700; font-size:13px; display:block; margin-bottom:6px;">Checklist Pemeriksaan:</label>
                                                                    <div style="display:grid; gap:6px; font-size:12px;">
                                                                        <label><input type="checkbox" name="checklist[]" value="NPWP dan NIK valid"> NPWP dan NIK valid</label>
                                                                        <label><input type="checkbox" name="checklist[]" value="Lokasi tempat kerja terverifikasi"> Lokasi tempat kerja terverifikasi</label>
                                                                        <label><input type="checkbox" name="checklist[]" value="Dokumen pendukung sesuai"> Dokumen pendukung sesuai</label>
                                                                    </div>
                                                                </div>

                                                                <div style="margin-bottom:14px;">
                                                                    <label style="font-weight:700; font-size:13px; display:block; margin-bottom:6px;">Catatan Verifikator:</label>
                                                                    <textarea name="verifier_notes" placeholder="Catatan wajib jika Revisi atau Tolak..."><?php echo e($vEmp['verifier_notes']); ?></textarea>
                                                                </div>

                                                                <div style="margin-bottom:14px;">
                                                                    <label style="font-weight:700; font-size:13px; display:block; margin-bottom:6px;">Keputusan Final:</label>
                                                                    <select name="decision" required style="width:100%; padding:8px; border-radius:8px; border:1px solid #cbd5e1; font-size:13px;">
                                                                        <option value="approve">Setujui (Profil Terverifikasi 3 Bulan)</option>
                                                                        <option value="revision">Perlu Diperbaiki (Minta Pemohon Melengkapi Data)</option>
                                                                        <option value="reject">Tolak Profil</option>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="ghost-btn" data-close-modal="modal-ver-emp-<?php echo $vEmp['user_id']; ?>">Batal</button>
                                                                <button type="submit" class="primary-btn">Simpan Keputusan</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>                                     <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 3. VERIFIKASI LOWONGAN (SECTION P) -->
            <?php if ($view === 'verifikasi_job'): ?>
                <div class="entity-selector">
                    <a href="admin.php?view=verifikasi_job&entity=Semua" class="entity-btn <?php echo $entity === 'Semua' ? 'active' : ''; ?>">Semua</a>
                    <a href="admin.php?view=verifikasi_job&entity=Perusahaan" class="entity-btn <?php echo $entity === 'Perusahaan' ? 'active' : ''; ?>">Perusahaan</a>
                    <a href="admin.php?view=verifikasi_job&entity=Individu" class="entity-btn <?php echo $entity === 'Individu' ? 'active' : ''; ?>">Individu</a>
                </div>

                <div class="admin-card">
                    <div class="admin-card-header">
                        <div class="tab-row" style="border:none; margin:0;">
                            <a class="<?php echo $tab === 'process' ? 'active' : ''; ?>" href="admin.php?view=verifikasi_job&entity=<?php echo $entity; ?>&tab=process">Menunggu Verifikasi</a>
                            <a class="<?php echo $tab === 'approved' ? 'active' : ''; ?>" href="admin.php?view=verifikasi_job&entity=<?php echo $entity; ?>&tab=approved">Tayang</a>
                            <a class="<?php echo $tab === 'revision' ? 'active' : ''; ?>" href="admin.php?view=verifikasi_job&entity=<?php echo $entity; ?>&tab=revision">Perlu Direvisi</a>
                            <a class="<?php echo $tab === 'rejected' ? 'active' : ''; ?>" href="admin.php?view=verifikasi_job&entity=<?php echo $entity; ?>&tab=rejected">Ditolak</a>
                        </div>
                    </div>
                    <div class="table-shell">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Judul Lowongan</th>
                                    <th>Jenis Entitas</th>
                                    <th>Pemberi Kerja</th>
                                    <th>Status</th>
                                    <th>Blacklist</th>
                                    <th>Tanggal Pengajuan</th>
                                    <th>Lihat Detail & Keputusan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$verificationJobs): ?>
                                    <tr><td colspan="7" style="text-align:center; padding:30px; color:#64748b;">Tidak ada data lowongan dalam antrean.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($verificationJobs as $vJob): ?>
                                        <tr>
                                            <td><strong><?php echo e($vJob['title']); ?></strong><br><small style="color:#64748b;">KBJI: <?php echo e($vJob['kbji_code']); ?></small></td>
                                            <td><span class="badge ok"><?php echo e($vJob['entity_type']); ?></span></td>
                                            <td><?php echo e($vJob['owner_name'] ?: $vJob['user_name']); ?></td>
                                            <td><span class="badge pending"><?php echo e($vJob['status']); ?></span></td>
                                            <td>
                                                <form method="post" action="admin.php?view=verifikasi_job&entity=<?php echo $entity; ?>&tab=<?php echo $tab; ?>" style="display:inline;">
                                                    <input type="hidden" name="admin_action" value="toggle_blacklist">
                                                    <input type="hidden" name="job_id" value="<?php echo $vJob['id']; ?>">
                                                    <button type="submit" class="badge <?php echo $vJob['is_blacklisted'] ? 'suspended' : 'ok'; ?>" style="border:none; cursor:pointer;">
                                                        <?php echo $vJob['is_blacklisted'] ? 'Blacklisted' : 'Aman'; ?>

                                                    </button>
                                                </form>
                                            </td>
                                            <td><?php echo date('d M Y', strtotime($vJob['created_at'])); ?></td>
                                            <td>
                                                <button class="primary-btn" style="height:32px; padding:0 12px; font-size:12px;" data-open-modal="modal-job-dec-<?php echo $vJob['id']; ?>">
                                                    <i class="fa-solid fa-gavel"></i> Keputusan
                                                </button>

                                                <!-- MODAL KEPUTUSAN VERIFIKASI LOWONGAN (SECTION P) -->
                                                <div class="modal-backdrop" data-modal="modal-job-dec-<?php echo $vJob['id']; ?>">
                                                    <div class="modal-panel" style="width:min(600px, 90vw);">
                                                        <div class="modal-header">
                                                            <div class="modal-title">Keputusan Verifikasi Lowongan</div>
                                                            <div class="modal-subtitle"><?php echo e($vJob['title']); ?></div>
                                                        </div>
                                                        <form method="post" action="admin.php?view=verifikasi_job&entity=<?php echo $entity; ?>&tab=<?php echo $tab; ?>">
                                                            <input type="hidden" name="admin_action" value="verify_job">
                                                            <input type="hidden" name="job_id" value="<?php echo $vJob['id']; ?>">
                                                            <div class="modal-body">
                                                                <div style="background:#f8fafc; padding:12px; border-radius:10px; margin-bottom:14px; font-size:12px;">
                                                                    <div><strong>Lokasi:</strong> <?php echo e($vJob['location']); ?> | <strong>Tipe:</strong> <?php echo e($vJob['job_type']); ?></div>
                                                                    <div><strong>Deskripsi:</strong> <?php echo e($vJob['description']); ?></div>
                                                                </div>

                                                                <div style="margin-bottom:14px;">
                                                                    <label style="font-weight:700; font-size:13px; display:block; margin-bottom:6px;">Checklist Pelanggaran (Jika Ada):</label>
                                                                    <div style="display:grid; gap:6px; font-size:12px;">
                                                                        <label><input type="checkbox" name="checklist[]" value="Data tidak lengkap"> Data tidak lengkap</label>
                                                                        <label><input type="checkbox" name="checklist[]" value="Tidak sesuai substansi"> Tidak sesuai substansi</label>
                                                                        <label><input type="checkbox" name="checklist[]" value="Tidak sesuai dengan aturan"> Tidak sesuai dengan aturan</label>
                                                                        <label><input type="checkbox" name="checklist[]" value="Tidak sesuai dengan aturan anti diskriminasi"> Tidak sesuai dengan aturan anti diskriminasi</label>
                                                                    </div>
                                                                </div>

                                                                <div style="margin-bottom:14px;">
                                                                    <label style="font-weight:700; font-size:13px; display:block; margin-bottom:6px;">CATATAN VERIFIKATOR <small style="color:#ef4444;">(Wajib diisi jika checklist aktif)</small>:</label>
                                                                    <textarea name="verifier_notes" placeholder="Berikan catatan detail keputusan..."><?php echo e($vJob['verifier_notes']); ?></textarea>
                                                                </div>

                                                                <div style="margin-bottom:14px;">
                                                                    <label style="font-weight:700; font-size:13px; display:block; margin-bottom:6px;">Ambil Keputusan:</label>
                                                                    <select name="decision" required style="width:100%; padding:8px; border-radius:8px; border:1px solid #cbd5e1; font-size:13px;">
                                                                        <option value="approve">Setujui & Publikasikan (Tayang)</option>
                                                                        <option value="revision">Revisi (Kembalikan ke Employer)</option>
                                                                        <option value="reject">Tolak Lowongan</option>
                                                                    </select>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="ghost-btn" data-close-modal="modal-job-dec-<?php echo $vJob['id']; ?>">Batal</button>
                                                                <button type="submit" class="primary-btn">Simpan Keputusan</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<script src="assets/app.js"></script>
</body>
</html>
