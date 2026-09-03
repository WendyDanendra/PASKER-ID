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
    <title>Profil Pencari Kerja</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
    <style>
        .setup-shell {
            min-height: 100vh;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 18px;
        }
        .setup-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .setup-title h1 {
            font-size: 30px;
            margin-bottom: 6px;
        }
        .setup-title p {
            color: #64748b;
            font-size: 14px;
        }
        .setup-card {
            background: rgba(255,255,255,0.98);
            border: 1px solid #e8ebf2;
            border-radius: 22px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }
        .setup-card-header {
            padding: 18px 20px;
            border-bottom: 1px solid #eef2f7;
            background: linear-gradient(180deg, #ffffff, #fbfdff);
        }
        .setup-card-body {
            padding: 20px;
        }
        .setup-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .setup-grid .span-2 {
            grid-column: 1 / -1;
        }
        .setup-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 18px 20px 20px;
            border-top: 1px solid #eef2f7;
        }
        .section-block {
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px solid #eef2f7;
        }
        .section-block h3 {
            font-size: 15px;
            margin-bottom: 6px;
        }
        .section-block p {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 14px;
        }
        .hint {
            font-size: 12px;
            color: #64748b;
            margin-top: 6px;
        }
        @media (max-width: 900px) {
            .setup-grid {
                grid-template-columns: 1fr;
            }
            .setup-shell {
                padding: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="setup-shell">
        <div class="setup-topbar">
            <div class="setup-title">
                <h1>Lengkapi Profil Pencari Kerja</h1>
                <p>Isi biodata, pengalaman, pelatihan, pendidikan, keahlian, dan bahasa.</p>
            </div>
            <a class="link-btn" href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Keluar</a>
        </div>

        <?php if ($flash = get_flash()): ?>
            <div class="alert-box <?php echo $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>"><?php echo e($flash['message']); ?></div>
        <?php endif; ?>

        <div class="setup-card">
            <div class="setup-card-header">
                <div class="chip-inline"><i class="fa-solid fa-id-card"></i> Biodata pengguna</div>
            </div>
            <form method="post">
                <div class="setup-card-body">
                    <div class="setup-grid">
                        <div class="field"><label>Nomor Induk Kependudukan</label><input type="text" name="nik" value="<?php echo e($profile['nik'] ?? ''); ?>" required></div>
                        <div class="field"><label>Tempat, tanggal lahir</label><input type="text" name="birth_place" value="<?php echo e($profile['birth_place'] ?? ''); ?>" placeholder="Tempat lahir" required></div>
                        <div class="field"><label>Tanggal lahir</label><input type="date" name="birth_date" value="<?php echo e($profile['birth_date'] ?? ''); ?>" required></div>
                        <div class="field"><label>Jenis kelamin</label><select name="gender" required><option value="">Pilih</option><option value="Laki-laki" <?php echo (($profile['gender'] ?? '') === 'Laki-laki') ? 'selected' : ''; ?>>Laki-laki</option><option value="Perempuan" <?php echo (($profile['gender'] ?? '') === 'Perempuan') ? 'selected' : ''; ?>>Perempuan</option></select></div>
                        <div class="field"><label>Status kawin / belum kawin</label><select name="marital_status" required><option value="">Pilih</option><option value="Kawin" <?php echo (($profile['marital_status'] ?? '') === 'Kawin') ? 'selected' : ''; ?>>Kawin</option><option value="Belum Kawin" <?php echo (($profile['marital_status'] ?? '') === 'Belum Kawin') ? 'selected' : ''; ?>>Belum Kawin</option></select></div>
                        <div class="field"><label>Nomor telepon</label><input type="text" name="phone" value="<?php echo e($profile['phone'] ?? ''); ?>" required></div>
                        <div class="field span-2"><label>Alamat sesuai KTP</label><textarea name="ktp_address" required><?php echo e($profile['ktp_address'] ?? ''); ?></textarea></div>
                        <div class="field span-2"><label>Alamat domisili</label><textarea name="domicile_address" required><?php echo e($profile['domicile_address'] ?? ''); ?></textarea></div>
                    </div>

                    <div class="section-block">
                        <h3>Pengalaman Kerja</h3>
                        <p>Format 1 baris = <strong>Perusahaan | Posisi | Durasi | Catatan</strong></p>
                        <div class="field span-2"><textarea name="experience_input" placeholder="PT Contoh | Admin | 2022-2024 | Mengelola administrasi"><?php echo e($experienceText); ?></textarea></div>
                    </div>

                    <div class="section-block">
                        <h3>Pelatihan</h3>
                        <p>Format 1 baris = <strong>Nama pelatihan | Penyelenggara | Tahun | Sertifikat</strong></p>
                        <div class="field span-2"><textarea name="training_input" placeholder="Food Handling | BLK | 2024 | Ada"><?php echo e($trainingText); ?></textarea></div>
                    </div>

                    <div class="section-block">
                        <h3>Pendidikan</h3>
                        <p>Format 1 baris = <strong>Level | Nama sekolah | Jurusan | Tahun lulus</strong></p>
                        <div class="field span-2"><textarea name="education_input" placeholder="SMA | SMA Negeri 1 | IPS | 2020"><?php echo e($educationText); ?></textarea></div>
                    </div>

                    <div class="section-block">
                        <h3>Keahlian</h3>
                        <p>Format 1 baris = <strong>Nama keahlian | Level</strong></p>
                        <div class="field span-2"><textarea name="skills_input" placeholder="Excel | Menengah"><?php echo e($skillsText); ?></textarea></div>
                    </div>

                    <div class="section-block">
                        <h3>Bahasa</h3>
                        <p>Format 1 baris = <strong>Nama bahasa | Level</strong></p>
                        <div class="field span-2"><textarea name="languages_input" placeholder="Indonesia | Lancar"><?php echo e($languagesText); ?></textarea></div>
                    </div>

                    <div class="hint">Gunakan tanda <strong>|</strong> untuk memisahkan isi di tiap baris. Kamu juga bisa isi satu baris dulu untuk demo.</div>
                </div>
                <div class="setup-actions">
                    <a class="ghost-btn" href="logout.php">Batal</a>
                    <button class="primary-btn" type="submit">Simpan & Masuk Dashboard</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
