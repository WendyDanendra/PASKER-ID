CREATE DATABASE IF NOT EXISTS paskerid CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE paskerid;

DROP TABLE IF EXISTS kbji_data;
DROP TABLE IF EXISTS job_posts;
DROP TABLE IF EXISTS seeker_languages;
DROP TABLE IF EXISTS seeker_skills;
DROP TABLE IF EXISTS seeker_trainings;
DROP TABLE IF EXISTS seeker_educations;
DROP TABLE IF EXISTS seeker_experiences;
DROP TABLE IF EXISTS seeker_profiles;
DROP TABLE IF EXISTS employer_profiles;
DROP TABLE IF EXISTS users;

CREATE TABLE kbji_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kode_kbji VARCHAR(20) NOT NULL UNIQUE,
    nama_jabatan VARCHAR(255) NOT NULL
);

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'employer', 'seeker') NOT NULL,
    profile_complete TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE employer_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    owner_name VARCHAR(120) NOT NULL,
    profession VARCHAR(120) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    address TEXT NOT NULL,
    city VARCHAR(120) NOT NULL,
    province VARCHAR(120) NOT NULL,
    description TEXT NULL,
    verified TINYINT(1) NOT NULL DEFAULT 0,
    active_until DATETIME NULL DEFAULT NULL,
    extension_requested TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_employer_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE seeker_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    nik VARCHAR(30) NOT NULL,
    birth_place VARCHAR(120) NOT NULL,
    birth_date DATE NOT NULL,
    gender ENUM('Laki-laki', 'Perempuan') NOT NULL,
    marital_status ENUM('Kawin', 'Belum Kawin') NOT NULL,
    phone VARCHAR(30) NOT NULL,
    ktp_address TEXT NOT NULL,
    domicile_address TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_seeker_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE seeker_experiences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    company_name VARCHAR(150) NOT NULL,
    position VARCHAR(120) NOT NULL,
    duration VARCHAR(120) NOT NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_exp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE seeker_trainings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    training_name VARCHAR(150) NOT NULL,
    organizer VARCHAR(150) NULL,
    year VARCHAR(20) NULL,
    certificate VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_training_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE seeker_educations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    level VARCHAR(80) NOT NULL,
    school_name VARCHAR(150) NOT NULL,
    major VARCHAR(120) NULL,
    graduation_year VARCHAR(20) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_education_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE seeker_skills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    skill_name VARCHAR(120) NOT NULL,
    level VARCHAR(50) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_skill_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE seeker_languages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    language_name VARCHAR(120) NOT NULL,
    proficiency VARCHAR(50) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_language_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE job_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    location VARCHAR(150) NOT NULL,
    job_type VARCHAR(80) NOT NULL,
    industry VARCHAR(120) NULL,
    status ENUM('Draft', 'Menunggu Verifikasi', 'Tayang', 'Ditutup', 'Ditolak', 'Penuh') NOT NULL DEFAULT 'Draft',
    salary_min INT NULL,
    salary_max INT NULL,
    quota INT NOT NULL DEFAULT 1,
    kbji_code VARCHAR(20) NULL,
    details TEXT NULL,
    parent_job_id INT NULL,
    unfulfilled_reason TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_job_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

INSERT INTO users (name, email, password_hash, role, profile_complete) VALUES
('Admin Pusat', 'admin@paskerid.test', '$2y$10$6oyYT1H5LbMGUPCDKGQlVefo1D07I3CDkNNDQur49Vw0RpoEc9UU6', 'admin', 1),
('Perorangan Demo', 'perorangan@paskerid.test', '$2y$10$aW5VNKZZF8jblGzaMduEG.gpZse5bFWEB8QvhO88CGOshtvOLhkAm', 'employer', 0),
('Pencari Kerja Demo', 'seeker@paskerid.test', '$2y$10$xRt/tkNkvzp2qtMsDhqdjOE2HJfN5RqqowsgsjVFPhWTHAgLpbGGa', 'seeker', 0);

INSERT INTO employer_profiles (user_id, owner_name, profession, phone, address, city, province, description, verified, active_until) VALUES
(2, 'Perorangan Demo', 'Kuliner', '08123456789', 'Bekasi', 'Kota Bekasi', 'Jawa Barat', 'Demo pemberi kerja individu untuk kebutuhan showcase aplikasi.', 1, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 3 MONTH));

UPDATE users SET profile_complete = 1 WHERE id = 2;

INSERT INTO kbji_data (kode_kbji, nama_jabatan) VALUES
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
('2221.01', 'Perawat Profesional');
