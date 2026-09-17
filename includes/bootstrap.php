<?php
session_start();

define('APP_NAME', 'Karirhub');
define('APP_URL', '');

define('DB_HOST', 'localhost');
define('DB_NAME', 'paskerid');
define('DB_USER', 'root');
define('DB_PASS', '');

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            ensure_database_schema($pdo);
            ensure_platform_schema();
        } catch (PDOException $e) {
            error_log('[Karirhub DB] MySQL connection failed: ' . $e->getMessage());

            // Fallback to SQLite database file for guaranteed demo uptime
            $sqlitePath = __DIR__ . '/../database/demo.sqlite';
            $isNew = !file_exists($sqlitePath);
            $pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            if ($isNew) {
                init_sqlite_schema($pdo);
            }
            ensure_sqlite_extra_tables($pdo);
        }
    }

    return $pdo;
}

function ensure_sqlite_extra_tables(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        message TEXT NOT NULL,
        type TEXT NOT NULL,
        job_id INTEGER,
        is_read INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS job_applications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_id INTEGER NOT NULL,
        seeker_id INTEGER NOT NULL,
        status TEXT DEFAULT "Lamaran Masuk",
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME,
        UNIQUE (job_id, seeker_id)
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS job_verifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        kbji_code TEXT NOT NULL,
        status TEXT DEFAULT "PENDING",
        verifier_notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        entity_type TEXT NOT NULL,
        entity_id INTEGER NOT NULL,
        actor_name TEXT NOT NULL,
        actor_role TEXT NOT NULL,
        action TEXT NOT NULL,
        details TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');

    try {
        $cols = array_column($pdo->query('PRAGMA table_info(employer_profiles)')->fetchAll(), 'name');
        if (!in_array('workplace_photo', $cols, true)) {
            $pdo->exec('ALTER TABLE employer_profiles ADD COLUMN workplace_photo TEXT');
        }
        if (!in_array('permit_document', $cols, true)) {
            $pdo->exec('ALTER TABLE employer_profiles ADD COLUMN permit_document TEXT');
        }
        if (!in_array('assigned_to', $cols, true)) {
            $pdo->exec('ALTER TABLE employer_profiles ADD COLUMN assigned_to TEXT');
        }
        if (!in_array('assigned_at', $cols, true)) {
            $pdo->exec('ALTER TABLE employer_profiles ADD COLUMN assigned_at DATETIME');
        }
        if (!in_array('assignment_reason', $cols, true)) {
            $pdo->exec('ALTER TABLE employer_profiles ADD COLUMN assignment_reason TEXT');
        }
        if (!in_array('rejection_count', $cols, true)) {
            $pdo->exec('ALTER TABLE employer_profiles ADD COLUMN rejection_count INTEGER DEFAULT 0');
        }
        if (!in_array('manual_review_status', $cols, true)) {
            $pdo->exec('ALTER TABLE employer_profiles ADD COLUMN manual_review_status TEXT DEFAULT "NONE"');
        }
        if (!in_array('consent_data_hash', $cols, true)) {
            $pdo->exec('ALTER TABLE employer_profiles ADD COLUMN consent_data_hash TEXT');
        }
        if (!in_array('consent_given_at', $cols, true)) {
            $pdo->exec('ALTER TABLE employer_profiles ADD COLUMN consent_given_at DATETIME');
        }
        if (!in_array('officer_statement', $cols, true)) {
            $pdo->exec('ALTER TABLE employer_profiles ADD COLUMN officer_statement TEXT');
        }
        if (!in_array('officer_name', $cols, true)) {
            $pdo->exec('ALTER TABLE employer_profiles ADD COLUMN officer_name TEXT');
        }
        if (!in_array('entity_type', $cols, true)) {
            $pdo->exec('ALTER TABLE employer_profiles ADD COLUMN entity_type TEXT DEFAULT "Individu"');
        }
        if (!in_array('consent_agreed', $cols, true)) {
            $pdo->exec('ALTER TABLE employer_profiles ADD COLUMN consent_agreed INTEGER DEFAULT 0');
        }
    } catch (Throwable $ignored) {}

    try {
        $cols = array_column($pdo->query('PRAGMA table_info(job_posts)')->fetchAll(), 'name');
        if (!in_array('admin_notes', $cols, true)) {
            $pdo->exec('ALTER TABLE job_posts ADD COLUMN admin_notes TEXT');
        }
        if (!in_array('details', $cols, true)) {
            $pdo->exec('ALTER TABLE job_posts ADD COLUMN details TEXT');
        }
        if (!in_array('min_education', $cols, true)) {
            $pdo->exec('ALTER TABLE job_posts ADD COLUMN min_education TEXT');
        }
        if (!in_array('min_experience', $cols, true)) {
            $pdo->exec('ALTER TABLE job_posts ADD COLUMN min_experience TEXT');
        }
        if (!in_array('parent_job_id', $cols, true)) {
            $pdo->exec('ALTER TABLE job_posts ADD COLUMN parent_job_id INTEGER');
        }
        if (!in_array('published_at', $cols, true)) {
            $pdo->exec('ALTER TABLE job_posts ADD COLUMN published_at DATETIME');
        }
        if (!in_array('unfulfilled_reason', $cols, true)) {
            $pdo->exec('ALTER TABLE job_posts ADD COLUMN unfulfilled_reason TEXT');
        }
        if (!in_array('additional_doc_required', $cols, true)) {
            $pdo->exec('ALTER TABLE job_posts ADD COLUMN additional_doc_required INTEGER DEFAULT 0');
        }
        if (!in_array('assigned_to', $cols, true)) {
            $pdo->exec('ALTER TABLE job_posts ADD COLUMN assigned_to TEXT');
        }
        if (!in_array('assigned_at', $cols, true)) {
            $pdo->exec('ALTER TABLE job_posts ADD COLUMN assigned_at DATETIME');
        }
        if (!in_array('assignment_reason', $cols, true)) {
            $pdo->exec('ALTER TABLE job_posts ADD COLUMN assignment_reason TEXT');
        }
        if (!in_array('compliance_checklist', $cols, true)) {
            $pdo->exec('ALTER TABLE job_posts ADD COLUMN compliance_checklist TEXT');
        }
        // Migrate status to canonical strings
        $pdo->exec('UPDATE job_posts SET status = "Menunggu Verifikasi" WHERE status = "Dikirim/Menunggu Verifikasi" OR status = "Dikirim"');
        $pdo->exec('UPDATE job_posts SET status = "Perlu Direvisi" WHERE status = "Perlu Revisi"');

        $verCols = array_column($pdo->query('PRAGMA table_info(job_verifications)')->fetchAll(), 'name');
        if (!in_array('additional_doc_required', $verCols, true)) {
            $pdo->exec('ALTER TABLE job_verifications ADD COLUMN additional_doc_required INTEGER DEFAULT 0');
        }
        if (!in_array('layer_flags', $verCols, true)) {
            $pdo->exec('ALTER TABLE job_verifications ADD COLUMN layer_flags TEXT');
        }
    } catch (Throwable $ignored) {}
}

