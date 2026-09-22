<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$mode = $_GET['mode'] ?? 'active_valid';
$pdo = db();

$userStmt = $pdo->query("SELECT * FROM users WHERE id = 2 LIMIT 1");
$user = $userStmt->fetch();
if ($user) {
    login_user($user);
}

if ($mode === 'active_valid') {
    // 4 days elapsed, 86 days remaining (total 90 days)
    $lastActivated = date('Y-m-d H:i:s', strtotime('-4 days'));
    $activeUntil = date('Y-m-d H:i:s', strtotime('+86 days'));
    $pdo->prepare("UPDATE employer_profiles SET verification_status = 'APPROVED', verified = 1, last_activated_at = ?, active_until = ? WHERE user_id = 2")
        ->execute([$lastActivated, $activeUntil]);
} elseif ($mode === 'active_h7') {
    // 83 days elapsed, 7 days remaining (total 90 days)
    $lastActivated = date('Y-m-d H:i:s', strtotime('-83 days'));
    $activeUntil = date('Y-m-d H:i:s', strtotime('+7 days'));
    $pdo->prepare("UPDATE employer_profiles SET verification_status = 'APPROVED', verified = 1, last_activated_at = ?, active_until = ? WHERE user_id = 2")
        ->execute([$lastActivated, $activeUntil]);
} elseif ($mode === 'active_null') {
    // ACTIVE but NULL dates
    $pdo->prepare("UPDATE employer_profiles SET verification_status = 'APPROVED', verified = 1, last_activated_at = NULL, active_until = NULL WHERE user_id = 2")
        ->execute();
} elseif ($mode === 'pending') {
    // PENDING verification
    $pdo->prepare("UPDATE employer_profiles SET verification_status = 'PENDING', verified = 0, last_activated_at = NULL, active_until = NULL WHERE user_id = 2")
        ->execute();
}

redirect('../dashboard.php');
