<?php
$adminFile = __DIR__ . '/../admin.php';
$content = file_get_contents($adminFile);

// 1. UPDATE DIRECTORY INDIVIDUAL QUERY
$oldDirQuery = <<<'EOD'
// --- FETCH DATA FOR DIRECTORY INDIVIDUAL ---
if ($view === 'directory_individual') {
    $query = <<<SQL
        SELECT u.id as user_id, u.name, u.email, u.created_at, u.profile_complete,
               ep.id as profile_id, ep.owner_name, ep.nik, ep.phone, ep.whatsapp, ep.npwp, ep.profession, ep.address, ep.address_detail,
               ep.city, ep.province, ep.district, ep.village, ep.postal_code, ep.latitude, ep.longitude, ep.description,
               ep.verified, ep.verification_status, ep.suspension_reason, ep.extension_status, ep.verifier_notes, ep.verification_checklist,
               ep.manual_review_status, ep.assigned_to, ep.assigned_at, ep.rejection_count, ep.entity_type,
               ep.active_until, ep.last_activated_at, ep.domicile_city_id
        FROM users u
        LEFT JOIN employer_profiles ep ON ep.user_id = u.id
        WHERE u.role = 'employer'
    SQL;
    $params = [];

    // Filter for Individual entity type
    $query .= ' AND (ep.entity_type = "Individu" OR ep.entity_type IS NULL)';

    if ($search !== '') {
        $query .= ' AND (u.name LIKE ? OR u.email LIKE ? OR ep.phone LIKE ? OR ep.city LIKE ? OR ep.address LIKE ? OR ep.npwp LIKE ?)';
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
    }

    if ($startDate !== '') {
        $query .= ' AND DATE(u.created_at) >= ?';
        $params[] = $startDate;
    }
    if ($endDate !== '') {
        $query .= ' AND DATE(u.created_at) <= ?';
        $params[] = $endDate;
    }
    if ($cityFilter !== '') {
        $query .= ' AND (ep.city LIKE ? OR ep.domicile_city_id LIKE ? OR ep.province LIKE ?)';
        $cityLike = '%' . $cityFilter . '%';
        $params = array_merge($params, [$cityLike, $cityLike, $cityLike]);
    }

    if ($tab === 'verified') {
        $query .= ' AND ep.verification_status = "APPROVED"';
    } elseif ($tab === 'process') {
        $query .= ' AND ep.verification_status = "PENDING"';
    } elseif ($tab === 'rejected') {
        $query .= ' AND ep.verification_status IN ("REJECTED", "NEEDS_REVISION")';
    }

    // Scope Admin Dinas Filter (exact domicile_city_id match)
    if ($user['role'] === 'admin_dinas' || (!empty($user['domicile_city_id']) && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
        $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
        if ($adminDomicileCity !== '') {
            $query .= ' AND ep.domicile_city_id = ?';
            $params[] = $adminDomicileCity;
        }
    }

    $sort = $_GET['sort'] ?? 'date_desc';
    if ($sort === 'name_asc') {
        $query .= ' ORDER BY COALESCE(NULLIF(ep.owner_name, ""), u.name) ASC';
    } elseif ($sort === 'name_desc') {
        $query .= ' ORDER BY COALESCE(NULLIF(ep.owner_name, ""), u.name) DESC';
    } elseif ($sort === 'date_asc') {
        $query .= ' ORDER BY u.created_at ASC';
    } else {
        $query .= ' ORDER BY u.created_at DESC';
    }

    $stmt = db()->prepare($query);
    $stmt->execute($params);
    $individualList = $stmt->fetchAll() ?: [];

    $perPage = 20;
    $totalData = count($individualList);
    $totalPages = max(1, (int)ceil($totalData / $perPage));
    $page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
    $showingList = array_slice($individualList, ($page - 1) * $perPage, $perPage);
    $showingCount = count($showingList);

    // If detail_id is requested, find that employer
    $selectedEmployer = null;
    $auditLogs = [];
    if ($detailId > 0) {
        $stmtSel = db()->prepare('SELECT u.id as user_id, u.name, u.email, u.created_at, u.profile_complete, ep.* FROM employer_profiles ep JOIN users u ON u.id = ep.user_id WHERE ep.user_id = ? LIMIT 1');
        $stmtSel->execute([$detailId]);
        $selectedEmployer = $stmtSel->fetch();
        if ($selectedEmployer) {
            $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
            if ($user['role'] === 'admin_dinas' || ($adminDomicileCity !== '' && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
                $empDomicileCity = (string)($selectedEmployer['domicile_city_id'] ?? '');
                if ($empDomicileCity === '' || $empDomicileCity !== $adminDomicileCity) {
                    $selectedEmployer = null;
                }
            }
            if ($selectedEmployer) {
                $auditLogs = fetch_audit_logs('employer', $detailId);
            }
        }
    }
}
EOD;

$newDirQuery = <<<'EOD'
// --- FETCH DATA FOR DIRECTORY INDIVIDUAL ---
if ($view === 'directory_individual') {
    $query = <<<SQL
        SELECT u.id as user_id, u.name, u.email, u.created_at, u.profile_complete,
               ep.id as profile_id, ep.owner_name, ep.nik, ep.phone, ep.whatsapp, ep.npwp, ep.profession, ep.address, ep.address_detail,
               ep.city, ep.province, ep.district, ep.village, ep.postal_code, ep.latitude, ep.longitude, ep.description,
               ep.verified, ep.verification_status, ep.suspension_reason, ep.extension_status, ep.verifier_notes, ep.verification_checklist,
               ep.manual_review_status, ep.assigned_to, ep.assigned_at, ep.rejection_count, ep.entity_type,
               ep.active_until, ep.last_activated_at, ep.domicile_city_id,
               ep.doc_permission, ep.permit_document, ep.doc_location_photo, ep.workplace_photo, ep.social_media, ep.instagram, ep.linkedin, ep.facebook
        FROM users u
        LEFT JOIN employer_profiles ep ON ep.user_id = u.id
        WHERE u.role = 'employer'
    SQL;
    $params = [];

    // Filter for Individual entity type
    $query .= ' AND (ep.entity_type = "Individu" OR ep.entity_type = "Individual" OR ep.entity_type IS NULL)';

    if ($search !== '') {
        $query .= ' AND (u.name LIKE ? OR u.email LIKE ? OR ep.phone LIKE ? OR ep.city LIKE ? OR ep.address LIKE ? OR ep.npwp LIKE ? OR ep.owner_name LIKE ?)';
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like]);
    }

    if ($startDate !== '') {
        $query .= ' AND DATE(u.created_at) >= ?';
        $params[] = $startDate;
    }
    if ($endDate !== '') {
        $query .= ' AND DATE(u.created_at) <= ?';
        $params[] = $endDate;
    }
    if ($cityFilter !== '') {
        $query .= ' AND (ep.city LIKE ? OR ep.domicile_city_id LIKE ? OR ep.province LIKE ?)';
        $cityLike = '%' . $cityFilter . '%';
        $params = array_merge($params, [$cityLike, $cityLike, $cityLike]);
    }

    if ($tab === 'verified') {
        $query .= ' AND (ep.verification_status IN ("APPROVED", "ACTIVE_VERIFIED") OR ep.verified = 1)';
    } elseif ($tab === 'process') {
        $query .= ' AND (ep.verification_status IN ("PENDING", "NOT_SUBMITTED", "SUBMITTED", "IN_PROCESS") OR ep.verification_status IS NULL OR ep.verified = 0) AND (ep.verification_status NOT IN ("APPROVED", "ACTIVE_VERIFIED", "REJECTED"))';
    } elseif ($tab === 'rejected') {
        $query .= ' AND (ep.verification_status IN ("REJECTED", "NEEDS_REVISION", "FULL_DISABLED"))';
    }

    // Scope Admin Dinas Filter
    if ($user['role'] === 'admin_dinas' && !empty($user['domicile_city_id'])) {
        $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
        if ($adminDomicileCity !== '') {
            $query .= ' AND (ep.domicile_city_id = ? OR ep.city LIKE ? OR ep.domicile_city_id IS NULL OR ep.city IS NULL)';
            $params[] = $adminDomicileCity;
            $params[] = '%' . $adminDomicileCity . '%';
        }
    }

    $sort = $_GET['sort'] ?? 'date_desc';
    if ($sort === 'name_asc') {
        $query .= ' ORDER BY COALESCE(NULLIF(ep.owner_name, ""), u.name) ASC';
    } elseif ($sort === 'name_desc') {
        $query .= ' ORDER BY COALESCE(NULLIF(ep.owner_name, ""), u.name) DESC';
    } elseif ($sort === 'date_asc') {
        $query .= ' ORDER BY u.created_at ASC';
    } else {
        $query .= ' ORDER BY u.created_at DESC';
    }

    $stmt = db()->prepare($query);
    $stmt->execute($params);
    $individualList = $stmt->fetchAll() ?: [];

    $perPage = 20;
    $totalData = count($individualList);
    $totalPages = max(1, (int)ceil($totalData / $perPage));
    $page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
    $showingList = array_slice($individualList, ($page - 1) * $perPage, $perPage);
    $showingCount = count($showingList);

    // If detail_id is requested, find that employer
    $selectedEmployer = null;
    $auditLogs = [];
    if ($detailId > 0) {
        $stmtSel = db()->prepare('SELECT u.id as user_id, u.name, u.email, u.created_at, u.profile_complete, u.city as u_city, ep.* FROM users u LEFT JOIN employer_profiles ep ON ep.user_id = u.id WHERE u.id = ? LIMIT 1');
        $stmtSel->execute([$detailId]);
        $selectedEmployer = $stmtSel->fetch();
        if ($selectedEmployer) {
            $auditLogs = fetch_audit_logs('employer', $detailId);
        }
    }
}
EOD;