function init_sqlite_schema(PDO $pdo): void
{
    $queries = [
        "CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL,
            profile_complete INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME
        )",
        "CREATE TABLE IF NOT EXISTS employer_profiles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL UNIQUE,
            owner_name TEXT,
            nik TEXT,
            profession TEXT,
            phone TEXT,
            whatsapp TEXT,
            npwp TEXT,
            linkedin TEXT,
            facebook TEXT,
            instagram TEXT,
            province TEXT,
            city TEXT,
            district TEXT,
            subdistrict TEXT,
            postal_code TEXT,
            address TEXT,
            address_detail TEXT,
            latitude TEXT,
            longitude TEXT,
            description TEXT,
            consent_agreed INTEGER DEFAULT 0,
            profile_complete INTEGER DEFAULT 0,
            verification_status TEXT DEFAULT 'ACTIVE_VERIFIED',
            active_until DATETIME,
            extension_requested INTEGER DEFAULT 0,
            extension_status TEXT DEFAULT 'NONE',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME
        )",
        "CREATE TABLE IF NOT EXISTS seeker_profiles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            nik TEXT NOT NULL,
            birth_place TEXT NOT NULL,
            birth_date DATE NOT NULL,
            gender TEXT NOT NULL,
            marital_status TEXT NOT NULL,
            phone TEXT NOT NULL,
            ktp_address TEXT NOT NULL,
            domicile_address TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME
        )",
        "CREATE TABLE IF NOT EXISTS seeker_experiences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            company_name TEXT NOT NULL,
            position TEXT NOT NULL,
            duration TEXT NOT NULL,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS seeker_trainings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            training_name TEXT NOT NULL,
            organizer TEXT,
            year TEXT,
            certificate TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS seeker_educations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            level TEXT NOT NULL,
            school_name TEXT NOT NULL,
            major TEXT,
            graduation_year TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS seeker_skills (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            skill_name TEXT NOT NULL,
            level TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS seeker_languages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            language_name TEXT NOT NULL,
            proficiency TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS job_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            description TEXT NOT NULL,
            location TEXT NOT NULL,
            job_type TEXT NOT NULL,
            industry TEXT,
            entity_type TEXT DEFAULT 'Individu',
            status TEXT DEFAULT 'Draft',
            salary_min INTEGER,
            salary_max INTEGER,
            quota INTEGER DEFAULT 1,
            accepted_count INTEGER DEFAULT 0,
            kbji_code TEXT,
            parent_job_id INTEGER,
            unfulfilled_reason TEXT,
            verifier_notes TEXT,
            verification_checklist TEXT,
            is_blacklisted INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME
        )",
        "CREATE TABLE IF NOT EXISTS kbji_data (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kode_kbji TEXT NOT NULL UNIQUE,
            nama_jabatan TEXT NOT NULL
        )",
        "INSERT INTO users (name, email, password_hash, role, profile_complete) VALUES
        ('Admin Pusat', 'admin@pasker-id.test', '\$2y\$10\$6oyYT1H5LbMGUPCDKGQlVefo1D07I3CDkNNDQur49Vw0RpoEc9UU6', 'admin', 1),
        ('Perorangan Demo', 'perorangan@pasker-id.test', '\$2y\$10\$aW5VNKZZF8jblGzaMduEG.gpZse5bFWEB8QvhO88CGOshtvOLhkAm', 'employer', 1),
        ('Pencari Kerja Demo', 'seeker@pasker-id.test', '\$2y\$10\$xRt/tkNkvzp2qtMsDhqdjOE2HJfN5RqqowsgsjVFPhWTHAgLpbGGa', 'seeker', 1)",
        "INSERT INTO employer_profiles (
            user_id, owner_name, nik, profession, phone, whatsapp, npwp, linkedin, facebook, instagram,
            province, city, district, village, postal_code, address, address_detail, latitude, longitude,
            description, user_consent, verified, verification_status, active_until
        ) VALUES (
            2, 'Perorangan Demo', '3275012304890001', 'Kuliner & Katering', '08123456789', '08123456789', '12.345.678.9-012.000',
            'https://linkedin.com/in/perorangan-demo', 'https://facebook.com/perorangan.demo', 'https://instagram.com/perorangandemo',
            'Jawa Barat', 'Kota Bekasi', 'Bekasi Selatan', 'Pekayon Jaya', '17148', 'Jl. Ahmad Yani No. 12', 'Samping Indomaret Pekayon',
            '-6.241586', '106.992416', 'Usaha katering rumahan dan jasa konsultasi menu kuliner keluarga.', 1, 1, 'APPROVED', datetime('now', '+3 months')
        )",
        "INSERT INTO job_posts (user_id, title, description, location, job_type, industry, entity_type, status, quota, accepted_count, kbji_code) VALUES
        (2, 'Koki Masakan Tradisional', 'Membutuhkan koki berpengalaman untuk katering harian rumahan.', 'Kota Bekasi', 'Full Time', 'Kuliner', 'Individu', 'Tayang', 2, 1, '5120.01'),
        (2, 'Asisten Rumah Tangga', 'Membantu kebersihan dan kerapian rumah tinggal.', 'Kota Bekasi', 'Full Time', 'Jasa Perorangan', 'Individu', 'Draft', 1, 0, '9111.01'),
        (2, 'Staf Entri Data Katering', 'Mengelola data pesanan dan bahan makanan.', 'Kota Bekasi', 'Part Time', 'Administrasi', 'Individu', 'Dikirim/Menunggu Verifikasi', 1, 0, '4312.01')",
        "INSERT INTO kbji_data (kode_kbji, nama_jabatan) VALUES
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
        ('2221.01', 'Perawat Profesional')"
    ];

    foreach ($queries as $q) {
        try {
            $pdo->exec($q);
        } catch (Exception $e) {}
    }
}

