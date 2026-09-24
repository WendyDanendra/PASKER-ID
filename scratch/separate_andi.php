<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$db = db();

$passPusat = password_hash('Pusatpasarkerj4', PASSWORD_DEFAULT);
$passDefault = password_hash('password', PASSWORD_DEFAULT);

// 1. RESTORE DUMMY PERORANGAN (Budi Santoso)
$stmt = $db->prepare("SELECT id FROM users WHERE email = 'perorangan@paskerid.test' OR email = 'perorangan@pasker-id.test'");
$stmt->execute();
$existingPerorangan = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existingPerorangan) {
    $budiId = (int)$existingPerorangan['id'];
    $db->prepare("UPDATE users SET name = 'Budi Santoso', email = 'perorangan@paskerid.test', password_hash = ?, role = 'employer', profile_complete = 1, domicile_city_id = 'Kota Bandung', city = 'Kota Bandung' WHERE id = ?")->execute([$passDefault, $budiId]);
} else {
    $db->prepare("INSERT INTO users (name, email, password_hash, role, profile_complete, domicile_city_id, city, created_at) VALUES ('Budi Santoso', 'perorangan@paskerid.test', ?, 'employer', 1, 'Kota Bandung', 'Kota Bandung', '2026-09-15 02:29:16')")->execute([$passDefault]);
    $budiId = (int)$db->lastInsertId();
}

$budiProfile = [
    'user_id' => $budiId,
    'owner_name' => 'Budi Santoso',
    'nik' => '3273012345670002',
    'profession' => 'Jasa Perorangan / Rumah Tangga',
    'phone' => '08123456789',
    'whatsapp' => '08123456789',
    'npwp' => '12.345.678.9-000.000',
    'province' => 'Jawa Barat',
    'city' => 'Kota Bandung',
    'district' => 'Coblong',
    'village' => 'Dago',
    'postal_code' => '40135',
    'address' => 'Jl. Ahmad Yani No. 12',
    'address_detail' => 'Dago',
    'latitude' => '-6.887844',
    'longitude' => '107.613038',
    'description' => 'Pemberi kerja perorangan untuk asisten rumah tangga.',
    'doc_permission' => 'dokumen-legalitas.pdf',
    'doc_location_photo' => 'foto-rumah.jpg',
    'social_media' => 'Instagram : @budisantoso',
    'instagram' => '@budisantoso',
    'linkedin' => '',
    'facebook' => '',
    'entity_type' => 'Individu',
    'verification_status' => 'APPROVED',
    'verified' => 1,
    'domicile_city_id' => 'Kota Bandung',
    'created_at' => '2026-09-15 02:29:16'
];

$stmtEp = $db->prepare("SELECT id FROM employer_profiles WHERE user_id = ?");
$stmtEp->execute([$budiId]);
if ($stmtEp->fetch()) {
    $sql = "UPDATE employer_profiles SET owner_name = :owner_name, nik = :nik, profession = :profession, phone = :phone, whatsapp = :whatsapp, npwp = :npwp, province = :province, city = :city, district = :district, village = :village, postal_code = :postal_code, address = :address, address_detail = :address_detail, latitude = :latitude, longitude = :longitude, description = :description, doc_permission = :doc_permission, doc_location_photo = :doc_location_photo, social_media = :social_media, instagram = :instagram, linkedin = :linkedin, facebook = :facebook, entity_type = :entity_type, verification_status = :verification_status, verified = :verified, domicile_city_id = :domicile_city_id, created_at = :created_at WHERE user_id = :user_id";
    $db->prepare($sql)->execute($budiProfile);
} else {
    $fields = implode(', ', array_keys($budiProfile));
    $placeholders = ':' . implode(', :', array_keys($budiProfile));
    $sql = "INSERT INTO employer_profiles ({$fields}) VALUES ({$placeholders})";
    $db->prepare($sql)->execute($budiProfile);
}

// 2. CREATE / SEPARATE ANDI PRATAMA USER
// Support andi@paskerid.test, andi@paskerid.tes, ahmad@email.com
$stmtAndi = $db->prepare("SELECT id FROM users WHERE email = 'andi@paskerid.test' OR email = 'andi@paskerid.tes' OR email = 'ahmad@email.com' OR name = 'Andi Pratama'");
$stmtAndi->execute();
$existingAndi = $stmtAndi->fetch(PDO::FETCH_ASSOC);

if ($existingAndi && (int)$existingAndi['id'] !== $budiId) {
    $andiId = (int)$existingAndi['id'];
    $db->prepare("UPDATE users SET name = 'Andi Pratama', email = 'andi@paskerid.test', password_hash = ?, role = 'employer', profile_complete = 1, domicile_city_id = 'Kota Bandung', city = 'Kota Bandung' WHERE id = ?")->execute([$passPusat, $andiId]);
} else {
    $db->prepare("INSERT INTO users (name, email, password_hash, role, profile_complete, domicile_city_id, city, created_at) VALUES ('Andi Pratama', 'andi@paskerid.test', ?, 'employer', 1, 'Kota Bandung', 'Kota Bandung', '2026-09-23 17:02:00')")->execute([$passPusat]);
    $andiId = (int)$db->lastInsertId();
}

