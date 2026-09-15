<?php

function ensure_platform_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = db();

    $employerColumns = array_column($pdo->query('SHOW COLUMNS FROM employer_profiles')->fetchAll(), 'Field');
    $employerAdds = [
        'nik' => 'VARCHAR(30) NULL',
        'whatsapp' => 'VARCHAR(30) NULL',
        'linkedin' => 'VARCHAR(255) NULL',
        'facebook' => 'VARCHAR(255) NULL',
        'instagram' => 'VARCHAR(255) NULL',
        'npwp' => 'VARCHAR(40) NULL',
        'latitude' => 'VARCHAR(40) NULL',
        'longitude' => 'VARCHAR(40) NULL',
        'permit_document' => 'VARCHAR(255) NULL',
        'workplace_photo' => 'VARCHAR(255) NULL',
        'consent_accepted' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'active_until' => 'DATETIME NULL',
        'extension_requested' => 'TINYINT(1) NOT NULL DEFAULT 0',
    ];
    foreach ($employerAdds as $name => $definition) {
        if (!in_array($name, $employerColumns, true)) {
            $pdo->exec('ALTER TABLE employer_profiles ADD COLUMN ' . $name . ' ' . $definition);
        }
    }

    $jobColumns = array_column($pdo->query('SHOW COLUMNS FROM job_posts')->fetchAll(), 'Field');
    $jobAdds = [
        'kbji_code' => 'VARCHAR(20) NULL',
        'details' => 'TEXT NULL',
        'parent_job_id' => 'INT NULL',
        'unfulfilled_reason' => 'TEXT NULL',
        'admin_notes' => 'TEXT NULL',
        'revision_opened_at' => 'DATETIME NULL',
    ];
    foreach ($jobAdds as $name => $definition) {
        if (!in_array($name, $jobColumns, true)) {
            $pdo->exec('ALTER TABLE job_posts ADD COLUMN ' . $name . ' ' . $definition);
        }
    }

    try {
        $pdo->exec("ALTER TABLE job_posts MODIFY status ENUM('Draft','Menunggu Verifikasi','Perlu Revisi','Tayang','Ditutup','Ditolak','Penuh') NOT NULL DEFAULT 'Draft'");
    } catch (Throwable $ignored) {
        // ENUM may already include the extra values.
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(180) NOT NULL,
        message TEXT NOT NULL,
        type VARCHAR(40) NOT NULL,
        job_id INT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_notif_user (user_id, is_read)
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS job_applications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        job_id INT NOT NULL,
        seeker_id INT NOT NULL,
        status VARCHAR(40) NOT NULL DEFAULT "Lamaran Masuk",
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_job_seeker (job_id, seeker_id)
    )');

    $appColumns = array_column($pdo->query('SHOW COLUMNS FROM job_applications')->fetchAll(), 'Field');
    if (!in_array('updated_at', $appColumns, true)) {
        $pdo->exec('ALTER TABLE job_applications ADD COLUMN updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
    }
    $pdo->exec("UPDATE job_applications SET status = 'Lamaran Masuk' WHERE status IN ('Dilamar', 'Applied', '')");
}

function notify_user(int $userId, string $title, string $message, string $type = 'info', ?int $jobId = null): void
{
    $statement = db()->prepare('INSERT INTO notifications (user_id, title, message, type, job_id) VALUES (?, ?, ?, ?, ?)');
    $statement->execute([$userId, $title, $message, $type, $jobId]);
}

function user_notifications(int $userId, int $limit = 12): array
{
    $statement = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int) $limit);
    $statement->execute([$userId]);
    return $statement->fetchAll() ?: [];
}

function unread_notification_count(int $userId): int
{
    $statement = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $statement->execute([$userId]);
    return (int) $statement->fetchColumn();
}

function mark_notifications_read(int $userId): void
{
    $statement = db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
    $statement->execute([$userId]);
}

