<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$db = db();

// Ensure user perorangan@paskerid.test exists with password 'password'
$hash = password_hash('password', PASSWORD_DEFAULT);

$stmt = $db->prepare("SELECT id FROM users WHERE email = ? OR email = ?");
$stmt->execute(['perorangan@paskerid.test', 'perorangan@pasker-id.test']);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

if ($u) {
    $userId = (int)$u['id'];
    $db->prepare("UPDATE users SET name = 'Budi Santoso', email = 'perorangan@paskerid.test', password_hash = ?, role = 'employer', profile_complete = 1, domicile_city_id = 'Kota Bandung', city = 'Kota Bandung' WHERE id = ?")->execute([$hash, $userId]);
} else {
    $db->prepare("INSERT INTO users (name, email, password_hash, role, profile_complete, domicile_city_id, city, created_at) VALUES ('Budi Santoso', 'perorangan@paskerid.test', ?, 'employer', 1, 'Kota Bandung', 'Kota Bandung', '2026-09-23 17:02:00')")->execute([$hash]);
    $userId = (int)$db->lastInsertId();
}

// Ensure employer profile exists for this user
$stmtEp = $db->prepare("SELECT id FROM employer_profiles WHERE user_id = ?");
$stmtEp->execute([$userId]);
$ep = $stmtEp->fetch(PDO::FETCH_ASSOC);

$profileData = [
    'user_id' => $userId,
    'owner_name' => 'Budi Santoso',
    'nik' => '3273012345670002',
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
    'social_media' => 'Instagram : @budisantoso, LinkedIn : linkedin.com/in/budisantoso',
    'instagram' => '@budisantoso',
    'linkedin' => 'linkedin.com/in/budisantoso',
    'entity_type' => 'Individu',
    'verification_status' => 'PENDING',
    'verified' => 0,
    'domicile_city_id' => 'Kota Bandung',
    'created_at' => '2026-09-23 17:02:00'
];

if ($ep) {
    $sql = "UPDATE employer_profiles SET owner_name = :owner_name, nik = :nik, profession = :profession, phone = :phone, whatsapp = :whatsapp, npwp = :npwp, province = :province, city = :city, district = :district, village = :village, postal_code = :postal_code, address = :address, address_detail = :address_detail, latitude = :latitude, longitude = :longitude, description = :description, doc_permission = :doc_permission, doc_location_photo = :doc_location_photo, social_media = :social_media, instagram = :instagram, linkedin = :linkedin, entity_type = :entity_type, verification_status = :verification_status, verified = :verified, domicile_city_id = :domicile_city_id, created_at = :created_at WHERE user_id = :user_id";
    $db->prepare($sql)->execute($profileData);
} else {
    $fields = implode(', ', array_keys($profileData));
    $placeholders = ':' . implode(', :', array_keys($profileData));
    $sql = "INSERT INTO employer_profiles ({$fields}) VALUES ({$placeholders})";
    $db->prepare($sql)->execute($profileData);
}

// Also ensure Andi Pratama has password 'password' for easy testing
$db->prepare("UPDATE users SET password_hash = ? WHERE email = 'ahmad@email.com' OR name = 'Andi Pratama'")->execute([$hash]);

echo "Updated perorangan@paskerid.test (User ID: {$userId}) successfully.\n";