$andiProfile = [
    'user_id' => $andiId,
    'owner_name' => 'Andi Pratama',
    'nik' => '3273012345670001',
    'profession' => 'Jasa Desain Grafis',
    'phone' => '0812-3456-7890',
    'whatsapp' => '0812-3456-7890',
    'npwp' => '12.345.678.9-123.000',
    'province' => 'Jawa Barat',
    'city' => 'Kota Bandung',
    'district' => 'Coblong',
    'village' => 'Dago',
    'postal_code' => '40135',
    'address' => 'Jl. Ir. H. Juanda No. 25',
    'address_detail' => 'Dago, Coblong',
    'latitude' => '-6.887844',
    'longitude' => '107.613038',
    'description' => 'Menjalankan usaha jasa desain grafis dan layanan digital secara mandiri di wilayah Kota Bandung.',
    'doc_permission' => 'dokumen-usaha.pdf',
    'doc_location_photo' => 'foto-tempat-usaha.jpg',
    'social_media' => 'Instagram : @andipratama, LinkedIn : linkedin.com/in/andipratama',
    'instagram' => '@andipratama',
    'linkedin' => 'linkedin.com/in/andipratama',
    'facebook' => '@andipratama',
    'entity_type' => 'Individu',
    'verification_status' => 'PENDING',
    'verified' => 0,
    'domicile_city_id' => 'Kota Bandung',
    'created_at' => '2026-09-23 17:02:00'
];

$stmtEpAndi = $db->prepare("SELECT id FROM employer_profiles WHERE user_id = ?");
$stmtEpAndi->execute([$andiId]);
if ($stmtEpAndi->fetch()) {
    $sql = "UPDATE employer_profiles SET owner_name = :owner_name, nik = :nik, profession = :profession, phone = :phone, whatsapp = :whatsapp, npwp = :npwp, province = :province, city = :city, district = :district, village = :village, postal_code = :postal_code, address = :address, address_detail = :address_detail, latitude = :latitude, longitude = :longitude, description = :description, doc_permission = :doc_permission, doc_location_photo = :doc_location_photo, social_media = :social_media, instagram = :instagram, linkedin = :linkedin, facebook = :facebook, entity_type = :entity_type, verification_status = :verification_status, verified = :verified, domicile_city_id = :domicile_city_id, created_at = :created_at WHERE user_id = :user_id";
    $db->prepare($sql)->execute($andiProfile);
} else {
    $fields = implode(', ', array_keys($andiProfile));
    $placeholders = ':' . implode(', :', array_keys($andiProfile));
    $sql = "INSERT INTO employer_profiles ({$fields}) VALUES ({$placeholders})";
    $db->prepare($sql)->execute($andiProfile);
}

// Audit logs for Andi Pratama
$db->prepare("DELETE FROM audit_logs WHERE entity_type = 'employer' AND entity_id = ?")->execute([$andiId]);
$logs = [
    ['action' => 'Pemberi kerja mengajukan profil.', 'actor_name' => 'Andi Pratama', 'actor_role' => 'employer', 'created_at' => '2026-09-23 16:55:00', 'details' => 'Pembuatan profil awal individu.'],
    ['action' => 'Profil dikirim untuk verifikasi.', 'actor_name' => 'Andi Pratama', 'actor_role' => 'employer', 'created_at' => '2026-09-23 17:02:00', 'details' => 'Data pemberi kerja dikirim untuk verifikasi.'],
    ['action' => 'Budi Santoso mengambil pengajuan: Dikirim → Dalam Verifikasi.', 'actor_name' => 'Budi Santoso, S.T., M.M.', 'actor_role' => 'admin_dinas', 'created_at' => '2026-09-24 08:50:00', 'details' => 'Pengambilan berkas pengajuan.'],
    ['action' => 'Ditugaskan ke Budi Santoso.', 'actor_name' => 'Admin Pusat', 'actor_role' => 'admin_pusat', 'created_at' => '2026-09-24 08:55:00', 'details' => 'Penugasan verifikator wilayah Kota Bandung.'],
    ['action' => 'Budi Santoso memberikan keputusan verifikasi.', 'actor_name' => 'Budi Santoso, S.T., M.M.', 'actor_role' => 'admin_dinas', 'created_at' => '2026-09-24 09:20:00', 'details' => 'Pemeriksaan kelengkapan berkas legalitas dan lokasi.'],
    ['action' => 'Pengajuan dilepas oleh Budi Santoso.', 'actor_name' => 'Budi Santoso, S.T., M.M.', 'actor_role' => 'admin_dinas', 'created_at' => '2026-09-24 09:27:00', 'details' => 'Status dikembalikan ke antrian verifikasi.']
];
foreach ($logs as $l) {
    $db->prepare("INSERT INTO audit_logs (entity_type, entity_id, actor_name, actor_role, action, details, created_at) VALUES ('employer', ?, ?, ?, ?, ?, ?)")
       ->execute([$andiId, $l['actor_name'], $l['actor_role'], $l['action'], $l['details'], $l['created_at']]);
}

// 3. ADMIN ACCOUNTS (Ensure password 'password' and 'Pusatpasarkerj4' works)
$db->prepare("UPDATE users SET email = 'admin@paskerid.test', password_hash = ?, role = 'admin', profile_complete = 1 WHERE id = 1")->execute([$passDefault]);
$db->prepare("UPDATE users SET password_hash = ?, role = 'admin_dinas', domicile_city_id = 'Kota Bandung', city = 'Kota Bandung', profile_complete = 1 WHERE email = 'admin.bandung@paskerid.test'")->execute([$passDefault]);

echo "SUCCESS: Dummy perorangan restored to Budi Santoso (ID: {$budiId}).\n";
echo "SUCCESS: Andi Pratama separated (ID: {$andiId}, Email: andi@paskerid.test, Pass: Pusatpasarkerj4).\n";
