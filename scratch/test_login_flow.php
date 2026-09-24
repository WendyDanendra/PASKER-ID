<?php
require_once __DIR__ . '/../includes/bootstrap.php';

// Test login logic for perorangan@paskerid.test
$email = 'perorangan@paskerid.test';
$password = 'password';

$user = find_user_by_email($email);
if (!$user) {
    echo "ERROR: User {$email} not found!\n";
    exit(1);
}

if (!password_verify($password, $user['password_hash'])) {
    echo "ERROR: Password verification failed for {$email}!\n";
    exit(1);
}

login_user($user);
echo "SUCCESS: User {$user['name']} ({$user['email']}) logged in with role {$user['role']}.\n";
echo "Profile complete: " . (is_profile_complete($user) ? 'YES' : 'NO') . "\n";
echo "Redirect target: " . (is_profile_complete($user) ? 'dashboard.php' : 'profile-employer.php') . "\n";
