<?php
require_once __DIR__ . '/includes/bootstrap.php';


$user = current_user();

if (!$user) {
    redirect('login.php');
}

if (($user['role'] ?? '') === 'admin') {
    redirect('admin.php');
}

$context = get_active_context();
if ($context === 'seeker') {
    redirect('seeker.php');
}

redirect('dashboard.php');

