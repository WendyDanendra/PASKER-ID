<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$db = db();

$allUsers = $db->query("SELECT id, name, email, password_hash, role, profile_complete FROM users")->fetchAll(PDO::FETCH_ASSOC);

echo "CURRENT USERS IN DB:\n";
foreach ($allUsers as $u) {
    echo "ID: {$u['id']} | Name: {$u['name']} | Email: {$u['email']} | Role: {$u['role']}\n";
    echo "  Password 'password': " . (password_verify('password', $u['password_hash']) ? 'VALID' : 'INVALID') . "\n";
    echo "  Password 'Pusatpasarkerj4': " . (password_verify('Pusatpasarkerj4', $u['password_hash']) ? 'VALID' : 'INVALID') . "\n";
}