function ensure_database_schema(PDO $pdo): void
{
    static $schemaChecked = false;
    if ($schemaChecked) return;
    $schemaChecked = true;

    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            // Ensure audit_logs table exists
            $pdo->exec('CREATE TABLE IF NOT EXISTS audit_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                entity_type VARCHAR(50) NOT NULL,
                entity_id INT NOT NULL,
                actor_name VARCHAR(120) NOT NULL,
                actor_role VARCHAR(50) NOT NULL,
                action VARCHAR(80) NOT NULL,
                details TEXT,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )');

            // Ensure job_verifications table exists
            $pdo->exec('CREATE TABLE IF NOT EXISTS job_verifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_id INT NOT NULL,
                user_id INT NOT NULL,
                kbji_code VARCHAR(20) NOT NULL,
                status VARCHAR(40) NOT NULL DEFAULT "PENDING",
                verifier_notes TEXT NULL,
                additional_doc_required TINYINT(1) DEFAULT 0,
                layer_flags TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )');

            // Alter employer_profiles columns if missing
            $columns = $pdo->query("SHOW COLUMNS FROM employer_profiles")->fetchAll(PDO::FETCH_COLUMN);
            
            $addCols = [
                'nik' => "VARCHAR(30) NULL AFTER owner_name",
                'whatsapp' => "VARCHAR(30) NULL AFTER phone",
                'npwp' => "VARCHAR(30) NULL AFTER whatsapp",
                'linkedin' => "VARCHAR(255) NULL AFTER npwp",
                'facebook' => "VARCHAR(255) NULL AFTER linkedin",
                'instagram' => "VARCHAR(255) NULL AFTER facebook",
                'same_location_siapkerja' => "TINYINT(1) DEFAULT 1 AFTER instagram",
                'district' => "VARCHAR(120) NULL AFTER city",
                'village' => "VARCHAR(120) NULL AFTER district",
                'postal_code' => "VARCHAR(20) NULL AFTER village",
                'same_address_siapkerja' => "TINYINT(1) DEFAULT 1 AFTER postal_code",
                'address_detail' => "TEXT NULL AFTER address",
                'latitude' => "VARCHAR(50) NULL AFTER address_detail",
                'longitude' => "VARCHAR(50) NULL AFTER latitude",
                'doc_permission' => "VARCHAR(255) NULL AFTER longitude",
                'doc_location_photo' => "VARCHAR(255) NULL AFTER doc_permission",
                'permit_document' => "VARCHAR(255) NULL AFTER doc_location_photo",
                'workplace_photo' => "VARCHAR(255) NULL AFTER permit_document",
                'user_consent' => "TINYINT(1) DEFAULT 0 AFTER description",
                'consent_accepted' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER user_consent",
                'consent_agreed' => "TINYINT(1) DEFAULT 0 AFTER consent_accepted",
                'verification_status' => "ENUM('NOT_SUBMITTED', 'PENDING', 'NEEDS_REVISION', 'APPROVED', 'SUSPENDED', 'TRANSITION_LIMITED', 'FULL_DISABLED') DEFAULT 'NOT_SUBMITTED' AFTER verified",
                'rejection_count' => "INT DEFAULT 0 AFTER verification_status",
                'verifier_notes' => "TEXT NULL AFTER rejection_count",
                'verification_checklist' => "TEXT NULL AFTER verifier_notes",
                'suspension_reason' => "TEXT NULL AFTER verification_checklist",
                'active_until' => "DATETIME NULL AFTER suspension_reason",
                'extension_requested' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER active_until",
                'extension_status' => "ENUM('NONE', 'REQUESTED', 'APPROVED', 'REJECTED') DEFAULT 'NONE' AFTER extension_requested",
                'manual_review_status' => "VARCHAR(50) DEFAULT 'NONE' AFTER extension_status",
                'assigned_to' => "VARCHAR(120) NULL AFTER manual_review_status",
                'assigned_at' => "DATETIME NULL AFTER assigned_to",
                'assignment_reason' => "TEXT NULL AFTER assigned_at",
                'consent_data_hash' => "TEXT NULL AFTER assignment_reason",
                'consent_given_at' => "DATETIME NULL AFTER consent_data_hash",
                'officer_statement' => "TEXT NULL AFTER consent_given_at",
                'officer_name' => "VARCHAR(120) NULL AFTER officer_statement",
                'entity_type' => "VARCHAR(50) DEFAULT 'Individu' AFTER officer_name",
            ];

            foreach ($addCols as $col => $definition) {
                if (!in_array($col, $columns)) {
                    $pdo->exec("ALTER TABLE employer_profiles ADD COLUMN {$col} {$definition}");
                }
            }

            // Alter job_posts columns if missing
            $jobCols = $pdo->query("SHOW COLUMNS FROM job_posts")->fetchAll(PDO::FETCH_COLUMN);
            $addJobCols = [
                'entity_type' => "ENUM('Perusahaan', 'Individu') DEFAULT 'Individu' AFTER industry",
                'accepted_count' => "INT NOT NULL DEFAULT 0 AFTER quota",
                'kbji_code' => "VARCHAR(20) NULL AFTER accepted_count",
                'parent_job_id' => "INT NULL AFTER kbji_code",
                'published_at' => "DATETIME NULL AFTER created_at",
                'unfulfilled_reason' => "TEXT NULL AFTER parent_job_id",
                'verifier_notes' => "TEXT NULL AFTER unfulfilled_reason",
                'verification_checklist' => "TEXT NULL AFTER verifier_notes",
                'is_blacklisted' => "TINYINT(1) DEFAULT 0 AFTER verification_checklist",
                'additional_doc_required' => "TINYINT(1) DEFAULT 0 AFTER is_blacklisted",
                'admin_notes' => "TEXT NULL AFTER additional_doc_required",
                'details' => "TEXT NULL AFTER admin_notes",
                'min_education' => "VARCHAR(80) NULL AFTER details",
                'min_experience' => "VARCHAR(80) NULL AFTER min_education",
                'assigned_to' => "VARCHAR(120) NULL AFTER min_experience",
                'assigned_at' => "DATETIME NULL AFTER assigned_to",
                'assignment_reason' => "TEXT NULL AFTER assigned_at",
                'compliance_checklist' => "TEXT NULL AFTER assignment_reason",
                'revision_opened_at' => "DATETIME NULL AFTER compliance_checklist",
            ];

            foreach ($addJobCols as $col => $definition) {
                if (!in_array($col, $jobCols)) {
                    $pdo->exec("ALTER TABLE job_posts ADD COLUMN {$col} {$definition}");
                }
            }

            // Modify status ENUM in job_posts to canonical statuses
            $pdo->exec("UPDATE job_posts SET status = 'Menunggu Verifikasi' WHERE status IN ('Dikirim/Menunggu Verifikasi', 'Dikirim')");
            $pdo->exec("UPDATE job_posts SET status = 'Perlu Direvisi' WHERE status = 'Perlu Revisi'");
            $pdo->exec("ALTER TABLE job_posts MODIFY COLUMN status ENUM('Draft', 'Menunggu Verifikasi', 'Perlu Direvisi', 'Ditolak', 'Terjadwal Tayang', 'Tayang', 'Ditangguhkan', 'Ditutup', 'Kedaluwarsa', 'Diblokir') NOT NULL DEFAULT 'Draft'");
        }
    } catch (Exception $e) {
        // Silently handle if table structures already match
    }
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function get_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $cachedUser = null;
    static $cachedUserId = null;

    if ($cachedUser !== null && $cachedUserId === (int) $_SESSION['user_id']) {
        return $cachedUser;
    }

    $statement = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $statement->execute([$_SESSION['user_id']]);
    $cachedUser = $statement->fetch() ?: null;
    $cachedUserId = $cachedUser['id'] ?? null;

    return $cachedUser;
}

