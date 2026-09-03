<?php
require __DIR__ . '/includes/bootstrap.php';

$user = current_user();

if (!$user) {
    redirect('login.php');
}

if (($user['role'] ?? '') === 'admin') {
    redirect('admin.php');
}

if (($user['role'] ?? '') === 'seeker') {
    if (!is_profile_complete($user)) {
        redirect('profile-seeker.php');
    }

    redirect('seeker.php');
}

if (!is_profile_complete($user)) {
    redirect('profile-employer.php');
}

redirect('dashboard.php');
