<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = current_user();

if (!$user) {
    redirect('login.php');
}

redirect(role_home($user['role'] ?? ''));