function store_upload(string $field, string $subdir, array $allowedExt): ?string
{
    if (empty($_FILES[$field]['name']) || (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int) $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $original = (string) $_FILES[$field]['name'];
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return null;
    }

    $dir = __DIR__ . '/../uploads/' . trim($subdir, '/');
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $filename = $field . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = $dir . '/' . $filename;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
        return null;
    }

    return 'uploads/' . trim($subdir, '/') . '/' . $filename;
}

function job_status_meta(string $status): array
{
    return match ($status) {
        'Draft' => ['label' => 'Draft', 'class' => 'draft'],
        'Menunggu Verifikasi' => ['label' => 'Menunggu Verifikasi', 'class' => 'pending'],
        'Perlu Revisi' => ['label' => 'Perlu Revisi', 'class' => 'revision'],
        'Tayang' => ['label' => 'Disetujui', 'class' => 'live'],
        'Ditutup' => ['label' => 'Ditutup', 'class' => 'closed'],
        'Ditolak' => ['label' => 'Ditolak', 'class' => 'rejected'],
        'Penuh' => ['label' => 'Penuh', 'class' => 'full'],
        default => ['label' => $status, 'class' => 'draft'],
    };
}

function job_default_approve_note(): string
{
    return 'Lowongan telah memenuhi syarat dan disetujui untuk ditayangkan';
}

function job_revision_quick_tags(): array
{
    return [
        'Deskripsi pekerjaan kurang lengkap atau tidak jelas (rincian tugas/tanggung jawab belum dijabarkan spesifik).',
        'Kode KBJI tidak sesuai dengan posisi jabatan (tidak sinkron dengan judul/uraian pekerjaan).',
        'Persyaratan atau kualifikasi tidak wajar / diskriminatif (memuat kriteria fisik/syarat yang melanggar norma).',
        'Informasi gaji atau kompensasi tidak jelas (tidak dicantumkan atau berpotensi menyesatkan).',
        'Penulisan lowongan tidak profesional (huruf kapital berlebihan, banyak typo, atau tata bahasa tidak baku).',
        'Jenis pekerjaan atau status kontrak tidak sesuai (kategori magang/kontrak/penuh waktu tidak sinkron dengan isi teks).',
    ];
}

function job_rich_html(?string $html): string
{
    $clean = strip_tags((string) $html, '<p><br><b><strong><i><em><u><s><ul><ol><li><h3><h4><a><span>');
    return trim($clean);
}

function format_rupiah(mixed $value): string
{
    if ($value === null || $value === '') {
        return 'Tidak dicantumkan';
    }

    return 'Rp ' . number_format((int) $value, 0, ',', '.');
}

function job_yes_no(bool $value): string
{
    return $value ? 'Ya' : 'Tidak';
}

function time_ago_id(?string $datetime): string
{
    $timestamp = strtotime((string) $datetime);
    if (!$timestamp) {
        return '-';
    }

    $diff = max(0, time() - $timestamp);
    if ($diff < 60) {
        return 'baru saja';
    }
    if ($diff < 3600) {
        return 'sekitar ' . (int) floor($diff / 60) . ' menit yang lalu';
    }
    if ($diff < 86400) {
        return 'sekitar ' . (int) floor($diff / 3600) . ' jam yang lalu';
    }
    if ($diff < 2592000) {
        $days = (int) floor($diff / 86400);
        return $days . ' hari yang lalu';
    }

    return date('d M Y', $timestamp);
}

function job_public_salary(array $job): string
{
    $data = job_to_form_data($job);
    if (empty($data['show_salary']) || ($data['salary_min'] === null && $data['salary_max'] === null)) {
        return 'Dirahasiakan';
    }

    $min = $data['salary_min'] !== null ? format_rupiah($data['salary_min']) : '';
    $max = $data['salary_max'] !== null ? format_rupiah($data['salary_max']) : '';
    if ($min && $max) {
        return $min . ' - ' . $max;
    }

    return $min ?: $max;
}

function job_apply_deadline(array $job): string
{
    $days = (int) (job_to_form_data($job)['expiry_days'] ?: 30);
    $created = strtotime((string) ($job['created_at'] ?? 'now')) ?: time();
    return date('d M Y', strtotime('+' . $days . ' days', $created));
}

