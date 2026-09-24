<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$db = db();

// Check if user Andi Pratama / Ahmad Pratama exists
$stmt = $db->prepare("SELECT id FROM users WHERE email = ? OR name = ? OR name = ?");
$stmt->execute(['ahmad@email.com', 'Andi Pratama', 'Ahmad Pratama']);
$existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existingUser) {
    $userId = (int)$existingUser['id'];
    $db->prepare("UPDATE users SET name = 'Andi Pratama', email = 'ahmad@email.com', role = 'employer', profile_complete = 1, domicile_city_id = 'Kota Bandung', city = 'Kota Bandung', created_at = '2026-09-23 17:02:00' WHERE id = ?")->execute([$userId]);
} else {
    $stmt = $db->prepare("INSERT INTO users (name, email, password_hash, role, profile_complete, domicile_city_id, city, created_at) VALUES (?, ?, ?, 'employer', 1, 'Kota Bandung', 'Kota Bandung', '2026-09-23 17:02:00')");
    $stmt->execute([
        'Andi Pratama',
        'ahmad@email.com',
        password_hash('password123', PASSWORD_DEFAULT)
    ]);
    $userId = (int)$db->lastInsertId();
}

// Check employer profile
$stmtEp = $db->prepare("SELECT id FROM employer_profiles WHERE user_id = ?");
$stmtEp->execute([$userId]);
$existingEp = $stmtEp->fetch(PDO::FETCH_ASSOC);

$profileData = [
    'user_id' => $userId,
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
    'social_media' => 'Instagram : @ahmadpratama, LinkedIn : linkedin.com/in/ahmadpratama',
    'instagram' => '@ahmadpratama',
    'linkedin' => 'linkedin.com/in/ahmadpratama',
    'entity_type' => 'Individu',
    'verification_status' => 'PENDING',
    'verified' => 0,
    'domicile_city_id' => 'Kota Bandung',
    'created_at' => '2026-09-23 17:02:00'
];

if ($existingEp) {
    $sql = "UPDATE employer_profiles SET owner_name = :owner_name, nik = :nik, profession = :profession, phone = :phone, whatsapp = :whatsapp, npwp = :npwp, province = :province, city = :city, district = :district, village = :village, postal_code = :postal_code, address = :address, address_detail = :address_detail, latitude = :latitude, longitude = :longitude, description = :description, doc_permission = :doc_permission, doc_location_photo = :doc_location_photo, social_media = :social_media, instagram = :instagram, linkedin = :linkedin, entity_type = :entity_type, verification_status = :verification_status, verified = :verified, domicile_city_id = :domicile_city_id, created_at = :created_at WHERE user_id = :user_id";
    $db->prepare($sql)->execute($profileData);
} else {
    $fields = implode(', ', array_keys($profileData));
    $placeholders = ':' . implode(', :', array_keys($profileData));
    $sql = "INSERT INTO employer_profiles ({$fields}) VALUES ({$placeholders})";
    $db->prepare($sql)->execute($profileData);
}

// Clean and insert sample audit logs for Andi Pratama
$db->prepare("DELETE FROM audit_logs WHERE entity_type = 'employer' AND entity_id = ?")->execute([$userId]);
$logs = [
    ['action' => 'Pemberi kerja mengajukan profil.', 'actor_name' => 'Andi Pratama', 'actor_role' => 'employer', 'created_at' => '2026-09-23 16:55:00', 'details' => 'Pembuatan profil awal individu.'],
    ['action' => 'Profil dikirim untuk verifikasi.', 'actor_name' => 'Andi Pratama', 'actor_role' => 'employer', 'created_at' => '2026-09-23 17:02:00', 'details' => 'Data pemberi kerja dikirim untuk verifikasi.'],
    ['action' => 'Budi Santoso mengambil pengajuan: Dikirim → Dalam Verifikasi.', 'actor_name' => 'Budi Santoso, S.T., M.M.', 'actor_role' => 'admin_dinas', 'created_at' => '2026-09-24 08:50:00', 'details' => 'Pengambilan berkas pengajuan.'],
    ['action' => 'Ditugaskan ke Budi Santoso.', 'actor_name' => 'Admin Pusat', 'actor_role' => 'admin_pusat', 'created_at' => '2026-09-24 08:55:00', 'details' => 'Penugasan verifikator wilayah Kota Bandung.'],
    ['action' => 'Budi Santoso memberikan keputusan verifikasi.', 'actor_name' => 'Budi Santoso, S.T., M.M.', 'actor_role' => 'admin_dinas', 'created_at' => '2026-09-24 09:20:00', 'details' => 'Pemeriksaan kelengkapan berkas legalitas dan lokasi.'],
    ['action' => 'Pengajuan dilepas oleh Budi Santoso.', 'actor_name' => 'Budi Santoso, S.T., M.M.', 'actor_role' => 'admin_dinas', 'created_at' => '2026-09-24 09:27:00', 'details' => 'Status dikembalikan ke antrian verifikasi.']
];

foreach ($logs as $l) {
    $stmtLog = $db->prepare("INSERT INTO audit_logs (entity_type, entity_id, actor_name, actor_role, action, details, created_at) VALUES ('employer', ?, ?, ?, ?, ?, ?)");
    $stmtLog->execute([$userId, $l['actor_name'], $l['actor_role'], $l['action'], $l['details'], $l['created_at']]);
}

echo "Successfully created/updated Andi Pratama with ID: {$userId}\n";
