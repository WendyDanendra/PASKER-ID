<?php
require __DIR__ . '/includes/bootstrap.php';

$user = require_role('seeker');

$statement = db()->prepare('SELECT * FROM seeker_profiles WHERE user_id = ? LIMIT 1');
$statement->execute([$user['id']]);
$profile = $statement->fetch() ?: [];

function parse_lines(string $text): array
{
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    $items = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $items[] = $line;
        }
    }

    return $items;
}

function load_records(string $table, int $userId): array
{
    $statement = db()->prepare('SELECT * FROM ' . $table . ' WHERE user_id = ? ORDER BY id ASC');
    $statement->execute([$userId]);

    return $statement->fetchAll();
}

function join_records(array $records, callable $formatter): string
{
    $lines = [];

    foreach ($records as $record) {
        $lines[] = $formatter($record);
    }

    return implode("\n", $lines);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nik = trim($_POST['nik'] ?? '');
    $birthPlace = trim($_POST['birth_place'] ?? '');
    $birthDate = trim($_POST['birth_date'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $maritalStatus = $_POST['marital_status'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $ktpAddress = trim($_POST['ktp_address'] ?? '');
    $domicileAddress = trim($_POST['domicile_address'] ?? '');

    $experienceInput = trim($_POST['experience_input'] ?? '');
    $trainingInput = trim($_POST['training_input'] ?? '');
    $educationInput = trim($_POST['education_input'] ?? '');
    $skillsInput = trim($_POST['skills_input'] ?? '');
    $languagesInput = trim($_POST['languages_input'] ?? '');

    if ($nik === '' || $birthPlace === '' || $birthDate === '' || $gender === '' || $maritalStatus === '' || $phone === '' || $ktpAddress === '' || $domicileAddress === '') {
        flash('error', 'Lengkapi biodata pengguna terlebih dahulu.');
        redirect('profile-seeker.php');
    }

    if ($profile) {
        $update = db()->prepare('UPDATE seeker_profiles SET nik = ?, birth_place = ?, birth_date = ?, gender = ?, marital_status = ?, phone = ?, ktp_address = ?, domicile_address = ?, updated_at = NOW() WHERE user_id = ?');
        $update->execute([$nik, $birthPlace, $birthDate, $gender, $maritalStatus, $phone, $ktpAddress, $domicileAddress, $user['id']]);
    } else {
        $insert = db()->prepare('INSERT INTO seeker_profiles (user_id, nik, birth_place, birth_date, gender, marital_status, phone, ktp_address, domicile_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$user['id'], $nik, $birthPlace, $birthDate, $gender, $maritalStatus, $phone, $ktpAddress, $domicileAddress]);
    }

    db()->prepare('DELETE FROM seeker_experiences WHERE user_id = ?')->execute([$user['id']]);
    db()->prepare('DELETE FROM seeker_trainings WHERE user_id = ?')->execute([$user['id']]);
    db()->prepare('DELETE FROM seeker_educations WHERE user_id = ?')->execute([$user['id']]);
    db()->prepare('DELETE FROM seeker_skills WHERE user_id = ?')->execute([$user['id']]);
    db()->prepare('DELETE FROM seeker_languages WHERE user_id = ?')->execute([$user['id']]);

    foreach (parse_lines($experienceInput) as $line) {
        $parts = array_map('trim', explode('|', $line));
        $company = $parts[0] ?? '';
        $position = $parts[1] ?? '';
        $duration = $parts[2] ?? '';
        $notes = $parts[3] ?? '';

        if ($company !== '' && $position !== '' && $duration !== '') {
            $insert = db()->prepare('INSERT INTO seeker_experiences (user_id, company_name, position, duration, notes) VALUES (?, ?, ?, ?, ?)');
            $insert->execute([$user['id'], $company, $position, $duration, $notes]);
        }
    }

    foreach (parse_lines($trainingInput) as $line) {
        $parts = array_map('trim', explode('|', $line));
        $training = $parts[0] ?? '';
        $organizer = $parts[1] ?? '';
        $year = $parts[2] ?? '';
        $certificate = $parts[3] ?? '';

        if ($training !== '') {
            $insert = db()->prepare('INSERT INTO seeker_trainings (user_id, training_name, organizer, year, certificate) VALUES (?, ?, ?, ?, ?)');
            $insert->execute([$user['id'], $training, $organizer, $year, $certificate]);
        }
    }

    foreach (parse_lines($educationInput) as $line) {
        $parts = array_map('trim', explode('|', $line));
        $level = $parts[0] ?? '';
        $school = $parts[1] ?? '';
        $major = $parts[2] ?? '';
        $year = $parts[3] ?? '';

        if ($level !== '' && $school !== '') {
            $insert = db()->prepare('INSERT INTO seeker_educations (user_id, level, school_name, major, graduation_year) VALUES (?, ?, ?, ?, ?)');
            $insert->execute([$user['id'], $level, $school, $major, $year]);
        }
    }

    foreach (parse_lines($skillsInput) as $line) {
        $parts = array_map('trim', explode('|', $line));
        $skill = $parts[0] ?? '';
        $level = $parts[1] ?? '';

        if ($skill !== '') {
            $insert = db()->prepare('INSERT INTO seeker_skills (user_id, skill_name, level) VALUES (?, ?, ?)');
            $insert->execute([$user['id'], $skill, $level]);
        }
    }

    foreach (parse_lines($languagesInput) as $line) {
        $parts = array_map('trim', explode('|', $line));
        $language = $parts[0] ?? '';
        $proficiency = $parts[1] ?? '';

        if ($language !== '') {
            $insert = db()->prepare('INSERT INTO seeker_languages (user_id, language_name, proficiency) VALUES (?, ?, ?)');
            $insert->execute([$user['id'], $language, $proficiency]);
        }
    }

    db()->prepare('UPDATE users SET profile_complete = 1 WHERE id = ?')->execute([$user['id']]);
    flash('success', 'Profil pencari kerja berhasil disimpan.');
    redirect('seeker.php');
}

$experiences = load_records('seeker_experiences', $user['id']);
$trainings = load_records('seeker_trainings', $user['id']);
$educations = load_records('seeker_educations', $user['id']);
$skills = load_records('seeker_skills', $user['id']);
$languages = load_records('seeker_languages', $user['id']);

$experienceText = join_records($experiences, fn($row) => implode(' | ', [$row['company_name'], $row['position'], $row['duration'], $row['notes'] ?? '']));
$trainingText = join_records($trainings, fn($row) => implode(' | ', [$row['training_name'], $row['organizer'], $row['year'], $row['certificate']]));
$educationText = join_records($educations, fn($row) => implode(' | ', [$row['level'], $row['school_name'], $row['major'], $row['graduation_year']]));
$skillsText = join_records($skills, fn($row) => implode(' | ', [$row['skill_name'], $row['level']]));
$languagesText = join_records($languages, fn($row) => implode(' | ', [$row['language_name'], $row['proficiency']]));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Pencari Kerja - Karirhub</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css?v=seeker-prof-1">
    <style>
        body {
            background-color: #f1f5f9;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            color: #1e293b;
            margin: 0;
            padding: 0;
        }
        .profile-page-shell {
            max-width: 960px;
            margin: 0 auto;
            padding: 32px 20px 60px;
        }
        .profile-page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .profile-page-header .brand-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .brand-icon-box {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #0284c7, #0369a1);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 20px;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);
        }
        .profile-page-header h1 {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 4px;
            letter-spacing: -0.02em;
        }
        .profile-page-header p {
            font-size: 13px;
            color: #64748b;
            margin: 0;
        }
        .back-btn-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            color: #475569;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }
        .back-btn-pill:hover {
            border-color: #cbd5e1;
            color: #0284c7;
            background: #f8fafc;
        }
        .profile-form-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.04);
            overflow: hidden;
        }
        .form-section {
            padding: 24px;
            border-bottom: 1px solid #f1f5f9;
        }
        .form-section:last-of-type {
            border-bottom: none;
        }
        .section-heading {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
        }
        .section-heading i {
            color: #0284c7;
            font-size: 16px;
        }
        .section-heading h2 {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }
        .section-heading span {
            font-size: 12px;
            color: #64748b;
            font-weight: 400;
            margin-left: auto;
        }
        .field-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
        .field-grid .col-full {
            grid-column: 1 / -1;
        }
        .custom-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .custom-field label {
            font-size: 12px;
            font-weight: 600;
            color: #334155;
        }
        .custom-field label .req {
            color: #ef4444;
            margin-left: 2px;
        }
        .custom-field input[type="text"],
        .custom-field input[type="date"],
        .custom-field select,
        .custom-field textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 13px;
            color: #0f172a;
            background: #ffffff;
            transition: all 0.2s;
            box-sizing: border-box;
            font-family: inherit;
        }
        .custom-field textarea {
            min-height: 80px;
            resize: vertical;
            line-height: 1.5;
        }
        .custom-field input:focus,
        .custom-field select:focus,
        .custom-field textarea:focus {
            outline: none;
            border-color: #0284c7;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.12);
        }
        .field-help-box {
            background: #f8fafc;
            border-left: 3px solid #0284c7;
            padding: 10px 14px;
            border-radius: 0 8px 8px 0;
            margin-bottom: 12px;
            font-size: 12px;
            color: #475569;
            line-height: 1.5;
        }
        .field-help-box strong {
            color: #0f172a;
        }
        .form-footer-actions {
            padding: 20px 24px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
        }
        .save-submit-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 24px;
            background: #0284c7;
            color: #ffffff;
            font-weight: 700;
            font-size: 13px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 2px 8px rgba(2, 132, 199, 0.25);
        }
        .save-submit-btn:hover {
            background: #0369a1;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.35);
        }
        .cancel-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            background: transparent;
            color: #64748b;
            font-weight: 600;
            font-size: 13px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            text-decoration: none;
            transition: all 0.2s;
        }
        .cancel-btn:hover {
            background: #e2e8f0;
            color: #334155;
        }
        @media (max-width: 768px) {
            .field-grid {
                grid-template-columns: 1fr;
            }
            .profile-page-shell {
                padding: 16px 12px 40px;
            }
        }
    </style>