function job_employer_display_name(array $job): string
{
    $owner = trim((string) ($job['owner_name'] ?? ''));
    return $owner !== '' ? $owner : (string) ($job['employer_name'] ?? 'Pemberi kerja individu');
}

function job_placement_address(array $job): string
{
    $parts = array_values(array_filter([
        trim((string) ($job['address'] ?? '')),
        trim((string) ($job['location'] ?? '')),
        trim((string) ($job['city'] ?? '')),
        trim((string) ($job['province'] ?? '')),
    ], static fn($value) => $value !== ''));
    $unique = [];
    foreach ($parts as $part) {
        if (!in_array($part, $unique, true)) {
            $unique[] = $part;
        }
    }

    return $unique ? implode(', ', $unique) : '-';
}

function job_employer_contact(array $job): string
{
    return trim((string) (($job['whatsapp'] ?? '') ?: ($job['phone'] ?? ''))) ?: '-';
}

function seeker_city_options(): array
{
    return [
        'Kota Bekasi',
        'Kabupaten Bekasi',
        'Kota Jakarta Pusat',
        'Kota Jakarta Selatan',
        'Kota Jakarta Timur',
        'Kota Jakarta Barat',
        'Kota Jakarta Utara',
        'Kota Bandung',
        'Kota Surabaya',
        'Kota Semarang',
        'Kota Yogyakarta',
        'Kota Depok',
        'Kota Tangerang',
        'Kota Tangerang Selatan',
        'Kota Medan',
        'Kota Makassar',
        'Kota Denpasar',
    ];
}

function seeker_job_type_options(): array
{
    return ['Penuh Waktu', 'Paruh Waktu', 'Kontrak', 'Magang', 'Freelance', 'Harian'];
}

function public_job_select_sql(): string
{
    return 'SELECT j.*, u.name AS employer_name, u.email AS employer_email,
            ep.owner_name, ep.profession, ep.address, ep.city, ep.province, ep.phone, ep.whatsapp,
            ep.verified, ep.description AS employer_bio, ep.workplace_photo, ep.latitude, ep.longitude
        FROM job_posts j
        JOIN users u ON u.id = j.user_id
        LEFT JOIN employer_profiles ep ON ep.user_id = j.user_id
        WHERE j.status = "Tayang"';
}

function render_seeker_job_card(array $job, array $appliedIds): string
{
    $applied = in_array((int) $job['id'], $appliedIds, true);
    $name = job_employer_display_name($job);
    $initial = strtoupper(mb_substr($name, 0, 1));
    $photo = trim((string) ($job['workplace_photo'] ?? ''));
    $avatar = $photo !== ''
        ? '<img src="' . e($photo) . '" alt="' . e($name) . '">'
        : e($initial);
    $titleUrl = 'seeker.php?page=job&id=' . (int) $job['id'];
    $quota = max(1, (int) ($job['quota'] ?? 1));

    $html = '<article class="vacancy-card">';
    $html .= '<div class="vacancy-card-top">';
    $html .= '<div class="vacancy-logo">' . $avatar . '</div>';
    $html .= '<span class="vacancy-time">' . e(time_ago_id($job['created_at'] ?? null)) . '</span>';
    $html .= '</div>';
    $html .= '<h3><a href="' . e($titleUrl) . '">' . e($job['title']) . '</a></h3>';
    $html .= '<div class="vacancy-employer">' . e($name) . ' <span class="perorangan-badge">Perorangan</span></div>';
    $html .= '<div class="vacancy-location"><i class="fa-solid fa-location-dot"></i> ' . e($job['location'] ?: '-') . '</div>';
    $html .= '<div class="vacancy-salary"><span>Kisaran Gaji</span><strong>' . e(job_public_salary($job)) . '</strong></div>';
    $html .= '<div class="vacancy-deadline">Lamar sebelum ' . e(job_apply_deadline($job)) . '</div>';
    $html .= '<div class="vacancy-badge">Tersisa ' . $quota . ' lowongan</div>';
    if ($applied) {
        $html .= '<button class="vacancy-apply is-disabled" type="button" disabled>Sudah Dilamar</button>';
    } else {
        $html .= '<form method="post" class="vacancy-apply-form"><input type="hidden" name="apply_job_id" value="' . (int) $job['id'] . '"><button class="vacancy-apply" type="submit">Lamar Sekarang</button></form>';
    }
    $html .= '<div class="vacancy-card-foot"><span>Lowongan dari <strong>Karirhub Perorangan</strong></span><span class="vacancy-source-mark"><i class="fa-solid fa-star"></i> Karirhub</span></div>';
    $html .= '</article>';

    return $html;
}