function login_user(array $user): void
{
    $_SESSION['user_id'] = (int) $user['id'];
}

function logout_user(): void
{
    unset($_SESSION['user_id']);
}

function require_login(): array
{
    $user = current_user();

    if (!$user) {
        redirect('login.php');
    }

    return $user;
}

function role_home(string $role): string
{
    return match ($role) {
        'admin' => 'admin.php',
        'seeker' => 'seeker.php',
        default => 'dashboard.php',
    };
}

function is_profile_complete(array $user): bool
{
    return (int) ($user['profile_complete'] ?? 0) === 1;
}

function require_role(string $role): array
{
    $user = require_login();

    if (($user['role'] ?? '') !== $role) {
        redirect(role_home($user['role'] ?? ''));
    }

    return $user;
}

function find_user_by_email(string $email): ?array
{
    $statement = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $statement->execute([$email]);

    return $statement->fetch() ?: null;
}

function create_user(string $name, string $email, string $password, string $role): int
{
    $statement = db()->prepare('INSERT INTO users (name, email, password_hash, role, profile_complete, created_at) VALUES (?, ?, ?, ?, 0, NOW())');
    $statement->execute([
        $name,
        $email,
        password_hash($password, PASSWORD_DEFAULT),
        $role,
    ]);

    return (int) db()->lastInsertId();
}

function employer_profile_exists(int $userId): bool
{
    $statement = db()->prepare('SELECT COUNT(*) FROM employer_profiles WHERE user_id = ?');
    $statement->execute([$userId]);

    return (int) $statement->fetchColumn() > 0;
}

function seeker_profile_exists(int $userId): bool
{
    $statement = db()->prepare('SELECT COUNT(*) FROM seeker_profiles WHERE user_id = ?');
    $statement->execute([$userId]);

    return (int) $statement->fetchColumn() > 0;
}

require_once __DIR__ . '/platform.php';
