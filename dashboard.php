<?php
require __DIR__ . '/includes/bootstrap.php';

$user = require_role('employer');

$profileStatement = db()->prepare('SELECT * FROM employer_profiles WHERE user_id = ? LIMIT 1');
$profileStatement->execute([$user['id']]);
$profile = $profileStatement->fetch() ?: [];

if (!is_profile_complete($user)) {
    redirect('profile-employer.php');
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
    'Akun Anda telah aktif dan berlaku selama 3 bulan. Anda kini dapat mempublikasikan lowongan kerja atau proyek.<br>' . "\r\n" . '                        <strong>Catatan penting: Anda hanya dapat memiliki 1 lowongan aktif dalam satu waktu dan wajib menetapkan kandidat terpilih sebelum dapat membuat postingan lowongan baru.</strong>' => 'Akun Anda telah aktif dan berlaku selama 3 bulan. Anda dapat memiliki lebih dari satu lowongan aktif untuk jabatan/posisi yang berbeda.<br>' . "\r\n" . '                        <strong>Catatan penting: Anda tidak dapat membuat lowongan baru untuk jabatan/posisi yang sama selama masih terdapat lowongan aktif untuk posisi tersebut.</strong>',
];

if (empty($profile['verified'])) {
    $alertKey = 'Akun Anda telah aktif dan berlaku selama 3 bulan. Anda dapat memiliki lebih dari satu lowongan aktif untuk jabatan/posisi yang berbeda.<br>' . "\r\n" . '                        <strong>Catatan penting: Anda tidak dapat membuat lowongan baru untuk jabatan/posisi yang sama selama masih terdapat lowongan aktif untuk posisi tersebut.</strong>';
    $replacements[$alertKey] = '<strong style="color:orange"><i class="fa-solid fa-clock"></i> Menunggu Verifikasi Admin:</strong> Profil Anda sedang dalam tahap peninjauan. Fitur posting lowongan belum dapat diakses sebelum akun disetujui.';
    // Disable Add Button
    $html = str_replace('<button class="action-chip"><i class="fa-solid fa-plus"></i> Tambah</button>', '<button class="action-chip" style="opacity:0.5;cursor:not-allowed;" disabled><i class="fa-solid fa-plus"></i> Tambah</button>', $html);
} else if ($isTransitionPeriod) {
    $btnPerpanjangan = '';
    if ($profile['extension_requested'] == 0) {
        $btnPerpanjangan = '<form method="post" action="dashboard.php" style="margin-top:10px;"><input type="hidden" name="request_extension" value="1"><button type="submit" class="ghost-btn" style="border:1px solid red; color:red; padding:4px 12px; font-size:12px;"><i class="fa-solid fa-clock-rotate-left"></i> Ajukan Perpanjangan Waktu (1x)</button></form>';
    }
    $alertKey = 'Akun Anda telah aktif dan berlaku selama 3 bulan. Anda dapat memiliki lebih dari satu lowongan aktif untuk jabatan/posisi yang berbeda.<br>' . "\r\n" . '                        <strong>Catatan penting: Anda tidak dapat membuat lowongan baru untuk jabatan/posisi yang sama selama masih terdapat lowongan aktif untuk posisi tersebut.</strong>';
    $replacements[$alertKey] = '<strong style="color:red">Masa Transisi (Akses Dibatasi):</strong> Masa aktif Anda telah habis. Akses saat ini difokuskan untuk menyelesaikan rekrutmen. Tombol posting lowongan telah dinonaktifkan.<br>Sisa masa transisi: ' . (7 + $daysRemaining) . ' hari.' . $btnPerpanjangan;
    // Disable Add Button
    $html = str_replace('<button class="action-chip"><i class="fa-solid fa-plus"></i> Tambah</button>', '<button class="action-chip" style="opacity:0.5;cursor:not-allowed;" disabled><i class="fa-solid fa-plus"></i> Tambah</button>', $html);
} else if ($daysRemaining <= 7) {
    $btnPerpanjangan = '';
    if ($profile['extension_requested'] == 0) {
        $btnPerpanjangan = '<form method="post" action="dashboard.php" style="margin-top:10px;"><input type="hidden" name="request_extension" value="1"><button type="submit" class="ghost-btn" style="border:1px solid orange; color:orange; padding:4px 12px; font-size:12px;"><i class="fa-solid fa-clock-rotate-left"></i> Ajukan Perpanjangan Waktu (1x)</button></form>';
    }
    $alertKey = 'Akun Anda telah aktif dan berlaku selama 3 bulan. Anda dapat memiliki lebih dari satu lowongan aktif untuk jabatan/posisi yang berbeda.<br>' . "\r\n" . '                        <strong>Catatan penting: Anda tidak dapat membuat lowongan baru untuk jabatan/posisi yang sama selama masih terdapat lowongan aktif untuk posisi tersebut.</strong>';
    $replacements[$alertKey] = '<strong style="color:orange">Pengingat:</strong> Masa aktif akun Anda akan berakhir dalam ' . $daysRemaining . ' hari. Segera selesaikan rekrutmen Anda sebelum masa transisi dimulai.' . $btnPerpanjangan;
}