function kbji_name_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $map = [];
    try {
        foreach (db()->query('SELECT kode_kbji, nama_jabatan FROM kbji_data') as $row) {
            $map[(string) $row['kode_kbji']] = (string) $row['nama_jabatan'];
        }
    } catch (Throwable $ignored) {
        $map = [];
    }

    return $map;
}

function render_job_review_details(array $job): string
{
    $data = job_to_form_data($job);
    $kbjiName = kbji_name_map()[$data['kbji_code']] ?? '';
    $kbjiText = trim($data['kbji_code'] . ($kbjiName !== '' ? ' — ' . $kbjiName : ''));
    $salary = format_rupiah($data['salary_min']) . ' – ' . format_rupiah($data['salary_max']);
    if (!empty($data['show_salary'])) {
        $salary .= ' (ditampilkan pada lowongan)';
    } else {
        $salary .= ' (disembunyikan dari pelamar)';
    }

    $row = static function (string $label, string $value) {
        return '<div class="job-review-row"><span>' . e($label) . '</span><strong>' . $value . '</strong></div>';
    };

    $text = static function (mixed $value, string $fallback = '-') {
        $value = trim((string) $value);
        return e($value !== '' ? $value : $fallback);
    };

    $list = static function (array $items, string $fallback = '-') {
        $items = array_values(array_filter(array_map(static fn($item) => trim((string) $item), $items)));
        return e($items ? implode(', ', $items) : $fallback);
    };

    $description = job_rich_html($data['description']);
    $special = job_rich_html($data['special_requirements']);
    $employerPhone = trim((string) (($job['employer_whatsapp'] ?? '') ?: ($job['employer_phone'] ?? '')));

    $html = '<section class="job-review-section"><h3>Informasi Lowongan</h3>';
    $html .= $row('Judul jabatan', $text($data['title']));
    $html .= $row('Jenis pekerjaan', $text($data['job_type']));
    $html .= $row('Bidang pekerjaan', $text($data['job_field']));
    $html .= $row('Industri / sektor', $text($data['industry']));
    $html .= $row('Kode KBJI', $text($kbjiText));
    $html .= $row('Lokasi', $text($data['location']));
    $html .= $row('Remote working', e(job_yes_no(!empty($data['is_remote']))));
    $html .= $row('Terbatas', e(job_yes_no(!empty($data['is_limited']))));
    $html .= $row('Durasi tayang', $data['expiry_days'] !== '' ? e($data['expiry_days'] . ' hari') : e('-'));
    $html .= $row('Kuota', e((string) $data['quota'] . ' orang'));
    $html .= $row('Gaji', e($salary));
    $html .= '</section>';

    $html .= '<section class="job-review-section"><h3>Deskripsi Pekerjaan</h3>';
    $html .= $description !== '' ? '<div class="job-review-prose">' . $description . '</div>' : '<p class="job-review-empty">Tidak ada deskripsi.</p>';
    $html .= '</section>';

    $html .= '<section class="job-review-section"><h3>Kualifikasi &amp; Persyaratan</h3>';
    $html .= $row('Pendidikan minimal', $text($data['education_required']));
    $html .= $row('Pengalaman', $text($data['experience_required']));
    $html .= $row('Status pernikahan', $list($data['marital_statuses']));
    $html .= $row('Usia', e(trim(($data['age_min'] !== '' ? $data['age_min'] . ' th' : '-') . ' – ' . ($data['age_max'] !== '' ? $data['age_max'] . ' th' : '-'))));
    $html .= $row('Kondisi fisik', $list($data['physical_conditions']));
    $html .= $row('Jenis kelamin', $list($data['genders']));
    $html .= $row('Disabilitas tidak diperbolehkan', $text($data['disability_excluded'], 'Tidak ada batasan'));
    $html .= '</section>';

    $html .= '<section class="job-review-section"><h3>Persyaratan Khusus</h3>';
    $html .= $special !== '' ? '<div class="job-review-prose">' . $special . '</div>' : '<p class="job-review-empty">Tidak ada persyaratan khusus.</p>';
    $html .= '</section>';

    $html .= '<section class="job-review-section"><h3>Keahlian &amp; Kontak</h3>';
    $html .= $row('Skill / keahlian', $list($data['skills']));
    $html .= $row('Kontak lamaran', $list($data['contacts']));
    $html .= '</section>';

    $html .= '<section class="job-review-section"><h3>Pemberi Kerja</h3>';
    $html .= $row('Nama', $text($job['employer_name'] ?? '-'));
    $html .= $row('Email', $text($job['employer_email'] ?? '-'));
    $html .= $row('Telepon / WA', $text($employerPhone, '-'));
    $html .= $row('Profesi', $text($job['employer_profession'] ?? '-'));
    $html .= $row('Dibuat', e(date('d M Y H:i', strtotime((string) ($job['created_at'] ?? 'now')))));
    $html .= '</section>';

    return $html;
}