</head>
<body>
    <div class="profile-page-shell">
        <div class="profile-page-header">
            <div class="brand-title">
                <div class="brand-icon-box">
                    <i class="fa-solid fa-user-pen"></i>
                </div>
                <div>
                    <h1>Lengkapi Profil Pencari Kerja</h1>
                    <p>Kelola data biodata, pengalaman kerja, pendidikan, dan keahlian untuk melamar lowongan.</p>
                </div>
            </div>
            <a class="back-btn-pill" href="seeker.php">
                <i class="fa-solid fa-arrow-left"></i> Kembali ke Dasbor
            </a>
        </div>

        <?php if ($flash = get_flash()): ?>
            <div class="alert-box <?php echo $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>" style="margin-bottom: 20px;">
                <i class="fa-solid <?php echo $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <?php echo e($flash['message']); ?>
            </div>
        <?php endif; ?>

        <div class="profile-form-card">
            <form method="post">
                <!-- 1. BIODATA PENGGUNA -->
                <div class="form-section">
                    <div class="section-heading">
                        <i class="fa-solid fa-id-card"></i>
                        <h2>1. Biodata Pribadi</h2>
                        <span>Wajib diisi</span>
                    </div>

                    <div class="field-grid">
                        <div class="custom-field">
                            <label>Nomor Induk Kependudukan (NIK)<span class="req">*</span></label>
                            <input type="text" name="nik" value="<?php echo e($profile['nik'] ?? ''); ?>" placeholder="16 digit NIK sesuai KTP" required>
                        </div>
                        <div class="custom-field">
                            <label>Nomor Telepon / WhatsApp<span class="req">*</span></label>
                            <input type="text" name="phone" value="<?php echo e($profile['phone'] ?? ''); ?>" placeholder="Contoh: 081234567890" required>
                        </div>
                        <div class="custom-field">
                            <label>Tempat Lahir<span class="req">*</span></label>
                            <input type="text" name="birth_place" value="<?php echo e($profile['birth_place'] ?? ''); ?>" placeholder="Kota tempat lahir" required>
                        </div>
                        <div class="custom-field">
                            <label>Tanggal Lahir<span class="req">*</span></label>
                            <input type="date" name="birth_date" value="<?php echo e($profile['birth_date'] ?? ''); ?>" required>
                        </div>
                        <div class="custom-field">
                            <label>Jenis Kelamin<span class="req">*</span></label>
                            <select name="gender" required>
                                <option value="">Pilih Jenis Kelamin</option>
                                <option value="Laki-laki" <?php echo (($profile['gender'] ?? '') === 'Laki-laki') ? 'selected' : ''; ?>>Laki-laki</option>
                                <option value="Perempuan" <?php echo (($profile['gender'] ?? '') === 'Perempuan') ? 'selected' : ''; ?>>Perempuan</option>
                            </select>
                        </div>
                        <div class="custom-field">
                            <label>Status Pernikahan<span class="req">*</span></label>
                            <select name="marital_status" required>
                                <option value="">Pilih Status</option>
                                <option value="Belum Kawin" <?php echo (($profile['marital_status'] ?? '') === 'Belum Kawin') ? 'selected' : ''; ?>>Belum Kawin</option>
                                <option value="Kawin" <?php echo (($profile['marital_status'] ?? '') === 'Kawin') ? 'selected' : ''; ?>>Kawin</option>
                            </select>
                        </div>
                        <div class="custom-field col-full">
                            <label>Alamat Sesuai KTP<span class="req">*</span></label>
                            <textarea name="ktp_address" placeholder="Tuliskan alamat lengkap sesuai data di KTP..." required><?php echo e($profile['ktp_address'] ?? ''); ?></textarea>
                        </div>
                        <div class="custom-field col-full">
                            <label>Alamat Domisili Sekarang<span class="req">*</span></label>
                            <textarea name="domicile_address" placeholder="Tuliskan alamat tempat tinggal saat ini..." required><?php echo e($profile['domicile_address'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- 2. PENGALAMAN KERJA -->
                <div class="form-section">
                    <div class="section-heading">
                        <i class="fa-solid fa-briefcase"></i>
                        <h2>2. Pengalaman Kerja</h2>
                    </div>
                    <div class="field-help-box">
                        Format pengisian per baris: <strong>Nama Perusahaan | Posisi / Jabatan | Durasi Waktu | Keterangan Tambahan</strong><br>
                        <em>Contoh: PT Sumber Rejeki | Kasir | 2022-2024 | Mengelola transaksi harian</em>
                    </div>
                    <div class="custom-field">
                        <textarea name="experience_input" rows="3" placeholder="Nama Perusahaan | Posisi | Durasi | Catatan..."><?php echo e($experienceText); ?></textarea>
                    </div>
                </div>

                <!-- 3. PELATIHAN & SERTIFIKASI -->
                <div class="form-section">
                    <div class="section-heading">
                        <i class="fa-solid fa-certificate"></i>
                        <h2>3. Riwayat Pelatihan & Sertifikasi</h2>
                    </div>
                    <div class="field-help-box">
                        Format pengisian per baris: <strong>Nama Pelatihan | Penyelenggara | Tahun | Keterangan Sertifikat</strong><br>
                        <em>Contoh: Pelatihan Barista | BLK Kemnaker | 2024 | Bersertifikat BNSP</em>
                    </div>
                    <div class="custom-field">
                        <textarea name="training_input" rows="3" placeholder="Nama Pelatihan | Penyelenggara | Tahun | Sertifikat..."><?php echo e($trainingText); ?></textarea>
                    </div>
                </div>

                <!-- 4. PENDIDIKAN -->
                <div class="form-section">
                    <div class="section-heading">
                        <i class="fa-solid fa-graduation-cap"></i>
                        <h2>4. Riwayat Pendidikan</h2>
                    </div>
                    <div class="field-help-box">
                        Format pengisian per baris: <strong>Jenjang | Nama Institusi / Sekolah | Jurusan | Tahun Lulus</strong><br>
                        <em>Contoh: SMA/SMK | SMK Negeri 1 Jakarta | Tata Boga | 2021</em>
                    </div>
                    <div class="custom-field">
                        <textarea name="education_input" rows="3" placeholder="Jenjang | Nama Sekolah | Jurusan | Tahun Lulus..."><?php echo e($educationText); ?></textarea>
                    </div>
                </div>

                <!-- 5. KEAHLIAN -->
                <div class="form-section">
                    <div class="section-heading">
                        <i class="fa-solid fa-star"></i>
                        <h2>5. Keterampilan & Keahlian</h2>
                    </div>
                    <div class="field-help-box">
                        Format pengisian per baris: <strong>Nama Keahlian | Tingkat Penguasaan</strong><br>
                        <em>Contoh: Microsoft Office | Mahir</em>
                    </div>
                    <div class="custom-field">
                        <textarea name="skills_input" rows="3" placeholder="Nama Keahlian | Level (Dasar / Menengah / Mahir)..."><?php echo e($skillsText); ?></textarea>
                    </div>
                </div>

                <!-- 6. BAHASA -->
                <div class="form-section">
                    <div class="section-heading">
                        <i class="fa-solid fa-language"></i>
                        <h2>6. Penguasaan Bahasa</h2>
                    </div>
                    <div class="field-help-box">
                        Format pengisian per baris: <strong>Bahasa | Tingkat Kefasihan</strong><br>
                        <em>Contoh: Bahasa Inggris | Menengah (Percakapan Kerja)</em>
                    </div>
                    <div class="custom-field">
                        <textarea name="languages_input" rows="3" placeholder="Bahasa | Tingkat (Dasar / Lancar / Fasih)..."><?php echo e($languagesText); ?></textarea>
                    </div>
                </div>

                <div class="form-footer-actions">
                    <a class="cancel-btn" href="seeker.php">Batal</a>
                    <button class="save-submit-btn" type="submit">
                        <i class="fa-solid fa-floppy-disk"></i> Simpan & Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
