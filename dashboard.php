<?php
require __DIR__ . '/includes/bootstrap.php';

$user = require_role('employer');

$profileStatement = db()->prepare('SELECT * FROM employer_profiles WHERE user_id = ? LIMIT 1');
$profileStatement->execute([$user['id']]);
$profile = $profileStatement->fetch() ?: [];

if (!is_profile_complete($user)) {
    redirect('profile-employer.php');
}

if (isset($_GET['read_notif'])) {
    mark_notifications_read((int) $user['id']);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

if (isset($_GET['applicant_json'])) {
    $applicationId = (int) $_GET['applicant_json'];
    $detail = db()->prepare('SELECT a.*, j.title AS job_title, j.user_id AS employer_id, u.name AS seeker_name, u.email AS seeker_email,
            sp.nik, sp.phone, sp.gender, sp.marital_status, sp.birth_place, sp.birth_date, sp.ktp_address, sp.domicile_address
        FROM job_applications a
        JOIN job_posts j ON j.id = a.job_id
        JOIN users u ON u.id = a.seeker_id
        LEFT JOIN seeker_profiles sp ON sp.user_id = a.seeker_id
        WHERE a.id = ? AND j.user_id = ?
        LIMIT 1');
    $detail->execute([$applicationId, $user['id']]);
    $row = $detail->fetch();
    header('Content-Type: application/json');
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false]);
        exit;
    }
    $row['status'] = normalize_application_status($row['status'] ?? '');
    $row['profile'] = seeker_profile_bundle((int) $row['seeker_id']);
    echo json_encode(['ok' => true, 'data' => $row], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_application_status'])) {
    $applicationId = (int) ($_POST['application_id'] ?? 0);
    $nextStatus = normalize_application_status($_POST['status'] ?? '');
    if (!in_array($nextStatus, application_statuses(), true)) {
        flash('error', 'Status pelamar tidak valid.');
        redirect('dashboard.php#lowongan');
    }

    $owned = db()->prepare('SELECT a.id, a.seeker_id, j.title FROM job_applications a JOIN job_posts j ON j.id = a.job_id WHERE a.id = ? AND j.user_id = ?');
    $owned->execute([$applicationId, $user['id']]);
    $application = $owned->fetch();
    if (!$application) {
        flash('error', 'Pelamar tidak ditemukan.');
        redirect('dashboard.php#lowongan');
    }

    db()->prepare('UPDATE job_applications SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$nextStatus, $applicationId]);
    notify_user((int) $application['seeker_id'], 'Status lamaran diperbarui', 'Status lamaran Anda untuk "' . $application['title'] . '" sekarang: ' . $nextStatus . '.', 'info', $applicationId);
    flash('success', 'Status pelamar diperbarui menjadi ' . $nextStatus . '.');
    redirect('dashboard.php#lowongan');
}

$ownerName = $profile['owner_name'] ?? $user['name'];
$profession = $profile['profession'] ?? 'Kuliner';
$city = $profile['city'] ?? 'Kota Bekasi';

// Guard: active_until bisa NULL untuk employer baru yang belum diverifikasi
$activeUntilRaw = $profile['active_until'] ?? null;
$activeUntil = $activeUntilRaw ? new DateTime($activeUntilRaw) : (new DateTime())->modify('+3 months');
$now = new DateTime();
$diff = $now->diff($activeUntil);
$isExpired = $activeUntil < $now;
$daysRemaining = $isExpired ? -$diff->days : $diff->days;

$isTransitionPeriod = $daysRemaining < 0 && $daysRemaining >= -7;
$isFullDisable = $daysRemaining < -7;

if ($isFullDisable) {
    // Redirect to blocked page or show blocker
    echo "<h1>Akun Terkunci (Full Disable)</h1><p>Masa transisi telah habis. Silakan lakukan Verifikasi Ulang Profil atau hubungi Disnaker.</p>";
    exit;
}

// Ambil flash message SEBELUM ob_start (karena session harus dibaca dulu)
$flashData = get_flash();
$flashHtml = '';
if ($flashData) {
    $flashType = $flashData['type'] === 'success' ? 'success' : 'error';
    $flashIcon = $flashType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation';
    $flashColor = $flashType === 'success'
        ? 'background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;'
        : 'background:#fef2f2;border:1px solid #fecaca;color:#991b1b;';
    $flashHtml = '<div style="position:fixed;top:20px;right:20px;z-index:9999;max-width:400px;border-radius:14px;padding:14px 18px;font-size:14px;font-weight:600;display:flex;align-items:center;gap:10px;box-shadow:0 8px 24px rgba(0,0,0,0.12);' . $flashColor . '">';
    $flashHtml .= '<i class="fa-solid ' . $flashIcon . '"></i>';
    $flashHtml .= htmlspecialchars($flashData['message'], ENT_QUOTES, 'UTF-8');
    $flashHtml .= '<button onclick="this.parentElement.remove()" style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:16px;color:inherit;opacity:0.6;">×</button>';
    $flashHtml .= '</div>';
}

ob_start();
include __DIR__ . '/Index.html';
$html = ob_get_clean();

// Sisipkan flash toast ke dalam body
if ($flashHtml) {
    $html = str_replace('<body', $flashHtml . '<body', $html);
}

$initials = mb_strtoupper(mb_substr($ownerName, 0, 1));
if (str_contains($ownerName, ' ')) {
    $parts = explode(' ', $ownerName);
    $initials = mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
}

$replacements = [
    'Karirhub - Pemberi Kerja Individu'   => 'Karirhub - ' . htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8'),
    'Halo nama Pemberi Kerja Individu'    => 'Halo ' . htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8'),
    // Sidebar profile card
    'id="sidebarName">Pemberi Kerja Individu' => 'id="sidebarName">' . htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8'),
    'id="sidebarProfession">Profesi: Kuliner'  => 'id="sidebarProfession">Profesi: ' . htmlspecialchars($profession, ENT_QUOTES, 'UTF-8'),
    'id="sidebarAvatar">PI'               => 'id="sidebarAvatar">' . $initials,
    // Topbar chip
    '<strong>Pemberi Kerja Individu</strong>' => '<strong>' . htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8') . '</strong>',
    '<span>Profesi: Kuliner</span>'        => '<span>Profesi: ' . htmlspecialchars($profession, ENT_QUOTES, 'UTF-8') . '</span>',
    // Profile page
    'PT. Pandu Jaya'                       => htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8'),
    'Kota Bekasi'                          => htmlspecialchars($city, ENT_QUOTES, 'UTF-8'),
    '<strong>Sisa 87 hari</strong>'        => '<strong>Sisa ' . max(0, $daysRemaining) . ' hari</strong>',
];

$alertKey = 'Akun Anda telah aktif dan berlaku selama 3 bulan. Anda kini dapat mempublikasikan lowongan kerja.<br>' . "\r\n" . '                        <strong>Catatan penting: Anda dapat memiliki lebih dari satu lowongan aktif untuk jabatan/posisi yang berbeda. Namun, Anda tidak dapat membuat lowongan baru untuk jabatan/posisi yang sama selama masih terdapat lowongan aktif untuk posisi tersebut.</strong>';

if (empty($profile['verified'])) {
    $replacements[$alertKey] = '<strong style="color:orange"><i class="fa-solid fa-clock"></i> Menunggu Verifikasi Admin:</strong> Profil Anda sedang dalam tahap peninjauan. Fitur posting lowongan belum dapat diakses sebelum akun disetujui.';
    // Disable Add Button
    $html = str_replace('<button class="action-chip"><i class="fa-solid fa-plus"></i> Tambah</button>', '<button class="action-chip" style="opacity:0.5;cursor:not-allowed;" disabled><i class="fa-solid fa-plus"></i> Tambah</button>', $html);
} else if ($isTransitionPeriod) {
    $btnPerpanjangan = '';
    if ($profile['extension_requested'] == 0) {
        $btnPerpanjangan = '<form method="post" action="dashboard.php" style="margin-top:10px;"><input type="hidden" name="request_extension" value="1"><button type="submit" class="ghost-btn" style="border:1px solid red; color:red; padding:4px 12px; font-size:12px;"><i class="fa-solid fa-clock-rotate-left"></i> Ajukan Perpanjangan Waktu (1x)</button></form>';
    }
    $replacements[$alertKey] = '<strong style="color:red">Masa Transisi (Akses Dibatasi):</strong> Masa aktif Anda telah habis. Akses saat ini difokuskan untuk menyelesaikan rekrutmen. Tombol posting lowongan telah dinonaktifkan.<br>Sisa masa transisi: ' . (7 + $daysRemaining) . ' hari.' . $btnPerpanjangan;
    // Disable Add Button
    $html = str_replace('<button class="action-chip"><i class="fa-solid fa-plus"></i> Tambah</button>', '<button class="action-chip" style="opacity:0.5;cursor:not-allowed;" disabled><i class="fa-solid fa-plus"></i> Tambah</button>', $html);
} else if ($daysRemaining <= 7) {
    $btnPerpanjangan = '';
    if ($profile['extension_requested'] == 0) {
        $btnPerpanjangan = '<form method="post" action="dashboard.php" style="margin-top:10px;"><input type="hidden" name="request_extension" value="1"><button type="submit" class="ghost-btn" style="border:1px solid orange; color:orange; padding:4px 12px; font-size:12px;"><i class="fa-solid fa-clock-rotate-left"></i> Ajukan Perpanjangan Waktu (1x)</button></form>';
    }
    $replacements[$alertKey] = '<strong style="color:orange">Pengingat:</strong> Masa aktif akun Anda akan berakhir dalam ' . $daysRemaining . ' hari. Segera selesaikan rekrutmen Anda sebelum masa transisi dimulai.' . $btnPerpanjangan;
}


$html = str_replace(array_keys($replacements), array_values($replacements), $html);

$modalStyles = <<<'CSS'
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: none;
            align-items: center;
            justify-content: flex-end;
            z-index: 1000;
            padding: 16px;
        }
        .modal-backdrop.open {
            display: flex;
        }
        .modal-panel {
            width: min(720px, 100%);
            max-height: calc(100vh - 32px);
            overflow: auto;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 24px 80px rgba(15, 23, 42, 0.28);
        }
        .job-create-panel {
            width: min(860px, 100%);
            height: calc(100vh - 32px);
            max-height: calc(100vh - 32px);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            min-height: 0;
        }
        .job-create-panel form {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }
        .modal-header,
        .modal-footer {
            padding: 20px 24px 16px;
            border-bottom: 1px solid #eef2f7;
            flex-shrink: 0;
        }
        .job-create-panel .modal-header {
            position: relative;
            padding-right: 56px;
        }
        .modal-footer {
            border-bottom: none;
            border-top: 1px solid #eef2f7;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 14px 24px;
        }
        .modal-title {
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -0.02em;
            margin-bottom: 4px;
            color: #111827;
        }
        .modal-subtitle {
            color: #6b7280;
            font-size: 13px;
        }
        .modal-close {
            position: absolute;
            top: 18px;
            right: 18px;
            width: 32px;
            height: 32px;
            border: none;
            background: transparent;
            color: #6b7280;
            cursor: pointer;
            border-radius: 8px;
            display: grid;
            place-items: center;
            font-size: 18px;
        }
        .modal-close:hover {
            background: #f3f4f6;
            color: #111827;
        }
        .stepper {
            display: flex;
            align-items: center;
            gap: 0;
            margin-top: 20px;
        }
        .step {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #9ca3af;
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
        }
        .step .bubble {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #e5e7eb;
            display: grid;
            place-items: center;
            color: #6b7280;
            font-size: 12px;
            font-weight: 700;
            flex-shrink: 0;
        }
        .step.active {
            color: #1e97c4;
        }
        .step.active .bubble {
            background: #1e97c4;
            color: #fff;
        }
        .step.done {
            color: #16a34a;
        }
        .step.done .bubble {
            background: #16a34a;
            color: #fff;
        }
        .step-line {
            flex: 1;
            height: 2px;
            background: #e5e7eb;
            margin: 0 14px;
            min-width: 24px;
        }
        .step-line.done {
            background: #16a34a;
        }
        .modal-body {
            padding: 8px 24px 20px;
        }
        .job-create-panel .modal-body {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
            overscroll-behavior: contain;
            -webkit-overflow-scrolling: touch;
        }
        .job-create-panel [hidden] {
            display: none !important;
        }
        .modal-section {
            margin-bottom: 8px;
            padding: 18px 0 8px;
        }
        .section-heading {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 16px;
        }
        .section-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: #e8f7fc;
            color: #1e97c4;
            display: grid;
            place-items: center;
            flex-shrink: 0;
            font-size: 15px;
        }
        .section-title {
            font-size: 15px;
            font-weight: 800;
            margin-bottom: 2px;
            color: #111827;
        }
        .section-text {
            font-size: 12px;
            color: #6b7280;
            line-height: 1.45;
        }
        .job-create-panel .field {
            margin-bottom: 16px;
        }
        .job-create-panel .field label,
        .job-create-panel .field-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 8px;
            color: #1f2937;
        }
        .req {
            color: #ef4444;
            font-weight: 800;
        }
        .field-hint {
            display: block;
            margin-top: 6px;
            font-size: 12px;
            color: #9ca3af;
            font-weight: 500;
        }
        .job-create-panel .field input[type="text"],
        .job-create-panel .field input[type="number"],
        .job-create-panel .field input[type="email"],
        .job-create-panel .field select,
        .job-create-panel .field textarea {
            width: 100%;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 11px 14px;
            background: #fff;
            color: #111827;
            font-size: 13px;
            outline: none;
            transition: border-color .15s ease, box-shadow .15s ease;
            appearance: none;
        }
        .job-create-panel .field select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%236b7280' d='M1.4.8 6 5.4 10.6.8 12 2.2 6 8.2 0 2.2z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 36px;
        }
        .job-create-panel .field input:focus,
        .job-create-panel .field select:focus,
        .job-create-panel .field textarea:focus {
            border-color: #30aed8;
            box-shadow: 0 0 0 3px rgba(48, 174, 216, 0.12);
        }
        .field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px 16px;
        }
        .field-grid .field {
            margin-bottom: 0;
        }
        .span-2 {
            grid-column: span 2;
        }
        .affix-input {
            display: flex;
            align-items: stretch;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
            background: #fff;
        }
        .affix-input:focus-within {
            border-color: #30aed8;
            box-shadow: 0 0 0 3px rgba(48, 174, 216, 0.12);
        }
        .affix-input .affix {
            display: grid;
            place-items: center;
            min-width: 48px;
            padding: 0 12px;
            background: #f3f4f6;
            color: #6b7280;
            font-size: 13px;
            font-weight: 700;
            border-right: 1px solid #e5e7eb;
        }
        .affix-input.suffix .affix {
            border-right: none;
            border-left: 1px solid #e5e7eb;
        }
        .affix-input input {
            border: none !important;
            box-shadow: none !important;
            border-radius: 0 !important;
            min-width: 0;
        }
        .check-row {
            display: flex;
            flex-wrap: wrap;
            gap: 18px 28px;
        }
        .check-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            cursor: pointer;
            max-width: 100%;
        }
        .check-item input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .check-box {
            width: 18px;
            height: 18px;
            border: 1.5px solid #d1d5db;
            border-radius: 4px;
            display: grid;
            place-items: center;
            margin-top: 1px;
            flex-shrink: 0;
            background: #fff;
            color: transparent;
            font-size: 11px;
        }
        .check-item input:checked + .check-box {
            background: #1e97c4;
            border-color: #1e97c4;
            color: #fff;
        }
        .check-copy {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .check-copy strong {
            font-size: 13px;
            font-weight: 700;
            color: #1f2937;
        }
        .check-copy span {
            font-size: 12px;
            color: #9ca3af;
            font-weight: 500;
            line-height: 1.4;
        }
        .rich-editor {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
            background: #fff;
        }
        .rich-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 2px;
            padding: 8px 10px;
            border-bottom: 1px solid #eef2f7;
            background: #fafafa;
        }
        .rich-toolbar button,
        .rich-toolbar select {
            height: 28px;
            min-width: 28px;
            border: none;
            background: transparent;
            color: #4b5563;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }
        .rich-toolbar select {
            font-size: 12px;
            padding: 0 6px;
            background: #fff;
            border: 1px solid #e5e7eb;
        }
        .rich-toolbar button:hover {
            background: #eef2f7;
            color: #111827;
        }
        .rich-area {
            min-height: 160px;
            padding: 12px 14px;
            font-size: 13px;
            line-height: 1.6;
            outline: none;
        }
        .rich-area:empty:before {
            content: attr(data-placeholder);
            color: #9ca3af;
        }
        .chip-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        .choice-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #e8f7fc;
            color: #0f6f93;
            border-radius: 999px;
            padding: 6px 10px 6px 12px;
            font-size: 12px;
            font-weight: 700;
        }
        .choice-chip button {
            border: none;
            background: transparent;
            color: inherit;
            cursor: pointer;
            font-size: 14px;
            line-height: 1;
        }
        .modal-footer .ghost-btn,
        .modal-footer .primary-btn {
            height: 42px;
            padding: 0 18px;
            border-radius: 10px;
            min-width: 120px;
        }
        .modal-footer .ghost-btn {
            border: 1px solid #e5e7eb;
            background: #fff;
            color: #111827;
        }
        .info-tip {
            color: #9ca3af;
            font-size: 12px;
            cursor: help;
        }
        .applicant-profile-panel {
            width: min(640px, 100%);
        }
        .applicant-profile-grid {
            display: grid;
            gap: 10px;
        }
        .applicant-profile-grid .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            font-size: 13px;
            border-bottom: 1px solid #eef2f7;
            padding-bottom: 8px;
        }
        .profile-block {
            margin-top: 14px;
        }
        .profile-block h4 {
            font-size: 13px;
            margin-bottom: 8px;
        }
        .status-select {
            width: 100%;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 10px 12px;
            font: inherit;
        }
        .record-item {
            border: 1px solid #edf1f6;
            background: #fafcff;
            border-radius: 12px;
            padding: 10px 12px;
            margin-bottom: 8px;
        }
        .record-item strong { display: block; font-size: 13px; }
        .record-item span { font-size: 11px; color: #64748b; }
        @media (max-width: 760px) {
            .modal-backdrop {
                justify-content: center;
                padding: 8px;
            }
            .modal-panel,
            .job-create-panel {
                width: 100%;
                height: calc(100vh - 16px);
                max-height: calc(100vh - 16px);
            }
            .field-grid,
            .field-grid .span-2 {
                grid-template-columns: 1fr;
                grid-column: auto;
            }
            .step {
                font-size: 11px;
            }
        }
CSS;

$html = str_replace('</style>', $modalStyles . "\n    </style>", $html);

$kbjiOptionsHtml = '<option value="">Pilih jabatan sesuai KBJI</option>';
try {
    db()->query('SELECT 1 FROM kbji_data LIMIT 1');
} catch (Throwable $kbjiMissing) {
    db()->exec('CREATE TABLE IF NOT EXISTS kbji_data (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kode_kbji VARCHAR(20) NOT NULL UNIQUE,
        nama_jabatan VARCHAR(255) NOT NULL
    )');
    db()->exec("INSERT IGNORE INTO kbji_data (kode_kbji, nama_jabatan) VALUES
        ('2512.01', 'Pengembang Perangkat Lunak'),
        ('2512.02', 'Programmer (Programmer Komputer)'),
        ('2511.01', 'Analis Sistem Komputer'),
        ('5120.01', 'Koki'),
        ('5230.01', 'Kasir'),
        ('3322.01', 'Tenaga Penjualan (Sales)'),
        ('4111.01', 'Staf Administrasi Umum'),
        ('4312.01', 'Staf Entri Data'),
        ('2141.01', 'Insinyur Industri dan Produksi'),
        ('2421.01', 'Analis Manajemen'),
        ('3411.01', 'Petugas Bantuan Hukum'),
        ('5131.01', 'Pramusaji'),
        ('2411.01', 'Akuntan'),
        ('4311.01', 'Staf Akuntansi'),
        ('5411.01', 'Petugas Keamanan (Satpam)'),
        ('9111.01', 'Asisten Rumah Tangga'),
        ('8322.01', 'Pengemudi Mobil Barang (Sopir)'),
        ('3333.01', 'Agen Penyalur Tenaga Kerja'),
        ('2211.01', 'Dokter Umum'),
        ('2221.01', 'Perawat Profesional')");
}

$kbjiStmt = db()->query('SELECT kode_kbji, nama_jabatan FROM kbji_data ORDER BY nama_jabatan ASC');
foreach ($kbjiStmt as $kbjiRow) {
    $kbjiOptionsHtml .= '<option value="' . e($kbjiRow['kode_kbji']) . '">' . e($kbjiRow['nama_jabatan']) . ' (' . e($kbjiRow['kode_kbji']) . ')</option>';
}

$employerEmail = e($user['email'] ?? '');

$modal = <<<HTML
    <div class="modal-backdrop" data-modal="job-create">
        <div class="modal-panel job-create-panel" role="dialog" aria-modal="true" aria-labelledby="jobCreateTitle">
            <div class="modal-header">
                <button type="button" class="modal-close" data-close-modal="job-create" aria-label="Tutup"><i class="fa-solid fa-xmark"></i></button>
                <div class="modal-title" id="jobCreateTitle">Tambah Lowongan</div>
                <div class="modal-subtitle">Lengkapi form berikut untuk mengisi lowongan</div>
                <div class="stepper" data-job-stepper>
                    <span class="step active" data-step-label="1"><span class="bubble">1</span> Informasi Loker</span>
                    <span class="step-line" data-step-line="1"></span>
                    <span class="step" data-step-label="2"><span class="bubble">2</span> Persyaratan</span>
                    <span class="step-line" data-step-line="2"></span>
                    <span class="step" data-step-label="3"><span class="bubble">3</span> Tambahan</span>
                </div>
            </div>
            <form method="post" action="dashboard.php" novalidate data-job-create-form>
                <input type="hidden" name="create_job" value="1">
                <input type="hidden" name="revise_job_id" id="reviseJobId" value="">
                <input type="hidden" name="status" value="Menunggu Verifikasi">
                <div class="modal-body">
                    <div class="job-step" data-job-step="1">
                        <div class="modal-section">
                            <div class="section-heading">
                                <div class="section-icon"><i class="fa-solid fa-briefcase"></i></div>
                                <div>
                                    <div class="section-title">Informasi Loker</div>
                                    <div class="section-text">Judul, lokasi, hingga jenis disabilitas loker</div>
                                </div>
                            </div>
                            <div class="field">
                                <label>Judul loker <span class="req">*</span></label>
                                <input type="text" name="job_title" placeholder="Masukkan judul loker" required>
                            </div>
                            <div class="field">
                                <label>Deskripsi loker <span class="req">*</span></label>
                                <div class="rich-editor" data-rich-editor>
                                    <div class="rich-toolbar">
                                        <button type="button" data-cmd="undo" title="Undo"><i class="fa-solid fa-rotate-left"></i></button>
                                        <button type="button" data-cmd="redo" title="Redo"><i class="fa-solid fa-rotate-right"></i></button>
                                        <select data-block>
                                            <option value="p">Paragraph</option>
                                            <option value="h3">Heading</option>
                                        </select>
                                        <button type="button" data-cmd="bold" title="Bold"><i class="fa-solid fa-bold"></i></button>
                                        <button type="button" data-cmd="italic" title="Italic"><i class="fa-solid fa-italic"></i></button>
                                        <button type="button" data-cmd="underline" title="Underline"><i class="fa-solid fa-underline"></i></button>
                                        <button type="button" data-cmd="strikeThrough" title="Strikethrough"><i class="fa-solid fa-strikethrough"></i></button>
                                        <button type="button" data-cmd="justifyLeft" title="Rata kiri"><i class="fa-solid fa-align-left"></i></button>
                                        <button type="button" data-cmd="justifyCenter" title="Rata tengah"><i class="fa-solid fa-align-center"></i></button>
                                        <button type="button" data-cmd="justifyRight" title="Rata kanan"><i class="fa-solid fa-align-right"></i></button>
                                        <button type="button" data-cmd="insertUnorderedList" title="Bullet"><i class="fa-solid fa-list-ul"></i></button>
                                        <button type="button" data-cmd="insertOrderedList" title="Number"><i class="fa-solid fa-list-ol"></i></button>
                                        <button type="button" data-cmd="indent" title="Indent"><i class="fa-solid fa-indent"></i></button>
                                        <button type="button" data-cmd="outdent" title="Outdent"><i class="fa-solid fa-outdent"></i></button>
                                        <button type="button" data-cmd="createLink" title="Tautan"><i class="fa-solid fa-link"></i></button>
                                    </div>
                                    <div class="rich-area" contenteditable="true" data-placeholder="Masukkan deskripsi loker"></div>
                                    <textarea name="job_description" required hidden></textarea>
                                </div>
                            </div>
                            <div class="field">
                                <label>Jabatan sesuai KBJI <span class="req">*</span></label>
                                <select name="kbji_code" required>{$kbjiOptionsHtml}</select>
                            </div>
                            <div class="field">
                                <label>Lokasi loker <span class="req">*</span></label>
                                <select name="job_location" required>
                                    <option value="">Pilih lokasi loker</option>
                                    <option>Kota Bekasi</option>
                                    <option>Kabupaten Bekasi</option>
                                    <option>Kota Jakarta Pusat</option>
                                    <option>Kota Jakarta Selatan</option>
                                    <option>Kota Jakarta Timur</option>
                                    <option>Kota Jakarta Barat</option>
                                    <option>Kota Jakarta Utara</option>
                                    <option>Kota Bandung</option>
                                    <option>Kota Surabaya</option>
                                    <option>Kota Semarang</option>
                                    <option>Kota Yogyakarta</option>
                                    <option>Kota Depok</option>
                                    <option>Kota Tangerang</option>
                                    <option>Kota Tangerang Selatan</option>
                                    <option>Kota Medan</option>
                                    <option>Kota Makassar</option>
                                    <option>Kota Denpasar</option>
                                </select>
                            </div>
                            <div class="field-grid">
                                <div class="field">
                                    <label>Jenis pekerjaan <span class="req">*</span></label>
                                    <select name="job_type" required>
                                        <option value="">Pilih jenis pekerjaan</option>
                                        <option>Penuh Waktu</option>
                                        <option>Paruh Waktu</option>
                                        <option>Kontrak</option>
                                        <option>Magang</option>
                                        <option>Freelance</option>
                                        <option>Harian</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Bidang pekerjaan <span class="req">*</span></label>
                                    <select name="job_field" required>
                                        <option value="">Pilih bidang pekerjaan</option>
                                        <option>Teknologi Informasi</option>
                                        <option>Administrasi</option>
                                        <option>Keuangan &amp; Akuntansi</option>
                                        <option>Penjualan &amp; Marketing</option>
                                        <option>Kuliner &amp; Hospitality</option>
                                        <option>Kesehatan</option>
                                        <option>Pendidikan</option>
                                        <option>Teknik &amp; Manufaktur</option>
                                        <option>Logistik</option>
                                        <option>Keamanan</option>
                                        <option>Lainnya</option>
                                    </select>
                                </div>
                            </div>
                            <div class="field">
                                <label>Industri / Sektor <span class="req">*</span></label>
                                <select name="industry" required>
                                    <option value="">Pilih industri / sektor</option>
                                    <option>Informasi dan Komunikasi</option>
                                    <option>Penyediaan Akomodasi dan Makan Minum</option>
                                    <option>Jasa Keuangan dan Asuransi</option>
                                    <option>Industri Pengolahan</option>
                                    <option>Perdagangan Besar dan Eceran</option>
                                    <option>Jasa Kesehatan dan Kegiatan Sosial</option>
                                    <option>Pendidikan</option>
                                    <option>Konstruksi</option>
                                    <option>Transportasi dan Pergudangan</option>
                                    <option>Aktivitas Jasa Lainnya</option>
                                </select>
                            </div>
                            <div class="field">
                                <div class="field-label">Kondisi fisik <span class="req">*</span></div>
                                <div class="check-row" data-required-group="Pilih minimal satu kondisi fisik">
                                    <label class="check-item">
                                        <input type="checkbox" name="physical_condition[]" value="Disabilitas" checked>
                                        <span class="check-box"><i class="fa-solid fa-check"></i></span>
                                        <span class="check-copy"><strong>Disabilitas</strong></span>
                                    </label>
                                    <label class="check-item">
                                        <input type="checkbox" name="physical_condition[]" value="Non Disabilitas" checked>
                                        <span class="check-box"><i class="fa-solid fa-check"></i></span>
                                        <span class="check-copy"><strong>Non Disabilitas</strong></span>
                                    </label>
                                </div>
                            </div>
                            <div class="field">
                                <div class="field-label">Jenis kelamin <span class="req">*</span></div>
                                <div class="check-row" data-required-group="Pilih minimal satu jenis kelamin">
                                    <label class="check-item">
                                        <input type="checkbox" name="gender[]" value="Laki-laki" checked>
                                        <span class="check-box"><i class="fa-solid fa-check"></i></span>
                                        <span class="check-copy"><strong>Laki-laki</strong></span>
                                    </label>
                                    <label class="check-item">
                                        <input type="checkbox" name="gender[]" value="Perempuan" checked>
                                        <span class="check-box"><i class="fa-solid fa-check"></i></span>
                                        <span class="check-copy"><strong>Perempuan</strong></span>
                                    </label>
                                </div>
                            </div>
                            <div class="field">
                                <label>Jenis disabilitas tidak diperbolehkan <i class="fa-solid fa-circle-info info-tip" title="Kosongkan jika seluruh jenis disabilitas diperbolehkan"></i></label>
                                <select name="disability_excluded">
                                    <option value="">Pilih jenis disabilitas</option>
                                    <option>Tuna Netra</option>
                                    <option>Tuna Rungu</option>
                                    <option>Tuna Wicara</option>
                                    <option>Tuna Daksa</option>
                                    <option>Tuna Grahita</option>
                                    <option>Autisme</option>
                                    <option>Disabilitas Ganda</option>
                                </select>
                                <span class="field-hint">Pilih jenis disabilitas yang tidak diperbolehkan untuk melamar.</span>
                            </div>
                        </div>

                        <div class="modal-section">
                            <div class="section-heading">
                                <div class="section-icon"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
                                <div>
                                    <div class="section-title">Preferensi Gaji</div>
                                    <div class="section-text">Besaran dan pengaturan gaji pada loker</div>
                                </div>
                            </div>
                            <div class="field-grid">
                                <div class="field">
                                    <label>Gaji minimal <span class="req">*</span></label>
                                    <div class="affix-input">
                                        <span class="affix">Rp</span>
                                        <input type="number" name="salary_min" min="0" step="1000" placeholder="Isi minimal gaji..." required>
                                    </div>
                                </div>
                                <div class="field">
                                    <label>Gaji maksimal <span class="req">*</span></label>
                                    <div class="affix-input">
                                        <span class="affix">Rp</span>
                                        <input type="number" name="salary_max" min="0" step="1000" placeholder="Isi maksimal gaji..." required>
                                    </div>
                                </div>
                            </div>
                            <div class="field">
                                <label class="check-item">
                                    <input type="checkbox" name="show_salary" value="1">
                                    <span class="check-box"><i class="fa-solid fa-check"></i></span>
                                    <span class="check-copy">
                                        <strong>Tampilkan gaji</strong>
                                        <span>Fun facts: Berbagi rentang gaji meningkatkan klik posting pekerjaan kamu.</span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div class="modal-section">
                            <div class="section-heading">
                                <div class="section-icon"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
                                <div>
                                    <div class="section-title">Preferensi Lainnya</div>
                                    <div class="section-text">Tentukan preferensi lainnya untuk lowongan pekerjaan</div>
                                </div>
                            </div>
                            <div class="field">
                                <label class="check-item">
                                    <input type="checkbox" name="is_remote" value="1">
                                    <span class="check-box"><i class="fa-solid fa-check"></i></span>
                                    <span class="check-copy">
                                        <strong>Remote working</strong>
                                        <span>Dapat bekerja secara remote (jarak jauh)</span>
                                    </span>
                                </label>
                            </div>
                            <div class="field">
                                <label class="check-item">
                                    <input type="checkbox" name="is_limited" value="1">
                                    <span class="check-box"><i class="fa-solid fa-check"></i></span>
                                    <span class="check-copy">
                                        <strong>Terbatas</strong>
                                        <span>Loker tidak dipublikasikan secara umum</span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div class="modal-section">
                            <div class="section-heading">
                                <div class="section-icon"><i class="fa-solid fa-calendar-days"></i></div>
                                <div>
                                    <div class="section-title">Durasi Tayang &amp; Kuota Loker</div>
                                    <div class="section-text">Tentukan berapa lama loker tayang setelah diverifikasi dan jumlah kuota yang diperlukan</div>
                                </div>
                            </div>
                            <div class="field">
                                <label>Lama expired loker <span class="req">*</span></label>
                                <select name="expiry_days" required>
                                    <option value="">Pilih lama expired loker</option>
                                    <option value="7">7 hari</option>
                                    <option value="14">14 hari</option>
                                    <option value="30">30 hari</option>
                                    <option value="60">60 hari</option>
                                    <option value="90">90 hari</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>Jumlah lowongan <span class="req">*</span></label>
                                <div class="affix-input suffix">
                                    <input type="number" name="quota" min="1" value="1" placeholder="Isi jumlah lowongan" required>
                                    <span class="affix">Orang</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="job-step" data-job-step="2" hidden>
                        <div class="modal-section">
                            <div class="section-heading">
                                <div class="section-icon"><i class="fa-solid fa-file-circle-check"></i></div>
                                <div>
                                    <div class="section-title">Persyaratan Umum</div>
                                    <div class="section-text">Informasi pendidikan, pengalaman, status pernikahan, dan usia.</div>
                                </div>
                            </div>
                            <div class="field-grid">
                                <div class="field">
                                    <label>Pendidikan minimal <span class="req">*</span></label>
                                    <select name="education_required" required>
                                        <option value="">Pilih pendidikan minimal</option>
                                        <option>SD</option>
                                        <option>SMP</option>
                                        <option>SMA / SMK</option>
                                        <option>D1</option>
                                        <option>D2</option>
                                        <option>D3</option>
                                        <option>D4 / S1</option>
                                        <option>S2</option>
                                        <option>S3</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Pengalaman dibutuhkan <span class="req">*</span></label>
                                    <select name="experience_required" required>
                                        <option value="">Pilih pengalaman</option>
                                        <option>Tanpa pengalaman</option>
                                        <option>Kurang dari 1 tahun</option>
                                        <option>1 - 2 tahun</option>
                                        <option>3 - 5 tahun</option>
                                        <option>Lebih dari 5 tahun</option>
                                    </select>
                                </div>
                            </div>
                            <div class="field">
                                <div class="field-label">Status pernikahan <span class="req">*</span></div>
                                <div class="check-row" data-required-group="Pilih minimal satu status pernikahan">
                                    <label class="check-item">
                                        <input type="checkbox" name="marital_status[]" value="Telah Menikah" checked>
                                        <span class="check-box"><i class="fa-solid fa-check"></i></span>
                                        <span class="check-copy"><strong>Telah Menikah</strong></span>
                                    </label>
                                    <label class="check-item">
                                        <input type="checkbox" name="marital_status[]" value="Lajang / Belum Menikah" checked>
                                        <span class="check-box"><i class="fa-solid fa-check"></i></span>
                                        <span class="check-copy"><strong>Lajang / Belum Menikah</strong></span>
                                    </label>
                                </div>
                            </div>
                            <div class="field-grid">
                                <div class="field">
                                    <label>Usia minimal <span class="req">*</span></label>
                                    <div class="affix-input suffix">
                                        <input type="number" name="age_min" min="15" max="70" placeholder="Isi usia minimal" required>
                                        <span class="affix">Tahun</span>
                                    </div>
                                </div>
                                <div class="field">
                                    <label>Usia maksimal <span class="req">*</span></label>
                                    <div class="affix-input suffix">
                                        <input type="number" name="age_max" min="15" max="70" placeholder="Isi usia maksimal" required>
                                        <span class="affix">Tahun</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="modal-section">
                            <div class="section-heading">
                                <div class="section-icon"><i class="fa-solid fa-file-circle-check"></i></div>
                                <div>
                                    <div class="section-title">Persyaratan Khusus</div>
                                    <div class="section-text">Masukkan persyaratan khusus untuk loker ini.</div>
                                </div>
                            </div>
                            <div class="field">
                                <div class="rich-editor" data-rich-editor>
                                    <div class="rich-toolbar">
                                        <button type="button" data-cmd="undo" title="Undo"><i class="fa-solid fa-rotate-left"></i></button>
                                        <button type="button" data-cmd="redo" title="Redo"><i class="fa-solid fa-rotate-right"></i></button>
                                        <select data-block>
                                            <option value="p">Paragraph</option>
                                            <option value="h3">Heading</option>
                                        </select>
                                        <button type="button" data-cmd="bold" title="Bold"><i class="fa-solid fa-bold"></i></button>
                                        <button type="button" data-cmd="italic" title="Italic"><i class="fa-solid fa-italic"></i></button>
                                        <button type="button" data-cmd="underline" title="Underline"><i class="fa-solid fa-underline"></i></button>
                                        <button type="button" data-cmd="strikeThrough" title="Strikethrough"><i class="fa-solid fa-strikethrough"></i></button>
                                        <button type="button" data-cmd="justifyLeft" title="Rata kiri"><i class="fa-solid fa-align-left"></i></button>
                                        <button type="button" data-cmd="justifyCenter" title="Rata tengah"><i class="fa-solid fa-align-center"></i></button>
                                        <button type="button" data-cmd="justifyRight" title="Rata kanan"><i class="fa-solid fa-align-right"></i></button>
                                        <button type="button" data-cmd="insertUnorderedList" title="Bullet"><i class="fa-solid fa-list-ul"></i></button>
                                        <button type="button" data-cmd="insertOrderedList" title="Number"><i class="fa-solid fa-list-ol"></i></button>
                                        <button type="button" data-cmd="createLink" title="Tautan"><i class="fa-solid fa-link"></i></button>
                                    </div>
                                    <div class="rich-area" contenteditable="true" data-placeholder="Masukkan persyaratan khusus"></div>
                                    <textarea name="special_requirements" hidden></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="job-step" data-job-step="3" hidden>
                        <div class="modal-section">
                            <div class="section-heading">
                                <div class="section-icon"><i class="fa-solid fa-graduation-cap"></i></div>
                                <div>
                                    <div class="section-title">Skill / Keahlian</div>
                                    <div class="section-text">Keahlian sangat berpengaruh untuk sistem pencocokan dengan pencari kerja.</div>
                                </div>
                            </div>
                            <div class="field">
                                <select data-chip-select="skills">
                                    <option value="">Pilih keahlian</option>
                                    <option>Microsoft Office</option>
                                    <option>Komunikasi</option>
                                    <option>Pelayanan Pelanggan</option>
                                    <option>Administrasi</option>
                                    <option>Memasak</option>
                                    <option>Mengemudi</option>
                                    <option>Bahasa Inggris</option>
                                    <option>Komputer</option>
                                    <option>Penjualan</option>
                                    <option>Akuntansi</option>
                                    <option>Desain Grafis</option>
                                    <option>Pemrograman</option>
                                    <option>Manajemen Waktu</option>
                                    <option>Kerja Tim</option>
                                </select>
                                <div class="chip-list" data-chip-list="skills"></div>
                                <input type="hidden" name="skills" data-chip-value="skills" required>
                            </div>
                        </div>
                        <div class="modal-section">
                            <div class="section-heading">
                                <div class="section-icon"><i class="fa-solid fa-graduation-cap"></i></div>
                                <div>
                                    <div class="section-title">Kontak</div>
                                    <div class="section-text">Kami akan mengirimkan email ke daftar email di bawah ini untuk setiap lamaran yang masuk.</div>
                                </div>
                            </div>
                            <div class="field">
                                <input type="email" data-chip-input="contacts" placeholder="Klik untuk menambahkan kontak">
                                <div class="chip-list" data-chip-list="contacts"></div>
                                <input type="hidden" name="contacts" data-chip-value="contacts" value="{$employerEmail}" required>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="ghost-btn" data-job-cancel data-close-modal="job-create">Batal</button>
                    <button type="button" class="ghost-btn" data-job-back hidden><i class="fa-solid fa-arrow-left"></i> Kembali</button>
                    <button type="button" class="primary-btn" data-job-next>Selanjutnya</button>
                    <button type="submit" class="primary-btn" data-job-submit hidden>Tambah Loker</button>
                </div>
            </form>
        </div>
    </div>
    <div class="modal-backdrop" data-modal="job-close">
        <div class="modal-panel" style="width: min(500px, 100%);" role="dialog">
            <div class="modal-header">
                <div class="modal-title">Selesaikan Lowongan & Tetapkan Kandidat</div>
                <div class="modal-subtitle">Kuota belum terpenuhi. Mohon isi alasan.</div>
            </div>
            <form method="post" action="dashboard.php">
                <input type="hidden" name="close_job" value="1">
                <input type="hidden" name="job_id" id="close_job_id" value="">
                <input type="hidden" name="sisa_kuota" id="close_sisa_kuota" value="1">
                <div class="modal-body">
                    <div class="modal-section" style="border-bottom:none;">
                        <div class="section-text" style="margin-bottom:8px;">
                            Anda menetapkan kandidat kurang dari kuota yang tersedia. Mohon pilih alasan mengapa sisa kuota belum terpenuhi (pilih minimal 1):
                        </div>
                        <div style="display:flex; flex-direction:column; gap:8px; margin-bottom: 16px;">
                            <label style="font-size:13px;"><input type="checkbox" name="reasons[]" value="Pelamar tidak sesuai kualifikasi"> Pelamar tidak sesuai kualifikasi</label>
                            <label style="font-size:13px;"><input type="checkbox" name="reasons[]" value="Pelamar menolak tawaran"> Pelamar menolak tawaran</label>
                            <label style="font-size:13px;"><input type="checkbox" name="reasons[]" value="Kebutuhan perusahaan berubah"> Kebutuhan perusahaan berubah</label>
                            <label style="font-size:13px;"><input type="checkbox" name="reasons[]" value="Kandidat dari luar sistem"> Kandidat dari luar sistem</label>
                            <label style="font-size:13px;"><input type="checkbox" name="reasons[]" value="Lainnya" onchange="document.getElementById('reason_lainnya').style.display = this.checked ? 'block' : 'none'"> Lainnya</label>
                            <textarea id="reason_lainnya" name="reason_lainnya" placeholder="Tulis alasan spesifik Anda..." style="display:none; font-size:13px; padding:8px; border:1px solid #dbe7f0; border-radius:8px; min-height:60px; margin-top:4px;"></textarea>
                        </div>
                        
                        <div style="background:#f0f9ff; padding:12px; border-radius:8px; border:1px solid #bae6fd;">
                            <div style="font-weight:700; font-size:13px; color:#0369a1; margin-bottom:4px;">Posting Ulang Sisa Kuota?</div>
                            <div style="font-size:12px; color:#0c4a6e; margin-bottom:10px;">Apakah Anda ingin mempublikasikan ulang lowongan ini secara otomatis untuk memenuhi sisa kuota?</div>
                            <select name="repost" required style="width:100%; padding:8px; border:1px solid #bae6fd; border-radius:6px; font-size:13px;">
                                <option value="">Pilih tindakan...</option>
                                <option value="1">Ya, Posting Ulang Sisa Kuota</option>
                                <option value="0">Tidak, Tutup Saja</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="ghost-btn" data-close-modal="job-close">Batal</button>
                    <button type="submit" class="primary-btn">Simpan & Selesaikan</button>
                </div>
            </form>
        </div>
    </div>
    <div class="modal-backdrop" data-modal="applicant-profile">
        <div class="modal-panel applicant-profile-panel" role="dialog" aria-modal="true">
            <div class="modal-header">
                <button type="button" class="modal-close" data-close-modal="applicant-profile" aria-label="Tutup"><i class="fa-solid fa-xmark"></i></button>
                <div class="modal-title" id="applicantName">Profil Pelamar</div>
                <div class="modal-subtitle" id="applicantJob">Lowongan</div>
            </div>
            <form method="post" action="dashboard.php#lowongan">
                <input type="hidden" name="update_application_status" value="1">
                <input type="hidden" name="application_id" id="applicantId" value="">
                <div class="modal-body">
                    <div class="field">
                        <label>Status pelamar</label>
                        <select class="status-select" name="status" id="applicantStatus">
                            <option>Lamaran Masuk</option>
                            <option>Sedang Dipelajari</option>
                            <option>Wawancara</option>
                            <option>Diterima</option>
                            <option>Ditolak</option>
                        </select>
                    </div>
                    <div class="applicant-profile-grid" id="applicantBiodata"></div>
                    <div class="profile-block"><h4>Pendidikan</h4><div id="applicantEducation"></div></div>
                    <div class="profile-block"><h4>Pengalaman</h4><div id="applicantExperience"></div></div>
                    <div class="profile-block"><h4>Keahlian</h4><div id="applicantSkills"></div></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="ghost-btn" data-close-modal="applicant-profile">Tutup</button>
                    <button type="submit" class="primary-btn">Simpan Status</button>
                </div>
            </form>
        </div>
    </div>
HTML;

$html = str_replace('</body>', $modal . "\n</body>", $html);

$html = str_replace('<button class="primary-btn"><i class="fa-solid fa-plus"></i> Tambah Lowongan</button>', '<button class="primary-btn" data-open-modal="job-create"><i class="fa-solid fa-plus"></i> Tambah Lowongan</button>', $html);
$html = str_replace('<button class="primary-btn"><i class="fa-solid fa-plus"></i> Tambah Lowongan</button>', '<button class="primary-btn" data-open-modal="job-create"><i class="fa-solid fa-plus"></i> Tambah Lowongan</button>', $html);
$html = str_replace('<button class="primary-btn"><i class="fa-solid fa-plus"></i> Tambah Lowongan', '<button class="primary-btn" data-open-modal="job-create"><i class="fa-solid fa-plus"></i> Tambah Lowongan', $html);

if (empty($profile['verified']) || $isTransitionPeriod) {
    $html = str_replace('<button class="primary-btn" data-open-modal="job-create"><i class="fa-solid fa-plus"></i> Tambah Lowongan</button>', '<button class="primary-btn" style="opacity:0.5;cursor:not-allowed;" disabled><i class="fa-solid fa-plus"></i> Tambah Lowongan</button>', $html);
    $html = str_replace('<button class="primary-btn" data-open-modal="job-create"><i class="fa-solid fa-plus"></i> Tambah Lowongan', '<button class="primary-btn" style="opacity:0.5;cursor:not-allowed;" disabled><i class="fa-solid fa-plus"></i> Tambah Lowongan', $html);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_job'])) {
    if (empty($profile['verified'])) {
        flash('error', 'Tidak dapat membuat lowongan karena profil Anda belum diverifikasi oleh Admin.');
        redirect('dashboard.php#lowongan');
        exit;
    }
    
    if ($isExpired) {
        flash('error', 'Tidak dapat membuat lowongan di masa transisi/kedaluwarsa.');
        redirect('dashboard.php#lowongan');
        exit;
    }

    $title = trim($_POST['job_title'] ?? '');
    $description = trim(strip_tags($_POST['job_description'] ?? '', '<p><br><b><strong><i><em><u><s><ul><ol><li><h3><a>'));
    $location = trim($_POST['job_location'] ?? '');
    $jobType = trim($_POST['job_type'] ?? '');
    $industry = trim($_POST['industry'] ?? '');
    $kbjiCode = trim($_POST['kbji_code'] ?? '');
    $quota = max(1, (int) ($_POST['quota'] ?? 1));
    $status = 'Menunggu Verifikasi';
    $reviseJobId = (int) ($_POST['revise_job_id'] ?? 0);
    $salaryMin = ($_POST['salary_min'] ?? '') !== '' ? (int) $_POST['salary_min'] : null;
    $salaryMax = ($_POST['salary_max'] ?? '') !== '' ? (int) $_POST['salary_max'] : null;
    $details = json_encode([
        'job_field' => trim($_POST['job_field'] ?? ''),
        'physical_conditions' => array_values(array_filter((array) ($_POST['physical_condition'] ?? []))),
        'genders' => array_values(array_filter((array) ($_POST['gender'] ?? []))),
        'disability_excluded' => trim($_POST['disability_excluded'] ?? ''),
        'show_salary' => !empty($_POST['show_salary']),
        'is_remote' => !empty($_POST['is_remote']),
        'is_limited' => !empty($_POST['is_limited']),
        'expiry_days' => (int) ($_POST['expiry_days'] ?? 0),
        'education_required' => trim($_POST['education_required'] ?? ''),
        'experience_required' => trim($_POST['experience_required'] ?? ''),
        'marital_statuses' => array_values(array_filter((array) ($_POST['marital_status'] ?? []))),
        'age_min' => ($_POST['age_min'] ?? '') !== '' ? (int) $_POST['age_min'] : null,
        'age_max' => ($_POST['age_max'] ?? '') !== '' ? (int) $_POST['age_max'] : null,
        'special_requirements' => trim(strip_tags($_POST['special_requirements'] ?? '', '<p><br><b><strong><i><em><u><s><ul><ol><li><h3><a>')),
        'skills' => array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['skills'] ?? ''))))),
        'contacts' => array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['contacts'] ?? ''))))),
    ], JSON_UNESCAPED_UNICODE);

    if ($title !== '' && $description !== '' && $location !== '' && $jobType !== '' && $kbjiCode !== '') {
        $jobColumns = array_column(db()->query('SHOW COLUMNS FROM job_posts')->fetchAll(), 'Field');
        $neededColumns = [
            'kbji_code' => 'VARCHAR(20) NULL',
            'details' => 'TEXT NULL',
            'parent_job_id' => 'INT NULL',
            'unfulfilled_reason' => 'TEXT NULL',
        ];
        foreach ($neededColumns as $columnName => $columnDef) {
            if (!in_array($columnName, $jobColumns, true)) {
                db()->exec('ALTER TABLE job_posts ADD COLUMN ' . $columnName . ' ' . $columnDef);
            }
        }

        $cekDuplicate = db()->prepare('SELECT id FROM job_posts WHERE user_id = ? AND kbji_code = ? AND status IN ("Menunggu Verifikasi", "Tayang") AND id != ? LIMIT 1');
        $cekDuplicate->execute([$user['id'], $kbjiCode, $reviseJobId]);
        if ($cekDuplicate->fetch()) {
            flash('error', 'Anda tidak dapat membuat lowongan baru untuk jabatan yang sama selama masih terdapat lowongan aktif untuk posisi tersebut.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        if ($reviseJobId > 0) {
            $statement = db()->prepare('UPDATE job_posts SET title=?, description=?, location=?, job_type=?, industry=?, status=?, salary_min=?, salary_max=?, quota=?, kbji_code=?, details=?, updated_at=NOW() WHERE id=? AND user_id=?');
            $statement->execute([$title, $description, $location, $jobType, $industry, $status, $salaryMin, $salaryMax, $quota, $kbjiCode, $details, $reviseJobId, $user['id']]);
            flash('success', 'Revisi lowongan berhasil dikirim ulang ke Admin.');
        } else {
            $statement = db()->prepare('INSERT INTO job_posts (user_id, title, description, location, job_type, industry, status, salary_min, salary_max, quota, kbji_code, details) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $statement->execute([$user['id'], $title, $description, $location, $jobType, $industry, $status, $salaryMin, $salaryMax, $quota, $kbjiCode, $details]);
            flash('success', 'Lowongan baru berhasil dikirim dan menunggu tinjauan Admin.');
        }
        redirect('dashboard.php#lowongan');
    } else {
        flash('error', 'Lengkapi judul, deskripsi, lokasi, jenis pekerjaan, dan KBJI.');
        redirect('dashboard.php#lowongan');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_job'])) {
    $jobId = (int)$_POST['job_id'];
    $sisaKuota = (int)$_POST['sisa_kuota'];
    $repost = $_POST['repost'] === '1';
    $reasons = $_POST['reasons'] ?? [];
    $lainnya = trim($_POST['reason_lainnya'] ?? '');
    
    // Validate reasons
    if (empty($reasons)) {
        flash('error', 'Anda harus memilih minimal 1 alasan.');
        redirect('dashboard.php#lowongan');
        exit;
    }
    if (in_array('Lainnya', $reasons) && $lainnya === '') {
        flash('error', 'Alasan "Lainnya" wajib diisi.');
        redirect('dashboard.php#lowongan');
        exit;
    }

    $reasonStr = implode(', ', $reasons);
    if (in_array('Lainnya', $reasons)) {
        $reasonStr .= ' - ' . $lainnya;
    }

    // 1. Set original job to Closed
    $stmt = db()->prepare('UPDATE job_posts SET status = "Ditutup", unfulfilled_reason = ? WHERE id = ? AND user_id = ?');
    $stmt->execute([$reasonStr, $jobId, $user['id']]);

    if ($repost) {
        // 2. Auto-Reposting (Duplicate)
        $jobStmt = db()->prepare('SELECT * FROM job_posts WHERE id = ?');
        $jobStmt->execute([$jobId]);
        $oldJob = $jobStmt->fetch();

        if ($oldJob) {
            $insert = db()->prepare('INSERT INTO job_posts (user_id, title, description, location, job_type, industry, status, quota, kbji_code, parent_job_id) VALUES (?, ?, ?, ?, ?, ?, "Menunggu Verifikasi", ?, ?, ?)');
            $insert->execute([
                $user['id'], 
                $oldJob['title'], 
                $oldJob['description'], 
                $oldJob['location'], 
                $oldJob['job_type'], 
                $oldJob['industry'], 
                $sisaKuota, 
                $oldJob['kbji_code'], 
                $jobId
            ]);
            flash('success', 'Lowongan ditutup dan sisa kuota berhasil diposting ulang (Menunggu Verifikasi).');
        }
    } else {
        flash('success', 'Lowongan berhasil ditutup.');
    }
    
    redirect('dashboard.php#lowongan');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_extension'])) {
    if ($profile['extension_requested'] == 0) {
        // Beri perpanjangan 3 hari (otomatis disetujui untuk kemudahan, atau admin logic)
        $newActiveDate = (new DateTime($profile['active_until']))->modify('+3 days')->format('Y-m-d H:i:s');
        $stmt = db()->prepare('UPDATE employer_profiles SET extension_requested = 1, active_until = ? WHERE user_id = ?');
        $stmt->execute([$newActiveDate, $user['id']]);
        flash('success', 'Perpanjangan waktu 3 hari berhasil diaktifkan.');
    } else {
        flash('error', 'Anda sudah menggunakan kesempatan perpanjangan waktu.');
    }
    redirect('dashboard.php');
}

if (!str_contains($html, 'src="assets/app.js"')) {
    $html = str_replace('</body>', '<script src="assets/app.js"></script>' . "\n</body>", $html);
}

if (!str_contains($html, 'window.testCloseJob')) {
    $html = str_replace('</body>', <<<'JS'
    <script>
    window.testCloseJob = function(jobId, sisaKuota) {
        document.getElementById("close_job_id").value = jobId;
        document.getElementById("close_sisa_kuota").value = sisaKuota;
        const modal = document.querySelector("[data-modal='job-close']");
        if (modal) modal.classList.add("open");
    };
    </script>
</body>
JS, $html);
}

$employerJobs = db()->prepare('SELECT * FROM job_posts WHERE user_id = ? ORDER BY created_at DESC');
$employerJobs->execute([$user['id']]);
$employerJobs = $employerJobs->fetchAll();
$jobCounts = ['Draft' => 0, 'Menunggu Verifikasi' => 0, 'Perlu Revisi' => 0, 'Tayang' => 0];
foreach ($employerJobs as $row) {
    if (isset($jobCounts[$row['status']])) {
        $jobCounts[$row['status']]++;
    }
}

$jobRowsHtml = '';
if (!$employerJobs) {
    $jobRowsHtml = '<tr><td colspan="6" style="text-align:center;color:#64748b;padding:28px">Belum ada lowongan. Klik Tambah Lowongan untuk mengirim ke Admin.</td></tr>';
} else {
    foreach ($employerJobs as $row) {
        $meta = job_status_meta($row['status']);
        $countStmt = db()->prepare('SELECT COUNT(*) FROM job_applications WHERE job_id = ?');
        $countStmt->execute([$row['id']]);
        $appCount = (int) $countStmt->fetchColumn();
        $reviseBtn = $row['status'] === 'Perlu Revisi'
            ? '<button type="button" class="ghost-btn" data-revise-job="' . (int) $row['id'] . '">Revisi</button>'
            : '-';
        $jobRowsHtml .= '<tr><td><strong>' . e($row['title']) . '</strong><div class="tiny">Dibuat ' . e(date('d M Y', strtotime($row['created_at']))) . '</div></td>'
            . '<td>' . e($row['location']) . '</td>'
            . '<td>' . (int) $row['quota'] . ' orang</td>'
            . '<td>' . $appCount . ' pelamar</td>'
            . '<td><span class="status ' . e($meta['class']) . '">' . e($meta['label']) . '</span></td>'
            . '<td>' . $reviseBtn . '</td></tr>';
    }
}

$applicants = employer_applicants((int) $user['id']);
$stageCounts = [
    'Lamaran Masuk' => 0,
    'Sedang Dipelajari' => 0,
    'Wawancara' => 0,
    'Diterima' => 0,
    'Ditolak' => 0,
];
foreach ($applicants as $applicant) {
    $stage = normalize_application_status($applicant['status'] ?? '');
    if (isset($stageCounts[$stage])) {
        $stageCounts[$stage]++;
    }
}
$totalApplicants = count($applicants);
$funnelTotal = max(1, $totalApplicants);
$hiredRate = $totalApplicants ? (int) round(($stageCounts['Diterima'] / $totalApplicants) * 100) : 0;
$funnelRow = static function (string $label, int $count, int $total, string $extraClass = '') {
    $width = $total > 0 ? max(8, (int) round(($count / $total) * 100)) : 8;
    return '<div class="funnel-row"><div class="funnel-label">' . e($label) . '</div><div class="funnel-track"><div class="funnel-fill ' . $extraClass . '" style="width:' . $width . '%">' . $count . '</div></div><div class="funnel-percent">' . $width . '%</div></div>';
};
$funnelHtml = '<div class="funnel-layout"><div class="funnel-bars">'
    . $funnelRow('Pelamar Masuk', $totalApplicants, $funnelTotal)
    . $funnelRow('Dipelajari', $stageCounts['Sedang Dipelajari'], $funnelTotal, 'soft')
    . $funnelRow('Wawancara', $stageCounts['Wawancara'], $funnelTotal, 'softest')
    . $funnelRow('Diterima', $stageCounts['Diterima'], $funnelTotal, 'pale')
    . '</div><div class="funnel-stats"><h4>Konversi keseluruhan</h4><h2>' . $hiredRate . '%</h2><p>dari pelamar masuk sampai diterima</p><div class="stat-block"><div style="color:var(--muted);font-size:12px;margin-bottom:8px;">Tidak berlanjut</div><div class="stat-block-row"><span>Ditolak</span><strong>' . $stageCounts['Ditolak'] . '</strong></div></div></div></div>';

if (!$applicants) {
    $readyHtml = '<div class="empty-state"><i class="fa-solid fa-user-group" style="color:#c9dff7"></i><h4>Belum ada pelamar</h4><p>Pelamar yang masuk akan<br>muncul di sini.</p></div>';
    $applicantHtml = '<div class="record-item"><strong>Belum ada pelamar</strong><span>Lamaran dari pencari kerja akan tampil di sini.</span></div>';
    $activityHtml = '<div class="list"><div class="activity-item"><div class="activity-title">Belum ada aktivitas lamaran.</div><div class="activity-subtitle">Riwayat perubahan status akan muncul di sini.</div></div></div>';
} else {
    $readyHtml = '<div class="applicant-ready-list">';
    foreach ($applicants as $index => $applicant) {
        $meta = application_status_meta($applicant['status']);
        $hidden = $index >= 4 ? ' hidden' : '';
        $avatar = 'https://ui-avatars.com/api/?name=' . rawurlencode($applicant['seeker_name']) . '&background=e8f7fc&color=1e97c4';
        $readyHtml .= '<button type="button" class="applicant-item" data-open-applicant="' . (int) $applicant['id'] . '"' . $hidden . '>'
            . '<img class="avatar" src="' . e($avatar) . '" alt="">'
            . '<div class="meta"><div class="title">' . e($applicant['seeker_name']) . '</div><div class="subtitle">' . e($applicant['job_title']) . '</div></div>'
            . '<span class="tag ' . e($meta['class']) . '">' . e($meta['label']) . '</span></button>';
    }
    $readyHtml .= '</div>';
    if ($totalApplicants > 4) {
        $readyHtml .= '<button type="button" class="show-more-btn" data-expand-applicants>Tampilkan lebih banyak</button>';
    }

    $applicantHtml = '<table class="applicant-table"><thead><tr><th>Pelamar</th><th>Lowongan</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach ($applicants as $applicant) {
        $meta = application_status_meta($applicant['status']);
        $applicantHtml .= '<tr data-open-applicant="' . (int) $applicant['id'] . '"><td><strong>' . e($applicant['seeker_name']) . '</strong><div class="tiny">' . e($applicant['seeker_email']) . '</div></td><td>' . e($applicant['job_title']) . '</td><td><span class="tag ' . e($meta['class']) . '">' . e($meta['label']) . '</span></td><td>Lihat profil</td></tr>';
    }
    $applicantHtml .= '</tbody></table>';

    $activityHtml = '<div class="list">';
    foreach (array_slice($applicants, 0, 5) as $applicant) {
        $meta = application_status_meta($applicant['status']);
        $when = $applicant['updated_at'] ?: $applicant['created_at'];
        $activityHtml .= '<div class="activity-item"><div class="activity-title">' . e($applicant['seeker_name']) . ' · ' . e($meta['label']) . '</div><div class="activity-subtitle">' . e($applicant['job_title']) . '</div><div class="activity-meta">' . e(date('d M Y H:i', strtotime((string) $when))) . '</div></div>';
    }
    $activityHtml .= '</div>';
}

$notifications = user_notifications((int) $user['id']);
$unread = unread_notification_count((int) $user['id']);
$html = str_replace('<div class="notif"><i class="fa-regular fa-bell"></i></div>', render_notif_dropdown($notifications, $unread), $html);
$html = preg_replace('/<h3>Draft<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Draft</h3><div class="value">' . $jobCounts['Draft'] . '</div>', $html, 1);
$html = preg_replace('/<h3>Dikirim<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Dikirim</h3><div class="value">' . $jobCounts['Menunggu Verifikasi'] . '</div>', $html, 1);
$html = preg_replace('/<h3>Perlu Direvisi<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Perlu Direvisi</h3><div class="value">' . $jobCounts['Perlu Revisi'] . '</div>', $html, 1);
$html = preg_replace('/<h3>Lowongan Aktif<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Lowongan Aktif</h3><div class="value">' . $jobCounts['Tayang'] . '</div>', $html, 1);
$html = str_replace('<!--JOB_TABLE_ROWS-->', $jobRowsHtml, $html);
$html = str_replace('<!--JOB_APPLICANTS-->', $applicantHtml, $html);
$html = str_replace('<!--READY_APPLICANTS-->', $readyHtml, $html);
$html = str_replace('<!--FUNNEL_METRICS-->', $funnelHtml, $html);
$html = str_replace('<!--APPLICATION_ACTIVITY-->', $activityHtml, $html);
$html = preg_replace('/<h3>Lowongan<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Lowongan</h3><div class="value">' . count($employerJobs) . '</div>', $html, 1);
$html = preg_replace('/<h3>Pelamar<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Pelamar</h3><div class="value">' . $totalApplicants . '</div>', $html, 1);
$html = preg_replace('/<h3>Wawancara<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Wawancara</h3><div class="value">' . $stageCounts['Wawancara'] . '</div>', $html, 1);
$html = preg_replace('/<h3>Diterima<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Diterima</h3><div class="value">' . $stageCounts['Diterima'] . '</div>', $html, 1);

echo $html;