function parse_job_details(?string $json): array
{
    $decoded = json_decode((string) $json, true);
    return is_array($decoded) ? $decoded : [];
}

function job_decision_editable(array $job): bool
{
    $status = (string) ($job['status'] ?? '');
    if (!in_array($status, ['Menunggu Verifikasi', 'Perlu Revisi', 'Tayang', 'Ditolak'], true)) {
        return false;
    }

    // After the employer opens and works on the revision form, the saved
    // decision can no longer be changed until they resubmit.
    if ($status === 'Perlu Revisi' && !empty($job['revision_opened_at'])) {
        return false;
    }

    return true;
}

function job_to_form_data(array $job): array
{
    $details = parse_job_details($job['details'] ?? null);

    return [
        'id' => (int) ($job['id'] ?? 0),
        'title' => (string) ($job['title'] ?? ''),
        'description' => (string) ($job['description'] ?? ''),
        'location' => (string) ($job['location'] ?? ''),
        'job_type' => (string) ($job['job_type'] ?? ''),
        'industry' => (string) ($job['industry'] ?? ''),
        'salary_min' => $job['salary_min'] ?? null,
        'salary_max' => $job['salary_max'] ?? null,
        'quota' => (int) ($job['quota'] ?? 1),
        'kbji_code' => (string) ($job['kbji_code'] ?? ''),
        'admin_notes' => (string) ($job['admin_notes'] ?? ''),
        'job_field' => (string) ($details['job_field'] ?? ''),
        'physical_conditions' => array_values((array) ($details['physical_conditions'] ?? ['Disabilitas', 'Non Disabilitas'])),
        'genders' => array_values((array) ($details['genders'] ?? ['Laki-laki', 'Perempuan'])),
        'disability_excluded' => (string) ($details['disability_excluded'] ?? ''),
        'show_salary' => !empty($details['show_salary']),
        'is_remote' => !empty($details['is_remote']),
        'is_limited' => !empty($details['is_limited']),
        'expiry_days' => ((int) ($details['expiry_days'] ?? 0)) > 0 ? (int) $details['expiry_days'] : '',
        'education_required' => (string) ($details['education_required'] ?? ''),
        'experience_required' => (string) ($details['experience_required'] ?? ''),
        'marital_statuses' => array_values((array) ($details['marital_statuses'] ?? ['Telah Menikah', 'Lajang / Belum Menikah'])),
        'age_min' => $details['age_min'] ?? '',
        'age_max' => $details['age_max'] ?? '',
        'special_requirements' => (string) ($details['special_requirements'] ?? ''),
        'skills' => array_values((array) ($details['skills'] ?? [])),
        'contacts' => array_values((array) ($details['contacts'] ?? [])),
    ];
}

