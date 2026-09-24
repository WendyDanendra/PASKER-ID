<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$db = db();

// Check all admin users
$admins = $db->query("SELECT id, name, email, password_hash, role, domicile_city_id FROM users WHERE role LIKE '%admin%'")->fetchAll(PDO::FETCH_ASSOC);
echo "ADMIN USERS:\n";
print_r($admins);

foreach ($admins as $adm) {
    echo "Admin {$adm['id']} ({$adm['email']}):\n";
    echo "  password verify 'password': " . (password_verify('password', $adm['password_hash']) ? 'YES' : 'NO') . "\n";
    echo "  password verify 'password123': " . (password_verify('password123', $adm['password_hash']) ? 'YES' : 'NO') . "\n";
}
