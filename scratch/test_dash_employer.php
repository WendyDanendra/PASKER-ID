<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../includes/bootstrap.php';

$email = 'sim.employer.' . date('YmdHis') . '.' . random_int(1000, 9999) . '@paskerid.test';
create_user('Test Employer', $email, 'password', 'employer');
$u = find_user_by_email($email);
if ($u) {
    // Insert into employer_profiles as register.php does
    $stmtEp = db()->prepare('INSERT INTO employer_profiles (
        user_id, owner_name, nik, profession, phone, whatsapp, npwp,
        province, city, district, village, postal_code, address, address_detail,
        latitude, longitude, description, linkedin, instagram, facebook, social_media,
        entity_type, verification_status, verified, domicile_city_id, user_consent, created_at
    ) VALUES (
        ?, "Test Employer", "3201010101010001", "Swasta", "08123456789", "08123456789", "",
        "Jawa Barat", "Kota Bandung", "Coblong", "Lebak Siliwangi", "40132", "Jl Ganesha No 10", "",
        "-6.887844", "107.613038", "Test", "", "", "", "",
        "Individu", "PENDING", 0, "3273", 1, CURRENT_TIMESTAMP
    )');
    $stmtEp->execute([(int)$u['id']]);

    login_user($u);
}

try {
    ob_start();
    include __DIR__ . '/../dashboard.php';
    $out = ob_get_clean();
    echo "DASHBOARD SUCCESS: " . strlen($out) . " bytes\n";
} catch (Throwable $e) {
    if (ob_get_level()) ob_end_clean();
    echo "DASHBOARD ERROR: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
}