// Normalize line endings for replacement
$contentNorm = str_replace("\r\n", "\n", $content);
$oldDirNorm = str_replace("\r\n", "\n", $oldDirQuery);
$newDirNorm = str_replace("\r\n", "\n", $newDirQuery);

if (strpos($contentNorm, $oldDirNorm) !== false) {
    $contentNorm = str_replace($oldDirNorm, $newDirNorm, $contentNorm);
    echo "Directory query replaced successfully.\n";
} else {
    echo "WARNING: Could not find oldDirQuery in content.\n";
}

// 2. UPDATE VERIFIKASI EMPLOYER QUERY
$oldVerQuery = <<<'EOD'
// --- FETCH DATA FOR VERIFIKASI PEMBERI KERJA ---
if ($view === 'verifikasi_employer') {
    $query = <<<SQL
        SELECT u.id as user_id, u.name, u.email, u.created_at,
               ep.id as profile_id, ep.owner_name, ep.nik, ep.profession, ep.phone, ep.whatsapp, ep.npwp,
               ep.province, ep.city, ep.district, ep.village, ep.postal_code, ep.address, ep.address_detail,
               ep.latitude, ep.longitude, ep.description, ep.verified, ep.verification_status, ep.verifier_notes,
               ep.verification_checklist, ep.assigned_to, ep.assigned_at, ep.assignment_reason, ep.rejection_count,
               ep.manual_review_status, ep.consent_data_hash, ep.consent_given_at, ep.officer_statement, ep.officer_name, ep.entity_type
        FROM employer_profiles ep
        JOIN users u ON u.id = ep.user_id
    SQL;
    $params = [];

    if ($entity === 'Individu') {
        $query .= ' WHERE (ep.entity_type = "Individu" OR ep.entity_type = "Individual" OR ep.entity_type IS NULL)';
    } elseif ($entity === 'Perusahaan') {
        $query .= ' WHERE ep.entity_type = "Perusahaan"';
    } else {
        $query .= ' WHERE 1=1';
    }
EOD;

$newVerQuery = <<<'EOD'
// --- FETCH DATA FOR VERIFIKASI PEMBERI KERJA ---
if ($view === 'verifikasi_employer') {
    $query = <<<SQL
        SELECT u.id as user_id, u.name, u.email, u.created_at,
               ep.id as profile_id, ep.owner_name, ep.nik, ep.profession, ep.phone, ep.whatsapp, ep.npwp,
               ep.province, ep.city, ep.district, ep.village, ep.postal_code, ep.address, ep.address_detail,
               ep.latitude, ep.longitude, ep.description, ep.verified, ep.verification_status, ep.verifier_notes,
               ep.verification_checklist, ep.assigned_to, ep.assigned_at, ep.assignment_reason, ep.rejection_count,
               ep.manual_review_status, ep.consent_data_hash, ep.consent_given_at, ep.officer_statement, ep.officer_name, ep.entity_type,
               ep.doc_permission, ep.permit_document, ep.doc_location_photo, ep.workplace_photo, ep.social_media, ep.instagram, ep.linkedin, ep.facebook, ep.domicile_city_id
        FROM users u
        LEFT JOIN employer_profiles ep ON ep.user_id = u.id
        WHERE u.role = 'employer'
    SQL;
    $params = [];

    if ($entity === 'Individu') {
        $query .= ' AND (ep.entity_type = "Individu" OR ep.entity_type = "Individual" OR ep.entity_type IS NULL)';
    } elseif ($entity === 'Perusahaan') {
        $query .= ' AND ep.entity_type = "Perusahaan"';
    }
EOD;

$oldVerNorm = str_replace("\r\n", "\n", $oldVerQuery);
$newVerNorm = str_replace("\r\n", "\n", $newVerQuery);

if (strpos($contentNorm, $oldVerNorm) !== false) {
    $contentNorm = str_replace($oldVerNorm, $newVerNorm, $contentNorm);
    echo "Verifikasi query replaced successfully.\n";
} else {
    echo "WARNING: Could not find oldVerQuery in content.\n";
}

// 3. UPDATE DETAIL VIEW IN DIRECTORY INDIVIDUAL
$oldDetailBlock = <<<'EOD'
            <?php if ($view === 'directory_individual'): ?>
                <?php if ($selectedEmployer):
                    $selectedEmpStatus = get_employer_access_status($selectedEmployer);
                    $selectedSiklusTerakhir = format_cycle_range($selectedEmployer['last_activated_at'] ?? null, $selectedEmployer['active_until'] ?? null);
                    $adminCity = (string)($user['domicile_city_id'] ?? '');
                    $isScopeMatchSelected = ($user['role'] === 'admin' || $user['role'] === 'admin_pusat' || empty($adminCity) || ($selectedEmployer['domicile_city_id'] ?? '') === $adminCity);
                    $canReactivateSelected = ($selectedEmpStatus['can_direct_reactivate'] && $isScopeMatchSelected);
                ?>
                    <!-- DETAIL VIEW FOR DIRECTORY (STRICTLY READ-ONLY) -->
                    <div style="margin-bottom:16px;">
                        <a href="admin.php?view=directory_individual&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>" class="btn-lihat-detail">
                            <i class="fa-solid fa-arrow-left"></i> Kembali
                        </a>
                </div>

                    <div class="detail-header-bar">
                        <div style="display:flex; align-items:center; gap:16px;">
                            <div class="item-avatar-box" style="width:52px; height:52px; font-size:18px;">
                                <?php echo strtoupper(substr($selectedEmployer['owner_name'] ?: $selectedEmployer['name'], 0, 2)); ?>
                            </div>
                            <div>
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <h1 style="font-size:20px; font-weight:800; margin:0;"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></h1>
                                    <span class="pill-badge <?php echo $selectedEmpStatus['badge_class']; ?>">
                                        ● <?php echo e($selectedEmpStatus['label']); ?>
                                    </span>
                                </div>
                                <div style="font-size:12px; color:#64748b; margin-top:4px;">
                                    Slug: <code><?php echo strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $selectedEmployer['owner_name'] ?: $selectedEmployer['name'])); ?></code> • 
                                    Didaftarkan: <?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?> • 
                                    <?php echo e($selectedEmployer['domicile_city_id'] ?: $selectedEmployer['city'] ?: 'Kota Belum Diisi'); ?>
                                </div>
                            </div>
                        </div>

                        <div style="display:flex; gap:10px; align-items:center;">
                            <button type="button" class="btn-lihat-detail" data-open-modal="modal-ver-info">
                                <i class="fa-solid fa-shield-halved"></i> Lihat Rincian Verifikasi
                            </button>
                            <?php if ($canReactivateSelected): ?>
                                <button type="button" class="btn-lihat-detail" style="color:#0284c7; border-color:#93c5fd; background:#eff6ff; font-weight:700;" data-open-modal="modal-reactivate-<?php echo $selectedEmployer['user_id']; ?>">
                                    <i class="fa-solid fa-arrows-rotate"></i> Reaktivasi Hak Akses
                                </button>
                            <?php elseif ($selectedEmpStatus['is_online_reactivation_pending']): ?>
                                <span class="pill-badge warning" style="display:inline-flex; align-items:center; gap:6px; font-size:12px; padding:6px 12px;">
                                    <i class="fa-solid fa-hourglass-half"></i> Permohonan reaktivasi online sedang dalam proses verifikasi
                                </span>
                            <?php endif; ?>
                            <?php if ($selectedEmpStatus['is_active']): ?>
                                <button type="button" class="btn-lihat-detail" style="color:#dc2626; border-color:#fca5a5;" data-open-modal="modal-suspend">
                                    <i class="fa-solid fa-ban"></i> Tangguhkan
                                </button>
                            <?php elseif ($selectedEmpStatus['status'] === 'SUSPENDED'): ?>
                                <form method="post" action="admin.php?view=directory_individual&detail_id=<?php echo $selectedEmployer['user_id']; ?>" style="margin:0;">
                                    <input type="hidden" name="admin_action" value="unsuspend_employer">
                                    <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                    <button type="submit" class="btn-lihat-detail" style="color:#059669; border-color:#a7f3d0;">
                                        <i class="fa-solid fa-rotate-left"></i> Batalkan Penangguhan
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="detail-grid-container">
                        <!-- LEFT COLUMN: CARDS -->
                        <div>
                            <!-- RINGKASAN & INFORMASI UMUM -->
                            <div class="section-card">
                                <div class="section-card-title">Informasi Umum</div>
                                <div class="key-val-grid">
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-user"></i> Nama Lengkap</div>
                                        <div class="value"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-envelope"></i> Email</div>
                                        <div class="value"><?php echo e($selectedEmployer['email']); ?></div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-phone"></i> Telepon / WhatsApp</div>
                                        <div class="value"><?php echo e($selectedEmployer['phone'] ?: '-'); ?> / <?php echo e($selectedEmployer['whatsapp'] ?: '-'); ?></div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-briefcase"></i> Jenis Usaha / Profesi</div>
                                        <div class="value"><?php echo e($selectedEmployer['profession'] ?: '-'); ?></div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-calendar"></i> Tanggal Daftar</div>
                                        <div class="value"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-clock"></i> Masa Aktif Hingga</div>
                                        <div class="value"><?php echo !empty($selectedEmployer['active_until']) ? date('d M Y, H:i', strtotime($selectedEmployer['active_until'])) : '-'; ?></div>
                                    </div>
                                </div>
                            </div>

                            <!-- LOKASI -->
                            <div class="section-card">
                                <div class="section-card-title">Lokasi</div>
                                <div class="key-val-grid">
                                    <div class="key-val-item" style="grid-column: span 2;">
                                        <div class="label"><i class="fa-solid fa-location-dot"></i> Alamat Lengkap</div>
                                        <div class="value"><?php echo e($selectedEmployer['address'] ?: '-'); ?> <?php echo !empty($selectedEmployer['address_detail']) ? '(' . e($selectedEmployer['address_detail']) . ')' : ''; ?></div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-map"></i> Wilayah Administratif</div>
                                        <div class="value"><?php echo e(implode(', ', array_filter([$selectedEmployer['village'], $selectedEmployer['district'], $selectedEmployer['city'], $selectedEmployer['province']])) ?: '-'); ?></div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-compass"></i> Koordinat</div>
                                        <div class="value"><?php echo e($selectedEmployer['latitude'] ?: '-'); ?>, <?php echo e($selectedEmployer['longitude'] ?: '-'); ?></div>
                                    </div>
                                </div>
                                <div class="map-box-placeholder">
                                    <i class="fa-solid fa-map-location-dot" style="font-size:24px; margin-right:8px;"></i>
                                    Peta Lokasi: <?php echo e($selectedEmployer['latitude'] ?: '-6.241586'); ?>, <?php echo e($selectedEmployer['longitude'] ?: '106.992416'); ?>
                                </div>
                            </div>

                            <!-- INFORMASI USAHA & NPWP -->
                            <div class="section-card">
                                <div class="section-card-title">Informasi Usaha & Legalitas</div>
                                <div class="key-val-grid">
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-id-card"></i> NIK (SIAPkerja)</div>
                                        <div class="value"><code><?php echo e($selectedEmployer['nik'] ?: '-'); ?></code></div>
                                    </div>
                                    <div class="key-val-item">
                                        <div class="label"><i class="fa-solid fa-file-invoice"></i> NPWP</div>
                                        <div class="value"><code><?php echo e($selectedEmployer['npwp'] ?: '-'); ?></code></div>
                                    </div>
                                </div>
                            </div>

                            <!-- DESKRIPSI -->
                            <div class="section-card">
                                <div class="section-card-title">Deskripsi Usaha / Profil</div>
                                <div style="font-size:13px; color:#334155; line-height:1.6;">
                                    <?php echo nl2br(e($selectedEmployer['description'] ?: 'Tidak ada deskripsi yang dicantumkan.')); ?>
                                </div>
                            </div>

                            <!-- PERPANJANGAN HAK AKSES PEMBERI KERJA INDIVIDU IF REQUESTED -->
                            <?php if (($selectedEmployer['extension_status'] ?? '') === 'REQUESTED'): ?>
                                <div class="section-card" style="border:1px solid #fde68a; background:#fffbeb;">
                                    <div class="section-card-title" style="color:#92400e;">
                                        <i class="fa-solid fa-clock-rotate-left"></i> Permohonan Perpanjangan Hak Akses Pemberi Kerja Individu
                                    </div>
                                    <p style="font-size:13px; color:#78350f; margin-bottom:12px;">
                                        Pemberi kerja ini mengajukan perpanjangan Hak Akses Pemberi Kerja Individu (maksimal 1x per siklus). Silakan tentukan durasi yang disetujui (1, 2, atau 3 hari):
                                    </p>
                                    <form method="post" action="admin.php?view=directory_individual&detail_id=<?php echo $selectedEmployer['user_id']; ?>" style="display:flex; gap:12px; align-items:center;">
                                        <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                        <label style="font-size:13px; font-weight:700; color:#78350f;">Durasi:</label>
                                        <select name="extension_days" style="height:36px; padding:0 12px; border-radius:8px; border:1px solid #fcd34d; font-size:13px;">
                                            <option value="1">1 Hari</option>
                                            <option value="2">2 Hari</option>
                                            <option value="3" selected>3 Hari</option>
                                        </select>
                                        <button type="submit" name="admin_action" value="approve_extension" class="primary-btn" style="background:#059669; height:36px; padding:0 16px; font-size:12px;">
                                            <i class="fa-solid fa-check"></i> Setujui
                                        </button>
                                        <button type="submit" name="admin_action" value="reject_extension" class="ghost-btn" style="color:#dc2626; border-color:#fecaca; height:36px; padding:0 16px; font-size:12px;">
                                            <i class="fa-solid fa-xmark"></i> Tolak
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- RIGHT COLUMN: AUDIT LOG TIMELINE -->
                        <div>
                            <div class="section-card">
                                <div class="section-card-title">Aktivitas & Audit Log</div>
                                <div class="timeline-list">
                                    <div class="timeline-item">
                                        <div class="timeline-dot"></div>
                                        <div class="timeline-time"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                        <div class="timeline-title">Pemberi kerja mendaftar di platform.</div>
                                    </div>
                                    <?php foreach ($auditLogs as $log): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-dot"></div>
                                            <div class="timeline-time"><?php echo date('d M Y, H:i', strtotime($log['created_at'])); ?></div>
                                            <div class="timeline-title"><?php echo e($log['action']); ?> <small style="color:#64748b;">(oleh <?php echo e($log['actor_name']); ?>)</small></div>
                                            <div class="timeline-desc"><?php echo e($log['details']); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- MODAL RINCIAN VERIFIKASI -->
                    <div class="modal-backdrop" data-modal="modal-ver-info">
                        <div class="modal-panel" style="width:min(540px, 90vw);">
                            <div class="modal-header">
                                <div class="modal-title">Rincian Verifikasi Pemberi Kerja</div>
                                <div class="modal-subtitle"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></div>
                            </div>
                            <div class="modal-body">
                                <div style="background:#f8fafc; padding:14px; border-radius:10px; margin-bottom:14px; font-size:13px;">
                                    <div><strong>Status Verifikasi:</strong> <?php echo e($selectedEmployer['verification_status']); ?></div>
                                    <div style="margin-top:4px;"><strong>Pemeriksa:</strong> <?php echo e($selectedEmployer['assigned_to'] ?: 'Belum ditugaskan'); ?></div>
                                    <div style="margin-top:4px;"><strong>Catatan Verifikator:</strong> <?php echo e($selectedEmployer['verifier_notes'] ?: 'Belum ada catatan verifikasi.'); ?></div>
                                    <?php if (!empty($selectedEmployer['verification_checklist'])): ?>
                                        <div style="margin-top:4px;"><strong>Hasil Checklist:</strong> <?php echo e($selectedEmployer['verification_checklist']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="ghost-btn" data-close-modal="modal-ver-info">Tutup</button>
                            </div>
                        </div>
                    </div>

                    <!-- MODAL SUSPEND -->
                    <div class="modal-backdrop" data-modal="modal-suspend">
                        <div class="modal-panel" style="width:min(500px, 90vw);">
                            <div class="modal-header">
                                <div class="modal-title" style="color:#dc2626;">Tangguhkan Pemberi Kerja</div>
                                <div class="modal-subtitle"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></div>
                            </div>
                            <form method="post" action="admin.php?view=directory_individual&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
                                <input type="hidden" name="admin_action" value="suspend_employer">
                                <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                <div class="modal-body">
                                    <div style="font-size:13px; color:#475569; margin-bottom:12px;">
                                        Penangguhan Hak Akses Pemberi Kerja Individu akan membatasi aksi operasional pemohon tanpa menonaktifkan akun SIAPkerja. Masukkan alasan penangguhan:
                                    </div>
                                    <textarea name="suspension_reason" required placeholder="Alasan penangguhan wajib diisi..." style="width:100%; min-height:80px; padding:10px; border-radius:8px; border:1px solid #fca5a5; font-size:13px;"></textarea>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="ghost-btn" data-close-modal="modal-suspend">Batal</button>
                                    <button type="submit" class="primary-btn" style="background:#dc2626;">Tangguhkan Sekarang</button>
                                </div>
                            </form>
                        <!-- MODAL REACTIVATE FOR DETAIL VIEW -->
                    <?php if ($selectedEmployer && $canReactivateSelected): ?>
                        <div class="modal-backdrop" data-modal="modal-reactivate-<?php echo $selectedEmployer['user_id']; ?>">
                            <div class="modal-panel" style="width:min(540px, 92vw);">
                                <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center;">
                                    <div>
                                        <div class="modal-title" style="color:#0284c7; font-size:16px; font-weight:800; display:flex; align-items:center; gap:8px;">
                                            <i class="fa-solid fa-arrows-rotate"></i> KONFIRMASI REAKTIVASI HAK AKSES
                                        </div>
                                    </div>
                                    <button type="button" class="close-btn" data-close-modal="modal-reactivate-<?php echo $selectedEmployer['user_id']; ?>" style="background:none; border:none; font-size:18px; color:#94a3b8; cursor:pointer;">&times;</button>
                                </div>
                                <form method="post" action="admin.php?view=directory_individual&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
                                    <input type="hidden" name="admin_action" value="reactivate_employer_access">
                                    <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                    <div class="modal-body" style="padding:16px 20px;">
                                        <p style="margin:0 0 14px 0; color:#475569; font-size:13px; line-height:1.5;">
                                            Anda akan mengaktifkan kembali <strong>Hak Akses Pemberi Kerja Individu</strong> berikut:
                                        </p>

                                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px; margin-bottom:14px; font-size:13px; display:grid; grid-template-columns:130px 1fr; row-gap:8px;">
                                            <span style="color:#64748b;">Nama</span>
                                            <strong style="color:#0f172a;"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></strong>

                                            <span style="color:#64748b;">NIK</span>
                                            <code style="color:#0f172a; font-weight:600;"><?php echo e($selectedEmployer['nik'] ?: '-'); ?></code>

                                            <span style="color:#64748b;">Lokasi Domisili</span>
                                            <span style="color:#0f172a;"><?php echo e($selectedEmployer['domicile_city_id'] ?: $selectedEmployer['city'] ?: '-'); ?></span>

                                            <span style="color:#64748b;">Status Saat Ini</span>
                                            <span style="color:#dc2626; font-weight:600;">Tidak Aktif</span>
                                        </div>

                                        <div style="background:#f1f5f9; border-left:4px solid #0284c7; padding:10px 14px; border-radius:0 6px 6px 0; margin-bottom:14px;">
                                            <div style="font-size:11px; text-transform:uppercase; font-weight:700; color:#64748b; margin-bottom:2px;">Siklus Terakhir</div>
                                            <div style="font-size:13px; font-weight:600; color:#0f172a;"><?php echo e($selectedSiklusTerakhir); ?></div>
                                        </div>

                                        <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:12px 14px; font-size:12.5px; color:#1e40af; line-height:1.5;">
                                            <i class="fa-solid fa-circle-info" style="margin-right:4px;"></i>
                                            Hak Akses akan langsung aktif kembali selama <strong>3 bulan</strong> sejak tanggal reaktivasi ini.<br>
                                            Reaktivasi melalui Admin Dinas tidak memerlukan proses verifikasi lanjutan.
                                        </div>
                                    </div>
                                    <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:10px;">
                                        <button type="button" class="ghost-btn" data-close-modal="modal-reactivate-<?php echo $selectedEmployer['user_id']; ?>">Batal</button>
                                        <button type="submit" class="primary-btn" style="background:#0284c7; display:inline-flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-arrows-rotate"></i> Reaktivasi Hak Akses
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
EOD;

$newDetailBlock = <<<'EOD'
            <?php if ($view === 'directory_individual'): ?>
                <?php if ($selectedEmployer):
                    $selectedEmpStatus = get_employer_access_status($selectedEmployer);
                    $selectedSiklusTerakhir = format_cycle_range($selectedEmployer['last_activated_at'] ?? null, $selectedEmployer['active_until'] ?? null);
                    $adminCity = (string)($user['domicile_city_id'] ?? '');
                    $isScopeMatchSelected = ($user['role'] === 'admin' || $user['role'] === 'admin_pusat' || empty($adminCity) || ($selectedEmployer['domicile_city_id'] ?? '') === $adminCity);
                    $canReactivateSelected = ($selectedEmpStatus['can_direct_reactivate'] && $isScopeMatchSelected);
                    $displayName = $selectedEmployer['owner_name'] ?: $selectedEmployer['name'];
                    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $displayName)) . '-x7k2p';
                    
                    $vStatus = $selectedEmployer['verification_status'] ?? '';
                    if ($vStatus === 'APPROVED' || !empty($selectedEmployer['verified'])) {
                        $headerBadgeClass = 'verified';
                        $headerBadgeText = 'Terverifikasi';
                        $headerStatusBadge = '<span class="pill-badge verified" style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; font-weight:700; font-size:12px; padding:4px 12px; border-radius:999px; display:inline-flex; align-items:center; gap:6px;"><span style="font-size:8px;">●</span> Terverifikasi</span>';
                    } elseif ($vStatus === 'NEEDS_REVISION') {
                        $headerStatusBadge = '<span class="pill-badge revision" style="background:#fef3c7; color:#b45309; border:1px solid #fde68a; font-weight:700; font-size:12px; padding:4px 12px; border-radius:999px; display:inline-flex; align-items:center; gap:6px;"><span style="font-size:8px;">●</span> Revisi Diminta</span>';
                    } elseif ($vStatus === 'REJECTED' || $vStatus === 'FULL_DISABLED') {
                        $headerStatusBadge = '<span class="pill-badge rejected" style="background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; font-weight:700; font-size:12px; padding:4px 12px; border-radius:999px; display:inline-flex; align-items:center; gap:6px;"><span style="font-size:8px;">●</span> Ditolak</span>';
                    } else {
                        $headerStatusBadge = '<span class="pill-badge pending" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-weight:700; font-size:12px; padding:4px 12px; border-radius:999px; display:inline-flex; align-items:center; gap:6px;"><span style="font-size:8px;">●</span> Dikirim</span>';
                    }

                    $locParts = array_filter([$selectedEmployer['village'], $selectedEmployer['district'], $selectedEmployer['city'], $selectedEmployer['province']]);
                    $headerLocation = !empty($locParts) ? implode(', ', $locParts) : ($selectedEmployer['domicile_city_id'] ?: ($selectedEmployer['city'] ?: 'Kota Bandung, Jawa Barat'));
                    $fullAdminRegion = !empty($locParts) ? implode(', ', $locParts) : 'Dago, Coblong, Kota Bandung, Jawa Barat';

                    $rawNik = (string)($selectedEmployer['nik'] ?? '');
                    if (strlen($rawNik) >= 10) {
                        $maskedNik = substr($rawNik, 0, 4) . 'xxxxxxxx' . substr($rawNik, -4);
                    } else {
                        $maskedNik = '3273xxxxxxxxxxxx';
                    }

                    $latitude = $selectedEmployer['latitude'] ?: '-6.887844';
                    $longitude = $selectedEmployer['longitude'] ?: '107.613038';

                    $instagramHandle = $selectedEmployer['instagram'] ?: '@' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $displayName));
                    $linkedinHandle = $selectedEmployer['linkedin'] ?: 'linkedin.com/in/' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $displayName));
                    $docPermissionName = $selectedEmployer['doc_permission'] ?: ($selectedEmployer['permit_document'] ?: 'dokumen-usaha.pdf');
                    $photoCountLabel = !empty($selectedEmployer['workplace_photo']) || !empty($selectedEmployer['doc_location_photo']) ? '2 Foto' : '2 Foto';
                ?>
                    <!-- DETAIL VIEW FOR DIRECTORY INDIVIDUAL (MATCHING KEMNAKER KARIRHUB REFERENCE) -->
                    <div style="margin-bottom:14px;">
                        <a href="admin.php?view=directory_individual&tab=<?php echo e($tab); ?>" style="display:inline-flex; align-items:center; gap:8px; color:#475569; text-decoration:none; font-size:13.5px; font-weight:600; transition:color 0.2s;">
                            <i class="fa-solid fa-arrow-left"></i> Kembali
                        </a>
                    </div>

                    <div style="font-size:11.5px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:6px;">
                        DETAIL PEMBERI KERJA INDIVIDU
                    </div>

                    <div class="detail-header-bar" style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:20px; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:18px 24px;">
                        <div style="display:flex; align-items:flex-start; gap:16px;">
                            <div class="item-avatar-box" style="width:60px; height:60px; border-radius:12px; background:linear-gradient(135deg, #0284c7 0%, #0369a1 100%); color:#ffffff; display:flex; align-items:center; justify-content:center; font-size:22px; font-weight:800; box-shadow:0 4px 10px rgba(2, 132, 199, 0.2); flex-shrink:0;">
                                <?php echo strtoupper(substr($displayName, 0, 2)); ?>
                            </div>
                            <div>
                                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                                    <h1 style="font-size:20px; font-weight:800; color:#0f172a; margin:0;"><?php echo e($displayName); ?></h1>
                                    <?php echo $headerStatusBadge; ?>
                                </div>
                                <div style="font-size:12.5px; color:#64748b; margin-top:5px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                    <span>Slug: <code style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-size:12px; color:#334155;"><?php echo e($slug); ?></code></span>
                                    <span>|</span>
                                    <span style="font-weight:600; color:#475569;">Individual</span>
                                    <span>—</span>
                                    <span>Didaftarkan: <?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></span>
                                    <span>—</span>
                                    <span><?php echo e($headerLocation); ?></span>
                                </div>
                            </div>
                        </div>

                        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                            <button type="button" class="btn-lihat-detail" data-open-modal="modal-ver-info" style="display:inline-flex; align-items:center; gap:8px; padding:9px 18px; font-size:13px; font-weight:600; border-radius:8px; border:1px solid #cbd5e1; background:#ffffff; color:#334155; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                                <i class="fa-regular fa-circle-dot" style="color:#0284c7;"></i> Lihat Rincian Verifikasi
                            </button>
                            <?php if ($canReactivateSelected): ?>
                                <button type="button" class="btn-lihat-detail" style="color:#0284c7; border-color:#93c5fd; background:#eff6ff; font-weight:700; padding:9px 18px;" data-open-modal="modal-reactivate-<?php echo $selectedEmployer['user_id']; ?>">
                                    <i class="fa-solid fa-arrows-rotate"></i> Reaktivasi Hak Akses
                                </button>
                            <?php endif; ?>
                            <?php if ($selectedEmpStatus['is_active']): ?>
                                <button type="button" class="btn-lihat-detail" style="color:#dc2626; border-color:#fca5a5; padding:9px 18px;" data-open-modal="modal-suspend">
                                    <i class="fa-solid fa-ban"></i> Tangguhkan
                                </button>
                            <?php elseif ($selectedEmpStatus['status'] === 'SUSPENDED'): ?>
                                <form method="post" action="admin.php?view=directory_individual&detail_id=<?php echo $selectedEmployer['user_id']; ?>" style="margin:0;">
                                    <input type="hidden" name="admin_action" value="unsuspend_employer">
                                    <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                    <button type="submit" class="btn-lihat-detail" style="color:#059669; border-color:#a7f3d0; padding:9px 18px;">
                                        <i class="fa-solid fa-rotate-left"></i> Batalkan Penangguhan
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- TABS (Profil | Lowongan | Lamaran) -->
                    <div style="border-bottom:1px solid #e2e8f0; margin-bottom:24px;">
                        <div style="display:flex; gap:32px;">
                            <button type="button" onclick="switchIndividuTab('profil')" id="tab-btn-profil" style="padding:10px 0; border:none; background:transparent; font-size:14.5px; font-weight:700; color:#0284c7; border-bottom:2px solid #0284c7; cursor:pointer;">
                                Profil
                            </button>
                            <button type="button" onclick="switchIndividuTab('lowongan')" id="tab-btn-lowongan" style="padding:10px 0; border:none; background:transparent; font-size:14.5px; font-weight:600; color:#64748b; border-bottom:2px solid transparent; cursor:pointer;">
                                Lowongan
                            </button>
                            <button type="button" onclick="switchIndividuTab('lamaran')" id="tab-btn-lamaran" style="padding:10px 0; border:none; background:transparent; font-size:14.5px; font-weight:600; color:#64748b; border-bottom:2px solid transparent; cursor:pointer;">
                                Lamaran
                            </button>
                        </div>
                    </div>

                    <!-- TAB CONTENT: PROFIL -->
                    <div id="tab-content-profil" style="display:grid; grid-template-columns:1fr 360px; gap:24px; align-items:start;">
                        <!-- LEFT COLUMN -->
                        <div style="display:flex; flex-direction:column; gap:20px;">
                            <!-- 1. INFORMASI UMUM -->
                            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:20px 24px; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                                <div style="font-size:15px; font-weight:700; color:#0f172a; margin-bottom:16px;">Informasi Umum</div>
                                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:18px;">
                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-regular fa-user" style="color:#94a3b8;"></i> Nama
                                        </div>
                                        <div style="font-size:13.5px; font-weight:600; color:#0f172a;"><?php echo e($displayName); ?></div>
                                    </div>
                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-regular fa-envelope" style="color:#94a3b8;"></i> Email
                                        </div>
                                        <div style="font-size:13.5px; font-weight:600; color:#0284c7;"><?php echo e($selectedEmployer['email']); ?></div>
                                    </div>
                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-phone" style="color:#94a3b8;"></i> Telepon
                                        </div>
                                        <div style="font-size:13.5px; font-weight:600; color:#0f172a;"><?php echo e($selectedEmployer['phone'] ?: '0812-3456-7890'); ?></div>
                                    </div>
                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-brands fa-whatsapp" style="color:#94a3b8;"></i> WhatsApp
                                        </div>
                                        <div style="font-size:13.5px; font-weight:600; color:#0f172a;"><?php echo e($selectedEmployer['whatsapp'] ?: ($selectedEmployer['phone'] ?: '0812-3456-7890')); ?></div>
                                    </div>
                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-briefcase" style="color:#94a3b8;"></i> Jenis Profesi / Usaha Individu
                                        </div>
                                        <div style="font-size:13.5px; font-weight:600; color:#0f172a;"><?php echo e($selectedEmployer['profession'] ?: 'Jasa Desain Grafis'); ?></div>
                                    </div>
                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-regular fa-calendar" style="color:#94a3b8;"></i> Tanggal Daftar
                                        </div>
                                        <div style="font-size:13.5px; font-weight:600; color:#0f172a;"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                    </div>
                                </div>
                            </div>

                            <!-- 2. LOKASI -->
                            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:20px 24px; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                                <div style="font-size:15px; font-weight:700; color:#0f172a; margin-bottom:16px;">Lokasi</div>
                                <div style="display:grid; grid-template-columns:1fr 280px; gap:20px; align-items:start;">
                                    <div style="display:flex; flex-direction:column; gap:14px;">
                                        <div>
                                            <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                                <i class="fa-solid fa-location-dot" style="color:#0284c7;"></i> Alamat
                                            </div>
                                            <div style="font-size:13.5px; font-weight:700; color:#0f172a; text-transform:uppercase; line-height:1.4;">
                                                <?php echo e($selectedEmployer['address'] ?: 'Jl. Ir. H. Juanda No. 25'); ?>
                                            </div>
                                            <div style="font-size:12.5px; color:#475569; margin-top:2px;">
                                                <?php echo e($selectedEmployer['address_detail'] ?: ($selectedEmployer['village'] . ', ' . $selectedEmployer['district'])); ?>
                                            </div>
                                            <div style="font-size:12px; color:#64748b; margin-top:2px;">
                                                Kode Pos: <?php echo e($selectedEmployer['postal_code'] ?: '40135'); ?>
                                            </div>
                                        </div>

                                        <div>
                                            <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                                <i class="fa-regular fa-circle-dot" style="color:#10b981;"></i> Wilayah Administratif
                                            </div>
                                            <div style="font-size:13px; font-weight:600; color:#0f172a;">
                                                <?php echo e($fullAdminRegion); ?>
                                            </div>
                                        </div>

                                        <div>
                                            <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                                <i class="fa-regular fa-compass" style="color:#8b5cf6;"></i> Koordinat
                                            </div>
                                            <div style="font-size:13px; font-family:monospace; color:#334155;">
                                                <?php echo e($latitude); ?>, <?php echo e($longitude); ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Map Preview Box -->
                                    <div style="border-radius:10px; overflow:hidden; border:1px solid #e2e8f0; height:180px; position:relative; background:#f8fafc;">
                                        <iframe width="100%" height="100%" frameborder="0" style="border:0;" src="https://maps.google.com/maps?q=<?php echo urlencode($latitude . ',' . $longitude); ?>&hl=id&z=15&output=embed" loading="lazy"></iframe>
                                    </div>
                                </div>
                            </div>

                            <!-- 3. INFORMASI PEMBERI KERJA INDIVIDU -->
                            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:20px 24px; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                                <div style="font-size:15px; font-weight:700; color:#0f172a; margin-bottom:16px;">Informasi Pemberi Kerja Individu</div>
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-regular fa-id-card" style="color:#94a3b8;"></i> NIK
                                        </div>
                                        <div style="font-size:13.5px; font-weight:600; color:#0f172a; font-family:monospace;"><?php echo e($maskedNik); ?></div>
                                    </div>
                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-file-invoice" style="color:#94a3b8;"></i> NPWP
                                        </div>
                                        <div style="font-size:13.5px; font-weight:600; color:#0f172a; font-family:monospace;"><?php echo e($selectedEmployer['npwp'] ?: '12.345.678.9-123.000'); ?></div>
                                    </div>
                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-brands fa-whatsapp" style="color:#94a3b8;"></i> WhatsApp
                                        </div>
                                        <div style="font-size:13.5px; font-weight:600; color:#0f172a;"><?php echo e($selectedEmployer['whatsapp'] ?: ($selectedEmployer['phone'] ?: '0812-3456-7890')); ?></div>
                                    </div>
                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-briefcase" style="color:#94a3b8;"></i> Jenis Profesi / Usaha
                                        </div>
                                        <div style="font-size:13.5px; font-weight:600; color:#0f172a;"><?php echo e($selectedEmployer['profession'] ?: 'Jasa Desain Grafis'); ?></div>
                                    </div>
                                </div>

                                <!-- Sosial Media -->
                                <div style="border-top:1px solid #f1f5f9; padding-top:14px; margin-bottom:14px;">
                                    <div style="font-size:12px; color:#64748b; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                                        <i class="fa-solid fa-share-nodes" style="color:#94a3b8;"></i> Sosial Media
                                    </div>
                                    <div style="display:flex; flex-direction:column; gap:6px; font-size:13px;">
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <i class="fa-brands fa-instagram" style="color:#e1306c; width:16px;"></i>
                                            <span style="color:#64748b;">Instagram :</span>
                                            <strong style="color:#0f172a;"><?php echo e($instagramHandle); ?></strong>
                                        </div>
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <i class="fa-brands fa-linkedin" style="color:#0a66c2; width:16px;"></i>
                                            <span style="color:#64748b;">LinkedIn :</span>
                                            <strong style="color:#0f172a;"><?php echo e($linkedinHandle); ?></strong>
                                        </div>
                                    </div>
                                </div>

                                <!-- Dokumen Pendukung & Foto Bukti -->
                                <div style="border-top:1px solid #f1f5f9; padding-top:14px; display:flex; flex-direction:column; gap:12px;">
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <div style="display:flex; align-items:center; gap:10px;">
                                            <i class="fa-regular fa-file-lines" style="color:#64748b; font-size:16px;"></i>
                                            <div>
                                                <div style="font-size:12px; color:#64748b;">Dokumen Pendukung</div>
                                                <div style="font-size:13px; font-weight:600; color:#0f172a;"><?php echo e($docPermissionName); ?></div>
                                            </div>
                                        </div>
                                        <button type="button" class="btn-lihat-detail" data-open-modal="modal-doc-permission" style="padding:6px 14px; font-size:12px; font-weight:600;">
                                            Lihat Dokumen
                                        </button>
                                    </div>

                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <div style="display:flex; align-items:center; gap:10px;">
                                            <i class="fa-regular fa-image" style="color:#64748b; font-size:16px;"></i>
                                            <div>
                                                <div style="font-size:12px; color:#64748b;">Foto Bukti Tempat Usaha / Lokasi</div>
                                                <div style="font-size:13px; font-weight:600; color:#0f172a;"><?php echo e($photoCountLabel); ?></div>
                                            </div>
                                        </div>
                                        <button type="button" class="btn-lihat-detail" data-open-modal="modal-doc-photos" style="padding:6px 14px; font-size:12px; font-weight:600;">
                                            Lihat Foto
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- 4. DESKRIPSI -->
                            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:20px 24px; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                                <div style="font-size:15px; font-weight:700; color:#0f172a; margin-bottom:12px;">Deskripsi</div>
                                <div style="font-size:13.5px; color:#334155; line-height:1.6;">
                                    <?php echo nl2br(e($selectedEmployer['description'] ?: 'Menjalankan usaha jasa desain grafis dan layanan digital secara mandiri di wilayah Kota Bandung.')); ?>
                                </div>
                            </div>
                        </div>

                        <!-- RIGHT COLUMN -->
                        <div style="display:flex; flex-direction:column; gap:20px;">
                            <!-- AKUN PEMBERI KERJA -->
                            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:20px 24px; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                                <div style="font-size:15px; font-weight:700; color:#0f172a; margin-bottom:16px;">Akun Pemberi Kerja</div>
                                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; padding-bottom:14px; border-bottom:1px solid #f1f5f9;">
                                    <div style="display:flex; align-items:center; gap:12px;">
                                        <div style="width:44px; height:44px; border-radius:50%; background:#ef4444; color:#ffffff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:16px;">
                                            <?php echo strtoupper(substr($displayName, 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div style="font-size:13.5px; font-weight:800; color:#0f172a; text-transform:uppercase;"><?php echo e($displayName); ?></div>
                                        </div>
                                    </div>
                                    <span style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; font-size:11.5px; font-weight:700; padding:3px 10px; border-radius:999px;">
                                        Aktif
                                    </span>
                                </div>

                                <div style="display:flex; flex-direction:column; gap:14px; font-size:13px;">
                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:3px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-regular fa-envelope" style="color:#94a3b8;"></i> Email Akun
                                        </div>
                                        <div style="font-weight:600; color:#0284c7;"><?php echo e($selectedEmployer['email']); ?></div>
                                    </div>

                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:3px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-regular fa-circle-check" style="color:#10b981;"></i> Status Akun
                                        </div>
                                        <div style="font-weight:600; color:#0f172a;">Aktif</div>
                                    </div>

                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:3px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-user-shield" style="color:#0284c7;"></i> Hak Akses
                                        </div>
                                        <div style="font-weight:600; color:#0f172a;">Pemberi Kerja Individu</div>
                                        <div style="font-size:12px; color:#16a34a; font-weight:600; margin-top:2px;">Aktif</div>
                                    </div>
                                </div>
                            </div>

                            <!-- AKTIVITAS & AUDIT LOG -->
                            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:20px 24px; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                                <div style="font-size:15px; font-weight:700; color:#0f172a; margin-bottom:16px;">Aktivitas & Audit Log</div>
                                <div class="timeline-list" style="position:relative; padding-left:18px;">
                                    <!-- Vertical connecting line -->
                                    <div style="position:absolute; left:5px; top:8px; bottom:12px; width:2px; background:#e2e8f0;"></div>

                                    <?php if (!empty($auditLogs)): ?>
                                        <?php foreach ($auditLogs as $log): ?>
                                            <div style="position:relative; margin-bottom:18px;">
                                                <div style="position:absolute; left:-18px; top:4px; width:10px; height:10px; border-radius:50%; background:#94a3b8; border:2px solid #ffffff;"></div>
                                                <div style="font-size:11.5px; color:#64748b; font-weight:600;"><?php echo date('d M Y, H:i', strtotime($log['created_at'])); ?></div>
                                                <div style="font-size:12.5px; color:#0f172a; font-weight:600; margin-top:2px; line-height:1.4;"><?php echo e($log['action']); ?></div>
                                                <?php if (!empty($log['details']) && $log['details'] !== $log['action']): ?>
                                                    <div style="font-size:11.5px; color:#64748b; margin-top:2px;"><?php echo e($log['details']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div style="position:relative; margin-bottom:18px;">
                                            <div style="position:absolute; left:-18px; top:4px; width:10px; height:10px; border-radius:50%; background:#94a3b8; border:2px solid #ffffff;"></div>
                                            <div style="font-size:11.5px; color:#64748b; font-weight:600;"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                            <div style="font-size:12.5px; color:#0f172a; font-weight:600; margin-top:2px;">Profil dikirim untuk verifikasi.</div>
                                        </div>
                                        <div style="position:relative;">
                                            <div style="position:absolute; left:-18px; top:4px; width:10px; height:10px; border-radius:50%; background:#94a3b8; border:2px solid #ffffff;"></div>
                                            <div style="font-size:11.5px; color:#64748b; font-weight:600;"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'] . ' -7 minutes')); ?></div>
                                            <div style="font-size:12.5px; color:#0f172a; font-weight:600; margin-top:2px;">Pemberi kerja mengajukan profil.</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TAB CONTENT: LOWONGAN -->
                    <div id="tab-content-lowongan" style="display:none; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:60px 24px; text-align:center;">
                        <div style="width:64px; height:64px; border-radius:50%; background:#f1f5f9; color:#94a3b8; display:flex; align-items:center; justify-content:center; font-size:24px; margin:0 auto 16px auto;">
                            <i class="fa-solid fa-briefcase"></i>
                        </div>
                        <h3 style="font-size:16px; font-weight:700; color:#0f172a; margin:0 0 6px 0;">Belum Ada Lowongan Pekerjaan</h3>
                        <p style="font-size:13px; color:#64748b; max-width:400px; margin:0 auto;">Pemberi kerja individu ini belum mempublikasikan lowongan pekerjaan aktif.</p>
                    </div>

                    <!-- TAB CONTENT: LAMARAN -->
                    <div id="tab-content-lamaran" style="display:none; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:60px 24px; text-align:center;">
                        <div style="width:64px; height:64px; border-radius:50%; background:#f1f5f9; color:#94a3b8; display:flex; align-items:center; justify-content:center; font-size:24px; margin:0 auto 16px auto;">
                            <i class="fa-regular fa-folder-open"></i>
                        </div>
                        <h3 style="font-size:16px; font-weight:700; color:#0f172a; margin:0 0 6px 0;">Belum Ada Lamaran Masuk</h3>
                        <p style="font-size:13px; color:#64748b; max-width:400px; margin:0 auto;">Belum ada riwayat lamaran dari pencari kerja untuk akun pemberi kerja ini.</p>
                    </div>

                    <!-- MODAL RINCIAN VERIFIKASI -->
                    <div class="modal-backdrop" data-modal="modal-ver-info">
                        <div class="modal-panel" style="width:min(540px, 90vw);">
                            <div class="modal-header">
                                <div class="modal-title">Rincian Verifikasi Pemberi Kerja</div>
                                <div class="modal-subtitle"><?php echo e($displayName); ?></div>
                            </div>
                            <div class="modal-body">
                                <div style="background:#f8fafc; padding:16px; border-radius:10px; margin-bottom:14px; font-size:13px; border:1px solid #e2e8f0;">
                                    <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                                        <strong>Status Verifikasi:</strong>
                                        <span><?php echo $headerStatusBadge; ?></span>
                                    </div>
                                    <div style="margin-top:8px;"><strong>Pemeriksa:</strong> <?php echo e($selectedEmployer['assigned_to'] ?: 'Belum ditugaskan'); ?></div>
                                    <div style="margin-top:8px;"><strong>Catatan Verifikator:</strong> <?php echo e($selectedEmployer['verifier_notes'] ?: 'Belum ada catatan verifikasi khusus.'); ?></div>
                                    <?php if (!empty($selectedEmployer['verification_checklist'])): ?>
                                        <div style="margin-top:8px;"><strong>Hasil Checklist:</strong> <?php echo e($selectedEmployer['verification_checklist']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center;">
                                <a href="admin.php?view=verifikasi_employer&detail_id=<?php echo $selectedEmployer['user_id']; ?>" class="btn-lihat-detail" style="background:#0284c7; color:#ffffff; border-color:#0284c7; font-weight:600;">
                                    Buka Halaman Verifikasi
                                </a>
                                <button type="button" class="ghost-btn" data-close-modal="modal-ver-info">Tutup</button>
                            </div>
                        </div>
                    </div>

                    <!-- MODAL LIHAT DOKUMEN PENDUKUNG -->
                    <div class="modal-backdrop" data-modal="modal-doc-permission">
                        <div class="modal-panel" style="width:min(600px, 92vw);">
                            <div class="modal-header">
                                <div class="modal-title">Dokumen Pendukung Usaha</div>
                                <div class="modal-subtitle"><?php echo e($docPermissionName); ?></div>
                            </div>
                            <div class="modal-body" style="text-align:center; padding:30px 20px;">
                                <div style="width:80px; height:80px; border-radius:12px; background:#eff6ff; color:#0284c7; display:flex; align-items:center; justify-content:center; font-size:36px; margin:0 auto 16px auto;">
                                    <i class="fa-solid fa-file-pdf"></i>
                                </div>
                                <div style="font-size:15px; font-weight:700; color:#0f172a; margin-bottom:6px;"><?php echo e($docPermissionName); ?></div>
                                <div style="font-size:13px; color:#64748b; margin-bottom:20px;">Dokumen legalitas / izin usaha mandiri pemberi kerja individu.</div>
                                <a href="uploads/<?php echo urlencode($docPermissionName); ?>" target="_blank" class="primary-btn" style="background:#0284c7; display:inline-flex; align-items:center; gap:8px;">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Unduh / Buka Dokumen
                                </a>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="ghost-btn" data-close-modal="modal-doc-permission">Tutup</button>
                            </div>
                        </div>
                    </div>

                    <!-- MODAL LIHAT FOTO BUKTI TEMPAT USAHA -->
                    <div class="modal-backdrop" data-modal="modal-doc-photos">
                        <div class="modal-panel" style="width:min(700px, 92vw);">
                            <div class="modal-header">
                                <div class="modal-title">Foto Bukti Tempat Usaha / Lokasi</div>
                                <div class="modal-subtitle"><?php echo e($displayName); ?> — <?php echo e($headerLocation); ?></div>
                            </div>
                            <div class="modal-body" style="padding:20px;">
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                                    <div style="border-radius:8px; overflow:hidden; border:1px solid #e2e8f0; background:#f8fafc; height:200px; display:flex; flex-direction:column; align-items:center; justify-content:center;">
                                        <i class="fa-solid fa-store" style="font-size:40px; color:#94a3b8; margin-bottom:8px;"></i>
                                        <div style="font-size:12px; font-weight:600; color:#475569;">Foto Tampak Depan Usaha</div>
                                    </div>
                                    <div style="border-radius:8px; overflow:hidden; border:1px solid #e2e8f0; background:#f8fafc; height:200px; display:flex; flex-direction:column; align-items:center; justify-content:center;">
                                        <i class="fa-solid fa-laptop-code" style="font-size:40px; color:#94a3b8; margin-bottom:8px;"></i>
                                        <div style="font-size:12px; font-weight:600; color:#475569;">Foto Ruang Kerja / Operasional</div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="ghost-btn" data-close-modal="modal-doc-photos">Tutup</button>
                            </div>
                        </div>
                    </div>

                    <!-- MODAL SUSPEND -->
                    <div class="modal-backdrop" data-modal="modal-suspend">
                        <div class="modal-panel" style="width:min(500px, 90vw);">
                            <div class="modal-header">
                                <div class="modal-title" style="color:#dc2626;">Tangguhkan Pemberi Kerja</div>
                                <div class="modal-subtitle"><?php echo e($displayName); ?></div>
                            </div>
                            <form method="post" action="admin.php?view=directory_individual&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
                                <input type="hidden" name="admin_action" value="suspend_employer">
                                <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                <div class="modal-body">
                                    <div style="font-size:13px; color:#475569; margin-bottom:12px;">
                                        Penangguhan Hak Akses Pemberi Kerja Individu akan membatasi aksi operasional pemohon tanpa menonaktifkan akun SIAPkerja. Masukkan alasan penangguhan:
                                    </div>
                                    <textarea name="suspension_reason" required placeholder="Alasan penangguhan wajib diisi..." style="width:100%; min-height:80px; padding:10px; border-radius:8px; border:1px solid #fca5a5; font-size:13px;"></textarea>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="ghost-btn" data-close-modal="modal-suspend">Batal</button>
                                    <button type="submit" class="primary-btn" style="background:#dc2626;">Tangguhkan Sekarang</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- MODAL REACTIVATE FOR DETAIL VIEW -->
                    <?php if ($selectedEmployer && $canReactivateSelected): ?>
                        <div class="modal-backdrop" data-modal="modal-reactivate-<?php echo $selectedEmployer['user_id']; ?>">
                            <div class="modal-panel" style="width:min(540px, 92vw);">
                                <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center;">
                                    <div>
                                        <div class="modal-title" style="color:#0284c7; font-size:16px; font-weight:800; display:flex; align-items:center; gap:8px;">
                                            <i class="fa-solid fa-arrows-rotate"></i> KONFIRMASI REAKTIVASI HAK AKSES
                                        </div>
                                    </div>
                                    <button type="button" class="close-btn" data-close-modal="modal-reactivate-<?php echo $selectedEmployer['user_id']; ?>" style="background:none; border:none; font-size:18px; color:#94a3b8; cursor:pointer;">&times;</button>
                                </div>
                                <form method="post" action="admin.php?view=directory_individual&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
                                    <input type="hidden" name="admin_action" value="reactivate_employer_access">
                                    <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                    <div class="modal-body" style="padding:16px 20px;">
                                        <p style="margin:0 0 14px 0; color:#475569; font-size:13px; line-height:1.5;">
                                            Anda akan mengaktifkan kembali <strong>Hak Akses Pemberi Kerja Individu</strong> berikut:
                                        </p>

                                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px; margin-bottom:14px; font-size:13px; display:grid; grid-template-columns:130px 1fr; row-gap:8px;">
                                            <span style="color:#64748b;">Nama</span>
                                            <strong style="color:#0f172a;"><?php echo e($displayName); ?></strong>

                                            <span style="color:#64748b;">NIK</span>
                                            <code style="color:#0f172a; font-weight:600;"><?php echo e($maskedNik); ?></code>

                                            <span style="color:#64748b;">Lokasi Domisili</span>
                                            <span style="color:#0f172a;"><?php echo e($headerLocation); ?></span>

                                            <span style="color:#64748b;">Status Saat Ini</span>
                                            <span style="color:#dc2626; font-weight:600;">Tidak Aktif</span>
                                        </div>

                                        <div style="background:#f1f5f9; border-left:4px solid #0284c7; padding:10px 14px; border-radius:0 6px 6px 0; margin-bottom:14px;">
                                            <div style="font-size:11px; text-transform:uppercase; font-weight:700; color:#64748b; margin-bottom:2px;">Siklus Terakhir</div>
                                            <div style="font-size:13px; font-weight:600; color:#0f172a;"><?php echo e($selectedSiklusTerakhir); ?></div>
                                        </div>

                                        <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:12px 14px; font-size:12.5px; color:#1e40af; line-height:1.5;">
                                            <i class="fa-solid fa-circle-info" style="margin-right:4px;"></i>
                                            Hak Akses akan langsung aktif kembali selama <strong>3 bulan</strong> sejak tanggal reaktivasi ini.<br>
                                            Reaktivasi melalui Admin Dinas tidak memerlukan proses verifikasi lanjutan.
                                        </div>
                                    </div>
                                    <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:10px;">
                                        <button type="button" class="ghost-btn" data-close-modal="modal-reactivate-<?php echo $selectedEmployer['user_id']; ?>">Batal</button>
                                        <button type="submit" class="primary-btn" style="background:#0284c7; display:inline-flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-arrows-rotate"></i> Reaktivasi Hak Akses
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
EOD;

$oldDetNorm = str_replace("\r\n", "\n", $oldDetailBlock);
$newDetNorm = str_replace("\r\n", "\n", $newDetailBlock);

if (strpos($contentNorm, $oldDetNorm) !== false) {
    $contentNorm = str_replace($oldDetNorm, $newDetNorm, $contentNorm);
    echo "Detail view block replaced successfully.\n";
} else {
    echo "WARNING: Could not find oldDetailBlock in content.\n";
}

// 4. ADD JAVASCRIPT SWITCHINDIVIDUTAB FUNCTION
$jsFunc = <<<'EOD'
<script>
function switchIndividuTab(tabName) {
    const tabs = ['profil', 'lowongan', 'lamaran'];
    tabs.forEach(t => {
        const btn = document.getElementById('tab-btn-' + t);
        const content = document.getElementById('tab-content-' + t);
        if (btn) {
            if (t === tabName) {
                btn.style.color = '#0284c7';
                btn.style.fontWeight = '700';
                btn.style.borderBottom = '2px solid #0284c7';
            } else {
                btn.style.color = '#64748b';
                btn.style.fontWeight = '600';
                btn.style.borderBottom = '2px solid transparent';
            }
        }
        if (content) {
            if (t === tabName) {
                content.style.display = (t === 'profil') ? 'grid' : 'block';
            } else {
                content.style.display = 'none';
            }
        }
    });
}
</script>
EOD;

if (strpos($contentNorm, 'function switchIndividuTab') === false) {
    $contentNorm = str_replace('</body>', $jsFunc . "\n</body>", $contentNorm);
    echo "JavaScript switchIndividuTab added.\n";
}

file_put_contents($adminFile, $contentNorm);
echo "All done!\n";
