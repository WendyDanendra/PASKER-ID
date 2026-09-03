<?php
require __DIR__ . '/includes/bootstrap.php';

$user = require_role('admin');
$tab = $_GET['tab'] ?? 'all';
$search = trim($_GET['q'] ?? '');

// --- PROSES AKSI SETUJUI / TOLAK ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['user_id'])) {
    $action = $_POST['action'];
    $targetUserId = (int)$_POST['user_id'];
    
    if ($action === 'approve') {
        db()->prepare('UPDATE employer_profiles SET verified = 1 WHERE user_id = ?')->execute([$targetUserId]);
        flash('success', 'Akun pemberi kerja berhasil disetujui.');
    } elseif ($action === 'reject') {
        db()->prepare('UPDATE users SET profile_complete = 0 WHERE id = ?')->execute([$targetUserId]);
        flash('success', 'Akun pemberi kerja ditolak.');
    }
    
    $redirectTab = urlencode($_GET['tab'] ?? 'all');
    redirect("admin.php?tab={$redirectTab}");
    exit;
}
// ------------------------------------

$query = <<<SQL
    SELECT u.id, u.name, u.email, u.created_at, u.profile_complete, ep.phone, ep.address, ep.city, ep.province, ep.verified
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
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Individual</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
    <style>
        .admin-shell {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .admin-content {
            padding: 20px 22px 30px;
        }
        .admin-page-title {
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 18px;
        }
        .tab-row {
            display: flex;
            gap: 18px;
            border-bottom: 1px solid #eef2f7;
            margin-bottom: 18px;
            overflow-x: auto;
        }
        .tab-row a {
            padding: 10px 2px 12px;
            text-decoration: none;
            color: #64748b;
            font-size: 13px;
            font-weight: 700;
            border-bottom: 2px solid transparent;
            white-space: nowrap;
        }
        .tab-row a.active {
            color: #1e97c4;
            border-bottom-color: #1e97c4;
        }
        .admin-panel {
            background: #fff;
            border: 1px solid #e8ebf2;
            border-radius: 18px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }
        .admin-toolbar {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            padding: 16px;
        }
        .admin-toolbar .search-small { flex: 1; min-width: 240px; }
        .admin-table td, .admin-table th { vertical-align: middle; }
        .admin-empty {
            padding: 44px 16px;
            text-align: center;
            color: #64748b;
        }
        .status-chip {
            display: inline-flex;
            align-items: center;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
        }
        .status-chip.ok { background: #e8f8fc; color: #0f766e; }
        .status-chip.pending { background: #fff7ed; color: #b45309; }
        .status-chip.empty { background: #f1f5f9; color: #475569; }
    </style>
</head>
<body>
    <div class="admin-shell">
        <header class="topbar">
            <div class="crumbs">
                <span><i class="fa-solid fa-chevron-left"></i></span>
                <span>Beranda</span>
                <span>&gt;</span>
                <strong>Individual</strong>
            </div>
            <div class="search-bar">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" value="<?php echo e($search); ?>" placeholder="Cari lowongan, pemberi kerja, pencari kerja, atau...">
            </div>
            <div class="top-actions">
                <div class="notif"><i class="fa-regular fa-bell"></i></div>
                <div class="company-chip">
                    <div>
                        <strong>Admin</strong>
                        <span>Admin pusat</span>
                    </div>
                </div>
                <a class="action-chip" href="logout.php">Logout</a>
            </div>
        </header>

        <div class="admin-content">
            <div class="admin-page-title">Individual</div>

            <div class="tab-row">
                <a class="<?php echo $tab === 'all' ? 'active' : ''; ?>" href="admin.php?tab=all">Semua</a>
                <a class="<?php echo $tab === 'verified' ? 'active' : ''; ?>" href="admin.php?tab=verified">Terverifikasi</a>
                <a class="<?php echo $tab === 'process' ? 'active' : ''; ?>" href="admin.php?tab=process">Dalam Proses</a>
                <a class="<?php echo $tab === 'rejected' ? 'active' : ''; ?>" href="admin.php?tab=rejected">Ditolak</a>
            </div>

            <div class="admin-panel">
                <div class="admin-toolbar">
                    <form method="get" action="admin.php" style="flex:1; display:flex; gap:10px; flex-wrap:wrap;">
                        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="search-small"><i class="fa-solid fa-magnifying-glass"></i><input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Cari individual..."></div>
                        <button class="ghost-btn" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
                        <?php if ($search): ?>
                            <a href="admin.php?tab=<?php echo htmlspecialchars($tab, ENT_QUOTES, 'UTF-8'); ?>" class="ghost-btn" style="color:#ef4444;border-color:#fecaca;"><i class="fa-solid fa-xmark"></i> Reset</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="table-shell">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>Email</th>
                                <th>Telepon</th>
                                <th>Alamat</th>
                                <th>Lokasi</th>
                                <th>Status</th>
                                <th>Tanggal Daftar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$employers): ?>
                                <tr>
                                    <td colspan="7" class="admin-empty">Tidak ada data individual yang tersedia.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($employers as $employer): ?>
                                    <tr>
                                        <td><strong><?php echo e($employer['name']); ?></strong></td>
                                        <td><?php echo e($employer['email']); ?></td>
                                        <td><?php echo e($employer['phone'] ?? '-'); ?></td>
                                        <td><?php echo e($employer['address'] ?? '-'); ?></td>
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
                                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                                                <span><?php echo e(date('d M Y', strtotime($employer['created_at']))); ?></span>
                                                
                                                <?php if ((int) ($employer['profile_complete'] ?? 0) === 1 && (int) ($employer['verified'] ?? 0) === 0): ?>
                                                    <div style="display: flex; gap: 6px;">
                                                        <form method="post" action="admin.php" style="margin: 0;">
                                                            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab, ENT_QUOTES, 'UTF-8'); ?>">
                                                            <input type="hidden" name="action" value="approve">
                                                            <input type="hidden" name="user_id" value="<?php echo $employer['id']; ?>">
                                                            <button type="submit" class="status-chip ok" style="border:none; cursor:pointer; padding: 6px 10px;" title="Setujui">
                                                                <i class="fa-solid fa-check"></i>
                                                            </button>
                                                        </form>
                                                        <form method="post" action="admin.php" style="margin: 0;">
                                                            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab, ENT_QUOTES, 'UTF-8'); ?>">
                                                            <input type="hidden" name="action" value="reject">
                                                            <input type="hidden" name="user_id" value="<?php echo $employer['id']; ?>">
                                                            <button type="submit" class="status-chip" style="background:#fef2f2; color:#b91c1c; border:none; cursor:pointer; padding: 6px 10px;" title="Tolak">
                                                                <i class="fa-solid fa-xmark"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>