$html = str_replace(array_keys($replacements), array_values($replacements), $html);

$modalStyles = <<<'CSS'
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            display: none;
            align-items: center;
            justify-content: flex-end;
            z-index: 1000;
            padding: 12px;
        }
        .modal-backdrop.open {
            display: flex;
        }
        .modal-panel {
            width: min(720px, 100%);
            max-height: calc(100vh - 24px);
            overflow: auto;
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 24px 80px rgba(15, 23, 42, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.6);
        }
        .modal-header,
        .modal-footer {
            padding: 16px 20px;
            border-bottom: 1px solid #eef2f7;
        }
        .modal-footer {
            border-bottom: none;
            border-top: 1px solid #eef2f7;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .modal-title {
            font-size: 18px;
            font-weight: 800;
            margin-bottom: 6px;
        }
        .modal-subtitle {
            color: #64748b;
            font-size: 13px;
        }
        .stepper {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 14px;
            flex-wrap: wrap;
        }
        .step {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
        }
        .step .bubble {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #e5e7eb;
            display: grid;
            place-items: center;
            color: #64748b;
            font-size: 11px;
        }
        .step.active {
            color: #1e97c4;
        }
        .step.active .bubble {
            background: #1e97c4;
            color: #fff;
        }
        .step.done {
            color: #0f9d63;
        }
        .step.done .bubble {
            background: #16a34a;
            color: #fff;
        }
        .modal-body {
            padding: 18px 20px 6px;
        }
        .modal-section {
            margin-bottom: 18px;
            padding-bottom: 18px;
            border-bottom: 1px solid #eef2f7;
        }
        .modal-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .section-title {
            font-size: 15px;
            font-weight: 800;
            margin-bottom: 8px;
        }
        .section-text {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 14px;
        }
        .field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .field-grid .field {
            margin-bottom: 0;
        }
        .option-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .option-chip {
            border: 1px solid #dbe7f0;
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 12px;
            color: #334155;
            background: #fff;
        }
        .option-chip input {
            margin-right: 6px;
        }
        .modal-footer .ghost-btn,
        .modal-footer .primary-btn {
            height: 42px;
            padding: 0 16px;
            border-radius: 14px;
        }
        .title-topline {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }
        .title-topline .chip-inline {
            font-size: 11px;
        }
        @media (max-width: 760px) {
            .modal-backdrop {
                justify-content: center;
            }
            .modal-panel {
                width: 100%;
            }
            .field-grid {
                grid-template-columns: 1fr;
            }
        }
CSS;

$html = str_replace('</style>', $modalStyles . "\n    </style>", $html);

