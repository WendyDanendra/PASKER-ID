<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$db = db();

$passHash = password_hash('password', PASSWORD_DEFAULT);

// 1. UPDATE OR INSERT PERORANGAN USER AS ANDI PRATAMA
$stmt = $db->prepare("SELECT id FROM users WHERE email = 'perorangan@paskerid.test' OR email = 'perorangan@pasker-id.test'");
$stmt->execute();
$existingPerorangan = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existingPerorangan) {
    $peroranganId = (int)$existingPerorangan['id'];
    $db->prepare("UPDATE users SET name = 'Andi Pratama', email = 'perorangan@paskerid.test', password_hash = ?, role = 'employer', profile_complete = 1, domicile_city_id = 'Kota Bandung', city = 'Kota Bandung' WHERE id = ?")->execute([$passHash, $peroranganId]);
} else {
    $db->prepare("INSERT INTO users (name, email, password_hash, role, profile_complete, domicile_city_id, city, created_at) VALUES ('Andi Pratama', 'perorangan@paskerid.test', ?, 'employer', 1, 'Kota Bandung', 'Kota Bandung', '2026-09-23 17:02:00')")->execute([$passHash]);
    $peroranganId = (int)$db->lastInsertId();
}

// Ensure employer profile for Andi Pratama (User ID: $peroranganId)
$stmtEp = $db->prepare("SELECT id FROM employer_profiles WHERE user_id = ?");
$stmtEp->execute([$peroranganId]);
$existingEp = $stmtEp->fetch(PDO::FETCH_ASSOC);

$profileData = [
    'user_id' => $peroranganId,
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

if ($existingEp) {
    $sql = "UPDATE employer_profiles SET owner_name = :owner_name, nik = :nik, profession = :profession, phone = :phone, whatsapp = :whatsapp, npwp = :npwp, province = :province, city = :city, district = :district, village = :village, postal_code = :postal_code, address = :address, address_detail = :address_detail, latitude = :latitude, longitude = :longitude, description = :description, doc_permission = :doc_permission, doc_location_photo = :doc_location_photo, social_media = :social_media, instagram = :instagram, linkedin = :linkedin, facebook = :facebook, entity_type = :entity_type, verification_status = :verification_status, verified = :verified, domicile_city_id = :domicile_city_id, created_at = :created_at WHERE user_id = :user_id";
    $db->prepare($sql)->execute($profileData);
} else {
    $fields = implode(', ', array_keys($profileData));
    $placeholders = ':' . implode(', :', array_keys($profileData));
    $sql = "INSERT INTO employer_profiles ({$fields}) VALUES ({$placeholders})";
    $db->prepare($sql)->execute($profileData);
}

// Also update audit logs for Andi Pratama
$db->prepare("DELETE FROM audit_logs WHERE entity_type = 'employer' AND entity_id = ?")->execute([$peroranganId]);
$logs = [
    ['action' => 'Pemberi kerja mengajukan profil.', 'actor_name' => 'Andi Pratama', 'actor_role' => 'employer', 'created_at' => '2026-09-23 16:55:00', 'details' => 'Pembuatan profil awal individu.'],
    ['action' => 'Profil dikirim untuk verifikasi.', 'actor_name' => 'Andi Pratama', 'actor_role' => 'employer', 'created_at' => '2026-09-23 17:02:00', 'details' => 'Data pemberi kerja dikirim untuk verifikasi.']
];
foreach ($logs as $l) {
    $db->prepare("INSERT INTO audit_logs (entity_type, entity_id, actor_name, actor_role, action, details, created_at) VALUES ('employer', ?, ?, ?, ?, ?, ?)")
       ->execute([$peroranganId, $l['actor_name'], $l['actor_role'], $l['action'], $l['details'], $l['created_at']]);
}

// 2. UPDATE ADMIN PUSAT (admin@paskerid.test)
$stmtAdm = $db->prepare("SELECT id FROM users WHERE email = 'admin@paskerid.test' OR email = 'admin@pasker-id.test'");
$stmtAdm->execute();
$adm = $stmtAdm->fetch(PDO::FETCH_ASSOC);
if ($adm) {
    $db->prepare("UPDATE users SET email = 'admin@paskerid.test', password_hash = ?, role = 'admin', profile_complete = 1 WHERE id = ?")->execute([$passHash, (int)$adm['id']]);
} else {
    $db->prepare("INSERT INTO users (name, email, password_hash, role, profile_complete, created_at) VALUES ('Admin Pusat', 'admin@paskerid.test', ?, 'admin', 1, '2026-09-15 02:29:16')")->execute([$passHash]);
}

// 3. UPDATE ADMIN DINAS (admin.bandung@paskerid.test)
$stmtDinas = $db->prepare("SELECT id FROM users WHERE email = 'admin.bandung@paskerid.test'");
$stmtDinas->execute();
$dinas = $stmtDinas->fetch(PDO::FETCH_ASSOC);
if ($dinas) {
    $db->prepare("UPDATE users SET password_hash = ?, role = 'admin_dinas', domicile_city_id = 'Kota Bandung', city = 'Kota Bandung', profile_complete = 1 WHERE id = ?")->execute([$passHash, (int)$dinas['id']]);
} else {
    $db->prepare("INSERT INTO users (name, email, password_hash, role, profile_complete, domicile_city_id, city, created_at) VALUES ('Admin Dinas Kota Bandung', 'admin.bandung@paskerid.test', ?, 'admin_dinas', 1, 'Kota Bandung', 'Kota Bandung', '2026-09-21 01:54:53')")->execute([$passHash]);
}

// 4. UPDATE SEEKER (seeker@paskerid.test)
$stmtSeeker = $db->prepare("SELECT id FROM users WHERE email = 'seeker@paskerid.test' OR email = 'seeker@pasker-id.test'");
$stmtSeeker->execute();
$skr = $stmtSeeker->fetch(PDO::FETCH_ASSOC);
if ($skr) {
    $db->prepare("UPDATE users SET email = 'seeker@paskerid.test', password_hash = ?, role = 'seeker', profile_complete = 1 WHERE id = ?")->execute([$passHash, (int)$skr['id']]);
} else {
    $db->prepare("INSERT INTO users (name, email, password_hash, role, profile_complete, created_at) VALUES ('Pencari Kerja Demo', 'seeker@paskerid.test', ?, 'seeker', 1, '2026-09-15 02:29:16')")->execute([$passHash]);
}

echo "Database updated successfully!\n";
echo "Andi Pratama User ID: {$peroranganId}\n";
