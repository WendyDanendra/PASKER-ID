<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$u = db()->query("SELECT id, name, email, password_hash, role, profile_complete FROM users WHERE email LIKE '%perorangan%' OR email LIKE '%ahmad%' OR email LIKE '%andi%'")->fetchAll(PDO::FETCH_ASSOC);
print_r($u);

// Check if 'password' or 'password123' verifies
foreach ($u as $row) {
    echo "User {$row['id']} ({$row['email']}):\n";
    echo "  password verify 'password': " . (password_verify('password', $row['password_hash']) ? 'YES' : 'NO') . "\n";
    echo "  password verify 'password123': " . (password_verify('password123', $row['password_hash']) ? 'YES' : 'NO') . "\n";
}
