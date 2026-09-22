<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/platform.php';

$action = $_GET['action'] ?? '';
$pdo = db();

if ($action === 'login_admin_dinas_full_disabled') {
    // Ensure Admin Dinas Bandung exists
    $admin = $pdo->query("SELECT * FROM users WHERE email = 'admin.bandung@paskerid.test' LIMIT 1")->fetch();
    if (!$admin) {
        $pdo->exec("INSERT INTO users (name, email, password_hash, role, domicile_city_id, city, profile_complete) VALUES ('Admin Dinas Kota Bandung', 'admin.bandung@paskerid.test', '\$2y\$10\$4Ub96pSJd1xdfdkRHCaWw.WbK19BOoTxiBqxEy7by6Gwub1dJBydm', 'admin_dinas', 'Kota Bandung', 'Kota Bandung', 1)");
        $admin = $pdo->query("SELECT * FROM users WHERE email = 'admin.bandung@paskerid.test' LIMIT 1")->fetch();
    }
    login_user($admin);

    // Setup Budi Santoso as FULL_DISABLED in Kota Bandung
    $pdo->prepare("UPDATE users SET name = 'Budi Santoso', domicile_city_id = 'Kota Bandung', city = 'Kota Bandung', role = 'employer' WHERE id = 2")->execute();
    $pdo->prepare("UPDATE employer_profiles SET 
        owner_name = 'Budi Santoso',
        nik = '3273012345670001',
        domicile_city_id = 'Kota Bandung',
        city = 'Kota Bandung',
        verification_status = 'FULL_DISABLED',
        verified = 0,
        active_until = '2026-12-21 23:59:59',
        last_activated_at = '2026-09-21 08:00:00',
        assigned_to = NULL,
        extension_status = 'NONE'
        WHERE user_id = 2
    ")->execute();

    redirect('../admin.php?view=directory_individual');
}

if ($action === 'login_employer_reactivated') {
    $emp = $pdo->query("SELECT * FROM users WHERE id = 2 LIMIT 1")->fetch();
    login_user($emp);

    $now = date('Y-m-d H:i:s');
    $activeUntil = date('Y-m-d H:i:s', strtotime('+92 days'));
    $pdo->prepare("UPDATE employer_profiles SET 
        verification_status = 'APPROVED',
        verified = 1,
        last_activated_at = ?,
        active_until = ?,
        assigned_to = NULL,
        extension_status = 'NONE'
        WHERE user_id = 2
    ")->execute([$now, $activeUntil]);

    record_audit_log('employer', 2, 'REACTIVATE_EMPLOYER_ACCESS', "Hak Akses Pemberi Kerja Individu direaktivasi langsung oleh Admin Dinas. Previous Status: FULL_DISABLED, New Status: ACTIVE, Previous Active Until: 21 Des 2026, New Activated At: {$now}, New Active Until: {$activeUntil} | Source: ADMIN_DINAS", 'Admin Dinas Kota Bandung', 'admin_dinas', false);

    redirect('../dashboard.php');
}