function render_notif_dropdown(array $notifications, int $unread): string
{
    $items = '';
    if (!$notifications) {
        $items = '<div class="notif-empty">Belum ada notifikasi.</div>';
    } else {
        foreach ($notifications as $row) {
            $unreadClass = empty($row['is_read']) ? ' unread' : '';
            $items .= '<div class="notif-item' . $unreadClass . '">';
            $items .= '<strong>' . e($row['title']) . '</strong>';
            $items .= '<p>' . e($row['message']) . '</p>';
            $items .= '<span>' . e(date('d M Y H:i', strtotime((string) $row['created_at']))) . '</span>';
            $items .= '</div>';
        }
    }

    $dot = $unread > 0 ? ' has-unread' : '';
    return '<div class="notif-wrap">'
        . '<button type="button" class="notif' . $dot . '" data-notif-toggle aria-label="Notifikasi"><i class="fa-regular fa-bell"></i></button>'
        . '<div class="notif-panel" hidden><div class="notif-head">Notifikasi</div>' . $items . '</div>'
        . '</div>';
}

function application_statuses(): array
{
    return [
        'Lamaran Masuk',
        'Sedang Dipelajari',
        'Wawancara',
        'Diterima',
        'Ditolak',
    ];
}

function normalize_application_status(?string $status): string
{
    $status = trim((string) $status);
    return match ($status) {
        'Dilamar', 'Applied', '' => 'Lamaran Masuk',
        'Dipelajari' => 'Sedang Dipelajari',
        default => in_array($status, application_statuses(), true) ? $status : 'Lamaran Masuk',
    };
}

function application_status_meta(string $status): array
{
    $status = normalize_application_status($status);
    return match ($status) {
        'Lamaran Masuk' => ['label' => $status, 'class' => 'in'],
        'Sedang Dipelajari' => ['label' => $status, 'class' => 'review'],
        'Wawancara' => ['label' => $status, 'class' => 'interview'],
        'Diterima' => ['label' => $status, 'class' => 'hired'],
        'Ditolak' => ['label' => $status, 'class' => 'rejected'],
        default => ['label' => $status, 'class' => 'in'],
    };
}

function employer_applicants(int $employerId): array
{
    $statement = db()->prepare('SELECT a.*, j.title AS job_title, u.name AS seeker_name, u.email AS seeker_email,
            sp.nik, sp.phone, sp.gender, sp.marital_status, sp.birth_place, sp.birth_date, sp.ktp_address, sp.domicile_address
        FROM job_applications a
        JOIN job_posts j ON j.id = a.job_id
        JOIN users u ON u.id = a.seeker_id
        LEFT JOIN seeker_profiles sp ON sp.user_id = a.seeker_id
        WHERE j.user_id = ?
        ORDER BY a.created_at DESC');
    $statement->execute([$employerId]);
    $rows = $statement->fetchAll() ?: [];
    foreach ($rows as &$row) {
        $row['status'] = normalize_application_status($row['status'] ?? '');
    }
    return $rows;
}

function seeker_profile_bundle(int $seekerId): array
{
    $tables = [
        'education' => 'seeker_educations',
        'experience' => 'seeker_experiences',
        'skills' => 'seeker_skills',
        'languages' => 'seeker_languages',
        'trainings' => 'seeker_trainings',
    ];
    $bundle = [];
    foreach ($tables as $key => $table) {
        $statement = db()->prepare('SELECT * FROM ' . $table . ' WHERE user_id = ? ORDER BY id DESC');
        $statement->execute([$seekerId]);
        $bundle[$key] = $statement->fetchAll() ?: [];
    }
    return $bundle;
}

function profession_options(): array
{
    return [
        'Kuliner',
        'Jasa',
        'Teknologi Informasi',
        'Perdagangan',
        'Pertanian',
        'Konstruksi',
        'Kesehatan',
        'Pendidikan',
        'Transportasi & Logistik',
        'Hiburan & Kreatif',
        'Keuangan',
        'Lainnya',
    ];
}
