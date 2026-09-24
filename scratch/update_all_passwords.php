<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$db = db();

$hash = password_hash('Pusatpasarkerj4', PASSWORD_DEFAULT);

// Update ALL users in the database to password 'Pusatpasarkerj4'
$db->prepare("UPDATE users SET password_hash = ?")->execute([$hash]);

// Ensure all standard users exist
$users = [
    ['name' => 'Admin Pusat', 'email' => 'admin@paskerid.test', 'role' => 'admin', 'profile_complete' => 1, 'domicile' => ''],
    ['name' => 'Admin Dinas Kota Bandung', 'email' => 'admin.bandung@paskerid.test', 'role' => 'admin_dinas', 'profile_complete' => 1, 'domicile' => 'Kota Bandung'],
    ['name' => 'Andi Pratama', 'email' => 'andi@paskerid.test', 'role' => 'employer', 'profile_complete' => 1, 'domicile' => 'Kota Bandung'],
    ['name' => 'Budi Santoso', 'email' => 'perorangan@paskerid.test', 'role' => 'employer', 'profile_complete' => 1, 'domicile' => 'Kota Bandung'],
    ['name' => 'Pencari Kerja Demo', 'email' => 'seeker@paskerid.test', 'role' => 'seeker', 'profile_complete' => 1, 'domicile' => '']
];

foreach ($users as $u) {
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$u['email']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $db->prepare("UPDATE users SET name = ?, password_hash = ?, role = ?, profile_complete = ?, domicile_city_id = ?, city = ? WHERE id = ?")
           ->execute([$u['name'], $hash, $u['role'], $u['profile_complete'], $u['domicile'], $u['domicile'], $row['id']]);
    } else {
        $db->prepare("INSERT INTO users (name, email, password_hash, role, profile_complete, domicile_city_id, city, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))")
           ->execute([$u['name'], $u['email'], $hash, $u['role'], $u['profile_complete'], $u['domicile'], $u['domicile']]);
    }
}

echo "All users updated with password 'Pusatpasarkerj4' successfully.\n";

$all = $db->query("SELECT id, name, email, role FROM users")->fetchAll(PDO::FETCH_ASSOC);
print_r($all);