$modal = <<<'HTML'
    <div class="modal-backdrop" data-modal="job-create">
        <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="jobCreateTitle">
            <div class="modal-header">
                <div class="title-topline">
                    <div>
                        <div class="modal-title" id="jobCreateTitle">Tambah Lowongan</div>
                        <div class="modal-subtitle">Lengkapi form berikut untuk mengisi lowongan</div>
                    </div>
                </div>
                <div class="stepper">
                    <span class="step done"><span class="bubble">✓</span> Informasi Loker</span>
                    <span class="step active"><span class="bubble">2</span> Persyaratan</span>
                    <span class="step"><span class="bubble">3</span> Tambahan</span>
                </div>
            </div>
            <form method="post" action="dashboard.php">
                <input type="hidden" name="create_job" value="1">
                <div class="modal-body">
                    <div class="modal-section">
                        <div class="section-title">Informasi Loker</div>
                        <div class="section-text">Judul, lokasi, dan tipe pekerjaan.</div>
                        <div class="field-grid">
                            <div class="field">
                                <label>Judul loker</label>
                                <input type="text" name="job_title" placeholder="Masukkan judul loker" required>
                            </div>
                            <div class="field">
                                <label>Lokasi loker</label>
                                <input type="text" name="job_location" placeholder="Pilih lokasi loker" required>
                            </div>
                            <div class="field">
                                <label>Jenis pekerjaan</label>
                                <select name="job_type" required>
                                    <option value="">Pilih jenis pekerjaan</option>
                                    <option>Full Time</option>
                                    <option>Part Time</option>
                                    <option>Freelance</option>
                                    <option>Remote</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>Bidang pekerjaan</label>
                                <input type="text" name="industry" placeholder="Pilih bidang pekerjaan">
                            </div>
                            <div class="field" style="grid-column: span 2;">
                                <label>Jabatan (KBJI)</label>
                                <select name="kbji_code" required>
                                    <option value="">Pilih Jabatan Standar KBJI</option>
                                    <?php
                                    $kbjiStmt = db()->query('SELECT kode_kbji, nama_jabatan FROM kbji_data ORDER BY nama_jabatan ASC');
                                    while ($kbji = $kbjiStmt->fetch()) {
                                        echo \'<option value="\' . $kbji[\'kode_kbji\'] . \'">\' . htmlspecialchars($kbji[\'nama_jabatan\']) . \' (\' . $kbji[\'kode_kbji\'] . \')</option>\';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        <div class="field" style="margin-top:12px;">
                            <label>Deskripsi loker</label>
                            <textarea name="job_description" placeholder="Masukkan deskripsi loker" required></textarea>
                        </div>
                    </div>

                    <div class="modal-section">
                        <div class="section-title">Persyaratan</div>
                        <div class="section-text">Isi persyaratan umum agar proses publish lebih rapi.</div>
                        <div class="field-grid">
                            <div class="field">
                                <label>Pendidikan minimal</label>
                                <select name="education_required">
                                    <option>SMK / SMA</option>
                                    <option>D3</option>
                                    <option>S1</option>
                                    <option>Lainnya</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>Pengalaman dibutuhkan</label>
                                <select name="experience_required">
                                    <option>Tanpa pengalaman</option>
                                    <option>1 - 2 tahun</option>
                                    <option>3 - 5 tahun</option>
                                    <option>Lebih dari 5 tahun</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="modal-section">
                        <div class="section-title">Tambahan</div>
                        <div class="section-text">Atur kuota dan status awal lowongan.</div>
                        <div class="field-grid">
                            <div class="field">
                                <label>Kuota</label>
                                <input type="number" name="quota" min="1" value="1">
                            </div>
                            <div class="field">
                                <label>Status awal</label>
                                <select name="status">
                                    <option value="Draft">Draft</option>
                                    <option value="Menunggu Verifikasi">Menunggu Verifikasi</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="ghost-btn" data-close-modal="job-create">Batal</button>
                    <button type="submit" class="primary-btn">Simpan Lowongan</button>
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
    $description = trim($_POST['job_description'] ?? '');
    $location = trim($_POST['job_location'] ?? '');
    $jobType = trim($_POST['job_type'] ?? '');
    $industry = trim($_POST['industry'] ?? '');
    $kbjiCode = trim($_POST['kbji_code'] ?? '');
    $quota = max(1, (int) ($_POST['quota'] ?? 1));
    $status = $_POST['status'] ?? 'Draft';

    if ($title !== '' && $description !== '' && $location !== '' && $jobType !== '' && $kbjiCode !== '') {
        // Cek duplicate job untuk KBJI yang sama
        $cekDuplicate = db()->prepare('SELECT id FROM job_posts WHERE user_id = ? AND kbji_code = ? AND status IN ("Menunggu Verifikasi", "Tayang") LIMIT 1');
        $cekDuplicate->execute([$user['id'], $kbjiCode]);
        if ($cekDuplicate->fetch()) {
            flash('error', 'Anda tidak dapat membuat lowongan baru untuk jabatan yang sama selama masih terdapat lowongan aktif untuk posisi tersebut.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        $statement = db()->prepare('INSERT INTO job_posts (user_id, title, description, location, job_type, industry, status, quota, kbji_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$user['id'], $title, $description, $location, $jobType, $industry, $status, $quota, $kbjiCode]);
        flash('success', 'Lowongan baru berhasil disimpan sebagai ' . $status . '.');
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

// Dummy JS injection to trigger the modal for testing
$html = str_replace('<script src="assets/app.js"></script>', '<script src="assets/app.js"></script>
    <script>
    // Test helper to open close-job modal
    window.testCloseJob = function(jobId, sisaKuota) {
        document.getElementById("close_job_id").value = jobId;
        document.getElementById("close_sisa_kuota").value = sisaKuota;
        const modal = document.querySelector("[data-modal=\'job-close\']");
        if(modal) modal.classList.add("open");
    };
    </script>', $html);


$html = str_replace('<script>
        const menuItems = Array.from(document.querySelectorAll(\'.menu-item\'));', '<script src="assets/app.js"></script>\n    <script>\n        const menuItems = Array.from(document.querySelectorAll(\'.menu-item\'));', $html);

// Keep the original page script in control, but allow the modal helper from assets/app.js to bind after DOM load.

echo $html;
